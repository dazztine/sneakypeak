<?php
namespace SneakyPeak\Migration;

defined('ABSPATH') || exit;

use SneakyPeak\Campaign;
use SneakyPeak\Campaigns\PostType;
use SneakyPeak\Campaigns\Resolver;

/**
 * Class Importer
 *
 * Imports settings from the legacy "wc-1010-sneak-peek" plugin (option wc_1010_sneak_peek_options)
 * into a SneakyPeak campaign named "10.10 Sale" without deleting old data.
 * Displays an admin notice recommending deactivation of the legacy plugin if still active.
 */
class Importer {

    public const LEGACY_OPTION_KEY = 'wc_1010_sneak_peek_options';
    public const IMPORTED_FLAG_KEY = 'sneakypeak_1010_imported';

    public static function init(): void {
        add_action('admin_notices', array(__CLASS__, 'render_admin_notices'));
        add_action('admin_post_sneakypeak_import_1010', array(__CLASS__, 'handle_manual_import'));
    }

    /**
     * Check and automatically migrate legacy options on activation or request
     */
    public static function check_and_migrate(): bool {
        $legacy_options = get_option(self::LEGACY_OPTION_KEY);
        if (empty($legacy_options) || !is_array($legacy_options)) {
            return false;
        }

        // Avoid duplicate automatic imports
        if (get_option(self::IMPORTED_FLAG_KEY)) {
            return false;
        }

        return self::import_from_options($legacy_options);
    }

    /**
     * Create campaign post from legacy options
     */
    public static function import_from_options(array $legacy): bool {
        // Create campaign post
        $post_id = wp_insert_post(array(
            'post_title'  => '10.10 Sale',
            'post_type'   => PostType::CPT,
            'post_status' => 'publish',
        ));

        if (is_wp_error($post_id) || !$post_id) {
            return false;
        }

        $defaults = Campaign::get_default_settings();

        $target_cats = array();
        if (!empty($legacy['category_id'])) {
            $cat_id = absint($legacy['category_id']);
            if ($cat_id > 0) {
                $target_cats[] = $cat_id;
            }
        }

        $settings = array_merge($defaults, array(
            'enabled'                => ($legacy['enabled'] ?? 'yes') === 'yes' ? 'yes' : 'no',
            'priority'               => 10,
            'reveal_start_datetime'  => sanitize_text_field($legacy['reveal_datetime'] ?? ''),
            'end_datetime'           => sanitize_text_field($legacy['end_datetime'] ?? ''),
            'target_categories'      => $target_cats,
            'include_subcategories'  => 'yes',
            'badge_corner'           => 'top-right',
            'badge_top_offset'       => absint($legacy['badge_top_offset'] ?? 0),
            'badge_right_offset'     => absint($legacy['badge_right_offset'] ?? 0),
            'badge_shape'            => 'ribbon',
            'badge_text_teaser'      => sanitize_text_field($legacy['badge_text_before'] ?? '10.10 SALE'),
            'badge_text_live'        => sanitize_text_field($legacy['badge_text_after'] ?? '10.10 SALE'),
            'badge_bg_start_teaser'  => sanitize_hex_color($legacy['badge_bg_start'] ?? '') ?: '#ff416c',
            'badge_bg_end_teaser'    => sanitize_hex_color($legacy['badge_bg_end'] ?? '') ?: '#ff4b2b',
            'badge_text_color_teaser'=> sanitize_hex_color($legacy['badge_text_color'] ?? '') ?: '#ffffff',
            'badge_bg_start_live'    => sanitize_hex_color($legacy['badge_bg_start'] ?? '') ?: '#ff416c',
            'badge_bg_end_live'      => sanitize_hex_color($legacy['badge_bg_end'] ?? '') ?: '#ff4b2b',
            'badge_text_color_live'  => sanitize_hex_color($legacy['badge_text_color'] ?? '') ?: '#ffffff',
            'teaser_label'           => sanitize_text_field($legacy['teaser_label'] ?? '10.10 Price:'),
            'teaser_variable_prefix' => sanitize_text_field($legacy['teaser_variable_prefix'] ?? 'From'),
            'mask_rule'              => in_array($legacy['mask_rule'] ?? '', array('keep_first', 'mask_last_3'), true) ? $legacy['mask_rule'] : 'keep_first',
        ));

        update_post_meta($post_id, '_sneakypeak_settings', $settings);
        update_option(self::IMPORTED_FLAG_KEY, $post_id, false);

        Resolver::invalidate_caches();
        return true;
    }

    /**
     * Handle manual import POST request
     */
    public static function handle_manual_import(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized action.', 'sneakypeak'));
        }

        check_admin_referer('sneakypeak_import_1010_action');

        $legacy = get_option(self::LEGACY_OPTION_KEY);
        if (!empty($legacy) && is_array($legacy)) {
            self::import_from_options($legacy);
            wp_safe_redirect(admin_url('edit.php?post_type=' . PostType::CPT . '&imported=1'));
            exit;
        }

        wp_safe_redirect(admin_url('edit.php?post_type=' . PostType::CPT . '&import_error=1'));
        exit;
    }

    /**
     * Render notices on campaign screens
     */
    public static function render_admin_notices(): void {
        global $current_screen;
        if (!$current_screen || $current_screen->post_type !== PostType::CPT) {
            return;
        }

        // Notice 1: Legacy plugin is still active
        if (is_plugin_active('wc-1010-sneak-peek/wc-1010-sneak-peek.php')) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e('Notice:', 'sneakypeak'); ?></strong>
                    <?php esc_html_e('The legacy "WooCommerce 10.10 Sneak Peek" plugin is currently active. To avoid overlapping price filters and badge duplicates, we recommend deactivating it in Plugins.', 'sneakypeak'); ?>
                </p>
            </div>
            <?php
        }

        // Notice 2: Manual import button if legacy options exist but haven't been imported
        $legacy = get_option(self::LEGACY_OPTION_KEY);
        if (!empty($legacy) && is_array($legacy) && !get_option(self::IMPORTED_FLAG_KEY)) {
            $import_url = wp_nonce_url(admin_url('admin-post.php?action=sneakypeak_import_1010'), 'sneakypeak_import_1010_action');
            ?>
            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e('Legacy Data Found:', 'sneakypeak'); ?></strong>
                    <?php esc_html_e('We found existing settings from "10.10 Sneak Peek". You can automatically import them into a new SneakyPeak campaign.', 'sneakypeak'); ?>
                    &nbsp;&nbsp;
                    <a href="<?php echo esc_url($import_url); ?>" class="button button-secondary">
                        <?php esc_html_e('Import from 10.10 Sneak Peek', 'sneakypeak'); ?>
                    </a>
                </p>
            </div>
            <?php
        }

        // Notice 3: Import success message
        if (isset($_GET['imported']) && $_GET['imported'] === '1') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Successfully imported campaign "10.10 Sale" from 10.10 Sneak Peek!', 'sneakypeak'); ?></p>
            </div>
            <?php
        }
    }
}
