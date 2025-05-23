<?php
/**
 * Main Plugin Class
 *
 * @package Printify_SureCart_Sync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Main Plugin Class
 */
class Printify_SureCart_Sync_Main {
    /**
     * Printify API token
     *
     * @var string
     */
    private $printify_api_token;

    /**
     * Printify shop ID
     *
     * @var string
     */
    private $printify_shop_id;

    /**
     * Printify API instance
     *
     * @var Printify_SureCart_Sync_API
     */
    private $printify_api;

    /**
     * SureCart integration instance
     *
     * @var Printify_SureCart_Sync_Integration
     */
    private $surecart_integration;
    
    /**
     * Order sync instance
     *
     * @var Printify_SureCart_Sync_Order_Sync
     */
    private $order_sync;

    /**
     * Constructor
     */
    public function __construct() {
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize the plugin
        add_action('plugins_loaded', array($this, 'init'));
    }

    /**
     * Load dependencies
     */
    private function load_dependencies() {
        // Load Printify API class
        require_once PRINTIFY_SURECART_SYNC_PLUGIN_DIR . 'includes/class-printify-api.php';
        
        // Load SureCart integration class
        require_once PRINTIFY_SURECART_SYNC_PLUGIN_DIR . 'includes/class-surecart-integration.php';
        
        // Load Order Sync class
        require_once PRINTIFY_SURECART_SYNC_PLUGIN_DIR . 'includes/class-order-sync.php';
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if SureCart is active
        if (!class_exists('SureCart\Models\Product')) {
            add_action('admin_notices', array($this, 'surecart_missing_notice'));
            return;
        }

        // Load plugin text domain
        load_plugin_textdomain('printify-surecart-sync', false, dirname(plugin_basename(PRINTIFY_SURECART_SYNC_PLUGIN_DIR)) . '/languages');

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Get settings
        $this->printify_api_token = get_option('printify_surecart_sync_api_token', '');
        $this->printify_shop_id = get_option('printify_surecart_sync_shop_id', '');

        // Initialize API and integration classes
        if (!empty($this->printify_api_token) && !empty($this->printify_shop_id)) {
            $this->printify_api = new Printify_SureCart_Sync_API($this->printify_api_token, $this->printify_shop_id);
            $this->surecart_integration = new Printify_SureCart_Sync_Integration();
            $this->order_sync = new Printify_SureCart_Sync_Order_Sync($this->printify_api);
        }

        // Add AJAX handlers
        add_action('wp_ajax_printify_surecart_sync_products', array($this, 'ajax_sync_products'));
        add_action('wp_ajax_printify_surecart_sync_order', array($this, 'ajax_sync_order'));

        // Add cron schedule for automatic syncing
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        add_action('printify_surecart_sync_cron', array($this, 'sync_products'));

        // Schedule cron if not already scheduled and auto-sync is enabled
        if (get_option('printify_surecart_sync_auto_sync', 0) && !wp_next_scheduled('printify_surecart_sync_cron')) {
            wp_schedule_event(time(), 'daily', 'printify_surecart_sync_cron');
        }
    }

    /**
     * Add custom cron schedule
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_schedule($schedules) {
        $schedules['daily'] = array(
            'interval' => 86400, // 24 hours in seconds
            'display' => __('Once Daily', 'printify-surecart-sync')
        );
        return $schedules;
    }

    /**
     * Display notice if SureCart is not active
     */
    public function surecart_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Printify SureCart Sync requires SureCart to be installed and activated.', 'printify-surecart-sync'); ?></p>
        </div>
        <?php
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'settings',
            __('Printify Sync', 'printify-surecart-sync'),
            __('Printify Sync', 'printify-surecart-sync'),
            'manage_options',
            'printify-surecart-sync',
            array($this, 'admin_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('printify_surecart_sync_settings', 'printify_surecart_sync_api_token');
        register_setting('printify_surecart_sync_settings', 'printify_surecart_sync_shop_id');
        register_setting('printify_surecart_sync_settings', 'printify_surecart_sync_auto_sync');
        register_setting('printify_surecart_sync_settings', 'printify_surecart_sync_order_sync');
        register_setting('printify_surecart_sync_settings', 'printify_surecart_sync_order_statuses');
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin page
        if ('surecart_page_printify-surecart-sync' !== $hook) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'printify-surecart-sync-admin',
            PRINTIFY_SURECART_SYNC_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            PRINTIFY_SURECART_SYNC_VERSION
        );
        
        // Enqueue JS
        wp_enqueue_script(
            'printify-surecart-sync-admin',
            PRINTIFY_SURECART_SYNC_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            PRINTIFY_SURECART_SYNC_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script(
            'printify-surecart-sync-admin',
            'printifySureCartSync',
            array(
                'nonce' => wp_create_nonce('printify_surecart_sync_nonce'),
                'errorText' => __('An error occurred during the sync process', 'printify-surecart-sync'),
                'showText' => __('Show', 'printify-surecart-sync'),
                'hideText' => __('Hide', 'printify-surecart-sync'),
                'enterOrderIdText' => __('Please enter a valid order ID', 'printify-surecart-sync'),
                'syncingOrderText' => __('Syncing order...', 'printify-surecart-sync'),
                'orderSyncedText' => __('Order successfully synced to Printify', 'printify-surecart-sync'),
            )
        );
    }

    /**
     * Admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <div class="printify-surecart-sync-header">
                <h1><?php _e('Printify SureCart Sync', 'printify-surecart-sync'); ?></h1>
                <p><?php _e('Sync your Printify products with SureCart to easily sell print-on-demand products.', 'printify-surecart-sync'); ?></p>
            </div>
            
            <div class="printify-surecart-sync-section">
                <h2><?php _e('Settings', 'printify-surecart-sync'); ?></h2>
                
                <form method="post" action="options.php">
                    <?php settings_fields('printify_surecart_sync_settings'); ?>
                    <?php do_settings_sections('printify_surecart_sync_settings'); ?>
                    
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php _e('Printify API Token', 'printify-surecart-sync'); ?></th>
                            <td>
                                <input type="password" id="printify_surecart_sync_api_token" name="printify_surecart_sync_api_token" value="<?php echo esc_attr(get_option('printify_surecart_sync_api_token')); ?>" class="regular-text" />
                                <a href="#" id="toggle-api-token"><?php _e('Show', 'printify-surecart-sync'); ?></a>
                                <p class="description"><?php _e('Enter your Printify API token. You can generate one in your Printify account under My Profile > Connections.', 'printify-surecart-sync'); ?></p>
                            </td>
                        </tr>
                        
                        <tr valign="top">
                            <th scope="row"><?php _e('Printify Shop ID', 'printify-surecart-sync'); ?></th>
                            <td>
                                <input type="text" name="printify_surecart_sync_shop_id" value="<?php echo esc_attr(get_option('printify_surecart_sync_shop_id')); ?>" class="regular-text" />
                                <p class="description"><?php _e('Enter your Printify Shop ID. You can find this in the URL when viewing your shop in Printify.', 'printify-surecart-sync'); ?></p>
                            </td>
                        </tr>
                        
                        <tr valign="top">
                            <th scope="row"><?php _e('Auto Sync', 'printify-surecart-sync'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="printify_surecart_sync_auto_sync" value="1" <?php checked(1, get_option('printify_surecart_sync_auto_sync', 0)); ?> />
                                    <?php _e('Automatically sync products daily', 'printify-surecart-sync'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr valign="top">
                            <th scope="row"><?php _e('Order Sync', 'printify-surecart-sync'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="printify_surecart_sync_order_sync" value="1" <?php checked(1, get_option('printify_surecart_sync_order_sync', 0)); ?> />
                                    <?php _e('Automatically sync orders to Printify', 'printify-surecart-sync'); ?>
                                </label>
                                <p class="description"><?php _e('When enabled, new orders in SureCart will be automatically sent to Printify for fulfillment.', 'printify-surecart-sync'); ?></p>
                            </td>
                        </tr>
                        
                        <tr valign="top">
                            <th scope="row"><?php _e('Order Statuses to Sync', 'printify-surecart-sync'); ?></th>
                            <td>
                                <?php
                                $order_statuses = array(
                                    'paid' => __('Paid', 'printify-surecart-sync'),
                                    'processing' => __('Processing', 'printify-surecart-sync'),
                                    'completed' => __('Completed', 'printify-surecart-sync'),
                                );
                                
                                $selected_statuses = get_option('printify_surecart_sync_order_statuses', array('paid'));
                                
                                if (!is_array($selected_statuses)) {
                                    $selected_statuses = array('paid');
                                }
                                
                                foreach ($order_statuses as $status => $label) {
                                    ?>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox" name="printify_surecart_sync_order_statuses[]" value="<?php echo esc_attr($status); ?>" <?php checked(in_array($status, $selected_statuses)); ?> />
                                        <?php echo esc_html($label); ?>
                                    </label>
                                    <?php
                                }
                                ?>
                                <p class="description"><?php _e('Select which order statuses should trigger a sync to Printify.', 'printify-surecart-sync'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(); ?>
                </form>
            </div>
            
            <div class="printify-surecart-sync-section">
                <h2><?php _e('Manual Sync', 'printify-surecart-sync'); ?></h2>
                <p><?php _e('Click the button below to manually sync products from Printify to SureCart.', 'printify-surecart-sync'); ?></p>
                
                <button id="printify-surecart-sync-button" class="button button-primary">
                    <?php _e('Sync Products Now', 'printify-surecart-sync'); ?>
                </button>
                
                <div id="printify-surecart-sync-status" class="printify-surecart-sync-status" style="display: none;">
                    <span class="spinner is-active"></span>
                    <p><?php _e('Syncing products...', 'printify-surecart-sync'); ?></p>
                </div>
                
                <div id="printify-surecart-sync-results" class="printify-surecart-sync-results" style="display: none;">
                    <h3><?php _e('Sync Results', 'printify-surecart-sync'); ?></h3>
                    <div id="printify-surecart-sync-results-content"></div>
                </div>
            </div>
            
            <?php if (get_option('printify_surecart_sync_order_sync', 0)): ?>
            <div class="printify-surecart-sync-section">
                <h2><?php _e('Order Sync', 'printify-surecart-sync'); ?></h2>
                <p><?php _e('You can manually sync a specific SureCart order to Printify by entering the order ID below.', 'printify-surecart-sync'); ?></p>
                
                <div class="manual-order-sync" style="margin-bottom: 20px;">
                    <input type="text" id="order-id-to-sync" placeholder="<?php esc_attr_e('Enter SureCart Order ID', 'printify-surecart-sync'); ?>" class="regular-text">
                    <button id="sync-single-order" class="button button-secondary">
                        <?php _e('Sync Order', 'printify-surecart-sync'); ?>
                    </button>
                    
                    <div id="order-sync-status" style="display: none; margin-top: 10px;">
                        <span class="spinner is-active" style="float: left; margin-right: 5px;"></span>
                        <p style="margin: 0;"><?php _e('Syncing order...', 'printify-surecart-sync'); ?></p>
                    </div>
                    
                    <div id="order-sync-result" style="display: none; margin-top: 10px;"></div>
                </div>
                
                <h3><?php _e('Recent Order Syncs', 'printify-surecart-sync'); ?></h3>
                
                <?php
                global $wpdb;
                
                // Get recent orders with Printify sync data
                $orders = $wpdb->get_results(
                    "SELECT p.ID, p.post_date, pm.meta_value as printify_order_id
                    FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE pm.meta_key = '_printify_order_id'
                    ORDER BY p.post_date DESC
                    LIMIT 10"
                );
                
                if (!empty($orders)):
                ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Order #', 'printify-surecart-sync'); ?></th>
                            <th><?php _e('Date', 'printify-surecart-sync'); ?></th>
                            <th><?php _e('Printify Order ID', 'printify-surecart-sync'); ?></th>
                            <th><?php _e('Status', 'printify-surecart-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=surecart&tab=orders&id=' . $order->ID)); ?>">
                                    <?php echo esc_html($order->ID); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($order->post_date))); ?></td>
                            <td><?php echo esc_html($order->printify_order_id); ?></td>
                            <td>
                                <?php
                                $notes = get_post_meta($order->ID, '_printify_sync_notes', true);
                                if (is_array($notes) && !empty($notes)) {
                                    $latest_note = end($notes);
                                    echo esc_html($latest_note['note']);
                                } else {
                                    _e('Synced', 'printify-surecart-sync');
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p><?php _e('No orders have been synced to Printify yet.', 'printify-surecart-sync'); ?></p>
                <?php endif; ?>
                
                <p class="description" style="margin-top: 10px;">
                    <?php _e('This table shows the 10 most recent orders that have been synced to Printify.', 'printify-surecart-sync'); ?>
                </p>
            </div>
            <?php endif; ?>
            
            <div class="printify-surecart-sync-api-info">
                <h3><?php _e('API Information', 'printify-surecart-sync'); ?></h3>
                <p><?php _e('This plugin uses the Printify API to fetch product data and the SureCart API to create and update products.', 'printify-surecart-sync'); ?></p>
                <p><?php _e('For more information, visit:', 'printify-surecart-sync'); ?></p>
                <ul>
                    <li><a href="https://developers.printify.com/" target="_blank"><?php _e('Printify API Documentation', 'printify-surecart-sync'); ?></a></li>
                    <li><a href="https://surecart.com/docs/" target="_blank"><?php _e('SureCart Documentation', 'printify-surecart-sync'); ?></a></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for syncing products
     */
    public function ajax_sync_products() {
        // Check nonce
        check_ajax_referer('printify_surecart_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'printify-surecart-sync'));
        }
        
        // Sync products
        $result = $this->sync_products();
        
        // Output results
        echo $result;
        
        wp_die();
    }
    
    /**
     * AJAX handler for syncing a single order
     */
    public function ajax_sync_order() {
        // Check nonce
        check_ajax_referer('printify_surecart_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'printify-surecart-sync'));
        }
        
        // Get order ID
        $order_id = isset($_POST['order_id']) ? sanitize_text_field($_POST['order_id']) : '';
        
        if (empty($order_id)) {
            wp_send_json_error(array(
                'message' => __('Please enter a valid order ID.', 'printify-surecart-sync')
            ));
        }
        
        // Check if API and order sync are initialized
        if (!$this->printify_api || !$this->order_sync) {
            wp_send_json_error(array(
                'message' => __('Printify API is not properly configured. Please check your settings.', 'printify-surecart-sync')
            ));
        }
        
        // Get order from SureCart
        $order = \SureCart\Models\Order::find($order_id);
        
        if (!$order) {
            wp_send_json_error(array(
                'message' => __('Order not found in SureCart.', 'printify-surecart-sync')
            ));
        }
        
        // Check if order has already been synced
        $printify_order_id = get_post_meta($order_id, '_printify_order_id', true);
        
        if ($printify_order_id) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('This order has already been synced to Printify (ID: %s).', 'printify-surecart-sync'),
                    $printify_order_id
                )
            ));
        }
        
        // Sync order to Printify
        $result = $this->order_sync->sync_order_to_printify($order);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Order successfully synced to Printify (ID: %s).', 'printify-surecart-sync'),
                $result
            )
        ));
    }

    /**
     * Sync products from Printify to SureCart
     *
     * @return string HTML output of sync results
     */
    public function sync_products() {
        // Check if API token and shop ID are set
        if (empty($this->printify_api_token) || empty($this->printify_shop_id)) {
            return '<div class="notice notice-error"><p>' . __('Please set your Printify API token and shop ID in the settings.', 'printify-surecart-sync') . '</p></div>';
        }
        
        // Check if API and integration classes are initialized
        if (!$this->printify_api || !$this->surecart_integration) {
            $this->printify_api = new Printify_SureCart_Sync_API($this->printify_api_token, $this->printify_shop_id);
            $this->surecart_integration = new Printify_SureCart_Sync_Integration();
        }
        
        // Get products from Printify
        $printify_products = $this->printify_api->get_all_products();
        
        if (is_wp_error($printify_products)) {
            return '<div class="notice notice-error"><p>' . $printify_products->get_error_message() . '</p></div>';
        }
        
        if (empty($printify_products)) {
            return '<div class="notice notice-warning"><p>' . __('No products found in your Printify shop.', 'printify-surecart-sync') . '</p></div>';
        }
        
        // Process each product
        $created = 0;
        $updated = 0;
        $errors = 0;
        $error_messages = array();
        
        foreach ($printify_products as $printify_product) {
            // Get detailed product information
            $product_details = $this->printify_api->get_product($printify_product['id']);
            
            if (is_wp_error($product_details)) {
                $errors++;
                $error_messages[] = $product_details->get_error_message();
                continue;
            }
            
            // Process the product
            $result = $this->surecart_integration->process_product($product_details);
            
            if (is_wp_error($result)) {
                $errors++;
                $error_messages[] = $result->get_error_message();
            } elseif ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        }
        
        // Build output
        $output = '<div class="notice notice-success"><p>' . 
            sprintf(
                __('Sync completed. %d products created, %d products updated, %d errors.', 'printify-surecart-sync'),
                $created,
                $updated,
                $errors
            ) . 
            '</p></div>';
        
        if (!empty($error_messages)) {
            $output .= '<div class="notice notice-error"><p>' . __('Errors:', 'printify-surecart-sync') . '</p><ul>';
            foreach ($error_messages as $message) {
                $output .= '<li>' . esc_html($message) . '</li>';
            }
            $output .= '</ul></div>';
        }
        
        return $output;
    }
}