<?php
namespace SneakyPeak\Frontend;

defined('ABSPATH') || exit;

use SneakyPeak\Campaign;

/**
 * Class Badge
 *
 * Generates badge HTML for SneakyPeak campaigns (ribbon, pill, circle, flag).
 * Supports configurable corners, sizes, icons, fonts, and phase colors.
 */
class Badge {

    /**
     * Render badge HTML markup
     *
     * @param Campaign $campaign
     * @param string $phase ('teaser' | 'live')
     * @param bool $is_single
     * @return string
     */
    public static function render(Campaign $campaign, string $phase, bool $is_single = false): string {
        $corner = $is_single
            ? (string) $campaign->get_setting('single_badge_corner', 'top-right')
            : (string) $campaign->get_setting('badge_corner', 'top-right');
        $shape  = (string) $campaign->get_setting('badge_shape', 'ribbon');
        $size   = (string) $campaign->get_setting('badge_size', 'medium');
        $icon   = (string) $campaign->get_setting('badge_icon', 'star');

        $is_live = ($phase === Campaign::PHASE_LIVE);
        $badge_text = $is_live
            ? (string) $campaign->get_setting('badge_text_live', 'SALE NOW')
            : (string) $campaign->get_setting('badge_text_teaser', 'SNEAK PEEK');

        $wrap_classes = array(
            'sneakypeak-badge-wrap',
            'sneakypeak-corner-' . sanitize_html_class($corner),
            'sneakypeak-shape-' . sanitize_html_class($shape),
            'sneakypeak-size-' . sanitize_html_class($size),
        );
        if ($is_single) {
            $wrap_classes[] = 'sneakypeak-badge-single-wrap';
        }

        $badge_classes = array(
            'sneakypeak-badge',
            'sneakypeak-badge-' . ($is_live ? 'live' : 'teaser'),
            'sneakypeak-badge-shape-' . sanitize_html_class($shape),
            'sneakypeak-badge-size-' . sanitize_html_class($size),
        );
        if ($is_single) {
            $badge_classes[] = 'sneakypeak-badge-single';
        }

        $icon_html = '';
        if ($icon === 'star') {
            $icon_html = '<span class="sneakypeak-badge-icon sneakypeak-icon-star" aria-hidden="true">&#9733;</span> ';
        } elseif ($icon === 'fire') {
            $icon_html = '<span class="sneakypeak-badge-icon sneakypeak-icon-fire" aria-hidden="true">&#128293;</span> ';
        } elseif ($icon === 'tag') {
            $icon_html = '<span class="sneakypeak-badge-icon sneakypeak-icon-tag" aria-hidden="true">&#127991;</span> ';
        }

        return sprintf(
            '<span class="%1$s" data-campaign-id="%2$d"><span class="%3$s">%4$s%5$s</span></span>',
            esc_attr(implode(' ', $wrap_classes)),
            esc_attr($campaign->get_id()),
            esc_attr(implode(' ', $badge_classes)),
            $icon_html,
            esc_html($badge_text)
        );
    }
}
