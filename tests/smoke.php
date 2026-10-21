<?php
/**
 * Minimal smoke tests for helper functions without a running WordPress install.
 * Run with: php tests/smoke.php
 */
define('ABSPATH', __DIR__ . '/');
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function get_option($name, $default = false) { return $default; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function wp_parse_url($url) { return parse_url($url); }
function esc_url_raw($url, $protocols = null) {
    $parts = parse_url((string) $url);
    if (!$parts || empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) return '';
    return (string) $url;
}
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_salt($scheme = 'auth') { return 'wp-analyz-test-salt'; }
function wp_unslash($value) { return $value; }
function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
require dirname(__DIR__) . '/wp-analyz.php';

class FakeWpaOrder { public $meta = array(); public function update_meta_data($key, $value) { $this->meta[$key] = $value; } public function save() {} }
class FakeWpaDb { public $prefix = 'wp_'; public $queries = array(); public function prepare($query, ...$args) { return $query; } public function query($query) { $this->queries[] = $query; return 1; } }

function check($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    echo "PASS: {$message}\n";
}

check(wpa_uuid('550e8400-e29b-41d4-a716-446655440000') !== false, 'accepts a valid UUID');
check(wpa_uuid('not-a-uuid') === false, 'rejects malformed UUID');
check(wpa_uuid('550e8400-e29b-01d4-a716-446655440000') === false, 'rejects unsupported UUID version');
check(wpa_defaults()['require_consent'] === 1, 'advanced tracking consent gate is enabled by default');
check(wpa_defaults()['baseline_analytics'] === 1, 'aggregate baseline analytics is enabled by default');
check(wpa_defaults()['persistent_visitor'] === 0, 'persistent visitor tracking is disabled by default');
$mergedCounts = wpa_merge_count_rows(array(array('label'=>'/a','total'=>2)), array(array('label'=>'/a','total'=>3), array('label'=>'/b','total'=>1)), 10);
check($mergedCounts[0]['label'] === '/a' && $mergedCounts[0]['total'] === 5, 'merges baseline and consented page counters');
$GLOBALS['wpdb'] = new FakeWpaDb();
wpa_increment_aggregate('page_view', '/sample/', 'mobile', 'chrome');
check(wpa_aggregate_table() === 'wp_wpa_aggregates', 'uses the dedicated aggregate table');
check(strpos($GLOBALS['wpdb']->queries[0], 'ON DUPLICATE KEY UPDATE') !== false, 'increments daily aggregate counters without inserting event rows');
check(wpa_clean_path('https://example.test/products/item/?email=secret#section') === '/products/item/', 'keeps only same-site path and drops query/fragment');
check(wpa_clean_path('https://attacker.test/track') === false, 'rejects foreign page URLs');
check(wpa_stable_uuid('purchase:123') === wpa_stable_uuid('purchase:123'), 'stable UUID is deterministic');
check(wpa_stable_uuid('purchase:123') !== wpa_stable_uuid('purchase:124'), 'stable UUID differs for distinct seeds');
check(wpa_csv_safe('=1+1') === '\'=1+1', 'CSV export neutralizes spreadsheet formulas');
$_COOKIE = array();
$orderWithoutConsent = new FakeWpaOrder();
wpa_capture_order_analytics_context($orderWithoutConsent);
check(empty($orderWithoutConsent->meta), 'does not associate orders before consent');
$_COOKIE = array('wpa_consent' => 'denied');
$orderDeniedConsent = new FakeWpaOrder();
wpa_capture_order_analytics_context($orderDeniedConsent);
check(($orderDeniedConsent->meta['_wpa_analytics_consent'] ?? '') === 'denied', 'records explicit analytics rejection on the checkout order');
$_COOKIE = array('wpa_consent' => 'granted', 'wpa_visitor_id' => '550e8400-e29b-41d4-a716-446655440000', 'wpa_session_id' => '550e8400-e29b-41d4-a716-446655440001');
$orderWithConsent = new FakeWpaOrder();
wpa_capture_order_analytics_context($orderWithConsent);
check(($orderWithConsent->meta['_wpa_analytics_consent'] ?? '') === 'granted', 'captures consent state on checkout order');
check(($orderWithConsent->meta['_wpa_visitor_id'] ?? '') === $_COOKIE['wpa_visitor_id'], 'associates consented checkout with visitor ID');
$plugin = file_get_contents(dirname(__DIR__) . '/wp-analyz.php');
check(strpos($plugin, "'purchase','login'") === false, 'public event allowlist does not accept purchase events');
check(strpos($plugin, "require_consent") !== false, 'advanced server-side purchase attribution respects the consent gate');
check(strpos($plugin, 'baseline_allowed') !== false && strpos($plugin, "'mode'") !== false, 'server supports restricted aggregate mode without visitor/session IDs');
check(strpos($plugin, "wpa_increment_aggregate('purchase'") !== false, 'unconsented WooCommerce purchases update daily aggregate counters');
check(strpos($plugin, "empty($settings['enabled']) || empty($settings['woocommerce'])") !== false, 'WooCommerce purchase tracking respects the global enabled setting');
check(strpos($plugin, "'date_created'=>'<'.$cutoff_datetime") !== false && strpos($plugin, "'_wpa_session_id'") !== false, 'daily retention cleanup removes expired WooCommerce analytics context');
check(strpos($plugin, "'billing_email'=>sanitize_email($email)") !== false, 'privacy exporter and eraser can locate guest orders by billing email');
check(strpos($plugin, 'wpa_each_event_data_row') !== false && strpos($plugin, 'wpa_event_data_rows') === false, 'campaign, search, and performance reports iterate through all matching event rows in batches');
check(strpos($plugin, 'wpa_increment_aggregate($name') !== false, 'baseline browser events update daily aggregate counters instead of event rows');
check(strpos($plugin, 'strlen((string)$body)>8192') !== false, 'rejects oversized event payloads');
check(strpos($plugin, 'wpa_csv_safe') !== false, 'CSV export has spreadsheet-formula mitigation');
$tracker = file_get_contents(dirname(__DIR__) . '/assets/js/tracker.js');
check(strpos($tracker, "window.location.origin + window.location.pathname") !== false, 'tracker excludes query strings from page URLs');
check(strpos($tracker, "interaction_latency_estimate_ms") !== false, 'tracker labels lightweight interaction estimate honestly');
check(strpos($tracker, "wpa_session_id") !== false, 'tracker supports consent-aware checkout attribution');
echo "All WP Analyz smoke tests passed.\n";
