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
     * Find a SureCart product by Printify ID
     *
     * @param string $printify_id Printify product ID
     * @return object|null SureCart product or null if not found
     */
    public function find_product_by_printify_id($printify_id) {
        // Query SureCart products with metadata filter
        $products = \SureCart\Models\Product::where([
            'metadata' => [
                'printify_id' => $printify_id
            ]
        ])->get();
        
        return !empty($products->data) ? $products->data[0] : null;
    }

    /**
     * Create a new SureCart product
     *
     * @param array $product_data Product data
     * @return object|WP_Error SureCart product or WP_Error on failure
     */
    public function create_product($product_data) {
        try {
            $product = \SureCart\Models\Product::create($product_data);
            
            if (!$product) {
                return new WP_Error('surecart_create_error', __('Failed to create product in SureCart', 'printify-surecart-sync'));
            }
            
            return $product;
        } catch (Exception $e) {
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
            $product = \SureCart\Models\Product::update($product_id, $product_data);
            
            if (!$product) {
                return new WP_Error('surecart_update_error', __('Failed to update product in SureCart', 'printify-surecart-sync'));
            }
            
            return $product;
        } catch (Exception $e) {
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
        // Basic product data
        $product_data = array(
            'name' => $printify_product['title'],
            'description' => $printify_product['description'],
            'metadata' => array(
                'printify_id' => $printify_product['id'],
                'printify_external_id' => $printify_product['external_id'] ?? '',
                'printify_print_provider_id' => $printify_product['print_provider_id'] ?? '',
                'printify_blueprint_id' => $printify_product['blueprint_id'] ?? '',
                'printify_synced_at' => current_time('mysql'),
            ),
        );
        
        // Set product image if available
        if (!empty($printify_product['images']) && is_array($printify_product['images'])) {
            $product_data['image_url'] = $printify_product['images'][0];
        }
        
        // Process variants
        $variants = array();
        $prices = array();
        
        if (!empty($printify_product['variants']) && is_array($printify_product['variants'])) {
            foreach ($printify_product['variants'] as $index => $variant) {
                // Create a price for this variant
                $prices[] = array(
                    'name' => $variant['title'],
                    'amount' => $variant['price'] * 100, // Convert to cents
                    'currency' => 'usd', // Default to USD
                    'recurring' => false,
                );
                
                // Create a variant
                $variants[] = array(
                    'title' => $variant['title'],
                    'sku' => $variant['sku'],
                    'price' => $variant['price'] * 100, // Convert to cents
                    'cost' => $variant['cost'] * 100, // Convert to cents
                    'metadata' => array(
                        'printify_variant_id' => $variant['id'],
                        'printify_variant_options' => json_encode($variant['options'] ?? array()),
                    ),
                );
            }
        }
        
        // Add prices and variants to product data
        $product_data['prices'] = $prices;
        $product_data['variants'] = $variants;
        
        return $product_data;
    }

    /**
     * Process a Printify product
     *
     * @param array $printify_product Printify product data
     * @return string|WP_Error 'created', 'updated', or WP_Error on failure
     */
    public function process_product($printify_product) {
        try {
            // Convert Printify product to SureCart format
            $product_data = $this->convert_printify_to_surecart($printify_product);
            
            // Check if product already exists
            $existing_product = $this->find_product_by_printify_id($printify_product['id']);
            
            if ($existing_product) {
                // Update existing product
                $result = $this->update_product($existing_product->id, $product_data);
                return is_wp_error($result) ? $result : 'updated';
            } else {
                // Create new product
                $result = $this->create_product($product_data);
                return is_wp_error($result) ? $result : 'created';
            }
        } catch (Exception $e) {
            return new WP_Error('processing_error', sprintf(__('Error processing product %s: %s', 'printify-surecart-sync'), $printify_product['id'], $e->getMessage()));
        }
    }
}