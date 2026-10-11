<?php
namespace SneakyPeak\Campaigns;

defined('ABSPATH') || exit;

use SneakyPeak\Campaign;
use SneakyPeak\Pricing\PriceSource;
use WC_Product;

/**
 * Class Resolver
 *
 * Resolves the winning active campaign for a given product using priority,
 * evaluates targets (categories + subcategories, tags, specific products, exclusions),
 * and caches target product ID sets per campaign using versioned transients.
 */
class Resolver {

    private static ?PriceSource $price_source = null;
    private static array $resolved_cache = array();
    private static array $guard_cache = array();
    private static ?array $published_campaigns_cache = null;
    private const CACHE_VERSION_OPTION = 'sneakypeak_target_cache_version';

    public static function set_price_source(PriceSource $source): void {
        self::$price_source = $source;
    }

    public static function get_price_source(): PriceSource {
        if (self::$price_source === null) {
            self::$price_source = new \SneakyPeak\Pricing\LegacyPriceSource();
        }
        return self::$price_source;
    }

    /**
     * Get target cache version number
     */
    public static function get_cache_version(): int {
        return (int) get_option(self::CACHE_VERSION_OPTION, 1);
    }

    /**
     * Invalidate all target resolution caches and in-memory campaign caches
     */
    public static function invalidate_caches(): void {
        $version = self::get_cache_version();
        update_option(self::CACHE_VERSION_OPTION, $version + 1, false);
        self::$resolved_cache = array();
        self::$guard_cache = array();
        self::$published_campaigns_cache = null;
    }

    public static function get_preview_cache_context(): string {
        $session = \SneakyPeak\Preview::get_current_session();
        if (!$session) {
            return 'real';
        }
        if ($session['mode'] === 'custom') {
            return 'custom_' . $session['timestamp'];
        }
        return 'preset_' . $session['phase'] . '_' . ($session['campaign_id'] ?? 0);
    }

    /**
     * Resolve the winning active campaign and phase for a product (Teaser and Live phases).
     * Used for front-end badge display and teaser price rendering.
     *
     * Returns array with:
     * - 'campaign': Campaign|null
     * - 'phase': string (draft|scheduled|teaser|live|ended)
     *
     * @param WC_Product|int $product
     * @param int|null $now
     * @return array
     */
    public static function resolve($product, ?int $now = null): array {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product || !is_a($product, 'WC_Product')) {
            return array('campaign' => null, 'phase' => Campaign::PHASE_DRAFT);
        }

        $product_id = $product->get_id();
        $cache_ctx = ($now !== null) ? (string) $now : self::get_preview_cache_context();
        $cache_key = $product_id . '_' . $cache_ctx;

        if (isset(self::$resolved_cache[$cache_key])) {
            return self::$resolved_cache[$cache_key];
        }

        $active_campaigns = self::get_active_campaigns($now);
        if (empty($active_campaigns)) {
            $result = array('campaign' => null, 'phase' => Campaign::PHASE_DRAFT);
            self::$resolved_cache[$cache_key] = $result;
            return $result;
        }

        foreach ($active_campaigns as $campaign) {
            if (self::is_product_in_campaign($product, $campaign)) {
                // Must have a campaign price or custom teaser (Rule 4: No fake or estimated prices)
                if (self::get_price_source()->has_campaign_price_or_teaser($product, $campaign)) {
                    $result = array(
                        'campaign' => $campaign,
                        'phase'    => $campaign->get_phase($now),
                    );
                    self::$resolved_cache[$cache_key] = $result;
                    return $result;
                }
            }
        }

        $result = array('campaign' => null, 'phase' => Campaign::PHASE_DRAFT);
        self::$resolved_cache[$cache_key] = $result;
        return $result;
    }

    /**
     * Resolve the winning campaign for price guarding (Scheduled and Teaser phases).
     *
     * When a campaign is in 'scheduled' (Teaser start in future, reveal not reached)
     * or 'teaser' phase, the early sale price guard must be active so the real sale price
     * does not leak in cart, checkout, REST API, JSON-LD, or variation JSON.
     *
     * Returns array with:
     * - 'campaign': Campaign|null
     * - 'phase': string (draft|scheduled|teaser|live|ended)
     *
     * @param WC_Product|int $product
     * @param int|null $now
     * @return array
     */
    public static function resolve_for_guard($product, ?int $now = null): array {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product || !is_a($product, 'WC_Product')) {
            return array('campaign' => null, 'phase' => Campaign::PHASE_DRAFT);
        }

        $product_id = $product->get_id();
        $cache_ctx = ($now !== null) ? (string) $now : self::get_preview_cache_context();
        $cache_key = $product_id . '_' . $cache_ctx;

        if (isset(self::$guard_cache[$cache_key])) {
            return self::$guard_cache[$cache_key];
        }

        $guarding_campaigns = self::get_guarding_campaigns($now);
        if (empty($guarding_campaigns)) {
            $result = array('campaign' => null, 'phase' => Campaign::PHASE_DRAFT);
            self::$guard_cache[$cache_key] = $result;
            return $result;
        }

        foreach ($guarding_campaigns as $campaign) {
            if (self::is_product_in_campaign($product, $campaign)) {
                if (self::get_price_source()->has_campaign_price_or_teaser($product, $campaign)) {
                    $result = array(
                        'campaign' => $campaign,
                        'phase'    => $campaign->get_phase($now),
                    );
                    self::$guard_cache[$cache_key] = $result;
                    return $result;
                }
            }
        }

        $result = array('campaign' => null, 'phase' => Campaign::PHASE_DRAFT);
        self::$guard_cache[$cache_key] = $result;
        return $result;
    }

    /**
     * Get all published campaign objects, cached once per request.
     *
     * @return Campaign[]
     */
    public static function get_all_published_campaigns(): array {
        if (self::$published_campaigns_cache !== null) {
            return self::$published_campaigns_cache;
        }

        $campaign_ids = get_posts(array(
            'post_type'      => 'sneakypeak_campaign',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));

        $campaigns = array();
        if (!empty($campaign_ids)) {
            foreach ($campaign_ids as $cid) {
                $camp = Campaign::get((int) $cid);
                if ($camp) {
                    $campaigns[] = $camp;
                }
            }
        }

        self::$published_campaigns_cache = $campaigns;
        return self::$published_campaigns_cache;
    }

    /**
     * Get all published campaigns that are currently active (Teaser or Live),
     * evaluated against $now and sorted by priority ASC (lower number wins).
     *
     * @param int|null $now
     * @return Campaign[]
     */
    public static function get_active_campaigns(?int $now = null): array {
        $all = self::get_all_published_campaigns();
        if (empty($all)) {
            return array();
        }

        $campaigns = array();
        foreach ($all as $camp) {
            if ($camp->is_active($now)) {
                $campaigns[] = $camp;
            }
        }

        // Sort by priority ASC (lower wins)
        usort($campaigns, function (Campaign $a, Campaign $b) {
            return $a->get_priority() <=> $b->get_priority();
        });

        return $campaigns;
    }

    /**
     * Get all published campaigns that are currently guarding prices (Scheduled or Teaser),
     * evaluated against $now and sorted by priority ASC (lower number wins).
     *
     * @param int|null $now
     * @return Campaign[]
     */
    public static function get_guarding_campaigns(?int $now = null): array {
        $all = self::get_all_published_campaigns();
        if (empty($all)) {
            return array();
        }

        $campaigns = array();
        foreach ($all as $camp) {
            if ($camp->is_guarding($now)) {
                $campaigns[] = $camp;
            }
        }

        // Sort by priority ASC (lower wins)
        usort($campaigns, function (Campaign $a, Campaign $b) {
            return $a->get_priority() <=> $b->get_priority();
        });

        return $campaigns;
    }

    /**
     * Check if a product matches target settings of a campaign
     *
     * @param WC_Product $product
     * @param Campaign $campaign
     * @return bool
     */
    public static function is_product_in_campaign(WC_Product $product, Campaign $campaign): bool {
        $product_id = $product->get_id();
        $parent_id  = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;

        // Check exclusions first (check both product/variation and parent)
        $exclude_products = array_map('intval', (array) $campaign->get_setting('exclude_products', array()));
        if (in_array($product_id, $exclude_products, true) || ($parent_id > 0 && in_array($parent_id, $exclude_products, true))) {
            return false;
        }

        $prod_cats = $product->get_category_ids();
        $prod_tags = $product->get_tag_ids();
        if ($parent_id > 0 && empty($prod_cats)) {
            $parent = wc_get_product($parent_id);
            if ($parent) {
                $prod_cats = $parent->get_category_ids();
                $prod_tags = $parent->get_tag_ids();
            }
        }

        $exclude_categories = array_map('intval', (array) $campaign->get_setting('exclude_categories', array()));
        if (!empty($exclude_categories)) {
            if (!empty(array_intersect($prod_cats, $exclude_categories))) {
                return false;
            }
        }

        // Check target products (check both product/variation and parent)
        $target_products = array_map('intval', (array) $campaign->get_setting('target_products', array()));
        if (in_array($product_id, $target_products, true) || ($parent_id > 0 && in_array($parent_id, $target_products, true))) {
            return true;
        }

        // Check target categories (including subcategories if enabled)
        $target_categories = (array) $campaign->get_setting('target_categories', array());
        if (!empty($target_categories)) {
            $effective_cats = self::get_effective_category_ids($campaign);
            if (!empty(array_intersect($prod_cats, $effective_cats))) {
                return true;
            }
        }

        // Check target tags
        $target_tags = (array) $campaign->get_setting('target_tags', array());
        if (!empty($target_tags)) {
            $target_tag_ints = array_map('intval', $target_tags);
            if (!empty(array_intersect($prod_tags, $target_tag_ints))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get effective category IDs including subcategories if enabled, cached in transient
     *
     * @param Campaign $campaign
     * @return int[]
     */
    public static function get_effective_category_ids(Campaign $campaign): array {
        $cid = $campaign->get_id();
        $version = self::get_cache_version();
        $transient_key = 'sp_camp_cats_' . $cid . '_v' . $version;

        $cached = get_transient($transient_key);
        if (is_array($cached)) {
            return $cached;
        }

        $cats = array_map('intval', (array) $campaign->get_setting('target_categories', array()));
        $include_subs = ($campaign->get_setting('include_subcategories', 'yes') === 'yes');

        if (!$include_subs || empty($cats)) {
            set_transient($transient_key, $cats, DAY_IN_SECONDS);
            return $cats;
        }

        $all_cats = $cats;
        foreach ($cats as $cat_id) {
            $descendants = get_term_children($cat_id, 'product_cat');
            if (!is_wp_error($descendants) && !empty($descendants)) {
                $all_cats = array_merge($all_cats, array_map('intval', $descendants));
            }
        }

        $all_cats = array_values(array_unique($all_cats));
        set_transient($transient_key, $all_cats, DAY_IN_SECONDS);
        return $all_cats;
    }

    /**
     * Count products targeted by a campaign
     *
     * @param Campaign $campaign
     * @return int
     */
    public static function count_targeted_products(Campaign $campaign): int {
        $cid = $campaign->get_id();
        $version = self::get_cache_version();
        $transient_key = 'sp_camp_pcount_' . $cid . '_v' . $version;

        $cached = get_transient($transient_key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $tax_query = array('relation' => 'OR');
        $has_tax = false;

        $effective_cats = self::get_effective_category_ids($campaign);
        if (!empty($effective_cats)) {
            $tax_query[] = array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $effective_cats,
            );
            $has_tax = true;
        }

        $target_tags = array_map('intval', (array) $campaign->get_setting('target_tags', array()));
        if (!empty($target_tags)) {
            $tax_query[] = array(
                'taxonomy' => 'product_tag',
                'field'    => 'term_id',
                'terms'    => $target_tags,
            );
            $has_tax = true;
        }

        $target_products = array_map('intval', (array) $campaign->get_setting('target_products', array()));

        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        );

        $exclude_products = array_map('intval', (array) $campaign->get_setting('exclude_products', array()));
        if (!empty($exclude_products)) {
            $args['post__not_in'] = $exclude_products;
        }

        $matched_ids = array();
        if ($has_tax) {
            $args['tax_query'] = $tax_query;
            $tax_ids = get_posts($args);
            if (!empty($tax_ids)) {
                $matched_ids = array_merge($matched_ids, $tax_ids);
            }
        }

        if (!empty($target_products)) {
            $matched_ids = array_merge($matched_ids, $target_products);
        }

        $matched_ids = array_unique($matched_ids);
        $count = count($matched_ids);

        if (!\SneakyPeak\Preview::is_active()) {
            set_transient($transient_key, $count, HOUR_IN_SECONDS);
        }
        return $count;
    }
}
