<?php
namespace SneakyPeak\Admin;

defined('ABSPATH') || exit;

use SneakyPeak\Campaign;
use SneakyPeak\Campaigns\Resolver;
use DateTime;

/**
 * Class MetaBox
 *
 * Renders and handles saving of SneakyPeak campaign configuration meta boxes:
 * - Status & Overview (Timeline, current phase, store timezone)
 * - Timing & Schedule (Teaser start, Reveal/Live start, End)
 * - Targets & Exclusions (Categories + subcats toggle, tags, specific products, exclusions)
 * - Badge Appearance (Corner, signed nudges X/Y, shape, size, text & colors per phase, custom CSS)
 * - Teaser Settings (Mask rule, prefix, label)
 */
class MetaBox {

    public const NONCE_ACTION = 'sneakypeak_save_campaign_meta';
    public const NONCE_NAME   = 'sneakypeak_meta_nonce';

    public static function init(): void {
        add_action('add_meta_boxes', array(__CLASS__, 'register_meta_boxes'));
        add_action('save_post_sneakypeak_campaign', array(__CLASS__, 'save_meta'), 10, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('admin_notices', array(__CLASS__, 'display_admin_notices'));
        add_filter('upload_mimes', array(__CLASS__, 'filter_upload_mimes'));
    }

    /**
     * Allow font uploads (.woff, .woff2, .ttf) only for manage_options users on campaign screens
     */
    public static function filter_upload_mimes(array $mimes): array {
        if (!current_user_can('manage_options')) {
            return $mimes;
        }
        if (!function_exists('get_current_screen')) {
            return $mimes;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'sneakypeak_campaign') {
            return $mimes;
        }

        $mimes['woff']  = 'font/woff';
        $mimes['woff2'] = 'font/woff2';
        $mimes['ttf']   = 'font/ttf';
        return $mimes;
    }

    public static function display_admin_notices(): void {
        global $post;
        if (!$post || $post->post_type !== 'sneakypeak_campaign') {
            return;
        }

        $user_id = get_current_user_id();
        $transient_key = 'sneakypeak_notices_' . $user_id . '_' . $post->ID;
        $notices = get_transient($transient_key);

        if (!empty($notices) && is_array($notices)) {
            delete_transient($transient_key);
            foreach ($notices as $notice) {
                $type = esc_attr($notice['type'] ?? 'error');
                $message = esc_html($notice['message'] ?? '');
                printf(
                    '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                    $type,
                    $message
                );
            }
        }
    }

    public static function enqueue_admin_assets(string $hook): void {
        if (!function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'sneakypeak_campaign') {
            return;
        }

        if ($hook === 'post.php' || $hook === 'post-new.php') {
            wp_enqueue_media();
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            wp_add_inline_script(
                'wp-color-picker',
                "jQuery(document).ready(function($){ $('.sneakypeak-color-field').wpColorPicker(); });"
            );

            // Enqueue main front-end CSS so mock preview card renders exact badge shapes/ribbons
            wp_enqueue_style(
                'sneakypeak-preview-card-css',
                SNEAKYPEAK_PLUGIN_URL . 'assets/css/sneakypeak.css',
                array(),
                SNEAKYPEAK_VERSION
            );

            // Enqueue admin live preview designer script
            wp_enqueue_script(
                'sneakypeak-admin-designer',
                SNEAKYPEAK_PLUGIN_URL . 'assets/js/sneakypeak-admin.js',
                array('jquery', 'wp-color-picker'),
                SNEAKYPEAK_VERSION,
                true
            );

            wp_localize_script('sneakypeak-admin-designer', 'sneakypeakAdminData', array(
                'currencySymbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '₱',
            ));
        }
    }

    public static function register_meta_boxes(): void {
        add_meta_box(
            'sneakypeak_campaign_settings',
            __('Campaign Settings', 'sneakypeak'),
            array(__CLASS__, 'render_settings_meta_box'),
            'sneakypeak_campaign',
            'normal',
            'high'
        );

        add_meta_box(
            'sneakypeak_campaign_status',
            __('Campaign Phase & Status', 'sneakypeak'),
            array(__CLASS__, 'render_status_meta_box'),
            'sneakypeak_campaign',
            'side',
            'high'
        );

        add_meta_box(
            'sneakypeak_campaign_preview_insights',
            __('SneakyPeak — Live Preview & Insights', 'sneakypeak'),
            array(__CLASS__, 'render_preview_insights_meta_box'),
            'sneakypeak_campaign',
            'normal',
            'default'
        );
    }

    public static function render_status_meta_box(\WP_Post $post): void {
        $campaign = new Campaign($post->ID);
        $phase = $campaign->get_phase();
        $tz = Campaign::get_store_timezone();
        $tz_name = function_exists('wp_timezone_string') ? wp_timezone_string() : $tz->getName();

        $phase_colors = array(
            Campaign::PHASE_DRAFT     => '#888',
            Campaign::PHASE_SCHEDULED => '#0073aa',
            Campaign::PHASE_TEASER    => '#ff8c00',
            Campaign::PHASE_LIVE      => '#46b450',
            Campaign::PHASE_ENDED     => '#999',
        );
        $color = $phase_colors[$phase] ?? '#555';

        $now_formatted = function_exists('wp_date')
            ? wp_date('Y-m-d H:i:s T')
            : date('Y-m-d H:i:s') . ' (' . $tz_name . ')';

        $targeted_count = Resolver::count_targeted_products($campaign);

        ?>
        <div style="padding: 6px 0;">
            <p style="margin: 0 0 10px 0;">
                <strong><?php esc_html_e('Current Phase:', 'sneakypeak'); ?></strong><br>
                <span style="display:inline-block; margin-top:4px; background:<?php echo esc_attr($color); ?>; color:#fff; padding:4px 10px; border-radius:3px; font-weight:bold; font-size:12px; text-transform:uppercase;">
                    <?php echo esc_html(strtoupper($phase)); ?>
                </span>
            </p>
            <hr style="border:0; border-top:1px solid #ddd; margin:10px 0;">
            <p style="font-size:12px; color:#555; margin:0 0 6px 0;">
                <strong><?php esc_html_e('Store Timezone:', 'sneakypeak'); ?></strong><br>
                <code><?php echo esc_html($tz_name); ?></code>
            </p>
            <p style="font-size:12px; color:#555; margin:0 0 6px 0;">
                <strong><?php esc_html_e('Current Time:', 'sneakypeak'); ?></strong><br>
                <code><?php echo esc_html($now_formatted); ?></code>
            </p>
            <p style="font-size:12px; color:#555; margin:0 0 6px 0;">
                <strong><?php esc_html_e('Target Products:', 'sneakypeak'); ?></strong><br>
                <span style="font-size:14px; font-weight:bold; color:#0073aa;"><?php echo (int) $targeted_count; ?></span> <?php esc_html_e('products match', 'sneakypeak'); ?>
            </p>
        </div>
        <?php
    }

    public static function render_settings_meta_box(\WP_Post $post): void {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $campaign = new Campaign($post->ID);
        $settings = $campaign->get_all_settings();

        // Product categories for select
        $categories = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ));

        // Product tags for select
        $tags = get_terms(array(
            'taxonomy'   => 'product_tag',
            'hide_empty' => false,
        ));

        $selected_cats = array_map('intval', (array) ($settings['target_categories'] ?? array()));
        $selected_tags = array_map('intval', (array) ($settings['target_tags'] ?? array()));
        $exclude_cats  = array_map('intval', (array) ($settings['exclude_categories'] ?? array()));

        $target_prods_str  = implode(', ', array_map('intval', (array) ($settings['target_products'] ?? array())));
        $exclude_prods_str = implode(', ', array_map('intval', (array) ($settings['exclude_products'] ?? array())));
        ?>
        <style>
            .post-type-sneakypeak_campaign .postbox .hndle,
            .post-type-sneakypeak_campaign .postbox-header h2 {
                font-size: 15px;
                font-weight: 700;
                letter-spacing: 0.3px;
            }
            .post-type-sneakypeak_campaign .sneakypeak-section { 
                margin-bottom: 24px; 
                padding-bottom: 20px; 
                border-bottom: 1px solid #e5e5e5; 
            }
            .post-type-sneakypeak_campaign .sneakypeak-section:last-child { 
                border-bottom: none; 
            }
            .post-type-sneakypeak_campaign .sneakypeak-section h3 { 
                margin: 0 0 14px 0; 
                font-size: 14px; 
                font-weight: 700; 
                text-transform: uppercase; 
                letter-spacing: 0.5px; 
                color: #1d2327;
                border-bottom: 1px solid #f0f0f1;
                padding-bottom: 8px;
            }
            .post-type-sneakypeak_campaign .sneakypeak-form-table th,
            .post-type-sneakypeak_campaign .form-table th { 
                width: 220px; 
                padding: 10px 10px 10px 0; 
                vertical-align: top; 
                font-weight: 600;
                color: #2c3338;
            }
            .post-type-sneakypeak_campaign .sneakypeak-form-table td { 
                padding: 10px 0; 
            }
            .post-type-sneakypeak_campaign .sneakypeak-form-table select[multiple] { 
                height: 130px; 
                width: 100%; 
                max-width: 450px; 
            }

            /* Section 3 Sticky 2-Column Layout */
            @media (min-width: 1200px) {
                .sneakypeak-badge-designer-container {
                    display: grid;
                    grid-template-columns: 1fr 340px;
                    gap: 24px;
                    align-items: start;
                }
                .sneakypeak-sticky-preview-col {
                    position: sticky;
                    top: 48px;
                }
            }
            @media (max-width: 1199px) {
                .sneakypeak-badge-designer-container {
                    display: block;
                }
                .sneakypeak-sticky-preview-col {
                    margin-top: 24px;
                }
            }

            /* Mock Card Styles */
            .sneakypeak-preview-card-panel {
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                padding: 16px;
                box-sizing: border-box;
            }
            .sneakypeak-preview-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 12px;
                padding-bottom: 8px;
                border-bottom: 1px solid #e2e4e7;
            }
            .sneakypeak-mock-tab-group, .sneakypeak-mock-phase-group {
                display: flex;
                gap: 4px;
            }
            .sneakypeak-mock-card-container {
                width: 100%;
                max-width: 280px;
                margin: 0 auto;
                background: #fff;
                border: 1px solid #e2e4e7;
                border-radius: 4px;
                overflow: hidden;
                position: relative;
                box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            }
            .sneakypeak-mock-image-box {
                position: relative;
                background: #f0f0f1;
                height: 170px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #8c8f94;
                font-size: 36px;
                user-select: none;
            }
            .sneakypeak-mock-details {
                padding: 12px;
            }
            .sneakypeak-mock-title {
                font-size: 13px;
                font-weight: 600;
                margin: 0 0 6px 0;
                color: #1d2327;
            }
            .sneakypeak-mock-price-area, .sneakypeak-mock-single-price-area {
                font-size: 13px;
            }
            .sneakypeak-mock-regular-price, .sneakypeak-mock-single-regular-price {
                color: #50575e;
                margin-right: 6px;
            }
            .sneakypeak-mock-regular-price.strikethrough, .sneakypeak-mock-single-regular-price.strikethrough {
                text-decoration: line-through;
                color: #8c8f94;
            }

            /* Single Product Mock Styles */
            .sneakypeak-mock-single-layout {
                display: grid;
                grid-template-columns: 120px 1fr;
                gap: 12px;
                background: #fff;
                border: 1px solid #e2e4e7;
                border-radius: 4px;
                padding: 10px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.06);
                box-sizing: border-box;
            }
            .sneakypeak-mock-single-gallery-col {
                display: flex;
                flex-direction: column;
            }
            .sneakypeak-mock-single-main-image {
                position: relative;
                background: #f0f0f1;
                height: 120px;
                border-radius: 3px;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                color: #8c8f94;
                font-size: 26px;
                user-select: none;
            }
            .sneakypeak-mock-single-thumbs {
                display: flex;
                gap: 4px;
                margin-top: 6px;
            }
            .sneakypeak-mock-single-thumb {
                width: 22px;
                height: 22px;
                background: #e5e5e5;
                border-radius: 2px;
                border: 1px solid #dcdcde;
            }
            .sneakypeak-mock-single-thumb.active {
                border-color: #0073aa;
                background: #d0e7f7;
            }
            .sneakypeak-mock-single-summary-col {
                display: flex;
                flex-direction: column;
                justify-content: flex-start;
                min-width: 0;
            }
            .sneakypeak-mock-single-title {
                font-size: 13px;
                font-weight: 700;
                margin: 0 0 6px 0;
                color: #1d2327;
                line-height: 1.3;
            }
            .sneakypeak-mock-cart-btn {
                margin-top: 10px !important;
                font-size: 11px !important;
                height: 26px !important;
                line-height: 24px !important;
                padding: 0 10px !important;
                align-self: flex-start;
            }
        </style>

        <!-- 1. GENERAL & TIMING -->
        <div class="sneakypeak-section">
            <h3><?php esc_html_e('1. General & Timing', 'sneakypeak'); ?></h3>
            <table class="form-table sneakypeak-form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Campaign Enabled', 'sneakypeak'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="sneakypeak[enabled]" value="yes" <?php checked($settings['enabled'] ?? 'yes', 'yes'); ?> />
                            <?php esc_html_e('Activate this campaign', 'sneakypeak'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Priority', 'sneakypeak'); ?></th>
                    <td>
                        <input type="number" min="1" max="1000" name="sneakypeak[priority]" value="<?php echo esc_attr($settings['priority'] ?? 10); ?>" class="small-text" />
                        <p class="description"><?php esc_html_e('Lower number wins if a product is targeted by multiple active campaigns (e.g. 1 beats 10).', 'sneakypeak'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Teaser Start Date & Time', 'sneakypeak'); ?></th>
                    <td>
                        <input type="text" name="sneakypeak[teaser_start_datetime]" value="<?php echo esc_attr($settings['teaser_start_datetime'] ?? ''); ?>" class="regular-text" placeholder="YYYY-MM-DD HH:MM" />
                        <p class="description"><?php esc_html_e('Optional. When to begin showing teaser badges and masked prices. Leave blank to start immediately upon publishing.', 'sneakypeak'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Reveal (Live) Start Date & Time', 'sneakypeak'); ?></th>
                    <td>
                        <input type="text" name="sneakypeak[reveal_start_datetime]" value="<?php echo esc_attr($settings['reveal_start_datetime'] ?? ''); ?>" class="regular-text" placeholder="YYYY-MM-DD HH:MM" required />
                        <p class="description"><strong><?php esc_html_e('Required.', 'sneakypeak'); ?></strong> <?php esc_html_e('When live prices unlock and the badge switches to the Live phase.', 'sneakypeak'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Campaign End Date & Time', 'sneakypeak'); ?></th>
                    <td>
                        <input type="text" name="sneakypeak[end_datetime]" value="<?php echo esc_attr($settings['end_datetime'] ?? ''); ?>" class="regular-text" placeholder="YYYY-MM-DD HH:MM" />
                        <p class="description"><?php esc_html_e('Optional. When the sale ends. Badges/teasers are removed and normal WooCommerce prices apply.', 'sneakypeak'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- 2. TARGETS & EXCLUSIONS -->
        <div class="sneakypeak-section">
            <h3><?php esc_html_e('2. Targets & Exclusions', 'sneakypeak'); ?></h3>
            <table class="form-table sneakypeak-form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Target Product Categories', 'sneakypeak'); ?></th>
                    <td>
                        <select name="sneakypeak[target_categories][]" multiple="multiple">
                            <?php if (!is_wp_error($categories) && !empty($categories)) : ?>
                                <?php foreach ($categories as $cat) : ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected(in_array((int) $cat->term_id, $selected_cats, true)); ?>>
                                        <?php echo esc_html($cat->name . ' (' . $cat->count . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <br>
                        <label style="margin-top:6px; display:inline-block;">
                            <input type="checkbox" name="sneakypeak[include_subcategories]" value="yes" <?php checked($settings['include_subcategories'] ?? 'yes', 'yes'); ?> />
                            <?php esc_html_e('Include all subcategories of selected categories', 'sneakypeak'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Target Product Tags', 'sneakypeak'); ?></th>
                    <td>
                        <select name="sneakypeak[target_tags][]" multiple="multiple">
                            <?php if (!is_wp_error($tags) && !empty($tags)) : ?>
                                <?php foreach ($tags as $tag) : ?>
                                    <option value="<?php echo esc_attr($tag->term_id); ?>" <?php selected(in_array((int) $tag->term_id, $selected_tags, true)); ?>>
                                        <?php echo esc_html($tag->name . ' (' . $tag->count . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Hold Ctrl (or Cmd) to select multiple tags.', 'sneakypeak'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Specific Product IDs', 'sneakypeak'); ?></th>
                    <td>
                        <input type="text" name="sneakypeak[target_products]" value="<?php echo esc_attr($target_prods_str); ?>" class="regular-text" placeholder="101, 102, 103" />
                        <p class="description"><?php esc_html_e('Comma-separated product IDs to include explicitly.', 'sneakypeak'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Exclude Product Categories', 'sneakypeak'); ?></th>
                    <td>
                        <select name="sneakypeak[exclude_categories][]" multiple="multiple">
                            <?php if (!is_wp_error($categories) && !empty($categories)) : ?>
                                <?php foreach ($categories as $cat) : ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected(in_array((int) $cat->term_id, $exclude_cats, true)); ?>>
                                        <?php echo esc_html($cat->name . ' (' . $cat->count . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Categories to explicitly exclude from this campaign.', 'sneakypeak'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Exclude Product IDs', 'sneakypeak'); ?></th>
                    <td>
                        <input type="text" name="sneakypeak[exclude_products]" value="<?php echo esc_attr($exclude_prods_str); ?>" class="regular-text" placeholder="201, 202" />
                        <p class="description"><?php esc_html_e('Comma-separated product IDs to explicitly exclude.', 'sneakypeak'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- 3. BADGE DESIGN & POSITIONING -->
        <div class="sneakypeak-section">
            <h3><?php esc_html_e('3. Badge Design & Placement', 'sneakypeak'); ?></h3>
            <p class="description" style="margin-bottom:16px;">
                <strong><?php esc_html_e('Rule:', 'sneakypeak'); ?></strong>
                <?php esc_html_e('Badges attach to the product CARD element only via absolute positioning. The plugin never wraps or touches product thumbnails.', 'sneakypeak'); ?>
            </p>

            <div class="sneakypeak-badge-designer-container">
                <!-- Settings Column (Left) -->
                <div class="sneakypeak-badge-settings-col">
                    <table class="form-table sneakypeak-form-table" style="margin-top:0;">
                        <tr>
                            <th scope="row"><?php esc_html_e('Card Corner', 'sneakypeak'); ?></th>
                            <td>
                                <select name="sneakypeak[badge_corner]">
                                    <option value="top-right" <?php selected($settings['badge_corner'] ?? 'top-right', 'top-right'); ?>><?php esc_html_e('Top Right (Default)', 'sneakypeak'); ?></option>
                                    <option value="top-left" <?php selected($settings['badge_corner'] ?? '', 'top-left'); ?>><?php esc_html_e('Top Left', 'sneakypeak'); ?></option>
                                    <option value="bottom-left" <?php selected($settings['badge_corner'] ?? '', 'bottom-left'); ?>><?php esc_html_e('Bottom Left', 'sneakypeak'); ?></option>
                                    <option value="bottom-right" <?php selected($settings['badge_corner'] ?? '', 'bottom-right'); ?>><?php esc_html_e('Bottom Right', 'sneakypeak'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Position Nudge (px)', 'sneakypeak'); ?></th>
                            <td>
                                <?php
                                list($nudge_x_val, $nudge_y_val) = $campaign->get_nudge_offsets();
                                ?>
                                <label style="margin-right:16px;">
                                    <?php esc_html_e('Horizontal (X):', 'sneakypeak'); ?>
                                    <input type="number" min="-100" max="100" step="1" name="sneakypeak[badge_nudge_x]" value="<?php echo esc_attr($nudge_x_val); ?>" class="small-text" /> px
                                </label>
                                <label>
                                    <?php esc_html_e('Vertical (Y):', 'sneakypeak'); ?>
                                    <input type="number" min="-100" max="100" step="1" name="sneakypeak[badge_nudge_y]" value="<?php echo esc_attr($nudge_y_val); ?>" class="small-text" /> px
                                </label>
                                <p class="description" style="margin-top:6px;">
                                    <?php esc_html_e('Move the badge by pixels from its corner. Right/down are positive, left/up are negative.', 'sneakypeak'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Badge Shape', 'sneakypeak'); ?></th>
                            <td>
                                <select name="sneakypeak[badge_shape]">
                                    <option value="ribbon" <?php selected($settings['badge_shape'] ?? 'ribbon', 'ribbon'); ?>><?php esc_html_e('Ribbon (Angled left edge)', 'sneakypeak'); ?></option>
                                    <option value="pill" <?php selected($settings['badge_shape'] ?? '', 'pill'); ?>><?php esc_html_e('Pill (Rounded tag)', 'sneakypeak'); ?></option>
                                    <option value="circle" <?php selected($settings['badge_shape'] ?? '', 'circle'); ?>><?php esc_html_e('Circle', 'sneakypeak'); ?></option>
                                    <option value="flag" <?php selected($settings['badge_shape'] ?? '', 'flag'); ?>><?php esc_html_e('Flag (Notched bookmark)', 'sneakypeak'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Badge Size & Font', 'sneakypeak'); ?></th>
                            <td>
                                <select name="sneakypeak[badge_size]">
                                    <option value="small" <?php selected($settings['badge_size'] ?? 'medium', 'small'); ?>><?php esc_html_e('Small', 'sneakypeak'); ?></option>
                                    <option value="medium" <?php selected($settings['badge_size'] ?? 'medium', 'medium'); ?>><?php esc_html_e('Medium (Default)', 'sneakypeak'); ?></option>
                                    <option value="large" <?php selected($settings['badge_size'] ?? 'medium', 'large'); ?>><?php esc_html_e('Large', 'sneakypeak'); ?></option>
                                </select>
                                &nbsp;&nbsp;
                                <label>
                                    <?php esc_html_e('Font Size:', 'sneakypeak'); ?>
                                    <input type="number" min="9" max="24" name="sneakypeak[badge_font_size]" value="<?php echo esc_attr($settings['badge_font_size'] ?? 12); ?>" class="small-text" /> px
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Badge Icon', 'sneakypeak'); ?></th>
                            <td>
                                <select name="sneakypeak[badge_icon]">
                                    <option value="star" <?php selected($settings['badge_icon'] ?? 'star', 'star'); ?>><?php esc_html_e('Gold Star (★)', 'sneakypeak'); ?></option>
                                    <option value="fire" <?php selected($settings['badge_icon'] ?? '', 'fire'); ?>><?php esc_html_e('Fire (🔥)', 'sneakypeak'); ?></option>
                                    <option value="tag" <?php selected($settings['badge_icon'] ?? '', 'tag'); ?>><?php esc_html_e('Price Tag (🏷)', 'sneakypeak'); ?></option>
                                    <option value="none" <?php selected($settings['badge_icon'] ?? '', 'none'); ?>><?php esc_html_e('None', 'sneakypeak'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Teaser Phase Badge', 'sneakypeak'); ?></th>
                            <td>
                                <input type="text" name="sneakypeak[badge_text_teaser]" value="<?php echo esc_attr($settings['badge_text_teaser'] ?? 'SNEAK PEEK'); ?>" class="regular-text" /><br><br>
                                <label><?php esc_html_e('Gradient Start:', 'sneakypeak'); ?> <input type="text" name="sneakypeak[badge_bg_start_teaser]" value="<?php echo esc_attr($settings['badge_bg_start_teaser'] ?? '#ff416c'); ?>" class="sneakypeak-color-field" /></label>&nbsp;&nbsp;
                                <label><?php esc_html_e('Gradient End:', 'sneakypeak'); ?> <input type="text" name="sneakypeak[badge_bg_end_teaser]" value="<?php echo esc_attr($settings['badge_bg_end_teaser'] ?? '#ff4b2b'); ?>" class="sneakypeak-color-field" /></label>&nbsp;&nbsp;
                                <label><?php esc_html_e('Text Color:', 'sneakypeak'); ?> <input type="text" name="sneakypeak[badge_text_color_teaser]" value="<?php echo esc_attr($settings['badge_text_color_teaser'] ?? '#ffffff'); ?>" class="sneakypeak-color-field" /></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Live Phase Badge', 'sneakypeak'); ?></th>
                            <td>
                                <input type="text" name="sneakypeak[badge_text_live]" value="<?php echo esc_attr($settings['badge_text_live'] ?? 'SALE NOW'); ?>" class="regular-text" /><br><br>
                                <label><?php esc_html_e('Gradient Start:', 'sneakypeak'); ?> <input type="text" name="sneakypeak[badge_bg_start_live]" value="<?php echo esc_attr($settings['badge_bg_start_live'] ?? '#ff416c'); ?>" class="sneakypeak-color-field" /></label>&nbsp;&nbsp;
                                <label><?php esc_html_e('Gradient End:', 'sneakypeak'); ?> <input type="text" name="sneakypeak[badge_bg_end_live]" value="<?php echo esc_attr($settings['badge_bg_end_live'] ?? '#ff4b2b'); ?>" class="sneakypeak-color-field" /></label>&nbsp;&nbsp;
                                <label><?php esc_html_e('Text Color:', 'sneakypeak'); ?> <input type="text" name="sneakypeak[badge_text_color_live]" value="<?php echo esc_attr($settings['badge_text_color_live'] ?? '#ffffff'); ?>" class="sneakypeak-color-field" /></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Custom CSS', 'sneakypeak'); ?></th>
                            <td>
                                <textarea name="sneakypeak[badge_custom_css]" rows="4" class="large-text code"><?php echo esc_textarea($settings['badge_custom_css'] ?? ''); ?></textarea>
                                <p class="description"><?php esc_html_e('Optional custom CSS injected specifically when this campaign is active.', 'sneakypeak'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th colspan="2" style="padding-top:16px; border-top:1px dashed #ddd;">
                                <h4 style="margin:4px 0 0; font-size:13px; text-transform:uppercase; color:#1d2327;"><?php esc_html_e('Single Product View Badge Placement', 'sneakypeak'); ?></h4>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Placement Location', 'sneakypeak'); ?></th>
                            <td>
                                <select name="sneakypeak[single_badge_position]" id="sneakypeak-single-badge-position">
                                    <option value="gallery" <?php selected($settings['single_badge_position'] ?? 'gallery', 'gallery'); ?>><?php esc_html_e('Over main gallery image (Default)', 'sneakypeak'); ?></option>
                                    <option value="summary" <?php selected($settings['single_badge_position'] ?? '', 'summary'); ?>><?php esc_html_e('Inside product summary (above title/price)', 'sneakypeak'); ?></option>
                                    <option value="custom" <?php selected($settings['single_badge_position'] ?? '', 'custom'); ?>><?php esc_html_e('Custom CSS selector', 'sneakypeak'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Choose where the promo badge is anchored on the single product page.', 'sneakypeak'); ?></p>
                            </td>
                        </tr>
                        <tr id="sneakypeak-single-custom-selector-row" style="<?php echo (($settings['single_badge_position'] ?? 'gallery') === 'custom') ? '' : 'display:none;'; ?>">
                            <th scope="row"><?php esc_html_e('Target CSS Selector', 'sneakypeak'); ?></th>
                            <td>
                                <input type="text" name="sneakypeak[single_badge_custom_selector]" value="<?php echo esc_attr($settings['single_badge_custom_selector'] ?? ''); ?>" class="regular-text" placeholder=".product-gallery, .entry-summary, etc." />
                                <p class="description"><?php esc_html_e('Element selector where the badge will be injected on single product pages.', 'sneakypeak'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Single View Corner & Nudge', 'sneakypeak'); ?></th>
                            <td>
                                <select name="sneakypeak[single_badge_corner]" style="margin-bottom:8px;">
                                    <option value="top-right" <?php selected($settings['single_badge_corner'] ?? 'top-right', 'top-right'); ?>><?php esc_html_e('Top Right', 'sneakypeak'); ?></option>
                                    <option value="top-left" <?php selected($settings['single_badge_corner'] ?? '', 'top-left'); ?>><?php esc_html_e('Top Left', 'sneakypeak'); ?></option>
                                    <option value="bottom-left" <?php selected($settings['single_badge_corner'] ?? '', 'bottom-left'); ?>><?php esc_html_e('Bottom Left', 'sneakypeak'); ?></option>
                                    <option value="bottom-right" <?php selected($settings['single_badge_corner'] ?? '', 'bottom-right'); ?>><?php esc_html_e('Bottom Right', 'sneakypeak'); ?></option>
                                </select>
                                <br>
                                <?php list($single_nudge_x, $single_nudge_y) = $campaign->get_single_nudge_offsets(); ?>
                                <label style="margin-right:16px;">
                                    <?php esc_html_e('Horizontal (X):', 'sneakypeak'); ?>
                                    <input type="number" min="-100" max="100" step="1" name="sneakypeak[single_badge_nudge_x]" value="<?php echo esc_attr($single_nudge_x); ?>" class="small-text" /> px
                                </label>
                                <label>
                                    <?php esc_html_e('Vertical (Y):', 'sneakypeak'); ?>
                                    <input type="number" min="-100" max="100" step="1" name="sneakypeak[single_badge_nudge_y]" value="<?php echo esc_attr($single_nudge_y); ?>" class="small-text" /> px
                                </label>
                                <p class="description" style="margin-top:6px;">
                                    <?php esc_html_e('Independent fine-tuning nudge for single product view.', 'sneakypeak'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Sticky Mock Card Preview Column (Right) -->
                <div class="sneakypeak-sticky-preview-col">
                    <div class="sneakypeak-preview-card-panel">
                        <div class="sneakypeak-preview-card-header">
                            <div class="sneakypeak-mock-tab-group">
                                <button type="button" class="button button-small sneakypeak-mock-tab-toggle active button-primary" data-tab="shop"><?php esc_html_e('Shop card', 'sneakypeak'); ?></button>
                                <button type="button" class="button button-small sneakypeak-mock-tab-toggle button-secondary" data-tab="single"><?php esc_html_e('Single product', 'sneakypeak'); ?></button>
                            </div>
                            <div class="sneakypeak-mock-phase-group">
                                <button type="button" class="button button-small sneakypeak-mock-phase-toggle button-primary active" data-phase="teaser"><?php esc_html_e('Teaser', 'sneakypeak'); ?></button>
                                <button type="button" class="button button-small sneakypeak-mock-phase-toggle button-secondary" data-phase="live"><?php esc_html_e('Live', 'sneakypeak'); ?></button>
                            </div>
                        </div>

                        <?php
                        $curr_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '₱';
                        ?>

                        <!-- 1. Shop Card Mock Section -->
                        <div id="sneakypeak-mock-shop-container" class="sneakypeak-mock-view-panel">
                            <h4 style="margin:0 0 10px 0; font-size:12px; font-weight:700; color:#1d2327; text-transform:uppercase; letter-spacing:0.5px;"><?php esc_html_e('Badge preview', 'sneakypeak'); ?></h4>
                            <div class="sneakypeak-mock-card-container" id="sneakypeak-mock-card">
                                <!-- Dynamic Badge Mock -->
                                <span id="sneakypeak-mock-badge-wrap" class="sneakypeak-badge-wrap sneakypeak-corner-top-right sneakypeak-shape-ribbon sneakypeak-size-medium">
                                    <span id="sneakypeak-mock-badge" class="sneakypeak-badge sneakypeak-badge-teaser sneakypeak-badge-shape-ribbon sneakypeak-badge-size-medium">
                                        <span id="sneakypeak-mock-icon" class="sneakypeak-badge-icon sneakypeak-icon-star">&#9733;&nbsp;</span>
                                        <span id="sneakypeak-mock-text">SNEAK PEEK</span>
                                    </span>
                                </span>

                                <div class="sneakypeak-mock-image-box">
                                    <span>📦</span>
                                </div>
                                <div class="sneakypeak-mock-details">
                                    <h4 class="sneakypeak-mock-title"><?php esc_html_e('Sample Product Card', 'sneakypeak'); ?></h4>
                                    <div class="sneakypeak-mock-price-area">
                                        <span class="sneakypeak-mock-regular-price"><?php echo esc_html($curr_symbol); ?>12,500.00</span>
                                        <strong class="sneakypeak-mock-live-price" style="display:none; color:#d63638;"><?php echo esc_html($curr_symbol); ?>8,499.00</strong>
                                        <div class="sneakypeak-mock-teaser-line sneakypeak-teaser-wrap">
                                            <span class="sneakypeak-teaser-label"><?php echo esc_html($settings['teaser_label'] ?? 'Sale Price:'); ?></span>
                                            <span class="sneakypeak-teaser-price"><?php echo esc_html($curr_symbol); ?>8,???</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Single Product Page Mock Section -->
                        <div id="sneakypeak-mock-single-container" class="sneakypeak-mock-view-panel" style="display:none;">
                            <h4 style="margin:0 0 10px 0; font-size:12px; font-weight:700; color:#1d2327; text-transform:uppercase; letter-spacing:0.5px;"><?php esc_html_e('Single product page preview', 'sneakypeak'); ?></h4>
                            <div class="sneakypeak-mock-single-layout" id="sneakypeak-mock-single">
                                <!-- Gallery Column -->
                                <div class="sneakypeak-mock-single-gallery-col">
                                    <div class="sneakypeak-mock-single-main-image" id="sneakypeak-mock-single-image-box">
                                        <span class="sneakypeak-mock-single-image-placeholder">📷</span>
                                        <!-- Main Gallery Badge Target -->
                                        <span id="sneakypeak-mock-single-gallery-badge-wrap" class="sneakypeak-badge-wrap sneakypeak-badge-single-wrap sneakypeak-corner-top-right sneakypeak-shape-ribbon sneakypeak-size-medium">
                                            <span id="sneakypeak-mock-single-gallery-badge" class="sneakypeak-badge sneakypeak-badge-teaser sneakypeak-badge-single sneakypeak-badge-shape-ribbon sneakypeak-badge-size-medium">
                                                <span id="sneakypeak-mock-single-gallery-icon" class="sneakypeak-badge-icon sneakypeak-icon-star">&#9733;&nbsp;</span>
                                                <span id="sneakypeak-mock-single-gallery-text">SNEAK PEEK</span>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="sneakypeak-mock-single-thumbs">
                                        <span class="sneakypeak-mock-single-thumb active"></span>
                                        <span class="sneakypeak-mock-single-thumb"></span>
                                        <span class="sneakypeak-mock-single-thumb"></span>
                                    </div>
                                </div>

                                <!-- Summary Column -->
                                <div class="sneakypeak-mock-single-summary-col">
                                    <!-- Inside Summary / Custom Selector Badge Target (above title) -->
                                    <div id="sneakypeak-mock-single-summary-badge-container" style="display:none; margin-bottom:8px; line-height:1;">
                                        <span id="sneakypeak-mock-single-summary-badge-wrap" class="sneakypeak-badge-wrap sneakypeak-badge-single-wrap sneakypeak-shape-ribbon sneakypeak-size-medium" style="position:relative; display:inline-block; top:auto; left:auto; right:auto; bottom:auto;">
                                            <span id="sneakypeak-mock-single-summary-badge" class="sneakypeak-badge sneakypeak-badge-teaser sneakypeak-badge-single sneakypeak-badge-shape-ribbon sneakypeak-badge-size-medium">
                                                <span id="sneakypeak-mock-single-summary-icon" class="sneakypeak-badge-icon sneakypeak-icon-star">&#9733;&nbsp;</span>
                                                <span id="sneakypeak-mock-single-summary-text">SNEAK PEEK</span>
                                            </span>
                                        </span>
                                    </div>

                                    <h4 class="sneakypeak-mock-single-title"><?php esc_html_e('Premium Athletic Shoes', 'sneakypeak'); ?></h4>
                                    <div class="sneakypeak-mock-single-price-area">
                                        <span class="sneakypeak-mock-single-regular-price"><?php echo esc_html($curr_symbol); ?>12,500.00</span>
                                        <strong class="sneakypeak-mock-single-live-price" style="display:none; color:#d63638;"><?php echo esc_html($curr_symbol); ?>8,499.00</strong>
                                        <div class="sneakypeak-mock-single-teaser-line sneakypeak-teaser-wrap">
                                            <span class="sneakypeak-teaser-label"><?php echo esc_html($settings['teaser_label'] ?? 'Sale Price:'); ?></span>
                                            <span class="sneakypeak-teaser-price"><?php echo esc_html($curr_symbol); ?>8,???</span>
                                        </div>
                                    </div>

                                    <button type="button" class="button button-primary sneakypeak-mock-cart-btn" disabled><?php esc_html_e('Add to cart', 'sneakypeak'); ?></button>
                                </div>
                            </div>

                            <!-- Note under mock for Custom CSS Selector mode -->
                            <div id="sneakypeak-mock-single-custom-note" style="display:none; margin-top:10px; padding:6px 8px; background:#fff; border:1px solid #ccd0d4; border-radius:3px; font-size:11px; color:#50575e; line-height:1.4;">
                                <span class="dashicons dashicons-info" style="font-size:14px; width:14px; height:14px; vertical-align:text-top; margin-right:2px;"></span>
                                <span id="sneakypeak-mock-single-custom-note-text"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. TEASER PRICE DISPLAY -->
        <div class="sneakypeak-section">
            <h3><?php esc_html_e('4. Teaser Price Settings (Before Live)', 'sneakypeak'); ?></h3>
            <table class="form-table sneakypeak-form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Masking Logic', 'sneakypeak'); ?></th>
                    <td>
                        <select name="sneakypeak[mask_rule]">
                            <option value="keep_first" <?php selected($settings['mask_rule'] ?? 'keep_first', 'keep_first'); ?>><?php esc_html_e('Keep 1st digit, mask rest (e.g. 8,499 -> 8,??? | 120 -> 1??)', 'sneakypeak'); ?></option>
                            <option value="mask_last_3" <?php selected($settings['mask_rule'] ?? '', 'mask_last_3'); ?>><?php esc_html_e('Mask last 3 digits (e.g. 8,499 -> 8,??? | 12,500 -> 12,???)', 'sneakypeak'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Teaser Label', 'sneakypeak'); ?></th>
                    <td>
                        <input type="text" name="sneakypeak[teaser_label]" value="<?php echo esc_attr($settings['teaser_label'] ?? 'Sale Price:'); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Label above masked price (e.g. "Sale Price:" or "10.10 Price:").', 'sneakypeak'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Variable Product Prefix', 'sneakypeak'); ?></th>
                    <td>
                        <input type="text" name="sneakypeak[teaser_variable_prefix]" value="<?php echo esc_attr($settings['teaser_variable_prefix'] ?? 'From'); ?>" class="small-text" />
                        <p class="description"><?php esc_html_e('Prepended to variable teaser prices (e.g. "From ₱8,???").', 'sneakypeak'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Teaser Font Style', 'sneakypeak'); ?></th>
                    <td>
                        <select name="sneakypeak[teaser_font_family]" id="sneakypeak-teaser-font-family">
                            <option value="inherit" <?php selected($settings['teaser_font_family'] ?? 'inherit', 'inherit'); ?>><?php esc_html_e('Theme default', 'sneakypeak'); ?></option>
                            <optgroup label="<?php esc_attr_e('Curated Geometric Sans (Adidas / Modern Retail Style)', 'sneakypeak'); ?>">
                                <option value="jost" <?php selected($settings['teaser_font_family'] ?? '', 'jost'); ?>>Jost (Geometric Sans / Futura-style)</option>
                                <option value="montserrat" <?php selected($settings['teaser_font_family'] ?? '', 'montserrat'); ?>>Montserrat (Bold Urban / Retail)</option>
                                <option value="poppins" <?php selected($settings['teaser_font_family'] ?? '', 'poppins'); ?>>Poppins (Clean Geometric)</option>
                                <option value="outfit" <?php selected($settings['teaser_font_family'] ?? '', 'outfit'); ?>>Outfit (Contemporary Display)</option>
                                <option value="inter" <?php selected($settings['teaser_font_family'] ?? '', 'inter'); ?>>Inter (Modern Grotesque)</option>
                                <option value="questrial" <?php selected($settings['teaser_font_family'] ?? '', 'questrial'); ?>>Questrial (Avant Garde Curves)</option>
                            </optgroup>
                            <option value="custom" <?php selected($settings['teaser_font_family'] ?? '', 'custom'); ?>><?php esc_html_e('Upload Custom Font (.woff2, .woff, .ttf)', 'sneakypeak'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Custom typography for the teaser sale price and label to match brand aesthetics.', 'sneakypeak'); ?></p>
                    </td>
                </tr>
                <tr id="sneakypeak-custom-font-row" style="<?php echo (($settings['teaser_font_family'] ?? 'inherit') === 'custom') ? '' : 'display:none;'; ?>">
                    <th scope="row"><?php esc_html_e('Custom Font File', 'sneakypeak'); ?></th>
                    <td>
                        <div style="display:flex; align-items:center; gap:8px; max-width:550px;">
                            <input type="text" name="sneakypeak[teaser_font_custom_url]" id="sneakypeak-custom-font-url" value="<?php echo esc_url($settings['teaser_font_custom_url'] ?? ''); ?>" class="regular-text" style="flex:1;" placeholder="https://example.com/fonts/myfont.woff2" />
                            <button type="button" class="button" id="sneakypeak-upload-font-button"><?php esc_html_e('Upload / Choose Font', 'sneakypeak'); ?></button>
                        </div>
                        <p class="description"><?php esc_html_e('Upload or select a .woff2, .woff, or .ttf font file from your WordPress Media Library.', 'sneakypeak'); ?></p>
                        <label style="margin-top:6px; display:inline-block;">
                            <?php esc_html_e('Custom Font Family Name (optional):', 'sneakypeak'); ?>
                            <input type="text" name="sneakypeak[teaser_font_custom_name]" value="<?php echo esc_attr($settings['teaser_font_custom_name'] ?? ''); ?>" class="regular-text" placeholder="e.g. MyBrandSans" style="width:200px;" />
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Teaser Font Size', 'sneakypeak'); ?></th>
                    <td>
                        <input type="number" min="0" max="60" step="1" name="sneakypeak[teaser_font_size]" value="<?php echo esc_attr($settings['teaser_font_size'] ?? 0); ?>" class="small-text" /> px
                        <p class="description"><?php esc_html_e('Custom teaser price font size (in px). Set to 0 to use responsive theme defaults.', 'sneakypeak'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    public static function save_meta(int $post_id, \WP_Post $post): void {
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('manage_woocommerce', $post_id)) {
            return;
        }

        $input = isset($_POST['sneakypeak']) && is_array($_POST['sneakypeak']) ? $_POST['sneakypeak'] : array();
        $clean = array();

        $clean['enabled']  = (!empty($input['enabled']) && $input['enabled'] === 'yes') ? 'yes' : 'no';
        $clean['priority'] = max(1, min(1000, absint($input['priority'] ?? 10)));

        $tz = Campaign::get_store_timezone();
        $notices = array();

        // Validate timing order: teaser <= reveal < end
        $teaser_raw = trim(sanitize_text_field($input['teaser_start_datetime'] ?? ''));
        $reveal_raw = trim(sanitize_text_field($input['reveal_start_datetime'] ?? ''));
        $end_raw    = trim(sanitize_text_field($input['end_datetime'] ?? ''));

        $teaser_ts = 0;
        $reveal_ts = 0;
        $end_ts    = 0;

        // 1. Reveal Date & Time Validation
        if (!empty($reveal_raw)) {
            try {
                $dt = new DateTime($reveal_raw, $tz);
                $reveal_ts = (int) $dt->getTimestamp();
                $clean['reveal_start_datetime'] = $reveal_raw;
            } catch (\Exception $e) {
                $clean['reveal_start_datetime'] = '';
                $notices[] = array(
                    'type'    => 'error',
                    'message' => __('Reveal (Live) Start Date & Time could not be parsed. Please use format YYYY-MM-DD HH:MM.', 'sneakypeak'),
                );
            }
        } else {
            $clean['reveal_start_datetime'] = '';
            $notices[] = array(
                'type'    => 'error',
                'message' => __('Reveal (Live) Start Date & Time is required.', 'sneakypeak'),
            );
        }

        // If reveal date is missing or invalid, campaign cannot be enabled and remains in Draft
        if ($clean['reveal_start_datetime'] === '' || $reveal_ts <= 0) {
            $clean['enabled'] = 'no';
            $notices[] = array(
                'type'    => 'warning',
                'message' => __('This campaign cannot be enabled without a valid Reveal (Live) Date & Time. It will remain disabled in Draft until a reveal date is set.', 'sneakypeak'),
            );
        }

        // 2. Teaser Start Date & Time Validation
        if (!empty($teaser_raw)) {
            try {
                $dt = new DateTime($teaser_raw, $tz);
                $teaser_ts = (int) $dt->getTimestamp();
                $clean['teaser_start_datetime'] = $teaser_raw;
            } catch (\Exception $e) {
                $clean['teaser_start_datetime'] = '';
                $notices[] = array(
                    'type'    => 'error',
                    'message' => __('Teaser Start Date & Time could not be parsed. The teaser start date was cleared.', 'sneakypeak'),
                );
            }
        } else {
            $clean['teaser_start_datetime'] = '';
        }

        // Check teaser <= reveal
        if ($teaser_ts > 0 && $reveal_ts > 0 && $teaser_ts > $reveal_ts) {
            $clean['teaser_start_datetime'] = ''; // Teaser cannot be after reveal
            $notices[] = array(
                'type'    => 'error',
                'message' => __('Teaser Start Date & Time cannot be after Reveal (Live) Start Date & Time. The teaser start date was cleared.', 'sneakypeak'),
            );
        }

        // 3. Campaign End Date & Time Validation
        if (!empty($end_raw)) {
            try {
                $dt = new DateTime($end_raw, $tz);
                $end_ts = (int) $dt->getTimestamp();
                if ($reveal_ts > 0 && $end_ts <= $reveal_ts) {
                    $clean['end_datetime'] = ''; // Invalid: end must be strictly after reveal
                    $notices[] = array(
                        'type'    => 'error',
                        'message' => __('Campaign End Date & Time must be strictly after Reveal (Live) Start Date & Time. The end date was cleared.', 'sneakypeak'),
                    );
                } else {
                    $clean['end_datetime'] = $end_raw;
                }
            } catch (\Exception $e) {
                $clean['end_datetime'] = '';
                $notices[] = array(
                    'type'    => 'error',
                    'message' => __('Campaign End Date & Time could not be parsed. The end date was cleared.', 'sneakypeak'),
                );
            }
        } else {
            $clean['end_datetime'] = '';
        }

        // Store validation notices in transient for display on next admin load
        if (!empty($notices)) {
            $user_id = get_current_user_id();
            set_transient('sneakypeak_notices_' . $user_id . '_' . $post_id, $notices, 60);
        }

        // Targets & Exclusions
        $clean['target_categories']     = isset($input['target_categories']) ? array_map('absint', (array) $input['target_categories']) : array();
        $clean['include_subcategories'] = (!empty($input['include_subcategories']) && $input['include_subcategories'] === 'yes') ? 'yes' : 'no';
        $clean['target_tags']           = isset($input['target_tags']) ? array_map('absint', (array) $input['target_tags']) : array();

        $parse_ids = function ($str) {
            if (empty($str)) {
                return array();
            }
            $parts = explode(',', (string) $str);
            $ids = array();
            foreach ($parts as $p) {
                $id = absint(trim($p));
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            return array_values(array_unique($ids));
        };

        $clean['target_products']   = $parse_ids($input['target_products'] ?? '');
        $clean['exclude_categories'] = isset($input['exclude_categories']) ? array_map('absint', (array) $input['exclude_categories']) : array();
        $clean['exclude_products']   = $parse_ids($input['exclude_products'] ?? '');

        // Badge settings
        $clean['badge_corner'] = in_array($input['badge_corner'] ?? '', array('top-right', 'top-left', 'bottom-left', 'bottom-right'), true)
            ? $input['badge_corner']
            : 'top-right';

        $clean['badge_shape'] = in_array($input['badge_shape'] ?? '', array('ribbon', 'pill', 'circle', 'flag'), true)
            ? $input['badge_shape']
            : 'ribbon';

        $clean['badge_size'] = in_array($input['badge_size'] ?? '', array('small', 'medium', 'large'), true)
            ? $input['badge_size']
            : 'medium';

        $clean['badge_icon'] = in_array($input['badge_icon'] ?? '', array('star', 'fire', 'tag', 'none'), true)
            ? $input['badge_icon']
            : 'star';

        $clean['badge_font_size'] = min(24, max(9, absint($input['badge_font_size'] ?? 12)));
        $clean['badge_nudge_x']   = max(-100, min(100, (int) ($input['badge_nudge_x'] ?? 0)));
        $clean['badge_nudge_y']   = max(-100, min(100, (int) ($input['badge_nudge_y'] ?? 0)));

        $clean['badge_text_teaser'] = sanitize_text_field($input['badge_text_teaser'] ?? 'SNEAK PEEK');
        $clean['badge_text_live']   = sanitize_text_field($input['badge_text_live'] ?? 'SALE NOW');

        $clean['badge_bg_start_teaser']   = sanitize_hex_color($input['badge_bg_start_teaser'] ?? '') ?: '#ff416c';
        $clean['badge_bg_end_teaser']     = sanitize_hex_color($input['badge_bg_end_teaser'] ?? '') ?: '#ff4b2b';
        $clean['badge_text_color_teaser'] = sanitize_hex_color($input['badge_text_color_teaser'] ?? '') ?: '#ffffff';

        $clean['badge_bg_start_live']   = sanitize_hex_color($input['badge_bg_start_live'] ?? '') ?: '#ff416c';
        $clean['badge_bg_end_live']     = sanitize_hex_color($input['badge_bg_end_live'] ?? '') ?: '#ff4b2b';
        $clean['badge_text_color_live'] = sanitize_hex_color($input['badge_text_color_live'] ?? '') ?: '#ffffff';

        $clean['badge_custom_css'] = sanitize_textarea_field($input['badge_custom_css'] ?? '');

        // Single Product Badge Placement
        $clean['single_badge_position'] = in_array($input['single_badge_position'] ?? '', array('gallery', 'summary', 'custom'), true)
            ? $input['single_badge_position']
            : 'gallery';

        // Sanitise custom selector: reject <, >, {, }, quotes (', "), backticks (`), and ;
        // and limit to 200 chars
        $raw_selector = trim((string) ($input['single_badge_custom_selector'] ?? ''));
        $cleaned_selector = str_replace(array('<', '>', '{', '}', "'", '"', '`', ';'), '', $raw_selector);
        $clean['single_badge_custom_selector'] = substr(sanitize_text_field($cleaned_selector), 0, 200);

        $clean['single_badge_corner'] = in_array($input['single_badge_corner'] ?? '', array('top-right', 'top-left', 'bottom-left', 'bottom-right'), true)
            ? $input['single_badge_corner']
            : 'top-right';
        $clean['single_badge_nudge_x'] = max(-100, min(100, (int) ($input['single_badge_nudge_x'] ?? 0)));
        $clean['single_badge_nudge_y'] = max(-100, min(100, (int) ($input['single_badge_nudge_y'] ?? 0)));

        // Teaser settings
        $clean['mask_rule'] = in_array($input['mask_rule'] ?? '', array('keep_first', 'mask_last_3'), true) ? $input['mask_rule'] : 'keep_first';
        $raw_teaser_label   = isset($input['teaser_label']) ? trim(sanitize_text_field($input['teaser_label'])) : '';
        $clean['teaser_label'] = ($raw_teaser_label !== '') ? $raw_teaser_label : 'Sale Price:';
        $clean['teaser_variable_prefix'] = sanitize_text_field($input['teaser_variable_prefix'] ?? 'From');

        // Font settings
        $valid_fonts = array('inherit', 'jost', 'montserrat', 'poppins', 'outfit', 'inter', 'questrial', 'custom');
        $clean['teaser_font_family'] = in_array($input['teaser_font_family'] ?? '', $valid_fonts, true)
            ? $input['teaser_font_family']
            : 'inherit';

        // Validate uploaded font URL: must be .woff2, .woff, or .ttf and originate from media library
        $raw_font_url = esc_url_raw(trim($input['teaser_font_custom_url'] ?? ''));
        if (!empty($raw_font_url)) {
            $path = wp_parse_url($raw_font_url, PHP_URL_PATH);
            $ext  = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
            if (!in_array($ext, array('woff2', 'woff', 'ttf'), true)) {
                $raw_font_url = '';
            } else {
                $upload_dir = wp_upload_dir();
                $base_url   = $upload_dir['baseurl'];
                $is_media_library = (strpos($raw_font_url, $base_url) === 0);
                if (!$is_media_library && function_exists('attachment_url_to_postid')) {
                    $is_media_library = (attachment_url_to_postid($raw_font_url) > 0);
                }
                if (!$is_media_library) {
                    $raw_font_url = '';
                }
            }
        }
        $clean['teaser_font_custom_url']  = $raw_font_url;
        $clean['teaser_font_custom_name'] = sanitize_text_field($input['teaser_font_custom_name'] ?? '');
        $clean['teaser_font_size']        = max(0, min(60, absint($input['teaser_font_size'] ?? 0)));

        update_post_meta($post_id, '_sneakypeak_settings', $clean);

        // Invalidate target resolution caches
        Resolver::invalidate_caches();
    }

    /**
     * Render the Live Preview & Insights Meta Box on Campaign Edit Screen
     */
    public static function render_preview_insights_meta_box(\WP_Post $post): void {
        $campaign = new Campaign($post->ID);
        $phase = $campaign->get_phase();
        $settings = $campaign->get_all_settings();

        $tz = Campaign::get_store_timezone();
        $tz_name = function_exists('wp_timezone_string') ? wp_timezone_string() : $tz->getName();

        $teaser_ts = $campaign->get_teaser_start_timestamp();
        $reveal_ts = $campaign->get_reveal_start_timestamp();
        $end_ts    = $campaign->get_end_timestamp();
        $now       = Campaign::now();

        $format_ts = function (?int $ts) {
            if (!$ts || $ts <= 0) {
                return __('Immediate / Unspecified', 'sneakypeak');
            }
            return function_exists('wp_date') ? wp_date('M j, Y H:i T', $ts) : date('M j, Y H:i', $ts);
        };

        $format_diff = function (int $target_ts, int $current_ts) {
            $diff = $target_ts - $current_ts;
            if ($diff <= 0) {
                return __('Reached', 'sneakypeak');
            }
            $days = floor($diff / 86400);
            $hours = floor(($diff % 86400) / 3600);
            $mins = floor(($diff % 3600) / 60);
            $parts = array();
            if ($days > 0) $parts[] = $days . 'd';
            if ($hours > 0) $parts[] = $hours . 'h';
            $parts[] = $mins . 'm';
            return implode(' ', $parts) . ' ' . __('left', 'sneakypeak');
        };

        // Gather targeted product IDs (first 50 for insights list)
        $tax_query = array('relation' => 'OR');
        $has_tax = false;
        $effective_cats = Resolver::get_effective_category_ids($campaign);
        if (!empty($effective_cats)) {
            $tax_query[] = array('taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $effective_cats);
            $has_tax = true;
        }
        $target_tags = array_map('intval', (array) ($settings['target_tags'] ?? array()));
        if (!empty($target_tags)) {
            $tax_query[] = array('taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => $target_tags);
            $has_tax = true;
        }
        $target_products = array_map('intval', (array) ($settings['target_products'] ?? array()));
        $exclude_products = array_map('intval', (array) ($settings['exclude_products'] ?? array()));

        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        );
        if (!empty($exclude_products)) {
            $args['post__not_in'] = $exclude_products;
        }
        $matched_ids = array();
        if ($has_tax) {
            $args['tax_query'] = $tax_query;
            $tax_ids = get_posts($args);
            if (!empty($tax_ids)) $matched_ids = array_merge($matched_ids, $tax_ids);
        }
        if (!empty($target_products)) {
            $matched_ids = array_merge($matched_ids, $target_products);
        }
        $matched_ids = array_slice(array_unique($matched_ids), 0, 50);

        // Determine target storefront preview URL: shop page URL or first targeted product's permalink
        $store_target_url = '';
        if (!empty($matched_ids) && !empty($matched_ids[0])) {
            $first_prod_url = get_permalink($matched_ids[0]);
            if (!empty($first_prod_url)) {
                $store_target_url = $first_prod_url;
            }
        }
        if (empty($store_target_url) && function_exists('wc_get_page_permalink')) {
            $store_target_url = wc_get_page_permalink('shop');
        }
        if (empty($store_target_url)) {
            $store_target_url = home_url('/');
        }

        // Front-end preview button helper per phase
        $render_preview_button = function (string $target_phase, string $extra_classes = '') use ($post, $store_target_url) {
            $err_reason = '';
            $ts = \SneakyPeak\Preview::calculate_phase_timestamp($target_phase, $post->ID, $err_reason);

            if ($ts > 0) {
                $url = wp_nonce_url(
                    add_query_arg(array(
                        'sneakypeak_action' => 'set_preview_phase',
                        'phase'             => $target_phase,
                        'campaign_id'       => $post->ID,
                        'redirect_to'       => rawurlencode($store_target_url),
                    ), home_url('/')),
                    'sneakypeak_set_preview'
                );
                printf(
                    '<a href="%s" class="button button-small %s" target="_blank">%s ↗</a>',
                    esc_url($url),
                    esc_attr($extra_classes),
                    esc_html__('Preview on Store', 'sneakypeak')
                );
            } else {
                $reason_label = !empty($err_reason) ? $err_reason : __('unavailable', 'sneakypeak');
                printf(
                    '<button type="button" class="button button-small disabled" style="color:#999; cursor:not-allowed;" disabled title="%s">%s (%s)</button>',
                    esc_attr(sprintf(__('Unavailable: %s', 'sneakypeak'), $reason_label)),
                    esc_html__('Preview on Store', 'sneakypeak'),
                    esc_html($reason_label)
                );
            }
        };

        // Analyze warnings
        $warnings = array();
        if ($reveal_ts <= 0) {
            $warnings[] = __('Reveal (Live) start date is missing or invalid. Campaign cannot unlock live prices.', 'sneakypeak');
        }
        if ($teaser_ts > 0 && $reveal_ts > 0 && $teaser_ts >= $reveal_ts) {
            $warnings[] = __('Teaser start time must occur BEFORE Reveal time.', 'sneakypeak');
        }
        if ($end_ts > 0 && $reveal_ts > 0 && $end_ts <= $reveal_ts) {
            $warnings[] = __('End time must occur AFTER Reveal time.', 'sneakypeak');
        }

        // Check if any matched products lack sale price or have WC sale dates
        $products_without_sale_price = 0;
        $products_with_wc_schedule   = 0;
        $products_outranked          = 0;

        foreach ($matched_ids as $pid) {
            $post_obj = get_post($pid);
            if (!$post_obj || $post_obj->post_type !== 'product') continue;

            $has_sale = false;
            $prod_terms = get_the_terms($pid, 'product_type');
            $is_variable = ($prod_terms && !is_wp_error($prod_terms) && $prod_terms[0]->slug === 'variable');

            if ($is_variable) {
                $child_ids = get_posts(array(
                    'post_parent'    => $pid,
                    'post_type'      => 'product_variation',
                    'posts_per_page' => -1,
                    'post_status'    => 'publish',
                    'fields'         => 'ids',
                ));
                foreach ($child_ids as $cid) {
                    $c_sale = get_post_meta($cid, '_sale_price', true);
                    if ($c_sale !== '' && (float) $c_sale > 0) {
                        $has_sale = true;
                        break;
                    }
                }
            } else {
                $raw_sale = get_post_meta($pid, '_sale_price', true);
                $has_sale = ($raw_sale !== '' && (float) $raw_sale > 0);
            }

            if (!$has_sale) {
                $products_without_sale_price++;
            }

            $date_on_sale_from = get_post_meta($pid, '_sale_price_dates_from', true);
            $date_on_sale_to   = get_post_meta($pid, '_sale_price_dates_to', true);
            if (!empty($date_on_sale_from) || !empty($date_on_sale_to)) {
                $products_with_wc_schedule++;
            }

            // Resolver check using numeric product ID
            $winner_data = Resolver::resolve($pid, $now);
            $winner = $winner_data['campaign'] ?? null;
            if ($winner && $winner->get_id() !== $campaign->get_id()) {
                $products_outranked++;
            }
        }

        if ($products_without_sale_price > 0) {
            $warnings[] = sprintf(
                __('%d targeted product(s) do not have a regular WooCommerce Sale Price configured. SneakyPeak will suppress badges and teaser prices for these products to prevent misleading customers.', 'sneakypeak'),
                $products_without_sale_price
            );
        }
        if ($products_with_wc_schedule > 0) {
            $warnings[] = sprintf(
                __('%d targeted product(s) have native WooCommerce Sale Schedules set (_sale_price_dates_from/to). SneakyPeak independent reveal logic will override WooCommerce sale schedules, but clearing native dates is recommended to avoid confusion.', 'sneakypeak'),
                $products_with_wc_schedule
            );
        }
        if ($products_outranked > 0) {
            $warnings[] = sprintf(
                __('%d targeted product(s) are currently won by higher-priority campaigns (e.g. Priority lower than %d).', 'sneakypeak'),
                $products_outranked,
                $campaign->get_priority()
            );
        }

        $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '₱';
        ?>
        <style>
            .sneakypeak-insights-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 10px; }
            @media (max-width: 900px) { .sneakypeak-insights-grid { grid-template-columns: 1fr; } }
            .sneakypeak-panel { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 16px; }
            .sneakypeak-panel-title { margin: 0 0 12px 0; font-size: 13px; font-weight: 700; text-transform: uppercase; color: #23282d; border-bottom: 1px solid #eee; padding-bottom: 8px; }
            
            /* Timeline */
            .sneakypeak-timeline { list-style: none; margin: 0; padding: 0; position: relative; }
            .sneakypeak-timeline::before { content: ''; position: absolute; top: 12px; bottom: 12px; left: 14px; width: 2px; background: #ddd; }
            .sneakypeak-timeline-step { position: relative; padding-left: 38px; margin-bottom: 18px; }
            .sneakypeak-timeline-step:last-child { margin-bottom: 0; }
            .sneakypeak-step-dot { position: absolute; left: 6px; top: 2px; width: 18px; height: 18px; border-radius: 50%; background: #fff; border: 3px solid #ccc; box-sizing: border-box; }
            .sneakypeak-timeline-step.active .sneakypeak-step-dot { border-color: #2271b1; background: #2271b1; }
            .sneakypeak-timeline-step.passed .sneakypeak-step-dot { border-color: #46b450; background: #46b450; }
            .sneakypeak-step-header { font-weight: bold; font-size: 13px; display: flex; align-items: center; justify-content: space-between; }
            .sneakypeak-step-meta { font-size: 11px; color: #666; margin-top: 2px; }

            /* Warnings */
            .sneakypeak-warning-box { background: #fcf9e8; border-left: 4px solid #dba617; padding: 10px 14px; margin-bottom: 14px; border-radius: 2px; font-size: 12px; color: #614d03; }
            .sneakypeak-warning-box p { margin: 4px 0; }
            .sneakypeak-warning-box p:first-child { margin-top: 0; }
            .sneakypeak-warning-box p:last-child { margin-bottom: 0; }
        </style>

        <?php if (!empty($warnings)) : ?>
            <div class="sneakypeak-warning-box">
                <strong><?php esc_html_e('⚠️ Campaign Diagnostic Notices:', 'sneakypeak'); ?></strong>
                <?php foreach ($warnings as $w) : ?>
                    <p>• <?php echo esc_html($w); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="sneakypeak-insights-grid">
            
            <!-- LEFT COLUMN: Timeline & Behavior Table -->
            <div>
                <!-- 1. Vertical Timeline -->
                <div class="sneakypeak-panel" style="margin-bottom: 20px;">
                    <div class="sneakypeak-panel-title">
                        <?php esc_html_e('Campaign Timeline & Phase Milestones', 'sneakypeak'); ?>
                        <span style="float:right; font-weight:normal; text-transform:none; color:#666;">
                            <?php esc_html_e('Store Time:', 'sneakypeak'); ?> <?php echo esc_html($tz_name); ?>
                        </span>
                    </div>

                    <ul class="sneakypeak-timeline">
                        <li class="sneakypeak-timeline-step <?php echo ($phase === Campaign::PHASE_SCHEDULED) ? 'active' : (($phase !== Campaign::PHASE_DRAFT) ? 'passed' : ''); ?>">
                            <span class="sneakypeak-step-dot"></span>
                            <div class="sneakypeak-step-header">
                                <span>1. <?php esc_html_e('Scheduled', 'sneakypeak'); ?></span>
                                <?php $render_preview_button(Campaign::PHASE_SCHEDULED); ?>
                            </div>
                            <div class="sneakypeak-step-meta">
                                <?php esc_html_e('Status: Early-sale price guard active, badge hidden.', 'sneakypeak'); ?>
                            </div>
                        </li>

                        <li class="sneakypeak-timeline-step <?php echo ($phase === Campaign::PHASE_TEASER) ? 'active' : (($phase === Campaign::PHASE_LIVE || $phase === Campaign::PHASE_ENDED) ? 'passed' : ''); ?>">
                            <span class="sneakypeak-step-dot"></span>
                            <div class="sneakypeak-step-header">
                                <span>2. <?php esc_html_e('Teaser Phase', 'sneakypeak'); ?></span>
                                <?php $render_preview_button(Campaign::PHASE_TEASER); ?>
                            </div>
                            <div class="sneakypeak-step-meta">
                                <strong><?php esc_html_e('Starts:', 'sneakypeak'); ?></strong> <?php echo esc_html($format_ts($teaser_ts)); ?>
                                <?php if ($teaser_ts > $now) : ?>
                                    &nbsp;•&nbsp;<span style="color:#d63638;"><?php echo esc_html($format_diff($teaser_ts, $now)); ?></span>
                                <?php endif; ?>
                                <br><?php esc_html_e('Badges visible, masked teaser price shown, regular price enforced.', 'sneakypeak'); ?>
                            </div>
                        </li>

                        <li class="sneakypeak-timeline-step <?php echo ($phase === Campaign::PHASE_LIVE) ? 'active' : (($phase === Campaign::PHASE_ENDED) ? 'passed' : ''); ?>">
                            <span class="sneakypeak-step-dot"></span>
                            <div class="sneakypeak-step-header">
                                <span>3. <?php esc_html_e('Live (Sale Reveal)', 'sneakypeak'); ?></span>
                                <?php $render_preview_button(Campaign::PHASE_LIVE, 'button-primary'); ?>
                            </div>
                            <div class="sneakypeak-step-meta">
                                <strong><?php esc_html_e('Starts:', 'sneakypeak'); ?></strong> <?php echo esc_html($format_ts($reveal_ts)); ?>
                                <?php if ($reveal_ts > $now) : ?>
                                    &nbsp;•&nbsp;<span style="color:#2271b1; font-weight:bold;"><?php echo esc_html($format_diff($reveal_ts, $now)); ?></span>
                                <?php endif; ?>
                                <br><?php esc_html_e('Live badge visible, sale price unlocked, purchases permitted at sale price.', 'sneakypeak'); ?>
                            </div>
                        </li>

                        <li class="sneakypeak-timeline-step <?php echo ($phase === Campaign::PHASE_ENDED) ? 'active' : ''; ?>">
                            <span class="sneakypeak-step-dot"></span>
                            <div class="sneakypeak-step-header">
                                <span>4. <?php esc_html_e('Ended', 'sneakypeak'); ?></span>
                                <?php $render_preview_button(Campaign::PHASE_ENDED); ?>
                            </div>
                            <div class="sneakypeak-step-meta">
                                <strong><?php esc_html_e('Ends:', 'sneakypeak'); ?></strong> <?php echo esc_html($format_ts($end_ts)); ?>
                                <?php if ($end_ts > $now) : ?>
                                    &nbsp;•&nbsp;<span><?php echo esc_html($format_diff($end_ts, $now)); ?></span>
                                <?php endif; ?>
                                <br><?php esc_html_e('Badges removed, standard WooCommerce catalog prices restored.', 'sneakypeak'); ?>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- 2. "What Customers See" Matrix Table -->
                <div class="sneakypeak-panel">
                    <div class="sneakypeak-panel-title"><?php esc_html_e('Customer Experience by Phase', 'sneakypeak'); ?></div>
                    <table class="widefat striped" style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Phase', 'sneakypeak'); ?></th>
                                <th><?php esc_html_e('Badge Display', 'sneakypeak'); ?></th>
                                <th><?php esc_html_e('Price Displayed', 'sneakypeak'); ?></th>
                                <th><?php esc_html_e('Purchase Price', 'sneakypeak'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e('Scheduled', 'sneakypeak'); ?></strong></td>
                                <td><span style="color:#888;">— <?php esc_html_e('Hidden', 'sneakypeak'); ?> —</span></td>
                                <td><?php esc_html_e('Regular Price', 'sneakypeak'); ?></td>
                                <td><?php esc_html_e('Regular Price (Guarded)', 'sneakypeak'); ?></td>
                            </tr>
                            <tr>
                                <td><strong style="color:#ff8c00;"><?php esc_html_e('Teaser', 'sneakypeak'); ?></strong></td>
                                <td><code><?php echo esc_html($settings['badge_text_teaser'] ?? 'SNEAK PEEK'); ?></code></td>
                                <td><?php esc_html_e('Regular + Teaser Line', 'sneakypeak'); ?> (<code><?php echo esc_html($currency_symbol); ?>8,???</code>)</td>
                                <td><?php esc_html_e('Regular Price (Guarded)', 'sneakypeak'); ?></td>
                            </tr>
                            <tr>
                                <td><strong style="color:#46b450;"><?php esc_html_e('Live', 'sneakypeak'); ?></strong></td>
                                <td><code><?php echo esc_html($settings['badge_text_live'] ?? 'SALE NOW'); ?></code></td>
                                <td><?php esc_html_e('Discounted Sale Price', 'sneakypeak'); ?></td>
                                <td><?php esc_html_e('Sale Price (Unlocked)', 'sneakypeak'); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php esc_html_e('Ended', 'sneakypeak'); ?></strong></td>
                                <td><span style="color:#888;">— <?php esc_html_e('Hidden', 'sneakypeak'); ?> —</span></td>
                                <td><?php esc_html_e('Native WooCommerce Price', 'sneakypeak'); ?></td>
                                <td><?php esc_html_e('Native WooCommerce Price', 'sneakypeak'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- RIGHT COLUMN: Teaser Calculator & Matched Products -->
            <div>
                <!-- 3. Interactive Teaser Calculator -->
                <div class="sneakypeak-panel" style="margin-bottom: 20px;">
                    <div class="sneakypeak-panel-title"><?php esc_html_e('Interactive Teaser Price Calculator', 'sneakypeak'); ?></div>
                    <table class="form-table sneakypeak-form-table" style="margin:0;">
                        <tr>
                            <th style="width:140px; padding:6px 0;"><?php esc_html_e('Sample Price:', 'sneakypeak'); ?></th>
                            <td style="padding:6px 0;">
                                <input type="number" id="sneakypeak-calc-sample-price" value="8499.00" step="0.01" class="regular-text" style="max-width:140px;" />
                            </td>
                        </tr>
                        <tr>
                            <th style="padding:6px 0;"><?php esc_html_e('Simple Card:', 'sneakypeak'); ?></th>
                            <td style="padding:6px 0;">
                                <code id="sneakypeak-calc-output-simple" style="font-size:13px; font-weight:bold; color:#d63638;">Sale Price: <?php echo esc_html($currency_symbol); ?>8,???</code>
                            </td>
                        </tr>
                        <tr>
                            <th style="padding:6px 0;"><?php esc_html_e('Variable Card:', 'sneakypeak'); ?></th>
                            <td style="padding:6px 0;">
                                <code id="sneakypeak-calc-output-variable" style="font-size:13px; font-weight:bold; color:#d63638;">Sale Price: From <?php echo esc_html($currency_symbol); ?>8,???</code>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 4. Collapsible Matched Products List (First 50) -->
                <div class="sneakypeak-panel">
                    <div class="sneakypeak-panel-title" style="display:flex; justify-content:space-between; align-items:center;">
                        <span><?php esc_html_e('Targeted Products Sample', 'sneakypeak'); ?> (<?php echo count($matched_ids); ?>)</span>
                        <button type="button" class="button button-small" onclick="jQuery('#sneakypeak-products-list-wrap').slideToggle(); return false;">
                            <?php esc_html_e('Toggle List', 'sneakypeak'); ?>
                        </button>
                    </div>

                    <div id="sneakypeak-products-list-wrap" style="display:none; max-height: 250px; overflow-y: auto; margin-top: 10px;">
                        <?php if (empty($matched_ids)) : ?>
                            <p style="color:#888; margin:0;"><?php esc_html_e('No products match current categories/tags/IDs.', 'sneakypeak'); ?></p>
                        <?php else : ?>
                            <table class="widefat striped" style="font-size:11px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('ID', 'sneakypeak'); ?></th>
                                        <th><?php esc_html_e('Product', 'sneakypeak'); ?></th>
                                        <th><?php esc_html_e('Reg / Sale', 'sneakypeak'); ?></th>
                                        <th><?php esc_html_e('Status', 'sneakypeak'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                     <?php foreach ($matched_ids as $pid) : 
                                        $p_post = get_post($pid);
                                        if (!$p_post || $p_post->post_type !== 'product') continue;
                                        $p_reg = get_post_meta($pid, '_regular_price', true);
                                        $p_sale = get_post_meta($pid, '_sale_price', true);
                                        $p_terms = get_the_terms($pid, 'product_type');
                                        $is_var = ($p_terms && !is_wp_error($p_terms) && $p_terms[0]->slug === 'variable');
                                        $winner_res = Resolver::resolve($pid, $now);
                                        $winner = $winner_res['campaign'] ?? null;
                                        $is_winner = ($winner && $winner->get_id() === $campaign->get_id());
                                        $has_sale = ($p_sale !== '' && (float) $p_sale > 0);
                                    ?>
                                        <tr>
                                            <td>#<?php echo (int) $pid; ?></td>
                                            <td><a href="<?php echo esc_url(get_edit_post_link($pid)); ?>" target="_blank"><?php echo esc_html(get_the_title($pid)); ?></a></td>
                                            <td>
                                                <?php echo esc_html($p_reg !== '' ? $currency_symbol . $p_reg : '—'); ?> / 
                                                <?php echo esc_html($p_sale !== '' ? $currency_symbol . $p_sale : '—'); ?>
                                            </td>
                                            <td>
                                                <?php if (!$has_sale && !$is_var) : ?>
                                                    <span style="color:#d63638;"><?php esc_html_e('No Sale Price', 'sneakypeak'); ?></span>
                                                <?php elseif ($is_winner) : ?>
                                                    <span style="color:#46b450; font-weight:bold;"><?php esc_html_e('Active Winner', 'sneakypeak'); ?></span>
                                                <?php elseif ($winner) : ?>
                                                    <span style="color:#ff8c00;"><?php printf(esc_html__('Outranked by #%d', 'sneakypeak'), $winner->get_id()); ?></span>
                                                <?php else : ?>
                                                    <span><?php esc_html_e('Targeted', 'sneakypeak'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </div>
        <?php
    }
}

