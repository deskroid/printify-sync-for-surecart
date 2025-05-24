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
     * Test the API connection
     *
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function test_connection() {
        // First, try to get all shops (this should work with any valid token)
        $endpoint = '/shops.json';
        $response = $this->request($endpoint);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // If we got a valid response, check if the specified shop ID exists in the list
        if (is_array($response) && !empty($response)) {
            $shop_id = trim($this->shop_id);
            $shop_found = false;
            
            foreach ($response as $shop) {
                if (isset($shop['id']) && (string)$shop['id'] === (string)$shop_id) {
                    $shop_found = true;
                    break;
                }
            }
            
            if (!$shop_found) {
                return new WP_Error(
                    'printify_shop_not_found',
                    sprintf(__('API connection successful, but shop ID %s was not found in your account. Available shops: %s', 'printify-surecart-sync'), 
                            $shop_id, 
                            implode(', ', array_map(function($shop) { 
                                return $shop['id'] . ' (' . $shop['title'] . ')'; 
                            }, $response))
                    )
                );
            }
        }
        
        return true;
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
        
        // Log the request for debugging
        error_log('Printify API Request: ' . $url);
        error_log('Printify API Method: ' . $method);
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            error_log('Printify API WP Error: ' . $response->get_error_message());
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Log the response for debugging
        error_log('Printify API Response Code: ' . $response_code);
        error_log('Printify API Response Body: ' . $body);
        
        $data = json_decode($body, true);
        
        if ($response_code < 200 || $response_code >= 300) {
            $message = isset($data['message']) ? $data['message'] : __('Unknown error', 'printify-surecart-sync');
            $details = '';
            
            // Add more detailed error information if available
            if (isset($data['errors']) && is_array($data['errors'])) {
                $details = ' Details: ' . json_encode($data['errors']);
            }
            
            $error_message = sprintf(__('Printify API error: %s (Code: %s)%s', 'printify-surecart-sync'), 
                                    $message, 
                                    $response_code,
                                    $details);
            
            error_log('Printify API Error: ' . $error_message);
            
            return new WP_Error(
                'printify_api_error',
                $error_message
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
        // Make sure shop_id is properly formatted (trim any whitespace)
        $shop_id = trim($this->shop_id);
        
        // Log shop ID for debugging
        error_log('Printify Shop ID: ' . $shop_id);
        
        // Try a different endpoint format - some Printify API versions use this format
        $endpoint = '/shops/' . $shop_id . '/products.json';
        error_log('Trying first Printify API Products Endpoint: ' . $endpoint);
        
        $response = $this->request($endpoint);
        
        // If first attempt fails with 404, try alternative endpoint format
        if (is_wp_error($response) && strpos($response->get_error_message(), '404') !== false) {
            error_log('First endpoint attempt failed with 404, trying alternative endpoint');
            $endpoint = '/shops/' . $shop_id . '/products';
            error_log('Trying alternative Printify API Products Endpoint: ' . $endpoint);
            $response = $this->request($endpoint);
        }
        
        if (is_wp_error($response)) {
            error_log('Both endpoint attempts failed');
            return $response;
        }
        
        // Log the response structure for debugging
        if (is_array($response)) {
            error_log('Printify API Products Response Structure: ' . json_encode(array_keys($response)));
            
            // Log the first product if available
            if (!empty($response) && isset($response[0])) {
                error_log('First product sample: ' . json_encode(array_keys($response[0])));
            }
        } else {
            error_log('Printify API Products Response is not an array: ' . gettype($response));
        }
        
        // Check if we have the expected data structure
        if (!isset($response['data']) && is_array($response)) {
            error_log('Printify API: Unexpected response structure for products');
            
            // Try to handle different response formats
            if (isset($response['products'])) {
                error_log('Found products key in response');
                return $response['products'];
            }
            
            // If the response itself is an array of products
            if (isset($response[0]) && isset($response[0]['id'])) {
                error_log('Response appears to be an array of products');
                return $response;
            }
        } else if (isset($response['data'])) {
            error_log('Found data key in response with ' . count($response['data']) . ' products');
        }
        
        return isset($response['data']) ? $response['data'] : (is_array($response) ? $response : array());
    }

    /**
     * Get all products with pagination handling
     *
     * @return array|WP_Error All products or WP_Error on failure
     */
    public function get_all_products() {
        error_log('Starting get_all_products method');
        $page = 1;
        $limit = 100;
        $all_products = array();
        
        while (true) {
            error_log('Fetching products page ' . $page);
            $products = $this->get_products($page, $limit);
            
            if (is_wp_error($products)) {
                error_log('Error fetching products: ' . $products->get_error_message());
                return $products;
            }
            
            error_log('Products fetched: ' . (is_array($products) ? count($products) : 'not an array'));
            
            if (empty($products)) {
                error_log('No products found on page ' . $page);
                break;
            }
            
            $all_products = array_merge($all_products, $products);
            error_log('Total products collected so far: ' . count($all_products));
            
            if (count($products) < $limit) {
                error_log('Reached last page of products');
                break;
            }
            
            $page++;
        }
        
        error_log('Finished get_all_products method. Total products: ' . count($all_products));
        return $all_products;
    }

    /**
     * Get a single product by ID
     *
     * @param string $product_id Product ID
     * @return array|WP_Error Product data or WP_Error on failure
     */
    public function get_product($product_id) {
        $shop_id = trim($this->shop_id);
        
        // Try first endpoint format
        $endpoint = '/shops/' . $shop_id . '/products/' . $product_id . '.json';
        error_log('Trying first Printify API Product Endpoint: ' . $endpoint);
        
        $response = $this->request($endpoint);
        
        // If first attempt fails with 404, try alternative endpoint format
        if (is_wp_error($response) && strpos($response->get_error_message(), '404') !== false) {
            error_log('First product endpoint attempt failed with 404, trying alternative endpoint');
            $endpoint = '/shops/' . $shop_id . '/products/' . $product_id;
            error_log('Trying alternative Printify API Product Endpoint: ' . $endpoint);
            $response = $this->request($endpoint);
        }
        
        if (is_wp_error($response)) {
            error_log('Both product endpoint attempts failed for product ID: ' . $product_id);
            return $response;
        } 
        
        error_log('Successfully retrieved product details for ID: ' . $product_id);
        
        // Log the product structure
        if (is_array($response)) {
            error_log('Product response keys: ' . json_encode(array_keys($response)));
            
            // Check for images and log them
            if (isset($response['images'])) {
                error_log('Product has ' . count($response['images']) . ' images');
                
                // Log the first image structure
                if (!empty($response['images']) && isset($response['images'][0])) {
                    error_log('First image data: ' . json_encode($response['images'][0]));
                }
            } else {
                error_log('No images array found in product data');
                
                // Try to find images in other fields
                if (isset($response['image'])) {
                    error_log('Found image field: ' . json_encode($response['image']));
                    // Add it to the images array for consistency
                    $response['images'] = array($response['image']);
                } else if (isset($response['preview_image'])) {
                    error_log('Found preview_image field: ' . json_encode($response['preview_image']));
                    // Add it to the images array for consistency
                    $response['images'] = array($response['preview_image']);
                }
            }
            
            // If we still don't have images, try to get them from the publish API
            if (empty($response['images'])) {
                error_log('Attempting to get images from publish API');
                $publish_endpoint = '/shops/' . $shop_id . '/products/' . $product_id . '/publish.json';
                $publish_data = $this->request($publish_endpoint);
                
                if (!is_wp_error($publish_data) && isset($publish_data['images']) && !empty($publish_data['images'])) {
                    error_log('Found ' . count($publish_data['images']) . ' images in publish data');
                    $response['images'] = $publish_data['images'];
                }
            }
            
            // Try to get images from the preview API as a last resort
            if (empty($response['images'])) {
                error_log('Attempting to get images from preview API');
                $preview_endpoint = '/shops/' . $shop_id . '/products/' . $product_id . '/preview.json';
                $preview_data = $this->request($preview_endpoint);
                
                if (!is_wp_error($preview_data) && isset($preview_data['preview_url']) && !empty($preview_data['preview_url'])) {
                    error_log('Found preview_url in preview data: ' . $preview_data['preview_url']);
                    $response['images'] = array($preview_data['preview_url']);
                }
            }
            
            // Log the final image data
            if (!empty($response['images'])) {
                error_log('Final image data for product: ' . json_encode($response['images']));
            } else {
                error_log('No images found for product after all attempts');
            }
        }
        
        return $response;
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
            error_log('Error getting product for images: ' . $product->get_error_message());
            return $product;
        }
        
        if (isset($product['images'])) {
            error_log('Found ' . count($product['images']) . ' images for product ID: ' . $product_id);
            
            // Log the structure of the first image
            if (!empty($product['images']) && isset($product['images'][0])) {
                error_log('First image structure: ' . json_encode($product['images'][0]));
            }
            
            return $product['images'];
        } else {
            error_log('No images found for product ID: ' . $product_id);
            
            // Check if there's an image_url field instead
            if (isset($product['image_url']) && !empty($product['image_url'])) {
                error_log('Found image_url field: ' . $product['image_url']);
                return array($product['image_url']);
            }
            
            // Check if there's a preview_url field
            if (isset($product['preview_url']) && !empty($product['preview_url'])) {
                error_log('Found preview_url field: ' . $product['preview_url']);
                return array($product['preview_url']);
            }
            
            return array();
        }
    }

    /**
     * Get product print providers
     *
     * @return array|WP_Error Print providers or WP_Error on failure
     */
    public function get_print_providers() {
        $endpoint = '/catalog/print_providers';
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
        $endpoint = '/catalog/blueprints';
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
        $endpoint = '/catalog/blueprints/' . $blueprint_id;
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
        $endpoint = '/catalog/blueprints/' . $blueprint_id . '/print_providers/' . $print_provider_id . '/shipping';
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
        $shop_id = trim($shop_id);
        $endpoint = '/shops/' . $shop_id . '/orders';
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
        $shop_id = trim($shop_id);
        $endpoint = '/shops/' . $shop_id . '/orders/' . $order_id;
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
        $shop_id = trim($shop_id);
        $endpoint = '/shops/' . $shop_id . '/orders/' . $order_id;
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
        $shop_id = trim($shop_id);
        $endpoint = '/shops/' . $shop_id . '/orders/' . $order_id . '/cancel';
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
        $shop_id = trim($shop_id);
        $endpoint = '/shops/' . $shop_id . '/orders?page=' . $page . '&limit=' . $limit;
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
        $shop_id = trim($shop_id);
        $endpoint = '/shops/' . $shop_id . '/orders/' . $order_id . '/shipping';
        return $this->request($endpoint);
    }
}