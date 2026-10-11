<?php
/**
 * SneakyPeak 1.3.1 Automated Test Suite
 *
 * Tests:
 * 1. Schedule: single events created at Teaser start, Reveal start and End. Saving with new dates reschedules them. Deleting or trashing a campaign clears them.
 * 2. Purge: purge_all calls detected cache functions and fires hook. Throttle prevents second purge within 60s for same reason, allows different reason.
 * 3. Preview redirect: adds sp_v to target URL, preserves query string and page number, strips preview actions, sp_v not in regular site links.
 */

// Define WordPress constants
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('SNEAKYPEAK_TESTING')) {
    define('SNEAKYPEAK_TESTING', true);
}
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', '');
}

// Global mock state
$GLOBALS['_wp_cron_schedule'] = array();
$GLOBALS['_wp_actions']       = array();
$GLOBALS['_wp_action_counts'] = array();
$GLOBALS['_posts_store']      = array();
$GLOBALS['_post_meta_store']  = array();
$GLOBALS['_called_cache_functions'] = array();
$GLOBALS['_redirect_target']  = null;
$GLOBALS['_transients_store'] = array();

// Mock WordPress functions
function wp_schedule_single_event($timestamp, $hook, $args = array()) {
    $GLOBALS['_wp_cron_schedule'][] = array(
        'timestamp' => (int) $timestamp,
        'hook'      => $hook,
        'args'      => $args,
    );
    return true;
}

function wp_next_scheduled($hook, $args = array()) {
    foreach ($GLOBALS['_wp_cron_schedule'] as $item) {
        if ($item['hook'] === $hook && $item['args'] === $args) {
            return $item['timestamp'];
        }
    }
    return false;
}

function wp_unschedule_event($timestamp, $hook, $args = array()) {
    foreach ($GLOBALS['_wp_cron_schedule'] as $idx => $item) {
        if ($item['hook'] === $hook && $item['args'] === $args && $item['timestamp'] === (int) $timestamp) {
            unset($GLOBALS['_wp_cron_schedule'][$idx]);
            $GLOBALS['_wp_cron_schedule'] = array_values($GLOBALS['_wp_cron_schedule']);
            return true;
        }
    }
    return false;
}

function wp_clear_scheduled_hook($hook, $args = array()) {
    foreach ($GLOBALS['_wp_cron_schedule'] as $idx => $item) {
        if ($item['hook'] === $hook && (empty($args) || $item['args'] === $args)) {
            unset($GLOBALS['_wp_cron_schedule'][$idx]);
        }
    }
    $GLOBALS['_wp_cron_schedule'] = array_values($GLOBALS['_wp_cron_schedule']);
    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['_wp_actions'][$hook][] = array('callback' => $callback, 'priority' => $priority, 'args' => $accepted_args);
    return true;
}

function do_action($hook, ...$args) {
    if (!isset($GLOBALS['_wp_action_counts'][$hook])) {
        $GLOBALS['_wp_action_counts'][$hook] = 0;
    }
    $GLOBALS['_wp_action_counts'][$hook]++;

    if (!empty($GLOBALS['_wp_actions'][$hook])) {
        foreach ($GLOBALS['_wp_actions'][$hook] as $reg) {
            call_user_func_array($reg['callback'], $args);
        }
    }
}

function get_post_status($post_id) {
    return $GLOBALS['_posts_store'][$post_id]['post_status'] ?? 'publish';
}

function get_post($post_id) {
    if (isset($GLOBALS['_posts_store'][$post_id])) {
        $obj = new stdClass();
        $obj->ID = $post_id;
        $obj->post_type = $GLOBALS['_posts_store'][$post_id]['post_type'] ?? 'sneakypeak_campaign';
        $obj->post_status = $GLOBALS['_posts_store'][$post_id]['post_status'] ?? 'publish';
        $obj->post_title = $GLOBALS['_posts_store'][$post_id]['post_title'] ?? 'Campaign #' . $post_id;
        return $obj;
    }
    return null;
}

function get_the_title($post_id) {
    return $GLOBALS['_posts_store'][$post_id]['post_title'] ?? 'Test Campaign';
}

function get_post_meta($post_id, $key = '', $single = false) {
    if ($key === '_sneakypeak_settings') {
        return $GLOBALS['_post_meta_store'][$post_id]['_sneakypeak_settings'] ?? array();
    }
    return $GLOBALS['_post_meta_store'][$post_id][$key] ?? '';
}

function update_post_meta($post_id, $key, $value) {
    $GLOBALS['_post_meta_store'][$post_id][$key] = $value;
    return true;
}

function get_transient($key) {
    return $GLOBALS['_transients_store'][$key] ?? false;
}

function set_transient($key, $value, $expiration = 0) {
    $GLOBALS['_transients_store'][$key] = $value;
    return true;
}

function delete_transient($key) {
    unset($GLOBALS['_transients_store'][$key]);
    return true;
}

function get_current_user_id() { return 1; }
function current_user_can($cap) { return true; }
function user_can($user, $cap) { return true; }
function is_admin() { return false; }
function is_ssl() { return false; }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)); }
function sanitize_text_field($t) { return trim(strip_tags($t)); }
function absint($val) { return abs((int) $val); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function esc_url_raw($u) { return filter_var($u, FILTER_SANITIZE_URL); }
function set_url_scheme($u, $scheme = null) { return 'https:' . $u; }
function home_url($p = '') { return 'https://example.com' . $p; }
function wp_validate_redirect($loc, $default = false) { return !empty($loc) ? $loc : $default; }
function wp_safe_redirect($loc) {
    $GLOBALS['_redirect_target'] = $loc;
}

function add_query_arg(...$args) {
    if (count($args) === 2 && is_array($args[0])) {
        $params = $args[0];
        $url = $args[1] ?? '';
    } elseif (count($args) === 2 && is_string($args[0])) {
        $params = array($args[0] => $args[1]);
        $url = '';
    } else {
        $params = array($args[0] => $args[1]);
        $url = $args[2] ?? '';
    }
    $parts = parse_url($url);
    $existing = array();
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $existing);
    }
    $merged = array_merge($existing, $params);
    $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'example.com') . ($parts['path'] ?? '/');
    if (!empty($merged)) {
        return $base . '?' . http_build_query($merged);
    }
    return $base;
}

function remove_query_arg($keys, $url = '') {
    if (empty($url)) {
        $url = 'https://example.com/';
    }
    $keys = (array) $keys;
    $parts = parse_url($url);
    $existing = array();
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $existing);
    }
    foreach ($keys as $k) {
        unset($existing[$k]);
    }
    $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'example.com') . ($parts['path'] ?? '/');
    if (!empty($existing)) {
        return $base . '?' . http_build_query($existing);
    }
    return $base;
}

function wp_parse_args($args, $defaults = array()) {
    if (is_object($args)) {
        $r = get_object_vars($args);
    } elseif (is_array($args)) {
        $r = $args;
    } else {
        parse_str((string) $args, $r);
    }
    if (is_array($defaults)) {
        return array_merge($defaults, $r);
    }
    return $r;
}

function wp_timezone() {
    return new DateTimeZone('UTC');
}

function wp_timezone_string() {
    return 'UTC';
}

function wp_date($format, $timestamp = null, $timezone = null) {
    if ($timestamp === null) $timestamp = time();
    $dt = new DateTime('@' . $timestamp);
    if ($timezone) $dt->setTimezone($timezone);
    return $dt->format($format);
}

function __($text, $domain = 'default') { return $text; }
function esc_html__($text, $domain = 'default') { return $text; }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }

// Mock external cache plugin functions
function rocket_clean_domain() { $GLOBALS['_called_cache_functions'][] = 'rocket_clean_domain'; }
function w3tc_flush_all() { $GLOBALS['_called_cache_functions'][] = 'w3tc_flush_all'; }
function wp_cache_clear_cache() { $GLOBALS['_called_cache_functions'][] = 'wp_cache_clear_cache'; }
function sg_cachepress_purge_cache() { $GLOBALS['_called_cache_functions'][] = 'sg_cachepress_purge_cache'; }
class autoptimizeCache {
    public static function clearall() { $GLOBALS['_called_cache_functions'][] = 'autoptimizeCache::clearall'; }
}
function wpfc_clear_all_cache() { $GLOBALS['_called_cache_functions'][] = 'wpfc_clear_all_cache'; }
function wp_cache_flush() { $GLOBALS['_called_cache_functions'][] = 'wp_cache_flush'; }
function wc_delete_product_transients() { $GLOBALS['_called_cache_functions'][] = 'wc_delete_product_transients'; }

// Load SneakyPeak classes
require_once __DIR__ . '/../includes/Campaign.php';
require_once __DIR__ . '/../includes/Support/Safe.php';
require_once __DIR__ . '/../includes/Support/Cache.php';
require_once __DIR__ . '/../includes/Preview.php';

use SneakyPeak\Campaign;
use SneakyPeak\Support\Cache;
use SneakyPeak\Preview;

echo "========================================================\n";
echo "SNEAKYPEAK 1.3.1 AUTOMATED VERIFICATION SUITE\n";
echo "========================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function assert_test(bool $condition, string $title, string $details = '') {
    global $pass_count, $fail_count;
    if ($condition) {
        echo "[PASS] $title\n";
        $pass_count++;
    } else {
        echo "[FAIL] $title\n";
        if (!empty($details)) {
            echo "       Details: $details\n";
        }
        $fail_count++;
    }
}

// -------------------------------------------------------------
// TEST 1: Schedule Creation, Reschedule on Save, Clear on Delete
// -------------------------------------------------------------
echo "--- TEST 1: Schedule & Lifecycle Events ---\n";

$now = time();
$teaser_time = $now + 1800; // +30 mins
$reveal_time = $now + 3600; // +60 mins
$end_time    = $now + 7200; // +120 mins

$camp_id = 101;
$GLOBALS['_posts_store'][$camp_id] = array(
    'post_type'   => 'sneakypeak_campaign',
    'post_status' => 'publish',
    'post_title'  => 'Summer Flash Drop',
);
$GLOBALS['_post_meta_store'][$camp_id]['_sneakypeak_settings'] = array(
    'enabled'                => 'yes',
    'teaser_start_datetime' => gmdate('Y-m-d H:i:s', $teaser_time),
    'reveal_start_datetime' => gmdate('Y-m-d H:i:s', $reveal_time),
    'end_datetime'          => gmdate('Y-m-d H:i:s', $end_time),
);

// Clear schedule table
$GLOBALS['_wp_cron_schedule'] = array();

// 1.1 Schedule campaign events
Cache::schedule_campaign_events($camp_id);

$sched_teaser = wp_next_scheduled(Cache::CRON_HOOK, array($camp_id, Campaign::PHASE_TEASER));
$sched_reveal = wp_next_scheduled(Cache::CRON_HOOK, array($camp_id, Campaign::PHASE_LIVE));
$sched_end    = wp_next_scheduled(Cache::CRON_HOOK, array($camp_id, Campaign::PHASE_ENDED));

assert_test($sched_teaser === $teaser_time, 'Single event scheduled at Teaser start time');
assert_test($sched_reveal === $reveal_time, 'Single event scheduled at Reveal (Live) start time');
assert_test($sched_end === $end_time, 'Single event scheduled at End time');
assert_test(count($GLOBALS['_wp_cron_schedule']) === 3, 'Exactly 3 single events scheduled in WP-Cron');

// 1.2 Diagnostics check
$campaign = Campaign::get($camp_id);
$diag = Cache::get_next_phase_change_diagnostics($campaign);
assert_test($diag !== null && $diag['phase'] === Campaign::PHASE_TEASER && $diag['status'] === 'scheduled',
    'Diagnostics reports upcoming Teaser milestone as scheduled');

// 1.3 Saving with new dates reschedules them
$new_teaser_time = $now + 2400; // +40 mins
$new_reveal_time = $now + 4800; // +80 mins
$new_end_time    = $now + 9600; // +160 mins

$GLOBALS['_post_meta_store'][$camp_id]['_sneakypeak_settings']['teaser_start_datetime'] = gmdate('Y-m-d H:i:s', $new_teaser_time);
$GLOBALS['_post_meta_store'][$camp_id]['_sneakypeak_settings']['reveal_start_datetime'] = gmdate('Y-m-d H:i:s', $new_reveal_time);
$GLOBALS['_post_meta_store'][$camp_id]['_sneakypeak_settings']['end_datetime']          = gmdate('Y-m-d H:i:s', $new_end_time);

Cache::schedule_campaign_events($camp_id);

$re_teaser = wp_next_scheduled(Cache::CRON_HOOK, array($camp_id, Campaign::PHASE_TEASER));
$re_reveal = wp_next_scheduled(Cache::CRON_HOOK, array($camp_id, Campaign::PHASE_LIVE));
$re_end    = wp_next_scheduled(Cache::CRON_HOOK, array($camp_id, Campaign::PHASE_ENDED));

assert_test($re_teaser === $new_teaser_time, 'Rescheduled Teaser event with new updated timestamp');
assert_test($re_reveal === $new_reveal_time, 'Rescheduled Reveal event with new updated timestamp');
assert_test($re_end === $new_end_time, 'Rescheduled End event with new updated timestamp');
assert_test(count($GLOBALS['_wp_cron_schedule']) === 3, 'Cron table retains exactly 3 events after reschedule');

// 1.4 Trashing or deleting campaign clears them
Cache::clear_campaign_events($camp_id);
assert_test(count($GLOBALS['_wp_cron_schedule']) === 0, 'Deleting / trashing campaign cleanly clears all scheduled cron events');

echo "\n";

// -------------------------------------------------------------
// TEST 2: Purge All Engine Calls, Hook Firing, and Throttling
// -------------------------------------------------------------
echo "--- TEST 2: Cache Purge & Throttling ---\n";

Cache::reset_throttle();
$GLOBALS['_called_cache_functions'] = array();
$GLOBALS['_wp_action_counts']       = array();

// Hook listener for sneakypeak_cache_purged
$purged_reasons_received = array();
add_action('sneakypeak_cache_purged', function($reason) use (&$purged_reasons_received) {
    $purged_reasons_received[] = $reason;
});
add_action('litespeed_purge_all', function() {
    $GLOBALS['_called_cache_functions'][] = 'litespeed_purge_all';
});

// 2.1 First purge execution
$res1 = Cache::purge_all('Milestone: Reveal start', $camp_id);

assert_test($res1 === true, 'First purge call executes successfully');
assert_test(in_array('rocket_clean_domain', $GLOBALS['_called_cache_functions']), 'WP Rocket purge function called');
assert_test(in_array('w3tc_flush_all', $GLOBALS['_called_cache_functions']), 'W3 Total Cache purge function called');
assert_test(in_array('wp_cache_clear_cache', $GLOBALS['_called_cache_functions']), 'WP Super Cache purge function called');
assert_test(in_array('sg_cachepress_purge_cache', $GLOBALS['_called_cache_functions']), 'SiteGround Optimizer purge function called');
assert_test(in_array('autoptimizeCache::clearall', $GLOBALS['_called_cache_functions']), 'Autoptimize purge method called');
assert_test(in_array('wpfc_clear_all_cache', $GLOBALS['_called_cache_functions']), 'WP Fastest Cache purge function called');
assert_test(in_array('wp_cache_flush', $GLOBALS['_called_cache_functions']), 'WordPress Object cache flush called');
assert_test(in_array('wc_delete_product_transients', $GLOBALS['_called_cache_functions']), 'WooCommerce product transients deleted');
assert_test(in_array('litespeed_purge_all', $GLOBALS['_called_cache_functions']), 'LiteSpeed litespeed_purge_all action hook fired');
assert_test(!empty($purged_reasons_received) && $purged_reasons_received[0] === 'Milestone: Reveal start',
    'sneakypeak_cache_purged action hook fired with correct reason');

// 2.2 Throttle prevents second purge within 60s for SAME reason
$res2 = Cache::purge_all('Milestone: Reveal start', $camp_id);
assert_test($res2 === false, 'Second purge with same reason within 60s is throttled (returns false)');

// 2.3 Throttle allows a DIFFERENT reason immediately
$res3 = Cache::purge_all('Preview exit', null);
assert_test($res3 === true, 'Purge with different reason executes immediately without throttling');

echo "\n";

// -------------------------------------------------------------
// TEST 3: Preview Redirect (sp_v cache-busting, URL preservation)
// -------------------------------------------------------------
echo "--- TEST 3: Preview Redirect & URL Preservation ---\n";

// 3.1 Redirect adds sp_v to target URL, preserves query string and page number, strips sneakypeak_action etc.
$_SERVER['REQUEST_URI'] = '/product-category/shoes/page/2/?orderby=price&sneakypeak_action=set_preview_phase&phase=live&campaign_id=101&_wpnonce=abc123';
$_SERVER['HTTP_HOST']   = 'example.com';
$_REQUEST['redirect_to'] = 'https://example.com/product-category/shoes/page/2/?orderby=price&sneakypeak_action=set_preview_phase&phase=live&campaign_id=101&_wpnonce=abc123';

$ref_method = new ReflectionMethod(Preview::class, 'perform_redirect');
$ref_method->setAccessible(true);

$GLOBALS['_redirect_target'] = null;
try {
    $ref_method->invoke(null);
} catch (\Throwable $e) {}

$redir = $GLOBALS['_redirect_target'];
assert_test(!empty($redir), 'perform_redirect performed redirect');
assert_test(strpos($redir, '/product-category/shoes/page/2/') !== false, 'Preserved target path and page number (/page/2/)');
assert_test(strpos($redir, 'orderby=price') !== false, 'Preserved custom query parameters (?orderby=price)');
assert_test(strpos($redir, 'sp_v=') !== false, 'Target URL includes sp_v cache-busting timestamp parameter');
assert_test(strpos($redir, 'sneakypeak_action') === false, 'Stripped sneakypeak_action parameter from target');
assert_test(strpos($redir, 'phase=') === false, 'Stripped phase parameter from target');
assert_test(strpos($redir, '_wpnonce=') === false, 'Stripped _wpnonce parameter from target');

// 3.2 Verify sp_v is NOT printed in regular site links or current_request_url
$cur_method = new ReflectionMethod(Preview::class, 'current_request_url');
$cur_method->setAccessible(true);

$_SERVER['REQUEST_URI'] = '/shop/shoes/?orderby=rating';
$site_url = $cur_method->invoke(null);
assert_test(strpos($site_url, 'sp_v') === false, 'current_request_url does NOT inject sp_v into clean storefront links');

echo "\n========================================================\n";
echo "TEST RESULTS SUMMARY: $pass_count PASSED, $fail_count FAILED\n";
echo "========================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
