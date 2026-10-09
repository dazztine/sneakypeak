<?php
/**
 * Plugin Name: SneakyPeak — WooCommerce Sneak Peek & Campaign Reveal
 * Plugin URI:  https://github.com/dazztine/sneakypeak
 * Description: Generalized WooCommerce sneak peek campaigns: custom badges, masked teaser prices, early-sale price guards, and scheduled reveal phases.
 * Version:     1.2.3
 * Author:      dazztine
 * Author URI:  https://github.com/dazztine
 * Text Domain: sneakypeak
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 */

defined('ABSPATH') || exit;

define('SNEAKYPEAK_VERSION', '1.2.3');
define('SNEAKYPEAK_PLUGIN_FILE', __FILE__);
define('SNEAKYPEAK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SNEAKYPEAK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SNEAKYPEAK_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Autoloader for SneakyPeak\ classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'SneakyPeak\\';
    $base_dir = SNEAKYPEAK_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Declare HPOS and Cart/Checkout Blocks compatibility
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SNEAKYPEAK_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', SNEAKYPEAK_PLUGIN_FILE, true);
    }
});

/**
 * Bootstrap plugin on plugins_loaded
 */
add_action('plugins_loaded', function () {
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

    \SneakyPeak\Plugin::init();
});

/**
 * Activation Hook
 */
register_activation_hook(__FILE__, function () {
    // Register custom post type so rewrite rules flush cleanly if needed
    if (class_exists('SneakyPeak\\Campaigns\\PostType')) {
        \SneakyPeak\Campaigns\PostType::register();
    }
    flush_rewrite_rules();

    // Check for 10.10 Sneak Peek migration on activation
    if (class_exists('SneakyPeak\\Migration\\Importer')) {
        \SneakyPeak\Migration\Importer::check_and_migrate();
    }
});

/**
 * Deactivation Hook
 */
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
