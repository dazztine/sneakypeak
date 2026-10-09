<?php
namespace SneakyPeak\Support;

defined('ABSPATH') || exit;

use SneakyPeak\Campaign;
use SneakyPeak\Campaigns\Resolver;

/**
 * Class Cache
 *
 * Manages cache purging across popular WordPress cache plugins,
 * WP-Cron event scheduling for campaign phase transitions,
 * and cron health diagnostics.
 */
class Cache {

    public const CRON_HOOK = 'sneakypeak_phase_change';
    public const PURGE_NONCE_ACTION = 'sneakypeak_purge_cache_now';
    public const PURGE_NONCE_NAME = 'sneakypeak_purge_nonce';

    /**
     * In-memory throttling timestamps per reason: [ reason => timestamp ]
     */
    private static array $throttles = array();

    public static function init(): void {
        // Cron handler
        add_action(self::CRON_HOOK, array(__CLASS__, 'handle_phase_change'), 10, 2);

        // Lifecycle scheduling hooks
        add_action('save_post_sneakypeak_campaign', array(__CLASS__, 'on_campaign_save'), 20, 2);
        add_action('transition_post_status', array(__CLASS__, 'on_transition_post_status'), 10, 3);
        add_action('trashed_post', array(__CLASS__, 'on_trash_or_delete_post'));
        add_action('before_delete_post', array(__CLASS__, 'on_trash_or_delete_post'));

        // Cron event reconciliation
        add_action('admin_init', array(__CLASS__, 'reconcile_all_campaign_events'));

        // Diagnostics notices & manual purge handler
        add_action('admin_notices', array(__CLASS__, 'display_admin_notices'));
        add_action('admin_init', array(__CLASS__, 'handle_manual_purge_request'));
    }

    /**
     * Purge all detected WordPress cache layers.
     * Throttled to at most once per 60 seconds per reason.
     *
     * @param string $reason Human-readable reason for purge.
     * @param int|null $campaign_id Optional campaign ID context.
     * @return bool True if purge executed, false if throttled.
     */
    public static function purge_all(string $reason, ?int $campaign_id = null): bool {
        $now = time();

        // 1. In-memory throttle check (60s per reason)
        if (isset(self::$throttles[$reason]) && ($now - self::$throttles[$reason]) < 60) {
            return false;
        }

        // 2. Persistent transient throttle check if WordPress transient API available
        $transient_key = 'sp_purge_' . md5($reason);
        if (function_exists('get_transient') && get_transient($transient_key)) {
            return false;
        }

        // Mark throttled
        self::$throttles[$reason] = $now;
        if (function_exists('set_transient')) {
            set_transient($transient_key, $now, 60);
        }

        // 3. Log purge if WP_DEBUG is active
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SneakyPeak] cache purged: ' . $reason);
        }

        // 4. LiteSpeed Cache
        if (function_exists('do_action')) {
            try {
                do_action('litespeed_purge_all');
            } catch (\Throwable $e) {}
        }

        // 5. WP Rocket
        if (function_exists('rocket_clean_domain')) {
            try {
                rocket_clean_domain();
            } catch (\Throwable $e) {}
        }

        // 6. W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            try {
                w3tc_flush_all();
            } catch (\Throwable $e) {}
        }

        // 7. WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            try {
                wp_cache_clear_cache();
            } catch (\Throwable $e) {}
        }

        // 8. SiteGround Optimizer (SG CachePress)
        if (function_exists('sg_cachepress_purge_cache')) {
            try {
                sg_cachepress_purge_cache();
            } catch (\Throwable $e) {}
        }

        // 9. Autoptimize
        if (class_exists('autoptimizeCache') && is_callable(array('autoptimizeCache', 'clearall'))) {
            try {
                \autoptimizeCache::clearall();
            } catch (\Throwable $e) {}
        }

        // 10. WP Fastest Cache
        if (function_exists('wpfc_clear_all_cache')) {
            try {
                wpfc_clear_all_cache();
            } catch (\Throwable $e) {}
        }

        // 11. WordPress Object Cache
        if (function_exists('wp_cache_flush')) {
            try {
                wp_cache_flush();
            } catch (\Throwable $e) {}
        }

        // 12. WooCommerce product transients
        if (function_exists('wc_delete_product_transients')) {
            try {
                wc_delete_product_transients();
            } catch (\Throwable $e) {}
        }

        // 13. SneakyPeak Internal Resolvers
        if (class_exists('\\SneakyPeak\\Campaigns\\Resolver')) {
            try {
                Resolver::invalidate_caches();
            } catch (\Throwable $e) {}
        }

        // 14. Action hook for themes/plugins
        if (function_exists('do_action')) {
            try {
                do_action('sneakypeak_cache_purged', $reason);
            } catch (\Throwable $e) {}
        }

        return true;
    }

    /**
     * Reset throttle for testing or immediate forced purging.
     */
    public static function reset_throttle(?string $reason = null): void {
        if ($reason === null) {
            self::$throttles = array();
        } else {
            unset(self::$throttles[$reason]);
            if (function_exists('delete_transient')) {
                delete_transient('sp_purge_' . md5($reason));
            }
        }
    }

    /**
     * Cron callback triggered at phase milestones
     */
    public static function handle_phase_change(int $campaign_id, string $phase): void {
        $reason = sprintf('Phase change: %s (campaign #%d)', $phase, $campaign_id);
        self::purge_all($reason, $campaign_id);
    }

    /**
     * Schedule single WP-Cron events for a campaign at Teaser start, Reveal (Live) start, and End timestamps.
     */
    public static function schedule_campaign_events($campaign_or_id): void {
        $campaign = null;
        if ($campaign_or_id instanceof Campaign) {
            $campaign = $campaign_or_id;
        } elseif (is_numeric($campaign_or_id) && (int) $campaign_or_id > 0) {
            $campaign = Campaign::get((int) $campaign_or_id);
        }

        if (!$campaign) {
            return;
        }

        $campaign_id = $campaign->get_id();

        // If not published or not enabled, clear any existing cron events
        if (get_post_status($campaign_id) !== 'publish' || ($campaign->get_setting('enabled') ?? 'yes') !== 'yes') {
            self::clear_campaign_events($campaign_id);
            return;
        }

        // Clear existing events first to cleanly reschedule
        self::clear_campaign_events($campaign_id);

        $now = time();

        // 1. Teaser start
        $teaser_ts = $campaign->get_teaser_start_timestamp();
        if ($teaser_ts > $now) {
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event($teaser_ts, self::CRON_HOOK, array($campaign_id, Campaign::PHASE_TEASER));
            }
        }

        // 2. Reveal (Live) start
        $reveal_ts = $campaign->get_reveal_start_timestamp();
        if ($reveal_ts > $now) {
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event($reveal_ts, self::CRON_HOOK, array($campaign_id, Campaign::PHASE_LIVE));
            }
        }

        // 3. End
        $end_ts = $campaign->get_end_timestamp();
        if ($end_ts > $now) {
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event($end_ts, self::CRON_HOOK, array($campaign_id, Campaign::PHASE_ENDED));
            }
        }
    }

    /**
     * Clear all scheduled cron events for a specific campaign.
     */
    public static function clear_campaign_events(int $campaign_id): void {
        $phases = array(Campaign::PHASE_TEASER, Campaign::PHASE_LIVE, Campaign::PHASE_ENDED);

        foreach ($phases as $phase) {
            $args = array($campaign_id, $phase);
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(self::CRON_HOOK, $args);
            }
            if (function_exists('wp_next_scheduled') && function_exists('wp_unschedule_event')) {
                $next_ts = wp_next_scheduled(self::CRON_HOOK, $args);
                if ($next_ts) {
                    wp_unschedule_event($next_ts, self::CRON_HOOK, $args);
                }
            }
        }
    }

    /**
     * Clear all SneakyPeak cron events across all campaigns (e.g. on plugin deactivation).
     */
    public static function clear_all_cron_events(): void {
        if (!function_exists('get_posts')) {
            return;
        }
        $posts = get_posts(array(
            'post_type'      => 'sneakypeak_campaign',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ));
        foreach ($posts as $post_id) {
            self::clear_campaign_events((int) $post_id);
        }
    }

    /**
     * Reconcile cron events for all published campaigns on admin_init.
     */
    public static function reconcile_all_campaign_events(): void {
        static $reconciled = false;
        if ($reconciled || !function_exists('get_posts')) {
            return;
        }
        $reconciled = true;

        $campaign_ids = get_posts(array(
            'post_type'      => 'sneakypeak_campaign',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ));

        $now = time();
        foreach ($campaign_ids as $cid) {
            $campaign = Campaign::get((int) $cid);
            if (!$campaign) {
                continue;
            }

            if (($campaign->get_setting('enabled') ?? 'yes') !== 'yes') {
                self::clear_campaign_events((int) $cid);
                continue;
            }

            $milestones = array(
                Campaign::PHASE_TEASER => $campaign->get_teaser_start_timestamp(),
                Campaign::PHASE_LIVE   => $campaign->get_reveal_start_timestamp(),
                Campaign::PHASE_ENDED  => $campaign->get_end_timestamp(),
            );

            foreach ($milestones as $phase => $ts) {
                $args = array((int) $cid, $phase);
                $scheduled_ts = function_exists('wp_next_scheduled') ? wp_next_scheduled(self::CRON_HOOK, $args) : false;

                if ($ts > $now) {
                    // Future milestone: must have event scheduled at $ts
                    if (!$scheduled_ts) {
                        if (function_exists('wp_schedule_single_event')) {
                            wp_schedule_single_event($ts, self::CRON_HOOK, $args);
                        }
                    } elseif ($scheduled_ts !== $ts) {
                        // Reschedule if timestamp changed
                        if (function_exists('wp_unschedule_event')) {
                            wp_unschedule_event($scheduled_ts, self::CRON_HOOK, $args);
                        }
                        if (function_exists('wp_schedule_single_event')) {
                            wp_schedule_single_event($ts, self::CRON_HOOK, $args);
                        }
                    }
                }
            }
        }
    }

    /**
     * Hook: save_post_sneakypeak_campaign
     */
    public static function on_campaign_save(int $post_id, \WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if ($post->post_status === 'publish') {
            self::schedule_campaign_events($post_id);
        } else {
            self::clear_campaign_events($post_id);
        }

        self::purge_all('Campaign save', $post_id);
    }

    /**
     * Hook: transition_post_status
     */
    public static function on_transition_post_status(string $new_status, string $old_status, \WP_Post $post): void {
        if ($post->post_type !== 'sneakypeak_campaign') {
            return;
        }

        if ($new_status === 'publish') {
            self::schedule_campaign_events($post->ID);
            self::purge_all('Campaign published', $post->ID);
        } elseif ($old_status === 'publish' && $new_status !== 'publish') {
            self::clear_campaign_events($post->ID);
            self::purge_all('Campaign unpublished', $post->ID);
        }
    }

    /**
     * Hook: trashed_post / before_delete_post
     */
    public static function on_trash_or_delete_post(int $post_id): void {
        if (get_post_type($post_id) !== 'sneakypeak_campaign') {
            return;
        }
        self::clear_campaign_events($post_id);
        self::purge_all('Campaign removed', $post_id);
    }

    /**
     * Retrieve the next upcoming phase change milestone and its cron status for a campaign.
     *
     * @return array{phase: string, phase_label: string, timestamp: int, status: string, overdue_seconds: int}|null
     * Status values: 'scheduled' | 'overdue' | 'missing'
     */
    public static function get_next_phase_change_diagnostics(Campaign $campaign): ?array {
        if (get_post_status($campaign->get_id()) !== 'publish' || ($campaign->get_setting('enabled') ?? 'yes') !== 'yes') {
            return null;
        }

        $now = time();
        $campaign_id = $campaign->get_id();

        $milestones = array(
            Campaign::PHASE_TEASER => array(
                'label'     => __('Teaser Phase', 'sneakypeak'),
                'timestamp' => $campaign->get_teaser_start_timestamp(),
            ),
            Campaign::PHASE_LIVE => array(
                'label'     => __('Live (Sale Reveal)', 'sneakypeak'),
                'timestamp' => $campaign->get_reveal_start_timestamp(),
            ),
            Campaign::PHASE_ENDED => array(
                'label'     => __('Campaign Ended', 'sneakypeak'),
                'timestamp' => $campaign->get_end_timestamp(),
            ),
        );

        foreach ($milestones as $phase => $data) {
            $ts = $data['timestamp'];
            if ($ts <= 0) {
                continue;
            }

            $args = array($campaign_id, $phase);
            $scheduled_ts = function_exists('wp_next_scheduled') ? wp_next_scheduled(self::CRON_HOOK, $args) : false;

            // Case 1: Milestone is in the future
            if ($ts > $now) {
                if ($scheduled_ts) {
                    return array(
                        'phase'           => $phase,
                        'phase_label'     => $data['label'],
                        'timestamp'       => $ts,
                        'status'          => 'scheduled',
                        'overdue_seconds' => 0,
                    );
                } else {
                    return array(
                        'phase'           => $phase,
                        'phase_label'     => $data['label'],
                        'timestamp'       => $ts,
                        'status'          => 'missing',
                        'overdue_seconds' => 0,
                    );
                }
            }

            // Case 2: Milestone timestamp has passed ($ts <= $now), but event is still in cron table
            if ($scheduled_ts && $scheduled_ts <= $now) {
                return array(
                    'phase'           => $phase,
                    'phase_label'     => $data['label'],
                    'timestamp'       => $scheduled_ts,
                    'status'          => 'overdue',
                    'overdue_seconds' => ($now - $scheduled_ts),
                );
            }
        }

        return null;
    }

    /**
     * Check if any published campaign has a phase change event overdue by more than 2 minutes.
     *
     * @return array<int, array{campaign_id: int, campaign_title: string, phase: string, overdue_minutes: int}>
     */
    public static function get_overdue_campaign_events(): array {
        if (!function_exists('get_posts')) {
            return array();
        }

        $campaign_ids = get_posts(array(
            'post_type'      => 'sneakypeak_campaign',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ));

        $overdue = array();
        $now = time();

        foreach ($campaign_ids as $cid) {
            $campaign = Campaign::get((int) $cid);
            if (!$campaign) {
                continue;
            }
            $diag = self::get_next_phase_change_diagnostics($campaign);
            if ($diag && $diag['status'] === 'overdue' && $diag['overdue_seconds'] > 120) {
                $overdue[] = array(
                    'campaign_id'     => (int) $cid,
                    'campaign_title'  => get_the_title($cid),
                    'phase'           => $diag['phase_label'],
                    'overdue_minutes' => (int) ceil($diag['overdue_seconds'] / 60),
                );
            }
        }

        return $overdue;
    }

    /**
     * Display admin notices for overdue cron events and manual purge feedback.
     */
    public static function display_admin_notices(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Manual purge notice
        if (isset($_GET['sneakypeak_cache_purged']) && $_GET['sneakypeak_cache_purged'] === '1') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?php esc_html_e('SneakyPeak:', 'sneakypeak'); ?></strong> <?php esc_html_e('Cache has been successfully purged across all detected cache plugins and WordPress object cache.', 'sneakypeak'); ?></p>
            </div>
            <?php
        }

        // Overdue WP-Cron notices (> 2 minutes)
        $overdue = self::get_overdue_campaign_events();
        if (!empty($overdue)) {
            foreach ($overdue as $item) {
                ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php esc_html_e('SneakyPeak Warning:', 'sneakypeak'); ?></strong>
                        <?php printf(
                            esc_html__('A scheduled %s phase change for campaign "%s" is overdue by %d minute(s). Phase changes use WP-Cron. For exact timing, add a server cron that calls wp-cron.php every minute, and set define(\'DISABLE_WP_CRON\', true);.', 'sneakypeak'),
                            '<strong>' . esc_html($item['phase']) . '</strong>',
                            esc_html($item['campaign_title']),
                            (int) $item['overdue_minutes']
                        ); ?>
                    </p>
                </div>
                <?php
            }
        }
    }

    /**
     * Handle "Purge Cache Now" button submission from campaign edit screen.
     */
    public static function handle_manual_purge_request(): void {
        if (!isset($_POST['sneakypeak_action']) || $_POST['sneakypeak_action'] !== 'purge_cache_now') {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        check_admin_referer(self::PURGE_NONCE_ACTION, self::PURGE_NONCE_NAME);

        $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;

        // Force purge bypassing throttle for explicit manual action
        self::reset_throttle('Manual admin purge');
        self::purge_all('Manual admin purge', $campaign_id > 0 ? $campaign_id : null);

        $redirect_to = wp_get_referer();
        if (!$redirect_to) {
            $redirect_to = admin_url('edit.php?post_type=sneakypeak_campaign');
        }

        $redirect_to = add_query_arg('sneakypeak_cache_purged', '1', $redirect_to);
        wp_safe_redirect($redirect_to);
        exit;
    }
}
