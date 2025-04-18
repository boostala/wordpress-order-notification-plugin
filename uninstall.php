<?php
// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Drop the custom table
global $wpdb;
$table_name = $wpdb->prefix . 'boostala_devices';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Delete any options if they exist
delete_option('wponb_whatsapp_number');
delete_option('wponb_api_key');
delete_option('wponb_message_template');
delete_option('wponb_enabled_statuses');
