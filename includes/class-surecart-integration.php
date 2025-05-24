<?php
/**
 * SureCart Integration Class
 *
 * @package Printify_SureCart_Sync
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * SureCart Integration Class
 */
class Printify_SureCart_Sync_Integration {
    /**
     * Constructor
     */
    public function __construct() {
        // Check if SureCart is active
        if (!$this->is_surecart_active()) {
            error_log('SureCart plugin is not active or not properly loaded');
        } else {
            error_log('SureCart plugin is active and loaded');
            
            // Check SureCart version
            if (defined('SURECART_VERSION')) {
                error_log('SureCart version: ' . SURECART_VERSION);
            } else {
                error_log('SureCart version constant not defined');
                
                // Try to get version from plugin data
                if (!function_exists('get_plugin_data')) {
                    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
                }
                
                if (function_exists('get_plugin_data')) {
                    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/surecart/surecart.php');
                    if (isset($plugin_data['Version'])) {
                        error_log('SureCart version from plugin data: ' . $plugin_data['Version']);
                    }
                }
            }
        }
    }
    
    /**
     * Check if SureCart is active and properly loaded
     *
     * @return bool True if SureCart is active and loaded
     */
    public function is_surecart_active() {
        // Check if the SureCart plugin is active
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        $plugin_active = is_plugin_active('surecart/surecart.php');
        error_log('SureCart plugin active: ' . ($plugin_active ? 'Yes' : 'No'));
        
        // Check if the SureCart classes are loaded
        $classes_loaded = class_exists('\SureCart\Models\Product');
        error_log('SureCart classes loaded: ' . ($classes_loaded ? 'Yes' : 'No'));
        
        return $plugin_active && $classes_loaded;
    }
    
    /**
     * Add media (image) to a SureCart product
     *
     * @param string $product_id SureCart product ID
     * @param string $image_url URL of the image to add
     * @return bool True if successful, false otherwise
     */
    public function add_product_media($product_id, $image_url) {
        error_log('Adding product media (image) to product ID: ' . $product_id);
        
        if (empty($product_id) || empty($image_url)) {
            error_log('Product ID or image URL is empty');
            return false;
        }
        
        // Check if we can use the ProductMedia model
        if (class_exists('\SureCart\Models\ProductMedia')) {
            error_log('Found ProductMedia class with namespace: \SureCart\Models\ProductMedia');
            
            $media_data = [
                'product_id' => $product_id,
                'url' => $image_url
            ];
            
            error_log('Creating product media with data: ' . json_encode($media_data));
            $media = \SureCart\Models\ProductMedia::create($media_data);
            
            if (!$media || (isset($media->errors) && !empty($media->errors))) {
                error_log('Error creating product media: ' . json_encode($media));
                return false;
            } else {
                error_log('Product media created successfully with ID: ' . ($media->id ?? 'unknown'));
                return true;
            }
        } elseif (class_exists('SureCart\Models\ProductMedia')) {
            error_log('Found ProductMedia class with namespace: SureCart\Models\ProductMedia');
            
            $media_data = [
                'product_id' => $product_id,
                'url' => $image_url
            ];
            
            error_log('Creating product media with data: ' . json_encode($media_data));
            $media = SureCart\Models\ProductMedia::create($media_data);
            
            if (!$media || (isset($media->errors) && !empty($media->errors))) {
                error_log('Error creating product media: ' . json_encode($media));
                return false;
            } else {
                error_log('Product media created successfully with ID: ' . ($media->id ?? 'unknown'));
                return true;
            }
        } elseif (class_exists('\SureCart\Controllers\Admin\Products\MediaController')) {
            error_log('Trying MediaController');
            try {
                $media_controller = new \SureCart\Controllers\Admin\Products\MediaController();
                $methods = get_class_methods($media_controller);
                error_log('Available methods in MediaController: ' . json_encode($methods));
                
                if (in_array('store', $methods)) {
                    error_log('Using MediaController->store() method');
                    $media = $media_controller->store([
                        'body' => [
                            'product_id' => $product_id,
                            'url' => $image_url
                        ]
                    ]);
                    
                    if (!$media || (isset($media->errors) && !empty($media->errors))) {
                        error_log('Error creating product media: ' . json_encode($media));
                        return false;
                    } else {
                        error_log('Product media created successfully with ID: ' . ($media->id ?? 'unknown'));
                        return true;
                    }
                } else {
                    error_log('No suitable method found in MediaController');
                }
            } catch (Exception $media_e) {
                error_log('MediaController error: ' . $media_e->getMessage());
            }
        }
        
        // If we get here, try direct API call
        error_log('No ProductMedia class or MediaController found, trying direct API call');
        
        // Try direct API call to create media
        $api_url = '';
        
        if (defined('SURECART_APP_URL')) {
            $api_url = trailingslashit(SURECART_APP_URL) . 'products/' . $product_id . '/media';
        } elseif (function_exists('surecart_get_app_url')) {
            $app_url = surecart_get_app_url();
            if (!empty($app_url)) {
                $api_url = trailingslashit($app_url) . 'products/' . $product_id . '/media';
            }
        } else {
            $api_url = 'https://api.surecart.com/v1/products/' . $product_id . '/media';
        }
        
        error_log('Using API URL for media: ' . $api_url);
        
        $media_data = [
            'url' => $image_url
        ];
        
        $api_key = get_option('surecart_api_key', '');
        
        // If API key is not set, try to get it from SureCart
        if (empty($api_key)) {
            // Try multiple methods to get the API key
            
            // Method 1: Using ApiToken model
            if (class_exists('\SureCart\Models\ApiToken')) {
                try {
                    $token = \SureCart\Models\ApiToken::get();
                    if ($token && isset($token->key)) {
                        $api_key = $token->key;
                        error_log('Retrieved API key from SureCart ApiToken: ' . substr($api_key, 0, 5) . '...');
                    }
                } catch (Exception $token_e) {
                    error_log('Error getting API token: ' . $token_e->getMessage());
                }
            }
            
            // Method 2: Using the processor directly
            if (empty($api_key) && class_exists('\SureCart\Models\Processor')) {
                try {
                    $processor = \SureCart\Models\Processor::get();
                    if ($processor && isset($processor->live_secret_key)) {
                        $api_key = $processor->live_secret_key;
                        error_log('Retrieved API key from SureCart Processor: ' . substr($api_key, 0, 5) . '...');
                    }
                } catch (Exception $processor_e) {
                    error_log('Error getting processor: ' . $processor_e->getMessage());
                }
            }
            
            // Method 3: Using the settings
            if (empty($api_key) && function_exists('surecart_get_app_settings')) {
                try {
                    $settings = surecart_get_app_settings();
                    if ($settings && isset($settings['api_key'])) {
                        $api_key = $settings['api_key'];
                        error_log('Retrieved API key from SureCart settings: ' . substr($api_key, 0, 5) . '...');
                    }
                } catch (Exception $settings_e) {
                    error_log('Error getting settings: ' . $settings_e->getMessage());
                }
            }
        }
        
        if (empty($api_key)) {
            error_log('Could not find API key for SureCart');
            return false;
        }
        
        $response = wp_remote_post($api_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode($media_data),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('Error creating product media via API: ' . $response->get_error_message());
            return false;
        } else {
            $body = wp_remote_retrieve_body($response);
            $status = wp_remote_retrieve_response_code($response);
            
            if ($status >= 200 && $status < 300) {
                error_log('Product media created successfully via API');
                return true;
            } else {
                error_log('Error creating product media via API. Status: ' . $status . ', Response: ' . $body);
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * Check if a Printify variant is active/selected
     *
     * @param array $variant Printify variant data
     * @return bool True if the variant is active, false otherwise
     */
    private function is_variant_active($variant) {
        // Default to true if not specified
        $is_active = true;
        
        // Check different possible fields that might indicate if a variant is active
        if (isset($variant['is_active']) && $variant['is_active'] === false) {
            $is_active = false;
        } elseif (isset($variant['active']) && $variant['active'] === false) {
            $is_active = false;
        } elseif (isset($variant['is_enabled']) && $variant['is_enabled'] === false) {
            $is_active = false;
        } elseif (isset($variant['enabled']) && $variant['enabled'] === false) {
            $is_active = false;
        } elseif (isset($variant['is_selected']) && $variant['is_selected'] === false) {
            $is_active = false;
        } elseif (isset($variant['selected']) && $variant['selected'] === false) {
            $is_active = false;
        } elseif (isset($variant['is_available']) && $variant['is_available'] === false) {
            $is_active = false;
        } elseif (isset($variant['available']) && $variant['available'] === false) {
            $is_active = false;
        }
        
        return $is_active;
    }
    
    /**
     * Find a SureCart product by Printify ID
     *
     * @param string $printify_id Printify product ID
     * @return object|null SureCart product or null if not found
     */
    public function find_product_by_printify_id($printify_id) {
        // Check if SureCart is active
        if (!$this->is_surecart_active()) {
            error_log('ERROR: Cannot find product - SureCart is not active');
            return null;
        }
        
        try {
            // Query SureCart products with metadata filter
            $products = \SureCart\Models\Product::where([
                'metadata' => [
                    'printify_id' => $printify_id
                ]
            ])->get();
            
            return !empty($products->data) ? $products->data[0] : null;
        } catch (Exception $e) {
            error_log('Exception finding product by Printify ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new SureCart product
     *
     * @param array $product_data Product data
     * @return object|WP_Error SureCart product or WP_Error on failure
     */
    public function create_product($product_data) {
        try {
            error_log('Creating SureCart product with data structure: ' . json_encode(array_keys($product_data)));
            
            // Debug: Check if SureCart is properly loaded
            $surecart_class_exists = false;
            $surecart_namespace = '';
            
            // Try different possible namespaces for SureCart
            if (class_exists('\SureCart\Models\Product')) {
                error_log('Found SureCart Product class with namespace: \SureCart\Models\Product');
                $surecart_class_exists = true;
                $surecart_namespace = '\SureCart\Models\Product';
            } elseif (class_exists('SureCart\Models\Product')) {
                error_log('Found SureCart Product class with namespace: SureCart\Models\Product');
                $surecart_class_exists = true;
                $surecart_namespace = 'SureCart\Models\Product';
            } elseif (class_exists('SureCart_Models_Product')) {
                error_log('Found SureCart Product class with namespace: SureCart_Models_Product');
                $surecart_class_exists = true;
                $surecart_namespace = 'SureCart_Models_Product';
            } else {
                error_log('ERROR: SureCart\Models\Product class does not exist in create_product!');
                
                // Check if any SureCart classes exist
                if (class_exists('\SureCart\Plugin')) {
                    error_log('SureCart Plugin class exists, but Models\Product does not');
                }
                
                return new WP_Error('surecart_not_loaded', 'SureCart Product model not found');
            }
            
            // Debug: Check if we can access SureCart methods
            try {
                // Use eval to dynamically call the class with the correct namespace
                $code = '$test = ' . $surecart_namespace . '::where(["limit" => 1])->get();';
                error_log('Executing code: ' . $code);
                eval($code);
                error_log('SureCart test query successful: ' . (is_object($test) ? 'Yes' : 'No'));
                
                // Also try to get the SureCart version
                if (defined('SURECART_VERSION')) {
                    error_log('SureCart version: ' . SURECART_VERSION);
                } else {
                    error_log('SureCart version constant not defined');
                }
            } catch (Exception $e) {
                error_log('SureCart test query failed: ' . $e->getMessage());
            }
            
            // Try to create a simple test product first to see if basic product creation works
            try {
                error_log('Attempting to create a simple test product to verify SureCart API access');
                $test_product_data = [
                    'name' => 'Test Product ' . time(),
                    'description' => 'This is a test product to verify SureCart API access'
                ];
                // Use eval to dynamically call the class with the correct namespace
                $create_code = '$test_product = ' . $surecart_namespace . '::create($test_product_data);';
                error_log('Executing code: ' . $create_code);
                eval($create_code);
                
                if ($test_product && isset($test_product->id)) {
                    error_log('Test product created successfully with ID: ' . $test_product->id);
                    
                    // Clean up the test product
                    try {
                        $delete_code = $surecart_namespace . '::delete($test_product->id);';
                        error_log('Executing code: ' . $delete_code);
                        eval($delete_code);
                        error_log('Test product deleted successfully');
                    } catch (Exception $delete_e) {
                        error_log('Could not delete test product: ' . $delete_e->getMessage());
                    }
                } else {
                    error_log('Test product creation failed: ' . json_encode($test_product));
                }
            } catch (Exception $test_e) {
                error_log('Test product creation failed with exception: ' . $test_e->getMessage());
                error_log('Test exception trace: ' . $test_e->getTraceAsString());
            }
            
            // Try two approaches: 
            // 1. Create a simple product first, then add options, prices, and variants
            // 2. If that fails, try creating the product with all data at once
            
            // First approach: Simplify the product data for initial creation
            $simplified_data = array(
                'name' => $product_data['name'],
                'description' => $product_data['description'],
                'metadata' => $product_data['metadata'],
            );
            
            // Store the full data for fallback
            $full_data = array(
                'name' => $product_data['name'],
                'description' => $product_data['description'],
                'metadata' => $product_data['metadata'],
                'product_options' => isset($product_data['product_options']) ? $product_data['product_options'] : [],
                'prices' => isset($product_data['prices']) ? $product_data['prices'] : [],
                'variants' => isset($product_data['variants']) ? $product_data['variants'] : [],
            );
            
            if (isset($product_data['image_url'])) {
                $simplified_data['image_url'] = $product_data['image_url'];
                error_log('Setting image_url for create: ' . $product_data['image_url']);
                
                // Try to directly set the image using the media property
                $simplified_data['media'] = [
                    ['url' => $product_data['image_url']]
                ];
                error_log('Also setting media property with image URL: ' . json_encode($simplified_data['media']));
            } else {
                error_log('No image_url found in product data for create');
                
                // Try to get image from metadata if available
                if (isset($product_data['metadata']['printify_images'])) {
                    $images = json_decode($product_data['metadata']['printify_images'], true);
                    if (!empty($images) && isset($images[0])) {
                        if (is_string($images[0])) {
                            $simplified_data['image_url'] = $images[0];
                            error_log('Using image from metadata for create: ' . $images[0]);
                        } elseif (is_array($images[0]) && isset($images[0]['src'])) {
                            $simplified_data['image_url'] = $images[0]['src'];
                            error_log('Using image src from metadata for create: ' . $images[0]['src']);
                        }
                    }
                }
            }
            
            error_log('Creating product with simplified data first');
            
            try {
                // Check if SureCart is properly loaded
                if (!class_exists('\SureCart\Models\Product')) {
                    error_log('ERROR: SureCart\Models\Product class does not exist!');
                    return new WP_Error('surecart_not_loaded', 'SureCart Product model not found');
                }
                
                // Dump the data we're sending to SureCart
                error_log('Sending data to SureCart: ' . json_encode($simplified_data));
                
                // First, try to create the product with all data at once
                try {
                    error_log('Attempting to create product with all data at once first');
                    $create_full_code = '$full_product = ' . $surecart_namespace . '::create($full_data);';
                    error_log('Executing code: ' . $create_full_code);
                    eval($create_full_code);
                    
                    if ($full_product && isset($full_product->id)) {
                        error_log('Product created successfully with full data. ID: ' . $full_product->id);
                        return $full_product;
                    } else {
                        error_log('Failed to create product with full data, falling back to step-by-step creation');
                    }
                } catch (Exception $full_e) {
                    error_log('Failed to create product with full data: ' . $full_e->getMessage());
                    error_log('Falling back to step-by-step creation');
                }
                
                // If that fails, create the product step by step - with more detailed error handling
                try {
                    // Check if we can use the SureCart processor directly
                    if (class_exists('\SureCart\Controllers\Admin\Products\ProductsController')) {
                        error_log('Checking ProductsController methods');
                        try {
                            $controller = new \SureCart\Controllers\Admin\Products\ProductsController();
                            $methods = get_class_methods($controller);
                            error_log('Available methods in ProductsController: ' . json_encode($methods));
                            
                            // Check if store method exists (this is the correct method in newer versions)
                            if (in_array('store', $methods)) {
                                error_log('Using ProductsController->store() method');
                                $product = $controller->store(['body' => $simplified_data]);
                                error_log('ProductsController store call successful');
                            } else {
                                error_log('ProductsController store method not found, falling back to direct API call');
                                $create_code = '$product = ' . $surecart_namespace . '::create($simplified_data);';
                                error_log('Executing code: ' . $create_code);
                                eval($create_code);
                            }
                        } catch (Exception $controller_e) {
                            error_log('ProductsController call failed: ' . $controller_e->getMessage());
                            // Fall back to direct API call
                            $create_code = '$product = ' . $surecart_namespace . '::create($simplified_data);';
                            error_log('Executing code: ' . $create_code);
                            eval($create_code);
                        }
                    } else {
                        // Try to create the product with direct call
                        error_log('Attempting to create product with direct call to SureCart API');
                        $create_code = '$product = ' . $surecart_namespace . '::create($simplified_data);';
                        error_log('Executing code: ' . $create_code);
                        eval($create_code);
                        error_log('Direct API call successful');
                    }
                } catch (Exception $inner_e) {
                    error_log('Direct API call failed: ' . $inner_e->getMessage());
                    error_log('Trying to create product with all data at once...');
                    
                    // Try creating the product with all data at once
                    try {
                        error_log('Creating product with full data including options, prices, and variants');
                        $create_full_code = '$product = ' . $surecart_namespace . '::create($full_data);';
                        error_log('Executing code: ' . $create_full_code);
                        eval($create_full_code);
                        
                        if ($product && isset($product->id)) {
                            error_log('Product created successfully with full data. ID: ' . $product->id);
                            return $product;
                        } else {
                            error_log('Failed to create product with full data: ' . json_encode($product));
                        }
                    } catch (Exception $full_e) {
                        error_log('Failed to create product with full data: ' . $full_e->getMessage());
                        error_log('Full data exception trace: ' . $full_e->getTraceAsString());
                    }
                    
                    error_log('Trying alternative method using WordPress HTTP API');
                    
                    // Try to get the SureCart API URL from the plugin
                    $api_url = 'https://api.surecart.com/v1/products';
                    
                    // Check if we can get the API URL from SureCart
                    if (defined('SURECART_APP_URL')) {
                        $api_url = trailingslashit(SURECART_APP_URL) . 'products';
                        error_log('Using SureCart API URL from constant: ' . $api_url);
                    } else {
                        // Try to get the API URL from SureCart settings
                        if (function_exists('surecart_get_app_url')) {
                            $app_url = surecart_get_app_url();
                            if (!empty($app_url)) {
                                $api_url = trailingslashit($app_url) . 'products';
                                error_log('Using SureCart API URL from function: ' . $api_url);
                            }
                        }
                        
                        // Check if we can get the API URL from SureCart options
                        $app_url = get_option('surecart_app_url');
                        if (!empty($app_url)) {
                            $api_url = trailingslashit($app_url) . 'products';
                            error_log('Using SureCart API URL from option: ' . $api_url);
                        }
                    }
                    
                    // Get SureCart API key
                    $api_key = get_option('surecart_api_key', '');
                    
                    // If API key is not set, try to get it from SureCart
                    if (empty($api_key)) {
                        // Try multiple methods to get the API key
                        
                        // Method 1: Using ApiToken model
                        if (class_exists('\SureCart\Models\ApiToken')) {
                            try {
                                $token = \SureCart\Models\ApiToken::get();
                                if ($token && isset($token->key)) {
                                    $api_key = $token->key;
                                    error_log('Retrieved API key from SureCart ApiToken: ' . substr($api_key, 0, 5) . '...');
                                }
                            } catch (Exception $token_e) {
                                error_log('Error getting API token: ' . $token_e->getMessage());
                            }
                        }
                        
                        // Method 2: Using the processor directly
                        if (empty($api_key) && class_exists('\SureCart\Models\Processor')) {
                            try {
                                $processor = \SureCart\Models\Processor::get();
                                if ($processor && isset($processor->live_secret_key)) {
                                    $api_key = $processor->live_secret_key;
                                    error_log('Retrieved API key from SureCart Processor: ' . substr($api_key, 0, 5) . '...');
                                }
                            } catch (Exception $processor_e) {
                                error_log('Error getting processor: ' . $processor_e->getMessage());
                            }
                        }
                        
                        // Method 3: Using the settings
                        if (empty($api_key) && function_exists('surecart_get_app_settings')) {
                            try {
                                $settings = surecart_get_app_settings();
                                if ($settings && isset($settings['api_key'])) {
                                    $api_key = $settings['api_key'];
                                    error_log('Retrieved API key from SureCart settings: ' . substr($api_key, 0, 5) . '...');
                                }
                            } catch (Exception $settings_e) {
                                error_log('Error getting settings: ' . $settings_e->getMessage());
                            }
                        }
                    }
                    
                    error_log('Using API key: ' . (empty($api_key) ? 'Not found' : 'Found (starts with ' . substr($api_key, 0, 5) . '...)'));
                    
                    $headers = [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $api_key
                    ];
                    
                    error_log('Making HTTP request to SureCart API: ' . $api_url);
                    $response = wp_remote_post($api_url, [
                        'headers' => $headers,
                        'body' => json_encode($simplified_data),
                        'timeout' => 30
                    ]);
                    
                    if (is_wp_error($response)) {
                        error_log('HTTP request failed: ' . $response->get_error_message());
                        throw new Exception('HTTP request failed: ' . $response->get_error_message());
                    }
                    
                    $body = wp_remote_retrieve_body($response);
                    $product = json_decode($body);
                    error_log('Alternative method response: ' . $body);
                    
                    // If all else fails, try to use the WordPress admin functions
                    if (empty($product) || isset($product->error)) {
                        error_log('HTTP API failed, trying WordPress admin functions');
                        
                        // Try to use the WordPress admin functions to create a product
                        if (function_exists('surecart_create_product')) {
                            try {
                                $wp_product = surecart_create_product($simplified_data);
                                error_log('WordPress admin function successful: ' . json_encode($wp_product));
                                $product = $wp_product;
                            } catch (Exception $wp_e) {
                                error_log('WordPress admin function failed: ' . $wp_e->getMessage());
                            }
                        } else {
                            error_log('surecart_create_product function not found');
                        }
                    }
                }
                
                // Log the response
                error_log('SureCart create product response: ' . json_encode($product));
            } catch (Exception $e) {
                error_log('Exception creating SureCart product: ' . $e->getMessage());
                error_log('Exception trace: ' . $e->getTraceAsString());
                return new WP_Error('surecart_create_exception', $e->getMessage());
            }
            
            if (!$product) {
                error_log('Failed to create product in SureCart - no product returned');
                return new WP_Error('surecart_create_error', __('Failed to create product in SureCart - no product returned', 'printify-surecart-sync'));
            }
            
            // Check if there are validation errors in the response
            if (isset($product->errors) && !empty($product->errors)) {
                $error_messages = [];
                foreach ($product->errors as $field => $errors) {
                    if (is_array($errors)) {
                        foreach ($errors as $error) {
                            $error_messages[] = "$field: $error";
                        }
                    } else {
                        $error_messages[] = "$field: $errors";
                    }
                }
                $error_string = implode(', ', $error_messages);
                error_log('SureCart validation errors: ' . $error_string);
                return new WP_Error('surecart_validation_error', 'Validation errors: ' . $error_string);
            }
            
            // If product was created successfully, now add prices and options
            if (isset($product->id) && !empty($product->id)) {
                error_log('Product created successfully with ID: ' . $product->id);
                
                // First, create product options if they exist
                if (!empty($product_data['product_options'])) {
                    error_log('Adding ' . count($product_data['product_options']) . ' product options');
                    
                    // Create product options
                    $product_options_data = [
                        'product_id' => $product->id,
                        'options' => $product_data['product_options']
                    ];
                    
                    error_log('Creating product options: ' . json_encode($product_options_data));
                    
                    // Check if we need to use a different approach for product options
                    error_log('Checking for ProductOption class');
                    
                    if (class_exists('\SureCart\Models\ProductOption')) {
                        error_log('Found ProductOption class with namespace: \SureCart\Models\ProductOption');
                        $product_options = \SureCart\Models\ProductOption::create($product_options_data);
                    } elseif (class_exists('SureCart\Models\ProductOption')) {
                        error_log('Found ProductOption class with namespace: SureCart\Models\ProductOption');
                        $product_options = SureCart\Models\ProductOption::create($product_options_data);
                    } else {
                        error_log('ProductOption class not found, checking for alternative methods');
                        
                        // Check if we can use the product update method to set options
                        if (method_exists($product, 'update')) {
                            error_log('Using product->update() method to set options');
                            $product = $product->update([
                                'product_options' => $product_data['product_options']
                            ]);
                            $product_options = true; // Mark as successful
                        } elseif (class_exists('\SureCart\Controllers\Admin\Products\ProductOptionsController')) {
                            error_log('Trying ProductOptionsController');
                            try {
                                $options_controller = new \SureCart\Controllers\Admin\Products\ProductOptionsController();
                                $methods = get_class_methods($options_controller);
                                error_log('Available methods in ProductOptionsController: ' . json_encode($methods));
                                
                                if (in_array('store', $methods)) {
                                    error_log('Using ProductOptionsController->store() method');
                                    $product_options = $options_controller->store([
                                        'body' => [
                                            'product_id' => $product->id,
                                            'options' => $product_data['product_options']
                                        ]
                                    ]);
                                } else {
                                    error_log('No suitable method found in ProductOptionsController');
                                    $product_options = false;
                                }
                            } catch (Exception $options_e) {
                                error_log('ProductOptionsController error: ' . $options_e->getMessage());
                                $product_options = false;
                            }
                        } else {
                            error_log('No alternative method found for setting product options');
                            $product_options = false;
                        }
                    }
                    
                    if (!$product_options || (isset($product_options->errors) && !empty($product_options->errors))) {
                        error_log('Error creating product options: ' . json_encode($product_options));
                    } else {
                        error_log('Product options created successfully');
                    }
                }
                
                // Add prices one by one
                if (!empty($product_data['prices'])) {
                    error_log('Adding ' . count($product_data['prices']) . ' prices');
                    
                    foreach ($product_data['prices'] as $price_data) {
                        $price_data['product_id'] = $product->id;
                        
                        error_log('Creating price: ' . json_encode($price_data));
                        
                        // Check if we need to use a different approach for prices
                        error_log('Checking for Price class');
                        
                        if (class_exists('\SureCart\Models\Price')) {
                            error_log('Found Price class with namespace: \SureCart\Models\Price');
                            $price = \SureCart\Models\Price::create($price_data);
                        } elseif (class_exists('SureCart\Models\Price')) {
                            error_log('Found Price class with namespace: SureCart\Models\Price');
                            $price = SureCart\Models\Price::create($price_data);
                        } else {
                            error_log('Price class not found, checking for alternative methods');
                            
                            // Check if we can use the product update method to set prices
                            if (method_exists($product, 'update')) {
                                error_log('Using product->update() method to set price');
                                $product = $product->update([
                                    'prices' => [$price_data]
                                ]);
                                $price = true; // Mark as successful
                            } elseif (class_exists('\SureCart\Controllers\Admin\Products\PricesController')) {
                                error_log('Trying PricesController');
                                try {
                                    $prices_controller = new \SureCart\Controllers\Admin\Products\PricesController();
                                    $methods = get_class_methods($prices_controller);
                                    error_log('Available methods in PricesController: ' . json_encode($methods));
                                    
                                    if (in_array('store', $methods)) {
                                        error_log('Using PricesController->store() method');
                                        $price = $prices_controller->store([
                                            'body' => $price_data
                                        ]);
                                    } else {
                                        error_log('No suitable method found in PricesController');
                                        $price = false;
                                    }
                                } catch (Exception $price_e) {
                                    error_log('PricesController error: ' . $price_e->getMessage());
                                    $price = false;
                                }
                            } else {
                                error_log('No alternative method found for setting prices');
                                $price = false;
                            }
                        }
                        
                        if (!$price || (isset($price->errors) && !empty($price->errors))) {
                            error_log('Error creating price: ' . json_encode($price));
                        } else {
                            error_log('Price created successfully with ID: ' . ($price->id ?? 'unknown'));
                        }
                    }
                }
                
                // Add variants one by one after options are created
                if (!empty($product_data['variants'])) {
                    error_log('Adding ' . count($product_data['variants']) . ' variants');
                    
                    // Wait a moment for options to be fully processed
                    sleep(1);
                    
                    foreach ($product_data['variants'] as $variant_data) {
                        $variant_data['product_id'] = $product->id;
                        
                        error_log('Creating variant: ' . json_encode($variant_data));
                        
                        // Check if we need to use a different approach for variants
                        error_log('Checking for Variant class');
                        
                        if (class_exists('\SureCart\Models\Variant')) {
                            error_log('Found Variant class with namespace: \SureCart\Models\Variant');
                            $variant = \SureCart\Models\Variant::create($variant_data);
                        } elseif (class_exists('SureCart\Models\Variant')) {
                            error_log('Found Variant class with namespace: SureCart\Models\Variant');
                            $variant = SureCart\Models\Variant::create($variant_data);
                        } else {
                            error_log('Variant class not found, checking for alternative methods');
                            
                            // Check if we can use the product update method to set variants
                            if (method_exists($product, 'update')) {
                                error_log('Using product->update() method to set variant');
                                $product = $product->update([
                                    'variants' => [$variant_data]
                                ]);
                                $variant = true; // Mark as successful
                            } elseif (class_exists('\SureCart\Controllers\Admin\Products\VariantsController')) {
                                error_log('Trying VariantsController');
                                try {
                                    $variants_controller = new \SureCart\Controllers\Admin\Products\VariantsController();
                                    $methods = get_class_methods($variants_controller);
                                    error_log('Available methods in VariantsController: ' . json_encode($methods));
                                    
                                    if (in_array('store', $methods)) {
                                        error_log('Using VariantsController->store() method');
                                        $variant = $variants_controller->store([
                                            'body' => $variant_data
                                        ]);
                                    } else {
                                        error_log('No suitable method found in VariantsController');
                                        $variant = false;
                                    }
                                } catch (Exception $variant_e) {
                                    error_log('VariantsController error: ' . $variant_e->getMessage());
                                    $variant = false;
                                }
                            } else {
                                error_log('No alternative method found for setting variants');
                                $variant = false;
                            }
                        }
                        
                        if (!$variant || (isset($variant->errors) && !empty($variant->errors))) {
                            error_log('Error creating variant: ' . json_encode($variant));
                        } else {
                            error_log('Variant created successfully with ID: ' . ($variant->id ?? 'unknown'));
                        }
                    }
                }
            }
            
            error_log('Successfully created SureCart product with ID: ' . ($product->id ?? 'unknown'));
            return $product;
        } catch (Exception $e) {
            error_log('Exception creating SureCart product: ' . $e->getMessage());
            return new WP_Error('surecart_create_exception', $e->getMessage());
        }
    }

    /**
     * Update an existing SureCart product
     *
     * @param string $product_id SureCart product ID
     * @param array $product_data Product data
     * @return object|WP_Error SureCart product or WP_Error on failure
     */
    public function update_product($product_id, $product_data) {
        try {
            error_log('Updating SureCart product ID: ' . $product_id . ' with data structure: ' . json_encode(array_keys($product_data)));
            
            // Simplify the product data for update
            $simplified_data = array(
                'name' => $product_data['name'],
                'description' => $product_data['description'],
                'metadata' => $product_data['metadata'],
            );
            
            if (isset($product_data['image_url'])) {
                $simplified_data['image_url'] = $product_data['image_url'];
                error_log('Setting image_url for update: ' . $product_data['image_url']);
                
                // Try to directly set the image using the media property
                $simplified_data['media'] = [
                    ['url' => $product_data['image_url']]
                ];
                error_log('Also setting media property with image URL: ' . json_encode($simplified_data['media']));
            } else {
                error_log('No image_url found in product data for update');
                
                // Try to get image from metadata if available
                if (isset($product_data['metadata']['printify_images'])) {
                    $images = json_decode($product_data['metadata']['printify_images'], true);
                    if (!empty($images) && isset($images[0])) {
                        if (is_string($images[0])) {
                            $simplified_data['image_url'] = $images[0];
                            error_log('Using image from metadata for update: ' . $images[0]);
                        } elseif (is_array($images[0]) && isset($images[0]['src'])) {
                            $simplified_data['image_url'] = $images[0]['src'];
                            error_log('Using image src from metadata for update: ' . $images[0]['src']);
                        }
                    }
                }
            }
            
            error_log('Updating product with simplified data first');
            
            try {
                // Check if SureCart is properly loaded
                if (!class_exists('\SureCart\Models\Product')) {
                    error_log('ERROR: SureCart\Models\Product class does not exist!');
                    return new WP_Error('surecart_not_loaded', 'SureCart Product model not found');
                }
                
                // Dump the data we're sending to SureCart
                error_log('Sending data to SureCart for update: ' . json_encode($simplified_data));
                
                // Update the product
                $product = \SureCart\Models\Product::update($product_id, $simplified_data);
                
                // Log the response
                error_log('SureCart update product response: ' . json_encode($product));
            } catch (Exception $e) {
                error_log('Exception updating SureCart product: ' . $e->getMessage());
                error_log('Exception trace: ' . $e->getTraceAsString());
                return new WP_Error('surecart_update_exception', $e->getMessage());
            }
            
            if (!$product) {
                error_log('Failed to update product in SureCart - no product returned');
                return new WP_Error('surecart_update_error', __('Failed to update product in SureCart - no product returned', 'printify-surecart-sync'));
            }
            
            // Check if there are validation errors in the response
            if (isset($product->errors) && !empty($product->errors)) {
                $error_messages = [];
                foreach ($product->errors as $field => $errors) {
                    if (is_array($errors)) {
                        foreach ($errors as $error) {
                            $error_messages[] = "$field: $error";
                        }
                    } else {
                        $error_messages[] = "$field: $errors";
                    }
                }
                $error_string = implode(', ', $error_messages);
                error_log('SureCart validation errors: ' . $error_string);
                return new WP_Error('surecart_validation_error', 'Validation errors: ' . $error_string);
            }
            
            // If product was updated successfully, now update options, prices and variants
            if (isset($product->id) && !empty($product->id)) {
                error_log('Product updated successfully with ID: ' . $product->id);
                
                // First, update product options if they exist
                if (!empty($product_data['product_options'])) {
                    error_log('Updating ' . count($product_data['product_options']) . ' product options');
                    
                    // Get existing product options
                    $existing_options = \SureCart\Models\ProductOption::where(['product_id' => $product_id])->get();
                    
                    if ($existing_options && isset($existing_options->data) && !empty($existing_options->data)) {
                        // Update existing options
                        $existing_option_id = $existing_options->data[0]->id;
                        error_log('Updating existing product options with ID: ' . $existing_option_id);
                        
                        $product_options_data = [
                            'options' => $product_data['product_options']
                        ];
                        
                        $product_options = \SureCart\Models\ProductOption::update($existing_option_id, $product_options_data);
                        
                        if (!$product_options || (isset($product_options->errors) && !empty($product_options->errors))) {
                            error_log('Error updating product options: ' . json_encode($product_options));
                        } else {
                            error_log('Product options updated successfully');
                        }
                    } else {
                        // Create new options
                        error_log('Creating new product options');
                        
                        $product_options_data = [
                            'product_id' => $product_id,
                            'options' => $product_data['product_options']
                        ];
                        
                        $product_options = \SureCart\Models\ProductOption::create($product_options_data);
                        
                        if (!$product_options || (isset($product_options->errors) && !empty($product_options->errors))) {
                            error_log('Error creating product options: ' . json_encode($product_options));
                        } else {
                            error_log('Product options created successfully');
                        }
                    }
                    
                    // Wait a moment for options to be fully processed
                    sleep(1);
                }
                
                // Get existing prices
                $existing_prices = \SureCart\Models\Price::where(['product_id' => $product_id])->get();
                $existing_price_ids = [];
                
                if ($existing_prices && isset($existing_prices->data)) {
                    foreach ($existing_prices->data as $price) {
                        $existing_price_ids[] = $price->id;
                    }
                }
                
                error_log('Found ' . count($existing_price_ids) . ' existing prices');
                
                // Add or update prices
                if (!empty($product_data['prices'])) {
                    error_log('Processing ' . count($product_data['prices']) . ' prices');
                    
                    foreach ($product_data['prices'] as $index => $price_data) {
                        $price_data['product_id'] = $product_id;
                        
                        if (isset($existing_price_ids[$index])) {
                            // Update existing price
                            error_log('Updating price ID: ' . $existing_price_ids[$index]);
                            $price = \SureCart\Models\Price::update($existing_price_ids[$index], $price_data);
                        } else {
                            // Create new price
                            error_log('Creating new price');
                            $price = \SureCart\Models\Price::create($price_data);
                        }
                        
                        if (!$price || (isset($price->errors) && !empty($price->errors))) {
                            error_log('Error with price: ' . json_encode($price));
                        } else {
                            error_log('Price processed successfully with ID: ' . ($price->id ?? 'unknown'));
                        }
                    }
                }
                
                // Get existing variants
                $existing_variants = \SureCart\Models\Variant::where(['product_id' => $product_id])->get();
                $existing_variant_ids = [];
                
                if ($existing_variants && isset($existing_variants->data)) {
                    foreach ($existing_variants->data as $variant) {
                        $existing_variant_ids[] = $variant->id;
                    }
                }
                
                error_log('Found ' . count($existing_variant_ids) . ' existing variants');
                
                // Add or update variants
                if (!empty($product_data['variants'])) {
                    error_log('Processing ' . count($product_data['variants']) . ' variants');
                    
                    foreach ($product_data['variants'] as $index => $variant_data) {
                        $variant_data['product_id'] = $product_id;
                        
                        if (isset($existing_variant_ids[$index])) {
                            // Update existing variant
                            error_log('Updating variant ID: ' . $existing_variant_ids[$index]);
                            $variant = \SureCart\Models\Variant::update($existing_variant_ids[$index], $variant_data);
                        } else {
                            // Create new variant
                            error_log('Creating new variant');
                            $variant = \SureCart\Models\Variant::create($variant_data);
                        }
                        
                        if (!$variant || (isset($variant->errors) && !empty($variant->errors))) {
                            error_log('Error with variant: ' . json_encode($variant));
                        } else {
                            error_log('Variant processed successfully with ID: ' . ($variant->id ?? 'unknown'));
                        }
                    }
                }
            }
            
            error_log('Successfully updated SureCart product with ID: ' . $product_id);
            // Add product media (images) if available
            // First, check for the primary image
            if (isset($product_data['image_url']) && !empty($product_data['image_url'])) {
                error_log('Adding primary product image after update');
                $media_result = $this->add_product_media($product_id, $product_data['image_url']);
                if ($media_result) {
                    error_log('Successfully added primary product image');
                } else {
                    error_log('Failed to add primary product image, but product was updated successfully');
                }
            }
            
            // Then, check for additional images in the metadata
            if (isset($product_data['metadata']['printify_images'])) {
                $images = json_decode($product_data['metadata']['printify_images'], true);
                if (is_array($images) && count($images) > 1) {
                    error_log('Found ' . count($images) . ' additional images to add');
                    
                    // Skip the first image as we've already added it
                    for ($i = 1; $i < count($images); $i++) {
                        $image_url = '';
                        
                        if (is_string($images[$i])) {
                            // Direct URL
                            $image_url = $images[$i];
                        } elseif (is_array($images[$i]) && isset($images[$i]['src'])) {
                            // Object with src property
                            $image_url = $images[$i]['src'];
                        } elseif (is_object($images[$i]) && isset($images[$i]->src)) {
                            // Object with src property
                            $image_url = $images[$i]->src;
                        } else {
                            // Try to extract image URL from the JSON string
                            $image_json = json_encode($images[$i]);
                            if (preg_match('/"(https?:\/\/[^"]+\.(jpg|jpeg|png|gif))"/', $image_json, $matches)) {
                                $image_url = $matches[1];
                            }
                        }
                        
                        if (!empty($image_url)) {
                            error_log('Adding additional image ' . ($i + 1) . ': ' . $image_url);
                            $media_result = $this->add_product_media($product_id, $image_url);
                            if ($media_result) {
                                error_log('Successfully added additional image ' . ($i + 1));
                            } else {
                                error_log('Failed to add additional image ' . ($i + 1));
                            }
                            
                            // Add a small delay to prevent rate limiting
                            usleep(500000); // 0.5 seconds
                        }
                    }
                }
            }
            
            return $product;
        } catch (Exception $e) {
            error_log('Exception updating SureCart product: ' . $e->getMessage());
            return new WP_Error('surecart_update_exception', $e->getMessage());
        }
    }

    /**
     * Create a price for a product
     *
     * @param string $product_id SureCart product ID
     * @param array $price_data Price data
     * @return object|WP_Error SureCart price or WP_Error on failure
     */
    public function create_price($product_id, $price_data) {
        try {
            $price_data['product_id'] = $product_id;
            $price = \SureCart\Models\Price::create($price_data);
            
            if (!$price) {
                return new WP_Error('surecart_price_create_error', __('Failed to create price in SureCart', 'printify-surecart-sync'));
            }
            
            return $price;
        } catch (Exception $e) {
            return new WP_Error('surecart_price_create_exception', $e->getMessage());
        }
    }

    /**
     * Update an existing price
     *
     * @param string $price_id SureCart price ID
     * @param array $price_data Price data
     * @return object|WP_Error SureCart price or WP_Error on failure
     */
    public function update_price($price_id, $price_data) {
        try {
            $price = \SureCart\Models\Price::update($price_id, $price_data);
            
            if (!$price) {
                return new WP_Error('surecart_price_update_error', __('Failed to update price in SureCart', 'printify-surecart-sync'));
            }
            
            return $price;
        } catch (Exception $e) {
            return new WP_Error('surecart_price_update_exception', $e->getMessage());
        }
    }

    /**
     * Create a variant for a product
     *
     * @param string $product_id SureCart product ID
     * @param array $variant_data Variant data
     * @return object|WP_Error SureCart variant or WP_Error on failure
     */
    public function create_variant($product_id, $variant_data) {
        try {
            $variant_data['product_id'] = $product_id;
            $variant = \SureCart\Models\Variant::create($variant_data);
            
            if (!$variant) {
                return new WP_Error('surecart_variant_create_error', __('Failed to create variant in SureCart', 'printify-surecart-sync'));
            }
            
            return $variant;
        } catch (Exception $e) {
            return new WP_Error('surecart_variant_create_exception', $e->getMessage());
        }
    }

    /**
     * Update an existing variant
     *
     * @param string $variant_id SureCart variant ID
     * @param array $variant_data Variant data
     * @return object|WP_Error SureCart variant or WP_Error on failure
     */
    public function update_variant($variant_id, $variant_data) {
        try {
            $variant = \SureCart\Models\Variant::update($variant_id, $variant_data);
            
            if (!$variant) {
                return new WP_Error('surecart_variant_update_error', __('Failed to update variant in SureCart', 'printify-surecart-sync'));
            }
            
            return $variant;
        } catch (Exception $e) {
            return new WP_Error('surecart_variant_update_exception', $e->getMessage());
        }
    }

    /**
     * Convert Printify product to SureCart product data
     *
     * @param array $printify_product Printify product data
     * @return array SureCart product data
     */
    public function convert_printify_to_surecart($printify_product) {
        error_log('Converting Printify product to SureCart format: ' . json_encode(array_keys($printify_product)));
        
        // Validate required fields
        if (empty($printify_product['title'])) {
            error_log('Printify product missing title');
            $printify_product['title'] = 'Untitled Product';
        }
        
        if (empty($printify_product['description'])) {
            error_log('Printify product missing description');
            $printify_product['description'] = '';
        }
        
        // Clean up the description - strip HTML tags and decode entities
        $description = '';
        if (!empty($printify_product['description'])) {
            // First, convert <br> tags to newlines
            $description = str_replace(['<br>', '<br/>', '<br />'], "\n", $printify_product['description']);
            // Strip all other HTML tags
            $description = strip_tags($description);
            // Decode HTML entities
            $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
            // Trim whitespace
            $description = trim($description);
            
            error_log('Cleaned description: ' . substr($description, 0, 100) . (strlen($description) > 100 ? '...' : ''));
        }
        
        // Clean up the title - strip HTML tags and decode entities
        $title = '';
        if (!empty($printify_product['title'])) {
            $title = strip_tags($printify_product['title']);
            $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
            $title = trim($title);
            
            error_log('Cleaned title: ' . $title);
        }
        
        // Basic product data
        $product_data = array(
            'name' => $title,
            'description' => $description,
            'metadata' => array(
                'printify_id' => $printify_product['id'],
                'printify_external_id' => $printify_product['external_id'] ?? '',
                'printify_print_provider_id' => $printify_product['print_provider_id'] ?? '',
                'printify_blueprint_id' => $printify_product['blueprint_id'] ?? '',
                'printify_synced_at' => current_time('mysql'),
            ),
        );
        
        // Set product image if available
        $processed_images = [];
        $image_ids_to_include = [];
        $variant_specific_images = false;
        
        try {
            // First, collect image IDs from active variants
            if (!empty($printify_product['variants']) && is_array($printify_product['variants'])) {
            foreach ($printify_product['variants'] as $variant) {
                // Only consider active variants
                if ($this->is_variant_active($variant)) {
                    // Check for image_id field
                    if (isset($variant['image_id']) && !empty($variant['image_id'])) {
                        $image_ids_to_include[] = $variant['image_id'];
                        $variant_specific_images = true;
                        error_log('Found image_id ' . $variant['image_id'] . ' for active variant: ' . ($variant['title'] ?? 'Unnamed'));
                    }
                    
                    // Check for image field
                    if (isset($variant['image']) && !empty($variant['image'])) {
                        if (is_string($variant['image'])) {
                            // Direct URL
                            $processed_images[] = $variant['image'];
                            error_log('Found direct image URL for active variant: ' . $variant['image']);
                        } elseif (is_array($variant['image']) && isset($variant['image']['id'])) {
                            $image_ids_to_include[] = $variant['image']['id'];
                            $variant_specific_images = true;
                            error_log('Found image id ' . $variant['image']['id'] . ' for active variant: ' . ($variant['title'] ?? 'Unnamed'));
                        }
                    }
                }
            }
            
            // Remove duplicates
            $image_ids_to_include = array_unique($image_ids_to_include);
            error_log('Found ' . count($image_ids_to_include) . ' unique image IDs from active variants');
        }
        
        if (!empty($printify_product['images']) && is_array($printify_product['images'])) {
            error_log('Found ' . count($printify_product['images']) . ' images for product');
            
            // Process images to ensure we have valid URLs
            foreach ($printify_product['images'] as $image_index => $image) {
                // If we have variant-specific images, only include images that match the IDs from active variants
                if ($variant_specific_images && !empty($image_ids_to_include)) {
                    $include_image = false;
                    
                    // Check if this image's ID is in our list to include
                    if (is_array($image) && isset($image['id']) && in_array($image['id'], $image_ids_to_include)) {
                        $include_image = true;
                        error_log('Including image with ID ' . $image['id'] . ' (matched active variant)');
                    } elseif (is_object($image) && isset($image->id) && in_array($image->id, $image_ids_to_include)) {
                        $include_image = true;
                        error_log('Including image with ID ' . $image->id . ' (matched active variant)');
                    } elseif ($image_index === 0) {
                        // Always include the first image (main product image)
                        $include_image = true;
                        error_log('Including first image (main product image)');
                    }
                    
                    if (!$include_image) {
                        error_log('Skipping image that does not match any active variant');
                        continue;
                    }
                }
                
                $image_url = '';
                
                if (is_string($image)) {
                    // Direct URL
                    $image_url = $image;
                    error_log('Found direct image URL: ' . $image_url);
                } elseif (is_array($image)) {
                    // Check for different array formats
                    if (isset($image['src'])) {
                        $image_url = $image['src'];
                        error_log('Found image src from array: ' . $image_url);
                    } elseif (isset($image['preview_url'])) {
                        $image_url = $image['preview_url'];
                        error_log('Found preview_url from array: ' . $image_url);
                    } elseif (isset($image['url'])) {
                        $image_url = $image['url'];
                        error_log('Found url from array: ' . $image_url);
                    }
                } elseif (is_object($image)) {
                    // Check for different object formats
                    if (isset($image->src)) {
                        $image_url = $image->src;
                        error_log('Found image src from object: ' . $image_url);
                    } elseif (isset($image->preview_url)) {
                        $image_url = $image->preview_url;
                        error_log('Found preview_url from object: ' . $image_url);
                    } elseif (isset($image->url)) {
                        $image_url = $image->url;
                        error_log('Found url from object: ' . $image_url);
                    }
                }
                
                // If we still don't have a URL, try to extract it from the JSON
                if (empty($image_url)) {
                    error_log('Image format not recognized: ' . json_encode($image));
                    
                    // Try to extract image URL from the JSON string
                    $image_json = json_encode($image);
                    if (preg_match('/"(https?:\/\/[^"]+\.(jpg|jpeg|png|gif))"/', $image_json, $matches)) {
                        $image_url = $matches[1];
                        error_log('Extracted image URL from JSON: ' . $image_url);
                    }
                }
                
                // Add the image URL to our processed images array if it's valid
                if (!empty($image_url)) {
                    $processed_images[] = $image_url;
                }
            }
        } else {
            error_log('No images array found for product');
        }
        
        // Try to find images in other fields if we don't have any yet
        if (empty($processed_images)) {
            if (isset($printify_product['image']) && !empty($printify_product['image'])) {
                error_log('Found image field: ' . json_encode($printify_product['image']));
                if (is_string($printify_product['image'])) {
                    $processed_images[] = $printify_product['image'];
                    error_log('Using image field as URL: ' . $printify_product['image']);
                }
            }
            
            if (isset($printify_product['preview_url']) && !empty($printify_product['preview_url'])) {
                $processed_images[] = $printify_product['preview_url'];
                error_log('Using preview_url field: ' . $printify_product['preview_url']);
            }
            
            if (isset($printify_product['thumbnail_url']) && !empty($printify_product['thumbnail_url'])) {
                $processed_images[] = $printify_product['thumbnail_url'];
                error_log('Using thumbnail_url field: ' . $printify_product['thumbnail_url']);
            }
        }
        
        // Set the primary image URL if we have at least one image
        if (!empty($processed_images)) {
            $product_data['image_url'] = $processed_images[0];
            error_log('Setting primary image URL: ' . $product_data['image_url']);
            
            // Store all processed images in metadata for future use
            $product_data['metadata']['printify_images'] = json_encode($processed_images);
            error_log('Stored ' . count($processed_images) . ' processed image URLs in metadata');
        } else {
            error_log('No valid image URLs found for product');
        }
        
        // Make sure the image URL is properly formatted
        if (isset($product_data['image_url'])) {
            // Ensure the URL starts with http or https
            if (!preg_match('/^https?:\/\//', $product_data['image_url'])) {
                error_log('Image URL does not start with http(s), adding https: ' . $product_data['image_url']);
                $product_data['image_url'] = 'https:' . $product_data['image_url'];
            }
            
            error_log('Final image URL: ' . $product_data['image_url']);
        } else {
            error_log('No image URL set for product after all attempts');
        }
        } catch (Exception $e) {
            error_log('Error processing images: ' . $e->getMessage());
            error_log('Falling back to default image processing');
            
            // Fallback to simple image processing
            $processed_images = [];
            
            if (!empty($printify_product['images']) && is_array($printify_product['images'])) {
                foreach ($printify_product['images'] as $image) {
                    if (is_string($image)) {
                        $processed_images[] = $image;
                    } elseif (is_array($image) && isset($image['src'])) {
                        $processed_images[] = $image['src'];
                    }
                }
            }
            
            if (!empty($processed_images)) {
                $product_data['image_url'] = $processed_images[0];
                $product_data['metadata']['printify_images'] = json_encode($processed_images);
            }
        }
        
        // Process variants
        $variants = array();
        $prices = array();
        $product_options = array();
        
        // Extract product options from variant titles
        if (!empty($printify_product['variants']) && is_array($printify_product['variants'])) {
            // Count active variants
            $active_variants_count = 0;
            foreach ($printify_product['variants'] as $variant) {
                if ($this->is_variant_active($variant)) {
                    $active_variants_count++;
                }
            }
            
            error_log('Processing ' . count($printify_product['variants']) . ' total variants, ' . $active_variants_count . ' active variants');
            
            // Try to get option names from the product data
            $option_names = [];
            if (isset($printify_product['options']) && is_array($printify_product['options'])) {
                foreach ($printify_product['options'] as $option) {
                    if (isset($option['name'])) {
                        $option_names[] = $option['name'];
                    }
                }
                error_log('Found option names from product data: ' . json_encode($option_names));
            }
            
            // First, extract option names and values from variant titles
            $option_values = array();
            $max_parts = 0;
            
            // First pass: determine the maximum number of parts in any active variant title
            foreach ($printify_product['variants'] as $variant) {
                // Only consider active variants
                if ($this->is_variant_active($variant) && !empty($variant['title'])) {
                    $title_parts = explode(' / ', $variant['title']);
                    $max_parts = max($max_parts, count($title_parts));
                }
            }
            
            error_log('Maximum parts in variant titles: ' . $max_parts);
            
            // Second pass: extract option values from active variants only
            foreach ($printify_product['variants'] as $index => $variant) {
                // Only consider active variants
                if ($this->is_variant_active($variant) && !empty($variant['title'])) {
                    $title_parts = explode(' / ', $variant['title']);
                    
                    foreach ($title_parts as $i => $part) {
                        // Use provided option name if available, otherwise use generic name
                        $option_name = isset($option_names[$i]) ? $option_names[$i] : 'Option ' . ($i + 1);
                        
                        if (!isset($option_values[$option_name])) {
                            $option_values[$option_name] = array();
                        }
                        
                        $part = trim($part);
                        if (!empty($part) && !in_array($part, $option_values[$option_name])) {
                            $option_values[$option_name][] = $part;
                        }
                    }
                }
            }
            
            // Create product options array
            if (!empty($option_values)) {
                foreach ($option_values as $name => $values) {
                    if (!empty($values)) {
                        $product_options[] = array(
                            'name' => $name,
                            'values' => $values
                        );
                    }
                }
                
                error_log('Created product options: ' . json_encode($product_options));
                $product_data['product_options'] = $product_options;
            }
            
            // Now process each variant, but only if it's active/selected
            foreach ($printify_product['variants'] as $index => $variant) {
                // Check if the variant is active/selected
                $is_active = $this->is_variant_active($variant);
                
                // Log the reason if the variant is inactive
                if (!$is_active) {
                    if (isset($variant['is_active']) && $variant['is_active'] === false) {
                        error_log('Variant ' . ($index + 1) . ' is marked as inactive via is_active field');
                    } elseif (isset($variant['active']) && $variant['active'] === false) {
                        error_log('Variant ' . ($index + 1) . ' is marked as inactive via active field');
                    } elseif (isset($variant['is_enabled']) && $variant['is_enabled'] === false) {
                        error_log('Variant ' . ($index + 1) . ' is marked as inactive via is_enabled field');
                    } elseif (isset($variant['enabled']) && $variant['enabled'] === false) {
                        error_log('Variant ' . ($index + 1) . ' is marked as inactive via enabled field');
                    } elseif (isset($variant['is_selected']) && $variant['is_selected'] === false) {
                        error_log('Variant ' . ($index + 1) . ' is marked as inactive via is_selected field');
                    } elseif (isset($variant['selected']) && $variant['selected'] === false) {
                        error_log('Variant ' . ($index + 1) . ' is marked as inactive via selected field');
                    } elseif (isset($variant['is_available']) && $variant['is_available'] === false) {
                        error_log('Variant ' . ($index + 1) . ' is marked as inactive via is_available field');
                    } elseif (isset($variant['available']) && $variant['available'] === false) {
                        error_log('Variant ' . ($index + 1) . ' is marked as inactive via available field');
                    }
                    
                    error_log('Skipping inactive variant: ' . ($variant['title'] ?? 'Unnamed variant'));
                    continue;
                }
                
                // Validate variant data
                if (empty($variant['title'])) {
                    error_log('Variant missing title, using default');
                    $variant['title'] = 'Variant ' . ($index + 1);
                } else {
                    // Clean up the variant title
                    $variant['title'] = strip_tags(trim($variant['title']));
                    $variant['title'] = html_entity_decode($variant['title'], ENT_QUOTES, 'UTF-8');
                }
                
                if (!isset($variant['price']) || !is_numeric($variant['price'])) {
                    error_log('Variant missing or invalid price, using 0');
                    $variant['price'] = 0;
                }
                
                if (!isset($variant['cost']) || !is_numeric($variant['cost'])) {
                    error_log('Variant missing or invalid cost, using 0');
                    $variant['cost'] = 0;
                }
                
                if (empty($variant['sku'])) {
                    error_log('Variant missing SKU, generating one');
                    $variant['sku'] = 'PRINTIFY-' . $printify_product['id'] . '-' . ($index + 1);
                } else {
                    // Clean up the SKU - remove any special characters
                    $variant['sku'] = preg_replace('/[^A-Za-z0-9\-_]/', '', $variant['sku']);
                    if (empty($variant['sku'])) {
                        $variant['sku'] = 'PRINTIFY-' . $printify_product['id'] . '-' . ($index + 1);
                    }
                }
                
                // Ensure price and cost are valid numbers
                $price_amount = (float)$variant['price'];
                $cost_amount = (float)$variant['cost'];
                
                // Ensure price is not negative
                if ($price_amount < 0) {
                    error_log('Negative price detected, setting to 0');
                    $price_amount = 0;
                }
                
                // Ensure cost is not negative
                if ($cost_amount < 0) {
                    error_log('Negative cost detected, setting to 0');
                    $cost_amount = 0;
                }
                
                // Check if the price is already in cents (greater than 1000 likely means it's in cents)
                $is_already_cents = ($price_amount > 1000);
                
                // If the price is already in cents, convert it back to dollars
                if ($is_already_cents) {
                    $price_amount = $price_amount / 100;
                    $cost_amount = $cost_amount / 100;
                    error_log('Price appears to be in cents already, converting to dollars: ' . $price_amount);
                }
                
                // Log the variant data
                error_log('Variant ' . ($index + 1) . ': Title=' . $variant['title'] . ', SKU=' . $variant['sku'] . 
                          ', Price=' . $price_amount . ', Cost=' . $cost_amount);
                
                // Convert to cents for SureCart (SureCart expects prices in cents)
                $price_in_cents = (int)($price_amount * 100);
                $cost_in_cents = (int)($cost_amount * 100);
                
                error_log('Price in cents: ' . $price_in_cents);
                
                // Create a price for this variant
                $prices[] = array(
                    'name' => $variant['title'],
                    'amount' => $price_in_cents,
                    'currency' => 'usd', // Default to USD
                    'recurring' => false,
                );
                
                // Create a variant with option values
                $variant_data = array(
                    'title' => $variant['title'],
                    'sku' => $variant['sku'],
                    'price' => $price_in_cents,
                    'cost' => $cost_in_cents,
                    'metadata' => array(
                        'printify_variant_id' => $variant['id'] ?? '',
                        'printify_variant_options' => json_encode($variant['options'] ?? array()),
                    ),
                );
                
                // Add option values based on the variant title
                if (!empty($variant['title'])) {
                    $title_parts = explode(' / ', $variant['title']);
                    
                    foreach ($title_parts as $i => $part) {
                        $option_key = 'option_' . ($i + 1);
                        $variant_data[$option_key] = $part;
                    }
                    
                    error_log('Added option values to variant: ' . json_encode(array_filter($variant_data, function($key) {
                        return strpos($key, 'option_') === 0;
                    }, ARRAY_FILTER_USE_KEY)));
                }
                
                $variants[] = $variant_data;
            }
        } else {
            error_log('No variants found, creating default price');
            
            // Get a default price from the product if available
            $default_price = 0;
            if (isset($printify_product['price']) && is_numeric($printify_product['price'])) {
                $default_price = (float)$printify_product['price'];
                
                // Check if the price is already in cents (greater than 1000 likely means it's in cents)
                if ($default_price > 1000) {
                    $default_price = $default_price / 100;
                    error_log('Default price appears to be in cents already, converting to dollars: ' . $default_price);
                }
                
                error_log('Using product default price: ' . $default_price);
            }
            
            // Convert to cents for SureCart
            $default_price_cents = (int)($default_price * 100);
            
            // If no variants, create a default price
            $prices[] = array(
                'name' => 'Default',
                'amount' => $default_price_cents,
                'currency' => 'usd',
                'recurring' => false,
            );
            
            // Also create a default variant
            $variants[] = array(
                'title' => 'Default',
                'sku' => 'PRINTIFY-' . $printify_product['id'] . '-DEFAULT',
                'price' => $default_price_cents,
                'cost' => 0,
                'metadata' => array(
                    'printify_variant_id' => '',
                    'printify_variant_options' => '{}',
                ),
            );
            
            error_log('Created default price and variant');
        }
        
        // Add prices and variants to product data
        $product_data['prices'] = $prices;
        $product_data['variants'] = $variants;
        
        error_log('Converted product data: ' . json_encode(array_keys($product_data)));
        return $product_data;
    }

    /**
     * Process a Printify product
     *
     * @param array $printify_product Printify product data
     * @param bool $force_update Whether to force update even if product exists
     * @return string|WP_Error 'created', 'updated', or WP_Error on failure
     */
    public function process_product($printify_product, $force_update = false) {
        try {
            error_log('Processing Printify product ID: ' . $printify_product['id'] . 
                      ', Title: ' . ($printify_product['title'] ?? 'Unknown') . 
                      ($force_update ? ' (Force Update)' : ''));
            
            // Check if SureCart is active
            if (!$this->is_surecart_active()) {
                error_log('ERROR: Cannot process product - SureCart is not active');
                return new WP_Error('surecart_not_active', 'SureCart plugin is not active or not properly loaded');
            }
            
            // Validate required fields
            if (empty($printify_product['id'])) {
                error_log('Missing required field: id');
                return new WP_Error('missing_field', 'Missing required field: id');
            }
            
            if (empty($printify_product['title'])) {
                error_log('Missing required field: title');
                return new WP_Error('missing_field', 'Missing required field: title');
            }
            
            // Convert Printify product to SureCart format
            $product_data = $this->convert_printify_to_surecart($printify_product);
            
            // Check if product already exists
            $existing_product = $this->find_product_by_printify_id($printify_product['id']);
            
            if ($existing_product) {
                error_log('Product already exists in SureCart with ID: ' . $existing_product->id);
                
                // Always update if force_update is true
                if ($force_update) {
                    error_log('Force update enabled - updating product regardless of changes');
                    $result = $this->update_product($existing_product->id, $product_data);
                    if (is_wp_error($result)) {
                        error_log('Error updating product: ' . $result->get_error_message());
                        return $result;
                    }
                    return 'updated';
                } else {
                    // Update existing product
                    error_log('Normal update - updating product if needed');
                    $result = $this->update_product($existing_product->id, $product_data);
                    if (is_wp_error($result)) {
                        error_log('Error updating product: ' . $result->get_error_message());
                        return $result;
                    }
                    return 'updated';
                }
            } else {
                error_log('Creating new product in SureCart');
                // Create new product
                $result = $this->create_product($product_data);
                if (is_wp_error($result)) {
                    error_log('Error creating product: ' . $result->get_error_message());
                    return $result;
                }
                
                // Add product media (images) if available
                if (isset($result->id)) {
                    // First, check for the primary image
                    if (isset($product_data['image_url']) && !empty($product_data['image_url'])) {
                        error_log('Adding primary product image after creation');
                        $media_result = $this->add_product_media($result->id, $product_data['image_url']);
                        if ($media_result) {
                            error_log('Successfully added primary product image');
                        } else {
                            error_log('Failed to add primary product image, but product was created successfully');
                        }
                    }
                    
                    // Then, check for additional images in the metadata
                    if (isset($product_data['metadata']['printify_images'])) {
                        $images = json_decode($product_data['metadata']['printify_images'], true);
                        if (is_array($images) && count($images) > 1) {
                            error_log('Found ' . count($images) . ' additional images to add');
                            
                            // Skip the first image as we've already added it
                            for ($i = 1; $i < count($images); $i++) {
                                $image_url = '';
                                
                                if (is_string($images[$i])) {
                                    // Direct URL
                                    $image_url = $images[$i];
                                } elseif (is_array($images[$i]) && isset($images[$i]['src'])) {
                                    // Object with src property
                                    $image_url = $images[$i]['src'];
                                } elseif (is_object($images[$i]) && isset($images[$i]->src)) {
                                    // Object with src property
                                    $image_url = $images[$i]->src;
                                } else {
                                    // Try to extract image URL from the JSON string
                                    $image_json = json_encode($images[$i]);
                                    if (preg_match('/"(https?:\/\/[^"]+\.(jpg|jpeg|png|gif))"/', $image_json, $matches)) {
                                        $image_url = $matches[1];
                                    }
                                }
                                
                                if (!empty($image_url)) {
                                    error_log('Adding additional image ' . ($i + 1) . ': ' . $image_url);
                                    $media_result = $this->add_product_media($result->id, $image_url);
                                    if ($media_result) {
                                        error_log('Successfully added additional image ' . ($i + 1));
                                    } else {
                                        error_log('Failed to add additional image ' . ($i + 1));
                                    }
                                    
                                    // Add a small delay to prevent rate limiting
                                    usleep(500000); // 0.5 seconds
                                }
                            }
                        }
                    }
                }
                
                return 'created';
            }
        } catch (Exception $e) {
            $error_message = sprintf(__('Error processing product %s: %s', 'printify-surecart-sync'), 
                                    $printify_product['id'] ?? 'unknown', 
                                    $e->getMessage());
            error_log($error_message);
            return new WP_Error('processing_error', $error_message);
        }
    }
}