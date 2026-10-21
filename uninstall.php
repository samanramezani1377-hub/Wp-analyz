<?php
/**
 * Remove WP Analyz-owned data when the plugin is deleted from WordPress.
 *
 * Deactivation alone intentionally preserves analytics history. Deleting the
 * plugin is destructive and removes the plugin's own tables, settings,
 * database-backed transients, scheduled cleanup, and WooCommerce metadata.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Stop the plugin's scheduled cleanup before removing its storage.
wp_clear_scheduled_hook('wpa_daily_cleanup');

// Drop only tables owned by WP Analyz.
$tables = array(
    $wpdb->prefix . 'wpa_events',
    $wpdb->prefix . 'wpa_aggregates',
);
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Remove only WP Analyz's own settings/version options.
delete_option('wpa_settings');
delete_option('wpa_db_version');

// Remove analytics metadata from WooCommerce orders in both legacy postmeta
// storage and HPOS order-meta storage. Core order data is not modified.
$meta_keys = array(
    '_wpa_analytics_consent',
    '_wpa_visitor_id',
    '_wpa_session_id',
    '_wpa_analytics_purchase_recorded',
    '_wpa_analytics_refund_ids',
);
$placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
$meta_tables = array($wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta');
foreach (array_unique($meta_tables) as $meta_table) {
    $exists = $wpdb->get_var($wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($meta_table)
    ));
    if ($exists !== $meta_table) {
        continue;
    }
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$meta_table} WHERE meta_key IN ({$placeholders})",
        $meta_keys
    ));
}

// Delete WP Analyz's database-backed transient records and their corresponding
// cache entries. Do not flush the site's shared object cache or other plugins' data.
$transient_prefix = $wpdb->esc_like('_transient_wpa_') . '%';
$timeout_prefix = $wpdb->esc_like('_transient_timeout_wpa_') . '%';
$transient_options = $wpdb->get_col($wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options}
     WHERE option_name LIKE %s OR option_name LIKE %s",
    $transient_prefix,
    $timeout_prefix
));
foreach ((array) $transient_options as $option_name) {
    if (preg_match('/^_transient_(?:timeout_)?(wpa_[A-Za-z0-9_-]+)$/', $option_name, $matches)) {
        delete_transient($matches[1]);
    }
}
