<?php
/**
 * PTP Stripe Products
 * 
 * Creates and syncs REAL Stripe Products and Prices via the Stripe API.
 * Camps appear in Stripe Dashboard and can use Stripe Checkout Sessions.
 * 
 * @version 150.3.0
 */

defined('ABSPATH') || exit;

class PTP_Stripe_Products {
    
    private static $instance = null;
    private $secret_key;
    private $api_base = 'https://api.stripe.com/v1';
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->secret_key = get_option('ptp_stripe_secret_key', '');
        
        // Admin hooks
        add_action('admin_init', array($this, 'maybe_sync_all_products'));
        add_action('wp_ajax_ptp_sync_stripe_products', array($this, 'ajax_sync_all'));
        add_action('wp_ajax_ptp_create_stripe_product', array($this, 'ajax_create_product'));
        
        // Auto-sync when camp is saved
        add_action('save_post_ptp_camp', array($this, 'sync_camp_to_stripe'), 20, 2);
        
        // Webhook handler
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
    }
    
    /**
     * Make Stripe API request
     */
    private function api_request($endpoint, $method = 'GET', $data = array()) {
        if (empty($this->secret_key)) {
            return array('error' => array('message' => 'Stripe secret key not configured'));
        }
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'timeout' => 30,
        );
        
        if (!empty($data) && in_array($method, array('POST', 'PUT'))) {
            $args['body'] = $this->build_query($data);
        }
        
        $url = $this->api_base . $endpoint;
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return array('error' => array('message' => $response->get_error_message()));
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Build nested query string for Stripe API
     */
    private function build_query($data, $prefix = '') {
        $result = array();
        foreach ($data as $key => $value) {
            $new_key = $prefix ? "{$prefix}[{$key}]" : $key;
            if (is_array($value)) {
                $result[] = $this->build_query($value, $new_key);
            } else {
                $result[] = urlencode($new_key) . '=' . urlencode($value);
            }
        }
        return implode('&', $result);
    }
    
    /**
     * Create a Stripe Product
     */
    public function create_product($data) {
        $product_data = array(
            'name' => $data['name'],
            'type' => 'service',
            'metadata' => array(
                'source' => 'ptp_camps',
                'local_id' => $data['local_id'] ?? '',
                'product_type' => $data['product_type'] ?? 'camp',
                'camp_dates' => $data['camp_dates'] ?? '',
                'camp_location' => $data['camp_location'] ?? '',
                'camp_time' => $data['camp_time'] ?? '',
            ),
        );
        
        if (!empty($data['description'])) {
            $product_data['description'] = $data['description'];
        }
        
        if (!empty($data['image_url'])) {
            $product_data['images'] = array($data['image_url']);
        }
        
        $result = $this->api_request('/products', 'POST', $product_data);
        
        if (isset($result['id'])) {
            // Log success
            ptp_log("PTP Stripe: Created product {$result['id']} for {$data['name']}");
        }
        
        return $result;
    }
    
    /**
     * Update a Stripe Product
     */
    public function update_product($stripe_product_id, $data) {
        $product_data = array(
            'name' => $data['name'],
            'metadata' => array(
                'source' => 'ptp_camps',
                'local_id' => $data['local_id'] ?? '',
                'product_type' => $data['product_type'] ?? 'camp',
                'camp_dates' => $data['camp_dates'] ?? '',
                'camp_location' => $data['camp_location'] ?? '',
                'camp_time' => $data['camp_time'] ?? '',
            ),
        );
        
        if (!empty($data['description'])) {
            $product_data['description'] = $data['description'];
        }
        
        if (isset($data['active'])) {
            $product_data['active'] = $data['active'] ? 'true' : 'false';
        }
        
        return $this->api_request('/products/' . $stripe_product_id, 'POST', $product_data);
    }
    
    /**
     * Create a Stripe Price for a Product
     */
    public function create_price($stripe_product_id, $amount_cents, $currency = 'usd') {
        $price_data = array(
            'product' => $stripe_product_id,
            'unit_amount' => intval($amount_cents),
            'currency' => $currency,
            'metadata' => array(
                'source' => 'ptp_camps',
            ),
        );
        
        $result = $this->api_request('/prices', 'POST', $price_data);
        
        if (isset($result['id'])) {
            ptp_log("PTP Stripe: Created price {$result['id']} for product {$stripe_product_id}");
        }
        
        return $result;
    }
    
    /**
     * Update price by creating a new one (Stripe prices are immutable)
     * Archives the old price and creates a new one
     */
    public function update_price($old_price_id, $stripe_product_id, $new_amount_cents) {
        // Archive old price
        if (!empty($old_price_id)) {
            $this->api_request('/prices/' . $old_price_id, 'POST', array('active' => 'false'));
        }
        
        // Create new price
        return $this->create_price($stripe_product_id, $new_amount_cents);
    }
    
    /**
     * Get a Stripe Product
     */
    public function get_product($stripe_product_id) {
        return $this->api_request('/products/' . $stripe_product_id);
    }
    
    /**
     * Get a Stripe Price
     */
    public function get_price($stripe_price_id) {
        return $this->api_request('/prices/' . $stripe_price_id);
    }
    
    /**
     * List all PTP products from Stripe
     */
    public function list_products($limit = 100) {
        return $this->api_request('/products', 'GET', array(
            'limit' => $limit,
            'active' => 'true',
        ));
    }
    
    /**
     * Sync a local camp product to Stripe
     * Creates product + price if doesn't exist, updates if it does
     */
    public function sync_product_to_stripe($local_product) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        $stripe_product_id = $local_product['stripe_product_id'] ?? '';
        $stripe_price_id = $local_product['stripe_price_id'] ?? '';
        $local_id = $local_product['id'];
        
        // Check if this is a real Stripe ID (starts with prod_)
        $is_real_stripe_product = !empty($stripe_product_id) && strpos($stripe_product_id, 'prod_') === 0 && strpos($stripe_product_id, 'prod_ptp_') !== 0;
        
        $product_data = array(
            'name' => $local_product['name'],
            'description' => $local_product['description'] ?? '',
            'local_id' => $local_id,
            'product_type' => $local_product['product_type'] ?? 'camp',
            'camp_dates' => $local_product['camp_dates'] ?? '',
            'camp_location' => $local_product['camp_location'] ?? '',
            'camp_time' => $local_product['camp_time'] ?? '',
            'image_url' => $local_product['image_url'] ?? '',
            'active' => ($local_product['active'] ?? 1) == 1,
        );
        
        // Create or update product
        if ($is_real_stripe_product) {
            // Update existing Stripe product
            $product_result = $this->update_product($stripe_product_id, $product_data);
        } else {
            // Create new Stripe product
            $product_result = $this->create_product($product_data);
            
            if (isset($product_result['id'])) {
                $stripe_product_id = $product_result['id'];
                
                // Update local record with real Stripe ID
                $wpdb->update(
                    $table,
                    array('stripe_product_id' => $stripe_product_id),
                    array('id' => $local_id)
                );
            }
        }
        
        if (isset($product_result['error'])) {
            return $product_result;
        }
        
        // Handle price
        $price_cents = intval($local_product['price_cents'] ?? 0);
        if ($price_cents > 0) {
            $is_real_stripe_price = !empty($stripe_price_id) && strpos($stripe_price_id, 'price_') === 0;
            
            if ($is_real_stripe_price) {
                // Check if price amount changed
                $existing_price = $this->get_price($stripe_price_id);
                if (isset($existing_price['unit_amount']) && $existing_price['unit_amount'] != $price_cents) {
                    // Price changed - create new price
                    $price_result = $this->update_price($stripe_price_id, $stripe_product_id, $price_cents);
                    if (isset($price_result['id'])) {
                        $stripe_price_id = $price_result['id'];
                        $wpdb->update(
                            $table,
                            array('stripe_price_id' => $stripe_price_id),
                            array('id' => $local_id)
                        );
                    }
                }
            } else {
                // Create new price
                $price_result = $this->create_price($stripe_product_id, $price_cents);
                if (isset($price_result['id'])) {
                    $stripe_price_id = $price_result['id'];
                    $wpdb->update(
                        $table,
                        array('stripe_price_id' => $stripe_price_id),
                        array('id' => $local_id)
                    );
                }
            }
        }
        
        return array(
            'success' => true,
            'stripe_product_id' => $stripe_product_id,
            'stripe_price_id' => $stripe_price_id,
        );
    }
    
    /**
     * Sync ALL local products to Stripe
     */
    public function sync_all_products() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        // Check table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array('error' => 'Products table does not exist');
        }
        
        $products = $wpdb->get_results("SELECT * FROM $table WHERE active = 1", ARRAY_A);
        
        $results = array(
            'total' => count($products),
            'synced' => 0,
            'errors' => 0,
            'details' => array(),
        );
        
        foreach ($products as $product) {
            $result = $this->sync_product_to_stripe($product);
            
            if (isset($result['success']) && $result['success']) {
                $results['synced']++;
                $results['details'][] = array(
                    'name' => $product['name'],
                    'status' => 'synced',
                    'stripe_product_id' => $result['stripe_product_id'],
                    'stripe_price_id' => $result['stripe_price_id'],
                );
            } else {
                $results['errors']++;
                $results['details'][] = array(
                    'name' => $product['name'],
                    'status' => 'error',
                    'message' => $result['error']['message'] ?? 'Unknown error',
                );
            }
        }
        
        return $results;
    }
    
    /**
     * URL trigger for syncing all products
     */
    public function maybe_sync_all_products() {
        if (!isset($_GET['ptp_sync_stripe']) || !current_user_can('manage_options')) {
            return;
        }
        
        $results = $this->sync_all_products();
        
        $message = sprintf(
            'Stripe Sync Complete: %d/%d products synced. %d errors.',
            $results['synced'],
            $results['total'],
            $results['errors']
        );
        
        add_action('admin_notices', function() use ($message, $results) {
            $class = $results['errors'] > 0 ? 'notice-warning' : 'notice-success';
            echo "<div class='notice {$class} is-dismissible'><p>✅ {$message}</p></div>";
        });
    }
    
    /**
     * AJAX handler for syncing all products
     */
    public function ajax_sync_all() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $results = $this->sync_all_products();
        wp_send_json_success($results);
    }
    
    /**
     * AJAX handler for creating a single product
     */
    public function ajax_create_product() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $local_id = intval($_POST['local_id'] ?? 0);
        if (!$local_id) {
            wp_send_json_error('No product ID provided');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        $product = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $local_id), ARRAY_A);
        
        if (!$product) {
            wp_send_json_error('Product not found');
        }
        
        $result = $this->sync_product_to_stripe($product);
        
        if (isset($result['success']) && $result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error']['message'] ?? 'Sync failed');
        }
    }
    
    /**
     * Sync ptp_camp post to Stripe when saved
     */
    public function sync_camp_to_stripe($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Get camp data
        $price = (float) get_post_meta($post_id, '_camp_price', true) ?: 525;
        $sale_price = (float) get_post_meta($post_id, '_camp_sale_price', true);
        $final_price = ($sale_price > 0) ? $sale_price : $price;
        
        $product_data = array(
            'id' => 'post_' . $post_id,
            'stripe_product_id' => get_post_meta($post_id, '_stripe_product_id', true),
            'stripe_price_id' => get_post_meta($post_id, '_stripe_price_id', true),
            'name' => $post->post_title,
            'description' => wp_strip_all_tags($post->post_excerpt ?: $post->post_content),
            'price_cents' => intval($final_price * 100),
            'product_type' => 'camp',
            'camp_dates' => get_post_meta($post_id, '_camp_date', true),
            'camp_location' => get_post_meta($post_id, '_camp_location', true),
            'camp_time' => get_post_meta($post_id, '_camp_time', true),
            'image_url' => get_the_post_thumbnail_url($post_id, 'large'),
            'active' => true,
        );
        
        $result = $this->sync_product_to_stripe_from_post($product_data, $post_id);
        
        if (isset($result['stripe_product_id'])) {
            update_post_meta($post_id, '_stripe_product_id', $result['stripe_product_id']);
        }
        if (isset($result['stripe_price_id'])) {
            update_post_meta($post_id, '_stripe_price_id', $result['stripe_price_id']);
        }
    }
    
    /**
     * Sync from post meta (different from database table)
     */
    private function sync_product_to_stripe_from_post($data, $post_id) {
        $stripe_product_id = $data['stripe_product_id'];
        $stripe_price_id = $data['stripe_price_id'];
        
        // Check if real Stripe product
        $is_real_stripe_product = !empty($stripe_product_id) && strpos($stripe_product_id, 'prod_') === 0 && strpos($stripe_product_id, 'prod_ptp_') !== 0;
        
        $product_data = array(
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'local_id' => 'post_' . $post_id,
            'product_type' => 'camp',
            'camp_dates' => $data['camp_dates'] ?? '',
            'camp_location' => $data['camp_location'] ?? '',
            'camp_time' => $data['camp_time'] ?? '',
            'image_url' => $data['image_url'] ?? '',
            'active' => true,
        );
        
        if ($is_real_stripe_product) {
            $product_result = $this->update_product($stripe_product_id, $product_data);
        } else {
            $product_result = $this->create_product($product_data);
            if (isset($product_result['id'])) {
                $stripe_product_id = $product_result['id'];
            }
        }
        
        if (isset($product_result['error'])) {
            ptp_log('PTP Stripe: Error syncing post ' . $post_id . ': ' . $product_result['error']['message']);
            return array('error' => $product_result['error']);
        }
        
        // Handle price
        $price_cents = intval($data['price_cents'] ?? 0);
        if ($price_cents > 0 && !empty($stripe_product_id)) {
            $is_real_stripe_price = !empty($stripe_price_id) && strpos($stripe_price_id, 'price_') === 0;
            
            if ($is_real_stripe_price) {
                $existing_price = $this->get_price($stripe_price_id);
                if (isset($existing_price['unit_amount']) && $existing_price['unit_amount'] != $price_cents) {
                    $price_result = $this->update_price($stripe_price_id, $stripe_product_id, $price_cents);
                    if (isset($price_result['id'])) {
                        $stripe_price_id = $price_result['id'];
                    }
                }
            } else {
                $price_result = $this->create_price($stripe_product_id, $price_cents);
                if (isset($price_result['id'])) {
                    $stripe_price_id = $price_result['id'];
                }
            }
        }
        
        return array(
            'success' => true,
            'stripe_product_id' => $stripe_product_id,
            'stripe_price_id' => $stripe_price_id,
        );
    }
    
    /**
     * Register webhook endpoint for Stripe product updates
     */
    public function register_webhook_endpoint() {
        register_rest_route('ptp/v1', '/stripe-products-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * Handle Stripe webhooks for product updates
     */
    public function handle_webhook($request) {
        $payload = $request->get_body();
        $sig_header = $request->get_header('stripe-signature');
        $webhook_secret = get_option('ptp_stripe_products_webhook_secret', '');
        
        // Verify signature if secret is set
        if (!empty($webhook_secret)) {
            // Simple signature verification
            $elements = explode(',', $sig_header);
            $timestamp = null;
            $signatures = array();
            
            foreach ($elements as $element) {
                $parts = explode('=', $element, 2);
                if ($parts[0] === 't') {
                    $timestamp = $parts[1];
                } elseif ($parts[0] === 'v1') {
                    $signatures[] = $parts[1];
                }
            }
            
            if (empty($timestamp) || empty($signatures)) {
                return new WP_Error('invalid_signature', 'Invalid signature', array('status' => 400));
            }
            
            $signed_payload = $timestamp . '.' . $payload;
            $expected_sig = hash_hmac('sha256', $signed_payload, $webhook_secret);
            
            if (!in_array($expected_sig, $signatures)) {
                return new WP_Error('invalid_signature', 'Signature verification failed', array('status' => 400));
            }
        }
        
        $event = json_decode($payload, true);
        
        if (!isset($event['type']) || !isset($event['data']['object'])) {
            return new WP_Error('invalid_payload', 'Invalid event payload', array('status' => 400));
        }
        
        $type = $event['type'];
        $object = $event['data']['object'];
        
        switch ($type) {
            case 'product.updated':
            case 'product.created':
                $this->handle_product_update($object);
                break;
                
            case 'product.deleted':
                $this->handle_product_deleted($object);
                break;
                
            case 'price.updated':
            case 'price.created':
                $this->handle_price_update($object);
                break;
        }
        
        return array('received' => true);
    }
    
    /**
     * Handle product update from Stripe
     */
    private function handle_product_update($product) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        // Only handle products from PTP
        if (!isset($product['metadata']['source']) || $product['metadata']['source'] !== 'ptp_camps') {
            return;
        }
        
        $stripe_product_id = $product['id'];
        
        // Update local record
        $wpdb->update(
            $table,
            array(
                'name' => $product['name'],
                'description' => $product['description'] ?? '',
                'active' => $product['active'] ? 1 : 0,
                'updated_at' => current_time('mysql'),
            ),
            array('stripe_product_id' => $stripe_product_id)
        );
        
        ptp_log("PTP Stripe: Webhook updated product {$stripe_product_id}");
    }
    
    /**
     * Handle product deleted from Stripe
     */
    private function handle_product_deleted($product) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        $stripe_product_id = $product['id'];
        
        // Mark as inactive locally (don't delete)
        $wpdb->update(
            $table,
            array('active' => 0, 'updated_at' => current_time('mysql')),
            array('stripe_product_id' => $stripe_product_id)
        );
        
        ptp_log("PTP Stripe: Webhook marked product {$stripe_product_id} as inactive");
    }
    
    /**
     * Handle price update from Stripe
     */
    private function handle_price_update($price) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        if (!isset($price['product'])) {
            return;
        }
        
        $stripe_product_id = $price['product'];
        $stripe_price_id = $price['id'];
        $price_cents = $price['unit_amount'] ?? 0;
        
        // Update local record
        $wpdb->update(
            $table,
            array(
                'stripe_price_id' => $stripe_price_id,
                'price_cents' => $price_cents,
                'updated_at' => current_time('mysql'),
            ),
            array('stripe_product_id' => $stripe_product_id)
        );
        
        ptp_log("PTP Stripe: Webhook updated price for product {$stripe_product_id}");
    }
    
    /**
     * Create Checkout Session using Stripe Products
     */
    public function create_checkout_session($line_items, $success_url, $cancel_url, $metadata = array()) {
        $data = array(
            'mode' => 'payment',
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'line_items' => $line_items,
            'metadata' => array_merge(array('source' => 'ptp_camps'), $metadata),
        );
        
        // Add customer email if logged in
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $data['customer_email'] = $user->user_email;
        }
        
        return $this->api_request('/checkout/sessions', 'POST', $data);
    }
    
    /**
     * Build line items for Checkout Session from cart
     */
    public function build_line_items_from_cart($cart_items) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        $line_items = array();
        
        foreach ($cart_items as $item) {
            $stripe_price_id = null;
            
            // Get Stripe price ID
            if (!empty($item['stripe_price_id']) && strpos($item['stripe_price_id'], 'price_') === 0) {
                $stripe_price_id = $item['stripe_price_id'];
            } elseif (!empty($item['id'])) {
                // Look up from database
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT stripe_price_id FROM $table WHERE id = %d",
                    $item['id']
                ));
                if ($product && !empty($product->stripe_price_id)) {
                    $stripe_price_id = $product->stripe_price_id;
                }
            }
            
            if (!empty($stripe_price_id)) {
                $line_items[] = array(
                    'price' => $stripe_price_id,
                    'quantity' => $item['quantity'] ?? 1,
                );
            }
        }
        
        return $line_items;
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Stripe_Products::instance();
}, 20);
