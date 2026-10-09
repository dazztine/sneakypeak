<?php
namespace SneakyPeak\Frontend;

defined('ABSPATH') || exit;

use SneakyPeak\Campaign;
use SneakyPeak\Campaigns\Resolver;
use SneakyPeak\Plugin;
use SneakyPeak\Support\Safe;
use WC_Product;

/**
 * Class Controller
 *
 * Front-end controller handling early sale guard filters, teaser price insertion,
 * single product gallery badge injection (preserving flexslider/zoom/photoswipe),
 * post classes for JS card targeting, and dynamic custom CSS.
 *
 * RULE 1: NEVER wrap or alter catalog images or image containers. Badges attach to the CARD only.
 */
class Controller {

    private static bool $single_badge_rendered = false;

    public static function init(): void {
        if (!Plugin::is_storefront_request()) {
            return;
        }

        // Single product gallery thumbnail injection (direct div injection inside first image div, no wrapper)
        add_filter('woocommerce_single_product_image_thumbnail_html', Safe::filter(array(__CLASS__, 'filter_single_product_image_thumbnail_html'), 0), 20, 2);
        // Fallback for custom single product gallery markup
        add_action('woocommerce_before_single_product_summary', Safe::action(array(__CLASS__, 'render_single_gallery_fallback_badge')), 25);
        // Single product summary injection (above title/price)
        add_action('woocommerce_single_product_summary', Safe::action(array(__CLASS__, 'render_single_summary_badge')), 4);

        // Price HTML filter for teaser display prior to Live phase
        add_filter('woocommerce_get_price_html', Safe::filter(array(__CLASS__, 'filter_price_html'), 0), 99, 2);

        // Early sale guarding filters (active only during Teaser phase or before Live)
        add_filter('woocommerce_product_get_price', Safe::filter(array(__CLASS__, 'filter_product_get_price'), 0), 99, 2);
        add_filter('woocommerce_product_variation_get_price', Safe::filter(array(__CLASS__, 'filter_variation_get_price'), 0), 99, 2);
        add_filter('woocommerce_product_get_sale_price', Safe::filter(array(__CLASS__, 'filter_product_get_sale_price'), 0), 99, 2);
        add_filter('woocommerce_product_variation_get_sale_price', Safe::filter(array(__CLASS__, 'filter_variation_get_sale_price'), 0), 99, 2);
        add_filter('woocommerce_product_is_on_sale', Safe::filter(array(__CLASS__, 'filter_product_is_on_sale'), 0), 99, 2);

        // Variation prices hash & cached prices
        add_filter('woocommerce_variation_prices_price', Safe::filter(array(__CLASS__, 'filter_variation_prices_price'), 0), 99, 3);
        add_filter('woocommerce_variation_prices_sale_price', Safe::filter(array(__CLASS__, 'filter_variation_prices_sale_price'), 0), 99, 3);
        add_filter('woocommerce_variation_prices_hash', Safe::filter(array(__CLASS__, 'filter_variation_prices_hash'), 0), 99, 3);

        // Prevent price leaks in JSON-LD, Store API, and variation JSON prior to Live phase
        add_filter('woocommerce_structured_data_product_offer', Safe::filter(array(__CLASS__, 'filter_structured_data_offer'), 0), 99, 2);
        add_filter('woocommerce_available_variation', Safe::filter(array(__CLASS__, 'filter_available_variation'), 0), 99, 3);
        add_filter('woocommerce_store_api_product_prices', Safe::filter(array(__CLASS__, 'filter_store_api_product_prices'), 0), 99, 2);

        // Suppress default sale flash badge on campaign promo products during Teaser phase
        add_filter('woocommerce_sale_flash', Safe::filter(array(__CLASS__, 'filter_sale_flash'), 0), 99, 3);

        // Add promo classes for targeted card badge styling
        add_filter('post_class', Safe::filter(array(__CLASS__, 'filter_post_class'), 0), 10, 3);
        add_filter('woocommerce_post_class', Safe::filter(array(__CLASS__, 'filter_woocommerce_post_class'), 0), 10, 2);
        add_filter('body_class', Safe::filter(array(__CLASS__, 'filter_body_class'), 0));

        // Enqueue frontend CSS and JS
        add_action('wp_enqueue_scripts', Safe::action(array(__CLASS__, 'enqueue_assets')));

        // Debug footer output for administrators (?sneakypeak_debug=1)
        add_action('wp_footer', Safe::action(array(__CLASS__, 'render_debug_footer')), 999);
    }

    /**
     * Enqueue styles and scripts
     */
    public static function enqueue_assets(): void {
        $active_campaigns = Resolver::get_active_campaigns();
        if (empty($active_campaigns)) {
            return;
        }

        wp_enqueue_style(
            'sneakypeak-frontend',
            SNEAKYPEAK_PLUGIN_URL . 'assets/css/sneakypeak.css',
            array(),
            SNEAKYPEAK_VERSION
        );

        $custom_css = self::get_inline_custom_css($active_campaigns);
        if (!empty($custom_css)) {
            wp_add_inline_style('sneakypeak-frontend', $custom_css);
        }

        wp_enqueue_script(
            'sneakypeak-frontend',
            SNEAKYPEAK_PLUGIN_URL . 'assets/js/sneakypeak.js',
            array(),
            SNEAKYPEAK_VERSION,
            true // load in footer
        );

        // Prepare campaign config payload for JS badge placement
        $campaigns_payload = array();
        foreach ($active_campaigns as $camp) {
            $phase = $camp->get_phase();
            $campaigns_payload[$camp->get_id()] = array(
                'id'                   => $camp->get_id(),
                'phase'                => $phase,
                'isLive'               => ($phase === Campaign::PHASE_LIVE),
                'badgeHtml'            => Badge::render($camp, $phase, false),
                'singleBadgeHtml'      => Badge::render($camp, $phase, true),
                'singlePosition'       => (string) $camp->get_setting('single_badge_position', 'gallery'),
                'singleCustomSelector' => (string) $camp->get_setting('single_badge_custom_selector', ''),
            );
        }

        wp_localize_script('sneakypeak-frontend', 'sneakypeakConfig', array(
            'campaigns' => $campaigns_payload,
            'debug'     => (isset($_GET['sneakypeak_debug']) && $_GET['sneakypeak_debug'] === '1'),
        ));
    }

    /**
     * Add sneakypeak-promo and campaign-specific classes to product cards
     */
    public static function filter_post_class(array $classes, $class, $post_id): array {
        if (get_post_type($post_id) === 'product') {
            $product = wc_get_product($post_id);
            if ($product) {
                $res = Resolver::resolve($product);
                if ($res['campaign'] !== null) {
                    $classes[] = 'sneakypeak-promo';
                    $classes[] = 'sneakypeak-campaign-' . $res['campaign']->get_id();
                    $classes[] = 'sneakypeak-phase-' . $res['phase'];
                }
            }
        }
        return $classes;
    }

    public static function filter_woocommerce_post_class(array $classes, $product): array {
        if ($product && is_a($product, 'WC_Product')) {
            $res = Resolver::resolve($product);
            if ($res['campaign'] !== null) {
                $classes[] = 'sneakypeak-promo';
                $classes[] = 'sneakypeak-campaign-' . $res['campaign']->get_id();
                $classes[] = 'sneakypeak-phase-' . $res['phase'];
            }
        }
        return $classes;
    }

    public static function filter_body_class(array $classes): array {
        if (is_product()) {
            global $product;
            if (is_a($product, 'WC_Product')) {
                $res = Resolver::resolve($product);
                if ($res['campaign'] !== null) {
                    $classes[] = 'sneakypeak-promo-single';
                    $classes[] = 'sneakypeak-campaign-' . $res['campaign']->get_id();
                    $classes[] = 'sneakypeak-phase-' . $res['phase'];
                }
            }
        }
        return $classes;
    }

    /**
     * Single product page: Inject badge directly INSIDE the first gallery image div.
     * Does NOT wrap .woocommerce-product-gallery__image, preserving FlexSlider/PhotoSwipe/Zoom.
     */
    public static function filter_single_product_image_thumbnail_html(string $html, $attachment_id): string {
        if (!is_product()) {
            return $html;
        }
        global $product;
        if (!is_a($product, 'WC_Product')) {
            return $html;
        }

        $res = Resolver::resolve($product);
        if ($res['campaign'] === null || self::$single_badge_rendered || strpos($html, 'sneakypeak-badge') !== false) {
            return $html;
        }

        // Only attach if single product placement is set to 'gallery'
        $position = (string) $res['campaign']->get_setting('single_badge_position', 'gallery');
        if ($position !== 'gallery') {
            return $html;
        }

        // Only attach to primary product image in gallery
        if ((int) $attachment_id !== (int) $product->get_image_id()) {
            return $html;
        }

        $badge_html = Badge::render($res['campaign'], $res['phase'], true);

        // Safely inject right after opening <div ...> tag of .woocommerce-product-gallery__image
        $first_gt = strpos($html, '>');
        if ($first_gt !== false && stripos(substr($html, 0, $first_gt), '<div') !== false) {
            self::$single_badge_rendered = true;
            return substr($html, 0, $first_gt + 1) . $badge_html . substr($html, $first_gt + 1);
        }

        return $html;
    }

    /**
     * Fallback for custom single product gallery markup
     */
    public static function render_single_gallery_fallback_badge(): void {
        if (!is_product() || self::$single_badge_rendered) {
            return;
        }
        global $product;
        if (!is_a($product, 'WC_Product')) {
            return;
        }

        $res = Resolver::resolve($product);
        if ($res['campaign'] === null) {
            return;
        }

        $position = (string) $res['campaign']->get_setting('single_badge_position', 'gallery');
        if ($position !== 'gallery') {
            return;
        }

        self::$single_badge_rendered = true;
        echo '<div class="sneakypeak-single-fallback-wrap" style="position:relative; clear:both;">' . Badge::render($res['campaign'], $res['phase'], true) . '</div>';
    }

    /**
     * Single product summary badge (rendered above title/price inside product summary)
     */
    public static function render_single_summary_badge(): void {
        if (!is_product() || self::$single_badge_rendered) {
            return;
        }
        global $product;
        if (!is_a($product, 'WC_Product')) {
            return;
        }

        $res = Resolver::resolve($product);
        if ($res['campaign'] === null) {
            return;
        }

        $position = (string) $res['campaign']->get_setting('single_badge_position', 'gallery');
        if ($position !== 'summary') {
            return;
        }

        self::$single_badge_rendered = true;
        echo '<div class="sneakypeak-single-summary-wrap" style="position:relative; margin-bottom:12px; display:inline-block; clear:both;">' . Badge::render($res['campaign'], $res['phase'], true) . '</div>';
    }

    /**
     * Filter price HTML to show masked teaser prior to Live phase
     */
    public static function filter_price_html(string $price_html, $product): string {
        if (!is_a($product, 'WC_Product')) {
            return $price_html;
        }

        // Only handle simple and variable products; leave grouped/external products untouched
        if (!$product->is_type('simple') && !$product->is_type('variable')) {
            return $price_html;
        }

        $res = Resolver::resolve($product);
        if ($res['campaign'] === null) {
            return $price_html;
        }

        // If campaign is in Live phase or Ended, let standard WooCommerce / live pricing render
        if ($res['phase'] !== Campaign::PHASE_TEASER) {
            return $price_html;
        }

        $price_source = Resolver::get_price_source();
        $teaser_price = $price_source->get_teaser_price_string($product, $res['campaign']);
        if (empty($teaser_price)) {
            return $price_html;
        }

        $campaign = $res['campaign'];
        $label = esc_html((string) $campaign->get_setting('teaser_label', 'Sale Price:'));

        $teaser_block = sprintf(
            '<span class="sneakypeak-teaser-wrap sneakypeak-teaser-campaign-%1$d" data-campaign-id="%1$d">' .
            '<span class="sneakypeak-teaser-label">%2$s</span> ' .
            '<span class="sneakypeak-teaser-price">%3$s</span>' .
            '</span>',
            esc_attr($campaign->get_id()),
            $label,
            esc_html($teaser_price)
        );

        return $price_html . $teaser_block;
    }

    /**
     * Early sale guard: enforce regular price during Scheduled and Teaser phases
     */
    public static function filter_product_get_price($price, $product) {
        if (self::should_guard_product($product)) {
            $reg = $product->get_regular_price();
            if ($reg !== '' && $reg !== null) {
                return $reg;
            }
        }
        return $price;
    }

    public static function filter_variation_get_price($price, $product) {
        if (self::should_guard_product($product)) {
            $reg = $product->get_regular_price();
            if ($reg !== '' && $reg !== null) {
                return $reg;
            }
        }
        return $price;
    }

    public static function filter_product_get_sale_price($sale_price, $product) {
        if (self::should_guard_product($product)) {
            return '';
        }
        return $sale_price;
    }

    public static function filter_variation_get_sale_price($sale_price, $product) {
        if (self::should_guard_product($product)) {
            return '';
        }
        return $sale_price;
    }

    public static function filter_product_is_on_sale($is_on_sale, $product) {
        if (self::should_guard_product($product)) {
            return false;
        }
        return $is_on_sale;
    }

    public static function filter_variation_prices_price($price, $variation, $product) {
        if (self::should_guard_product($product)) {
            $reg = $variation->get_regular_price();
            if ($reg !== '' && $reg !== null) {
                return $reg;
            }
        }
        return $price;
    }

    public static function filter_variation_prices_sale_price($sale_price, $variation, $product) {
        if (self::should_guard_product($product)) {
            $reg = $variation->get_regular_price();
            if ($reg !== '' && $reg !== null) {
                return $reg;
            }
        }
        return $sale_price;
    }

    public static function filter_variation_prices_hash($hash, $product, $for_display) {
        $res = Resolver::resolve_for_guard($product);
        if ($res['campaign'] !== null) {
            $hash[] = 'sneakypeak_guard_phase_' . $res['phase'];
        }
        return $hash;
    }

    public static function filter_structured_data_offer($markup, $product) {
        if (self::should_guard_product($product)) {
            $reg = $product->get_regular_price();
            if ($reg !== '' && $reg !== null) {
                $markup['price'] = wc_format_decimal($reg, wc_get_price_decimals());
            }
        }
        return $markup;
    }

    public static function filter_available_variation($data, $product, $variation) {
        if (self::should_guard_product($variation)) {
            $reg = $variation->get_regular_price();
            if ($reg !== '' && $reg !== null) {
                $data['display_price'] = wc_get_price_to_display($variation, array('price' => $reg));
                $data['display_regular_price'] = wc_get_price_to_display($variation, array('price' => $reg));
                $data['price_html'] = '';
            }
        }
        return $data;
    }

    public static function filter_store_api_product_prices($prices, $product) {
        if (self::should_guard_product($product)) {
            $reg = $product->get_regular_price();
            if ($reg !== '' && $reg !== null) {
                $decimals = wc_get_price_decimals();
                $minor_units = pow(10, $decimals);
                $reg_minor = (string) round((float) $reg * $minor_units);

                $prices['price'] = $reg_minor;
                $prices['regular_price'] = $reg_minor;
                $prices['sale_price'] = $reg_minor;
                $prices['price_range'] = null;
            }
        }
        return $prices;
    }

    public static function filter_sale_flash($html, $post, $product) {
        $res = Resolver::resolve($product);
        if ($res['campaign'] !== null && $res['phase'] === Campaign::PHASE_TEASER) {
            return '';
        }
        return $html;
    }

    /**
     * Check if early sale guard should be enforced for this product.
     * Enforced when campaign is in Scheduled (before teaser start) or Teaser phases.
     */
    private static function should_guard_product($product): bool {
        if (!$product || !is_a($product, 'WC_Product')) {
            return false;
        }
        $res = Resolver::resolve_for_guard($product);
        return ($res['campaign'] !== null && ($res['phase'] === Campaign::PHASE_SCHEDULED || $res['phase'] === Campaign::PHASE_TEASER));
    }

    /**
     * Generate dynamic inline CSS for active campaigns
     *
     * @param Campaign[] $active_campaigns
     * @return string
     */
    public static function get_inline_custom_css(array $active_campaigns): string {
        $css = '';
        $google_fonts_needed = array();

        $gfont_map = array(
            'jost'       => 'Jost:wght@700;800',
            'montserrat' => 'Montserrat:wght@700;800',
            'poppins'    => 'Poppins:wght@700;800',
            'outfit'     => 'Outfit:wght@700;800',
            'inter'      => 'Inter:wght@700;800',
            'questrial'  => 'Questrial',
        );

        foreach ($active_campaigns as $camp) {
            $cid = $camp->get_id();
            $phase = $camp->get_phase();

            $bg_start = esc_attr((string) $camp->get_setting($phase === Campaign::PHASE_LIVE ? 'badge_bg_start_live' : 'badge_bg_start_teaser', '#ff416c'));
            $bg_end   = esc_attr((string) $camp->get_setting($phase === Campaign::PHASE_LIVE ? 'badge_bg_end_live' : 'badge_bg_end_teaser', '#ff4b2b'));
            $text_col = esc_attr((string) $camp->get_setting($phase === Campaign::PHASE_LIVE ? 'badge_text_color_live' : 'badge_text_color_teaser', '#ffffff'));

            list($nudge_x, $nudge_y) = $camp->get_nudge_offsets();
            $corner     = (string) $camp->get_setting('badge_corner', 'top-right');
            $font_size  = absint($camp->get_setting('badge_font_size', 12));
            $custom_css = (string) $camp->get_setting('badge_custom_css', '');

            // Single Product Badge Positioning & Nudge
            list($single_nudge_x, $single_nudge_y) = $camp->get_single_nudge_offsets();
            $single_corner = (string) $camp->get_setting('single_badge_corner', 'top-right');

            // Corner base anchor helper
            $get_corner_style = function ($c) {
                if ($c === 'top-right') {
                    return "top: 0 !important; right: 0 !important; bottom: auto !important; left: auto !important;";
                } elseif ($c === 'top-left') {
                    return "top: 0 !important; left: 0 !important; bottom: auto !important; right: auto !important;";
                } elseif ($c === 'bottom-left') {
                    return "bottom: 0 !important; left: 0 !important; top: auto !important; right: auto !important;";
                } elseif ($c === 'bottom-right') {
                    return "bottom: 0 !important; right: 0 !important; top: auto !important; left: auto !important;";
                }
                return "top: 0 !important; right: 0 !important; bottom: auto !important; left: auto !important;";
            };

            $corner_style = $get_corner_style($corner);
            $single_corner_style = $get_corner_style($single_corner);

            // Teaser Font Family & Size
            $font_family_setting = (string) $camp->get_setting('teaser_font_family', 'inherit');
            $custom_font_url     = esc_url_raw((string) $camp->get_setting('teaser_font_custom_url', ''));
            $custom_font_name    = sanitize_text_field((string) $camp->get_setting('teaser_font_custom_name', ''));
            if (empty($custom_font_name)) {
                $custom_font_name = 'SPCustomFont_' . $cid;
            }
            $teaser_font_size    = absint($camp->get_setting('teaser_font_size', 0));

            $teaser_font_css = '';
            if ($font_family_setting === 'custom' && !empty($custom_font_url)) {
                $format = 'woff2';
                if (preg_match('/\.ttf(\?.*)?$/i', $custom_font_url)) {
                    $format = 'truetype';
                } elseif (preg_match('/\.woff(\?.*)?$/i', $custom_font_url)) {
                    $format = 'woff';
                }
                $css .= "@font-face {\n  font-family: '{$custom_font_name}';\n  src: url('{$custom_font_url}') format('{$format}');\n  font-weight: 700 800;\n  font-display: swap;\n}\n";
                $teaser_font_css .= "font-family: '{$custom_font_name}', sans-serif !important;\n";
            } elseif (isset($gfont_map[$font_family_setting])) {
                $google_fonts_needed[$font_family_setting] = $gfont_map[$font_family_setting];
                $capitalized_font = ucfirst($font_family_setting);
                $teaser_font_css .= "font-family: '{$capitalized_font}', sans-serif !important;\n";
            }

            if ($teaser_font_size > 0) {
                $teaser_font_css .= "font-size: {$teaser_font_size}px !important;\n";
            }

            $css .= "
                .sneakypeak-badge-wrap[data-campaign-id='{$cid}'] {
                    {$corner_style}
                    transform: translate({$nudge_x}px, {$nudge_y}px) !important;
                }
                .sneakypeak-badge-wrap.sneakypeak-badge-single-wrap[data-campaign-id='{$cid}'] {
                    {$single_corner_style}
                    transform: translate({$single_nudge_x}px, {$single_nudge_y}px) !important;
                }
                .sneakypeak-badge-wrap[data-campaign-id='{$cid}'] .sneakypeak-badge {
                    background: linear-gradient(135deg, {$bg_start} 0%, {$bg_end} 100%) !important;
                    color: {$text_col} !important;
                    font-size: {$font_size}px !important;
                }
                .sneakypeak-teaser-campaign-{$cid} .sneakypeak-teaser-price {
                    color: {$bg_end} !important;
                    {$teaser_font_css}
                }
            ";

            if (!empty($custom_css)) {
                $css .= "\n/* Custom CSS for Campaign #{$cid} */\n" . $custom_css . "\n";
            }
        }

        if (!empty($google_fonts_needed)) {
            $gfont_query = implode('&family=', array_values($google_fonts_needed));
            $import_url = 'https://fonts.googleapis.com/css2?family=' . $gfont_query . '&display=swap';
            $css = "@import url('{$import_url}');\n" . $css;
        }

        return $css;
    }

    /**
     * Debug footer for administrators
     */
    public static function render_debug_footer(): void {
        if (!current_user_can('manage_woocommerce') || empty($_GET['sneakypeak_debug']) || $_GET['sneakypeak_debug'] !== '1') {
            return;
        }

        $active = Resolver::get_active_campaigns();
        echo "\n<!-- SneakyPeak Debug Info:\n";
        echo "Active Campaigns Count: " . count($active) . "\n";
        foreach ($active as $c) {
            echo "Campaign #{$c->get_id()} ({$c->get_name()}): Phase=" . $c->get_phase() . ", Priority=" . $c->get_priority() . "\n";
        }
        echo "-->\n";
    }
}
