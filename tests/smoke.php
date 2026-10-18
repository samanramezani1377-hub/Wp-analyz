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
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
require dirname(__DIR__) . '/wp-analyz.php';

class FakeWpaOrder { public $meta = array(); public function update_meta_data($key, $value) { $this->meta[$key] = $value; } public function save() {} }

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
check(wpa_clean_path('https://example.test/products/item/?email=secret#section') === '/products/item/', 'keeps only same-site path and drops query/fragment');
check(wpa_clean_path('https://attacker.test/track') === false, 'rejects foreign page URLs');
check(wpa_stable_uuid('purchase:123') === wpa_stable_uuid('purchase:123'), 'stable UUID is deterministic');
check(wpa_stable_uuid('purchase:123') !== wpa_stable_uuid('purchase:124'), 'stable UUID differs for distinct seeds');
check(wpa_csv_safe('=1+1') === '\'=1+1', 'CSV export neutralizes spreadsheet formulas');
$_COOKIE = array();
$orderWithoutConsent = new FakeWpaOrder();
wpa_capture_order_analytics_context($orderWithoutConsent);
check(empty($orderWithoutConsent->meta), 'does not associate orders before consent');
$_COOKIE = array('wpa_consent' => 'granted', 'wpa_visitor_id' => '550e8400-e29b-41d4-a716-446655440000', 'wpa_session_id' => '550e8400-e29b-41d4-a716-446655440001');
$orderWithConsent = new FakeWpaOrder();
wpa_capture_order_analytics_context($orderWithConsent);
check(($orderWithConsent->meta['_wpa_analytics_consent'] ?? '') === 'granted', 'captures consent state on checkout order');
check(($orderWithConsent->meta['_wpa_visitor_id'] ?? '') === $_COOKIE['wpa_visitor_id'], 'associates consented checkout with visitor ID');
$plugin = file_get_contents(dirname(__DIR__) . '/wp-analyz.php');
check(strpos($plugin, "'purchase','login'") === false, 'public event allowlist does not accept purchase events');
check(strpos($plugin, "require_consent") !== false, 'advanced server-side purchase attribution respects the consent gate');
check(strpos($plugin, 'baseline_allowed') !== false && strpos($plugin, "'mode'") !== false, 'server supports restricted aggregate mode without visitor/session IDs');
check(strpos($plugin, "'source' => 'woocommerce_aggregate'") !== false, 'unconsented WooCommerce purchases use aggregate-only fields');
check(strpos($plugin, 'strlen((string)$body)>8192') !== false, 'rejects oversized event payloads');
check(strpos($plugin, 'wpa_csv_safe') !== false, 'CSV export has spreadsheet-formula mitigation');
$tracker = file_get_contents(dirname(__DIR__) . '/assets/js/tracker.js');
check(strpos($tracker, "window.location.origin + window.location.pathname") !== false, 'tracker excludes query strings from page URLs');
check(strpos($tracker, "interaction_latency_estimate_ms") !== false, 'tracker labels lightweight interaction estimate honestly');
check(strpos($tracker, "wpa_session_id") !== false, 'tracker supports consent-aware checkout attribution');
echo "All WP Analyz smoke tests passed.\n";
