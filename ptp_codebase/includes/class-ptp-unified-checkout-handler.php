<?php
/**
 * PTP Unified Checkout Handler v2.0.0
 * 
 * Consolidated checkout handler for all purchase types:
 * - Training only
 * - Camps/Products only (PTP Native)
 * - Bundle (Training + Camps)
 * 
 * Improvements in v2.0:
 * - Uses standardized nonces from PTP_Cart_Helper
 * - Proper transaction handling with rollback
 * - Stripe payment verification before confirming orders
 * - Single source of truth for cart state
 * - Consolidated discount calculation
 * 
 * @since 60.3.0
 */

defined('ABSPATH') || exit;

class PTP_Unified_Checkout_Handler {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Register shortcodes
        add_shortcode('ptp_cart', array($this, 'render_cart_shortcode'));
        add_shortcode('ptp_checkout', array($this, 'render_checkout_shortcode'));
        
        // AJAX handlers - Cart operations (use CART nonce)
        add_action('wp_ajax_ptp_update_cart_qty', array($this, 'ajax_update_cart_qty'));
        add_action('wp_ajax_nopriv_ptp_update_cart_qty', array($this, 'ajax_update_cart_qty'));
        
        // v154: Removed duplicate - PTP_Cart_Ajax::remove_cart_item() at priority 5 handles this with try-catch
        // add_action('wp_ajax_ptp_remove_cart_item', array($this, 'ajax_remove_cart_item'));
        // add_action('wp_ajax_nopriv_ptp_remove_cart_item', array($this, 'ajax_remove_cart_item'));
        
        // Note: ptp_remove_training_from_cart handled by PTP_Cart_Ajax class
        
        add_action('wp_ajax_ptp_remove_session_training', array($this, 'ajax_remove_session_training'));
        add_action('wp_ajax_nopriv_ptp_remove_session_training', array($this, 'ajax_remove_session_training'));
        
        add_action('wp_ajax_ptp_get_cart_data', array($this, 'ajax_get_cart_data'));
        add_action('wp_ajax_nopriv_ptp_get_cart_data', array($this, 'ajax_get_cart_data'));
        
        // AJAX handlers - Checkout operations (use CHECKOUT nonce)
        add_action('wp_ajax_ptp_process_unified_checkout', array($this, 'ajax_process_checkout'));
        add_action('wp_ajax_nopriv_ptp_process_unified_checkout', array($this, 'ajax_process_checkout'));
        
        add_action('wp_ajax_ptp_confirm_unified_checkout', array($this, 'ajax_confirm_checkout'));
        add_action('wp_ajax_nopriv_ptp_confirm_unified_checkout', array($this, 'ajax_confirm_checkout'));
        
        // Redirect PTP Native cart/checkout to custom pages
        add_action('template_redirect', array($this, 'redirect_checkout_pages'));
        
        // Override template for ptp-cart and ptp-checkout pages (NOT thank-you - handled by class-ptp-training-thankyou)
        add_action('template_redirect', array($this, 'override_page_templates'), 5);
        
        // v133: DISABLED - thank-you now handled by class-ptp-training-thankyou.php
        // add_action('template_redirect', array($this, 'thank_you_url_fallback'), 1);
        
        // Clear cache when cart changes
        // REMOVED: add_action('ptp-native_add_to_cart', array($this, 'on_cart_change'));
        // REMOVED: add_action('ptp-native_cart_item_removed', array($this, 'on_cart_change'));
        // REMOVED: add_action('ptp-native_cart_emptied', array($this, 'on_cart_change'));
    }
    
    /**
     * Override template for cart/checkout pages - renders full page and exits
     */
    public function override_page_templates() {
        global $post;
        
        if (!is_page() || !$post) {
            return;
        }
        
        $slug = strtolower(trim($post->post_name));
        
        // Check for cart page (handle variations)
        if (in_array($slug, array('ptp-cart', 'ptp_cart', 'cart', 'training-cart'))) {
            include PTP_PLUGIN_DIR . 'templates/ptp-cart.php';
            exit;
        }
        
        // Check for checkout page (handle variations)
        if (in_array($slug, array('ptp-checkout', 'ptp_checkout', 'checkout', 'training-checkout'))) {
            include PTP_PLUGIN_DIR . 'templates/ptp-checkout.php';
            exit;
        }
        
        // v133: Thank-you page handling MOVED to class-ptp-training-thankyou.php
        // The new handler catches /thank-you/ at init priority 1
    }
    
    /**
     * v132.8: URL-based fallback for thank-you page
     * This catches /thank-you/ even if no WordPress page exists
     */
    public function thank_you_url_fallback() {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($request_uri, PHP_URL_PATH);
        $path = trim($path, '/');
        
        // Check if URL matches thank-you patterns
        if (in_array($path, array('thank-you', 'thankyou', 'order-received', 'order-confirmation'))) {
            ptp_log('[PTP Thank You Handler v132.8] URL fallback triggered for: ' . $path);
            
            // v132.8.2: Auto-create the page if it doesn't exist (for future requests)
            $this->ensure_thank_you_page_exists();
            
            try {
                $template = PTP_PLUGIN_DIR . 'templates/thank-you-v100.php';
                if (file_exists($template)) {
                    include $template;
                } else {
                    $ty_fallback = PTP_PLUGIN_DIR . 'templates/thank-you.php';
                    if (file_exists($ty_fallback)) {
                        include $ty_fallback;
                    } else {
                        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Thank You - PTP</title><style>body{font-family:-apple-system,sans-serif;background:#0A0A0A;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;padding:20px;box-sizing:border-box}.ty{text-align:center;max-width:500px}.ty h1{font-size:48px;margin:0 0 16px}.ty h2{color:#FCB900;font-size:20px;margin:0 0 20px}.ty p{color:rgba(255,255,255,0.7);line-height:1.6;margin:0 0 20px}.ty a{display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 28px;font-weight:700;text-decoration:none;border-radius:8px}</style></head><body><div class="ty"><h1>&#10003;</h1><h2>Payment Received!</h2><p>Your booking is confirmed. Check your email for details.</p><a href="' . home_url() . '">Return Home</a></div></body></html>';
                    }
                }
            } catch (Throwable $e) {
                ptp_log('[PTP Thank You Handler] [' . get_class($e) . '] Error loading template: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                ptp_log('[PTP Thank You Handler] Stack trace: ' . $e->getTraceAsString());
                // v132.8.3: Show error details on screen for debugging (remove in production)
                $error_details = 'Error: ' . get_class($e) . ' - ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Thank You - PTP</title><style>body{font-family:-apple-system,sans-serif;background:#0A0A0A;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;padding:20px;box-sizing:border-box}.ty{text-align:center;max-width:500px}.ty h1{font-size:48px;margin:0 0 16px}.ty h2{color:#FCB900;font-size:20px;margin:0 0 20px}.ty p{color:rgba(255,255,255,0.7);line-height:1.6;margin:0 0 20px}.ty a{display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 28px;font-weight:700;text-decoration:none;border-radius:8px}.ty .err{font-size:11px;color:#ff6b6b;margin-top:20px;padding:10px;background:rgba(255,0,0,0.1);border-radius:4px;word-break:break-all}</style></head><body><div class="ty"><h1>✓</h1><h2>Payment Received!</h2><p>Your booking is confirmed. Check your email for details.</p><a href="' . home_url() . '">Return Home</a><div class="err">' . esc_html($error_details) . '</div></div></body></html>';
            }
            exit;
        }
    }
    
    /**
     * v132.8.2: Ensure thank-you page exists in WordPress
     */
    private function ensure_thank_you_page_exists() {
        // Check if page already exists
        $existing = get_page_by_path('thank-you', OBJECT, 'page');
        if ($existing) {
            return $existing->ID;
        }
        
        // Create the page
        $page_id = wp_insert_post(array(
            'post_title'     => 'Thank You',
            'post_name'      => 'thank-you',
            'post_content'   => '[ptp_thank_you]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ));
        
        if ($page_id && !is_wp_error($page_id)) {
            ptp_log('[PTP] Auto-created thank-you page with ID: ' . $page_id);
            // Flush rewrite rules on next page load
            update_option('ptp_flush_rewrite', '1');
        }
        
        return $page_id;
    }
    
    /**
     * Handle cart change - invalidate cache
     */
    public function on_cart_change() {
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::invalidate_cart_cache();
        }
    }
    
    /**
     * Render cart shortcode (fallback if template_redirect doesn't fire)
     */
    public function render_cart_shortcode($atts) {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/ptp-cart.php';
        return ob_get_clean();
    }
    
    /**
     * Render checkout shortcode (fallback if template_redirect doesn't fire)
     */
    public function render_checkout_shortcode($atts) {
        ob_start();
        // Use v77 bulletproof checkout template
        $template_file = PTP_PLUGIN_DIR . 'templates/ptp-checkout-v77.php';
        if (!file_exists($template_file)) {
            $template_file = PTP_PLUGIN_DIR . 'templates/ptp-checkout.php';
        }
        include $template_file;
        return ob_get_clean();
    }
    
    /**
     * Redirect PTP Native cart/checkout to custom pages
     */
    public function redirect_checkout_pages() {
        // Skip admin and AJAX
        if (is_admin() || wp_doing_ajax()) {
            return;
        }
        
        // Prevent redirect loops
        if (isset($_GET['ptp_no_redirect'])) {
            return;
        }
        
        // Check if custom pages exist
        $cart_page = get_page_by_path('ptp-cart');
        $checkout_page = get_page_by_path('ptp-checkout');
        
        if (!$cart_page || !$checkout_page) {
            ptp_log('PTP Checkout: Custom cart/checkout pages not found - redirects disabled');
            return;
        }
        
        // Redirect /cart to /ptp-cart
        if (function_exists('is_cart') && is_page('ptp-cart') && !is_page('ptp-cart')) {
            wp_safe_redirect(add_query_arg('ptp_no_redirect', '1', home_url('/ptp-cart/')));
            exit;
        }
        
        // Redirect /checkout to /ptp-checkout (but not endpoints like order-received)
        if (is_page('ptp-checkout') && !is_page('ptp-checkout') && !false) {
            wp_safe_redirect(add_query_arg('ptp_no_redirect', '1', home_url('/ptp-checkout/')));
            exit;
        }
    }
    
    /**
     * AJAX: Get cart data
     */
    public function ajax_get_cart_data() {
        $cart_data = class_exists('PTP_Cart_Helper') 
            ? PTP_Cart_Helper::get_cart_data(true) 
            : array();
        
        wp_send_json_success($cart_data);
    }
    
    /**
     * AJAX: Update cart quantity
     */
    public function ajax_update_cart_qty() {
        // Use standardized nonce verification
        if (class_exists('PTP_Cart_Helper') && !PTP_Cart_Helper::verify_cart_nonce()) {
            PTP_Cart_Helper::send_nonce_error();
            return;
        }
        
        $cart_item_key = sanitize_text_field($_POST['cart_key'] ?? $_POST['cart_item_key'] ?? '');
        $delta = intval($_POST['delta'] ?? 0);
        
        if (!$cart_item_key || !function_exists('ptp_cart') || !ptp_cart()) {
            wp_send_json_error(array('message' => 'Invalid request'));
            return;
        }
        
        $cart = ptp_cart();
        $cart_item = $cart->get_cart_item($cart_item_key);
        
        if (!$cart_item) {
            wp_send_json_error(array('message' => 'Item not found'));
            return;
        }
        
        $new_qty = $cart_item['quantity'] + $delta;
        
        if ($new_qty <= 0) {
            $cart->remove_cart_item($cart_item_key);
        } else {
            $cart->set_quantity($cart_item_key, $new_qty);
        }
        
        // Invalidate cache
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::invalidate_cart_cache();
        }
        
        wp_send_json_success(array(
            'message' => 'Cart updated',
            'cart' => class_exists('PTP_Cart_Helper') ? PTP_Cart_Helper::get_cart_data(true) : null
        ));
    }
    
    /**
     * AJAX: Remove cart item
     */
    public function ajax_remove_cart_item() {
        // v152.7.6: Fixed - removed !false bug
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        // Accept multiple nonce names for backwards compatibility
        $nonce_valid = wp_verify_nonce($nonce, 'ptp_cart_action') || 
                       wp_verify_nonce($nonce, 'ptp_camps_nonce');
        
        if (!$nonce_valid) {
            wp_send_json_error(array('message' => 'Security check failed', 'code' => 'nonce_failed'));
            return;
        }
        
        $cart_item_key = sanitize_text_field($_POST['cart_key'] ?? $_POST['cart_item_key'] ?? $_POST['item_key'] ?? '');
        
        if (empty($cart_item_key)) {
            wp_send_json_error(array('message' => 'No item specified'));
            return;
        }
        
        if (!function_exists('ptp_cart') || !ptp_cart()) {
            wp_send_json_error(array('message' => 'Cart not available'));
            return;
        }
        
        $removed = ptp_cart()->remove_cart_item($cart_item_key);
        
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::invalidate_cart_cache();
        }
        
        if ($removed) {
            wp_send_json_success(array(
                'message' => 'Item removed',
                'cart_count' => ptp_cart()->get_cart_contents_count()
            ));
        } else {
            wp_send_json_error(array('message' => 'Item not found in cart'));
        }
    }
    
    /**
     * AJAX: Remove training from cart
     */
    public function ajax_remove_training() {
        ptp_log('[PTP Cart] Remove training called');
        
        // Verify nonce
        if (class_exists('PTP_Cart_Helper') && !PTP_Cart_Helper::verify_cart_nonce()) {
            ptp_log('[PTP Cart] Nonce verification failed');
            PTP_Cart_Helper::send_nonce_error();
            return;
        }
        
        // Start session if not started
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        // Initialize WC session if needed
        if (false && ptp_session() && !ptp_session()->has_session()) {
            ptp_session()->set_customer_session_cookie(true);
        }
        
        $cleared = false;
        
        // Clear all training items from cart (database + session)
        if (class_exists('PTP_Cart_Helper')) {
            $cleared = PTP_Cart_Helper::clear_training_items();
            ptp_log('[PTP Cart] clear_training_items result: ' . ($cleared ? 'success' : 'failed'));
        }
        
        // Also clear PTP Native session directly as backup
        if (false && ptp_session()) {
            ptp_session()->set('ptp_active_bundle', null);
            ptp_session()->set('ptp_training_items', array());
            ptp_session()->set('ptp_bundle_code', null);
            ptp_session()->set('ptp_training_cart', array());
            ptp_log('[PTP Cart] WC session cleared');
        }
        
        // Clear PHP session as well
        if (isset($_SESSION['ptp_training_items'])) {
            unset($_SESSION['ptp_training_items']);
        }
        if (isset($_SESSION['ptp_bundle_code'])) {
            unset($_SESSION['ptp_bundle_code']);
        }
        if (isset($_SESSION['ptp_training_cart'])) {
            unset($_SESSION['ptp_training_cart']);
        }
        if (isset($_SESSION['ptp_active_bundle'])) {
            unset($_SESSION['ptp_active_bundle']);
        }
        
        // Invalidate cache
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::invalidate_cart_cache();
        }
        
        ptp_log('[PTP Cart] Training removed successfully');
        wp_send_json_success(array('message' => 'Training removed', 'cleared' => $cleared));
    }
    
    /**
     * AJAX: Remove specific session-based training item by index
     */
    public function ajax_remove_session_training() {
        ptp_log('[PTP Cart] Remove session training called');
        
        // Verify nonce
        if (class_exists('PTP_Cart_Helper') && !PTP_Cart_Helper::verify_cart_nonce()) {
            ptp_log('[PTP Cart] Nonce verification failed');
            PTP_Cart_Helper::send_nonce_error();
            return;
        }
        
        // Start session if not started
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        // Initialize WC session if needed
        if (false && ptp_session() && !ptp_session()->has_session()) {
            ptp_session()->set_customer_session_cookie(true);
        }
        
        $index = isset($_POST['index']) ? intval($_POST['index']) : -1;
        
        if ($index < 0) {
            wp_send_json_error(array('message' => 'Invalid index'));
            return;
        }
        
        // Get existing training items
        $training = array();
        if (false && ptp_session()) {
            $training = ptp_session()->get('ptp_training_items', array());
        }
        if (empty($training) && isset($_SESSION['ptp_training_items'])) {
            $training = $_SESSION['ptp_training_items'];
        }
        
        // Remove the item at the specified index
        if (is_array($training) && isset($training[$index])) {
            array_splice($training, $index, 1);
            
            // Save back to session
            $_SESSION['ptp_training_items'] = $training;
            if (false && ptp_session()) {
                ptp_session()->set('ptp_training_items', $training);
            }
            
            ptp_log('[PTP Cart] Session training item removed at index ' . $index);
        } else {
            ptp_log('[PTP Cart] No training item found at index ' . $index);
        }
        
        // Invalidate cache
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::invalidate_cart_cache();
        }
        
        wp_send_json_success(array('message' => 'Training removed'));
    }
    
    /**
     * AJAX: Process unified checkout
     * Creates PTP Native order and/or PTP booking, then creates Stripe payment intent
     */
    public function ajax_process_checkout() {
        ptp_log('[PTP Checkout] ========== PROCESSING CHECKOUT ==========');
        
        // Use standardized nonce verification
        if (class_exists('PTP_Cart_Helper') && !PTP_Cart_Helper::verify_checkout_nonce()) {
            ptp_log('[PTP Checkout] ERROR: Nonce verification failed');
            ptp_log('[PTP Checkout] Received nonce: ' . ($_POST['nonce'] ?? 'NONE'));
            PTP_Cart_Helper::send_nonce_error();
            return;
        }
        ptp_log('[PTP Checkout] Nonce verified OK');
        
        // Rate limiting
        if (class_exists('PTP_Cart_Helper')) {
            $rate_check = PTP_Cart_Helper::check_checkout_rate_limit();
            if (is_wp_error($rate_check)) {
                ptp_log('[PTP Checkout] ERROR: Rate limited');
                PTP_Cart_Helper::send_rate_limit_error($rate_check);
                return;
            }
        }
        
        global $wpdb;
        
        // Get cart data
        $cart_data = class_exists('PTP_Cart_Helper') 
            ? PTP_Cart_Helper::get_cart_data() 
            : array('has_camps' => false, 'has_training' => false, 'total' => 0);
        
        ptp_log('[PTP Checkout] Cart: camps=' . ($cart_data['has_camps'] ? 'yes' : 'no') . ' training=' . ($cart_data['has_training'] ? 'yes' : 'no') . ' total=' . ($cart_data['total'] ?? 0));
        
        $has_camps = $cart_data['has_camps'];
        $has_training = $cart_data['has_training'];
        $bundle_code = $cart_data['bundle_code'] ?? sanitize_text_field($_POST['bundle_code'] ?? '');
        
        ptp_log('[PTP Checkout] has_camps: ' . ($has_camps ? 'YES' : 'NO'));
        ptp_log('[PTP Checkout] has_training: ' . ($has_training ? 'YES' : 'NO'));
        ptp_log('[PTP Checkout] bundle_code: ' . $bundle_code);
        
        // Use calculated total from helper (single source of truth)
        $total = $cart_data['total'];
        
        // Fallback to POST total if cart is somehow empty
        if ($total <= 0) {
            $total = floatval($_POST['total'] ?? 0);
        }
        
        // Get customer data using helper
        $customer = class_exists('PTP_Cart_Helper')
            ? PTP_Cart_Helper::sanitize_customer_data()
            : array(
                'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
                'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
                'email' => sanitize_email($_POST['email'] ?? ''),
                'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            );
        
        // Validate customer data
        if (class_exists('PTP_Cart_Helper')) {
            $validation_errors = PTP_Cart_Helper::validate_customer_data($customer);
            if (!empty($validation_errors)) {
                wp_send_json_error(array('message' => implode(', ', $validation_errors)));
                return;
            }
        } else {
            if (empty($customer['first_name']) || empty($customer['email'])) {
                wp_send_json_error(array('message' => 'Please provide your name and email'));
                return;
            }
        }
        
        if ($total <= 0) {
            ptp_log('[PTP Checkout] ERROR: Invalid total: ' . $total);
            wp_send_json_error(array('message' => 'Invalid order total'));
            return;
        }
        
        ptp_log('[PTP Checkout] Final total: $' . $total);
        ptp_log('[PTP Checkout] Customer: ' . ($customer['email'] ?? 'unknown'));
        
        $order_id = null;
        $booking_id = null;
        
        // Start transaction for atomic operations
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::start_transaction();
        }
        
        try {
            // =============================================
            // STEP 1: Create PTP Native Order (if camps)
            // =============================================
            if ($has_camps && false && ptp_cart() && ptp_cart()->get_item_count() > 0) {
                $order_id = $this->create_ptp_native_order($customer, $has_training, $cart_data);
                
                if (is_wp_error($order_id)) {
                    throw new Exception($order_id->get_error_message());
                }
            }
            
            // =============================================
            // STEP 2: Create PTP Booking (if training)
            // =============================================
            if ($has_training && $bundle_code) {
                ptp_log('[PTP Checkout] Creating PTP booking for bundle: ' . $bundle_code);
                $booking_id = $this->create_ptp_booking($customer, $bundle_code);
                
                if (is_wp_error($booking_id)) {
                    ptp_log('[PTP Checkout] ERROR creating booking: ' . $booking_id->get_error_message());
                    throw new Exception($booking_id->get_error_message());
                }
                ptp_log('[PTP Checkout] Booking created: ID ' . $booking_id);
            }
            
            // =============================================
            // STEP 3: Create Stripe Payment Intent
            // =============================================
            ptp_log('[PTP Checkout] Creating Stripe payment intent...');
            ptp_log('[PTP Checkout] PTP_Stripe class exists: ' . (class_exists('PTP_Stripe') ? 'YES' : 'NO'));
            ptp_log('[PTP Checkout] PTP_Stripe enabled: ' . (class_exists('PTP_Stripe') && PTP_Stripe::is_enabled() ? 'YES' : 'NO'));
            
            if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
                ptp_log('[PTP Checkout] ERROR: Stripe not configured');
                throw new Exception('Payment processing is not configured');
            }
            
            $amount_cents = intval(round($total * 100));
            
            $metadata = array(
                'order_id' => $order_id ?: 0,
                'booking_id' => $booking_id ?: 0,
                'bundle_code' => $bundle_code,
                'customer_email' => $customer['email'],
                'customer_name' => trim($customer['first_name'] . ' ' . $customer['last_name']),
                'has_camps' => $has_camps ? 'yes' : 'no',
                'has_training' => $has_training ? 'yes' : 'no',
            );
            
            $payment_intent = PTP_Stripe::create_payment_intent($total, $metadata);
            
            ptp_log('[PTP Checkout] Stripe intent: ' . ($payment_intent['id'] ?? 'unknown') . ' status=' . ($payment_intent['status'] ?? 'unknown'));
            
            if (is_wp_error($payment_intent)) {
                ptp_log('[PTP Checkout] ERROR creating payment intent: ' . $payment_intent->get_error_message());
                throw new Exception('Payment setup failed: ' . $payment_intent->get_error_message());
            }
            
            ptp_log('[PTP Checkout] Payment intent ID: ' . $payment_intent['id']);
            
            // Store payment intent ID on records
            if ($order_id) {
                $order = PTP_Native_Order_Manager::get_order($order_id);
                if ($order) {
                    $order->update_meta_data('_ptp_payment_intent_id', $payment_intent['id']);
                    $order->save();
                }
            }
            
            if ($booking_id) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_bookings',
                    array('payment_intent_id' => $payment_intent['id']),
                    array('id' => $booking_id)
                );
            }
            
            // Update bundle with order/booking IDs
            if ($bundle_code) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_bundles',
                    array(
                        'camp_order_id' => $order_id ?: null,
                        'training_booking_id' => $booking_id ?: null,
                        'payment_intent_id' => $payment_intent['id'],
                    ),
                    array('bundle_code' => $bundle_code)
                );
            }
            
            // Commit transaction
            if (class_exists('PTP_Cart_Helper')) {
                PTP_Cart_Helper::commit_transaction();
            }
            
            ptp_log('[PTP Checkout] SUCCESS! Sending response...');
            ptp_log('[PTP Checkout] Order ID: ' . ($order_id ?: 'N/A'));
            ptp_log('[PTP Checkout] Booking ID: ' . ($booking_id ?: 'N/A'));
            ptp_log('[PTP Checkout] Payment Intent: ' . $payment_intent['id']);
            
            wp_send_json_success(array(
                'order_id' => $order_id,
                'booking_id' => $booking_id,
                'client_secret' => $payment_intent['client_secret'],
                'payment_intent_id' => $payment_intent['id'],
            ));
            
        } catch (Exception $e) {
            // Rollback transaction
            if (class_exists('PTP_Cart_Helper')) {
                PTP_Cart_Helper::rollback_transaction();
            }
            
            ptp_log('PTP Unified Checkout Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Create PTP Native order from cart
     */
    private function create_ptp_native_order($customer, $has_training, $cart_data) {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            $existing = get_user_by('email', $customer['email']);
            if ($existing) {
                $user_id = $existing->ID;
            }
        }
        
        $order = PTP_Native_Order_Manager::create_order(array(
            'customer_id' => $user_id,
            'status' => 'pending',
        ));
        
        if (is_wp_error($order)) {
            return $order;
        }
        
        // Add cart items
        foreach (ptp_cart()->get_items() as $cart_item) {
            $order->add_product($cart_item['data'], $cart_item['quantity']);
        }
        
        // Set billing address
        $order->set_billing_first_name($customer['first_name']);
        $order->set_billing_last_name($customer['last_name']);
        $order->set_billing_email($customer['email']);
        $order->set_billing_phone($customer['phone']);
        
        // Apply bundle discount if applicable (using helper)
        if ($has_training && !empty($cart_data['bundle_discount'])) {
            $discount_percent = PTP_Cart_Helper::get_bundle_discount_percent();
            $fee = ptp_create_order_fee();
            $fee->set_name('Bundle Discount (' . $discount_percent . '%)');
            
            // Calculate discount on WC portion only
            $ptp_discount = round($cart_data['cart_subtotal'] * ($discount_percent / 100), 2);
            $fee->set_amount(-$ptp_discount);
            $fee->set_total(-$ptp_discount);
            $order->add_item($fee);
        }
        
        $order->calculate_totals();
        
        // Save camper info
        if (isset($_POST['camper']) && is_array($_POST['camper'])) {
            $order->update_meta_data('_ptp_campers', array_map(function($camper) {
                return array(
                    'first_name' => sanitize_text_field($camper['first_name'] ?? ''),
                    'last_name' => sanitize_text_field($camper['last_name'] ?? ''),
                    'age' => intval($camper['age'] ?? 0),
                    'skill_level' => sanitize_text_field($camper['skill_level'] ?? ''),
                );
            }, $_POST['camper']));
        }
        
        // Save emergency info
        $order->update_meta_data('_ptp_emergency_name', sanitize_text_field($_POST['emergency_name'] ?? ''));
        $order->update_meta_data('_ptp_emergency_phone', sanitize_text_field($_POST['emergency_phone'] ?? ''));
        $order->update_meta_data('_ptp_emergency_relationship', sanitize_text_field($_POST['emergency_relationship'] ?? ''));
        $order->update_meta_data('_ptp_medical_info', sanitize_textarea_field($_POST['medical_info'] ?? ''));
        
        // Save waiver
        $order->update_meta_data('_ptp_waiver_signed', 'yes');
        $order->update_meta_data('_ptp_waiver_signature', sanitize_text_field($_POST['signature'] ?? ''));
        $order->update_meta_data('_ptp_waiver_date', current_time('mysql'));
        
        $order->save();
        
        return $order->get_id();
    }
    
    /**
     * Create PTP booking from bundle
     */
    private function create_ptp_booking($customer, $bundle_code) {
        global $wpdb;
        
        if (!class_exists('PTP_Bundle_Checkout')) {
            return new WP_Error('no_bundle_class', 'Bundle checkout not available');
        }
        
        $bundle_checkout = PTP_Bundle_Checkout::instance();
        $bundle = $bundle_checkout->get_bundle_by_code($bundle_code);
        
        if (!$bundle || !$bundle->trainer_id) {
            return new WP_Error('invalid_bundle', 'Bundle not found or invalid');
        }
        
        // Get or create parent
        $user_id = get_current_user_id();
        $parent_id = 0;
        
        if ($user_id && class_exists('PTP_Parent')) {
            $parent = PTP_Parent::get_by_user_id($user_id);
            if ($parent) {
                $parent_id = $parent->id;
            } else {
                $result = PTP_Parent::create($user_id, array(
                    'display_name' => trim($customer['first_name'] . ' ' . $customer['last_name']),
                    'phone' => $customer['phone'],
                ));
                if (!is_wp_error($result)) {
                    $parent_id = $result;
                }
            }
        }
        
        // Get player from first camper
        $player_id = 0;
        if (isset($_POST['camper'][1]) && $parent_id && class_exists('PTP_Player')) {
            $camper = $_POST['camper'][1];
            $result = PTP_Player::create($parent_id, array(
                'name' => sanitize_text_field(($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? '')),
                'age' => intval($camper['age'] ?? 0),
                'skill_level' => sanitize_text_field($camper['skill_level'] ?? 'intermediate'),
            ));
            if (!is_wp_error($result)) {
                $player_id = $result;
            }
        }
        
        // Get trainer for hourly_rate
        $trainer = PTP_Trainer::get($bundle->trainer_id);
        $hourly_rate = $trainer ? floatval($trainer->hourly_rate ?: 80) : 80;
        
        // Calculate end_time (sessions are 1 hour)
        $start_time = $bundle->training_time;
        $end_time = date('H:i:s', strtotime($start_time . ' +1 hour'));
        
        // Calculate graduated fee
        $session_number = ($parent_id && $bundle->trainer_id) ? PTP_Escrow::get_session_number($bundle->trainer_id, $parent_id) : 1;
        $fee_rate = PTP_Escrow::get_fee_rate($session_number);
        
        // Create booking
        // v236: Resolve location_notes (full address) from trainer's training_locations
        $loc_notes = '';
        if (!empty($bundle->training_location) && $trainer) {
            $tl_raw = !empty($trainer->training_locations) ? $trainer->training_locations : '';
            if (!empty($tl_raw)) {
                $tl = json_decode($tl_raw, true);
                if (!is_array($tl)) $tl = json_decode(wp_unslash($tl_raw), true);
                if (is_array($tl)) {
                    foreach ($tl as $_tl) {
                        if (is_array($_tl) && !empty($_tl['name']) && $_tl['name'] === $bundle->training_location && !empty($_tl['address'])) {
                            $loc_notes = $_tl['address'];
                            break;
                        }
                    }
                }
            }
        }
        $booking_data = array(
            'booking_number' => 'PTP-' . strtoupper(wp_generate_password(8, false, false)),
            'trainer_id' => $bundle->trainer_id,
            'parent_id' => $parent_id ?: 0,
            'player_id' => $player_id ?: 0,
            'session_date' => $bundle->training_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'duration_minutes' => 60,
            'location' => $bundle->training_location ?: '',
            'location_notes' => $loc_notes,
            'hourly_rate' => $hourly_rate,
            'total_amount' => $bundle->training_amount,
            'trainer_payout' => round($bundle->training_amount * (1 - $fee_rate), 2),
            'platform_fee' => round($bundle->training_amount * $fee_rate, 2),
            'status' => 'pending',
            'payment_status' => 'pending',
            'session_type' => $bundle->training_package ?: 'single',
            'session_count' => $bundle->training_sessions ?: 1,
            'sessions_remaining' => $bundle->training_sessions ?: 1,
            'notes' => 'Bundle checkout - Guest: ' . trim($customer['first_name'] . ' ' . $customer['last_name']) . ' (' . $customer['email'] . ')',
            'created_at' => current_time('mysql'),
        );
        
        $result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $booking_data);
        
        if (!$result) {
            return new WP_Error('booking_failed', 'Could not create booking: ' . $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * AJAX: Confirm unified checkout
     * Verifies payment and updates all records
     */
    public function ajax_confirm_checkout() {
        ptp_log('[PTP Confirm] ========== CONFIRMING CHECKOUT ==========');
        
        if (class_exists('PTP_Cart_Helper') && !PTP_Cart_Helper::verify_checkout_nonce()) {
            ptp_log('[PTP Confirm] ERROR: Nonce verification failed');
            PTP_Cart_Helper::send_nonce_error();
            return;
        }
        
        global $wpdb;
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
        
        ptp_log('[PTP Confirm] Order ID: ' . $order_id);
        ptp_log('[PTP Confirm] Booking ID: ' . $booking_id);
        ptp_log('[PTP Confirm] Payment Intent: ' . $payment_intent_id);
        
        if (empty($payment_intent_id)) {
            ptp_log('[PTP Confirm] ERROR: No payment intent ID');
            wp_send_json_error(array('message' => 'No payment information provided'));
            return;
        }
        
        // =============================================
        // CRITICAL: Verify payment with Stripe first
        // =============================================
        if (class_exists('PTP_Cart_Helper')) {
            $verification = PTP_Cart_Helper::verify_stripe_payment($payment_intent_id);
            
            if (is_wp_error($verification)) {
                ptp_log('PTP Checkout: Payment verification failed - ' . $verification->get_error_message());
                wp_send_json_error(array(
                    'message' => $verification->get_error_message(),
                    'code' => $verification->get_error_code()
                ));
                return;
            }
            
            if ($verification !== true) {
                wp_send_json_error(array('message' => 'Payment could not be verified'));
                return;
            }
        } else {
            // Fallback: Direct Stripe check
            if (class_exists('PTP_Stripe')) {
                $intent = PTP_Stripe::get_payment_intent($payment_intent_id);
                if (is_wp_error($intent) || ($intent['status'] ?? '') !== 'succeeded') {
                    wp_send_json_error(array('message' => 'Payment verification failed'));
                    return;
                }
            }
        }
        
        // Start transaction
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::start_transaction();
        }
        
        try {
            // =============================================
            // Update PTP Native order
            // =============================================
            if ($order_id) {
                $order = PTP_Native_Order_Manager::get_order($order_id);
                if ($order) {
                    $order->payment_complete($payment_intent_id);
                    $order->update_status('processing', 'Payment confirmed via PTP Checkout');
                    
                    // Clear PTP Native cart
                    if (false && ptp_cart()) {
                        ptp_cart()->clear();
                    }
                    
                    // v153: Fire camp order completed hook for email wiring
                    do_action('ptp_camp_order_completed', $order_id, $order);
                    
                    // v216.1: Removed direct PTP_Camp_Emails::send_order_confirmation() call here.
                    // The hook above triggers email wiring which has proper duplicate guards
                    // (in-memory + DB confirmation_sent column). The direct call was a v153 backup
                    // that caused parents to receive 2 identical confirmation emails.
                } else {
                    throw new Exception('Order not found');
                }
            }
            
            // =============================================
            // Update PTP booking
            // =============================================
            if ($booking_id) {
                $updated = $wpdb->update(
                    $wpdb->prefix . 'ptp_bookings',
                    array(
                        'status' => 'confirmed',
                        'payment_status' => 'paid',
                        'paid_at' => current_time('mysql'),
                    ),
                    array('id' => $booking_id)
                );
                
                if ($updated === false) {
                    throw new Exception('Could not update booking');
                }
                
                // Fire hook for Google Calendar integration etc.
                do_action('ptp_booking_confirmed', $booking_id);
                
                // v216.1: Create escrow hold for trainer payout system
                if (class_exists('PTP_Escrow')) {
                    $existing_escrow = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}ptp_escrow WHERE booking_id = %d",
                        $booking_id
                    ));
                    if (!$existing_escrow) {
                        $booking_amount = $wpdb->get_var($wpdb->prepare(
                            "SELECT total_amount FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                            $booking_id
                        ));
                        if ($booking_amount > 0) {
                            $escrow_id = PTP_Escrow::create_hold($booking_id, $payment_intent_id, $booking_amount);
                            if (is_wp_error($escrow_id)) {
                                ptp_log("[PTP Checkout Handler] Escrow creation failed for booking {$booking_id}: " . $escrow_id->get_error_message());
                            }
                        }
                    }
                }
                
                // Check if this is trainer's first booking
                $booking = $wpdb->get_row($wpdb->prepare(
                    "SELECT trainer_id FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                    $booking_id
                ));
                if ($booking && $booking->trainer_id) {
                    $trainer_booking_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                         WHERE trainer_id = %d AND status NOT IN ('cancelled', 'rejected')",
                        $booking->trainer_id
                    ));
                    if ($trainer_booking_count == 1) {
                        do_action('ptp_trainer_first_booking', $booking->trainer_id, $booking_id);
                    }
                }
                
                // v136: Removed direct email call - handled by ptp_training_booking_completed hook
                // PTP_Email::send_booking_confirmation is now triggered exclusively via Order Email Wiring
            }
            
            // =============================================
            // Update bundle status
            // =============================================
            if ($order_id || $booking_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ptp_bundles 
                     SET 
                         training_status = CASE WHEN training_booking_id = %d THEN 'completed' ELSE training_status END,
                         camp_status = CASE WHEN camp_order_id = %d THEN 'completed' ELSE camp_status END,
                         status = 'completed',
                         payment_status = 'paid',
                         completed_at = NOW()
                     WHERE (training_booking_id = %d OR camp_order_id = %d)
                       AND status IN ('active', 'partial')",
                    $booking_id, $order_id, $booking_id, $order_id
                ));
            }
            
            // Commit transaction
            if (class_exists('PTP_Cart_Helper')) {
                PTP_Cart_Helper::commit_transaction();
                PTP_Cart_Helper::invalidate_cart_cache();
            }
            
            // Build redirect URL
            $redirect = home_url('/order-confirmation/');
            
            // Try PTP Native thank you page first
            if ($order_id && false) {
                $order = PTP_Native_Order_Manager::get_order($order_id);
                if ($order) {
                    $redirect = $order->get_checkout_order_received_url();
                }
            }
            
            // Add booking to URL if present
            if ($booking_id) {
                $redirect = add_query_arg('booking', $booking_id, $redirect);
            }
            
            wp_send_json_success(array(
                'redirect' => $redirect,
                'order_id' => $order_id,
                'booking_id' => $booking_id,
                'message' => 'Payment confirmed successfully',
            ));
            
        } catch (Throwable $e) {
            // Rollback transaction
            if (class_exists('PTP_Cart_Helper')) {
                PTP_Cart_Helper::rollback_transaction();
            }
            
            ptp_log('PTP Confirm Checkout Error [' . get_class($e) . ']: ' . $e->getMessage());
            
            // Note: At this point payment succeeded but order update failed
            // This is a critical error that needs manual intervention
            ptp_log('CRITICAL: Payment ' . $payment_intent_id . ' succeeded but order update failed');
            
            wp_send_json_error(array(
                'message' => 'Order update failed. Your payment was received. Please contact support.',
                'payment_intent_id' => $payment_intent_id,
            ));
        }
    }
    
    /**
     * Create required pages on plugin activation
     */
    public static function create_pages() {
        // Cart page
        if (!get_page_by_path('ptp-cart')) {
            wp_insert_post(array(
                'post_title' => 'Cart',
                'post_name' => 'ptp-cart',
                'post_content' => '[ptp_cart]',
                'post_status' => 'publish',
                'post_type' => 'page',
            ));
        }
        
        // Checkout page
        if (!get_page_by_path('ptp-checkout')) {
            wp_insert_post(array(
                'post_title' => 'Checkout',
                'post_name' => 'ptp-checkout',
                'post_content' => '[ptp_checkout]',
                'post_status' => 'publish',
                'post_type' => 'page',
            ));
        }
        
        // Training landing page
        if (!get_page_by_path('training')) {
            wp_insert_post(array(
                'post_title' => 'Private Training',
                'post_name' => 'training',
                'post_content' => '[ptp_training]',
                'post_status' => 'publish',
                'post_type' => 'page',
            ));
        }
    }
}

// Initialize
function ptp_unified_checkout_handler() {
    return PTP_Unified_Checkout_Handler::instance();
}

add_action('init', 'ptp_unified_checkout_handler');

// Create pages on activation
if (defined('PTP_PLUGIN_FILE')) {
    register_activation_hook(PTP_PLUGIN_FILE, array('PTP_Unified_Checkout_Handler', 'create_pages'));
}
