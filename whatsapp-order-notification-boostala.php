<?php
/**
 * Plugin Name: Whatsapp Order Notification by Boostala
 * Plugin URI: https://boostala.com
 * Description: Send WhatsApp notifications for WooCommerce orders
 * Version: 1.0.0
 * Author: Boostala
 * Author URI: https://boostala.com
 * Text Domain: whatsapp-order-notification-boostala
 * Domain Path: /languages
 * Requires at least: 6.7.2
 * Requires PHP: 8.0
 * WC requires at least: 9.7.1
 * WC tested up to: 9.7.1
 * HPOS: true
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('WPONB_VERSION', '1.0.0');
define('WPONB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPONB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPONB_TABLE_NAME', 'boostala_devices');
define('WPONB_BASE_URL', 'https://chat.boostala.com');
define('WPONB_LOGIN_URL', WPONB_BASE_URL . '/en/login');
define('WPONB_CONNECT_URL', WPONB_BASE_URL . '/en/wordpress/setup');
define('WPONB_TOKEN_LENGTH', 32);
define('WPONB_TOKEN_EXPIRY', 3600); // 1 hour in seconds

// Include required files
require_once WPONB_PLUGIN_DIR . 'includes/class-whatsapp-order-notification.php';
require_once WPONB_PLUGIN_DIR . 'includes/class-whatsapp-order-notification-templates.php';

// Initialize the plugin
function wponb_initialize_plugin() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wponb_woocommerce_missing_notice');
        return;
    }

    // Declare HPOS compatibility
    // if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
    //     \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    // }
    
    // Initialize main plugin class
    $plugin = new Whatsapp_Order_Notification();
    $plugin->init();
    
    // Initialize template management
    $templates = new Whatsapp_Order_Notification_Templates();
    $templates->init();
}
add_action('plugins_loaded', 'wponb_initialize_plugin');

/**
 * Load plugin text domain
 */
function wponb_load_textdomain() {
    load_plugin_textdomain(
        'whatsapp-order-notification-boostala',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
}
add_action('plugins_loaded', 'wponb_load_textdomain');

// Create database tables on plugin activation
function wponb_activate_plugin() {
    global $wpdb;
    
    // Create devices table
    $devices_table = $wpdb->prefix . WPONB_TABLE_NAME;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $devices_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        device_id varchar(255) NOT NULL,
        api_key varchar(255) NOT NULL,
        domain varchar(255) NOT NULL,
        token varchar(255) NOT NULL,
        token_expiry datetime NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY device_id (device_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'wponb_activate_plugin');

// Register REST API endpoint
function wponb_register_rest_routes() {
    register_rest_route('boostala/v1', '/device', array(
        'methods' => 'POST',
        'callback' => 'wponb_handle_device_registration',
        'permission_callback' => 'wponb_verify_token',
    ));
}
add_action('rest_api_init', 'wponb_register_rest_routes');

// Generate a secure token and store it in the database
function wponb_generate_token(): string {
    global $wpdb;
    $table_name = $wpdb->prefix . WPONB_TABLE_NAME;
    
    // Check if there's an existing record with device_id
    $existing_device = $wpdb->get_row(
        "SELECT * FROM $table_name WHERE device_id IS NOT NULL AND device_id != ''"
    );
    
    if ($existing_device) {
        return $existing_device->token;
    }
    
    // Generate new token
    $token = bin2hex(random_bytes(WPONB_TOKEN_LENGTH));
    $expiry = date('Y-m-d H:i:s', time() + WPONB_TOKEN_EXPIRY);
    
    // Delete any existing records without device_id
    $wpdb->query("DELETE FROM $table_name WHERE device_id IS NULL OR device_id = ''");
    
    // Store new token
    $wpdb->insert(
        $table_name,
        array(
            'token' => $token,
            'token_expiry' => $expiry,
            'created_at' => current_time('mysql')
        ),
        array('%s', '%s', '%s')
    );
    
    return $token;
}

// Verify Bearer token
function wponb_verify_token($request) {
    $auth_header = $request->get_header('Authorization');
    if (!$auth_header) {
        return new WP_Error('no_auth_header', 'Authorization header is missing', array('status' => 401));
    }

    if (strpos($auth_header, 'Bearer ') !== 0) {
        return new WP_Error('invalid_auth_header', 'Invalid authorization header format', array('status' => 401));
    }

    $token = substr($auth_header, 7);
    global $wpdb;
    $table_name = $wpdb->prefix . WPONB_TABLE_NAME;
    
    $device = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE token = %s AND token_expiry > NOW()",
        $token
    ));

    if (!$device) {
        return new WP_Error('invalid_token', 'Invalid or expired token', array('status' => 401));
    }

    return true;
}

// Handle device registration
function wponb_handle_device_registration($request) {
    $params = $request->get_json_params();
    
    if (!isset($params['device_id']) || !isset($params['api_key'])) {
        return new WP_Error('missing_params', 'Missing required parameters', array('status' => 400));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . WPONB_TABLE_NAME;
    
    // Get the latest record
    $device = $wpdb->get_row(
        "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 1"
    );

    if (!$device) {
        return new WP_Error('no_token', 'No token found', array('status' => 401));
    }

    // Update the record with device info
    $result = $wpdb->update(
        $table_name,
        array(
            'device_id' => sanitize_text_field($params['device_id']),
            'api_key' => sanitize_text_field($params['api_key']),
            'domain' => get_site_url(),
            'token_expiry' => date('Y-m-d H:i:s', time() + WPONB_TOKEN_EXPIRY)
        ),
        array('id' => $device->id)
    );

    if ($result === false) {
        return new WP_Error('db_error', 'Failed to update device information', array('status' => 500));
    }

    return array(
        'success' => true,
        'message' => 'Device information updated successfully',
        'token' => $device->token
    );
}

// WooCommerce missing notice
function wponb_woocommerce_missing_notice() {
    ?>
    <!-- <div class="error">
        <p><?php _e('Whatsapp Order Notification requires WooCommerce to be installed and active.', 'whatsapp-order-notification-boostala'); ?></p>
    </div> -->
    <?php
} 