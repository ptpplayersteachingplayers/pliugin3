<?php
/**
 * PTP Training Thank You Handler v162
 * 
 * Clean, simple, bulletproof thank-you page for training bookings.
 * Catches /thank-you/ URL early and renders a standalone page.
 * 
 * v162: CRITICAL FIX - Added booking CREATION/RECOVERY logic.
 *       The checkout flow redirects to thank-you BEFORE creating the booking,
 *       so this handler must CREATE the booking from session data + payment_intent.
 * 
 * v161: Added comprehensive error handling with fallback page.
 *       This is now the ONLY active thank-you handler.
 */

if (!defined('ABSPATH')) {
    exit;
}

class PTP_Training_Thankyou {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hook early to catch the URL before WordPress processes it
        // v168.5: Changed from priority 1 to 5 to allow Action Scheduler to initialize first
        // This fixes "as_next_scheduled_action called before data store initialized" error
        add_action('init', array($this, 'maybe_render_thankyou'), 5);
    }
    
    /**
     * Check if this is a thank-you page request and render it
     */
    public function maybe_render_thankyou() {
        // Get the request path
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($request_uri, PHP_URL_PATH);
        $path = trim($path, '/');
        
        // Only handle thank-you URLs
        if (!in_array($path, array('thank-you', 'thankyou', 'order-received', 'order-confirmation'))) {
            return;
        }
        
        // v162: Wrap everything in try-catch for bulletproof handling
        try {
            // Check if this is a training booking (has session or payment_intent param)
            $has_session = isset($_GET['session']) || isset($_GET['payment_intent']);
            $has_bookings = isset($_GET['bookings']) || isset($_GET['booking_id']);
            // v168.4: Check BOTH 'order' and 'order_id' params
            $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : (isset($_GET['order']) ? intval($_GET['order']) : 0);
            
            // v168.4: If we have an order param, check if it's a CAMP order first
            if ($order_id > 0) {
                global $wpdb;
                $camp_order = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE id = %d",
                    $order_id
                ));
                
                if ($camp_order) {
                    ptp_log('[PTP Training Thankyou v175] Order #' . $order_id . ' is a CAMP order - loading v175 upsell template');
                    // This is a camp order - load thank-you-v175.php with upsells
                    $template = PTP_PLUGIN_DIR . 'templates/thank-you-v175.php';
                    if (file_exists($template)) {
                        include $template;
                        exit;
                    }
                }
            }
            
            // v168.2: CHECK IF THIS IS A CAMP PURCHASE - Don't render training template for camps!
            $is_camp_purchase = false;
            $payment_intent_check = isset($_GET['payment_intent']) ? sanitize_text_field($_GET['payment_intent']) : '';
            $session_check = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
            
            if ($payment_intent_check || $session_check) {
                global $wpdb;
                
                // Check 1: Does a camp order already exist for this payment_intent?
                if ($payment_intent_check) {
                    $camp_order_exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE stripe_payment_intent_id = %s",
                        $payment_intent_check
                    ));
                    if ($camp_order_exists) {
                        $is_camp_purchase = true;
                        ptp_log('[PTP Training Thankyou v168.2] Camp order exists for payment_intent - skipping training template');
                    }
                }
                
                // Check 2: Does checkout transient have camp items?
                // v168.3: Check BOTH 'cart_items' (unified checkout) AND 'items' (bulletproof checkout)
                if (!$is_camp_purchase && $session_check) {
                    $checkout_data = get_transient('ptp_checkout_' . $session_check);
                    if ($checkout_data) {
                        // Get items array - could be 'cart_items' or 'items' depending on checkout type
                        $transient_items = $checkout_data['cart_items'] ?? $checkout_data['items'] ?? array();
                        
                        if (!empty($transient_items)) {
                            foreach ($transient_items as $item) {
                                // Check item_type directly or in nested structure
                                $item_type = $item['item_type'] ?? '';
                                if ($item_type === 'camp') {
                                    $is_camp_purchase = true;
                                    ptp_log('[PTP Training Thankyou v168.3] Camp item in checkout transient - skipping training template');
                                    break;
                                }
                            }
                        }
                        
                        // Also check totals for camp_count
                        if (!$is_camp_purchase && !empty($checkout_data['totals'])) {
                            $camp_count = intval($checkout_data['totals']['camp_count'] ?? 0);
                            $training_count = intval($checkout_data['totals']['training_count'] ?? 0);
                            if ($camp_count > 0 && $training_count == 0) {
                                $is_camp_purchase = true;
                                ptp_log('[PTP Training Thankyou v168.3] Camp detected from totals (camp_count=' . $camp_count . ')');
                            }
                        }
                    }
                }
                
                // Check 3: Query Stripe PaymentIntent metadata for camp_count
                if (!$is_camp_purchase && $payment_intent_check) {
                    $secret_key = get_option('ptp_stripe_test_mode', true) 
                        ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
                        : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
                    
                    if (!empty($secret_key)) {
                        $pi_response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_check, array(
                            'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                            'timeout' => 10,
                        ));
                        
                        if (!is_wp_error($pi_response)) {
                            $pi_data = json_decode(wp_remote_retrieve_body($pi_response), true);
                            $pi_metadata = $pi_data['metadata'] ?? array();
                            $camp_count = intval($pi_metadata['camp_count'] ?? 0);
                            $training_count = intval($pi_metadata['training_count'] ?? 0);
                            
                            // If has camps and no training, this is a camp purchase
                            if ($camp_count > 0 && $training_count == 0) {
                                $is_camp_purchase = true;
                                ptp_log('[PTP Training Thankyou v168.2] Stripe metadata shows camp purchase (camp_count=' . $camp_count . ') - skipping training template');
                            }
                        }
                    }
                }
            }
            
            // v168.2: If this is a camp purchase, let thank-you-v175.php handle it
            if ($is_camp_purchase) {
                ptp_log('[PTP Training Thankyou v175] Camp purchase detected - loading v175 upsell template');
                
                // v168.4: CRITICAL - Call handle_payment_return to create camp order BEFORE loading template
                // This normally runs on template_redirect but we're intercepting at init
                if (class_exists('PTP_Unified_Checkout')) {
                    $unified_checkout = PTP_Unified_Checkout::instance();
                    if (method_exists($unified_checkout, 'handle_payment_return')) {
                        ptp_log('[PTP Training Thankyou v175] Calling handle_payment_return to create camp order...');
                        $unified_checkout->handle_payment_return();
                        // Note: handle_payment_return may redirect and exit, which is fine
                    }
                }
                
                // Load thank-you-v175.php with upsells
                $template = PTP_PLUGIN_DIR . 'templates/thank-you-v175.php';
                if (file_exists($template)) {
                    include $template;
                    exit;
                }
                // Fall through to let other handlers try
                return;
            }
            
            // If we have training params OR no order, render training thank-you
            if ($has_session || $has_bookings || !$order_id) {
                $this->render_training_thankyou();
                exit;
            }
        } catch (Throwable $e) {
            ptp_log('[PTP Training Thankyou v162] FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->last_error = $e;
            $this->render_fallback_page();
            exit;
        }
    }
    
    private $last_error = null;
    
    /**
     * Render a simple fallback page when there's an error
     */
    private function render_fallback_page() {
        $home_url = function_exists('home_url') ? home_url() : '/';
        
        // Get error from caught exception or last PHP error
        if ($this->last_error) {
            $error_msg = $this->last_error->getMessage();
            $error_file = basename($this->last_error->getFile());
            $error_line = $this->last_error->getLine();
        } else {
            $last_error = error_get_last();
            $error_msg = $last_error ? $last_error['message'] : 'Unknown error';
            $error_file = $last_error ? basename($last_error['file']) : 'Unknown';
            $error_line = $last_error ? $last_error['line'] : '?';
        }
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You - PTP</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, sans-serif; background: #0A0A0A; color: #fff; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .ty { text-align: center; padding: 40px 20px; max-width: 600px; }
        .ty-check { width: 80px; height: 80px; background: #22C55E; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 40px; }
        .ty h1 { font-size: 28px; margin-bottom: 12px; }
        .ty p { color: rgba(255,255,255,0.7); margin-bottom: 24px; line-height: 1.6; }
        .ty a { display: inline-block; background: #FCB900; color: #0A0A0A; padding: 16px 32px; font-weight: 700; text-decoration: none; border-radius: 8px; text-transform: uppercase; }
        .debug-box { background: #1a1a1a; border: 1px solid #333; padding: 15px; margin: 20px 0; text-align: left; font-family: monospace; font-size: 12px; color: #ff6b6b; border-radius: 8px; word-break: break-all; }
    </style>
</head>
<body>
    <div class="ty">
        <div class="ty-check">✓</div>
        <h1>Payment Received!</h1>
        <p>Your booking is being processed. You'll receive a confirmation email shortly with all the details.</p>
        <div class="debug-box">
            <strong>Debug Info:</strong><br>
            Error: <?php echo esc_html($error_msg); ?><br>
            File: <?php echo esc_html($error_file); ?><br>
            Line: <?php echo esc_html($error_line); ?>
        </div>
        <a href="<?php echo esc_url($home_url); ?>">Return Home</a>
    </div>
</body>
</html>
        <?php
    }
    
    /**
     * Render the training thank-you page
     */
    private function render_training_thankyou() {
        try {
            global $wpdb;
            
            ptp_log('[PTP Training Thankyou v162] === STARTING THANK YOU PAGE ===');
            ptp_log('[PTP Training Thankyou v162] GET params: ' . json_encode($_GET));
            
            // Get URL params
            $session_param = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
            $payment_intent_param = isset($_GET['payment_intent']) ? sanitize_text_field($_GET['payment_intent']) : '';
            
            ptp_log('[PTP Training Thankyou v162] Session: ' . $session_param);
            ptp_log('[PTP Training Thankyou v162] Payment Intent: ' . $payment_intent_param);
            
            // Step 1: Try to find existing booking
            $booking_id = $this->find_booking_id();
            $booking = null;
            
            if ($booking_id) {
                ptp_log('[PTP Training Thankyou v162] Found existing booking: ' . $booking_id);
                $booking = $this->load_booking($booking_id);
            }
            
            // Step 2: If no booking, try to CREATE from session data (RECOVERY)
            if (!$booking && !empty($session_param) && !empty($payment_intent_param)) {
                ptp_log('[PTP Training Thankyou v162] No booking found - attempting RECOVERY creation');
                $booking_id = $this->create_booking_from_session($session_param, $payment_intent_param);
                if ($booking_id) {
                    ptp_log('[PTP Training Thankyou v162] RECOVERY SUCCESS - Created booking: ' . $booking_id);
                    $booking = $this->load_booking($booking_id);
                }
            }
            
            // Step 3: Get session data for display fallback
            // v222: ALWAYS load transient — booking may have empty date/time/location
            // that the transient still has from checkout
            $session_data = null;
            if ($session_param) {
                $session_data = get_transient('ptp_checkout_' . $session_param);
                ptp_log('[PTP Training Thankyou v222] Session transient loaded: ' . ($session_data ? 'YES' : 'NO'));
            }
            
            // Render the page
            $this->output_page($booking, $session_data);
            
        } catch (Throwable $e) {
            ptp_log('[PTP Training Thankyou v162] Render error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            $this->last_error = $e;
            $this->render_fallback_page();
        }
    }
    
    /**
     * v162: Create booking from checkout session data
     * This is the CRITICAL recovery logic - the checkout redirects to thank-you
     * before creating the booking, so we create it here.
     */
    private function create_booking_from_session($session_param, $payment_intent_id) {
        global $wpdb;
        
        ptp_log('[PTP Training Thankyou v162] create_booking_from_session called');
        
        // Check if booking already exists for this payment_intent (prevent duplicates)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s",
            $payment_intent_id
        ));
        if ($existing) {
            ptp_log('[PTP Training Thankyou v162] Booking already exists for payment_intent: ' . $existing);
            return intval($existing);
        }
        
        // Get checkout session data
        $session_data = get_transient('ptp_checkout_' . $session_param);
        if (!$session_data) {
            ptp_log('[PTP Training Thankyou v162] No session data found for: ' . $session_param);
            return 0;
        }
        
        ptp_log('[PTP Training Thankyou v162] Session data: ' . json_encode($session_data));
        
        // Extract trainer info - check multiple possible locations
        $trainer_id = 0;
        if (!empty($session_data['trainer_id'])) {
            $trainer_id = intval($session_data['trainer_id']);
        } elseif (!empty($session_data['training']['trainer_id'])) {
            $trainer_id = intval($session_data['training']['trainer_id']);
        } elseif (!empty($session_data['items'])) {
            foreach ($session_data['items'] as $item) {
                if (($item['type'] ?? '') === 'training' && !empty($item['trainer_id'])) {
                    $trainer_id = intval($item['trainer_id']);
                    break;
                }
            }
        }
        
        if (!$trainer_id) {
            ptp_log('[PTP Training Thankyou v162] No trainer_id found in session data');
            return 0;
        }
        
        ptp_log('[PTP Training Thankyou v162] Found trainer_id: ' . $trainer_id);
        
        // Get total amount
        $total_amount = 0;
        if (!empty($session_data['training_total'])) {
            $total_amount = floatval($session_data['training_total']);
        } elseif (!empty($session_data['total'])) {
            $total_amount = floatval($session_data['total']);
        } elseif (!empty($session_data['totals']['total'])) {
            $total_amount = floatval($session_data['totals']['total']);
        }
        
        // Get or create parent
        $parent_data = $session_data['parent_data'] ?? array();
        $user_id = get_current_user_id();
        $parent_id = 0;
        
        // Get email from multiple sources
        $parent_email = $parent_data['email'] ?? '';
        if (empty($parent_email) && $user_id > 0) {
            $wp_user = get_userdata($user_id);
            if ($wp_user) {
                $parent_email = $wp_user->user_email;
            }
        }
        if (empty($parent_email) && !empty($session_data['camper_data']['parent_email'])) {
            $parent_email = $session_data['camper_data']['parent_email'];
        }
        
        ptp_log('[PTP Training Thankyou v162] Parent email: ' . $parent_email);
        
        // Find existing parent
        if ($user_id > 0) {
            $parent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $user_id
            ));
        }
        if (!$parent_id && !empty($parent_email)) {
            $parent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE email = %s",
                $parent_email
            ));
        }
        
        // Create parent if needed
        if (!$parent_id) {
            $parent_first = $parent_data['first_name'] ?? '';
            $parent_last = $parent_data['last_name'] ?? '';
            $parent_phone = $parent_data['phone'] ?? '';
            
            // Try WP user info
            if (empty($parent_first) && $user_id > 0) {
                $wp_user = get_userdata($user_id);
                if ($wp_user) {
                    $parent_first = $wp_user->first_name ?: $wp_user->display_name;
                    $parent_last = $wp_user->last_name;
                }
            }
            
            $wpdb->insert($wpdb->prefix . 'ptp_parents', array(
                'user_id' => $user_id,
                'first_name' => $parent_first,
                'last_name' => $parent_last,
                'email' => $parent_email,
                'phone' => $parent_phone,
                'created_at' => current_time('mysql'),
            ));
            $parent_id = $wpdb->insert_id;
            ptp_log('[PTP Training Thankyou v162] Created parent: ' . $parent_id);
        }
        
        // Create player if needed
        $player_id = intval($session_data['player_id'] ?? 0);
        $camper_data = $session_data['camper_data'] ?? array();
        
        if (!$player_id && $parent_id && !empty($camper_data['first_name'])) {
            $player_name = trim(($camper_data['first_name'] ?? '') . ' ' . ($camper_data['last_name'] ?? ''));
            $wpdb->insert($wpdb->prefix . 'ptp_players', array(
                'parent_id' => $parent_id,
                'first_name' => $camper_data['first_name'],
                'last_name' => $camper_data['last_name'] ?? '',
                'name' => $player_name,
                'created_at' => current_time('mysql'),
            ));
            $player_id = $wpdb->insert_id;
            ptp_log('[PTP Training Thankyou v162] Created player: ' . $player_id);
        }
        
        // Calculate fees
        $training_package = $session_data['training_package'] ?? 'single';
        if (class_exists('PTP_Packages')) {
            $training_package = PTP_Packages::resolve_key($training_package);
            $num_sessions = PTP_Packages::get($training_package)['sessions'];
        } else {
            $sessions_map = array('single' => 1, 'pack3' => 3, 'pack5' => 5, 'pack10' => 10, '10pack' => 10);
            $num_sessions = $sessions_map[$training_package] ?? 1;
        }
        
        $platform_fee_pct = floatval(get_option('ptp_platform_fee_percent', 25));
        // v194: Graduated fee
        if (class_exists('PTP_Unified_Checkout')) {
            $ty_fee = PTP_Unified_Checkout::calculate_graduated_fee($trainer_id, $parent_id, $total_amount, $num_sessions);
            $platform_fee = $ty_fee['platform_fee'];
            $trainer_payout = $ty_fee['trainer_payout'];
        } else {
            $platform_fee = round($total_amount * ($platform_fee_pct / 100), 2);
            $trainer_payout = round($total_amount - $platform_fee, 2);
        }
        
        // Generate booking number
        $booking_number = 'PTP-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        
        // Get session details
        $session_date = $session_data['session_date'] ?? null;
        $session_time = $session_data['session_time'] ?? null;
        $location = $session_data['session_location'] ?? '';
        
        // Create the booking
        $insert_data = array(
            'booking_number' => $booking_number,
            'trainer_id' => $trainer_id,
            'parent_id' => $parent_id,
            'player_id' => $player_id,
            'session_date' => $session_date,
            'start_time' => $session_time,
            'location' => $location,
            'location_notes' => sanitize_text_field($session_data['session_location_address'] ?? ''),
            'total_amount' => $total_amount,
            'platform_fee' => $platform_fee,
            'trainer_payout' => $trainer_payout,
            'payment_intent_id' => $payment_intent_id,
            'payment_status' => 'paid',
            'status' => 'confirmed',
            'session_type' => $training_package,
            'session_count' => $num_sessions,
            'sessions_remaining' => $num_sessions,
            'package_type' => $training_package,
            'total_sessions' => $num_sessions,
            'group_size' => intval($session_data['group_size'] ?? 1),
            'created_at' => current_time('mysql'),
        );
        
        ptp_log('[PTP Training Thankyou v162] Inserting booking: ' . json_encode($insert_data));
        
        $result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $insert_data);
        
        if ($result === false) {
            ptp_log('[PTP Training Thankyou v162] BOOKING INSERT FAILED: ' . $wpdb->last_error);
            return 0;
        }
        
        $booking_id = $wpdb->insert_id;
        ptp_log('[PTP Training Thankyou v162] BOOKING CREATED: ' . $booking_id . ' (' . $booking_number . ')');
        
        // Set cookie for backup lookup
        if (!headers_sent()) {
            setcookie('ptp_last_booking', $booking_id, time() + 3600, '/');
        }
        
        // Send notification emails
        $this->send_booking_notifications($booking_id, $camper_data, $parent_email);
        
        return $booking_id;
    }
    
    /**
     * Send booking confirmation emails
     */
    private function send_booking_notifications($booking_id, $camper_data, $parent_email) {
        try {
            // v235.9: Fire the hook chain — this was missing!
            // notify_trainer() was gutted in v228 (emails delegated to hook chain)
            // but this file never fired the hook, so recovery bookings got ZERO emails.
            do_action('ptp_booking_confirmed', $booking_id);
            ptp_log('[PTP Training Thankyou v235.9] Fired ptp_booking_confirmed for booking ' . $booking_id);
            
            // Fallback: direct email if hook chain didn't send
            if (!get_transient('ptp_training_email_sent_' . $booking_id)) {
                if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_booking_confirmation')) {
                    PTP_Email::send_booking_confirmation($booking_id);
                    ptp_log('[PTP Training Thankyou v235.9] Direct fallback email sent for booking ' . $booking_id);
                }
            }
        } catch (Throwable $e) {
            ptp_log('[PTP Training Thankyou v235.9] Notification error: ' . $e->getMessage());
        }
    }
    
    /**
     * Find booking ID from URL params, cookies, or database
     */
    private function find_booking_id() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_bookings';
        
        // 1. Direct booking_id or booking param
        if (isset($_GET['booking_id'])) {
            return intval($_GET['booking_id']);
        }
        // v220: Free checkout uses 'booking' param
        if (isset($_GET['booking'])) {
            return intval($_GET['booking']);
        }
        
        // 2. Bookings param (comma-separated, take first)
        if (isset($_GET['bookings'])) {
            $ids = array_map('intval', explode(',', sanitize_text_field($_GET['bookings'])));
            if (!empty($ids[0])) {
                return $ids[0];
            }
        }
        
        // 3. Payment intent lookup
        if (isset($_GET['payment_intent'])) {
            $pi = sanitize_text_field($_GET['payment_intent']);
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE payment_intent_id = %s ORDER BY id DESC LIMIT 1",
                $pi
            ));
            if ($found) {
                return intval($found);
            }
        }
        
        // 4. Session param - check transient for booking info
        if (isset($_GET['session'])) {
            $session = sanitize_text_field($_GET['session']);
            $data = get_transient('ptp_checkout_' . $session);
            if ($data && !empty($data['booking_id'])) {
                return intval($data['booking_id']);
            }
            // Also try to find by trainer from session
            if ($data && !empty($data['trainer_id'])) {
                $found = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE trainer_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1",
                    intval($data['trainer_id'])
                ));
                if ($found) {
                    return intval($found);
                }
            }
        }
        
        // 5. Cookie fallback
        if (isset($_COOKIE['ptp_last_booking'])) {
            return intval($_COOKIE['ptp_last_booking']);
        }
        
        // 6. Recent booking for logged-in user
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $parent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $user_id
            ));
            if ($parent_id) {
                $found = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE parent_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1",
                    $parent_id
                ));
                if ($found) {
                    return intval($found);
                }
            }
        }
        
        return 0;
    }
    
    /**
     * Load booking with all related data
     */
    private function load_booking($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT 
                b.*,
                t.display_name as trainer_name,
                t.photo_url as trainer_photo,
                t.slug as trainer_slug,
                t.headline as trainer_headline,
                t.playing_level as trainer_level,
                p.email as parent_email,
                p.first_name as parent_first,
                p.last_name as parent_last,
                pl.first_name as player_first,
                pl.last_name as player_last
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE b.id = %d
        ", $booking_id));
        
        return $booking;
    }
    
    /**
     * Output the complete thank-you page
     */
    private function output_page($booking, $session_data) {
        // Extract display data
        $trainer_name = '';
        $trainer_photo = '';
        $player_name = '';
        $session_date = '';
        $session_time = '';
        $location = '';
        $package_type = 'single';
        $booking_number = '';
        $total_amount = 0;
        
        // v220: Also check thankyou transient for free session data
        $session_param = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
        $thankyou_data = $session_param ? get_transient('ptp_thankyou_' . $session_param) : null;
        
        if ($booking) {
            $trainer_name = $booking->trainer_name ?? '';
            $trainer_photo = $booking->trainer_photo ?? '';
            $player_name = trim(($booking->player_first ?? '') . ' ' . ($booking->player_last ?? ''));
            $session_date = $booking->session_date ?? '';
            $session_time = $booking->start_time ?? '';
            $location = $booking->location ?? '';
            $package_type = $booking->session_type ?? ($booking->package_type ?? 'single');
            $booking_number = $booking->booking_number ?? ('PTP-' . $booking->id);
            $total_amount = $booking->total_amount ?? 0;
            
            // v222: If booking has empty/invalid date/time/location, fill from transients
            $date_is_empty = empty($session_date) || preg_match('/^0000/', $session_date);
            $time_is_empty = empty($session_time) || $session_time === '00:00:00';
            $loc_is_empty  = empty($location);
            
            if ($date_is_empty || $time_is_empty || $loc_is_empty) {
                // Try thankyou transient first, then checkout transient
                $fallback = $thankyou_data ?: $session_data;
                if (!$fallback && $session_param) {
                    $fallback = get_transient('ptp_checkout_' . $session_param);
                }
                if ($fallback) {
                    ptp_log('[PTP Training Thankyou v222] Filling empty fields from transient: date_empty=' . ($date_is_empty ? 'Y' : 'N') . ', time_empty=' . ($time_is_empty ? 'Y' : 'N') . ', loc_empty=' . ($loc_is_empty ? 'Y' : 'N'));
                    $update_db = array();
                    if ($date_is_empty && !empty($fallback['session_date'])) {
                        $session_date = $fallback['session_date'];
                        $update_db['session_date'] = $session_date;
                    }
                    if ($time_is_empty && !empty($fallback['session_time'])) {
                        $session_time = $fallback['session_time'];
                        $update_db['start_time'] = $session_time;
                    }
                    if ($loc_is_empty && !empty($fallback['session_location'])) {
                        $location = $fallback['session_location'];
                        $update_db['location'] = $location;
                    }
                    if ($package_type === 'single' && !empty($fallback['training_package'])) {
                        $package_type = $fallback['training_package'];
                    }
                    
                    // v222: Write recovered data back to booking so it persists after transient expires
                    if ($booking && !empty($update_db)) {
                        global $wpdb;
                        $wpdb->update(
                            $wpdb->prefix . 'ptp_bookings',
                            $update_db,
                            array('id' => $booking->id)
                        );
                        ptp_log('[PTP Training Thankyou v222] Updated booking #' . $booking->id . ' with recovered data: ' . json_encode($update_db));
                    }
                }
            }
        } elseif ($session_data) {
            $trainer_id = $session_data['trainer_id'] ?? 0;
            if ($trainer_id) {
                global $wpdb;
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT display_name, photo_url FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $trainer_id
                ));
                if ($trainer) {
                    $trainer_name = $trainer->display_name;
                    $trainer_photo = $trainer->photo_url;
                }
            }
            $camper = $session_data['camper_data'] ?? array();
            $player_name = trim(($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? ''));
            $session_date = $session_data['session_date'] ?? '';
            $session_time = $session_data['session_time'] ?? '';
            $location = $session_data['session_location'] ?? '';
            $package_type = $session_data['training_package'] ?? 'single';
            $booking_number = 'Processing...';
            $total_amount = $session_data['training_total'] ?? ($session_data['total'] ?? 0);
        } elseif ($thankyou_data) {
            // v220: Pure thankyou transient fallback (free sessions)
            $trainer_id = $thankyou_data['trainer_id'] ?? 0;
            if ($trainer_id) {
                global $wpdb;
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT display_name, photo_url FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $trainer_id
                ));
                if ($trainer) {
                    $trainer_name = $trainer->display_name;
                    $trainer_photo = $trainer->photo_url;
                }
            }
            $session_date = $thankyou_data['session_date'] ?? '';
            $session_time = $thankyou_data['session_time'] ?? '';
            $location = $thankyou_data['session_location'] ?? '';
            $package_type = $thankyou_data['training_package'] ?? 'single';
            $booking_number = isset($_GET['booking']) ? 'PTP-' . intval($_GET['booking']) : '';
        }
        
        // Format date and time for display
        // v222: Bulletproof zero-date handling — catch 0000-00-00, NULL, negative timestamps, year < 2020
        $date_display = 'To be confirmed';
        if (!empty($session_date) && !preg_match('/^0000/', $session_date)) {
            $ts = strtotime($session_date);
            // Must be a valid timestamp AND year must be 2020+ (prevents -0001, 1970, etc.)
            if ($ts && $ts > 0 && intval(date('Y', $ts)) >= 2020) {
                $date_display = date('l, F j, Y', $ts);
            }
        }
        $time_display = 'To be confirmed';
        if (!empty($session_time) && strpos($session_time, ':') !== false) {
            // v220: Normalize time before display
            if (function_exists('ptp_normalize_session_time')) {
                $session_time = ptp_normalize_session_time($session_time) ?: $session_time;
            }
            $parts = explode(':', $session_time);
            $hour = intval($parts[0]);
            $min = isset($parts[1]) ? str_pad(intval($parts[1]), 2, '0', STR_PAD_LEFT) : '00';
            if ($hour >= 0 && $hour <= 23) {
                $ampm = $hour >= 12 ? 'PM' : 'AM';
                $hour12 = $hour > 12 ? $hour - 12 : ($hour ?: 12);
                $time_display = $hour12 . ':' . $min . ' ' . $ampm;
            }
        }
        
        // Package labels
        $package_labels = array(
            'single' => '1 Session',
            'pack3' => '3-Session Pack',
            'pack5' => '5-Session Pack',
            'pack10' => '10-Session Pack'
        );
        $package_display = $package_labels[$package_type] ?? '1 Session';
        
        // Get home URL safely
        $home_url = function_exists('home_url') ? home_url() : '/';
        
        // Output the page
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Booking Confirmed - PTP Training</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        html {
            height: 100%;
            overflow-x: hidden;
            overflow-y: scroll;
            -webkit-overflow-scrolling: touch;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0A0A0A;
            color: #fff;
            min-height: 100%;
            height: auto;
            overflow-x: hidden;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            position: relative;
        }
        
        .ty-container {
            max-width: 500px;
            margin: 0 auto;
            padding: 24px 16px 60px;
            padding-bottom: calc(60px + env(safe-area-inset-bottom, 0px));
            min-height: 100vh;
            min-height: 100dvh;
        }
        
        .ty-header {
            text-align: center;
            padding: 40px 0 32px;
        }
        
        .ty-check {
            width: 80px;
            height: 80px;
            background: #22C55E;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: scaleIn 0.5s ease;
        }
        
        @keyframes scaleIn {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .ty-check svg {
            width: 40px;
            height: 40px;
            stroke: #fff;
            stroke-width: 3;
        }
        
        .ty-title {
            font-family: 'Oswald', sans-serif;
            font-size: 28px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        
        .ty-subtitle {
            color: rgba(255,255,255,0.7);
            font-size: 16px;
        }
        
        .ty-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
        }
        
        .ty-trainer {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .ty-trainer-photo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            object-fit: cover;
        }
        
        .ty-trainer-info {
            flex: 1;
        }
        
        .ty-trainer-name {
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 4px;
        }
        
        .ty-trainer-label {
            color: #FCB900;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .ty-details {
            display: grid;
            gap: 16px;
        }
        
        .ty-detail {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .ty-detail-label {
            color: rgba(255,255,255,0.5);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .ty-detail-value {
            font-weight: 600;
            text-align: right;
        }
        
        .ty-booking-number {
            text-align: center;
            padding: 16px;
            background: rgba(252,185,0,0.1);
            border: 1px solid rgba(252,185,0,0.3);
            border-radius: 12px;
            margin-top: 20px;
        }
        
        .ty-booking-number-label {
            color: rgba(255,255,255,0.5);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .ty-booking-number-value {
            font-family: 'Oswald', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: #FCB900;
            letter-spacing: 2px;
        }
        
        .ty-next-steps {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .ty-next-steps-title {
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .ty-next-steps-list {
            list-style: none;
            font-size: 14px;
            color: rgba(255,255,255,0.8);
        }
        
        .ty-next-steps-list li {
            padding: 8px 0;
            padding-left: 24px;
            position: relative;
        }
        
        .ty-next-steps-list li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #22C55E;
            font-weight: bold;
        }
        
        .ty-cta {
            display: block;
            width: 100%;
            background: #FCB900;
            color: #0A0A0A;
            text-align: center;
            padding: 18px 24px;
            font-family: 'Oswald', sans-serif;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.2s;
        }
        
        .ty-cta:hover {
            background: #E5A800;
            transform: translateY(-2px);
        }
        
        .ty-secondary-cta {
            display: block;
            width: 100%;
            text-align: center;
            padding: 16px;
            color: rgba(255,255,255,0.7);
            font-size: 14px;
            text-decoration: none;
            margin-top: 12px;
        }
        
        .ty-secondary-cta:hover {
            color: #fff;
        }
    </style>
<!-- v216: Google Ads Purchase Conversion Tracking -->
<script async src="https://www.googletagmanager.com/gtag/js?id=AW-16949170445"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', 'AW-16949170445');
gtag('event', 'conversion', {
    'send_to': 'AW-16949170445/vxJCCNnsgfcbEI2i_5E_',
    'transaction_id': '<?php echo esc_js($booking_number ?: (isset($_GET['payment_intent']) ? sanitize_text_field($_GET['payment_intent']) : '')); ?>'
});
</script>
</head>
<body>
    <div class="ty-container">
        <div class="ty-header">
            <div class="ty-check">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h1 class="ty-title">You're Locked In!</h1>
            <p class="ty-subtitle">Your training session is confirmed</p>
        </div>
        
        <div class="ty-card">
            <?php if ($trainer_name): ?>
            <div class="ty-trainer">
                <?php if ($trainer_photo): ?>
                <img src="<?php echo esc_url($trainer_photo); ?>" alt="<?php echo esc_attr($trainer_name); ?>" class="ty-trainer-photo">
                <?php else: ?>
                <div class="ty-trainer-photo" style="display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:bold;color:#FCB900;">
                    <?php echo esc_html(substr($trainer_name, 0, 1)); ?>
                </div>
                <?php endif; ?>
                <div class="ty-trainer-info">
                    <div class="ty-trainer-name"><?php echo esc_html($trainer_name); ?></div>
                    <div class="ty-trainer-label">Your Trainer</div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="ty-details">
                <?php if ($player_name): ?>
                <div class="ty-detail">
                    <span class="ty-detail-label">Player</span>
                    <span class="ty-detail-value"><?php echo esc_html($player_name); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="ty-detail">
                    <span class="ty-detail-label">Package</span>
                    <span class="ty-detail-value"><?php echo esc_html($package_display); ?></span>
                </div>
                
                <div class="ty-detail">
                    <span class="ty-detail-label">Date</span>
                    <span class="ty-detail-value"><?php echo esc_html($date_display); ?></span>
                </div>
                
                <div class="ty-detail">
                    <span class="ty-detail-label">Time</span>
                    <span class="ty-detail-value"><?php echo esc_html($time_display); ?></span>
                </div>
                
                <?php if ($location): ?>
                <div class="ty-detail">
                    <span class="ty-detail-label">Location</span>
                    <span class="ty-detail-value"><?php echo esc_html($location); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($total_amount > 0): ?>
                <div class="ty-detail">
                    <span class="ty-detail-label">Total Paid</span>
                    <span class="ty-detail-value">$<?php echo number_format($total_amount, 2); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($booking_number && $booking_number !== 'Processing...'): ?>
            <div class="ty-booking-number">
                <div class="ty-booking-number-label">Confirmation Number</div>
                <div class="ty-booking-number-value"><?php echo esc_html($booking_number); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="ty-next-steps">
            <div class="ty-next-steps-title">
                <span>📋</span> What's Next
            </div>
            <ul class="ty-next-steps-list">
                <li>Confirmation email sent to your inbox</li>
                <li>Your trainer will reach out to confirm details</li>
                <li>Bring water, cleats, and a positive attitude!</li>
            </ul>
        </div>
        
        <a href="<?php echo esc_url($home_url); ?>" class="ty-cta">Return Home</a>
        <a href="<?php echo esc_url($home_url . '/find-trainers/'); ?>" class="ty-secondary-cta">Book Another Session</a>
    </div>
</body>
</html>
        <?php
    }
}

// Initialize - only if not already initialized
if (!isset($GLOBALS['ptp_training_thankyou_initialized'])) {
    $GLOBALS['ptp_training_thankyou_initialized'] = true;
    PTP_Training_Thankyou::instance();
}
