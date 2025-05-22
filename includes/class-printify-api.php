<?php
/**
 * Printify API Class
 *
 * @package Printify_SureCart_Sync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Printify API Class
 */
class Printify_SureCart_Sync_API {
    /**
     * Printify API token
     *
     * @var string
     */
    private $api_token;

    /**
     * Printify shop ID
     *
     * @var string
     */
    private $shop_id;

    /**
     * API base URL
     *
     * @var string
     */
    private $api_base_url = 'https://api.printify.com/v1';

    /**
     * Constructor
     *
     * @param string $api_token Printify API token
     * @param string $shop_id Printify shop ID
     */
    public function __construct($api_token, $shop_id) {
        $this->api_token = $api_token;
        $this->shop_id = $shop_id;
    }

    /**
     * Make a request to the Printify API
     *
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param array $data Request data
     * @return array|WP_Error Response data or WP_Error on failure
     */
    public function request($endpoint, $method = 'GET', $data = array()) {
        $url = $this->api_base_url . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
            ),
            'timeout' => 30
        );
        
        if (!empty($data) && in_array($method, array('POST', 'PUT'))) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($response_code < 200 || $response_code >= 300) {
            $message = isset($data['message']) ? $data['message'] : __('Unknown error', 'printify-surecart-sync');
            
            return new WP_Error(
                'printify_api_error',
                sprintf(__('Printify API error: %s (Code: %s)', 'printify-surecart-sync'), $message, $response_code)
            );
        }
        
        return $data;
    }

    /**
     * Get all products from the shop
     *
     * @param int $page Page number
     * @param int $limit Items per page
     * @return array|WP_Error Products or WP_Error on failure
     */
    public function get_products($page = 1, $limit = 100) {
        $endpoint = '/shops/' . $this->shop_id . '/products.json?page=' . $page . '&limit=' . $limit;
        $response = $this->request($endpoint);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return isset($response['data']) ? $response['data'] : array();
    }

    /**
     * Get all products with pagination handling
     *
     * @return array|WP_Error All products or WP_Error on failure
     */
    public function get_all_products() {
        $page = 1;
        $limit = 100;
        $all_products = array();
        
        while (true) {
            $products = $this->get_products($page, $limit);
            
            if (is_wp_error($products)) {
                return $products;
            }
            
            if (empty($products)) {
                break;
            }
            
            $all_products = array_merge($all_products, $products);
            
            if (count($products) < $limit) {
                break;
            }
            
            $page++;
        }
        
        return $all_products;
    }

    /**
     * Get a single product by ID
     *
     * @param string $product_id Product ID
     * @return array|WP_Error Product data or WP_Error on failure
     */
    public function get_product($product_id) {
        $endpoint = '/shops/' . $this->shop_id . '/products/' . $product_id . '.json';
        return $this->request($endpoint);
    }

    /**
     * Get product variants
     *
     * @param string $product_id Product ID
     * @return array|WP_Error Variants or WP_Error on failure
     */
    public function get_product_variants($product_id) {
        $product = $this->get_product($product_id);
        
        if (is_wp_error($product)) {
            return $product;
        }
        
        return isset($product['variants']) ? $product['variants'] : array();
    }

    /**
     * Get product images
     *
     * @param string $product_id Product ID
     * @return array|WP_Error Images or WP_Error on failure
     */
    public function get_product_images($product_id) {
        $product = $this->get_product($product_id);
        
        if (is_wp_error($product)) {
            return $product;
        }
        
        return isset($product['images']) ? $product['images'] : array();
    }

    /**
     * Get product print providers
     *
     * @return array|WP_Error Print providers or WP_Error on failure
     */
    public function get_print_providers() {
        $endpoint = '/catalog/print_providers.json';
        $response = $this->request($endpoint);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return $response;
    }

    /**
     * Get blueprints (product templates)
     *
     * @return array|WP_Error Blueprints or WP_Error on failure
     */
    public function get_blueprints() {
        $endpoint = '/catalog/blueprints.json';
        $response = $this->request($endpoint);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return $response;
    }

    /**
     * Get blueprint by ID
     *
     * @param int $blueprint_id Blueprint ID
     * @return array|WP_Error Blueprint data or WP_Error on failure
     */
    public function get_blueprint($blueprint_id) {
        $endpoint = '/catalog/blueprints/' . $blueprint_id . '.json';
        return $this->request($endpoint);
    }

    /**
     * Get shipping information for a blueprint and print provider
     *
     * @param int $blueprint_id Blueprint ID
     * @param int $print_provider_id Print provider ID
     * @return array|WP_Error Shipping data or WP_Error on failure
     */
    public function get_shipping_info($blueprint_id, $print_provider_id) {
        $endpoint = '/catalog/blueprints/' . $blueprint_id . '/print_providers/' . $print_provider_id . '/shipping.json';
        return $this->request($endpoint);
    }

    /**
     * Create an order in Printify
     *
     * @param string $shop_id Shop ID
     * @param array $order_data Order data
     * @return array|WP_Error Order data or WP_Error on failure
     */
    public function create_order($shop_id, $order_data) {
        $endpoint = '/shops/' . $shop_id . '/orders.json';
        return $this->request($endpoint, 'POST', $order_data);
    }

    /**
     * Get an order from Printify
     *
     * @param string $shop_id Shop ID
     * @param string $order_id Order ID
     * @return array|WP_Error Order data or WP_Error on failure
     */
    public function get_order($shop_id, $order_id) {
        $endpoint = '/shops/' . $shop_id . '/orders/' . $order_id . '.json';
        return $this->request($endpoint);
    }

    /**
     * Update order status in Printify
     *
     * @param string $shop_id Shop ID
     * @param string $order_id Order ID
     * @param string $status New status
     * @return array|WP_Error Response data or WP_Error on failure
     */
    public function update_order_status($shop_id, $order_id, $status) {
        $endpoint = '/shops/' . $shop_id . '/orders/' . $order_id . '.json';
        return $this->request($endpoint, 'PUT', array('status' => $status));
    }

    /**
     * Cancel an order in Printify
     *
     * @param string $shop_id Shop ID
     * @param string $order_id Order ID
     * @return array|WP_Error Response data or WP_Error on failure
     */
    public function cancel_order($shop_id, $order_id) {
        $endpoint = '/shops/' . $shop_id . '/orders/' . $order_id . '/cancel.json';
        return $this->request($endpoint, 'POST');
    }

    /**
     * Get all orders from Printify
     *
     * @param string $shop_id Shop ID
     * @param int $page Page number
     * @param int $limit Items per page
     * @return array|WP_Error Orders or WP_Error on failure
     */
    public function get_orders($shop_id, $page = 1, $limit = 20) {
        $endpoint = '/shops/' . $shop_id . '/orders.json?page=' . $page . '&limit=' . $limit;
        return $this->request($endpoint);
    }

    /**
     * Get order shipping information
     *
     * @param string $shop_id Shop ID
     * @param string $order_id Order ID
     * @return array|WP_Error Shipping data or WP_Error on failure
     */
    public function get_order_shipping($shop_id, $order_id) {
        $endpoint = '/shops/' . $shop_id . '/orders/' . $order_id . '/shipping.json';
        return $this->request($endpoint);
    }
}