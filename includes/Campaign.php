<?php
namespace SneakyPeak;

defined('ABSPATH') || exit;

use DateTime;
use DateTimeZone;

/**
 * Class Campaign
 *
 * Value object and model representing a single SneakyPeak campaign.
 */
class Campaign {

    public const PHASE_DRAFT     = 'draft';
    public const PHASE_SCHEDULED = 'scheduled';
    public const PHASE_TEASER    = 'teaser';
    public const PHASE_LIVE      = 'live';
    public const PHASE_ENDED     = 'ended';

    private int $id;
    private string $name;
    private array $meta;

    public function __construct(int $post_id) {
        $this->id = $post_id;
        $this->name = get_the_title($post_id);
        $this->load_meta();
    }

    public static function get(int $post_id): ?self {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'sneakypeak_campaign') {
            return null;
        }
        return new self($post_id);
    }

    private function load_meta(): void {
        $defaults = self::get_default_settings();
        $stored = get_post_meta($this->id, '_sneakypeak_settings', true);
        $this->meta = is_array($stored) ? wp_parse_args($stored, $defaults) : $defaults;
    }

    public static function get_default_settings(): array {
        return array(
            'enabled'                => 'yes',
            'priority'               => 10,
            // Timing (store timezone)
            'teaser_start_datetime'  => '',
            'reveal_start_datetime'  => '',
            'end_datetime'           => '',
            // Targets
            'target_categories'      => array(),
            'include_subcategories'  => 'yes',
            'target_tags'            => array(),
            'target_products'        => array(),
            'exclude_products'       => array(),
            'exclude_categories'     => array(),
            // Badge Settings
            'badge_corner'           => 'top-right', // top-right, top-left, bottom-left, bottom-right
            'badge_nudge_x'          => 0,           // -100 to 100 px (positive = right, negative = left)
            'badge_nudge_y'          => 0,           // -100 to 100 px (positive = down, negative = up)
            'badge_top_offset'       => 0,
            'badge_right_offset'     => 0,
            'badge_bottom_offset'    => 0,
            'badge_left_offset'      => 0,
            'badge_shape'            => 'ribbon',    // ribbon, pill, circle, flag
            'badge_size'             => 'medium',    // small, medium, large
            'badge_font_size'        => 12,          // in px
            'badge_icon'             => 'star',      // star, none, fire, tag
            'badge_text_teaser'      => 'SNEAK PEEK',
            'badge_text_live'        => 'SALE NOW',
            'badge_bg_start_teaser'  => '#ff416c',
            'badge_bg_end_teaser'    => '#ff4b2b',
            'badge_text_color_teaser'=> '#ffffff',
            'badge_bg_start_live'    => '#ff416c',
            'badge_bg_end_live'      => '#ff4b2b',
            'badge_text_color_live'  => '#ffffff',
            'badge_custom_css'       => '',
            // Single Product Badge Placement Settings
            'single_badge_position'        => 'gallery', // gallery (over main image), summary (inside summary above title), custom (CSS selector)
            'single_badge_custom_selector' => '',
            'single_badge_corner'          => 'top-right',
            'single_badge_nudge_x'         => 0,
            'single_badge_nudge_y'         => 0,
            // Teaser settings
            'teaser_label'           => 'Sale Price:',
            'teaser_variable_prefix' => 'From',
            'mask_rule'              => 'keep_first', // keep_first | mask_last_3
            'teaser_font_family'     => 'inherit',    // inherit, jost, montserrat, poppins, outfit, inter, questrial, custom
            'teaser_font_custom_url' => '',
            'teaser_font_custom_name'=> '',
            'teaser_font_size'       => 0,            // 0 = theme default
        );
    }

    public function get_id(): int {
        return $this->id;
    }

    public function get_name(): string {
        return $this->name;
    }

    public function get_setting(string $key, $default = null) {
        return $this->meta[$key] ?? $default;
    }

    public function get_all_settings(): array {
        return $this->meta;
    }

    /**
     * Get effective badge nudge offsets [x, y] with migration fallback for old corner offsets.
     * X: positive moves right, negative moves left.
     * Y: positive moves down, negative moves up.
     *
     * @return array{0: int, 1: int} [x, y]
     */
    public function get_nudge_offsets(): array {
        $has_nudge_x = isset($this->meta['badge_nudge_x']) && $this->meta['badge_nudge_x'] !== '';
        $has_nudge_y = isset($this->meta['badge_nudge_y']) && $this->meta['badge_nudge_y'] !== '';

        if ($has_nudge_x || $has_nudge_y) {
            $x = max(-100, min(100, (int) ($this->meta['badge_nudge_x'] ?? 0)));
            $y = max(-100, min(100, (int) ($this->meta['badge_nudge_y'] ?? 0)));
            return array($x, $y);
        }

        // Migration from old corner offsets
        $corner = (string) ($this->meta['badge_corner'] ?? 'top-right');
        $top    = (int) ($this->meta['badge_top_offset'] ?? 0);
        $right  = (int) ($this->meta['badge_right_offset'] ?? 0);
        $bottom = (int) ($this->meta['badge_bottom_offset'] ?? 0);
        $left   = (int) ($this->meta['badge_left_offset'] ?? 0);

        $x = 0;
        $y = 0;

        if ($corner === 'top-right') {
            $x = -$right;
            $y = $top;
        } elseif ($corner === 'top-left') {
            $x = $left;
            $y = $top;
        } elseif ($corner === 'bottom-left') {
            $x = $left;
            $y = -$bottom;
        } elseif ($corner === 'bottom-right') {
            $x = -$right;
            $y = -$bottom;
        }

        $x = max(-100, min(100, $x));
        $y = max(-100, min(100, $y));
        return array($x, $y);
    }

    /**
     * Get single product badge nudge offsets [x, y].
     *
     * @return array{0: int, 1: int} [x, y]
     */
    public function get_single_nudge_offsets(): array {
        $x = max(-100, min(100, (int) ($this->meta['single_badge_nudge_x'] ?? 0)));
        $y = max(-100, min(100, (int) ($this->meta['single_badge_nudge_y'] ?? 0)));
        return array($x, $y);
    }

    public function is_enabled(): bool {
        $status = get_post_status($this->id);
        if ($status !== 'publish') {
            return false;
        }
        return ($this->meta['enabled'] ?? 'yes') === 'yes';
    }

    public function get_priority(): int {
        return (int) ($this->meta['priority'] ?? 10);
    }

    /**
     * Store timezone object
     */
    public static function get_store_timezone(): DateTimeZone {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }
        $tz_string = get_option('timezone_string');
        if (!empty($tz_string)) {
            return new DateTimeZone($tz_string);
        }
        $offset = (float) get_option('gmt_offset', 0);
        $hours = (int) $offset;
        $minutes = abs(($offset - $hours) * 60);
        $sign = $offset < 0 ? '-' : '+';
        $tz_formatted = sprintf('%s%02d:%02d', $sign, abs($hours), $minutes);
        return new DateTimeZone($tz_formatted);
    }

    /**
     * Parse date string into UTC timestamp using store timezone
     */
    public static function parse_to_timestamp(?string $date_str): int {
        $date_str = trim((string) $date_str);
        if (empty($date_str)) {
            return 0;
        }
        try {
            $tz = self::get_store_timezone();
            $dt = new DateTime($date_str, $tz);
            return (int) $dt->getTimestamp();
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function get_teaser_start_timestamp(): int {
        return self::parse_to_timestamp($this->meta['teaser_start_datetime'] ?? '');
    }

    public function get_reveal_start_timestamp(): int {
        return self::parse_to_timestamp($this->meta['reveal_start_datetime'] ?? '');
    }

    public function get_end_timestamp(): int {
        return self::parse_to_timestamp($this->meta['end_datetime'] ?? '');
    }

    /**
     * Single source of truth for time across SneakyPeak
     */
    public static function now(): int {
        return Preview::now();
    }

    /**
     * Evaluate the campaign phase dynamically at request time
     */
    public function get_phase(?int $now = null): string {
        if (get_post_status($this->id) !== 'publish' || ($this->meta['enabled'] ?? 'yes') !== 'yes') {
            return self::PHASE_DRAFT;
        }

        if ($now === null) {
            $now = Preview::get_simulated_time_for_campaign($this);
        }

        $teaser_time = $this->get_teaser_start_timestamp();
        $reveal_time = $this->get_reveal_start_timestamp();
        $end_time    = $this->get_end_timestamp();

        // If reveal start is missing or invalid, campaign cannot be scheduled/live
        if ($reveal_time <= 0) {
            return self::PHASE_DRAFT;
        }

        // If end time is configured and passed
        if ($end_time > 0 && $now >= $end_time) {
            return self::PHASE_ENDED;
        }

        // Live phase: current time has reached or passed reveal start
        if ($now >= $reveal_time) {
            return self::PHASE_LIVE;
        }

        // Prior to reveal start:
        // If teaser start is configured and now is before teaser start, it's scheduled
        if ($teaser_time > 0 && $now < $teaser_time) {
            return self::PHASE_SCHEDULED;
        }

        // Otherwise (teaser start reached or no teaser start set), it is in Teaser phase
        return self::PHASE_TEASER;
    }

    public function is_active(?int $now = null): bool {
        $phase = $this->get_phase($now);
        return $phase === self::PHASE_TEASER || $phase === self::PHASE_LIVE;
    }

    /**
     * Check if the campaign is currently guarding prices (Scheduled or Teaser phases).
     *
     * In both Scheduled (before teaser start) and Teaser phases, the early sale price guard
     * is active so the real sale price never leaks before reveal (Live).
     */
    public function is_guarding(?int $now = null): bool {
        $phase = $this->get_phase($now);
        return $phase === self::PHASE_SCHEDULED || $phase === self::PHASE_TEASER;
    }
}
