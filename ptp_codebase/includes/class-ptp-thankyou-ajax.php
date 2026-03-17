<?php
/**
 * PTP Thank You Page AJAX Handlers v100
 * 
 * Handles AJAX requests for the enhanced thank you page:
 * - One-click upsell processing
 * - Social announcement saving
 * - Referral tracking
 * 
 * @since 100.0.0
 */

defined('ABSPATH') || exit;

class PTP_ThankYou_Ajax {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Upsell processing
        add_action('wp_ajax_ptp_process_thankyou_upsell', array($this, 'process_upsell'));
        add_action('wp_ajax_nopriv_ptp_process_thankyou_upsell', array($this, 'process_upsell'));
        
        // Track referral shares
        add_action('wp_ajax_ptp_track_thankyou_share', array($this, 'track_share'));
        add_action('wp_ajax_nopriv_ptp_track_thankyou_share', array($this, 'track_share'));
    }
    
    /**
     * Process one-click upsell
     */
    public function process_upsell() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_thankyou_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        
        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order ID.'));
        }
        
        // Get original order
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found.'));
        }
        
        // Check if upsell already purchased
        if ($order->get_meta('_ptp_upsell_purchased') === 'yes') {
            wp_send_json_error(array('message' => 'You\'ve already added this to your order!'));
        }
        
        // Get upsell product
        $upsell_product_id = get_option('ptp_thankyou_upsell_product_id', 0);
        $upsell_price = floatval(get_option('ptp_thankyou_upsell_price', 89));
        
        if (!$upsell_product_id) {
            // Try to find or create the upsell product
            $upsell_product_id = $this->get_or_create_upsell_product();
        }
        
        if (!$upsell_product_id) {
            wp_send_json_error(array('message' => 'Upsell product not configured. Please contact support.'));
        }
        
        $product = ptp_get_camp_product($upsell_product_id);
        if (!$product) {
            wp_send_json_error(array('message' => 'Product not found.'));
        }
        
        // Get customer info from original order
        $customer_id = $order->get_customer_id();
        $billing_email = $order->get_billing_email();
        
        // Try to charge using saved payment method (Stripe)
        $charge_result = $this->charge_saved_payment_method($order, $upsell_price);
        
        if (is_wp_error($charge_result)) {
            // If can't charge automatically, create a pending order
            $new_order = $this->create_upsell_order($order, $product, $upsell_price);
            
            if (is_wp_error($new_order)) {
                wp_send_json_error(array('message' => $new_order->get_error_message()));
            }
            
            // Mark original order
            $order->update_meta_data('_ptp_upsell_purchased', 'yes');
            $order->update_meta_data('_ptp_upsell_order_id', $new_order->get_id());
            $order->save();
            
            // Track conversion
            $this->track_upsell_conversion($order_id, $new_order->get_id(), $upsell_product_id, $upsell_price);
            
            wp_send_json_success(array(
                'message' => 'Private training session added! Check your email for details.',
                'order_id' => $new_order->get_id(),
                'charged' => false,
            ));
        }
        
        // Charge was successful
        $new_order = $this->create_upsell_order($order, $product, $upsell_price, true);
        
        if (is_wp_error($new_order)) {
            wp_send_json_error(array('message' => $new_order->get_error_message()));
        }
        
        // Add payment transaction to new order
        $new_order->set_transaction_id($charge_result['transaction_id'] ?? '');
        $new_order->add_order_note('Payment captured via one-click upsell. Transaction: ' . ($charge_result['transaction_id'] ?? 'N/A'));
        $new_order->save();
        
        // Mark original order
        $order->update_meta_data('_ptp_upsell_purchased', 'yes');
        $order->update_meta_data('_ptp_upsell_order_id', $new_order->get_id());
        $order->save();
        
        // Track conversion
        $this->track_upsell_conversion($order_id, $new_order->get_id(), $upsell_product_id, $upsell_price);
        
        wp_send_json_success(array(
            'message' => 'Private training session added and paid! Check your email for details.',
            'order_id' => $new_order->get_id(),
            'charged' => true,
        ));
    }
    
    /**
     * Create upsell order
     */
    private function create_upsell_order($original_order, $product, $price, $mark_paid = false) {
        try {
            $new_order = PTP_Native_Order_Manager::create_order(array(
                'customer_id' => $original_order->get_customer_id(),
                'status' => $mark_paid ? 'processing' : 'pending',
            ));
            
            if (is_wp_error($new_order)) {
                return $new_order;
            }
            
            // Add product
            $new_order->add_product($product, 1, array(
                'subtotal' => $price,
                'total' => $price,
            ));
            
            // Copy billing info
            $new_order->set_billing_first_name($original_order->get_billing_first_name());
            $new_order->set_billing_last_name($original_order->get_billing_last_name());
            $new_order->set_billing_email($original_order->get_billing_email());
            $new_order->set_billing_phone($original_order->get_billing_phone());
            $new_order->set_billing_address_1($original_order->get_billing_address_1());
            $new_order->set_billing_city($original_order->get_billing_city());
            $new_order->set_billing_state($original_order->get_billing_state());
            $new_order->set_billing_postcode($original_order->get_billing_postcode());
            $new_order->set_billing_country($original_order->get_billing_country());
            
            // Set payment method
            $new_order->set_payment_method($original_order->get_payment_method());
            $new_order->set_payment_method_title($original_order->get_payment_method_title());
            
            // Calculate totals
            $new_order->calculate_totals();
            
            // Add meta
            $new_order->update_meta_data('_ptp_is_upsell_order', 'yes');
            $new_order->update_meta_data('_ptp_original_order_id', $original_order->get_id());
            $new_order->update_meta_data('_ptp_upsell_source', 'thank_you_page');
            
            // Copy camper name if exists
            $camper_name = $original_order->get_meta('_camper_name');
            if ($camper_name) {
                $new_order->update_meta_data('_camper_name', $camper_name);
            }
            
            $new_order->add_order_note('Order created via thank you page one-click upsell. Original order: #' . $original_order->get_id());
            
            $new_order->save();
            
            // Send notification email
            // REMOVED: do_action('ptp-native_order_status_pending_to_processing_notification', $new_order->get_id(), $new_order);
            
            return $new_order;
            
        } catch (Exception $e) {
            return new WP_Error('order_creation_failed', $e->getMessage());
        }
    }
    
    /**
     * Charge saved payment method via Stripe
     */
    private function charge_saved_payment_method($order, $amount) {
        // Check if Stripe is available
        if (!false && !false) {
            return new WP_Error('stripe_not_available', 'Stripe not configured');
        }
        
        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            return new WP_Error('no_customer', 'Guest checkout - cannot charge saved method');
        }
        
        // Get Stripe customer ID
        $stripe_customer_id = get_user_meta($customer_id, '_stripe_customer_id', true);
        if (!$stripe_customer_id) {
            // Try alternate meta key
            $stripe_customer_id = get_user_meta($customer_id, 'ptp_stripe_customer', true);
        }
        
        if (!$stripe_customer_id) {
            return new WP_Error('no_stripe_customer', 'No saved payment method');
        }
        
        // Get saved payment method from original order
        $payment_method_id = $order->get_meta('_stripe_source_id');
        if (!$payment_method_id) {
            $payment_method_id = $order->get_meta('_stripe_payment_method_id');
        }
        
        if (!$payment_method_id) {
            return new WP_Error('no_payment_method', 'No payment method on file');
        }
        
        // Get Stripe secret key
        $stripe_settings = get_option('ptp-native_stripe_settings', array());
        $test_mode = isset($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes';
        $secret_key = $test_mode ? ($stripe_settings['test_secret_key'] ?? '') : ($stripe_settings['secret_key'] ?? '');
        
        if (!$secret_key) {
            return new WP_Error('no_stripe_key', 'Stripe not configured');
        }
        
        // Create payment intent
        try {
            $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ),
                'body' => array(
                    'amount' => intval($amount * 100), // Convert to cents
                    'currency' => strtolower(ptp_get_currency()),
                    'customer' => $stripe_customer_id,
                    'payment_method' => $payment_method_id,
                    'off_session' => 'true',
                    'confirm' => 'true',
                    'description' => 'PTP Private Training - Thank You Page Upsell',
                    'metadata' => array(
                        'original_order_id' => $order->get_id(),
                        'upsell_source' => 'thank_you_page',
                    ),
                ),
                'timeout' => 30,
            ));
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['error'])) {
                return new WP_Error('stripe_error', $body['error']['message'] ?? 'Payment failed');
            }
            
            if ($body['status'] === 'succeeded') {
                return array(
                    'success' => true,
                    'transaction_id' => $body['id'],
                    'charge_id' => $body['latest_charge'] ?? '',
                );
            }
            
            return new WP_Error('payment_not_complete', 'Payment requires additional action');
            
        } catch (Exception $e) {
            return new WP_Error('stripe_exception', $e->getMessage());
        }
    }
    
    /**
     * Get or create upsell product
     */
    private function get_or_create_upsell_product() {
        // Check if already set
        $product_id = get_option('ptp_thankyou_upsell_product_id', 0);
        if ($product_id && get_post($product_id)) {
            return $product_id;
        }
        
        // Try to find existing product by slug
        $existing = get_page_by_path('ptp-private-training-session', OBJECT, 'product');
        if ($existing) {
            update_option('ptp_thankyou_upsell_product_id', $existing->ID);
            return $existing->ID;
        }
        
        // Create new product
        $product = ptp_create_product();
        $product->set_name('Private Training Session (Thank You Page Offer)');
        $product->set_slug('ptp-private-training-session');
        $product->set_regular_price(get_option('ptp_thankyou_upsell_regular_price', 149));
        $product->set_sale_price(get_option('ptp_thankyou_upsell_price', 89));
        $product->set_description('One-on-one private training session with a PTP pro coach. Includes 60-minute session, video analysis, and skill assessment report.');
        $product->set_short_description('Private 1-on-1 training session with a pro coach.');
        $product->set_catalog_visibility('hidden');
        $product->set_virtual(true);
        $product->set_status('publish');
        $product->save();
        
        update_option('ptp_thankyou_upsell_product_id', $product->get_id());
        
        return $product->get_id();
    }
    
    /**
     * Track upsell conversion
     */
    private function track_upsell_conversion($original_order_id, $upsell_order_id, $product_id, $amount) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_upsell_conversions';
        
        // Create table if not exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                original_order_id bigint(20) UNSIGNED NOT NULL,
                upsell_order_id bigint(20) UNSIGNED NOT NULL,
                product_id bigint(20) UNSIGNED NOT NULL,
                amount decimal(10,2) NOT NULL,
                source varchar(50) DEFAULT 'thank_you_page',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY original_order_id (original_order_id)
            ) $charset;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        $wpdb->insert(
            $table,
            array(
                'original_order_id' => $original_order_id,
                'upsell_order_id' => $upsell_order_id,
                'product_id' => $product_id,
                'amount' => $amount,
                'source' => 'thank_you_page',
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%d', '%f', '%s', '%s')
        );
        
        // Fire action for analytics
        do_action('ptp_thankyou_upsell_conversion', array(
            'original_order_id' => $original_order_id,
            'upsell_order_id' => $upsell_order_id,
            'product_id' => $product_id,
            'amount' => $amount,
        ));
    }
    
    /**
     * Track share action
     */
    public function track_share() {
        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $order_id = intval($_POST['order_id'] ?? 0);
        $referral_code = sanitize_text_field($_POST['referral_code'] ?? '');
        
        if (!$platform) {
            wp_send_json_error();
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_shares';
        $charset = $wpdb->get_charset_collate();
        
        // Create table if not exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $sql = "CREATE TABLE $table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id bigint(20) UNSIGNED DEFAULT NULL,
                share_type varchar(50) NOT NULL DEFAULT 'thank_you_page',
                share_id bigint(20) UNSIGNED DEFAULT NULL,
                platform varchar(50) NOT NULL,
                share_url varchar(500) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY share_type (share_type),
                KEY platform (platform)
            ) $charset;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        $user_id = get_current_user_id();
        
        $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id ?: null,
                'share_type' => 'thank_you_page',
                'share_id' => $order_id,
                'platform' => $platform,
                'share_url' => home_url('/?ref=' . $referral_code),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%s', '%s', '%s')
        );
        
        wp_send_json_success();
    }
}

// Initialize immediately when file is loaded
// Loader handles timing via plugins_loaded
if (class_exists('PTP_ThankYou_Ajax')) {
    PTP_ThankYou_Ajax::instance();
}
