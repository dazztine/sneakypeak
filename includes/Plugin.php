<?php
namespace SneakyPeak;

defined('ABSPATH') || exit;

use SneakyPeak\Campaigns\PostType;
use SneakyPeak\Campaigns\Resolver;
use SneakyPeak\Pricing\LegacyPriceSource;
use SneakyPeak\Frontend\Controller;
use SneakyPeak\Admin\MetaBox;
use SneakyPeak\Migration\Importer;

/**
 * Class Plugin
 *
 * Core coordinator initializing SneakyPeak subsystems and wiring dependencies.
 */
class Plugin {

    private static bool $initialized = false;

    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e('SneakyPeak requires WooCommerce to be installed and active.', 'sneakypeak'); ?></p>
                </div>
                <?php
            });
            return;
        }

        self::$initialized = true;

        // 1. Initialize Preview Subsystem (time travel, cookies, admin bar)
        Preview::init();

        // 2. Wire pricing source to resolver
        $price_source = new LegacyPriceSource();
        Resolver::set_price_source($price_source);

        // 3. Initialize Custom Post Type & List Table
        PostType::init();

        // 4. Initialize Admin Meta Boxes & Settings
        if (is_admin()) {
            MetaBox::init();
            Importer::init();
        }

        // 5. Initialize Frontend Controller & Price Guards
        Controller::init();

        // 6. Load Translations
        add_action('init', array(__CLASS__, 'load_textdomain'));
    }

    public static function load_textdomain(): void {
        load_plugin_textdomain(
            'sneakypeak',
            false,
            dirname(SNEAKYPEAK_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Check if the current request is a storefront request.
     * Returns true for storefront page loads, Store API (/wc/store/), and storefront AJAX (including wc-ajax=*).
     * Returns false for is_admin() non-AJAX, other REST routes (/wc/v3, /wp/v2, /wc-analytics), WP-CLI, and cron.
     */
    public static function is_storefront_request(): bool {
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return false;
        }

        // Storefront AJAX via wc-ajax query parameter (e.g. ?wc-ajax=get_refreshed_fragments)
        if (!empty($_GET['wc-ajax'])) {
            return true;
        }

        // wp-admin checks
        if (is_admin()) {
            if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
                $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';
                $storefront_ajax = array(
                    'woocommerce_get_refreshed_fragments',
                    'woocommerce_add_to_cart',
                    'woocommerce_apply_coupon',
                    'woocommerce_remove_coupon',
                    'woocommerce_update_shipping_method',
                );
                return in_array($action, $storefront_ajax, true) || (strpos($action, 'woocommerce_') === 0 && !is_user_logged_in());
            }
            return false;
        }

        // REST API requests
        $is_rest = (defined('REST_REQUEST') && REST_REQUEST);
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $rest_route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
        if (!$is_rest) {
            if ($rest_route !== '' || (strpos($request_uri, '/wp-json/') !== false)) {
                $is_rest = true;
            }
        }

        if ($is_rest) {
            $path = $rest_route !== '' ? $rest_route : $request_uri;
            // Only allow Store API (/wc/store/)
            if (strpos($path, '/wc/store/') !== false || substr($path, -9) === '/wc/store') {
                return true;
            }
            return false;
        }

        return true;
    }
}
