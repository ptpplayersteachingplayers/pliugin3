<?php
/**
 * PTP Stripe Product Ensure v1.0.0
 * 
 * ISSUE #2 FIX: Camp Stripe Product Sync Gap
 * 
 * Ensures camps have Stripe product/price IDs before checkout.
 * Creates products on-demand if they don't exist in the ptp_stripe_products table.
 * 
 * This prevents checkout failures when camps are added to cart without being
 * synced to Stripe first.
 * 
 * @since 160.0.0
 */

defined('ABSPATH') || exit;

class PTP_Stripe_Product_Ensure {
    
    private static $instance = null;
    private $secret_key;
    private $api_base = 'https://api.stripe.com/v1';
    
    /**
     * Get singleton instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->secret_key = get_option('ptp_stripe_secret_key', '');
        
        // Hook into cart operations to ensure products exist
        add_filter('ptp_before_add_camp_to_cart', array($this, 'ensure_camp_stripe_product'), 10, 2);
        add_action('ptp_cart_item_added', array($this, 'verify_cart_item_stripe_ids'), 10, 4);
        
        // Hook into checkout to verify all items have Stripe IDs
        add_filter('ptp_checkout_validate_cart', array($this, 'validate_cart_stripe_products'), 10, 1);
    }
    
    /**
     * Ensure a camp has Stripe product/price IDs before adding to cart
     * 
     * @param array $camp_data Camp data being added to cart
     * @param int $camp_id Camp post ID
     * @return array Modified camp data with Stripe IDs
     */
    public function ensure_camp_stripe_product($camp_data, $camp_id) {
        if (empty($this->secret_key)) {
            ptp_log('[PTP Stripe Ensure] No secret key configured, skipping product sync');
            return $camp_data;
        }
        
        // Check if we already have Stripe IDs
        if (!empty($camp_data['stripe_product_id']) && !empty($camp_data['stripe_price_id'])) {
            // Verify they're valid format
            if (strpos($camp_data['stripe_product_id'], 'prod_') === 0 && 
                strpos($camp_data['stripe_price_id'], 'price_') === 0) {
                return $camp_data;
            }
        }
        
        // Try to find existing Stripe product in our table
        $stripe_product = $this->get_stripe_product_by_camp_id($camp_id);
        
        if ($stripe_product && !empty($stripe_product->stripe_product_id) && !empty($stripe_product->stripe_price_id)) {
            // Verify the IDs are valid format
            if (strpos($stripe_product->stripe_product_id, 'prod_') === 0 && 
                strpos($stripe_product->stripe_price_id, 'price_') === 0) {
                $camp_data['stripe_product_id'] = $stripe_product->stripe_product_id;
                $camp_data['stripe_price_id'] = $stripe_product->stripe_price_id;
                return $camp_data;
            }
        }
        
        // Need to create Stripe product on-demand
        ptp_log("[PTP Stripe Ensure] Creating Stripe product for camp #{$camp_id} on-demand");
        
        $result = $this->create_stripe_product_for_camp($camp_id);
        
        if ($result && !is_wp_error($result)) {
            $camp_data['stripe_product_id'] = $result['product_id'];
            $camp_data['stripe_price_id'] = $result['price_id'];
            ptp_log("[PTP Stripe Ensure] Created Stripe product: {$result['product_id']} / price: {$result['price_id']}");
        } else {
            ptp_log("[PTP Stripe Ensure] Failed to create Stripe product for camp #{$camp_id}");
            if (is_wp_error($result)) {
                ptp_log("[PTP Stripe Ensure] Error: " . $result->get_error_message());
            }
        }
        
        return $camp_data;
    }
    
    /**
     * Verify cart item has Stripe IDs after being added
     */
    public function verify_cart_item_stripe_ids($cart_key, $item_id, $quantity, $item_type, $metadata = array()) {
        if ($item_type !== 'camp') {
            return;
        }
        
        // If metadata doesn't have Stripe IDs, try to add them
        if (empty($metadata['stripe_product_id']) || empty($metadata['stripe_price_id'])) {
            $stripe_product = $this->get_stripe_product_by_camp_id($item_id);
            
            if ($stripe_product && function_exists('ptp_cart')) {
                $cart = ptp_cart();
                $cart_item = $cart->get_cart_item($cart_key);
                
                if ($cart_item) {
                    $cart_item['metadata']['stripe_product_id'] = $stripe_product->stripe_product_id;
                    $cart_item['metadata']['stripe_price_id'] = $stripe_product->stripe_price_id;
                    // Force cart to save
                    $cart->save_cart(true);
                }
            }
        }
    }
    
    /**
     * Validate all cart items have Stripe products before checkout
     * 
     * @param array $cart_data Cart data from PTP_Cart_Helper
     * @return array|WP_Error Cart data or error if validation fails
     */
    public function validate_cart_stripe_products($cart_data) {
        if (empty($cart_data['woo_items']) && empty($cart_data['training_items'])) {
            return $cart_data;
        }
        
        $errors = array();
        
        // Check camp items
        foreach ($cart_data['woo_items'] as $key => $item) {
            if (($item['type'] ?? $item['is_camp'] ?? false) && !empty($item['product_id'])) {
                $camp_id = $item['product_id'];
                
                // Try to ensure Stripe product exists
                $stripe_product = $this->ensure_stripe_product_exists($camp_id);
                
                if (is_wp_error($stripe_product)) {
                    $errors[] = sprintf('Camp "%s" could not be processed for payment.', $item['name'] ?? 'Unknown');
                }
            }
        }
        
        if (!empty($errors)) {
            return new WP_Error('stripe_product_error', implode(' ', $errors));
        }
        
        return $cart_data;
    }
    
    /**
     * Get Stripe product from our database by camp ID
     */
    private function get_stripe_product_by_camp_id($camp_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return null;
        }
        
        // Try to find by woo_product_id (which stores camp post ID)
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE woo_product_id = %d AND active = 1",
            $camp_id
        ));
        
        if ($product) {
            return $product;
        }
        
        // Try to find by name match (fallback)
        $camp_title = get_the_title($camp_id);
        if ($camp_title) {
            $product = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE name = %s AND product_type = 'camp' AND active = 1",
                $camp_title
            ));
        }
        
        return $product;
    }
    
    /**
     * Ensure a Stripe product exists for a camp, creating if necessary
     */
    public function ensure_stripe_product_exists($camp_id) {
        // First check our database
        $existing = $this->get_stripe_product_by_camp_id($camp_id);
        
        if ($existing && !empty($existing->stripe_product_id) && !empty($existing->stripe_price_id)) {
            // Verify they're valid Stripe IDs
            if (strpos($existing->stripe_product_id, 'prod_') === 0 && 
                strpos($existing->stripe_price_id, 'price_') === 0) {
                return array(
                    'product_id' => $existing->stripe_product_id,
                    'price_id' => $existing->stripe_price_id,
                );
            }
            
            // Invalid IDs in database, need to recreate
            return $this->create_stripe_product_for_camp($camp_id, $existing->id);
        }
        
        // Need to create new
        return $this->create_stripe_product_for_camp($camp_id);
    }
    
    /**
     * Create Stripe product and price for a camp
     * 
     * @param int $camp_id Camp post ID
     * @param int|null $db_row_id Existing database row ID to update
     * @return array|WP_Error Result with product_id and price_id, or error
     */
    public function create_stripe_product_for_camp($camp_id, $db_row_id = null) {
        if (empty($this->secret_key)) {
            return new WP_Error('no_api_key', 'Stripe API key not configured');
        }
        
        // Get camp data
        $camp = get_post($camp_id);
        if (!$camp || $camp->post_type !== 'ptp_camp') {
            // Try getting as regular post (some setups use different post types)
            $camp = get_post($camp_id);
            if (!$camp) {
                return new WP_Error('invalid_camp', 'Camp not found');
            }
        }
        
        // Get pricing
        $price = floatval(get_post_meta($camp_id, '_camp_price', true)) ?: 525;
        $sale_price = floatval(get_post_meta($camp_id, '_camp_sale_price', true));
        $final_price = ($sale_price > 0) ? $sale_price : $price;
        $price_cents = intval($final_price * 100);
        
        // Get other metadata
        $camp_date = get_post_meta($camp_id, '_camp_date', true);
        $camp_location = get_post_meta($camp_id, '_camp_location', true);
        $camp_time = get_post_meta($camp_id, '_camp_time', true) ?: '9:00 AM - 3:00 PM';
        $image_url = get_the_post_thumbnail_url($camp_id, 'large');
        
        // Create Stripe product
        $product_data = array(
            'name' => $camp->post_title,
            'description' => wp_strip_all_tags(wp_trim_words($camp->post_content, 50, '...')),
            'metadata[camp_id]' => $camp_id,
            'metadata[camp_date]' => $camp_date,
            'metadata[camp_location]' => $camp_location,
            'metadata[source]' => 'ptp_auto_sync',
        );
        
        if ($image_url) {
            $product_data['images[0]'] = $image_url;
        }
        
        $product_response = $this->stripe_request('products', $product_data);
        
        if (is_wp_error($product_response)) {
            return $product_response;
        }
        
        if (empty($product_response['id'])) {
            return new WP_Error('stripe_error', 'Failed to create Stripe product');
        }
        
        $stripe_product_id = $product_response['id'];
        
        // Create Stripe price
        $price_data = array(
            'product' => $stripe_product_id,
            'currency' => 'usd',
            'unit_amount' => $price_cents,
            'metadata[camp_id]' => $camp_id,
        );
        
        $price_response = $this->stripe_request('prices', $price_data);
        
        if (is_wp_error($price_response)) {
            return $price_response;
        }
        
        if (empty($price_response['id'])) {
            return new WP_Error('stripe_error', 'Failed to create Stripe price');
        }
        
        $stripe_price_id = $price_response['id'];
        
        // Save to database
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        // Ensure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $this->create_stripe_products_table();
        }
        
        $data = array(
            'stripe_product_id' => $stripe_product_id,
            'stripe_price_id' => $stripe_price_id,
            'name' => $camp->post_title,
            'description' => wp_strip_all_tags(wp_trim_words($camp->post_content, 100, '...')),
            'price_cents' => $price_cents,
            'product_type' => 'camp',
            'camp_dates' => $camp_date,
            'camp_location' => $camp_location,
            'camp_time' => $camp_time,
            'image_url' => $image_url,
            'woo_product_id' => $camp_id,
            'active' => 1,
            'updated_at' => current_time('mysql'),
        );
        
        if ($db_row_id) {
            // Update existing row
            $wpdb->update($table, $data, array('id' => $db_row_id));
        } else {
            // Insert new row
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
        }
        
        return array(
            'product_id' => $stripe_product_id,
            'price_id' => $stripe_price_id,
        );
    }
    
    /**
     * Make request to Stripe API
     */
    private function stripe_request($endpoint, $data, $method = 'POST') {
        $url = $this->api_base . '/' . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'timeout' => 30,
        );
        
        if ($method === 'POST' && !empty($data)) {
            $args['body'] = http_build_query($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code >= 400) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'Stripe API error';
            return new WP_Error('stripe_error', $error_msg, array('code' => $code));
        }
        
        return $body;
    }
    
    /**
     * Create stripe_products table if it doesn't exist
     */
    private function create_stripe_products_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stripe_product_id varchar(255) NOT NULL,
            stripe_price_id varchar(255) DEFAULT NULL,
            trainer_id bigint(20) UNSIGNED DEFAULT 0,
            name varchar(255) NOT NULL,
            description text,
            price_cents int(11) DEFAULT 0,
            product_type varchar(50) DEFAULT 'camp',
            camp_dates varchar(100) DEFAULT NULL,
            camp_location varchar(255) DEFAULT NULL,
            camp_time varchar(100) DEFAULT NULL,
            camp_age_min int(11) DEFAULT NULL,
            camp_age_max int(11) DEFAULT NULL,
            camp_capacity int(11) DEFAULT NULL,
            camp_registered int(11) DEFAULT 0,
            image_url varchar(500) DEFAULT NULL,
            sort_order int(11) DEFAULT 0,
            is_featured tinyint(1) DEFAULT 0,
            active tinyint(1) DEFAULT 1,
            woo_product_id bigint(20) UNSIGNED DEFAULT NULL,
            sku varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY stripe_product_id (stripe_product_id),
            KEY stripe_price_id (stripe_price_id),
            KEY trainer_id (trainer_id),
            KEY product_type (product_type),
            KEY active (active),
            KEY woo_product_id (woo_product_id)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Get Stripe product data for checkout metadata
     * 
     * @param int $camp_id Camp post ID
     * @return array Stripe product data
     */
    public static function get_checkout_metadata($camp_id) {
        $instance = self::instance();
        $result = $instance->ensure_stripe_product_exists($camp_id);
        
        if (is_wp_error($result)) {
            return array(
                'stripe_product_id' => null,
                'stripe_price_id' => null,
                'error' => $result->get_error_message(),
            );
        }
        
        return array(
            'stripe_product_id' => $result['product_id'],
            'stripe_price_id' => $result['price_id'],
        );
    }
}

/**
 * Global helper function
 */
function ptp_ensure_stripe_product($camp_id) {
    return PTP_Stripe_Product_Ensure::instance()->ensure_stripe_product_exists($camp_id);
}

// Initialize on plugins_loaded
add_action('plugins_loaded', function() {
    PTP_Stripe_Product_Ensure::instance();
}, 8); // After PTP_Stripe_Product_Sync
