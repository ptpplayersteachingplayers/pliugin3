<?php
/**
 * PTP Checkout v77.7 - High Performance Payment Handler
 * 
 * v77.7 Changes:
 * - Optimized Stripe API calls (reduced latency)
 * - Async email/notification processing
 * - Better error recovery and logging
 * - Idempotency keys for safer retries
 * - Mobile-optimized response times
 * 
 * @since 77.7.0
 */

defined('ABSPATH') || exit;

class PTP_Checkout_V77 {
    
    const NONCE_ACTION = 'ptp_checkout_v77';
    const VERSION = '77.7.0';
    
    private static $instance = null;
    private $start_time;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Start performance timer
        $this->start_time = microtime(true);
        
        // Primary AJAX handlers (V77 specific actions)
        add_action('wp_ajax_ptp_checkout_create_intent', array($this, 'ajax_create_intent'));
        add_action('wp_ajax_nopriv_ptp_checkout_create_intent', array($this, 'ajax_create_intent'));
        
        add_action('wp_ajax_ptp_checkout_confirm', array($this, 'ajax_confirm'));
        add_action('wp_ajax_nopriv_ptp_checkout_confirm', array($this, 'ajax_confirm'));
        
        // Optimize: Defer non-critical hooks
        add_action('shutdown', array($this, 'flush_deferred_tasks'));
    }
    
    public static function create_nonce() {
        return wp_create_nonce(self::NONCE_ACTION);
    }
    
    public static function verify_nonce($nonce = null) {
        $nonce = $nonce ?? ($_POST['nonce'] ?? $_POST['security'] ?? '');
        
        $valid_actions = array(
            self::NONCE_ACTION,
            'ptp_checkout_action',
            'ptp_checkout',
            'ptp_nonce',
            'ptp_bundle_checkout'
        );
        
        foreach ($valid_actions as $action) {
            if (wp_verify_nonce($nonce, $action)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function log($message, $data = null) {
        $elapsed = round((microtime(true) - $this->start_time) * 1000, 2);
        $log = '[PTP Checkout v77.7] [' . $elapsed . 'ms] ' . $message;
        if ($data !== null) {
            $log .= ' | ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES));
        }
        ptp_log($log);
    }
    
    /**
     * Queue for deferred tasks (emails, analytics, etc.)
     */
    private static $deferred_tasks = array();
    
    public function flush_deferred_tasks() {
        foreach (self::$deferred_tasks as $task) {
            try {
                call_user_func($task['callback'], $task['args']);
            } catch (Exception $e) {
                ptp_log('[PTP Checkout v77.7] Deferred task failed: ' . $e->getMessage());
            }
        }
    }
    
    private function defer_task($callback, $args = array()) {
        self::$deferred_tasks[] = array(
            'callback' => $callback,
            'args' => $args
        );
    }
    
    /**
     * Send JSON response - clean any output buffer first
     */
    private function send_json_success($data) {
        // Clean any output that may have been generated
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        
        echo json_encode(array(
            'success' => true,
            'data' => $data
        ));
        
        wp_die();
    }
    
    /**
     * Send JSON error - clean any output buffer first
     */
    private function send_json_error($message) {
        // Clean any output that may have been generated
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        
        echo json_encode(array(
            'success' => false,
            'data' => array('message' => $message)
        ));
        
        wp_die();
    }
    
    /**
     * Get Stripe secret key
     */
    private function get_stripe_secret_key() {
        $test_mode = get_option('ptp_stripe_test_mode', true);
        return $test_mode 
            ? get_option('ptp_stripe_test_secret', '')
            : get_option('ptp_stripe_live_secret', '');
    }
    
    /**
     * Make Stripe API request with idempotency and optimized connection
     */
    private function stripe_request($endpoint, $data = array(), $method = 'POST', $idempotency_key = null) {
        $secret_key = $this->get_stripe_secret_key();
        
        if (empty($secret_key)) {
            return new WP_Error('no_api_key', 'Stripe API key not configured');
        }
        
        $url = 'https://api.stripe.com/v1/' . $endpoint;
        
        $headers = array(
            'Authorization' => 'Bearer ' . $secret_key,
        );
        
        // Add idempotency key for safe retries on POST requests
        if ($method === 'POST' && $idempotency_key) {
            $headers['Idempotency-Key'] = $idempotency_key;
        }
        
        $args = array(
            'headers' => $headers,
            'timeout' => 30, // Reduced from 60 for faster failure detection
            'sslverify' => true,
            'compress' => true, // Enable gzip compression
        );
        
        if ($method === 'POST') {
            $args['body'] = $data;
            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }
        
        if (is_wp_error($response)) {
            $this->log('Stripe API error', $response->get_error_message());
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            $this->log('Stripe error', $body['error']['message']);
            return new WP_Error('stripe_error', $body['error']['message']);
        }
        
        return $body;
    }
    
    /**
     * Get customer data from POST
     */
    private function get_customer_data() {
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        
        return array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'full_name' => trim($first_name . ' ' . $last_name),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
        );
    }
    
    /**
     * Get camper/player data from POST
     */
    private function get_camper_data() {
        $campers = array();
        
        if (isset($_POST['camper']) && is_array($_POST['camper'])) {
            foreach ($_POST['camper'] as $camper) {
                if (!empty($camper['first_name'])) {
                    $first = sanitize_text_field($camper['first_name'] ?? '');
                    $last = sanitize_text_field($camper['last_name'] ?? '');
                    $campers[] = array(
                        'first_name' => $first,
                        'last_name' => $last,
                        'full_name' => trim($first . ' ' . $last),
                        'age' => intval($camper['age'] ?? 0),
                        'skill_level' => sanitize_text_field($camper['skill_level'] ?? ''),
                        'team' => sanitize_text_field($camper['team'] ?? ''),
                        'dob' => sanitize_text_field($camper['dob'] ?? ''),
                        'shirt_size' => sanitize_text_field($camper['shirt_size'] ?? ''),
                        'position' => sanitize_text_field($camper['position'] ?? ''),
                    );
                }
            }
        }
        
        $this->log('get_camper_data result', array(
            'count' => count($campers),
            'first_camper' => !empty($campers[0]) ? $campers[0]['full_name'] : 'none'
        ));
        
        return $campers;
    }
    
    /**
     * Get recurring booking data from POST
     */
    private function get_recurring_data() {
        $enabled = !empty($_POST['recurring_enabled']);
        
        if (!$enabled) {
            return null;
        }
        
        return array(
            'enabled' => true,
            'frequency' => sanitize_text_field($_POST['recurring_frequency'] ?? 'weekly'),
            'weeks' => intval($_POST['recurring_weeks'] ?? 8),
            'day_of_week' => intval($_POST['recurring_day'] ?? 0),
            'time' => sanitize_text_field($_POST['recurring_time'] ?? ''),
        );
    }
    
    /**
     * Get cart items
     */
    private function get_cart_items() {
        $items = array(
            'woo' => array(),
            'training' => array(),
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
        );
        
        // PTP Native items
        if (false && ptp_cart()) {
            foreach (ptp_cart()->get_items() as $key => $cart_item) {
                $product = $cart_item['data'];
                $subtotal = floatval($cart_item['line_subtotal']);
                $items['woo'][] = array(
                    'key' => $key,
                    'product_id' => $cart_item['product_id'],
                    'name' => $product->get_name(),
                    'quantity' => $cart_item['quantity'],
                    'price' => $subtotal,
                );
                $items['subtotal'] += $subtotal;
            }
        }
        
        // Training items from session
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        $training = array();
        if (false && ptp_session()) {
            $training = ptp_session()->get('ptp_training_items', array());
        }
        if (empty($training) && isset($_SESSION['ptp_training_items'])) {
            $training = $_SESSION['ptp_training_items'];
        }
        
        if (is_array($training)) {
            foreach ($training as $item) {
                $price = floatval($item['price'] ?? 0);
                $items['training'][] = array(
                    'trainer_id' => intval($item['trainer_id'] ?? 0),
                    'trainer_name' => sanitize_text_field($item['trainer_name'] ?? ''),
                    'package_name' => sanitize_text_field($item['package_name'] ?? ''),
                    'session_type' => sanitize_text_field($item['session_type'] ?? 'single'),
                    'sessions' => intval($item['sessions'] ?? 1),
                    'hourly_rate' => floatval($item['hourly_rate'] ?? 80),
                    'date' => sanitize_text_field($item['date'] ?? ''),
                    'time' => sanitize_text_field($item['time'] ?? ''),
                    'location' => sanitize_text_field($item['location'] ?? ''),
                    'location_address' => sanitize_text_field($item['location_address'] ?? ''),
                    'price' => $price,
                    'group_size' => intval($item['group_size'] ?? 1),
                );
                $items['subtotal'] += $price;
            }
        }
        
        // Bundle discount
        if (!empty($items['woo']) && !empty($items['training'])) {
            $discount_pct = floatval(get_option('ptp_bundle_discount_percent', 15));
            $items['discount'] = round($items['subtotal'] * ($discount_pct / 100), 2);
        }
        
        $items['total'] = $items['subtotal'] - $items['discount'];
        
        return $items;
    }
    
    /**
     * Build Stripe description
     */
    private function build_stripe_description($customer, $campers, $items) {
        $parts = array();
        
        $parts[] = 'Customer: ' . $customer['full_name'] . ' (' . $customer['email'] . ')';
        
        if (!empty($campers)) {
            $names = array_map(function($c) { return $c['full_name']; }, $campers);
            $parts[] = 'Player(s): ' . implode(', ', $names);
        }
        
        $item_list = array();
        foreach ($items['woo'] as $item) {
            $item_list[] = $item['name'];
        }
        foreach ($items['training'] as $item) {
            $item_list[] = $item['package_name'] ?: ($item['trainer_name'] . ' Training');
        }
        if (!empty($item_list)) {
            $parts[] = 'Items: ' . implode(', ', $item_list);
        }
        
        return substr(implode(' | ', $parts), 0, 1000);
    }
    
    /**
     * Store checkout session
     */
    private function save_session($data) {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        $_SESSION['ptp_checkout_v77'] = $data;
        
        if (false && ptp_session()) {
            ptp_session()->set('ptp_checkout_v77', $data);
        }
    }
    
    /**
     * Get checkout session
     */
    private function load_session() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        if (false && ptp_session()) {
            $data = ptp_session()->get('ptp_checkout_v77');
            if (!empty($data)) return $data;
        }
        
        return $_SESSION['ptp_checkout_v77'] ?? null;
    }
    
    /**
     * Clear checkout session
     */
    private function clear_session() {
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        unset($_SESSION['ptp_checkout_v77']);
        unset($_SESSION['ptp_training_items']);
        
        if (false && ptp_session()) {
            ptp_session()->set('ptp_checkout_v77', null);
            ptp_session()->set('ptp_training_items', array());
            ptp_session()->set('ptp_bundle_code', null);
        }
    }
    
    /**
     * Generate unique booking number
     */
    private function generate_booking_number() {
        return 'PTP-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    }
    
    /**
     * Get or create player ID
     */
    private function get_or_create_player_id($parent_id, $player_data) {
        global $wpdb;
        
        // Try to find existing player by name and parent
        $player_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d AND name = %s",
            $parent_id,
            $player_data['full_name']
        ));
        
        if ($player_id) {
            return intval($player_id);
        }
        
        // Create new player
        $wpdb->insert(
            $wpdb->prefix . 'ptp_players',
            array(
                'parent_id' => $parent_id,
                'name' => $player_data['full_name'],
                'age' => $player_data['age'] ?? null,
                'skill_level' => 'intermediate',
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );
        
        return $wpdb->insert_id ?: 0;
    }
    
    /**
     * AJAX: Create Payment Intent
     */
    public function ajax_create_intent() {
        // Start output buffering to catch any stray output
        ob_start();
        
        try {
            $this->log('=== CREATE INTENT ===');
            
            // Verify nonce
            if (!self::verify_nonce()) {
                $this->log('Nonce failed');
                $this->send_json_error('Security check failed. Please refresh and try again.');
                return;
            }
            
            // Collect data
            $customer = $this->get_customer_data();
            $campers = $this->get_camper_data();
            $items = $this->get_cart_items();
            
            $this->log('Customer', $customer);
            $this->log('Campers count', count($campers));
            $this->log('Items', array('woo' => count($items['woo']), 'training' => count($items['training']), 'total' => $items['total']));
            
            // Validate
            if (empty($customer['first_name'])) {
                $this->send_json_error('Please enter your first name');
                return;
            }
            
            if (empty($customer['email']) || !is_email($customer['email'])) {
                $this->send_json_error('Please enter a valid email address');
                return;
            }
            
            if (empty($campers)) {
                $this->send_json_error('Please enter player information');
                return;
            }
            
            // Get total
            $total = floatval($_POST['total'] ?? $items['total']);
            if ($total <= 0) {
                $this->send_json_error('Invalid order total');
                return;
            }
            
            // Check Stripe
            $stripe_key = $this->get_stripe_secret_key();
            if (empty($stripe_key)) {
                $this->log('Stripe not configured');
                $this->send_json_error('Payment system not configured. Please contact support.');
                return;
            }
            
            // Build Stripe data
            $description = $this->build_stripe_description($customer, $campers, $items);
            
            $stripe_data = array(
                'amount' => round($total * 100),
                'currency' => 'usd',
                'description' => $description,
                // v114.1: Removed receipt_email - PTP Native handles confirmation emails
                'automatic_payment_methods[enabled]' => 'true',
                'automatic_payment_methods[allow_redirects]' => 'never',
                'metadata[customer_name]' => $customer['full_name'],
                'metadata[customer_email]' => $customer['email'],
                'metadata[customer_phone]' => $customer['phone'],
                'metadata[player_name]' => $campers[0]['full_name'] ?? '',
                'metadata[player_age]' => strval($campers[0]['age'] ?? ''),
                'metadata[source]' => 'ptp_checkout_v77',
                'metadata[site]' => home_url(),
            );
            
            // Add item names
            $item_names = array();
            foreach ($items['woo'] as $i) { $item_names[] = $i['name']; }
            foreach ($items['training'] as $i) { $item_names[] = $i['package_name'] ?: 'Training'; }
            if (!empty($item_names)) {
                $stripe_data['metadata[items]'] = substr(implode(', ', $item_names), 0, 500);
            }
            
            // Attach Stripe Customer to PI
            if (!empty($customer['email']) && class_exists('PTP_Stripe') && method_exists('PTP_Stripe', 'find_or_create_customer_by_email')) {
                $stripe_cust_id = PTP_Stripe::find_or_create_customer_by_email($customer['email'], $customer['full_name'] ?? '', $customer['phone'] ?? '');
                if ($stripe_cust_id) {
                    $stripe_data['customer'] = $stripe_cust_id;
                }
                $stripe_data['receipt_email'] = $customer['email'];
            }
            
            $this->log('Creating Payment Intent', array('amount' => $total));
            
            // Create Payment Intent
            $intent = $this->stripe_request('payment_intents', $stripe_data);
            
            if (is_wp_error($intent)) {
                $this->log('Stripe error', $intent->get_error_message());
                $this->send_json_error('Payment setup failed: ' . $intent->get_error_message());
                return;
            }
            
            $this->log('Payment Intent created', $intent['id']);
            
            // Save session
            $this->save_session(array(
                'payment_intent_id' => $intent['id'],
                'customer' => $customer,
                'campers' => $campers,
                'items' => $items,
                'total' => $total,
                'created_at' => current_time('mysql'),
            ));
            
            $this->send_json_success(array(
                'client_secret' => $intent['client_secret'],
                'payment_intent_id' => $intent['id'],
            ));
            
        } catch (Exception $e) {
            $this->log('Exception', $e->getMessage());
            $this->send_json_error('An error occurred: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Confirm Payment and Create Order
     */
    public function ajax_confirm() {
        // Start output buffering
        ob_start();
        
        try {
            $this->log('=== CONFIRM PAYMENT ===');
            
            // Verify nonce
            if (!self::verify_nonce()) {
                $this->log('Nonce failed');
                $this->send_json_error('Security check failed');
                return;
            }
            
            $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
            if (empty($payment_intent_id)) {
                $this->log('No payment_intent_id');
                $this->send_json_error('Missing payment reference');
                return;
            }
            
            $this->log('Payment Intent', $payment_intent_id);
            
            // Verify payment with Stripe
            $intent = $this->stripe_request('payment_intents/' . $payment_intent_id, array(), 'GET');
            
            if (is_wp_error($intent)) {
                $this->log('Stripe verification error', $intent->get_error_message());
                $this->send_json_error('Payment verification failed');
                return;
            }
            
            if ($intent['status'] !== 'succeeded') {
                $this->log('Payment status', $intent['status']);
                $this->send_json_error('Payment not completed. Status: ' . $intent['status']);
                return;
            }
            
            $this->log('Payment verified: succeeded');
            
            // Get session data
            $session = $this->load_session();
            
            // Log what we got
            $this->log('Session loaded', $session ? 'Yes' : 'No');
            if ($session) {
                $this->log('Session campers count', count($session['campers'] ?? []));
            }
            
            // Fallback: reconstruct from POST
            if (empty($session) || empty($session['campers'])) {
                $this->log('Session missing or no campers, reconstructing from POST');
                $this->log('POST camper data', isset($_POST['camper']) ? 'Present' : 'Missing');
                
                $customer = $this->get_customer_data();
                $campers = $this->get_camper_data();
                $items = $this->get_cart_items();
                
                $this->log('Reconstructed campers count', count($campers));
                
                // If still no campers, try to get from Stripe metadata
                if (empty($campers) && !empty($intent['metadata']['player_name'])) {
                    $this->log('Using Stripe metadata for player info');
                    $campers = array(array(
                        'first_name' => explode(' ', $intent['metadata']['player_name'])[0] ?? '',
                        'last_name' => implode(' ', array_slice(explode(' ', $intent['metadata']['player_name']), 1)) ?? '',
                        'full_name' => $intent['metadata']['player_name'],
                        'age' => intval($intent['metadata']['player_age'] ?? 0),
                        'dob' => '',
                        'shirt_size' => '',
                        'team' => '',
                    ));
                }
                
                $session = array(
                    'payment_intent_id' => $payment_intent_id,
                    'customer' => $customer,
                    'campers' => $campers,
                    'items' => $items,
                    'total' => floatval($_POST['total'] ?? 0),
                );
            }
            
            $customer = $session['customer'];
            $campers = $session['campers'];
            $items = $session['items'];
            
            $this->log('Final campers count', count($campers));
            if (!empty($campers[0])) {
                $this->log('First camper', $campers[0]['full_name'] ?? 'Unknown');
            }
            
            $this->log('Processing order for', $customer['email']);
            
            global $wpdb;
            $order_id = null;
            $booking_ids = array();
            
            $wpdb->query('START TRANSACTION');
            
            try {
                // ========================================
                // CREATE PTP ORDER
                // ========================================
                if (!empty($items['woo']) && false && ptp_cart() && ptp_cart()->get_item_count() > 0) {
                    $this->log('Creating PTP Native order...');
                    
                    // Get or create user
                    $user_id = get_current_user_id();
                    if (!$user_id && !empty($customer['email'])) {
                        $user_id = email_exists($customer['email']);
                        if (!$user_id) {
                            $user_id = wp_create_user(
                                $customer['email'],
                                wp_generate_password(),
                                $customer['email']
                            );
                            if (!is_wp_error($user_id)) {
                                update_user_meta($user_id, 'first_name', $customer['first_name']);
                                update_user_meta($user_id, 'last_name', $customer['last_name']);
                                update_user_meta($user_id, 'billing_email', $customer['email']);
                                update_user_meta($user_id, 'billing_phone', $customer['phone']);
                                update_user_meta($user_id, 'billing_first_name', $customer['first_name']);
                                update_user_meta($user_id, 'billing_last_name', $customer['last_name']);
                            } else {
                                $user_id = 0;
                            }
                        }
                    }
                    
                    $order = PTP_Native_Order_Manager::create_order(array(
                        'customer_id' => is_numeric($user_id) ? $user_id : 0,
                        'status' => 'pending',
                    ));
                    
                    if (is_wp_error($order)) {
                        throw new Exception('Order creation failed: ' . $order->get_error_message());
                    }
                    
                    // Add products from cart
                    foreach (ptp_cart()->get_items() as $cart_item) {
                        $order->add_product(
                            $cart_item['data'],
                            $cart_item['quantity'],
                            array(
                                'subtotal' => $cart_item['line_subtotal'],
                                'total' => $cart_item['line_total'],
                            )
                        );
                    }
                    
                    // Set billing info
                    $order->set_billing_first_name($customer['first_name']);
                    $order->set_billing_last_name($customer['last_name']);
                    $order->set_billing_email($customer['email']);
                    $order->set_billing_phone($customer['phone']);
                    
                    // Set payment info
                    $order->set_payment_method('stripe');
                    $order->set_payment_method_title('Credit Card');
                    $order->set_transaction_id($payment_intent_id);
                    
                    // Store camper data
                    if (!empty($campers)) {
                        // Save full camper array for all details
                        $order->update_meta_data('_ptp_campers', $campers);
                        
                        // Save individual fields for first camper (for easy access)
                        $order->update_meta_data('_ptp_player_name', $campers[0]['full_name']);
                        $order->update_meta_data('_ptp_player_age', $campers[0]['age']);
                        $order->update_meta_data('_ptp_player_skill_level', $campers[0]['skill_level'] ?? '');
                        $order->update_meta_data('_ptp_player_team', $campers[0]['team'] ?? '');
                        $order->update_meta_data('_ptp_player_dob', $campers[0]['dob'] ?? '');
                        $order->update_meta_data('_ptp_player_shirt', $campers[0]['shirt_size'] ?? '');
                        
                        // Log what we're saving
                        $this->log('Saving camper data to order', array(
                            'order_id' => 'pending',
                            'campers_count' => count($campers),
                            'first_camper' => $campers[0]
                        ));
                        
                        // Order note with camper details
                        $note_lines = array('REGISTERED PLAYER' . (count($campers) > 1 ? 'S' : '') . ':');
                        foreach ($campers as $i => $c) {
                            $line = ($i + 1) . '. ' . $c['full_name'];
                            if (!empty($c['age'])) $line .= ' (Age ' . $c['age'] . ')';
                            if (!empty($c['skill_level'])) $line .= ' - ' . ucfirst($c['skill_level']);
                            if (!empty($c['team'])) $line .= ' - ' . $c['team'];
                            if (!empty($c['shirt_size'])) $line .= ' - Shirt: ' . $c['shirt_size'];
                            $note_lines[] = $line;
                        }
                        $order->add_order_note(implode("\n", $note_lines));
                    } else {
                        $this->log('WARNING: No camper data to save to order');
                    }
                    
                    // Store payment reference
                    $order->update_meta_data('_ptp_payment_intent_id', $payment_intent_id);
                    $order->update_meta_data('_ptp_checkout_version', self::VERSION);
                    
                    // Calculate and save
                    $order->calculate_totals();
                    $order->payment_complete($payment_intent_id);
                    $order->update_status('processing', 'Payment confirmed via PTP Checkout v77.4');
                    $order->save();
                    
                    $order_id = $order->get_id();
                    $this->log('PTP Native order created', $order_id);
                    
                    // Clear cart
                    ptp_cart()->clear();
                }
                
                // ========================================
                // CREATE PTP BOOKINGS
                // ========================================
                if (!empty($items['training'])) {
                    $this->log('Creating PTP bookings...');
                    
                    $table = $wpdb->prefix . 'ptp_bookings';
                    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
                    
                    if ($table_exists) {
                        // Get or create parent user
                        $parent_user_id = get_current_user_id();
                        if (!$parent_user_id && !empty($customer['email'])) {
                            $parent_user_id = email_exists($customer['email']);
                            if (!$parent_user_id) {
                                $parent_user_id = wp_create_user(
                                    $customer['email'],
                                    wp_generate_password(),
                                    $customer['email']
                                );
                                if (is_wp_error($parent_user_id)) {
                                    $parent_user_id = 0;
                                } else {
                                    update_user_meta($parent_user_id, 'first_name', $customer['first_name']);
                                    update_user_meta($parent_user_id, 'last_name', $customer['last_name']);
                                }
                            }
                        }
                        
                        // Get or create parent record
                        $parent_id = 0;
                        if ($parent_user_id) {
                            $parent_id = $wpdb->get_var($wpdb->prepare(
                                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                                $parent_user_id
                            ));
                            
                            if (!$parent_id) {
                                $wpdb->insert(
                                    $wpdb->prefix . 'ptp_parents',
                                    array(
                                        'user_id' => $parent_user_id,
                                        'display_name' => $customer['full_name'],
                                        'phone' => $customer['phone'],
                                        'created_at' => current_time('mysql'),
                                    ),
                                    array('%d', '%s', '%s', '%s')
                                );
                                $parent_id = $wpdb->insert_id;
                            }
                        }
                        
                        // Get or create player
                        $player_id = 0;
                        if (!empty($campers) && $parent_id) {
                            $player_id = $this->get_or_create_player_id($parent_id, $campers[0]);
                        }
                        
                        foreach ($items['training'] as $training) {
                            $amount = floatval($training['price']); // What customer pays (may include discount)
                            
                            // Get trainer's actual hourly rate from database
                            $trainer_id = intval($training['trainer_id']);
                            $trainer_rate = $wpdb->get_var($wpdb->prepare(
                                "SELECT hourly_rate FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                                $trainer_id
                            ));
                            $trainer_rate = floatval($trainer_rate ?: 80); // Default $80/hr
                            
                            // Determine number of sessions (from package or default 1)
                            $sessions = intval($training['sessions'] ?? 1);
                            if ($sessions < 1) $sessions = 1;
                            
                            // Get group size and multiplier
                            $group_size = intval($training['group_size'] ?? 1);
                            $group_multipliers = array(1 => 1, 2 => 1.6, 3 => 2, 4 => 2.4);
                            $group_mult = $group_multipliers[$group_size] ?? 1;
                            
                            // GRADUATED COMMISSION: 50% / 50% / 25% / 25% / 15% ongoing
                            // Uses PTP_Escrow::get_fee_rate() for centralized fee schedule
                            $previous_sessions = 0;
                            if ($parent_id) {
                                $previous_sessions = (int) $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                                     WHERE parent_id = %d AND trainer_id = %d 
                                     AND status IN ('completed', 'confirmed', 'pending')
                                     AND payment_status = 'paid'",
                                    $parent_id, $trainer_id
                                ));
                            }
                            
                            // For multi-session packages, calculate fee per session
                            // Each session advances the relationship counter
                            $effective_rate = $trainer_rate * $group_mult;
                            $total_trainer_payout = 0;
                            
                            for ($s = 1; $s <= $sessions; $s++) {
                                $session_num = $previous_sessions + $s;
                                $fee_rate = PTP_Escrow::get_fee_rate($session_num);
                                $trainer_share = 1 - $fee_rate;
                                $total_trainer_payout += round($effective_rate * $trainer_share, 2);
                            }
                            
                            $trainer_payout = $total_trainer_payout;
                            $first_session_num = $previous_sessions + 1;
                            $last_session_num = $previous_sessions + $sessions;
                            
                            $this->log('Graduated commission applied', array(
                                'parent_id' => $parent_id,
                                'trainer_id' => $trainer_id,
                                'previous_sessions' => $previous_sessions,
                                'session_range' => "{$first_session_num}-{$last_session_num}",
                                'sessions_purchased' => $sessions,
                                'trainer_payout' => $trainer_payout,
                            ));
                            
                            $platform_fee = round($amount - $trainer_payout, 2);
                            
                            // Log if discount eats into platform fee significantly
                            $normal_platform_fee = round($effective_rate * $sessions - $trainer_payout, 2);
                            if ($platform_fee < $normal_platform_fee * 0.5) {
                                $this->log('Heavy discount applied - platform absorbing cost', array(
                                    'amount_charged' => $amount,
                                    'trainer_payout' => $trainer_payout,
                                    'platform_fee' => $platform_fee,
                                    'normal_fee_would_be' => $normal_platform_fee,
                                    'discount_absorbed' => $normal_platform_fee - $platform_fee,
                                    'session_range' => "{$first_session_num}-{$last_session_num}",
                                    'group_size' => $group_size
                                ));
                            }
                            
                            // Parse date and time
                            $session_date = $training['date'] ?: date('Y-m-d', strtotime('+1 day'));
                            $session_time = $training['time'] ?: '09:00';
                            
                            // Calculate start and end times
                            $start_time = date('H:i:s', strtotime($session_time));
                            $end_time = date('H:i:s', strtotime($start_time) + 3600);
                            
                            // Generate booking number
                            $booking_number = $this->generate_booking_number();
                            
                            // v132.8: Check for existing booking at same time slot
                            $existing_booking = $wpdb->get_var($wpdb->prepare(
                                "SELECT id FROM {$table} 
                                 WHERE trainer_id = %d 
                                 AND session_date = %s 
                                 AND start_time = %s 
                                 AND status NOT IN ('cancelled', 'refunded')
                                 LIMIT 1",
                                $trainer_id, $session_date, $start_time
                            ));
                            
                            if ($existing_booking) {
                                $this->log('Slot already booked', array(
                                    'trainer_id' => $trainer_id,
                                    'date' => $session_date,
                                    'time' => $start_time,
                                    'existing_booking' => $existing_booking
                                ));
                                throw new Exception('This time slot is no longer available. Please select a different time.');
                            }
                            
                            // Build booking data with correct field names matching database schema
                            $group_size = intval($training['group_size'] ?? 1);
                            $group_labels = array(1 => 'Solo', 2 => 'Duo', 3 => 'Trio', 4 => 'Quad');
                            $group_label = $group_labels[$group_size] ?? 'Solo';
                            
                            $booking_data = array(
                                'booking_number'    => $booking_number,
                                'trainer_id'        => $trainer_id,
                                'parent_id'         => $parent_id ?: 0,
                                'player_id'         => $player_id ?: 0,
                                'session_date'      => $session_date,
                                'start_time'        => $start_time,
                                'end_time'          => $end_time,
                                'duration_minutes'  => 60 * $sessions,
                                'location'          => $training['location'] ?: '',
                                'location_notes'    => $training['location_address'] ?? '',
                                'hourly_rate'       => $trainer_rate,  // Trainer's actual rate
                                'total_amount'      => $amount,        // What customer paid
                                'platform_fee'      => $platform_fee,  // PTP's cut (may be negative with discounts)
                                'trainer_payout'    => $trainer_payout, // Trainer always gets full rate
                                'status'            => 'confirmed',
                                'payment_status'    => 'paid',
                                'payment_intent_id' => $payment_intent_id,
                                'session_type'      => $training['session_type'] ?: 'single',
                                'group_players'     => $group_size > 1 ? json_encode(array('size' => $group_size, 'label' => $group_label)) : '',
                                'notes'             => sprintf(
                                    "Package: %s (%d session%s)%s\nPlayer: %s (Age %d)\nParent: %s <%s>\nPhone: %s\nTrainer Rate: $%s/hr\nCustomer Paid: $%s\nPlatform Fee: $%s (%s)\nTrainer Payout: $%s\nCheckout v%s",
                                    $training['package_name'] ?: 'Single Session',
                                    $sessions,
                                    $sessions > 1 ? 's' : '',
                                    $group_size > 1 ? "\nGroup: " . $group_label . " (" . $group_size . " players)" : '',
                                    $campers[0]['full_name'] ?? 'N/A',
                                    $campers[0]['age'] ?? 0,
                                    $customer['full_name'],
                                    $customer['email'],
                                    $customer['phone'],
                                    number_format($trainer_rate, 2),
                                    number_format($amount, 2),
                                    number_format($platform_fee, 2),
                                    $is_first_session ? 'First Session 50%' : 'Repeat 25%',
                                    number_format($trainer_payout, 2),
                                    self::VERSION
                                ),
                                'created_at'        => current_time('mysql'),
                            );
                            
                            $result = $wpdb->insert($table, $booking_data);
                            
                            if ($result) {
                                $booking_id = $wpdb->insert_id;
                                $booking_ids[] = $booking_id;
                                $this->log('Booking created', array('id' => $booking_id, 'number' => $booking_number));
                                
                                // Fire hook for Google Calendar integration
                                do_action('ptp_booking_confirmed', $booking_id);
                                
                                // Check if this is trainer's first booking
                                $trainer_booking_count = $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                                     WHERE trainer_id = %d AND status NOT IN ('cancelled', 'rejected')",
                                    $trainer_id
                                ));
                                if ($trainer_booking_count == 1) {
                                    do_action('ptp_trainer_first_booking', $trainer_id, $booking_id);
                                }
                                
                                // ========================================
                                // CREATE RECURRING BOOKING IF ENABLED
                                // ========================================
                                $recurring_data = $this->get_recurring_data();
                                if ($recurring_data && $recurring_data['enabled']) {
                                    $this->log('Creating recurring booking...', $recurring_data);
                                    
                                    $recurring_table = $wpdb->prefix . 'ptp_recurring_bookings';
                                    $recurring_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $recurring_table)) === $recurring_table;
                                    
                                    if ($recurring_exists) {
                                        // Calculate total sessions based on frequency
                                        $total_weeks = intval($recurring_data['weeks']);
                                        $total_sessions = $recurring_data['frequency'] === 'biweekly' 
                                            ? ceil($total_weeks / 2) 
                                            : $total_weeks;
                                        
                                        // Get day of week from session date (1=Monday, 7=Sunday)
                                        $day_of_week = $recurring_data['day_of_week'] ?: date('N', strtotime($session_date));
                                        
                                        // Calculate next booking date (1 week or 2 weeks from first session)
                                        $interval = $recurring_data['frequency'] === 'biweekly' ? '+2 weeks' : '+1 week';
                                        $next_date = date('Y-m-d', strtotime($session_date . ' ' . $interval));
                                        
                                        $recurring_insert = array(
                                            'parent_id' => $parent_id ?: 0,
                                            'trainer_id' => $trainer_id,
                                            'player_id' => $player_id ?: 0,
                                            'frequency' => $recurring_data['frequency'],
                                            'total_sessions' => $total_sessions,
                                            'sessions_created' => 1, // First session already created
                                            'sessions_completed' => 0,
                                            'day_of_week' => $day_of_week,
                                            'preferred_time' => $start_time,
                                            'location' => $training['location'] ?: '',
                                            'status' => 'active',
                                            'next_booking_date' => $next_date,
                                            'created_at' => current_time('mysql'),
                                        );
                                        
                                        $recurring_result = $wpdb->insert($recurring_table, $recurring_insert);
                                        
                                        if ($recurring_result) {
                                            $recurring_id = $wpdb->insert_id;
                                            
                                            // Update the booking to reference the recurring booking
                                            $wpdb->update(
                                                $table,
                                                array(
                                                    'is_recurring' => 1,
                                                    'recurring_id' => $recurring_id,
                                                ),
                                                array('id' => $booking_id)
                                            );
                                            
                                            $this->log('Recurring booking created', array(
                                                'recurring_id' => $recurring_id,
                                                'total_sessions' => $total_sessions,
                                                'frequency' => $recurring_data['frequency'],
                                                'next_date' => $next_date
                                            ));
                                            
                                            // Fire hook for recurring booking setup
                                            do_action('ptp_recurring_booking_created', $recurring_id, $booking_id);
                                        } else {
                                            $this->log('Recurring booking insert failed', array(
                                                'error' => $wpdb->last_error
                                            ));
                                        }
                                    }
                                }
                            } else {
                                $this->log('Booking insert failed', array(
                                    'error' => $wpdb->last_error,
                                    'query' => $wpdb->last_query
                                ));
                            }
                        }
                    } else {
                        $this->log('ERROR: ptp_bookings table does not exist');
                    }
                }
                
                $wpdb->query('COMMIT');
                $this->log('Transaction committed');

                // ── Mark abandoned cart as recovered ──
                try {
                    $cust_email = strtolower($customer['email'] ?? '');
                    if ($cust_email) {
                        // Camp abandoned carts table
                        $ac_table = $wpdb->prefix . 'ptp_camp_abandoned_carts';
                        if ($wpdb->get_var("SHOW TABLES LIKE '{$ac_table}'") === $ac_table) {
                            $wpdb->update($ac_table,
                                array('status' => 'recovered', 'updated_at' => current_time('mysql')),
                                array('email' => $cust_email, 'status' => 'abandoned'),
                                array('%s', '%s'), array('%s', '%s')
                            );
                        }
                        // Training abandoned carts table
                        $at_table = $wpdb->prefix . 'ptp_abandoned_carts';
                        if ($wpdb->get_var("SHOW TABLES LIKE '{$at_table}'") === $at_table) {
                            $wpdb->update($at_table,
                                array('recovered' => 1),
                                array('email' => $cust_email, 'recovered' => 0),
                                array('%d'), array('%s', '%d')
                            );
                        }
                        // Also call class method if available
                        if (class_exists('PTP_Email_Automation') && method_exists('PTP_Email_Automation', 'mark_cart_recovered')) {
                            PTP_Email_Automation::mark_cart_recovered($cust_email, 0);
                        }
                        $this->log('Abandoned carts marked recovered for ' . $cust_email);
                    }
                } catch (Throwable $e) {
                    $this->log('Cart recovery marking error (non-fatal)', $e->getMessage());
                }
                
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                $this->log('Transaction error', $e->getMessage());
                ptp_log('CRITICAL [PTP]: Payment ' . $payment_intent_id . ' succeeded but order failed: ' . $e->getMessage());
                PTP_Monitor::payment_critical("Payment succeeded but order creation failed: PI={$payment_intent_id}", array('pi_id' => $payment_intent_id, 'error' => $e->getMessage()));
                
                $this->send_json_error('Order creation failed. Your payment was received. Contact support with ref: ' . $payment_intent_id);
                return;
            }
            
            // ========================================
            // SEND NOTIFICATIONS (async for speed)
            // ========================================
            $this->log('Scheduling notifications...');
            
            // Trigger PTP Native order processing (this sends emails)
            if ($order_id) {
                // Schedule for 2 seconds from now to not block response
                wp_schedule_single_event(time() + 2, 'ptp_send_order_emails', array($order_id));
                $this->log('Order emails scheduled for order #' . $order_id);
            }
            
            // Schedule booking confirmations
            if (!empty($booking_ids)) {
                wp_schedule_single_event(time() + 3, 'ptp_send_booking_emails', array($booking_ids));
                $this->log('Booking emails scheduled');
            }
            
            // Clear session
            $this->clear_session();
            
            // ========================================
            // REDIRECT TO THANK YOU PAGE
            // ========================================
            $redirect = home_url('/thank-you/');
            
            // Add order ID to URL
            if ($order_id) {
                $redirect = add_query_arg('order_id', $order_id, $redirect);
            }
            
            // Add booking IDs
            if (!empty($booking_ids)) {
                $redirect = add_query_arg('bookings', implode(',', $booking_ids), $redirect);
            }
            
            $this->log('=== COMPLETE ===', array(
                'order_id' => $order_id,
                'booking_ids' => $booking_ids,
                'redirect' => $redirect
            ));
            
            $this->send_json_success(array(
                'order_id' => $order_id,
                'booking_id' => $booking_ids[0] ?? null,
                'redirect_url' => $redirect,
                'redirect' => $redirect,
            ));
            
        } catch (Throwable $e) {
            $this->log('Exception', $e->getMessage());
            $this->send_json_error('An error occurred: ' . $e->getMessage());
        }
    }
}

// Initialize
function ptp_checkout_v77_init() {
    return PTP_Checkout_V77::instance();
}
add_action('init', 'ptp_checkout_v77_init', 5);

// Async email handlers for faster checkout
add_action('ptp_send_order_emails', function($order_id) {
    if (!$order_id) return;
    
    ptp_log('[PTP Checkout v77] Sending async order emails for #' . $order_id);
    
    // REMOVED: do_action('ptp-native_order_status_processing', $order_id);
    
    if (false) {
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if ($order) {
            // REMOVED: do_action('ptp-native_new_order', $order_id, $order);
        }
    }
});

add_action('ptp_send_booking_emails', function($booking_ids) {
    if (empty($booking_ids) || !is_array($booking_ids)) return;
    
    ptp_log('[PTP Checkout v77] Sending async booking emails for: ' . implode(',', $booking_ids));
    
    foreach ($booking_ids as $bid) {
        // v136: Dispatch hook instead of direct email calls
        do_action('ptp_training_booking_completed', $bid);
    }
});
