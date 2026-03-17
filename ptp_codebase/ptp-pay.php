<?php
/**
 * PTP Direct Payment Endpoint v113
 * Bypasses admin-ajax.php and REST API which may be blocked by firewalls
 * 
 * Access: POST to /wp-content/plugins/ptp-training-platform/ptp-pay.php
 * 
 * CHANGELOG v113:
 * - Improved error handling and logging
 * - Added idempotency key support
 * - Better nonce verification with multiple fallbacks
 * - Integrated with PTP_Stripe class when available
 * - Added rate limiting
 */

// Prevent direct output errors
error_reporting(0);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Load WordPress
$wp_load_paths = array(
    dirname(__FILE__) . '/../../../wp-load.php',
    dirname(__FILE__) . '/../../../../wp-load.php',
    $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',
);

$loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    echo json_encode(array('success' => false, 'data' => array('message' => 'WordPress not found')));
    exit;
}

// Get action and nonce
$action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';
$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

/**
 * Helper function to verify nonces with multiple fallbacks
 */
function ptp_pay_verify_nonce($nonce) {
    // Try multiple nonce actions for compatibility
    $valid_actions = array(
        'ptp_nonce',
        'ptp_checkout_action',
        'ptp_checkout',
        'ptp_cart_action',
    );
    
    foreach ($valid_actions as $action) {
        if (wp_verify_nonce($nonce, $action)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Helper function to get Stripe secret key
 */
function ptp_pay_get_stripe_key() {
    // Use PTP_Stripe class if available
    if (class_exists('PTP_Stripe')) {
        PTP_Stripe::init();
        if (PTP_Stripe::is_enabled()) {
            // Get from class (private, so we need to get from options)
            $test_mode = get_option('ptp_stripe_test_mode', true);
            if ($test_mode) {
                $key = get_option('ptp_stripe_test_secret', '');
            } else {
                $key = get_option('ptp_stripe_live_secret', '');
            }
            if (empty($key)) {
                $key = get_option('ptp_stripe_secret_key', '');
            }
            return $key;
        }
    }
    
    // Fallback to direct option retrieval
    $test_mode = get_option('ptp_stripe_test_mode', true);
    $key = $test_mode 
        ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
        : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
    
    return $key;
}

/**
 * Simple rate limiting
 */
function ptp_pay_check_rate_limit($action, $limit = 10, $window = 60) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'ptp_pay_rate_' . md5($action . $ip);
    
    $current = get_transient($key);
    
    if ($current === false) {
        set_transient($key, 1, $window);
        return true;
    }
    
    if ($current >= $limit) {
        return false;
    }
    
    set_transient($key, $current + 1, $window);
    return true;
}

// Handle actions
switch ($action) {
    
    case 'test':
        // Simple test - no auth required
        $cart_available = false && ptp_cart() ? 'yes' : 'no';
        $cart_total = 0;
        $cart_items = 0;
        
        if ($cart_available === 'yes') {
            ptp_cart()->calculate_totals();
            $cart_total = ptp_cart()->get_total('edit');
            $cart_items = ptp_cart()->get_item_count();
        }
        
        $secret_key = ptp_pay_get_stripe_key();
        $has_secret = !empty($secret_key);
        $test_mode = get_option('ptp_stripe_test_mode', true);
        
        echo json_encode(array(
            'success' => true,
            'data' => array(
                'status' => 'ok',
                'direct_endpoint' => true,
                'version' => '113.0.0',
                'ptp-native_cart' => $cart_available,
                'cart_total' => $cart_total,
                'cart_items' => $cart_items,
                'stripe_configured' => $has_secret ? 'yes' : 'no',
                'stripe_test_mode' => $test_mode ? 'yes' : 'no',
                'user_logged_in' => is_user_logged_in() ? 'yes' : 'no',
                'time' => current_time('mysql'),
            )
        ));
        break;
        
    case 'create_intent':
        // Rate limiting
        if (!ptp_pay_check_rate_limit('create_intent', 10, 60)) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Too many requests. Please wait a moment.')));
            exit;
        }
        
        // Verify nonce
        if (!ptp_pay_verify_nonce($nonce)) {
            ptp_log('PTP Pay: Nonce verification failed for create_intent');
            echo json_encode(array('success' => false, 'data' => array('message' => 'Session expired. Please refresh the page.')));
            exit;
        }
        
        // Try to use PTP_Stripe class first
        if (class_exists('PTP_Stripe') && PTP_Stripe::is_enabled()) {
            // Get amount from PTP Native cart
            if (!false || !ptp_cart()) {
                echo json_encode(array('success' => false, 'data' => array('message' => 'Cart not available')));
                exit;
            }
            
            ptp_cart()->calculate_totals();
            $total = floatval(ptp_cart()->get_total('edit'));
            
            if ($total < 0.50) {
                echo json_encode(array('success' => false, 'data' => array('message' => 'Order total must be at least $0.50')));
                exit;
            }
            
            // Create payment intent using PTP_Stripe
            $metadata = array(
                'source' => 'ptp_pay_direct',
                'site' => get_bloginfo('name'),
            );
            
            // Add customer info if available
            $pay_email = sanitize_email($_POST['email'] ?? $_POST['customer_email'] ?? '');
            $pay_name = sanitize_text_field($_POST['first_name'] ?? '') . ' ' . sanitize_text_field($_POST['last_name'] ?? '');
            $pay_name = trim($pay_name);
            $pay_phone = sanitize_text_field($_POST['phone'] ?? '');
            if (!empty($pay_email)) {
                $metadata['customer_email'] = $pay_email;
            }
            if (!empty($pay_name)) {
                $metadata['customer_name'] = $pay_name;
            }
            if (!empty($pay_phone)) {
                $metadata['customer_phone'] = $pay_phone;
            }
            
            $idempotency_key = 'ptp_pay_' . wp_generate_uuid4();
            $intent = PTP_Stripe::create_payment_intent($total, $metadata, $idempotency_key);
            
            if (is_wp_error($intent)) {
                ptp_log('PTP Pay: Stripe error - ' . $intent->get_error_message());
                echo json_encode(array('success' => false, 'data' => array('message' => $intent->get_error_message())));
                exit;
            }
            
            echo json_encode(array(
                'success' => true,
                'data' => array(
                    'clientSecret' => $intent['client_secret'],
                    'intentId' => $intent['id'],
                    'amount' => round($total * 100),
                    'currency' => 'usd'
                )
            ));
            exit;
        }
        
        // Fallback: Direct Stripe API call
        if (!false || !ptp_cart()) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Cart not available')));
            exit;
        }
        
        ptp_cart()->calculate_totals();
        $total = floatval(ptp_cart()->get_total('edit'));
        
        if ($total < 0.50) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Order total must be at least $0.50')));
            exit;
        }
        
        $secret_key = ptp_pay_get_stripe_key();
        
        if (empty($secret_key)) {
            ptp_log('PTP Pay: No Stripe secret key configured');
            echo json_encode(array('success' => false, 'data' => array('message' => 'Payment not configured. Please contact support.')));
            exit;
        }
        
        // Create payment intent via direct API call
        $amount = intval($total * 100);
        $currency = strtolower(ptp_get_currency());
        $idempotency_key = 'ptp_pay_' . wp_generate_uuid4();
        
        // Inline customer search/create (PTP_Stripe class not available in fallback)
        $fb_email = sanitize_email($_POST['email'] ?? $_POST['customer_email'] ?? '');
        $fb_first = sanitize_text_field($_POST['first_name'] ?? '');
        $fb_last = sanitize_text_field($_POST['last_name'] ?? '');
        $fb_name = trim($fb_first . ' ' . $fb_last);
        $fb_phone = sanitize_text_field($_POST['phone'] ?? '');
        $fb_customer_id = '';
        
        if (!empty($fb_email) && !empty($secret_key)) {
            // Search for existing customer
            $search_resp = wp_remote_get(
                'https://api.stripe.com/v1/customers/search?query=' . urlencode("email:'" . $fb_email . "'"),
                array(
                    'headers' => array('Authorization' => 'Bearer ' . $secret_key, 'Stripe-Version' => '2023-10-16'),
                    'timeout' => 15,
                )
            );
            if (!is_wp_error($search_resp)) {
                $search_data = json_decode(wp_remote_retrieve_body($search_resp), true);
                if (!empty($search_data['data'][0]['id'])) {
                    $fb_customer_id = $search_data['data'][0]['id'];
                    // Update name/phone
                    $upd = array();
                    if (!empty($fb_name)) $upd['name'] = $fb_name;
                    if (!empty($fb_phone)) $upd['phone'] = $fb_phone;
                    if (!empty($upd)) {
                        wp_remote_post('https://api.stripe.com/v1/customers/' . $fb_customer_id, array(
                            'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                            'body' => $upd, 'timeout' => 10,
                        ));
                    }
                }
            }
            // Create if not found
            if (empty($fb_customer_id)) {
                $create_data = array('email' => $fb_email, 'metadata[source]' => 'ptp_training');
                if (!empty($fb_name)) $create_data['name'] = $fb_name;
                if (!empty($fb_phone)) $create_data['phone'] = $fb_phone;
                $create_resp = wp_remote_post('https://api.stripe.com/v1/customers', array(
                    'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                    'body' => $create_data, 'timeout' => 15,
                ));
                if (!is_wp_error($create_resp)) {
                    $create_body = json_decode(wp_remote_retrieve_body($create_resp), true);
                    if (!empty($create_body['id'])) $fb_customer_id = $create_body['id'];
                }
            }
        }
        
        // Build payment intent body
        $pi_body = array(
            'amount' => $amount,
            'currency' => $currency,
            'automatic_payment_methods[enabled]' => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
            'metadata[site]' => get_bloginfo('name'),
            'metadata[source]' => 'ptp_pay_direct',
        );
        if (!empty($fb_customer_id)) $pi_body['customer'] = $fb_customer_id;
        if (!empty($fb_email)) {
            $pi_body['receipt_email'] = $fb_email;
            $pi_body['metadata[customer_email]'] = $fb_email;
        }
        if (!empty($fb_name)) $pi_body['metadata[customer_name]'] = $fb_name;
        
        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Idempotency-Key' => $idempotency_key,
                'Stripe-Version' => '2023-10-16',
            ),
            'body' => $pi_body,
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('PTP Pay: WP Error - ' . $response->get_error_message());
            echo json_encode(array('success' => false, 'data' => array('message' => 'Payment service unavailable. Please try again.')));
            exit;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            ptp_log('PTP Pay: Stripe error - ' . ($body['error']['message'] ?? 'Unknown error'));
            echo json_encode(array('success' => false, 'data' => array('message' => $body['error']['message'] ?? 'Payment error occurred')));
            exit;
        }
        
        if (empty($body['client_secret']) || empty($body['id'])) {
            ptp_log('PTP Pay: Invalid Stripe response - missing client_secret or id');
            echo json_encode(array('success' => false, 'data' => array('message' => 'Invalid payment response. Please try again.')));
            exit;
        }
        
        echo json_encode(array(
            'success' => true,
            'data' => array(
                'clientSecret' => $body['client_secret'],
                'intentId' => $body['id'],
                'amount' => $amount,
                'currency' => $currency
            )
        ));
        break;
        
    case 'process':
        // Rate limiting (stricter for payment processing)
        if (!ptp_pay_check_rate_limit('process', 5, 60)) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Too many requests. Please wait.')));
            exit;
        }
        
        // Verify nonce
        if (!ptp_pay_verify_nonce($nonce)) {
            ptp_log('PTP Pay: Nonce verification failed for process');
            echo json_encode(array('success' => false, 'data' => array('message' => 'Session expired. Please refresh the page.')));
            exit;
        }
        
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
        
        if (empty($payment_intent_id)) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Payment ID missing')));
            exit;
        }
        
        // Verify payment with Stripe before creating order
        $secret_key = ptp_pay_get_stripe_key();
        if (!empty($secret_key)) {
            $verify_response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                ),
                'timeout' => 30,
            ));
            
            if (!is_wp_error($verify_response)) {
                $verify_body = json_decode(wp_remote_retrieve_body($verify_response), true);
                if (isset($verify_body['status']) && $verify_body['status'] !== 'succeeded' && $verify_body['status'] !== 'processing') {
                    ptp_log('PTP Pay: Payment not successful - status: ' . $verify_body['status']);
                    echo json_encode(array('success' => false, 'data' => array('message' => 'Payment was not successful. Please try again.')));
                    exit;
                }
            }
        }
        
        // Get billing info
        $billing = array(
            'first_name' => sanitize_text_field($_POST['billing_first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['billing_last_name'] ?? ''),
            'email' => sanitize_email($_POST['billing_email'] ?? ''),
            'phone' => sanitize_text_field($_POST['billing_phone'] ?? ''),
        );
        
        if (!false || !ptp_cart()) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Cart not available')));
            exit;
        }
        
        ptp_cart()->calculate_totals();
        
        if (ptp_cart()->is_empty()) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Cart is empty')));
            exit;
        }
        
        // Create PTP Native order
        try {
            $order = PTP_Native_Order_Manager::create_order();
            
            foreach (ptp_cart()->get_items() as $cart_item) {
                $order->add_product($cart_item['data'], $cart_item['quantity']);
            }
            
            $order->set_billing_first_name($billing['first_name']);
            $order->set_billing_last_name($billing['last_name']);
            $order->set_billing_email($billing['email']);
            $order->set_billing_phone($billing['phone']);
            $order->set_payment_method('stripe');
            $order->set_payment_method_title('Credit Card');
            $order->set_transaction_id($payment_intent_id);
            $order->calculate_totals();
            $order->payment_complete($payment_intent_id);
            
            // Add order note
            $order->add_order_note('Payment completed via PTP Direct Pay endpoint. Payment Intent: ' . $payment_intent_id);
            
            ptp_cart()->clear();
            
            ptp_log('PTP Pay: Order created - ID: ' . $order->get_id() . ', Payment Intent: ' . $payment_intent_id);
            
            echo json_encode(array(
                'success' => true,
                'data' => array(
                    'order_id' => $order->get_id(),
                    'order_number' => $order->get_order_number(),
                    'redirect' => $order->get_checkout_order_received_url()
                )
            ));
        } catch (Exception $e) {
            ptp_log('PTP Pay: Order creation failed - ' . $e->getMessage());
            echo json_encode(array(
                'success' => false, 
                'data' => array(
                    'message' => 'Order creation failed. Your payment was received. Please contact support.',
                    'payment_intent_id' => $payment_intent_id
                )
            ));
        }
        break;
        
    case 'verify':
        // Verify a payment intent status
        if (!ptp_pay_verify_nonce($nonce)) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Session expired')));
            exit;
        }
        
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
        
        if (empty($payment_intent_id)) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Payment ID missing')));
            exit;
        }
        
        // Use PTP_Stripe if available
        if (class_exists('PTP_Stripe')) {
            $intent = PTP_Stripe::get_payment_intent($payment_intent_id);
            
            if (is_wp_error($intent)) {
                echo json_encode(array('success' => false, 'data' => array('message' => $intent->get_error_message())));
                exit;
            }
            
            echo json_encode(array(
                'success' => true,
                'data' => array(
                    'status' => $intent['status'],
                    'amount' => isset($intent['amount']) ? $intent['amount'] / 100 : 0,
                )
            ));
            exit;
        }
        
        // Fallback direct verification
        $secret_key = ptp_pay_get_stripe_key();
        if (empty($secret_key)) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Payment system not configured')));
            exit;
        }
        
        $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            echo json_encode(array('success' => false, 'data' => array('message' => 'Could not verify payment')));
            exit;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            echo json_encode(array('success' => false, 'data' => array('message' => $body['error']['message'] ?? 'Verification failed')));
            exit;
        }
        
        echo json_encode(array(
            'success' => true,
            'data' => array(
                'status' => $body['status'] ?? 'unknown',
                'amount' => isset($body['amount']) ? $body['amount'] / 100 : 0,
            )
        ));
        break;
        
    default:
        echo json_encode(array('success' => false, 'data' => array('message' => 'Invalid action')));
}

exit;
