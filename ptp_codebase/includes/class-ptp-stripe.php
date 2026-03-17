<?php
/**
 * PTP Stripe Payment Integration v113
 * Handles payments, refunds, and Connect payouts
 * 
 * CHANGELOG v113:
 * - Improved payment intent creation with better error handling
 * - Added idempotency key support to prevent duplicate charges
 * - Better logging for debugging payment issues
 * - Standardized API response handling
 * - Added payment intent retrieval caching
 */

defined('ABSPATH') || exit;

class PTP_Stripe {
    
    private static $secret_key;
    private static $publishable_key;
    private static $webhook_secret;
    private static $connect_enabled = false;
    private static $test_mode = true;
    private static $initialized = false;
    
    // Cache for payment intents to avoid duplicate API calls
    private static $intent_cache = array();
    
    public static function init() {
        if (self::$initialized) return;
        
        self::$test_mode = get_option('ptp_stripe_test_mode', true);
        
        if (self::$test_mode) {
            self::$secret_key = get_option('ptp_stripe_test_secret', '');
            self::$publishable_key = get_option('ptp_stripe_test_publishable', '');
        } else {
            self::$secret_key = get_option('ptp_stripe_live_secret', '');
            self::$publishable_key = get_option('ptp_stripe_live_publishable', '');
        }
        
        // Fallback to legacy option names
        if (empty(self::$secret_key)) {
            self::$secret_key = get_option('ptp_stripe_secret_key', '');
        }
        if (empty(self::$publishable_key)) {
            self::$publishable_key = get_option('ptp_stripe_publishable_key', '');
        }
        
        self::$webhook_secret = get_option('ptp_stripe_webhook_secret', '');
        self::$connect_enabled = (bool) get_option('ptp_stripe_connect_enabled', false);
        
        self::$initialized = true;
        
        // Register webhook endpoint
        add_action('rest_api_init', array(__CLASS__, 'register_webhook'));
        
        // Add Stripe JS
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }
    
    /**
     * Ensure initialization (call before any API method)
     */
    private static function ensure_init() {
        if (!self::$initialized) {
            self::init();
        }
    }
    
    /**
     * Check if Stripe is configured
     */
    public static function is_enabled() {
        self::ensure_init();
        return !empty(self::$secret_key) && !empty(self::$publishable_key);
    }
    
    /**
     * Check if Connect is enabled
     */
    public static function is_connect_enabled() {
        self::ensure_init();
        return self::$connect_enabled && self::is_enabled();
    }
    
    /**
     * Get configuration status for debugging
     */
    public static function get_config_status() {
        self::ensure_init();
        return array(
            'test_mode' => self::$test_mode,
            'has_secret_key' => !empty(self::$secret_key),
            'has_publishable_key' => !empty(self::$publishable_key),
            'connect_enabled' => self::$connect_enabled,
            'is_enabled' => self::is_enabled(),
        );
    }
    
    /**
     * Get publishable key for frontend
     */
    public static function get_publishable_key() {
        self::ensure_init();
        return self::$publishable_key;
    }
    
    /**
     * Enqueue Stripe JS
     */
    public static function enqueue_scripts() {
        if (!self::is_enabled()) return;
        
        // Only load on checkout/cart pages
        if (is_page(array('checkout', 'ptp-checkout', 'book-session', 'ptp-cart'))) {
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
            wp_localize_script('ptp-frontend', 'ptpStripe', array(
                'publishableKey' => self::$publishable_key,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ptp_nonce'),
            ));
        }
    }
    
    /**
     * Make Stripe API request with improved error handling
     */
    private static function api_request($endpoint, $method = 'POST', $data = array(), $idempotency_key = null) {
        self::ensure_init();
        
        if (empty(self::$secret_key)) {
            ptp_log('PTP Stripe API: No secret key configured');
            return new WP_Error('no_api_key', 'Stripe API key not configured');
        }
        
        $url = 'https://api.stripe.com/v1/' . $endpoint;
        
        $headers = array(
            'Authorization' => 'Bearer ' . self::$secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Stripe-Version' => '2023-10-16', // Pin API version for consistency
        );
        
        // Add idempotency key to prevent duplicate charges
        if ($idempotency_key) {
            $headers['Idempotency-Key'] = $idempotency_key;
        }
        
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 60,
        );
        
        if ($method === 'POST' && !empty($data)) {
            $args['body'] = $data;
        }
        
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log("PTP Stripe API: $method $endpoint");
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            ptp_log('PTP Stripe API: WP Error - ' . $response->get_error_message());
            return $response;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log("PTP Stripe API: Response code $http_code");
        }
        
        if (isset($body['error'])) {
            $error_message = $body['error']['message'] ?? 'Unknown Stripe error';
            $error_code = $body['error']['code'] ?? 'stripe_error';
            $error_type = $body['error']['type'] ?? 'api_error';
            
            ptp_log("PTP Stripe API Error [$error_type/$error_code]: $error_message");
            
            return new WP_Error($error_code, $error_message, $body['error']);
        }
        
        return $body;
    }
    
    /**
     * Create Payment Intent with idempotency support
     * Funds held in platform account until session confirmed
     */
    public static function create_payment_intent($amount, $metadata = array(), $idempotency_key = null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log('PTP Stripe: create_payment_intent called with amount ' . $amount);
        }
        
        if (!self::is_enabled()) {
            ptp_log('PTP Stripe: Not enabled - check API keys');
            return new WP_Error('stripe_not_configured', 'Stripe is not configured. Please check API keys in PTP Settings.');
        }
        
        // Validate amount
        $amount = floatval($amount);
        if ($amount < 0.50) {
            return new WP_Error('invalid_amount', 'Payment amount must be at least $0.50');
        }
        
        // Generate idempotency key if not provided (prevents duplicate charges on retry)
        if (!$idempotency_key && !empty($metadata['booking_id'])) {
            $idempotency_key = 'ptp_booking_' . $metadata['booking_id'] . '_' . time();
        } elseif (!$idempotency_key) {
            $idempotency_key = 'ptp_' . wp_generate_uuid4();
        }
        
        $data = array(
            'amount' => round($amount * 100), // Convert to cents
            'currency' => 'usd',
            'automatic_payment_methods[enabled]' => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
        );
        
        // Add metadata as properly formatted keys
        if (!empty($metadata)) {
            foreach ($metadata as $key => $value) {
                if (!empty($value)) {
                    $data["metadata[{$key}]"] = is_array($value) ? json_encode($value) : $value;
                }
            }
        }
        
        // Add site identifier
        $data['metadata[site]'] = get_bloginfo('name');
        $data['metadata[environment]'] = self::$test_mode ? 'test' : 'live';
        
        // Attach real Stripe Customer to payment intent
        $customer_email = !empty($metadata['customer_email']) ? $metadata['customer_email'] : '';
        $customer_name = !empty($metadata['customer_name']) ? $metadata['customer_name'] : '';
        $customer_phone = !empty($metadata['customer_phone']) ? $metadata['customer_phone'] : '';
        
        if ($customer_email) {
            $stripe_customer_id = self::find_or_create_customer_by_email($customer_email, $customer_name, $customer_phone);
            if ($stripe_customer_id) {
                $data['customer'] = $stripe_customer_id;
            }
            $data['receipt_email'] = $customer_email;
        }
        
        // Add transfer group for tracking (but don't transfer yet)
        if (!empty($metadata['booking_id'])) {
            $data['transfer_group'] = 'booking_' . $metadata['booking_id'];
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log('PTP Stripe: Creating payment intent with idempotency key: ' . $idempotency_key);
        }
        
        $result = self::api_request('payment_intents', 'POST', $data, $idempotency_key);
        
        if (is_wp_error($result)) {
            ptp_log('PTP Stripe: Payment intent creation failed - ' . $result->get_error_message());
            return $result;
        }
        
        if (empty($result['id']) || empty($result['client_secret'])) {
            ptp_log('PTP Stripe: Invalid response - missing id or client_secret');
            return new WP_Error('invalid_response', 'Invalid response from Stripe');
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log('PTP Stripe: Payment intent created - ID: ' . $result['id']);
        }
        
        // Cache the result
        self::$intent_cache[$result['id']] = $result;
        
        return $result;
    }
    
    /**
     * Confirm Payment Intent
     */
    public static function confirm_payment_intent($payment_intent_id) {
        return self::api_request("payment_intents/{$payment_intent_id}/confirm", 'POST');
    }
    
    /**
     * Retrieve Payment Intent with caching
     */
    public static function get_payment_intent($payment_intent_id) {
        if (empty($payment_intent_id)) {
            return new WP_Error('no_intent_id', 'Payment intent ID is required');
        }
        
        // Check cache first
        if (isset(self::$intent_cache[$payment_intent_id])) {
            return self::$intent_cache[$payment_intent_id];
        }
        
        $result = self::api_request("payment_intents/{$payment_intent_id}", 'GET');
        
        if (!is_wp_error($result)) {
            self::$intent_cache[$payment_intent_id] = $result;
        }
        
        return $result;
    }
    
    /**
     * Create refund
     */
    public static function create_refund($payment_intent_id, $amount = null, $reason = 'requested_by_customer') {
        $data = array(
            'payment_intent' => $payment_intent_id,
            'reason' => $reason,
        );
        
        if ($amount) {
            $data['amount'] = round($amount * 100);
        }
        
        return self::api_request('refunds', 'POST', $data);
    }
    
    /**
     * Create Stripe Connect account for trainer (Express - works for individuals)
     * Trainers don't need to be a business - they can receive payments as individuals
     */
    public static function create_connect_account($trainer_id, $email, $user_data = array()) {
        self::ensure_init();
        
        if (!self::is_enabled()) {
            return new WP_Error('stripe_not_configured', 'Stripe API keys are not configured');
        }
        
        if (!self::$connect_enabled) {
            return new WP_Error('connect_not_enabled', 'Stripe Connect is not enabled. Please enable it in WordPress Admin → PTP Settings → Stripe');
        }
        
        // Get trainer info for better onboarding
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        // Express accounts are perfect for individuals - no business required
        // They handle tax forms, identity verification, etc. automatically
        $data = array(
            'type' => 'express',
            'country' => 'US',
            'email' => $email,
            'capabilities[card_payments][requested]' => 'true',
            'capabilities[transfers][requested]' => 'true',
            'business_type' => 'individual', // Key: This allows individuals, not just businesses
            'metadata[trainer_id]' => $trainer_id,
            'metadata[platform]' => 'ptp_training',
            'settings[payouts][schedule][interval]' => 'daily', // Fast payouts
            'settings[payouts][schedule][delay_days]' => 2, // 2-day rolling
        );
        
        // Add business profile for better UX
        if ($trainer) {
            $data['business_profile[name]'] = $trainer->display_name . ' Training';
            $data['business_profile[product_description]'] = 'Private 1-on-1 training sessions';
            $data['business_profile[mcc]'] = '7941'; // Sports instruction
            $data['business_profile[url]'] = home_url('/trainer/' . $trainer->slug . '/');
        }
        
        $account = self::api_request('accounts', 'POST', $data);
        
        if (is_wp_error($account)) {
            ptp_log('PTP Stripe Connect Error: ' . $account->get_error_message());
            return $account;
        }
        
        // Save account ID to trainer
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'stripe_account_id' => $account['id'],
                'stripe_charges_enabled' => 0,
                'stripe_payouts_enabled' => 0,
            ),
            array('id' => $trainer_id)
        );
        
        return $account;
    }
    
    /**
     * Create Connect account onboarding link with proper return URLs
     */
    public static function create_account_link($account_id, $refresh_url = null, $return_url = null) {
        if (!$refresh_url) {
            $refresh_url = home_url('/trainer-onboarding/?stripe_refresh=1');
        }
        if (!$return_url) {
            $return_url = home_url('/trainer-dashboard/?tab=earnings&stripe_connected=1');
        }
        
        $data = array(
            'account' => $account_id,
            'refresh_url' => $refresh_url,
            'return_url' => $return_url,
            'type' => 'account_onboarding',
            'collect' => 'eventually_due', // Collect only required info upfront
        );
        
        return self::api_request('account_links', 'POST', $data);
    }
    
    /**
     * Complete onboarding flow - get or create account then return link
     */
    public static function start_connect_onboarding($trainer_id) {
        self::ensure_init();
        
        if (!self::is_enabled()) {
            return new WP_Error('stripe_not_configured', 'Stripe API keys are not configured');
        }
        
        if (!self::$connect_enabled) {
            return new WP_Error('connect_not_enabled', 'Stripe Connect is not enabled');
        }
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email 
             FROM {$wpdb->prefix}ptp_trainers t
             JOIN {$wpdb->prefix}users u ON t.user_id = u.ID
             WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('trainer_not_found', 'Trainer not found');
        }
        
        $account_id = $trainer->stripe_account_id;
        
        // Create account if doesn't exist
        if (!$account_id) {
            $account = self::create_connect_account($trainer_id, $trainer->user_email);
            
            if (is_wp_error($account)) {
                return $account;
            }
            
            $account_id = $account['id'];
        }
        
        // Check if account needs onboarding
        $account_status = self::get_account($account_id);
        
        if (is_wp_error($account_status)) {
            // Account might have been deleted, create new one
            $account = self::create_connect_account($trainer_id, $trainer->user_email);
            if (is_wp_error($account)) {
                return $account;
            }
            $account_id = $account['id'];
        }
        
        // Create onboarding link
        $link = self::create_account_link($account_id);
        
        if (is_wp_error($link)) {
            return $link;
        }
        
        return array(
            'url' => $link['url'],
            'account_id' => $account_id,
        );
    }
    
    /**
     * Create login link for Connect dashboard
     */
    public static function create_login_link($account_id) {
        return self::api_request("accounts/{$account_id}/login_links", 'POST');
    }
    
    /**
     * Get Connect account status
     */
    public static function get_account($account_id) {
        return self::api_request("accounts/{$account_id}", 'GET');
    }
    
    /**
     * Create direct payout to trainer (if not using Connect)
     */
    public static function create_payout($trainer_id, $amount) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d
        ", $trainer_id));
        
        if (!$trainer) {
            return new WP_Error('trainer_not_found', 'Trainer not found');
        }
        
        // If using Connect, transfer to connected account
        if (self::$connect_enabled && $trainer->stripe_account_id) {
            $data = array(
                'amount' => round($amount * 100),
                'currency' => 'usd',
                'destination' => $trainer->stripe_account_id,
                'metadata[trainer_id]' => $trainer_id,
            );
            
            return self::api_request('transfers', 'POST', $data);
        }
        
        // Otherwise, log for manual payout
        return array(
            'status' => 'pending_manual',
            'amount' => $amount,
            'trainer_id' => $trainer_id,
            'message' => 'Payout queued for manual processing',
        );
    }
    
    /**
     * Create transfer to connected account (alias for create_payout)
     */
    public static function create_transfer($amount_cents, $destination_account_id, $description = '', $idempotency_key = null) {
        $data = array(
            'amount' => $amount_cents,
            'currency' => 'usd',
            'destination' => $destination_account_id,
            'description' => $description,
        );
        
        return self::api_request('transfers', 'POST', $data, $idempotency_key);
    }
    
    /**
     * Process booking payment
     */
    public static function process_booking_payment($booking_id, $payment_method_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, t.stripe_account_id, t.display_name as trainer_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) {
            return new WP_Error('booking_not_found', 'Booking not found');
        }
        
        // Create payment intent
        $intent = self::create_payment_intent($booking->total_amount, array(
            'booking_id' => $booking_id,
            'trainer_id' => $booking->trainer_id,
            'trainer_stripe_account' => $booking->stripe_account_id,
        ));
        
        if (is_wp_error($intent)) {
            return $intent;
        }
        
        // Confirm payment
        $confirm = self::api_request("payment_intents/{$intent['id']}/confirm", 'POST', array(
            'payment_method' => $payment_method_id,
        ));
        
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        
        // Update booking with payment info
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'payment_intent_id' => $intent['id'],
                'payment_status' => $confirm['status'] === 'succeeded' ? 'paid' : $confirm['status'],
            ),
            array('id' => $booking_id)
        );
        
        return $confirm;
    }
    
    /**
     * Handle webhook events
     */
    public static function register_webhook() {
        register_rest_route('ptp/v1', '/stripe-webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));
    }
    
    public static function handle_webhook($request) {
        $payload = $request->get_body();
        $sig_header = $request->get_header('stripe-signature');
        
        // Verify webhook signature
        if (empty(self::$webhook_secret)) {
            ptp_log('[PTP Stripe Webhook] REJECTED: Webhook secret not configured. Set ptp_stripe_webhook_secret in PTP Settings.');
            return new WP_Error('webhook_not_configured', 'Webhook not configured', array('status' => 500));
        }
        
        if (empty($sig_header)) {
            return new WP_Error('missing_signature', 'Missing Stripe-Signature header', array('status' => 400));
        }

        $timestamp = null;
        $signature = null;
        
        foreach (explode(',', $sig_header) as $part) {
            $parts = explode('=', $part, 2);
            if (count($parts) !== 2) continue;
            list($key, $value) = $parts;
            if ($key === 't') $timestamp = $value;
            if ($key === 'v1') $signature = $value;
        }
        
        if (!$timestamp || !$signature) {
            return new WP_Error('invalid_signature', 'Malformed Stripe-Signature header', array('status' => 400));
        }
        
        // Reject if timestamp is more than 5 minutes old (replay protection)
        if (abs(time() - intval($timestamp)) > 300) {
            return new WP_Error('timestamp_expired', 'Webhook timestamp too old', array('status' => 400));
        }
        
        $signed_payload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signed_payload, self::$webhook_secret);
        
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('invalid_signature', 'Invalid webhook signature', array('status' => 400));
        }
        
        $event = json_decode($payload, true);
        
        if (!$event || !isset($event['type'])) {
            return new WP_Error('invalid_payload', 'Invalid webhook payload', array('status' => 400));
        }
        
        global $wpdb;
        
        switch ($event['type']) {
            case 'payment_intent.succeeded':
                $payment_intent = $event['data']['object'];
                $booking_id = $payment_intent['metadata']['booking_id'] ?? null;
                $pi_id = $payment_intent['id'] ?? 'unknown';
                
                ptp_log("[PTP Stripe Webhook] payment_intent.succeeded: PI={$pi_id}, booking_id={$booking_id}");
                
                if ($booking_id) {
                    // Update payment status
                    $update_data = array('payment_status' => 'paid');
                    
                    // If webhook has package metadata and booking doesn't have session info yet, add it
                    $md = $payment_intent['metadata'] ?? array();
                    if (!empty($md['package']) && !empty($md['sessions'])) {
                        $pkg_key = $md['package'];
                        if (class_exists('PTP_Packages')) {
                            $pkg_key = PTP_Packages::resolve_key($pkg_key);
                        }
                        $sessions = intval($md['sessions']);
                        $existing = $wpdb->get_row($wpdb->prepare(
                            "SELECT session_type, session_count FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                            $booking_id
                        ));
                        // Only fill in if still default
                        if ($existing && ($existing->session_type === 'single' || empty($existing->session_type)) && intval($existing->session_count) <= 1 && $sessions > 1) {
                            $update_data['session_type'] = $pkg_key;
                            $update_data['session_count'] = $sessions;
                            $update_data['sessions_remaining'] = max(0, $sessions - 1);
                        }
                    }
                    
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        $update_data,
                        array('id' => $booking_id)
                    );
                    ptp_log("[PTP Stripe Webhook] Updated booking {$booking_id} payment_status to paid");
                    
                    // v216.1: Create escrow hold if not already created
                    if (class_exists('PTP_Escrow')) {
                        $existing_escrow = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}ptp_escrow WHERE booking_id = %d",
                            $booking_id
                        ));
                        if (!$existing_escrow) {
                            $booking_for_escrow = $wpdb->get_row($wpdb->prepare(
                                "SELECT total_amount FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                                $booking_id
                            ));
                            if ($booking_for_escrow && $booking_for_escrow->total_amount > 0) {
                                $escrow_id = PTP_Escrow::create_hold($booking_id, $pi_id, $booking_for_escrow->total_amount);
                                if (is_wp_error($escrow_id)) {
                                    ptp_log("[PTP Stripe Webhook] Escrow creation failed for booking {$booking_id}: " . $escrow_id->get_error_message());
                                } else {
                                    ptp_log("[PTP Stripe Webhook] Escrow created: {$escrow_id} for booking {$booking_id}");
                                }
                            }
                        }
                    }
                    
                    // v136: Send emails via hook only (removed direct calls to prevent duplicates)
                    // The ptp_training_booking_completed hook triggers Order Email Wiring
                    ptp_log("[PTP Stripe Webhook] Booking {$booking_id} confirmed - emails will be sent via hook");
                    
                    // Send SMS if enabled (SMS has its own dedup via ptp_booking_confirmed hook)
                    if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
                        PTP_SMS::send_booking_confirmation($booking_id);
                        PTP_SMS::send_trainer_new_booking($booking_id);
                        ptp_log("[PTP Stripe Webhook] SMS notifications sent for booking {$booking_id}");
                    }
                    
                    // v152.7.5: Dispatch action for email wiring system
                    do_action('ptp_stripe_webhook_payment_intent.succeeded', $event);
                    do_action('ptp_training_booking_completed', $booking_id);
                    ptp_log("[PTP Stripe Webhook] Dispatched ptp_training_booking_completed for booking {$booking_id}");
                } else {
                    // v213: No booking_id in metadata - check if booking needs to be created
                    // This handles the case where user closes browser after payment but before client-side AJAX fires
                    $pi_id = $payment_intent['id'] ?? '';
                    $md = $payment_intent['metadata'] ?? array();
                    $trainer_id_wh = intval($md['trainer_id'] ?? 0);
                    $checkout_session_key = $md['checkout_session'] ?? '';
                    
                    ptp_log("[PTP Stripe Webhook] No booking_id - checking PI={$pi_id}, trainer_id={$trainer_id_wh}, session={$checkout_session_key}");
                    
                    if ($trainer_id_wh > 0 && !empty($pi_id)) {
                        // Check if a booking already exists for this payment intent (created by client-side AJAX)
                        $existing_booking = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s LIMIT 1",
                            $pi_id
                        ));
                        
                        if ($existing_booking) {
                            ptp_log("[PTP Stripe Webhook] Booking {$existing_booking} already exists for PI {$pi_id} - updating payment status");
                            $wpdb->update(
                                $wpdb->prefix . 'ptp_bookings',
                                array('payment_status' => 'paid'),
                                array('id' => $existing_booking)
                            );
                        } else {
                            // No booking exists - try to create from checkout session transient
                            $created = false;
                            
                            if (!empty($checkout_session_key)) {
                                $checkout_data = get_transient('ptp_checkout_' . $checkout_session_key);
                                if ($checkout_data && class_exists('PTP_Unified_Checkout')) {
                                    ptp_log("[PTP Stripe Webhook] Creating booking from checkout transient for PI {$pi_id}");
                                    $uc = new PTP_Unified_Checkout();
                                    $result = $uc->create_orders_from_session_public($checkout_data, $pi_id, $payment_intent);
                                    if (!empty($result['booking_id'])) {
                                        ptp_log("[PTP Stripe Webhook] Backup booking created: {$result['booking_id']}");
                                        $created = true;
                                        // Mark as processed so return-URL handler doesn't double-create
                                        set_transient('ptp_processed_' . $pi_id, true, DAY_IN_SECONDS);
                                    }
                                }
                            }
                            
                            // Last resort: create minimal booking from PI metadata
                            if (!$created) {
                                ptp_log("[PTP Stripe Webhook] Creating minimal booking from PI metadata for PI {$pi_id}");
                                $amount = ($payment_intent['amount'] ?? 0) / 100;
                                $booking_number = 'PTP-WH-' . strtoupper(substr(md5($pi_id), 0, 8));
                                
                                $wh_insert = array(
                                    'booking_number' => $booking_number,
                                    'trainer_id' => $trainer_id_wh,
                                    'session_date' => !empty($md['session_date']) ? sanitize_text_field($md['session_date']) : null,
                                    'start_time' => !empty($md['session_time']) ? sanitize_text_field($md['session_time']) : null,
                                    'location' => sanitize_text_field($md['session_location'] ?? ''),
                                    'location_notes' => sanitize_text_field($md['session_location_address'] ?? ''),
                                    'status' => 'confirmed',
                                    'payment_status' => 'paid',
                                    'payment_intent_id' => $pi_id,
                                    'created_at' => current_time('mysql'),
                                );
                                
                                // Add columns if they exist
                                $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings");
                                if (in_array('total_amount', $columns)) $wh_insert['total_amount'] = $amount;
                                if (in_array('amount_paid', $columns)) $wh_insert['amount_paid'] = $amount;
                                if (in_array('package_type', $columns)) $wh_insert['package_type'] = sanitize_text_field($md['package'] ?? 'single');
                                if (in_array('group_size', $columns)) $wh_insert['group_size'] = intval($md['group_size'] ?? 1);
                                if (in_array('guest_email', $columns) && !empty($payment_intent['receipt_email'])) {
                                    $wh_insert['guest_email'] = sanitize_email($payment_intent['receipt_email']);
                                }
                                if (in_array('notes', $columns)) {
                                    $wh_insert['notes'] = 'Created by webhook backup - client callback may have failed.';
                                }
                                
                                $insert_ok = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $wh_insert);
                                if ($insert_ok) {
                                    $new_booking_id = $wpdb->insert_id;
                                    ptp_log("[PTP Stripe Webhook] Minimal booking created: {$new_booking_id}");
                                    
                                    // v235.1: Create parent record from PI customer data so booking isn't orphaned
                                    $wh_email = sanitize_email($payment_intent['receipt_email'] ?? ($md['customer_email'] ?? ''));
                                    $wh_name = sanitize_text_field($md['customer_name'] ?? '');
                                    $wh_phone = sanitize_text_field($md['customer_phone'] ?? '');
                                    if ($wh_email) {
                                        $wh_parent_id = $wpdb->get_var($wpdb->prepare(
                                            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE email = %s", $wh_email
                                        ));
                                        if (!$wh_parent_id) {
                                            $name_parts = explode(' ', $wh_name, 2);
                                            $wpdb->insert($wpdb->prefix . 'ptp_parents', array(
                                                'first_name' => $name_parts[0] ?? '',
                                                'last_name'  => $name_parts[1] ?? '',
                                                'email'      => $wh_email,
                                                'phone'      => $wh_phone,
                                                'created_at' => current_time('mysql'),
                                            ));
                                            $wh_parent_id = $wpdb->insert_id;
                                            ptp_log("[PTP Stripe Webhook] Created parent {$wh_parent_id} for {$wh_email}");
                                        }
                                        if ($wh_parent_id && in_array('parent_id', $columns)) {
                                            $wpdb->update($wpdb->prefix . 'ptp_bookings',
                                                array('parent_id' => $wh_parent_id),
                                                array('id' => $new_booking_id)
                                            );
                                        }
                                    }
                                    
                                    // Create escrow hold
                                    if (class_exists('PTP_Escrow') && $amount > 0) {
                                        PTP_Escrow::create_hold($new_booking_id, $pi_id, $amount);
                                    }
                                    
                                    // v136: Dispatch hook instead of direct email calls
                                    do_action('ptp_training_booking_completed', $new_booking_id);
                                    
                                    // Flag for admin review since it may be missing parent/player data
                                    update_option('ptp_webhook_bookings_to_review', array_merge(
                                        (array) get_option('ptp_webhook_bookings_to_review', array()),
                                        array($new_booking_id)
                                    ));
                                } else {
                                    ptp_log("[PTP Stripe Webhook] Failed to create minimal booking: " . $wpdb->last_error);
                                }
                            }
                        }
                    } else {
                        ptp_log("[PTP Stripe Webhook] payment_intent.succeeded but no trainer_id or PI - skipping");
                    }
                }
                break;
                
            case 'payment_intent.payment_failed':
                $payment_intent = $event['data']['object'];
                $booking_id = $payment_intent['metadata']['booking_id'] ?? null;
                $pi_id = $payment_intent['id'] ?? 'unknown';
                $error = $payment_intent['last_payment_error']['message'] ?? 'Unknown error';
                
                ptp_log("[PTP Stripe Webhook] payment_intent.payment_failed: PI={$pi_id}, booking_id={$booking_id}, error={$error}");
                PTP_Monitor::payment_error("Payment failed: PI={$pi_id}, booking={$booking_id}", array('pi_id' => $pi_id, 'booking_id' => $booking_id, 'error' => $error));
                
                if ($booking_id) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        array('payment_status' => 'failed'),
                        array('id' => $booking_id)
                    );
                    ptp_log("[PTP Stripe Webhook] Updated booking {$booking_id} payment_status to failed");
                    
                    // Dispatch action for any failure handling
                    do_action('ptp_stripe_webhook_payment_intent.payment_failed', $event);
                }
                break;
                
            case 'charge.refunded':
                $charge = $event['data']['object'];
                $payment_intent_id = $charge['payment_intent'];
                
                $wpdb->query($wpdb->prepare("
                    UPDATE {$wpdb->prefix}ptp_bookings 
                    SET payment_status = 'refunded' 
                    WHERE payment_intent_id = %s
                ", $payment_intent_id));
                break;
                
            case 'account.updated':
                // Connect account status changed
                $account = $event['data']['object'];
                $trainer_id = $account['metadata']['trainer_id'] ?? null;
                
                if ($trainer_id) {
                    $charges_enabled = $account['charges_enabled'] ? 1 : 0;
                    $payouts_enabled = $account['payouts_enabled'] ? 1 : 0;
                    
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_trainers',
                        array(
                            'stripe_charges_enabled' => $charges_enabled,
                            'stripe_payouts_enabled' => $payouts_enabled,
                        ),
                        array('id' => $trainer_id)
                    );
                }
                break;
                
            case 'product.created':
                // Training created in Stripe - trigger email notification
                $product = $event['data']['object'];
                $trainer_id = $product['metadata']['trainer_id'] ?? null;
                
                ptp_log("[PTP Stripe Webhook] product.created received: " . ($product['id'] ?? 'unknown'));
                
                if ($trainer_id) {
                    // Get price info if available
                    $price_amount = null;
                    if (!empty($product['default_price'])) {
                        $price = self::api_request('prices/' . $product['default_price'], 'GET');
                        if (!is_wp_error($price) && isset($price['unit_amount'])) {
                            $price_amount = $price['unit_amount'];
                        }
                    }
                    
                    $product_data = array(
                        'id' => $product['id'],
                        'name' => $product['name'] ?? 'Training Session',
                        'description' => $product['description'] ?? '',
                        'default_price_amount' => $price_amount,
                    );
                    
                    // Store product in database for reference
                    $wpdb->insert(
                        $wpdb->prefix . 'ptp_stripe_products',
                        array(
                            'stripe_product_id' => $product['id'],
                            'trainer_id' => $trainer_id,
                            'name' => $product_data['name'],
                            'description' => $product_data['description'],
                            'price_cents' => $price_amount,
                            'active' => $product['active'] ? 1 : 0,
                            'created_at' => current_time('mysql'),
                        ),
                        array('%s', '%d', '%s', '%s', '%d', '%d', '%s')
                    );
                    
                    // Send email notification
                    if (class_exists('PTP_Email')) {
                        PTP_Email::send_new_training_notification($trainer_id, $product_data);
                    }
                    
                    ptp_log("[PTP Stripe Webhook] Training notification sent to trainer #$trainer_id for product: " . $product['id']);
                } else {
                    ptp_log("[PTP Stripe Webhook] product.created - no trainer_id in metadata, skipping notification");
                }
                break;
                
            case 'product.updated':
                // Training updated in Stripe - sync to database
                $product = $event['data']['object'];
                $trainer_id = $product['metadata']['trainer_id'] ?? null;
                
                if ($trainer_id) {
                    $price_amount = null;
                    if (!empty($product['default_price'])) {
                        $price = self::api_request('prices/' . $product['default_price'], 'GET');
                        if (!is_wp_error($price) && isset($price['unit_amount'])) {
                            $price_amount = $price['unit_amount'];
                        }
                    }
                    
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_stripe_products',
                        array(
                            'name' => $product['name'] ?? 'Training Session',
                            'description' => $product['description'] ?? '',
                            'price_cents' => $price_amount,
                            'active' => $product['active'] ? 1 : 0,
                            'updated_at' => current_time('mysql'),
                        ),
                        array('stripe_product_id' => $product['id']),
                        array('%s', '%s', '%d', '%d', '%s'),
                        array('%s')
                    );
                    
                    ptp_log("[PTP Stripe Webhook] Product updated: " . $product['id']);
                }
                break;
                
            case 'product.deleted':
                // Training deleted in Stripe - mark inactive
                $product = $event['data']['object'];
                
                $wpdb->update(
                    $wpdb->prefix . 'ptp_stripe_products',
                    array('active' => 0, 'updated_at' => current_time('mysql')),
                    array('stripe_product_id' => $product['id']),
                    array('%d', '%s'),
                    array('%s')
                );
                
                ptp_log("[PTP Stripe Webhook] Product deleted/deactivated: " . $product['id']);
                break;
            
            case 'checkout.session.completed':
                // Camp checkout completed - dispatch to camp order handler
                $session = $event['data']['object'];
                $type = $session['metadata']['type'] ?? '';
                $order_id = $session['metadata']['order_id'] ?? '';
                $customer_email = $session['customer_details']['email'] ?? ($session['metadata']['customer_email'] ?? '');
                
                ptp_log("[PTP Stripe Webhook] checkout.session.completed: session={$session['id']}, type={$type}, order_id={$order_id}, email={$customer_email}");
                
                if ($type === 'camp_registration') {
                    // Dispatch to camp orders handler
                    do_action('ptp_stripe_webhook_checkout.session.completed', $event);
                    ptp_log("[PTP Stripe Webhook] Dispatched ptp_stripe_webhook_checkout.session.completed for camp order {$order_id}");
                } elseif ($type === 'training' || !empty($session['metadata']['booking_id'])) {
                    // Training checkout - dispatch training event
                    $booking_id = $session['metadata']['booking_id'] ?? '';
                    do_action('ptp_training_booking_completed', $booking_id);
                    ptp_log("[PTP Stripe Webhook] Dispatched ptp_training_booking_completed for booking {$booking_id}");
                } else {
                    ptp_log("[PTP Stripe Webhook] checkout.session.completed with unknown type: {$type}");
                }
                break;
            
            case 'checkout.session.expired':
                // Camp checkout expired
                $session = $event['data']['object'];
                $type = $session['metadata']['type'] ?? '';
                
                ptp_log("[PTP Stripe Webhook] checkout.session.expired: " . $session['id']);
                
                if ($type === 'camp_registration') {
                    do_action('ptp_stripe_webhook_checkout.session.expired', $event);
                }
                break;
        }
        
        // Dispatch generic event for other handlers
        do_action('ptp_stripe_webhook_received', $event);
        
        // v216.3: Dispatch event-type-specific action so subscription/invoice handlers fire
        // This enables PTP_Subscriptions and PTP_Mentorship webhook hooks to work
        do_action('ptp_stripe_webhook_' . $event['type'], $event);
        
        return array('received' => true);
    }
    
    /**
     * Cancel and refund booking
     */
    public static function refund_booking($booking_id, $reason = 'requested_by_customer') {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d
        ", $booking_id));
        
        if (!$booking || !$booking->payment_intent_id) {
            return new WP_Error('no_payment', 'No payment found for this booking');
        }
        
        $refund = self::create_refund($booking->payment_intent_id, null, $reason);
        
        if (is_wp_error($refund)) {
            return $refund;
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'cancelled',
                'payment_status' => 'refunded',
            ),
            array('id' => $booking_id)
        );
        
        return $refund;
    }
    
    /**
     * Get payment history for booking
     */
    public static function get_payment_history($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d
        ", $booking_id));
        
        if (!$booking || !$booking->payment_intent_id) {
            return array();
        }
        
        $intent = self::get_payment_intent($booking->payment_intent_id);
        
        if (is_wp_error($intent)) {
            return array();
        }
        
        return array(
            'payment_intent' => $intent,
            'amount' => $intent['amount'] / 100,
            'status' => $intent['status'],
            'created' => date('Y-m-d H:i:s', $intent['created']),
        );
    }
    
    /**
     * Find or create a Stripe Customer by email
     * Searches existing customers, updates if found, creates if not.
     * Returns customer ID string or empty string on failure.
     */
    public static function find_or_create_customer_by_email($email, $name = '', $phone = '') {
        if (empty($email) || !is_email($email)) {
            return '';
        }
        
        self::ensure_init();
        if (empty(self::$secret_key)) {
            return '';
        }
        
        try {
            // Search for existing customer by email
            $search_response = wp_remote_get(
                'https://api.stripe.com/v1/customers/search?query=' . urlencode("email:'" . $email . "'"),
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . self::$secret_key,
                        'Stripe-Version' => '2023-10-16',
                    ),
                    'timeout' => 15,
                )
            );
            
            if (!is_wp_error($search_response)) {
                $search_body = json_decode(wp_remote_retrieve_body($search_response), true);
                
                if (!empty($search_body['data']) && count($search_body['data']) > 0) {
                    // Customer exists — update name/phone if provided
                    $customer_id = $search_body['data'][0]['id'];
                    
                    $update_data = array();
                    if (!empty($name)) $update_data['name'] = $name;
                    if (!empty($phone)) $update_data['phone'] = $phone;
                    
                    if (!empty($update_data)) {
                        self::api_request("customers/{$customer_id}", 'POST', $update_data);
                    }
                    
                    ptp_log('[PTP Stripe] Found existing customer ' . $customer_id . ' for ' . $email);
                    return $customer_id;
                }
            }
            
            // No existing customer — create new one
            $create_data = array(
                'email' => $email,
                'metadata[source]' => 'ptp_training',
            );
            if (!empty($name)) $create_data['name'] = $name;
            if (!empty($phone)) $create_data['phone'] = $phone;
            
            $customer = self::api_request('customers', 'POST', $create_data);
            
            if (!is_wp_error($customer) && !empty($customer['id'])) {
                ptp_log('[PTP Stripe] Created new customer ' . $customer['id'] . ' for ' . $email);
                return $customer['id'];
            }
        } catch (Exception $e) {
            ptp_log('[PTP Stripe] find_or_create_customer_by_email error: ' . $e->getMessage());
        }
        
        return '';
    }
    
    /**
     * Create customer for saved cards
     */
    public static function create_customer($user_id, $email, $name) {
        $data = array(
            'email' => $email,
            'name' => $name,
            'metadata[user_id]' => $user_id,
        );
        
        $customer = self::api_request('customers', 'POST', $data);
        
        if (!is_wp_error($customer)) {
            update_user_meta($user_id, 'ptp_stripe_customer_id', $customer['id']);
        }
        
        return $customer;
    }
    
    /**
     * Get or create Stripe customer
     */
    public static function get_or_create_customer($user_id) {
        $customer_id = get_user_meta($user_id, 'ptp_stripe_customer_id', true);
        
        if ($customer_id) {
            return $customer_id;
        }
        
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found');
        }
        
        $customer = self::create_customer($user_id, $user->user_email, $user->display_name);
        
        if (is_wp_error($customer)) {
            return $customer;
        }
        
        return $customer['id'];
    }
    
    /**
     * Check if Connect account is fully set up
     */
    public static function is_account_complete($account_id) {
        if (empty($account_id)) {
            return false;
        }
        
        $account = self::get_account($account_id);
        
        if (is_wp_error($account)) {
            return false;
        }
        
        return !empty($account['charges_enabled']) && !empty($account['payouts_enabled']);
    }
}
