<?php
namespace SneakyPeak;

defined('ABSPATH') || exit;

use DateTime;
use DateTimeZone;
use SneakyPeak\Support\Safe;

/**
 * Class Preview
 *
 * Single source of truth for SneakyPeak time travel and preview sessions.
 * Manages signed per-user preview cookies, per-campaign preset time travel,
 * admin bar nodes, checkout safety guards (classic & blocks/Store API), and cache suppression.
 */
class Preview {

    public const COOKIE_NAME = 'sneakypeak_preview_session';
    public const NONCE_ACTION = 'sneakypeak_preview_action';

    private static ?array $current_session = null;
    private static bool $checked_session = false;

    public static function init(): void {
        // Handle preview admin bar actions and custom date submissions
        add_action('init', Safe::action(array(__CLASS__, 'handle_preview_action')));

        // Admin bar UI (rendered on frontend and admin for manage_woocommerce users)
        add_action('admin_bar_menu', Safe::action(array(__CLASS__, 'render_admin_bar')), 100);

        // Sticky banner on front end
        add_action('wp_body_open', Safe::action(array(__CLASS__, 'render_preview_banner')), 1);
        add_action('wp_footer', Safe::action(array(__CLASS__, 'render_preview_banner_fallback')), 9999);

        // Admin notices on admin and custom modal on admin footer
        add_action('admin_notices', Safe::action(array(__CLASS__, 'display_admin_notices')));
        add_action('admin_footer', Safe::action(array(__CLASS__, 'render_admin_footer_modal')), 9999);

        // Add sneakypeak-previewing body class
        add_filter('body_class', Safe::filter(array(__CLASS__, 'filter_body_class'), 0));
        add_filter('admin_body_class', Safe::filter(array(__CLASS__, 'filter_admin_body_class'), 0));

        // Safety at classic cart and checkout
        add_action('woocommerce_before_cart', Safe::action(array(__CLASS__, 'render_checkout_notice')));
        add_action('woocommerce_before_checkout_form', Safe::action(array(__CLASS__, 'render_checkout_notice')));
        add_action('woocommerce_after_checkout_validation', Safe::action(array(__CLASS__, 'validate_checkout_preview_safety')), 10, 2);

        // Safety at Cart/Checkout Blocks & Store API checkout path
        self::register_store_api_safety_hooks();

        // Enqueue preview banner styling
        add_action('wp_enqueue_scripts', Safe::action(array(__CLASS__, 'enqueue_preview_styles')));
        add_action('admin_enqueue_scripts', Safe::action(array(__CLASS__, 'enqueue_preview_styles')));
    }

    /**
     * Get the effective global timestamp for SneakyPeak operations.
     * In custom mode, returns the simulated custom timestamp.
     * In preset mode, campaigns compute their own timestamp; this returns time().
     */
    public static function now(): int {
        $session = self::get_current_session();
        if ($session !== null && isset($session['mode']) && $session['mode'] === 'custom' && isset($session['timestamp']) && $session['timestamp'] > 0) {
            return (int) $session['timestamp'];
        }
        return time();
    }

    /**
     * Check if an active preview session is running for the current user
     */
    public static function is_active(): bool {
        return self::get_current_session() !== null;
    }

    /**
     * Enforce cache suppression if preview is active
     */
    public static function suppress_caching_if_active(): void {
        if (!self::is_active()) {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }
        if (!defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
    }

    /**
     * Get validated preview session for current request
     *
     * @return array|null [ 'user_id' => 1, 'mode' => 'preset|custom', 'phase' => '...', 'timestamp' => 0|int, 'campaign_id' => 0|int ]
     */
    public static function get_current_session(): ?array {
        if (self::$checked_session) {
            return self::$current_session;
        }
        self::$checked_session = true;

        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            self::$current_session = null;
            return null;
        }

        $raw = (string) $_COOKIE[self::COOKIE_NAME];
        $parts = explode('|', $raw);
        if (count($parts) !== 6) {
            self::$current_session = null;
            return null;
        }

        list($user_id, $mode, $phase, $timestamp, $campaign_id, $hmac) = $parts;
        $user_id = (int) $user_id;
        $timestamp = (int) $timestamp;
        $campaign_id = (int) $campaign_id;

        // Verify current user matches cookie
        $current_user_id = get_current_user_id();
        if ($user_id <= 0 || $current_user_id !== $user_id) {
            self::$current_session = null;
            return null;
        }

        // Must have manage_woocommerce
        if (!user_can($user_id, 'manage_woocommerce')) {
            self::$current_session = null;
            return null;
        }

        // Verify HMAC signature
        $expected_hmac = self::generate_signature($user_id, $mode, $phase, $timestamp, $campaign_id);
        if (!hash_equals($expected_hmac, $hmac)) {
            self::$current_session = null;
            return null;
        }

        self::$current_session = array(
            'user_id'     => $user_id,
            'mode'        => sanitize_key($mode),
            'phase'       => sanitize_key($phase),
            'timestamp'   => $timestamp,
            'campaign_id' => $campaign_id,
        );

        // Suppress page/object cache headers on preview requests
        self::suppress_caching_if_active();

        return self::$current_session;
    }

    private static function generate_signature(int $user_id, string $mode, string $phase, int $timestamp, int $campaign_id): string {
        $data = $user_id . '|' . $mode . '|' . $phase . '|' . $timestamp . '|' . $campaign_id;
        return wp_hash($data, 'nonce');
    }

    /**
     * Start a preview session and store signed cookie
     */
    public static function start_preview(string $mode, string $phase, int $timestamp = 0, int $campaign_id = 0): void {
        $user_id = get_current_user_id();
        if ($user_id <= 0 || !current_user_can('manage_woocommerce')) {
            return;
        }

        $hmac = self::generate_signature($user_id, $mode, $phase, $timestamp, $campaign_id);
        $cookie_value = $user_id . '|' . $mode . '|' . $phase . '|' . $timestamp . '|' . $campaign_id . '|' . $hmac;

        // Set HttpOnly, SameSite=Lax, 4-hour expiry
        $expire = time() + (4 * HOUR_IN_SECONDS);
        $cookie_path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $is_ssl = is_ssl();

        setcookie(self::COOKIE_NAME, $cookie_value, array(
            'expires'  => $expire,
            'path'     => $cookie_path,
            'domain'   => $cookie_domain,
            'secure'   => $is_ssl,
            'httponly' => true,
            'samesite' => 'Lax',
        ));

        $_COOKIE[self::COOKIE_NAME] = $cookie_value;
        self::$checked_session = false;
        self::get_current_session();
    }

    /**
     * Stop preview session and clear cookie
     */
    public static function stop_preview(): void {
        $cookie_path = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        setcookie(self::COOKIE_NAME, '', array(
            'expires'  => time() - 3600,
            'path'     => $cookie_path,
            'domain'   => $cookie_domain,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        unset($_COOKIE[self::COOKIE_NAME]);
        self::$current_session = null;
        self::$checked_session = true;
    }

    /**
     * Handle preview toggle actions from admin bar or links
     */
    public static function handle_preview_action(): void {
        if (!isset($_GET['sneakypeak_action'])) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $action = sanitize_key($_GET['sneakypeak_action']);

        if ($action === 'exit_preview') {
            check_admin_referer('sneakypeak_exit_preview');
            self::stop_preview();
            self::perform_redirect();
            return;
        }

        if ($action === 'set_preview_phase') {
            check_admin_referer('sneakypeak_set_preview');
            $phase = isset($_GET['phase']) ? sanitize_key($_GET['phase']) : '';
            $campaign_id = isset($_GET['campaign_id']) ? absint($_GET['campaign_id']) : 0;

            $error_reason = '';
            $timestamp = self::calculate_phase_timestamp($phase, $campaign_id, $error_reason);

            if ($timestamp <= 0) {
                // Preset unavailable: show notice and do not start preview
                $reason_text = !empty($error_reason) ? $error_reason : __('the required schedule dates are not set.', 'sneakypeak');
                $phase_label = ucfirst($phase);
                $message = sprintf(
                    __("The %s phase isn't available for this campaign: %s", 'sneakypeak'),
                    $phase_label,
                    $reason_text
                );

                $user_id = get_current_user_id();
                set_transient('sneakypeak_preview_notice_' . $user_id, $message, 60);

                self::perform_redirect();
                return;
            }

            // Per-campaign preset: store chosen phase and campaign_id, timestamp = 0
            self::start_preview('preset', $phase, 0, $campaign_id);
            self::perform_redirect();
            return;
        }

        if ($action === 'set_preview_custom') {
            check_admin_referer('sneakypeak_set_preview_custom');
            $custom_dt = isset($_POST['preview_datetime']) ? trim(sanitize_text_field($_POST['preview_datetime'])) : '';
            if (!empty($custom_dt)) {
                $tz = Campaign::get_store_timezone();
                try {
                    $dt = new DateTime($custom_dt, $tz);
                    $ts = (int) $dt->getTimestamp();
                    if ($ts > 0) {
                        self::start_preview('custom', 'custom', $ts, 0);
                    }
                } catch (\Exception $e) {
                    // Invalid custom date
                }
            }
            self::perform_redirect();
            return;
        }
    }

    /**
     * Redirect after an action using redirect_to parameter validated with wp_validate_redirect
     */
    private static function perform_redirect(): void {
        $target = '';
        if (isset($_REQUEST['redirect_to']) && !empty($_REQUEST['redirect_to'])) {
            $raw_redirect = esc_url_raw(wp_unslash($_REQUEST['redirect_to']));
            $validated = wp_validate_redirect($raw_redirect, false);
            if ($validated) {
                $target = $validated;
            }
        }

        if (empty($target)) {
            $referer = wp_get_referer();
            if ($referer) {
                $target = remove_query_arg(array('sneakypeak_action', 'phase', 'campaign_id', '_wpnonce', 'redirect_to'), $referer);
            } else {
                $target = home_url('/');
            }
        }

        wp_safe_redirect($target);
        exit;
    }

    /**
     * Check if a preset phase is available for a campaign, returning simulated timestamp or 0.
     * If unavailable, returns 0 and sets $error_reason.
     *
     * @param string $phase
     * @param int $campaign_id
     * @param string &$error_reason
     * @return int
     */
    public static function calculate_phase_timestamp(string $phase, int $campaign_id = 0, string &$error_reason = ''): int {
        $campaign = null;
        if ($campaign_id > 0) {
            $campaign = Campaign::get($campaign_id);
        }
        if (!$campaign) {
            $all = \SneakyPeak\Campaigns\Resolver::get_all_published_campaigns();
            $campaign = !empty($all) ? $all[0] : null;
        }

        if (!$campaign) {
            $error_reason = __('no published campaign found', 'sneakypeak');
            return 0;
        }

        $teaser_ts = $campaign->get_teaser_start_timestamp();
        $reveal_ts = $campaign->get_reveal_start_timestamp();
        $end_ts    = $campaign->get_end_timestamp();

        switch ($phase) {
            case Campaign::PHASE_SCHEDULED:
                if ($teaser_ts <= 0) {
                    $error_reason = __('no Teaser Start is set', 'sneakypeak');
                    return 0;
                }
                return $teaser_ts - 1;

            case Campaign::PHASE_TEASER:
                if ($reveal_ts <= 0) {
                    $error_reason = __('no Reveal (Live) Start is set', 'sneakypeak');
                    return 0;
                }
                if ($teaser_ts > 0) {
                    return $teaser_ts + 1;
                }
                return max(1, $reveal_ts - 3600);

            case Campaign::PHASE_LIVE:
                if ($reveal_ts <= 0) {
                    $error_reason = __('no Reveal (Live) Start is set', 'sneakypeak');
                    return 0;
                }
                return $reveal_ts + 1;

            case Campaign::PHASE_ENDED:
                if ($end_ts <= 0) {
                    $error_reason = __('no End Date is set', 'sneakypeak');
                    return 0;
                }
                return $end_ts + 1;

            default:
                $error_reason = __('unknown phase preset', 'sneakypeak');
                return 0;
        }
    }

    /**
     * Compute simulated timestamp for a specific Campaign object under current session
     */
    public static function get_simulated_time_for_campaign(Campaign $campaign): int {
        $session = self::get_current_session();
        if (!$session) {
            return time();
        }

        if ($session['mode'] === 'custom') {
            return ($session['timestamp'] > 0) ? (int) $session['timestamp'] : time();
        }

        // Preset mode: evaluate campaign's own preset timestamp
        $phase = $session['phase'];
        $dummy_reason = '';
        $ts = self::calculate_phase_timestamp($phase, $campaign->get_id(), $dummy_reason);
        if ($ts > 0) {
            return $ts;
        }

        // Preset unavailable for this campaign: fall back to real time
        return time();
    }

    /**
     * Display transient admin notices (e.g. unavailable preset attempts)
     */
    public static function display_admin_notices(): void {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return;
        }

        $transient_key = 'sneakypeak_preview_notice_' . $user_id;
        $message = get_transient($transient_key);
        if (!empty($message)) {
            delete_transient($transient_key);
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
                esc_html__('SneakyPeak Preview Notice:', 'sneakypeak'),
                esc_html($message)
            );
        }
    }

    /**
     * Admin bar UI node with presets and custom date dialog
     */
    public static function render_admin_bar(\WP_Admin_Bar $admin_bar): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $is_active = self::is_active();
        $session = self::get_current_session();

        $title = __('SneakyPeak Preview', 'sneakypeak');
        if ($is_active && $session) {
            $label = ($session['mode'] === 'custom')
                ? self::format_time($session['timestamp'])
                : ucfirst($session['phase']);
            $title = '<span style="color:#ffba00; font-weight:bold;">★ ' . esc_html__('Previewing:', 'sneakypeak') . ' ' . esc_html($label) . '</span>';
        }

        $admin_bar->add_node(array(
            'id'    => 'sneakypeak_preview',
            'title' => $title,
            'href'  => '#',
        ));

        // Off (real time)
        if ($is_active) {
            $exit_url = wp_nonce_url(
                add_query_arg('sneakypeak_action', 'exit_preview'),
                'sneakypeak_exit_preview'
            );
            $admin_bar->add_node(array(
                'id'     => 'sneakypeak_preview_off',
                'parent' => 'sneakypeak_preview',
                'title'  => '<strong>✕ ' . __('Turn Preview OFF (Real Time)', 'sneakypeak') . '</strong>',
                'href'   => $exit_url,
            ));
        }

        // Determine context campaign ID if available
        $context_campaign_id = 0;
        if ($session && !empty($session['campaign_id'])) {
            $context_campaign_id = (int) $session['campaign_id'];
        } elseif (is_admin()) {
            global $post;
            if ($post && isset($post->post_type) && $post->post_type === 'sneakypeak_campaign') {
                $context_campaign_id = $post->ID;
            }
        }

        // Presets
        $phases = array(
            Campaign::PHASE_SCHEDULED => __('Scheduled (Before Teaser)', 'sneakypeak'),
            Campaign::PHASE_TEASER    => __('Teaser (Badges + Masked Price)', 'sneakypeak'),
            Campaign::PHASE_LIVE      => __('Live (Sale Revealed)', 'sneakypeak'),
            Campaign::PHASE_ENDED     => __('Ended (Sale Finished)', 'sneakypeak'),
        );

        foreach ($phases as $ph_key => $ph_title) {
            $reason = '';
            $ts = self::calculate_phase_timestamp($ph_key, $context_campaign_id, $reason);
            $is_available = ($ts > 0);

            $is_current = ($is_active && $session && $session['phase'] === $ph_key);
            $bullet = $is_current ? '● ' : '○ ';

            if ($is_available) {
                $url = wp_nonce_url(
                    add_query_arg(array(
                        'sneakypeak_action' => 'set_preview_phase',
                        'phase'             => $ph_key,
                        'campaign_id'       => $context_campaign_id,
                    )),
                    'sneakypeak_set_preview'
                );
                $node_title = $bullet . $ph_title;
                $node_href  = $url;
            } else {
                // Greyed out and labeled with reason
                $node_title = '<span style="color:#888; cursor:not-allowed;" title="' . esc_attr($reason) . '">' . $bullet . esc_html($ph_title) . ' (' . esc_html($reason) . ')</span>';
                $node_href  = '#';
            }

            $admin_bar->add_node(array(
                'id'     => 'sneakypeak_preview_' . $ph_key,
                'parent' => 'sneakypeak_preview',
                'title'  => $node_title,
                'href'   => $node_href,
            ));
        }

        // Custom Date Item linking to modal / anchor
        $admin_bar->add_node(array(
            'id'     => 'sneakypeak_preview_custom',
            'parent' => 'sneakypeak_preview',
            'title'  => '⏱ ' . __('Custom Date / Time…', 'sneakypeak'),
            'href'   => '#sneakypeak-custom-time-modal',
            'meta'   => array('onclick' => 'sneakypeakOpenCustomModal(); return false;'),
        ));
    }

    /**
     * Sticky top banner on front end when previewing
     */
    private static bool $banner_rendered = false;

    public static function render_preview_banner(): void {
        if (self::$banner_rendered || is_admin() || !self::is_active()) {
            return;
        }
        self::$banner_rendered = true;

        $session = self::get_current_session();
        if (!$session) {
            return;
        }

        $real_formatted = self::format_time(time());
        $phase_label = ($session['mode'] === 'custom')
            ? __('Custom Date/Time', 'sneakypeak')
            : strtoupper($session['phase']);

        // In custom mode, show the custom simulated timestamp.
        // In preset mode, show chosen preset name and check for any non-simulated campaigns.
        $sim_time_display = '';
        if ($session['mode'] === 'custom') {
            $sim_time_display = self::format_time($session['timestamp']);
        }

        // List any campaigns where this preset is unavailable (they run in real time)
        $unsimulated_names = array();
        if ($session['mode'] === 'preset') {
            $all_camps = \SneakyPeak\Campaigns\Resolver::get_all_published_campaigns();
            foreach ($all_camps as $c) {
                $err = '';
                $ts = self::calculate_phase_timestamp($session['phase'], $c->get_id(), $err);
                if ($ts <= 0) {
                    $unsimulated_names[] = get_the_title($c->get_id());
                }
            }
        }

        $exit_url = wp_nonce_url(
            add_query_arg('sneakypeak_action', 'exit_preview'),
            'sneakypeak_exit_preview'
        );

        ?>
        <div id="sneakypeak-preview-banner" role="alert" aria-live="polite">
            <div class="sneakypeak-banner-inner">
                <span class="sneakypeak-banner-badge"><?php esc_html_e('SNEAKYPEAK PREVIEW', 'sneakypeak'); ?></span>
                <span class="sneakypeak-banner-info">
                    <strong><?php esc_html_e('Simulated Phase:', 'sneakypeak'); ?></strong> <?php echo esc_html($phase_label); ?>
                    <?php if (!empty($sim_time_display)) : ?>
                        (<code><?php echo esc_html($sim_time_display); ?></code>)
                    <?php endif; ?>
                    &nbsp;|&nbsp;
                    <strong><?php esc_html_e('Real Time:', 'sneakypeak'); ?></strong> <code><?php echo esc_html($real_formatted); ?></code>
                    <?php if (!empty($unsimulated_names)) : ?>
                        &nbsp;|&nbsp;
                        <span style="color:#ffba00;">
                            <strong><?php esc_html_e('Not simulated:', 'sneakypeak'); ?></strong> <?php echo esc_html(implode(', ', $unsimulated_names)); ?>
                        </span>
                    <?php endif; ?>
                    &nbsp;<em>(<?php esc_html_e('Only you can see this', 'sneakypeak'); ?>)</em>
                </span>
                <a href="<?php echo esc_url($exit_url); ?>" class="sneakypeak-banner-exit-btn">
                    <?php esc_html_e('Exit Preview', 'sneakypeak'); ?>
                </a>
            </div>
        </div>
        <?php
        self::render_custom_time_modal();
    }

    public static function render_preview_banner_fallback(): void {
        self::render_preview_banner();
        if (!is_admin()) {
            self::render_custom_time_modal();
        }
    }

    /**
     * Render modal on admin_footer when admin bar is showing for manage_woocommerce users
     * Only on SneakyPeak campaign screens.
     */
    public static function render_admin_footer_modal(): void {
        if (!is_admin_bar_showing() || !current_user_can('manage_woocommerce')) {
            return;
        }
        if (!function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'sneakypeak_campaign') {
            return;
        }
        self::render_custom_time_modal();
    }

    /**
     * Custom Date/Time modal dialog rendered in footer (single-render guard)
     */
    public static function render_custom_time_modal(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        static $modal_rendered = false;
        if ($modal_rendered) {
            return;
        }
        $modal_rendered = true;

        $tz = Campaign::get_store_timezone();
        $tz_name = function_exists('wp_timezone_string') ? wp_timezone_string() : $tz->getName();
        $now_val = self::format_time(self::now(), 'Y-m-d H:i');

        ?>
        <div id="sneakypeak-custom-time-modal" style="display:none;">
            <div class="sneakypeak-modal-overlay" onclick="sneakypeakCloseCustomModal()"></div>
            <div class="sneakypeak-modal-box">
                <h3><?php esc_html_e('SneakyPeak Time Travel', 'sneakypeak'); ?></h3>
                <p><?php printf(esc_html__('Enter a date & time in the store timezone (%s) to simulate storefront behavior.', 'sneakypeak'), esc_html($tz_name)); ?></p>
                <form method="post" action="<?php echo esc_url(add_query_arg('sneakypeak_action', 'set_preview_custom')); ?>">
                    <?php wp_nonce_field('sneakypeak_set_preview_custom'); ?>
                    <p>
                        <input type="text" name="preview_datetime" value="<?php echo esc_attr($now_val); ?>" placeholder="YYYY-MM-DD HH:MM" required style="width:100%; font-size:15px; padding:8px;" />
                    </p>
                    <div style="display:flex; justify-content:flex-end; gap:8px;">
                        <button type="button" class="button" onclick="sneakypeakCloseCustomModal()"><?php esc_html_e('Cancel', 'sneakypeak'); ?></button>
                        <button type="submit" class="button button-primary" style="background:#0073aa; color:#fff; border:none; padding:6px 14px; border-radius:3px; cursor:pointer;">
                            <?php esc_html_e('Simulate This Time', 'sneakypeak'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <script>
            function sneakypeakOpenCustomModal() {
                var el = document.getElementById('sneakypeak-custom-time-modal');
                if (el) el.style.display = 'block';
            }
            function sneakypeakCloseCustomModal() {
                var el = document.getElementById('sneakypeak-custom-time-modal');
                if (el) el.style.display = 'none';
            }
        </script>
        <?php
    }

    /**
     * Add sneakypeak-previewing body class
     */
    public static function filter_body_class(array $classes): array {
        if (self::is_active()) {
            $classes[] = 'sneakypeak-previewing';
        }
        return $classes;
    }

    public static function filter_admin_body_class(string $classes): string {
        if (!function_exists('get_current_screen')) {
            return $classes;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'sneakypeak_campaign') {
            return $classes;
        }

        if (self::is_active()) {
            $classes .= ' sneakypeak-previewing ';
        }
        return $classes;
    }

    /**
     * Check if checkout safety block should be triggered.
     * Blocks if in custom mode or if ANY published campaign's simulated phase differs from its REAL phase (time()).
     */
    public static function should_block_checkout(): bool {
        if (!self::is_active()) {
            return false;
        }

        if (apply_filters('sneakypeak_allow_preview_checkout', false)) {
            return false;
        }

        $session = self::get_current_session();
        if (!$session) {
            return false;
        }

        // Custom mode simulates an arbitrary time; block order placement
        if ($session['mode'] === 'custom') {
            return true;
        }

        // Check every published campaign: compare simulated phase against real phase
        $real_time = time();
        $all_campaigns = \SneakyPeak\Campaigns\Resolver::get_all_published_campaigns();
        foreach ($all_campaigns as $camp) {
            $sim_phase = $camp->get_phase(); // Evaluates with simulated time
            $real_phase = $camp->get_phase($real_time); // Evaluates with real time()
            if ($sim_phase !== $real_phase) {
                return true;
            }
        }

        return false;
    }

    /**
     * Notice on cart and checkout warning that preview prices are active
     */
    public static function render_checkout_notice(): void {
        if (self::should_block_checkout()) {
            ?>
            <div class="woocommerce-info sneakypeak-preview-notice" style="border-left-color:#ffba00;">
                <strong><?php esc_html_e('SneakyPeak Preview Active:', 'sneakypeak'); ?></strong>
                <?php esc_html_e('Prices shown may differ from live customer prices. Exit preview mode before placing an order.', 'sneakypeak'); ?>
            </div>
            <?php
        }
    }

    /**
     * Validate classic checkout safety: block order placement if preview differs from real phase
     */
    public static function validate_checkout_preview_safety($data, $errors): void {
        if (self::should_block_checkout()) {
            $errors->add(
                'sneakypeak_preview_block',
                __('Orders cannot be placed while SneakyPeak Preview mode is simulating a non-current campaign phase. Please exit preview mode to complete real purchases.', 'sneakypeak')
            );
        }
    }

    /**
     * Register hooks for WooCommerce Cart & Checkout Blocks / Store API
     */
    public static function register_store_api_safety_hooks(): void {
        // 1. Store API checkout update hook (runs before order processing in WooCommerce Blocks Checkout)
        add_action('woocommerce_store_api_checkout_update_order_from_request', Safe::action(array(__CLASS__, 'validate_store_api_checkout_safety')), 10, 2);

        // 2. Store API cart errors filter (adds notice to Blocks Cart / Checkout)
        add_action('woocommerce_store_api_cart_errors', Safe::action(array(__CLASS__, 'filter_store_api_cart_errors')), 10, 2);
    }

    /**
     * Store API Checkout Safety Guard
     *
     * @param \WC_Order $order
     * @param \WP_REST_Request $request
     * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException
     */
    public static function validate_store_api_checkout_safety($order, $request): void {
        if (self::should_block_checkout()) {
            if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    'sneakypeak_preview_block',
                    __('Orders cannot be placed while SneakyPeak Preview mode is simulating a non-current campaign phase. Please exit preview mode to complete real purchases.', 'sneakypeak'),
                    400
                );
            } else {
                wp_send_json_error(array(
                    'message' => __('Orders cannot be placed while SneakyPeak Preview mode is simulating a non-current campaign phase. Please exit preview mode to complete real purchases.', 'sneakypeak'),
                ), 400);
            }
        }
    }

    /**
     * Store API Cart Errors filter for WooCommerce Blocks
     *
     * @param \WP_Error $errors
     * @param \WC_Cart $cart
     */
    public static function filter_store_api_cart_errors($errors, $cart): void {
        if (self::should_block_checkout() && is_a($errors, 'WP_Error')) {
            $errors->add(
                'sneakypeak_preview_cart_notice',
                __('SneakyPeak Preview Active: Prices shown may differ from live customer prices. Exit preview mode before placing an order.', 'sneakypeak')
            );
        }
    }

    /**
     * Enqueue styles for preview banner and dialog
     */
    public static function enqueue_preview_styles(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $css = "
            #sneakypeak-preview-banner {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 999999;
                background: #1d2327;
                color: #f0f0f1;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
                font-size: 13px;
                line-height: 1.4;
                box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                border-bottom: 2px solid #ffba00;
            }
            body.admin-bar #sneakypeak-preview-banner {
                top: 32px;
            }
            @media screen and (max-width: 782px) {
                body.admin-bar #sneakypeak-preview-banner {
                    top: 46px;
                }
            }
            .sneakypeak-banner-inner {
                max-width: 1200px;
                margin: 0 auto;
                padding: 6px 16px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
            }
            .sneakypeak-banner-badge {
                background: #ffba00;
                color: #000;
                font-weight: 800;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
                letter-spacing: 0.5px;
            }
            .sneakypeak-banner-info {
                flex: 1;
                color: #e0e0e0;
            }
            .sneakypeak-banner-info code {
                background: #2c3338;
                padding: 2px 5px;
                border-radius: 3px;
                color: #72aee6;
            }
            .sneakypeak-banner-exit-btn {
                background: #d63638;
                color: #fff !important;
                text-decoration: none;
                font-weight: bold;
                padding: 3px 10px;
                border-radius: 3px;
                font-size: 11px;
                white-space: nowrap;
                transition: background 0.2s;
            }
            .sneakypeak-banner-exit-btn:hover {
                background: #b32d2e;
            }
            body.sneakypeak-previewing {
                margin-top: 36px !important;
            }
            body.admin-bar.sneakypeak-previewing {
                margin-top: 68px !important;
            }
            #sneakypeak-custom-time-modal {
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                z-index: 1000000;
            }
            .sneakypeak-modal-overlay {
                position: absolute;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.6);
            }
            .sneakypeak-modal-box {
                position: absolute;
                top: 50%; left: 50%;
                transform: translate(-50%, -50%);
                background: #fff;
                padding: 24px;
                border-radius: 6px;
                max-width: 440px;
                width: 90%;
                box-shadow: 0 8px 24px rgba(0,0,0,0.3);
                color: #1d2327;
            }
            .sneakypeak-modal-box h3 { margin: 0 0 10px 0; font-size: 18px; }
        ";

        wp_add_inline_style('sneakypeak-frontend', $css);
        if (is_admin()) {
            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();
                if (!$screen || $screen->post_type !== 'sneakypeak_campaign') {
                    return;
                }
            }
            wp_register_style('sneakypeak-admin-preview', false);
            wp_enqueue_style('sneakypeak-admin-preview');
            wp_add_inline_style('sneakypeak-admin-preview', $css);
        }
    }

    public static function format_time(int $timestamp, string $format = 'Y-m-d H:i:s'): string {
        $tz = Campaign::get_store_timezone();
        if (function_exists('wp_date')) {
            return wp_date($format, $timestamp, $tz);
        }
        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone($tz);
        return $dt->format($format);
    }
}
