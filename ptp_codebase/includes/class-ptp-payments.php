<?php
/**
 * PTP Payments - Robust Payment Processing v113
 * Handles parent payments and trainer payouts
 * Works for individuals - no business required
 * 
 * CHANGELOG v113:
 * - Improved integration with PTP_Stripe class
 * - Better error handling and logging
 * - Added idempotency support for payment intents
 * - Stricter payment verification before completing bookings
 */

defined('ABSPATH') || exit;

class PTP_Payments {
    
    // Platform fee percentage - DEPRECATED: Checkout v77+ uses tiered commission via PTP_Escrow::FEE_SCHEDULE
    // NOTE: This is an integer (25 = 25%), divided by 100 at usage site. See ptp_get_platform_fee() for central config.
    const PLATFORM_FEE_PERCENT = 25;
    
    // Minimum payout amount
    const MIN_PAYOUT_AMOUNT = 10;
    
    public static function init() {
        // Ensure Stripe is initialized
        if (class_exists('PTP_Stripe')) {
            PTP_Stripe::init();
        }
        
        // Payment AJAX endpoints
        add_action('wp_ajax_ptp_process_booking_payment', array(__CLASS__, 'process_booking_payment'));
        add_action('wp_ajax_nopriv_ptp_process_booking_payment', array(__CLASS__, 'process_booking_payment'));
        add_action('wp_ajax_ptp_complete_payment', array(__CLASS__, 'complete_payment'));
        add_action('wp_ajax_nopriv_ptp_complete_payment', array(__CLASS__, 'complete_payment'));
        
        // Trainer payout endpoints (ptp_request_payout handled by PTP_Stripe_Connect_V71)
        // add_action('wp_ajax_ptp_request_payout', array(__CLASS__, 'request_payout')); // Deferred to Stripe Connect v71
        add_action('wp_ajax_ptp_get_earnings_summary', array(__CLASS__, 'get_earnings_summary'));
        
        // Webhook handler for completed sessions
        add_action('ptp_session_completed', array(__CLASS__, 'process_trainer_earnings'), 10, 1);
        
        // Admin payout processing
        add_action('wp_ajax_ptp_admin_process_payout', array(__CLASS__, 'admin_process_payout'));
    }
    
    /**
     * Calculate trainer earnings from booking amount
     */
    public static function calculate_trainer_earnings($total_amount) {
        $platform_fee = round($total_amount * (self::PLATFORM_FEE_PERCENT / 100), 2);
        $trainer_earnings = $total_amount - $platform_fee;
        return array(
            'total' => $total_amount,
            'platform_fee' => $platform_fee,
            'trainer_earnings' => $trainer_earnings,
        );
    }
    
    /**
     * Process booking payment - creates payment intent
     * This is called when parent clicks "Book Session"
     */
    public static function process_booking_payment() {
        // Verify request — only accept the checkout-specific nonce
        $nonce = $_POST['nonce'] ?? '';
        $nonce_valid = wp_verify_nonce($nonce, 'ptp_checkout') || 
                       wp_verify_nonce($nonce, 'ptp_checkout_action');
        
        if (!$nonce_valid) {
            wp_send_json_error(array('message' => 'Session expired. Please refresh the page.'));
            return;
        }
        
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $trainer_id = isset($_POST['trainer_id']) ? intval($_POST['trainer_id']) : 0;
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        
        if (!$booking_id && !$trainer_id) {
            wp_send_json_error(array('message' => 'Missing required information'));
            return;
        }
        
        if ($amount < 0.50) {
            wp_send_json_error(array('message' => 'Amount must be at least $0.50'));
            return;
        }
        
        global $wpdb;
        
        // Get trainer info for Connect
        $trainer = null;
        if ($trainer_id) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $trainer_id
            ));
        } elseif ($booking_id) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                $booking_id
            ));
            if ($booking) {
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $booking->trainer_id
                ));
            }
        }
        
        // Calculate fees
        $earnings = self::calculate_trainer_earnings($amount);
        
        // Build metadata
        $metadata = array(
            'booking_id' => $booking_id,
            'trainer_id' => $trainer ? $trainer->id : 0,
            'platform' => 'ptp_soccer',
            'platform_fee' => $earnings['platform_fee'],
            'trainer_earnings' => $earnings['trainer_earnings'],
        );
        
        // Add trainer's Stripe account if using Connect
        if ($trainer && !empty($trainer->stripe_account_id) && $trainer->stripe_charges_enabled) {
            $metadata['trainer_stripe_account'] = $trainer->stripe_account_id;
        }
        
        // Add customer info for Stripe Customer attachment
        $pay_user = wp_get_current_user();
        if ($pay_user && $pay_user->ID) {
            $metadata['customer_email'] = $pay_user->user_email;
            $metadata['customer_name'] = $pay_user->display_name;
        } elseif (isset($booking) && $booking) {
            // Guest checkout fallback
            if (!empty($booking->guest_email)) $metadata['customer_email'] = $booking->guest_email;
            if (!empty($booking->guest_name)) $metadata['customer_name'] = $booking->guest_name;
        }
        
        // Generate idempotency key for this booking
        $idempotency_key = $booking_id ? 'ptp_booking_' . $booking_id . '_' . time() : 'ptp_trainer_' . $trainer_id . '_' . time();
        
        // Create payment intent
        $intent = PTP_Stripe::create_payment_intent($amount, $metadata, $idempotency_key);
        
        if (is_wp_error($intent)) {
            ptp_log('PTP Payment Error: ' . $intent->get_error_message());
            wp_send_json_error(array(
                'message' => 'Unable to process payment: ' . $intent->get_error_message(),
                'debug' => WP_DEBUG ? $intent->get_error_data() : null
            ));
            return;
        }
        
        // Update booking with payment intent ID
        if ($booking_id) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'payment_intent_id' => $intent['id'],
                    'payment_status' => 'pending',
                    'platform_fee' => $earnings['platform_fee'],
                    'trainer_earnings' => $earnings['trainer_earnings'],
                ),
                array('id' => $booking_id)
            );
        }
        
        wp_send_json_success(array(
            'client_secret' => $intent['client_secret'],
            'payment_intent_id' => $intent['id'],
            'amount' => $amount,
            'earnings' => $earnings,
        ));
    }
    
    /**
     * Complete payment after Stripe confirmation
     * Creates escrow hold - funds NOT released to trainer yet
     */
    public static function complete_payment() {
        // Verify nonce with multiple fallbacks
        $nonce = $_POST['nonce'] ?? '';
        $nonce_valid = wp_verify_nonce($nonce, 'ptp_nonce') || 
                       wp_verify_nonce($nonce, 'ptp_checkout_action') ||
                       wp_verify_nonce($nonce, 'ptp_checkout');
        
        if (!$nonce_valid) {
            wp_send_json_error(array('message' => 'Session expired. Please refresh the page.'));
            return;
        }
        
        $payment_intent_id = isset($_POST['payment_intent_id']) ? sanitize_text_field($_POST['payment_intent_id']) : '';
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        
        if (!$payment_intent_id) {
            wp_send_json_error(array('message' => 'Missing payment information'));
            return;
        }
        
        // CRITICAL: Verify payment with Stripe first
        $intent = PTP_Stripe::get_payment_intent($payment_intent_id);
        
        if (is_wp_error($intent)) {
            ptp_log('PTP Payment Verification Error: ' . $intent->get_error_message());
            wp_send_json_error(array('message' => 'Unable to verify payment. Please contact support.'));
            return;
        }
        
        // Check payment status
        $status = $intent['status'] ?? '';
        if ($status !== 'succeeded' && $status !== 'processing') {
            ptp_log('PTP Payment Status Invalid: ' . $status . ' for intent ' . $payment_intent_id);
            wp_send_json_error(array(
                'message' => 'Payment was not completed successfully.',
                'status' => $status
            ));
            return;
        }
        
        global $wpdb;
        
        // Update booking status
        if ($booking_id) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                $booking_id
            ));
            
            if (!$booking) {
                wp_send_json_error(array('message' => 'Booking not found'));
                return;
            }
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                    'paid_at' => current_time('mysql'),
                    'funds_held' => 1, // Funds are held in escrow
                ),
                array('id' => $booking_id)
            );
            
            // Create escrow hold - FUNDS ARE NOT RELEASED TO TRAINER YET
            if (class_exists('PTP_Escrow')) {
                $escrow_id = PTP_Escrow::create_hold(
                    $booking_id,
                    $payment_intent_id,
                    $booking->total_amount
                );
                
                if (is_wp_error($escrow_id)) {
                    ptp_log('PTP Escrow Error: ' . $escrow_id->get_error_message());
                }
            }
            
            // v136: Send emails via hook (handles dedup)
            do_action('ptp_training_booking_completed', $booking_id);
            
            // Send SMS notifications
            if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
                PTP_SMS::send_booking_confirmation($booking_id);
                PTP_SMS::send_trainer_new_booking($booking_id);
            }
            
            // Get booking details for response
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, t.display_name as trainer_name 
                 FROM {$wpdb->prefix}ptp_bookings b
                 JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 WHERE b.id = %d",
                $booking_id
            ));
            
            wp_send_json_success(array(
                'message' => 'Payment successful! Your session is confirmed.',
                'booking_id' => $booking_id,
                'booking_number' => 'PTP-' . str_pad($booking_id, 6, '0', STR_PAD_LEFT),
                'redirect' => home_url('/my-training/?booking=' . $booking_id),
                'trainer_name' => $booking ? $booking->trainer_name : '',
                'escrow_note' => 'Payment held securely until session is completed.',
            ));
        }
        
        wp_send_json_success(array('message' => 'Payment successful!'));
    }
    
    /**
     * Get trainer earnings summary
     */
    public static function get_earnings_summary() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        global $wpdb;
        
        // Get earnings data - v222.1: trainer_earnings→trainer_payout, payout_status uses 'pending'/'completed'
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN trainer_payout ELSE 0 END), 0) as total_earned,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' AND payout_status = 'pending' THEN trainer_payout ELSE 0 END), 0) as pending_payout,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' AND payout_status = 'completed' THEN trainer_payout ELSE 0 END), 0) as total_paid_out,
                COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_sessions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_sessions
            FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d
        ", $trainer->id));
        
        // Get recent transactions
        $recent = $wpdb->get_results($wpdb->prepare("
            SELECT 
                b.id,
                b.session_date,
                b.total_amount,
                b.trainer_earnings,
                b.payment_status,
                b.payout_status,
                b.paid_at,
                p.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            WHERE b.trainer_id = %d AND b.payment_status = 'paid'
            ORDER BY b.paid_at DESC
            LIMIT 10
        ", $trainer->id));
        
        // Get Stripe account status
        $stripe_status = 'not_connected';
        $stripe_details = null;
        
        if (!empty($trainer->stripe_account_id)) {
            $account = PTP_Stripe::get_account($trainer->stripe_account_id);
            if (!is_wp_error($account)) {
                $stripe_status = $account['charges_enabled'] && $account['payouts_enabled'] ? 'active' : 'pending';
                $stripe_details = array(
                    'charges_enabled' => $account['charges_enabled'],
                    'payouts_enabled' => $account['payouts_enabled'],
                    'details_submitted' => $account['details_submitted'],
                );
            }
        }
        
        wp_send_json_success(array(
            'total_earned' => floatval($stats->total_earned),
            'pending_payout' => floatval($stats->pending_payout),
            'total_paid_out' => floatval($stats->total_paid_out),
            'paid_sessions' => intval($stats->paid_sessions),
            'completed_sessions' => intval($stats->completed_sessions),
            'recent_transactions' => $recent,
            'stripe_status' => $stripe_status,
            'stripe_details' => $stripe_details,
            'can_receive_payouts' => $stripe_status === 'active',
            'min_payout' => self::MIN_PAYOUT_AMOUNT,
        ));
    }
    
    /**
     * Request payout for trainer
     */
    public static function request_payout() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        // Check Stripe Connect status
        if (empty($trainer->stripe_account_id)) {
            wp_send_json_error(array(
                'message' => 'Please connect your bank account first to receive payouts.',
                'action' => 'connect_stripe'
            ));
        }
        
        // Verify account is active
        $account = PTP_Stripe::get_account($trainer->stripe_account_id);
        if (is_wp_error($account) || !$account['payouts_enabled']) {
            wp_send_json_error(array(
                'message' => 'Your payout account is not fully set up. Please complete your Stripe setup.',
                'action' => 'complete_stripe'
            ));
        }
        
        global $wpdb;
        
        // Get pending earnings - v222.1: trainer_earnings→trainer_payout
        $pending = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(trainer_payout), 0)
            FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d 
            AND payment_status = 'paid' 
            AND (payout_status IS NULL OR payout_status = 'pending')
            AND status IN ('confirmed', 'completed')
        ", $trainer->id));
        
        if ($pending < self::MIN_PAYOUT_AMOUNT) {
            wp_send_json_error(array(
                'message' => 'Minimum payout amount is $' . self::MIN_PAYOUT_AMOUNT . '. Current balance: $' . number_format($pending, 2),
            ));
        }
        
        // Create transfer to connected account
        $transfer = PTP_Stripe::create_payout($trainer->id, $pending);
        
        if (is_wp_error($transfer)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ptp_log('PTP Payout Error: ' . $transfer->get_error_message());
            }
            wp_send_json_error(array(
                'message' => 'Unable to process payout. Please try again or contact support.'
            ));
        }
        
        // Update booking payout status - v222.1: fixed status value and column name
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->prefix}ptp_bookings
            SET payout_status = 'completed', payout_date = %s
            WHERE trainer_id = %d 
            AND payment_status = 'paid' 
            AND (payout_status IS NULL OR payout_status = 'pending')
        ", current_time('mysql'), $trainer->id));
        
        // Log the payout
        $wpdb->insert(
            $wpdb->prefix . 'ptp_payouts',
            array(
                'trainer_id' => $trainer->id,
                'amount' => $pending,
                'stripe_transfer_id' => $transfer['id'] ?? '',
                'status' => 'completed',
                'created_at' => current_time('mysql'),
            )
        );
        
        // Send notification
        if (class_exists('PTP_Email')) {
            PTP_Email::send_payout_notification($trainer->id, $pending);
        }
        
        wp_send_json_success(array(
            'message' => 'Payout of $' . number_format($pending, 2) . ' initiated! Funds will arrive in 2-3 business days.',
            'amount' => $pending,
            'transfer_id' => $transfer['id'] ?? null,
        ));
    }
    
    /**
     * Admin: Process payout for trainer
     */
    public static function admin_process_payout() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $trainer_id = isset($_POST['trainer_id']) ? intval($_POST['trainer_id']) : 0;
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        
        if (!$trainer_id || !$amount) {
            wp_send_json_error(array('message' => 'Missing required data'));
        }
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        // Create transfer
        $transfer = PTP_Stripe::create_payout($trainer_id, $amount);
        
        if (is_wp_error($transfer)) {
            wp_send_json_error(array('message' => $transfer->get_error_message()));
        }
        
        // Log payout
        $wpdb->insert(
            $wpdb->prefix . 'ptp_payouts',
            array(
                'trainer_id' => $trainer_id,
                'amount' => $amount,
                'stripe_transfer_id' => $transfer['id'] ?? 'manual',
                'status' => 'completed',
                'processed_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            )
        );
        
        wp_send_json_success(array(
            'message' => 'Payout processed successfully',
            'amount' => $amount,
        ));
    }
    
    /**
     * Process trainer earnings after session completion
     */
    public static function process_trainer_earnings($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking || $booking->payment_status !== 'paid') {
            return;
        }
        
        // Mark as ready for payout if using Connect with instant payouts
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $booking->trainer_id
        ));
        
        if ($trainer && $trainer->stripe_account_id && $trainer->stripe_payouts_enabled) {
            // With Connect, payments go directly to trainer minus platform fee
            // No additional action needed - Stripe handles it
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('payout_status' => 'completed'),
                array('id' => $booking_id)
            );
        }
    }
    
    /**
     * Check if trainer can receive payments
     */
    public static function trainer_can_receive_payments($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer || empty($trainer->stripe_account_id)) {
            return false;
        }
        
        // Check cached status first
        if ($trainer->stripe_charges_enabled && $trainer->stripe_payouts_enabled) {
            return true;
        }
        
        // Verify with Stripe
        $account = PTP_Stripe::get_account($trainer->stripe_account_id);
        
        if (is_wp_error($account)) {
            return false;
        }
        
        // Update cached status
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'stripe_charges_enabled' => $account['charges_enabled'] ? 1 : 0,
                'stripe_payouts_enabled' => $account['payouts_enabled'] ? 1 : 0,
            ),
            array('id' => $trainer_id)
        );
        
        // v222.1: Bust trainer object cache
        wp_cache_delete('ptp_trainer_' . $trainer_id, 'ptp');
        
        return $account['charges_enabled'] && $account['payouts_enabled'];
    }
    
    /**
     * Get payment methods saved for user
     */
    public static function get_saved_payment_methods($user_id) {
        $customer_id = get_user_meta($user_id, 'ptp_stripe_customer_id', true);
        
        if (!$customer_id) {
            return array();
        }
        
        // This would call Stripe to get saved payment methods
        // For now, return empty - can be implemented with Stripe Customer API
        return array();
    }
    
    /**
     * Refund a booking
     */
    public static function refund_booking($booking_id, $reason = 'requested_by_customer', $refund_amount = null) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            return new WP_Error('not_found', 'Booking not found');
        }
        
        if ($booking->payment_status !== 'paid') {
            return new WP_Error('not_paid', 'Booking has not been paid');
        }
        
        if (!$booking->payment_intent_id) {
            return new WP_Error('no_payment', 'No payment record found');
        }
        
        // Process refund through Stripe
        $refund = PTP_Stripe::create_refund(
            $booking->payment_intent_id,
            $refund_amount,
            $reason
        );
        
        if (is_wp_error($refund)) {
            return $refund;
        }
        
        // Update booking status
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'cancelled',
                'payment_status' => $refund_amount && $refund_amount < $booking->total_amount ? 'partial_refund' : 'refunded',
                'refund_amount' => $refund_amount ?: $booking->total_amount,
                'refunded_at' => current_time('mysql'),
            ),
            array('id' => $booking_id)
        );
        
        // Send notifications
        if (class_exists('PTP_Email')) {
            PTP_Email::send_cancellation_confirmation($booking_id);
        }
        
        return $refund;
    }
    
    /**
     * Get trainer payouts
     */
    public static function get_trainer_payouts($trainer_id, $limit = 10) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_payouts';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return array();
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_payouts 
             WHERE trainer_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d",
            $trainer_id,
            $limit
        ));
    }
    
    /**
     * Get payout stats for admin
     */
    public static function get_payout_stats() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_payouts';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return (object) array(
                'pending_count' => 0,
                'pending_total' => 0,
                'paid_30_days' => 0,
                'paid_all_time' => 0
            );
        }
        
        $stats = new stdClass();
        $stats->pending_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'") ?: 0;
        $stats->pending_total = $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = 'pending'") ?: 0;
        $stats->paid_30_days = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = 'completed' AND processed_at >= %s",
            date('Y-m-d', strtotime('-30 days'))
        )) ?: 0;
        $stats->paid_all_time = $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = 'completed'") ?: 0;
        
        return $stats;
    }
    
    /**
     * Get all pending payouts
     */
    public static function get_all_pending_payouts() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_payouts';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return array();
        }
        
        return $wpdb->get_results("
            SELECT p.*, t.display_name as trainer_name, COALESCE(t.email, '') as trainer_email
            FROM {$wpdb->prefix}ptp_payouts p
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
            WHERE p.status = 'pending'
            ORDER BY p.created_at DESC
        ");
    }
    /**
     * Get trainer earnings summary
     */
    public static function get_trainer_earnings($trainer_id) {
        global $wpdb;
        
        $earnings = array(
            'this_month' => 0,
            'total_earnings' => 0,
            'pending_payout' => 0
        );
        
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") != $bookings_table) {
            return $earnings;
        }
        
        // This month's earnings
        $earnings['this_month'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status IN ('completed', 'confirmed') 
             AND MONTH(session_date) = MONTH(CURRENT_DATE()) 
             AND YEAR(session_date) = YEAR(CURRENT_DATE())",
            $trainer_id
        )) ?: 0;
        
        // Total earnings
        $earnings['total_earnings'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status IN ('completed', 'confirmed')",
            $trainer_id
        )) ?: 0;
        
        // Pending payout - v222.1: Read from bookings (where earnings accumulate), not payouts log
        $earnings['pending_payout'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'",
            $trainer_id
        )) ?: 0;
        
        return $earnings;
    }
}

// Initialize
PTP_Payments::init();
