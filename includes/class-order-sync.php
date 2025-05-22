<?php
/**
 * Order Sync Class
 *
 * @package Printify_SureCart_Sync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Order Sync Class
 */
class Printify_SureCart_Sync_Order_Sync {
    /**
     * Printify API instance
     *
     * @var Printify_SureCart_Sync_API
     */
    private $printify_api;

    /**
     * Constructor
     *
     * @param Printify_SureCart_Sync_API $printify_api Printify API instance
     */
    public function __construct($printify_api) {
        $this->printify_api = $printify_api;
        
        // Add hooks for order creation and updates
        add_action('surecart/order_created', array($this, 'handle_order_created'), 10, 2);
        add_action('surecart/order_updated', array($this, 'handle_order_updated'), 10, 2);
        
        // Add webhook handler for SureCart order status changes
        add_action('rest_api_init', array($this, 'register_webhook_endpoints'));
    }

    /**
     * Register webhook endpoints
     */
    public function register_webhook_endpoints() {
        register_rest_route('printify-surecart-sync/v1', '/webhook/order', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Handle webhook requests
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function handle_webhook($request) {
        $body = $request->get_body();
        $data = json_decode($body, true);
        
        if (empty($data) || !isset($data['type']) || !isset($data['data'])) {
            return new WP_REST_Response(array('status' => 'error', 'message' => 'Invalid webhook data'), 400);
        }
        
        // Process based on event type
        switch ($data['type']) {
            case 'order.status_updated':
                $this->process_order_status_update($data['data']);
                break;
            
            case 'order.payment_succeeded':
                $this->process_order_payment($data['data']);
                break;
        }
        
        return new WP_REST_Response(array('status' => 'success'), 200);
    }

    /**
     * Handle order created event
     *
     * @param string $order_id Order ID
     * @param object $order Order object
     */
    public function handle_order_created($order_id, $order) {
        // Only process paid orders
        if ($order->status !== 'paid' && $order->status !== 'processing') {
            return;
        }
        
        $this->sync_order_to_printify($order);
    }

    /**
     * Handle order updated event
     *
     * @param string $order_id Order ID
     * @param object $order Order object
     */
    public function handle_order_updated($order_id, $order) {
        // Check if order status is relevant for syncing
        if (!in_array($order->status, array('paid', 'processing', 'completed', 'refunded', 'canceled'))) {
            return;
        }
        
        // Check if order has already been synced to Printify
        $printify_order_id = get_post_meta($order_id, '_printify_order_id', true);
        
        if ($printify_order_id) {
            // Update existing Printify order
            $this->update_printify_order($printify_order_id, $order);
        } else {
            // Create new Printify order
            $this->sync_order_to_printify($order);
        }
    }

    /**
     * Process order status update
     *
     * @param array $data Order data
     */
    private function process_order_status_update($data) {
        if (!isset($data['order']['id'])) {
            return;
        }
        
        $order_id = $data['order']['id'];
        $order = \SureCart\Models\Order::find($order_id);
        
        if (!$order) {
            return;
        }
        
        $this->handle_order_updated($order_id, $order);
    }

    /**
     * Process order payment
     *
     * @param array $data Order data
     */
    private function process_order_payment($data) {
        if (!isset($data['order']['id'])) {
            return;
        }
        
        $order_id = $data['order']['id'];
        $order = \SureCart\Models\Order::find($order_id);
        
        if (!$order) {
            return;
        }
        
        $this->handle_order_created($order_id, $order);
    }

    /**
     * Sync SureCart order to Printify
     *
     * @param object $order SureCart order
     * @return string|WP_Error Printify order ID or WP_Error on failure
     */
    public function sync_order_to_printify($order) {
        try {
            // Get order line items
            $line_items = $this->get_order_line_items($order);
            
            // Skip if no Printify products in order
            if (empty($line_items)) {
                return new WP_Error('no_printify_products', __('No Printify products found in order', 'printify-surecart-sync'));
            }
            
            // Get customer information
            $customer = $this->get_order_customer($order);
            
            // Get shipping address
            $shipping_address = $this->get_order_shipping_address($order);
            
            // Prepare order data for Printify
            $printify_order_data = array(
                'external_id' => $order->id,
                'label' => 'SureCart Order #' . $order->number,
                'line_items' => $line_items,
                'shipping_method' => 1, // Standard shipping
                'shipping_address' => $shipping_address,
                'send_shipping_notification' => true,
            );
            
            // Add customer info if available
            if ($customer) {
                $printify_order_data['address_to'] = $customer['name'];
                $printify_order_data['email'] = $customer['email'];
            }
            
            // Create order in Printify
            $shop_id = get_option('printify_surecart_sync_shop_id');
            $response = $this->printify_api->create_order($shop_id, $printify_order_data);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            // Store Printify order ID in SureCart order metadata
            if (isset($response['id'])) {
                update_post_meta($order->id, '_printify_order_id', $response['id']);
                
                // Add note to order
                $this->add_order_note($order->id, sprintf(
                    __('Order synced to Printify (ID: %s)', 'printify-surecart-sync'),
                    $response['id']
                ));
                
                return $response['id'];
            }
            
            return new WP_Error('printify_order_error', __('Failed to create order in Printify', 'printify-surecart-sync'));
        } catch (Exception $e) {
            return new WP_Error('order_sync_error', $e->getMessage());
        }
    }

    /**
     * Update Printify order
     *
     * @param string $printify_order_id Printify order ID
     * @param object $order SureCart order
     * @return bool|WP_Error True on success or WP_Error on failure
     */
    public function update_printify_order($printify_order_id, $order) {
        try {
            // Map SureCart status to Printify status
            $printify_status = $this->map_order_status($order->status);
            
            if (!$printify_status) {
                return true; // No update needed
            }
            
            // Update order status in Printify
            $shop_id = get_option('printify_surecart_sync_shop_id');
            $response = $this->printify_api->update_order_status($shop_id, $printify_order_id, $printify_status);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            // Add note to order
            $this->add_order_note($order->id, sprintf(
                __('Order status updated in Printify to: %s', 'printify-surecart-sync'),
                $printify_status
            ));
            
            return true;
        } catch (Exception $e) {
            return new WP_Error('order_update_error', $e->getMessage());
        }
    }

    /**
     * Get order line items for Printify
     *
     * @param object $order SureCart order
     * @return array Line items for Printify
     */
    private function get_order_line_items($order) {
        $line_items = array();
        
        if (empty($order->line_items) || empty($order->line_items->data)) {
            return $line_items;
        }
        
        foreach ($order->line_items->data as $item) {
            // Skip if no product or price
            if (empty($item->price) || empty($item->price->product)) {
                continue;
            }
            
            $product = $item->price->product;
            
            // Check if this is a Printify product
            if (empty($product->metadata) || empty($product->metadata->printify_id)) {
                continue;
            }
            
            // Get variant ID if available
            $variant_id = null;
            
            if (!empty($item->variant)) {
                $variant = $item->variant;
                if (!empty($variant->metadata) && !empty($variant->metadata->printify_variant_id)) {
                    $variant_id = $variant->metadata->printify_variant_id;
                }
            }
            
            // If no specific variant, try to get the first variant
            if (!$variant_id && !empty($product->metadata->printify_id)) {
                // Get product details from Printify to find variants
                $printify_product = $this->printify_api->get_product($product->metadata->printify_id);
                
                if (!is_wp_error($printify_product) && !empty($printify_product['variants'])) {
                    $variant_id = $printify_product['variants'][0]['id'];
                }
            }
            
            // Skip if no variant ID found
            if (!$variant_id) {
                continue;
            }
            
            // Add line item
            $line_items[] = array(
                'product_id' => $product->metadata->printify_id,
                'variant_id' => $variant_id,
                'quantity' => $item->quantity,
            );
        }
        
        return $line_items;
    }

    /**
     * Get customer information from order
     *
     * @param object $order SureCart order
     * @return array|null Customer information or null if not available
     */
    private function get_order_customer($order) {
        if (empty($order->customer)) {
            return null;
        }
        
        $customer = $order->customer;
        $name = '';
        
        if (!empty($customer->first_name) || !empty($customer->last_name)) {
            $name = trim($customer->first_name . ' ' . $customer->last_name);
        }
        
        return array(
            'name' => $name,
            'email' => $customer->email ?? '',
        );
    }

    /**
     * Get shipping address from order
     *
     * @param object $order SureCart order
     * @return array Shipping address
     */
    private function get_order_shipping_address($order) {
        $address = array(
            'first_name' => '',
            'last_name' => '',
            'address1' => '',
            'address2' => '',
            'city' => '',
            'state' => '',
            'zip' => '',
            'country' => 'US', // Default to US
            'phone' => '',
        );
        
        // Try to get shipping address
        if (!empty($order->shipping_address)) {
            $shipping = $order->shipping_address;
            
            $address['first_name'] = $shipping->first_name ?? '';
            $address['last_name'] = $shipping->last_name ?? '';
            $address['address1'] = $shipping->line_1 ?? '';
            $address['address2'] = $shipping->line_2 ?? '';
            $address['city'] = $shipping->city ?? '';
            $address['state'] = $shipping->state ?? '';
            $address['zip'] = $shipping->postal_code ?? '';
            $address['country'] = $shipping->country ?? 'US';
            $address['phone'] = $shipping->phone ?? '';
        }
        // If no shipping address, try billing address
        else if (!empty($order->billing_address)) {
            $billing = $order->billing_address;
            
            $address['first_name'] = $billing->first_name ?? '';
            $address['last_name'] = $billing->last_name ?? '';
            $address['address1'] = $billing->line_1 ?? '';
            $address['address2'] = $billing->line_2 ?? '';
            $address['city'] = $billing->city ?? '';
            $address['state'] = $billing->state ?? '';
            $address['zip'] = $billing->postal_code ?? '';
            $address['country'] = $billing->country ?? 'US';
            $address['phone'] = $billing->phone ?? '';
        }
        // If no addresses, try customer info
        else if (!empty($order->customer)) {
            $customer = $order->customer;
            
            $address['first_name'] = $customer->first_name ?? '';
            $address['last_name'] = $customer->last_name ?? '';
            $address['phone'] = $customer->phone ?? '';
        }
        
        return $address;
    }

    /**
     * Map SureCart order status to Printify status
     *
     * @param string $surecart_status SureCart order status
     * @return string|null Printify status or null if no mapping
     */
    private function map_order_status($surecart_status) {
        $status_map = array(
            'paid' => 'pending',
            'processing' => 'pending',
            'completed' => 'completed',
            'refunded' => 'canceled',
            'canceled' => 'canceled',
        );
        
        return isset($status_map[$surecart_status]) ? $status_map[$surecart_status] : null;
    }

    /**
     * Add note to order
     *
     * @param string $order_id Order ID
     * @param string $note Note text
     */
    private function add_order_note($order_id, $note) {
        // Check if SureCart has a method for adding order notes
        if (method_exists('\SureCart\Models\Order', 'add_note')) {
            \SureCart\Models\Order::add_note($order_id, $note);
        }
        
        // Alternatively, store in post meta
        $notes = get_post_meta($order_id, '_printify_sync_notes', true);
        
        if (!is_array($notes)) {
            $notes = array();
        }
        
        $notes[] = array(
            'date' => current_time('mysql'),
            'note' => $note,
        );
        
        update_post_meta($order_id, '_printify_sync_notes', $notes);
    }
}