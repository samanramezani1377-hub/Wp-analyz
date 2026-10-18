<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
wp_clear_scheduled_hook('wpa_daily_cleanup');
if (defined('WPA_DELETE_DATA_ON_UNINSTALL') && WPA_DELETE_DATA_ON_UNINSTALL) {
 global $wpdb; $table=$wpdb->prefix.'wpa_events'; $wpdb->query("DROP TABLE IF EXISTS {$table}"); delete_option('wpa_settings'); delete_option('wpa_db_version');
}
