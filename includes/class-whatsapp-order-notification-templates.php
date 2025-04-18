<?php

class Whatsapp_Order_Notification_Templates {
    private $api_base_url;

    public function __construct() {
        $this->api_base_url = WPONB_BASE_URL . '/api/wordpress';
    }

    public function init() {
        add_action('admin_menu', array($this, 'add_template_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wponb_save_template', array($this, 'save_template'));
        add_action('wp_ajax_wponb_update_template_status', array($this, 'update_template_status'));
    }

    public function add_template_menu() {
        add_submenu_page(
            'boostala-whatsapp',
            __('Templates', 'whatsapp-order-notification-boostala'),
            __('Templates', 'whatsapp-order-notification-boostala'),
            'manage_options',
            'boostala-templates',
            array($this, 'route_templates_page') // Use a routing method here
        );
    }
    
    public function route_templates_page() {
        // Check the 'action' parameter in the URL
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
    
        if ($action === 'edit') {
            // Call the edit page method
            $this->display_edit_template_page();
        } else {
            // Default to the templates list page
            $this->display_templates_page();
        }
    }

    public function enqueue_admin_scripts($hook) {
        if ('boostala-whatsapp_page_boostala-templates' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wponb-templates-styles',
            WPONB_PLUGIN_URL . 'assets/css/templates.css',
            array(),
            WPONB_VERSION
        );

        wp_enqueue_script(
            'wponb-templates-script',
            WPONB_PLUGIN_URL . 'assets/js/templates.js',
            array('jquery'),
            WPONB_VERSION,
            true
        );

        wp_localize_script('wponb-templates-script', 'wponb_templates', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wponb_templates_nonce')
        ));
    }

    public function display_templates_page() {
        $templatesRaw = $this->get_templates_from_api();
        if($templatesRaw && isset($templatesRaw->templates)) {
            $templates = $templatesRaw->templates;
        } else {
            $templates = array();
        }

        ?>
        <div class="wrap boostala-templates-container">
            <h1><?php _e('WhatsApp Templates', 'whatsapp-order-notification-boostala'); ?></h1>
            
            <div class="boostala-templates-list">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Template Name', 'whatsapp-order-notification-boostala'); ?></th>
                            <th><?php _e('Status', 'whatsapp-order-notification-boostala'); ?></th>
                            <th><?php _e('Actions', 'whatsapp-order-notification-boostala'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($templates)): ?>
                            <?php foreach ($templates as $template): ?>
                                <tr>
                                    <td><?php echo esc_html($template->name); ?></td>
                                    <td>
                                        <span class="template-status <?php echo $template->status ? 'active' : 'inactive'; ?>">
                                            <?php echo $template->status ? __('Active', 'whatsapp-order-notification-boostala') : __('Inactive', 'whatsapp-order-notification-boostala'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=boostala-templates&action=edit&id=' . $template->id); ?>" class="button button-primary">
                                            <?php _e('Edit', 'whatsapp-order-notification-boostala'); ?>
                                        </a>
                                        <button class="button toggle-status" data-id="<?php echo $template->id; ?>" data-status="<?php echo $template->status; ?>">
                                            <?php echo $template->status ? __('Disable', 'whatsapp-order-notification-boostala') : __('Enable', 'whatsapp-order-notification-boostala'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3"><?php _e('No templates found', 'whatsapp-order-notification-boostala'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('.toggle-status').on('click', function() {
                    const button = $(this);
                    const templateId = button.data('id');
                    const currentStatus = button.data('status');
                    const newStatus = currentStatus ? 0 : 1;
                    const confirmation = confirm('<?php _e('Are you sure you want to change the status?', 'whatsapp-order-notification-boostala'); ?>');

                    if (!confirmation) {
                        return;
                    }

                    button.prop('disabled', true).text('<?php _e('Processing...', 'whatsapp-order-notification-boostala'); ?>');

                    $.ajax({
                        url: wponb_templates.ajax_url,
                        method: 'POST',
                        data: {
                            action: 'wponb_update_template_status',
                            template_id: templateId,
                            status: newStatus,
                            nonce: wponb_templates.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert(response.data.message);
                            }
                        },
                        error: function() {
                            alert('<?php _e('An error occurred. Please try again.', 'whatsapp-order-notification-boostala'); ?>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text(currentStatus ? '<?php _e('Disable', 'whatsapp-order-notification-boostala'); ?>' : '<?php _e('Enable', 'whatsapp-order-notification-boostala'); ?>');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function display_edit_template_page() {
        $template_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $template = $template_id ? $this->get_template_from_api($template_id) : null;
        if ($template && isset($template->error)) {
            echo '<div class="error"><p>' . esc_html($template->error) . '</p></div>';
            return;
        }
        ?>
        <div class="wrap boostala-template-edit">
            <h1><?php _e('Edit Template', 'whatsapp-order-notification-boostala'); ?></h1>
            
            <form id="template-form" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="template_id" value="<?php echo $template_id; ?>">
                
                <table class="form-table" style="padding: 20px; border-radius: 5px; background-color: #f9f9f9;">
                    <tr>
                        <th scope="row">
                            <label for="template_name"><?php _e('Template Name', 'whatsapp-order-notification-boostala'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="template_name" id="template_name" 
                                   value="<?php echo $template ? esc_attr($template->name) : ''; ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="template_content"><?php _e('Template Content', 'whatsapp-order-notification-boostala'); ?></label>
                        </th>
                        <td>
                            <textarea name="template_content" id="template_content" rows="10" class="large-text"><?php 
                                echo $template ? esc_textarea($template->content) : ''; 
                            ?></textarea>
                            <p class="description">
                                <?php _e('Available shortcodes:', 'whatsapp-order-notification-boostala'); ?><br>
                                {order_id} - <?php _e('Order ID', 'whatsapp-order-notification-boostala'); ?><br>
                                {customer_name} - <?php _e('Customer Name', 'whatsapp-order-notification-boostala'); ?><br>
                                {order_total} - <?php _e('Order Total', 'whatsapp-order-notification-boostala'); ?><br>
                                {order_status} - <?php _e('Order Status', 'whatsapp-order-notification-boostala'); ?><br>
                                {order_date} - <?php _e('Order Date', 'whatsapp-order-notification-boostala'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="template_status"><?php _e('Status', 'whatsapp-order-notification-boostala'); ?></label>
                        </th>
                        <td>
                            <select name="template_status" id="template_status">
                                <option value="1" <?php selected($template ? $template->status : 1, 1); ?>>
                                    <?php _e('Active', 'whatsapp-order-notification-boostala'); ?>
                                </option>
                                <option value="0" <?php selected($template ? $template->status : 0, 0); ?>>
                                    <?php _e('Inactive', 'whatsapp-order-notification-boostala'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php if (isset($_GET['error']) && $_GET['error'] === '1'): ?>
                    <button type="submit" class="button button-primary" id="save-template-button">
                        <p><?php _e('An error occurred while saving the template. Please try again.', 'whatsapp-order-notification-boostala'); ?></p>
                    </div>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php _e('Save Template', 'whatsapp-order-notification-boostala'); ?>
                    </button>
            </form>
        </div>
        <?php
    }

    public function save_template() {
        check_ajax_referer('wponb_templates_nonce', 'nonce');

        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        $name = sanitize_text_field($_POST['template_name']);
        $content = wp_kses_post($_POST['template_content']);
        $status = isset($_POST['template_status']) ? intval($_POST['template_status']) : 0;

        $response = $this->save_template_to_api($template_id, $name, $content, $status);

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Template saved successfully', 'whatsapp-order-notification-boostala')));
    }

    public function update_template_status() {
        check_ajax_referer('wponb_templates_nonce', 'nonce');

        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        $status = isset($_POST['status']) ? intval($_POST['status']) : 0;

        $response = $this->update_template_status_in_api($template_id, $status);

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Status updated successfully', 'whatsapp-order-notification-boostala')));
    }

    private function get_templates_from_api() {
        $device = $this->get_active_device();
        
        if (!$device || empty($device->api_key)) {
            return array(); // Return an empty array if the device or api_key is invalid
        }
        if ($device) {
            // Make a GET request to the API to fetch templates
            $response = wp_remote_get($this->api_base_url . '/templates', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $device->api_key,
                    'Content-Type' => 'application/json'
                )
            ));
            if (is_wp_error($response)) {
                error_log('Error fetching templates: ' . $response->get_error_message());
                return array();
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                error_log('Unexpected status code: ' . $status_code);
                return array();
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JSON decode error: ' . json_last_error_msg());
                return array();
            }

            return $data ?? array();
        }
    }

    private function get_template_from_api($id) {
        $device = $this->get_active_device();
        if (!$device) {
            error_log(__('No active device found', 'whatsapp-order-notification-boostala'));
            return null;
        }

        $response = wp_remote_get($this->api_base_url . '/templates/' . $id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $device->api_key,
                'Content-Type' => 'application/json'
            )
        ));
        

        if (is_wp_error($response)) {
            error_log(__('Error fetching template: ', 'whatsapp-order-notification-boostala') . $response->get_error_message());
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log(__('Unexpected status code: ', 'whatsapp-order-notification-boostala') . $status_code);
            return null;
        }
        

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log(__('JSON decode error: ', 'whatsapp-order-notification-boostala') . json_last_error_msg());
            return null;
        }

        if (isset($data->error)) {
            error_log(__('API error: ', 'whatsapp-order-notification-boostala') . $data->error);
            return null;
        }

        return $data->template ?? null;
    }

    private function save_template_to_api($id, $name, $content, $status) {
        $device = $this->get_active_device();
        if (!$device) {
            return new WP_Error('no_device', __('No active device found', 'whatsapp-order-notification-boostala'));
        }

        $endpoint = $id ? '/templates/' . $id : '/content';
        $method = $id ? 'PUT' : 'POST';

        $response = wp_remote_request($this->api_base_url . $endpoint, array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $device->token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'name' => $name,
                'content' => $content,
                'status' => $status
            ))
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (isset($data->error)) {
            return new WP_Error('api_error', $data->error);
        }

        return $data;
    }

    private function update_template_status_in_api($id, $status) {
        $device = $this->get_active_device();
        if (!$device) {
            return new WP_Error('no_device', __('No active device found', 'whatsapp-order-notification-boostala'));
        }

        $response = wp_remote_request($this->api_base_url . '/templates/' . $id . '/status', array(
            'method' => 'PUT',
            'headers' => array(
                'Authorization' => 'Bearer ' . $device->token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'status' => $status
            ))
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (isset($data->error)) {
            return new WP_Error('api_error', $data->error);
        }

        return $data;
    }

 

    private function get_active_device() {
        global $wpdb;
        $table_name = $wpdb->prefix . WPONB_TABLE_NAME;
        
        return $wpdb->get_row(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 1"
        );
    }
}