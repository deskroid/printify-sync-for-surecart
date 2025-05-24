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
        
        // Register AJAX handlers
        add_action('wp_ajax_printify_surecart_sync_products', array($this, 'ajax_sync_products'));
        add_action('wp_ajax_printify_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_printify_surecart_sync_order', array($this, 'ajax_sync_order'));
        add_action('wp_ajax_printify_dismiss_sync_notice', array($this, 'ajax_dismiss_sync_notice'));
        
        // Register cron hook for background processing
        add_action('printify_surecart_process_sync_batch', array($this, 'process_sync_batch'));
        
        // Add admin notices for sync status
        add_action('admin_notices', array($this, 'display_sync_status_notice'));

        // Check if SureCart is active
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        $surecart_active = is_plugin_active('surecart/surecart.php');
        error_log('SureCart plugin active: ' . ($surecart_active ? 'Yes' : 'No'));
        
        if (!$surecart_active) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . 
                     __('Printify SureCart Sync requires the SureCart plugin to be installed and activated.', 'printify-surecart-sync') . 
                     '</p></div>';
            });
        }
        
        // Initialize API and integration classes
        if (!empty($this->printify_api_token) && !empty($this->printify_shop_id)) {
            $this->printify_api = new Printify_SureCart_Sync_API($this->printify_api_token, $this->printify_shop_id);
            $this->surecart_integration = new Printify_SureCart_Sync_Integration();
            $this->order_sync = new Printify_SureCart_Sync_Order_Sync($this->printify_api);
        }

        // Add AJAX handlers
        add_action('wp_ajax_printify_surecart_sync_products', array($this, 'ajax_sync_products'));
        add_action('wp_ajax_printify_surecart_sync_order', array($this, 'ajax_sync_order'));
        add_action('wp_ajax_printify_test_connection', array($this, 'ajax_test_connection'));

        // Add cron schedule for automatic syncing
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        add_action('printify_surecart_sync_cron', array($this, 'sync_products'));
        
        // Add action for clearing sync progress
        add_action('printify_surecart_clear_sync_progress', array($this, 'clear_sync_progress'));

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
     * Display sync status notice on all admin pages
     */
    public function display_sync_status_notice() {
        // Only show on admin pages
        if (!is_admin()) {
            return;
        }
        
        // Check for a completed sync first
        $completed = get_transient('printify_surecart_sync_completed');
        if ($completed) {
            // Show completion notice
            $notice_class = 'notice-success';
            $notice_title = __('Printify Product Sync Completed', 'printify-surecart-sync');
            
            // Format the completion time
            $completion_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $completed['time']);
            
            $notice_message = sprintf(
                __('Sync completed at %s', 'printify-surecart-sync'),
                $completion_time
            );
            
            // Add details about created/updated/errors
            $notice_details = sprintf(
                __('Created: %d, Updated: %d, Errors: %d, Total: %d', 'printify-surecart-sync'),
                $completed['created'],
                $completed['updated'],
                $completed['errors'],
                $completed['total']
            );
            
            // Add a link to the sync page
            $sync_page_url = admin_url('admin.php?page=printify-surecart-sync');
            $view_details_text = __('View Details', 'printify-surecart-sync');
            $dismiss_text = __('Dismiss', 'printify-surecart-sync');
            
            // Output the notice
            ?>
            <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible">
                <p><strong><?php echo esc_html($notice_title); ?></strong></p>
                <p><?php echo esc_html($notice_message); ?></p>
                <p><?php echo esc_html($notice_details); ?></p>
                <p>
                    <a href="<?php echo esc_url($sync_page_url); ?>" class="button button-secondary"><?php echo esc_html($view_details_text); ?></a>
                    <a href="#" class="button button-secondary dismiss-sync-notice" data-notice="completed"><?php echo esc_html($dismiss_text); ?></a>
                </p>
            </div>
            <?php
            
            // Add script to handle dismissal
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('.dismiss-sync-notice').on('click', function(e) {
                        e.preventDefault();
                        var noticeType = $(this).data('notice');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'printify_dismiss_sync_notice',
                                nonce: '<?php echo wp_create_nonce('printify_dismiss_notice_nonce'); ?>',
                                notice_type: noticeType
                            }
                        });
                        
                        $(this).closest('.notice').fadeOut();
                    });
                });
            </script>
            <?php
            
            return;
        }
        
        // Get the current sync progress
        $progress = get_transient('printify_surecart_sync_progress');
        
        // If there's no sync in progress or it's completed, don't show anything
        if (!$progress || $progress['completed']) {
            return;
        }
        
        // Calculate progress percentage
        $percent = 0;
        if (isset($progress['total']) && $progress['total'] > 0) {
            $percent = round(($progress['processed'] / $progress['total']) * 100);
            $percent = max(0, min(100, $percent));
        }
        
        // Check if the sync is stalled (no updates for more than 5 minutes)
        $stalled = false;
        if (isset($progress['last_processed']) && (time() - $progress['last_processed']) > 300) {
            $stalled = true;
        }
        
        // Prepare the notice class and message
        $notice_class = 'notice-info';
        $notice_title = __('Printify Product Sync in Progress', 'printify-surecart-sync');
        $notice_message = sprintf(
            __('Processing %d of %d products (%d%% complete)', 'printify-surecart-sync'),
            $progress['processed'],
            $progress['total'],
            $percent
        );
        
        // Add details about created/updated/errors
        $notice_details = sprintf(
            __('Created: %d, Updated: %d, Errors: %d', 'printify-surecart-sync'),
            $progress['created'],
            $progress['updated'],
            $progress['errors']
        );
        
        // If the sync is stalled, show a warning
        if ($stalled) {
            $notice_class = 'notice-warning';
            $notice_title = __('Printify Product Sync May Be Stalled', 'printify-surecart-sync');
            $notice_message .= ' - ' . __('No updates in the last 5 minutes', 'printify-surecart-sync');
        }
        
        // Add a link to the sync page
        $sync_page_url = admin_url('admin.php?page=printify-surecart-sync');
        $view_details_text = __('View Details', 'printify-surecart-sync');
        
        // Output the notice
        ?>
        <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible">
            <p><strong><?php echo esc_html($notice_title); ?></strong></p>
            <p><?php echo esc_html($notice_message); ?></p>
            <p><?php echo esc_html($notice_details); ?></p>
            <p><a href="<?php echo esc_url($sync_page_url); ?>" class="button button-secondary"><?php echo esc_html($view_details_text); ?></a></p>
        </div>
        <?php
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'options-general.php',
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
        // Debug log to see which hook is being used
        error_log('Current admin page hook: ' . $hook);
        
        // Only load on our plugin page - check both possible hook names
        if ('settings_page_printify-surecart-sync' !== $hook && 'options-general_page_printify-surecart-sync' !== $hook) {
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
                'testConnectionText' => __('Test API Connection', 'printify-surecart-sync'),
                'startingSyncText' => __('Starting sync...', 'printify-surecart-sync'),
                'syncingProgressText' => __('Processing {processed} of {total} products...', 'printify-surecart-sync'),
                'syncCompletedText' => __('Sync Completed', 'printify-surecart-sync'),
                'syncCompletedDetailsText' => __('Created: {created}, Updated: {updated}, Errors: {errors}', 'printify-surecart-sync'),
            )
        );
    }

    /**
     * Admin page
     */
    public function admin_page() {
        // Check if we should automatically continue a sync
        if (isset($_GET['action']) && $_GET['action'] === 'continue_sync') {
            // Display the sync results
            echo $this->sync_products();
        }
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
                                <div style="margin-top: 10px;">
                                    <button type="button" id="printify-test-connection" class="button button-secondary">
                                        <?php _e('Test API Connection', 'printify-surecart-sync'); ?>
                                    </button>
                                    <span id="printify-connection-result" style="margin-left: 10px; display: none;"></span>
                                </div>
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
                
                <!-- Inline script to ensure button functionality -->
                <script type="text/javascript">
                    /* <![CDATA[ */
                    var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
                    /* ]]> */
                    
                    jQuery(document).ready(function($) {
                        console.log('Inline script loaded');
                        
                        // Check if we're continuing a sync (URL has action=continue_sync)
                        var urlParams = new URLSearchParams(window.location.search);
                        if (urlParams.get('action') === 'continue_sync') {
                            // Scroll to the sync results
                            $('html, body').animate({
                                scrollTop: $('#printify-surecart-sync-results').offset().top - 50
                            }, 500);
                        }
                        
                        $('#printify-test-connection').on('click', function() {
                            console.log('Test connection button clicked (inline)');
                            var $button = $(this);
                            var $result = $('#printify-connection-result');
                            var apiToken = $('#printify_surecart_sync_api_token').val();
                            var shopId = $('input[name="printify_surecart_sync_shop_id"]').val();
                            
                            alert('Testing connection to Printify API...');
                            
                            // Disable button and show loading
                            $button.prop('disabled', true).text('Testing...');
                            $result.hide();
                            
                            // Make AJAX request
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'printify_test_connection',
                                    nonce: '<?php echo wp_create_nonce('printify_surecart_sync_nonce'); ?>',
                                    api_token: apiToken,
                                    shop_id: shopId
                                },
                                success: function(response) {
                                    console.log('AJAX success response:', response);
                                    
                                    if (response.success) {
                                        $result.removeClass('notice-error').addClass('notice-success')
                                               .html(response.data.message)
                                               .show();
                                    } else {
                                        $result.removeClass('notice-success').addClass('notice-error')
                                               .html(response.data.message)
                                               .show();
                                    }
                                    
                                    // Re-enable button
                                    $button.prop('disabled', false).text('<?php _e('Test API Connection', 'printify-surecart-sync'); ?>');
                                },
                                error: function(xhr, status, error) {
                                    console.log('AJAX error:', status, error);
                                    
                                    $result.removeClass('notice-success').addClass('notice-error')
                                           .html('Error: ' + error)
                                           .show();
                                    
                                    // Re-enable button
                                    $button.prop('disabled', false).text('<?php _e('Test API Connection', 'printify-surecart-sync'); ?>');
                                }
                            });
                        });
                    });
                </script>
            </div>
            
            <div class="printify-surecart-sync-section">
                <h2><?php _e('Manual Sync', 'printify-surecart-sync'); ?></h2>
                <p><?php _e('Click the button below to manually sync products from Printify to SureCart.', 'printify-surecart-sync'); ?></p>
                
                <button id="printify-surecart-sync-button" class="button button-primary">
                    <?php _e('Sync Products Now', 'printify-surecart-sync'); ?>
                </button>
                
                <button id="printify-surecart-force-sync-button" class="button button-secondary" style="margin-left: 10px;">
                    <?php _e('Force Full Resync', 'printify-surecart-sync'); ?>
                </button>
                
                <p class="description" style="margin-top: 5px;">
                    <?php _e('Use "Force Full Resync" to update all products including prices, even if they haven\'t changed.', 'printify-surecart-sync'); ?>
                </p>
                
                <div id="printify-surecart-sync-status" class="printify-surecart-sync-status" style="display: none;">
                    <span class="spinner is-active"></span>
                    <p><?php _e('Syncing products...', 'printify-surecart-sync'); ?></p>
                </div>
                
                <div id="printify-surecart-sync-notice" style="display: none; margin-top: 15px; margin-bottom: 15px;">
                    <div class="notice notice-info">
                        <p><strong><?php _e('Sync in Progress', 'printify-surecart-sync'); ?></strong></p>
                        <p><?php _e('Products are being synced in the background. You can continue using the admin area.', 'printify-surecart-sync'); ?></p>
                        <p id="printify-surecart-sync-status-text"><?php _e('Starting sync...', 'printify-surecart-sync'); ?></p>
                    </div>
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
     * Clear sync progress
     */
    public function clear_sync_progress() {
        $progress_key = 'printify_surecart_sync_progress';
        $result = delete_transient($progress_key);
        error_log('Cleared sync progress. Result: ' . ($result ? 'Success' : 'Failed or not found'));
        
        // Double check it's really gone
        $progress = get_transient($progress_key);
        if ($progress) {
            error_log('WARNING: Progress still exists after deletion attempt!');
            // Force delete again
            delete_transient($progress_key);
            error_log('Attempted forced deletion again');
        } else {
            error_log('Confirmed progress is cleared');
        }
    }
    
    /**
     * Initialize the sync process
     * 
     * @param bool $force_resync Whether to force resync all products
     */
    public function initialize_sync($force_resync = false) {
        error_log('Initializing sync process' . ($force_resync ? ' with force resync' : ''));
        
        // Check if API token and shop ID are set
        if (empty($this->printify_api_token) || empty($this->printify_shop_id)) {
            error_log('API token or shop ID is empty');
            return false;
        }
        
        // Check if API and integration classes are initialized
        if (!$this->printify_api || !$this->surecart_integration) {
            error_log('Initializing API and integration classes');
            $this->printify_api = new Printify_SureCart_Sync_API($this->printify_api_token, $this->printify_shop_id);
            $this->surecart_integration = new Printify_SureCart_Sync_Integration();
        }
        
        // Test the API connection first
        error_log('Testing API connection');
        $connection_test = $this->printify_api->test_connection();
        if (is_wp_error($connection_test)) {
            error_log('Connection test failed: ' . $connection_test->get_error_message());
            return false;
        }
        
        error_log('Connection test successful, fetching products');
        
        // Get products from Printify
        error_log('Getting products from Printify API');
        $printify_products = $this->printify_api->get_all_products();
        
        if (is_wp_error($printify_products)) {
            error_log('Error getting products: ' . $printify_products->get_error_message());
            return false;
        }
        
        if (empty($printify_products)) {
            error_log('No products found');
            return false;
        }
        
        error_log('Found ' . count($printify_products) . ' products to process');
        
        // Initialize progress
        $progress = array(
            'total' => count($printify_products),
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'error_messages' => array(),
            'completed' => false,
            'products' => $printify_products, // Store the products data
            'force_resync' => $force_resync,
            'last_processed' => time()
        );
        
        // Save progress for 1 hour
        set_transient('printify_surecart_sync_progress', $progress, 3600);
        
        return true;
    }
    
    /**
     * Schedule the background sync process
     */
    public function schedule_background_sync() {
        error_log('Scheduling background sync process');
        
        // Schedule the event to run immediately
        if (!wp_next_scheduled('printify_surecart_process_sync_batch')) {
            wp_schedule_single_event(time(), 'printify_surecart_process_sync_batch');
            error_log('Scheduled immediate sync batch');
        } else {
            error_log('Sync batch already scheduled');
        }
    }
    
    /**
     * Process a batch of products in the background
     */
    public function process_sync_batch() {
        error_log('Processing sync batch');
        
        // Get the current progress
        $progress = get_transient('printify_surecart_sync_progress');
        
        if (!$progress || $progress['completed']) {
            error_log('No sync in progress or sync already completed');
            return;
        }
        
        // Update the last processed time
        $progress['last_processed'] = time();
        set_transient('printify_surecart_sync_progress', $progress, 3600);
        
        // Check if API and integration classes are initialized
        if (!$this->printify_api || !$this->surecart_integration) {
            error_log('Initializing API and integration classes');
            $this->printify_api = new Printify_SureCart_Sync_API($this->printify_api_token, $this->printify_shop_id);
            $this->surecart_integration = new Printify_SureCart_Sync_Integration();
        }
        
        // Process a batch of products
        $batch_size = 5; // Process 5 products at a time
        $time_limit = 25; // 25 seconds per batch
        $start_time = time();
        $force_resync = $progress['force_resync'] ?? false;
        
        $created = $progress['created'];
        $updated = $progress['updated'];
        $errors = $progress['errors'];
        $error_messages = $progress['error_messages'];
        $printify_products = $progress['products'];
        
        error_log('Processing batch: ' . $progress['processed'] . ' to ' . min($progress['processed'] + $batch_size, count($printify_products)) . ' of ' . count($printify_products));
        
        // Process products in the current batch
        for ($i = $progress['processed']; $i < min($progress['processed'] + $batch_size, count($printify_products)); $i++) {
            // Check if we've reached the time limit
            if (time() - $start_time > $time_limit) {
                error_log('Reached time limit. Saving progress and scheduling next batch.');
                break;
            }
            
            $printify_product = $printify_products[$i];
            error_log('Processing product ' . ($i + 1) . ' of ' . count($printify_products));
            
            // Check if product has an ID
            if (!isset($printify_product['id'])) {
                error_log('Product missing ID: ' . json_encode($printify_product));
                $errors++;
                $error_messages[] = 'Product missing ID';
                continue;
            }
            
            error_log('Getting detailed information for product ID: ' . $printify_product['id']);
            
            // Get detailed product information
            $product_details = $this->printify_api->get_product($printify_product['id']);
            
            if (is_wp_error($product_details)) {
                error_log('Error getting product details: ' . $product_details->get_error_message());
                $errors++;
                $error_messages[] = $product_details->get_error_message();
                continue;
            }
            
            error_log('Successfully retrieved product details, processing product');
            
            // Free up memory
            gc_collect_cycles();
            
            // Process the product - pass the force_resync parameter
            try {
                $result = $this->surecart_integration->process_product($product_details, $force_resync);
                
                if (is_wp_error($result)) {
                    error_log('Error processing product: ' . $result->get_error_message());
                    $errors++;
                    $error_messages[] = $result->get_error_message();
                } elseif ($result === 'created') {
                    error_log('Product created successfully');
                    $created++;
                } elseif ($result === 'updated') {
                    error_log('Product updated successfully');
                    $updated++;
                } else {
                    error_log('Unexpected result from process_product: ' . $result);
                    $errors++;
                    $error_messages[] = 'Unexpected result: ' . $result;
                }
            } catch (Exception $e) {
                error_log('Exception during product processing: ' . $e->getMessage());
                $errors++;
                $error_messages[] = 'Exception: ' . $e->getMessage();
            }
        }
        
        // Update progress
        $progress['processed'] = $i;
        $progress['created'] = $created;
        $progress['updated'] = $updated;
        $progress['errors'] = $errors;
        $progress['error_messages'] = $error_messages;
        $progress['last_processed'] = time();
        
        // Check if we've processed all products
        if ($progress['processed'] >= count($printify_products)) {
            error_log('All products processed. Sync completed.');
            $progress['completed'] = true;
            
            // Set a transient to show a completion notice
            $completion_data = array(
                'time' => time(),
                'created' => $progress['created'],
                'updated' => $progress['updated'],
                'errors' => $progress['errors'],
                'total' => $progress['total']
            );
            set_transient('printify_surecart_sync_completed', $completion_data, 3600); // Keep for 1 hour
        }
        
        // Save progress for 1 hour
        set_transient('printify_surecart_sync_progress', $progress, 3600);
        
        // Schedule the next batch if not completed
        if (!$progress['completed']) {
            wp_schedule_single_event(time(), 'printify_surecart_process_sync_batch');
            error_log('Scheduled next sync batch');
        }
    }

    /**
     * AJAX handler for syncing products
     */
    public function ajax_sync_products() {
        try {
            // Check nonce
            check_ajax_referer('printify_surecart_sync_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'printify-surecart-sync'));
            }
        
            // Debug log all POST data
            error_log('AJAX sync_products called with POST data: ' . json_encode($_POST));
            
            // Check if SureCart is active
            if (!function_exists('is_plugin_active')) {
                include_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }
            
            $surecart_active = is_plugin_active('surecart/surecart.php');
            error_log('SureCart plugin active: ' . ($surecart_active ? 'Yes' : 'No'));
            
            if (!$surecart_active) {
                wp_send_json_error(array(
                    'message' => __('SureCart plugin is not active. Please install and activate SureCart before syncing products.', 'printify-surecart-sync')
                ));
                return;
            }
            
            // Check if SureCart classes are loaded
            if (!class_exists('\SureCart\Models\Product')) {
                wp_send_json_error(array(
                    'message' => __('SureCart classes are not properly loaded. Please check your SureCart installation.', 'printify-surecart-sync')
                ));
                return;
            }
            
            // Check if this is a force resync
            $force_resync = isset($_POST['force_resync']) && $_POST['force_resync'] == 1;
            error_log('Force resync parameter: ' . ($force_resync ? 'YES' : 'NO'));
            
            // Check if this is a status check request
            $check_status = isset($_POST['check_status']) && $_POST['check_status'] == 1;
            
            if ($check_status) {
                // Return the current sync status
                $this->ajax_check_sync_status();
                return;
            }
            
            // Clear any existing progress if forcing resync
            if ($force_resync) {
                error_log('Force resync requested - clearing existing progress');
                $this->clear_sync_progress();
                
                // Make sure the progress is really cleared
                delete_transient('printify_surecart_sync_progress');
                error_log('Deleted progress transient directly');
            }
            
            // Initialize the sync process
            $this->initialize_sync($force_resync);
            
            // Schedule the background sync process
            $this->schedule_background_sync();
            
            // Send a response indicating the sync has started
            wp_send_json_success(array(
                'html' => '<div class="notice notice-info"><p>' . 
                    __('Sync process has been started in the background. You can continue using the admin area while products are being synced.', 'printify-surecart-sync') . 
                    '</p><p>' . 
                    __('The status will be updated automatically.', 'printify-surecart-sync') . 
                    '</p></div>',
                'message' => 'Sync started in background',
                'status' => 'started',
                'progress' => 0,
                'show_progress' => true
            ));
        } catch (Exception $e) {
            error_log('Exception in ajax_sync_products: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());
            wp_send_json_error(array(
                'message' => 'Error: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX handler for checking sync status
     */
    public function ajax_check_sync_status() {
        try {
            // Get the current progress
            $progress = get_transient('printify_surecart_sync_progress');
            
            if (!$progress) {
                // No sync in progress
                wp_send_json_success(array(
                    'status' => 'not_started',
                    'html' => '<div class="notice notice-info"><p>' . 
                        __('No sync process is currently running.', 'printify-surecart-sync') . 
                        '</p></div>',
                    'message' => 'No sync in progress'
                ));
                return;
            }
            
            // Calculate progress percentage
            $percent = 0;
            if (isset($progress['total']) && $progress['total'] > 0) {
                $percent = round(($progress['processed'] / $progress['total']) * 100);
                // Ensure percent is between 0 and 100
                $percent = max(0, min(100, $percent));
            }
            
            if ($progress['completed']) {
                // Sync is complete
                $html = '<div class="notice notice-success"><p>' . 
                    __('Sync completed successfully!', 'printify-surecart-sync') . 
                    '</p><p>' . 
                    sprintf(
                        __('Results: %d created, %d updated, %d errors.', 'printify-surecart-sync'),
                        $progress['created'],
                        $progress['updated'],
                        $progress['errors']
                    ) . 
                    '</p>';
                
                if ($progress['errors'] > 0) {
                    $html .= '<p>' . __('Errors:', 'printify-surecart-sync') . '</p><ul>';
                    foreach ($progress['error_messages'] as $message) {
                        $html .= '<li>' . esc_html($message) . '</li>';
                    }
                    $html .= '</ul>';
                }
                
                $html .= '</div>';
                
                wp_send_json_success(array(
                    'status' => 'completed',
                    'html' => $html,
                    'message' => 'Sync completed',
                    'progress' => 100,
                    'created' => $progress['created'],
                    'updated' => $progress['updated'],
                    'errors' => $progress['errors'],
                    'show_progress' => true
                ));
            } else {
                // Sync is in progress
                $html = '<div class="notice notice-info"><p>' . 
                    sprintf(
                        __('Sync in progress: %d of %d products processed (%d%%).', 'printify-surecart-sync'),
                        $progress['processed'],
                        $progress['total'],
                        $percent
                    ) . 
                    '</p><p>' . 
                    sprintf(
                        __('Current results: %d created, %d updated, %d errors.', 'printify-surecart-sync'),
                        $progress['created'],
                        $progress['updated'],
                        $progress['errors']
                    ) . 
                    '</p>';
                
                if ($progress['errors'] > 0) {
                    $html .= '<p>' . __('Errors so far:', 'printify-surecart-sync') . '</p><ul>';
                    foreach ($progress['error_messages'] as $message) {
                        $html .= '<li>' . esc_html($message) . '</li>';
                    }
                    $html .= '</ul>';
                }
                
                $html .= '</div>';
                
                wp_send_json_success(array(
                    'status' => 'in_progress',
                    'html' => $html,
                    'message' => 'Sync in progress',
                    'progress' => $percent,
                    'processed' => $progress['processed'],
                    'total' => $progress['total'],
                    'created' => $progress['created'],
                    'updated' => $progress['updated'],
                    'errors' => $progress['errors'],
                    'show_progress' => true
                ));
            }
        } catch (Exception $e) {
            error_log('Exception in ajax_check_sync_status: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());
            wp_send_json_error(array(
                'message' => 'Error: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX handler for testing API connection
     */
    public function ajax_test_connection() {
        try {
            error_log('AJAX test_connection handler called');
            
            // Check nonce
            check_ajax_referer('printify_surecart_sync_nonce', 'nonce');
        error_log('Nonce check passed');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            error_log('Permission check failed');
            wp_send_json_error(array(
                'message' => __('You do not have sufficient permissions to perform this action.', 'printify-surecart-sync')
            ));
            return;
        }
        
        error_log('Permission check passed');
        
        // Get API credentials
        $api_token = isset($_POST['api_token']) ? sanitize_text_field($_POST['api_token']) : get_option('printify_surecart_sync_api_token', '');
        $shop_id = isset($_POST['shop_id']) ? sanitize_text_field($_POST['shop_id']) : get_option('printify_surecart_sync_shop_id', '');
        
        error_log('API Token length: ' . strlen($api_token));
        error_log('Shop ID: ' . $shop_id);
        
        // Validate credentials
        if (empty($api_token) || empty($shop_id)) {
            error_log('Empty credentials');
            wp_send_json_error(array(
                'message' => __('Please enter both API token and Shop ID.', 'printify-surecart-sync')
            ));
            return;
        }
        
        // Initialize API
        $api = new Printify_SureCart_Sync_API($api_token, $shop_id);
        error_log('API initialized');
        
        // Test connection
        error_log('Testing connection...');
        $result = $api->test_connection();
        
        if (is_wp_error($result)) {
            error_log('Connection test failed: ' . $result->get_error_message());
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
        }
        
        error_log('Connection test successful');
        wp_send_json_success(array(
            'message' => __('Connection successful! Your API credentials are working correctly.', 'printify-surecart-sync')
        ));
        } catch (Exception $e) {
            error_log('Exception in ajax_test_connection: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());
            wp_send_json_error(array(
                'message' => 'Error: ' . $e->getMessage()
            ));
        }
    }
    
    public function ajax_sync_order() {
        try {
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
        } catch (Exception $e) {
            error_log('Exception in ajax_sync_order: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());
            wp_send_json_error(array(
                'message' => 'Error: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Sync products from Printify to SureCart
     *
     * @param bool $force_resync Whether to force resync all products
     * @return string HTML output of sync results
     */
    public function sync_products($force_resync = false) {
        try {
            error_log('Starting sync_products method' . ($force_resync ? ' with force resync' : ''));
        
        // Check if API token and shop ID are set
        if (empty($this->printify_api_token) || empty($this->printify_shop_id)) {
            error_log('API token or shop ID is empty');
            return '<div class="notice notice-error"><p>' . __('Please set your Printify API token and shop ID in the settings.', 'printify-surecart-sync') . '</p></div>';
        }
        
        // Check if there's an existing sync in progress
        $progress_key = 'printify_surecart_sync_progress';
        $progress = get_transient($progress_key);
        
        // If there's a progress transient and it's not completed, we'll continue from where we left off
        $continue_sync = false;
        if ($progress && isset($progress['completed']) && $progress['completed'] === false) {
            error_log('Found existing sync progress, continuing from where we left off');
            $continue_sync = true;
        }
        
        error_log('API token length: ' . strlen($this->printify_api_token));
        error_log('Shop ID: ' . $this->printify_shop_id);
        
        // Check if API and integration classes are initialized
        if (!$this->printify_api || !$this->surecart_integration) {
            error_log('Initializing API and integration classes');
            $this->printify_api = new Printify_SureCart_Sync_API($this->printify_api_token, $this->printify_shop_id);
            $this->surecart_integration = new Printify_SureCart_Sync_Integration();
        }
        
        // Test the API connection first
        error_log('Testing API connection');
        $connection_test = $this->printify_api->test_connection();
        if (is_wp_error($connection_test)) {
            error_log('Connection test failed: ' . $connection_test->get_error_message());
            return '<div class="notice notice-error"><p>' . 
                __('Failed to connect to Printify API: ', 'printify-surecart-sync') . 
                $connection_test->get_error_message() . 
                '</p><p>' . 
                __('Please verify your API token and shop ID are correct.', 'printify-surecart-sync') . 
                '</p></div>';
        }
        
        error_log('Connection test successful, fetching products');
        
        // If we're continuing a sync, we'll use the existing products data from the progress
        if ($continue_sync && isset($progress['products']) && !empty($progress['products'])) {
            error_log('Using existing products data from progress');
            $printify_products = $progress['products'];
        } else {
            // Get products from Printify
            error_log('Getting products from Printify API');
            $printify_products = $this->printify_api->get_all_products();
            
            if (is_wp_error($printify_products)) {
                error_log('Error getting products: ' . $printify_products->get_error_message());
                return '<div class="notice notice-error"><p>' . $printify_products->get_error_message() . '</p></div>';
            }
            
            if (empty($printify_products)) {
                error_log('No products found');
                return '<div class="notice notice-warning"><p>' . __('No products found in your Printify shop.', 'printify-surecart-sync') . '</p></div>';
            }
        }
        
        error_log('Found ' . count($printify_products) . ' products to process');
        
        // Process each product with memory management and time limits
        $created = 0;
        $updated = 0;
        $errors = 0;
        $error_messages = array();
        $batch_size = 3; // Process 3 products at a time
        $time_limit = 20; // 20 seconds per batch
        $start_time = time();
        
        // Store progress in a transient
        $progress_key = 'printify_surecart_sync_progress';
        $progress = get_transient($progress_key);
        
        if (!$progress) {
            $progress = array(
                'total' => count($printify_products),
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'errors' => 0,
                'error_messages' => array(),
                'completed' => false,
                'products' => $printify_products // Store the products data
            );
        } else {
            // Resume from previous progress
            $created = $progress['created'];
            $updated = $progress['updated'];
            $errors = $progress['errors'];
            $error_messages = $progress['error_messages'];
            
            // Make sure products are stored in the progress
            if (!isset($progress['products'])) {
                $progress['products'] = $printify_products;
                set_transient($progress_key, $progress, 3600);
            }
        }
        
        error_log('Starting/resuming sync with ' . count($printify_products) . ' products. Already processed: ' . $progress['processed']);
        
        // Force reset progress if force_resync is true
        if ($force_resync) {
            error_log('Force resync is enabled - resetting progress counter to 0');
            $progress['processed'] = 0;
            $progress['created'] = 0;
            $progress['updated'] = 0;
            $progress['errors'] = 0;
            $progress['error_messages'] = array();
            $created = 0;
            $updated = 0;
            $errors = 0;
            $error_messages = array();
        }
        
        // Debug the progress tracking
        error_log('Progress tracking: processed=' . $progress['processed'] . ', total=' . count($printify_products));
        
        // Process products in batches
        for ($i = $progress['processed']; $i < count($printify_products); $i++) {
            $printify_product = $printify_products[$i];
            error_log('Processing product ' . ($i + 1) . ' of ' . count($printify_products));
            
            // Check if we've reached the batch limit or time limit
            if (($i > $progress['processed'] && $i % $batch_size === 0) || (time() - $start_time > $time_limit)) {
                error_log('Reached batch or time limit. Saving progress and continuing later.');
                
                // Update progress
                $progress['processed'] = $i;
                $progress['created'] = $created;
                $progress['updated'] = $updated;
                $progress['errors'] = $errors;
                $progress['error_messages'] = $error_messages;
                
                // Save progress for 1 hour
                set_transient($progress_key, $progress, 3600);
                
                // Return partial results
                $output = '<div class="notice notice-info"><p>' . 
                    sprintf(
                        __('Sync in progress: %d of %d products processed. Refresh to continue.', 'printify-surecart-sync'),
                        $i,
                        count($printify_products)
                    ) . 
                    '</p>';
                
                $output .= '<p>' . 
                    sprintf(
                        __('Current results: %d created, %d updated, %d errors.', 'printify-surecart-sync'),
                        $created,
                        $updated,
                        $errors
                    ) . 
                    '</p>';
                
                if ($errors > 0) {
                    $output .= '<p>' . __('Errors so far:', 'printify-surecart-sync') . '</p><ul>';
                    foreach ($error_messages as $message) {
                        $output .= '<li>' . esc_html($message) . '</li>';
                    }
                    $output .= '</ul>';
                }
                
                $output .= '<p><a href="' . admin_url('options-general.php?page=printify-surecart-sync&action=continue_sync') . '" class="button button-primary">' . 
                    __('Continue Sync', 'printify-surecart-sync') . 
                    '</a></p></div>';
                
                return $output;
            }
            
            // Check if product has an ID
            if (!isset($printify_product['id'])) {
                error_log('Product missing ID: ' . json_encode($printify_product));
                $errors++;
                $error_messages[] = 'Product missing ID';
                continue;
            }
            
            error_log('Getting detailed information for product ID: ' . $printify_product['id']);
            
            // Get detailed product information
            $product_details = $this->printify_api->get_product($printify_product['id']);
            
            if (is_wp_error($product_details)) {
                error_log('Error getting product details: ' . $product_details->get_error_message());
                $errors++;
                $error_messages[] = $product_details->get_error_message();
                continue;
            }
            
            error_log('Successfully retrieved product details, processing product');
            
            // Free up memory
            gc_collect_cycles();
            
            // Add more detailed logging
            error_log('About to process product: ' . $product_details['title']);
            error_log('SureCart integration object: ' . (is_object($this->surecart_integration) ? 'Valid object' : 'NOT VALID'));
            
            // Check if SureCart is active
            if (!function_exists('is_plugin_active')) {
                include_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }
            
            $surecart_active = is_plugin_active('surecart/surecart.php');
            error_log('SureCart plugin active (before processing): ' . ($surecart_active ? 'Yes' : 'No'));
            
            // Check if SureCart classes are loaded
            $classes_loaded = class_exists('\SureCart\Models\Product');
            error_log('SureCart classes loaded (before processing): ' . ($classes_loaded ? 'Yes' : 'No'));
            
            // Process the product - pass the force_resync parameter
            try {
                $result = $this->surecart_integration->process_product($product_details, $force_resync);
                
                if (is_wp_error($result)) {
                    error_log('Error processing product: ' . $result->get_error_message());
                    $errors++;
                    $error_messages[] = $result->get_error_message();
                } elseif ($result === 'created') {
                    error_log('Product created successfully');
                    $created++;
                } elseif ($result === 'updated') {
                    error_log('Product updated successfully');
                    $updated++;
                } else {
                    error_log('Unexpected result from process_product: ' . $result);
                    $errors++;
                    $error_messages[] = 'Unexpected result: ' . $result;
                }
            } catch (Exception $e) {
                error_log('Exception during product processing: ' . $e->getMessage());
                error_log('Exception trace: ' . $e->getTraceAsString());
                $errors++;
                $error_messages[] = 'Exception: ' . $e->getMessage();
            }
            
            // Free up memory after processing each product
            unset($product_details);
            gc_collect_cycles();
        }
        
        // Mark sync as completed
        $progress['processed'] = count($printify_products);
        $progress['created'] = $created;
        $progress['updated'] = $updated;
        $progress['errors'] = $errors;
        $progress['error_messages'] = $error_messages;
        $progress['completed'] = true;
        set_transient($progress_key, $progress, 3600);
        
        // Clear progress after 1 hour
        wp_schedule_single_event(time() + 3600, 'printify_surecart_clear_sync_progress');
        
        error_log('Sync completed. Created: ' . $created . ', Updated: ' . $updated . ', Errors: ' . $errors);
        
        // Build output
        $notice_type = ($errors > 0) ? 'notice-warning' : 'notice-success';
        
        $output = '<div class="notice ' . $notice_type . '"><p>' . 
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
            $output .= '</ul>';
            
            // Add troubleshooting information
            $output .= '<div class="printify-surecart-sync-troubleshooting" style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-left: 4px solid #007cba;">';
            $output .= '<h4 style="margin-top: 0;">' . __('Troubleshooting Tips:', 'printify-surecart-sync') . '</h4>';
            $output .= '<ul>';
            $output .= '<li>' . __('Check that your SureCart is properly configured and can create products manually.', 'printify-surecart-sync') . '</li>';
            $output .= '<li>' . __('Verify that the Printify products have all required fields (title, description, variants, etc.).', 'printify-surecart-sync') . '</li>';
            $output .= '<li>' . __('Check your WordPress error log for more detailed information about these errors.', 'printify-surecart-sync') . '</li>';
            $output .= '<li>' . __('Try syncing one product at a time to identify which specific products are causing issues.', 'printify-surecart-sync') . '</li>';
            $output .= '</ul>';
            $output .= '</div></div>';
        }
        
        return $output;
        } catch (Exception $e) {
            error_log('Exception in sync_products: ' . $e->getMessage());
            error_log('Exception trace: ' . $e->getTraceAsString());
            return '<div class="notice notice-error"><p>' . 
                   __('Error during sync process: ', 'printify-surecart-sync') . 
                   esc_html($e->getMessage()) . 
                   '</p></div>';
        }
    }
    
    // Note: clear_sync_progress method is already defined above
    
    /**
     * AJAX handler for dismissing sync notices
     */
    public function ajax_dismiss_sync_notice() {
        try {
            // Check nonce
            check_ajax_referer('printify_dismiss_notice_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'printify-surecart-sync'));
            }
            
            $notice_type = isset($_POST['notice_type']) ? sanitize_text_field($_POST['notice_type']) : '';
            
            if ($notice_type === 'completed') {
                // Delete the completion notice transient
                delete_transient('printify_surecart_sync_completed');
            }
            
            wp_send_json_success(array('message' => 'Notice dismissed'));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}