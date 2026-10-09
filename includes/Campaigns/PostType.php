<?php
namespace SneakyPeak\Campaigns;

defined('ABSPATH') || exit;

use SneakyPeak\Campaign;

/**
 * Class PostType
 *
 * Registers the sneakypeak_campaign Custom Post Type, admin menu under WooCommerce > SneakyPeak,
 * custom columns (Status, Start/End, Product Count, Duplicate action), and row actions.
 */
class PostType {

    public const CPT = 'sneakypeak_campaign';

    public static function init(): void {
        add_action('init', array(__CLASS__, 'register'));
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
        add_filter('parent_file', array(__CLASS__, 'highlight_woocommerce_menu'));
        add_filter('submenu_file', array(__CLASS__, 'highlight_submenu_file'));

        // Custom columns on post list table
        add_filter('manage_' . self::CPT . '_posts_columns', array(__CLASS__, 'custom_columns'));
        add_action('manage_' . self::CPT . '_posts_custom_column', array(__CLASS__, 'render_custom_column'), 10, 2);
        add_filter('post_row_actions', array(__CLASS__, 'row_actions'), 10, 2);

        // Handle duplicate campaign action
        add_action('admin_action_sneakypeak_duplicate_campaign', array(__CLASS__, 'handle_duplicate_campaign'));
    }

    public static function register(): void {
        $labels = array(
            'name'               => _x('Campaigns', 'post type general name', 'sneakypeak'),
            'singular_name'      => _x('Campaign', 'post type singular name', 'sneakypeak'),
            'menu_name'          => _x('SneakyPeak', 'admin menu', 'sneakypeak'),
            'name_admin_bar'     => _x('Campaign', 'add new on admin bar', 'sneakypeak'),
            'add_new'            => __('Add New Campaign', 'sneakypeak'),
            'add_new_item'       => __('Add New Campaign', 'sneakypeak'),
            'new_item'           => __('New Campaign', 'sneakypeak'),
            'edit_item'          => __('Edit Campaign', 'sneakypeak'),
            'view_item'          => __('View Campaign', 'sneakypeak'),
            'all_items'          => __('SneakyPeak Campaigns', 'sneakypeak'),
            'search_items'       => __('Search Campaigns', 'sneakypeak'),
            'not_found'          => __('No campaigns found.', 'sneakypeak'),
            'not_found_in_trash' => __('No campaigns found in Trash.', 'sneakypeak'),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => false, // Registered manually under WooCommerce
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'capabilities'       => array(
                'edit_post'          => 'manage_woocommerce',
                'read_post'          => 'manage_woocommerce',
                'delete_post'        => 'manage_woocommerce',
                'edit_posts'         => 'manage_woocommerce',
                'edit_others_posts'  => 'manage_woocommerce',
                'publish_posts'      => 'manage_woocommerce',
                'read_private_posts' => 'manage_woocommerce',
            ),
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => array('title'),
        );

        register_post_type(self::CPT, $args);
    }

    public static function register_admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('SneakyPeak Campaigns', 'sneakypeak'),
            __('SneakyPeak', 'sneakypeak'),
            'manage_woocommerce',
            'edit.php?post_type=' . self::CPT
        );
    }

    public static function highlight_woocommerce_menu(string $parent_file): string {
        global $current_screen;
        if ($current_screen && $current_screen->post_type === self::CPT) {
            return 'woocommerce';
        }
        return $parent_file;
    }

    public static function highlight_submenu_file($submenu_file) {
        global $current_screen;
        if ($current_screen && $current_screen->post_type === self::CPT) {
            return 'edit.php?post_type=' . self::CPT;
        }
        return $submenu_file;
    }

    public static function custom_columns(array $columns): array {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __('Campaign Name', 'sneakypeak');
        $new_columns['sp_status'] = __('Phase Status', 'sneakypeak');
        $new_columns['sp_priority'] = __('Priority', 'sneakypeak');
        $new_columns['sp_dates'] = __('Schedule (Teaser / Reveal / End)', 'sneakypeak');
        $new_columns['sp_products'] = __('Target Products', 'sneakypeak');
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }

    public static function render_custom_column(string $column, int $post_id): void {
        $campaign = Campaign::get($post_id);
        if (!$campaign) {
            echo '&mdash;';
            return;
        }

        switch ($column) {
            case 'sp_status':
                $phase = $campaign->get_phase();
                $labels = array(
                    Campaign::PHASE_DRAFT     => array('label' => __('Draft / Disabled', 'sneakypeak'), 'color' => '#888'),
                    Campaign::PHASE_SCHEDULED => array('label' => __('Scheduled', 'sneakypeak'), 'color' => '#0073aa'),
                    Campaign::PHASE_TEASER    => array('label' => __('Teaser Active', 'sneakypeak'), 'color' => '#ff8c00'),
                    Campaign::PHASE_LIVE      => array('label' => __('LIVE SALE', 'sneakypeak'), 'color' => '#46b450'),
                    Campaign::PHASE_ENDED     => array('label' => __('Ended', 'sneakypeak'), 'color' => '#999'),
                );
                $info = $labels[$phase] ?? array('label' => ucfirst($phase), 'color' => '#555');
                printf(
                    '<span class="badge" style="background:%s; color:#fff; padding:3px 8px; border-radius:3px; font-weight:bold; font-size:11px; text-transform:uppercase;">%s</span>',
                    esc_attr($info['color']),
                    esc_html($info['label'])
                );
                break;

            case 'sp_priority':
                echo esc_html((string) $campaign->get_priority());
                break;

            case 'sp_dates':
                $teaser = $campaign->get_setting('teaser_start_datetime');
                $reveal = $campaign->get_setting('reveal_start_datetime');
                $end    = $campaign->get_setting('end_datetime');

                echo '<div style="font-size:12px; line-height:1.4;">';
                if (!empty($teaser)) {
                    echo '<strong>' . esc_html__('Teaser:', 'sneakypeak') . '</strong> ' . esc_html($teaser) . '<br>';
                }
                echo '<strong>' . esc_html__('Reveal:', 'sneakypeak') . '</strong> ' . (!empty($reveal) ? esc_html($reveal) : '<em>' . esc_html__('Not set', 'sneakypeak') . '</em>') . '<br>';
                if (!empty($end)) {
                    echo '<strong>' . esc_html__('End:', 'sneakypeak') . '</strong> ' . esc_html($end);
                } else {
                    echo '<strong>' . esc_html__('End:', 'sneakypeak') . '</strong> <em>' . esc_html__('Indefinite', 'sneakypeak') . '</em>';
                }
                echo '</div>';
                break;

            case 'sp_products':
                $count = Resolver::count_targeted_products($campaign);
                printf(
                    '<strong>%d</strong> %s',
                    $count,
                    esc_html(_n('product', 'products', $count, 'sneakypeak'))
                );
                break;
        }
    }

    public static function row_actions(array $actions, \WP_Post $post): array {
        if ($post->post_type !== self::CPT) {
            return $actions;
        }

        if (current_user_can('manage_woocommerce')) {
            $duplicate_url = wp_nonce_url(
                admin_url('admin.php?action=sneakypeak_duplicate_campaign&post=' . $post->ID),
                'sneakypeak_duplicate_' . $post->ID
            );
            $actions['duplicate'] = sprintf(
                '<a href="%s" title="%s">%s</a>',
                esc_url($duplicate_url),
                esc_attr__('Duplicate this campaign', 'sneakypeak'),
                esc_html__('Duplicate', 'sneakypeak')
            );
        }

        return $actions;
    }

    public static function handle_duplicate_campaign(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized action.', 'sneakypeak'));
        }

        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        check_admin_referer('sneakypeak_duplicate_' . $post_id);

        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::CPT) {
            wp_die(esc_html__('Campaign not found.', 'sneakypeak'));
        }

        $new_title = $post->post_title . ' ' . __('(Copy)', 'sneakypeak');
        $new_post_id = wp_insert_post(array(
            'post_title'  => $new_title,
            'post_type'   => self::CPT,
            'post_status' => 'draft',
        ));

        if (!is_wp_error($new_post_id) && $new_post_id > 0) {
            $meta = get_post_meta($post_id, '_sneakypeak_settings', true);
            if (is_array($meta)) {
                $meta['enabled'] = 'no'; // duplicated campaign is disabled by default
                update_post_meta($new_post_id, '_sneakypeak_settings', $meta);
            }
            Resolver::invalidate_caches();
            wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
            exit;
        }

        wp_safe_redirect(admin_url('edit.php?post_type=' . self::CPT));
        exit;
    }
}
