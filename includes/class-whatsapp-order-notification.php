<?php

class Whatsapp_Order_Notification {
    private $table_name;
    private $api_base_url;


    public function __construct() {
        $this->api_base_url = WPONB_BASE_URL . '/api/wordpress';
    }

    /**
     * Initialize the plugin
     */
    public function init(): void {
        global $wpdb;
        $this->table_name = $wpdb->prefix . WPONB_TABLE_NAME;

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
        // Add admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

        // Add AJAX handlers
        add_action('wp_ajax_wponb_check_device_status', array($this, 'check_device_status'));
        add_action('wp_ajax_wponb_validate_token', array($this, 'validate_token'));
        add_action('wp_ajax_wponb_logout', array($this, 'handle_logout'));

        // Add WooCommerce hooks
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
        add_action('woocommerce_new_order', array($this, 'handle_new_order'), 10, 1);
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility(): void {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }

    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_admin_styles(): void {
        wp_enqueue_style(
            'wponb-admin-styles',
            WPONB_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WPONB_VERSION
        );

        wp_enqueue_script(
            'wponb-admin-script',
            WPONB_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WPONB_VERSION,
            true
        );

        wp_localize_script('wponb-admin-script', 'wponb_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wponb_nonce')
        ));
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts(): void {
        wp_enqueue_script(
            'wponb-admin',
            WPONB_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WPONB_VERSION,
            true
        );

        wp_localize_script('wponb-admin', 'wponb_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wponb_nonce'),
            'strings' => array(
                'device_status' => esc_html__('Device Status', 'whatsapp-order-notification-boostala'),
                'not_connected' => esc_html__('Not connected to Boostala', 'whatsapp-order-notification-boostala'),
                'login_with_boostala' => esc_html__('Login with Boostala', 'whatsapp-order-notification-boostala'),
                'checking_status' => esc_html__('Checking device status...', 'whatsapp-order-notification-boostala'),
                'device_connected' => esc_html__('Device successfully connected!', 'whatsapp-order-notification-boostala'),
                'successfully_logged_out' => esc_html__('Successfully logged out', 'whatsapp-order-notification-boostala'),
                'failed_to_logout' => esc_html__('Failed to logout', 'whatsapp-order-notification-boostala')
            )
        ));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_menu_page(
            __('Boostala WhatsApp', 'whatsapp-order-notification-boostala'),
            __('Boostala WhatsApp', 'whatsapp-order-notification-boostala'),
            'manage_options',
            'boostala-whatsapp',
            array($this, 'display_admin_page'),
            'dashicons-whatsapp',
            56
        );
    }

    /**
     * Check device status via AJAX
     */
    public function check_device_status(): void {
        check_ajax_referer('wponb_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . WPONB_TABLE_NAME;
        
        // Get the latest record with valid token
        $device = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %s ORDER BY created_at DESC LIMIT 1", $table_name)
        );
        
        if (!$device) {
            wp_send_json_error(array(
                'message' => __('No valid token found', 'whatsapp-order-notification-boostala')
            ));
            return;
        }
        
        wp_send_json_success(array(
            'device_id' => $device->device_id,
            'token' => $device->token,
            'token_expiry' => $device->token_expiry
        ));
    }

    /**
     * Validate token via AJAX
     */
    public function validate_token(): void {
        check_ajax_referer('wponb_nonce', 'nonce');
        
        $token = sanitize_text_field($_POST['token']);
        if (empty($token)) {
            wp_send_json_error(array('message' => 'Token is required'));
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . WPONB_TABLE_NAME;
        
        // Get the record with this token
        $device = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE token = %s AND token_expiry > NOW()",
            $token
        ));
        
        if (!$device) {
            wp_send_json_error(array('message' => 'Invalid or expired token'));
            return;
        }
        
        // Update token expiry
        $new_expiry = date('Y-m-d H:i:s', time() + WPONB_TOKEN_EXPIRY);
        $wpdb->update(
            $table_name,
            array('token_expiry' => $new_expiry),
            array('id' => $device->id)
        );
        
        wp_send_json_success(array(
            'message' => 'Token validated successfully',
            'device_id' => $device->device_id,
            'api_key' => $device->api_key
        ));
    }

    /**
     * Handle logout request
     */
    public function handle_logout(): void {
        check_ajax_referer('wponb_nonce', 'nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . WPONB_TABLE_NAME;
        
        // Delete all records from the table
        $result = $wpdb->query("DELETE FROM $table_name");
        
        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }


    private function get_active_device() {
        global $wpdb;
        $table_name = $wpdb->prefix . WPONB_TABLE_NAME;
        
        return $wpdb->get_row(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 1"
        );
    }

    private function get_connected_device_info() {
        $device = $this->get_active_device();
        if (!$device || empty($device->api_key)) {
            return null; // Return null if no active device or API key is missing
        }

        $response = wp_remote_get($this->api_base_url . '/info-devices', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $device->api_key,
                'Content-Type' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            error_log('Error fetching device info: ' . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('Unexpected status code: ' . $status_code);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON decode error: ' . json_last_error_msg());
            return null;
        }

        return $data->info ?? null;
    }


    /**
     * Display admin page
     */
    public function display_admin_page(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . WPONB_TABLE_NAME;
        
        // Get the latest device record
        $device = $wpdb->get_row(
            "SELECT * FROM $table_name WHERE device_id IS NOT NULL AND device_id != '' ORDER BY created_at DESC LIMIT 1"
        );
        
        // Generate token only if no device is connected
        if (!$device) {
            $token = wponb_generate_token();
        }else{
            $device_info = $this->get_connected_device_info(); // Fetch connected device info
        }
        
        $shop_url = get_site_url();
        $login_url = WPONB_LOGIN_URL . '?redirect=' . urlencode(WPONB_CONNECT_URL . '?shop=' . urlencode($shop_url) . '&token=' . ($device ? $device->token : $token));
        ?>
        <div class="wrap boostala-whatsapp-container">
            <div class="boostala-whatsapp-header">
                <img src="<?php echo esc_url(WPONB_PLUGIN_URL . 'assets/images/logo.svg'); ?>" alt="Boostala Logo" class="boostala-logo">
                <h1><?php _e('Welcome to Boostala WhatsApp', 'whatsapp-order-notification-boostala'); ?></h1>
            </div>

            <div class="boostala-whatsapp-content">
                <div class="boostala-whatsapp-card">
                    <h2><?php _e('About Boostala', 'whatsapp-order-notification-boostala'); ?></h2>
                    <p><?php _e('Boostala is a powerful WhatsApp integration platform that helps you connect with your customers through WhatsApp Business API. Our platform provides:', 'whatsapp-order-notification-boostala'); ?></p>
                    <ul class="boostala-features">
                        <li><?php _e('Automated WhatsApp notifications for orders', 'whatsapp-order-notification-boostala'); ?></li>
                        <li><?php _e('Customer support through WhatsApp', 'whatsapp-order-notification-boostala'); ?></li>
                        <li><?php _e('Marketing campaigns via WhatsApp', 'whatsapp-order-notification-boostala'); ?></li>
                        <li><?php _e('Real-time order updates', 'whatsapp-order-notification-boostala'); ?></li>
                    </ul>
                </div>

                <div class="boostala-whatsapp-card">
                    <h2><?php _e('Device Status', 'whatsapp-order-notification-boostala'); ?></h2>
                    <?php if ($device): ?>
                        <?php if ($device_info): ?>
                            <div class="device-info">
                                <h2><?php _e('Connected Device Info', 'whatsapp-order-notification-boostala'); ?></h2>
                                <table class="form-table">
                                    <tr>
                                        <th><?php _e('Device ID', 'whatsapp-order-notification-boostala'); ?></th>
                                        <td><?php echo esc_html($device_info->body); ?></td>
                                    </tr>
                                    <tr>
                                        <th><?php _e('Status', 'whatsapp-order-notification-boostala'); ?></th>
                                        <td>
                                            <span class="<?php echo $device_info->status === 'Connected' ? 'status-connected' : 'status-disconnected'; ?>">
                                                <?php echo esc_html($device_info->status); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><?php _e('Messages Sent', 'whatsapp-order-notification-boostala'); ?></th>
                                        <td><?php echo esc_html($device_info->message_sent); ?></td>
                                    </tr>
                                    <tr>
                                        <th><?php _e('Last Updated', 'whatsapp-order-notification-boostala'); ?></th>
                                        <td>
                                            <?php 
                                                $updated_at = $device_info->updated_at; // Original timestamp
                                                $readable_date = date('F j, Y, g:i A', strtotime($updated_at)); // Format the date
                                                echo esc_html($readable_date); 
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="text-align: center;">
                                            <button id="boostala-logout" class="button button-secondary" style="text-align: center;">
                                                <span><?php _e('Logout', 'whatsapp-order-notification-boostala'); ?></span>
                                            </button>
                                        </td>
                                    </tr>

                                </table>

                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="boostala-disconnected">
                            <p><?php _e('Not connected to Boostala', 'whatsapp-order-notification-boostala'); ?></p>
                            <div class="boostala-login-container">
                                <a href="<?php echo esc_url($login_url); ?>" class="boostala-login-button" id="boostala-login">
                                    <?php _e('Login with Boostala', 'whatsapp-order-notification-boostala'); ?>
                                </a>
                                <div class="boostala-loader" style="display: none;">
                                    <div class="spinner"></div>
                                    <p><?php _e('Checking device status...', 'whatsapp-order-notification-boostala'); ?></p>
                                </div>
                                <div class="boostala-success" style="display: none;">
                                    <p class="success-message"><?php _e('Device successfully connected!', 'whatsapp-order-notification-boostala'); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="boostala-whatsapp-card">
                    <h2><?php _e('Need Help?', 'whatsapp-order-notification-boostala'); ?></h2>
                    <p><?php _e('Our support team is here to help you with any questions or issues you might have:', 'whatsapp-order-notification-boostala'); ?></p>
                    <ul class="boostala-support">
                        <li><?php _e('Email: support@boostala.com', 'whatsapp-order-notification-boostala'); ?></li>
                        <li><?php _e('Website: https://boostala.com', 'whatsapp-order-notification-boostala'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
}