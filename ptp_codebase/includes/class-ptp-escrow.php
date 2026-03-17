<?php
/**
 * PTP Escrow - Secure Payment Hold System
 * Funds are held until session is confirmed complete
 * Version 25.1
 * 
 * FLOW:
 * 1. Parent pays → Funds captured to PTP platform account (NOT sent to trainer yet)
 * 2. Session occurs
 * 3. Trainer marks session "complete" 
 * 4. Parent has 24 hours to confirm or dispute
 * 5. After confirmation OR 24hr auto-release → Funds transfer to trainer
 * 6. If disputed → Admin reviews and decides
 */

defined('ABSPATH') || exit;

class PTP_Escrow {
    
    // Hours before auto-release if no dispute
    const AUTO_RELEASE_HOURS = 24;
    
    // Hours after session for trainer to mark complete
    const COMPLETION_WINDOW_HOURS = 48;
    
    /**
     * Graduated platform fee schedule for new parent-trainer relationships
     * Key = session number (1-indexed), Value = PTP platform fee percentage
     * After the defined sessions, the final rate applies to all future sessions
     */
    const FEE_SCHEDULE = array(
        1 => 0.50,  // Sessions 1-2: 50% to PTP
        2 => 0.50,
        3 => 0.25,  // Sessions 3-4: 25% to PTP
        4 => 0.25,
    );
    const FEE_ONGOING = 0.15; // Session 5+: 15% to PTP
    
    // Escrow statuses
    const STATUS_HOLDING = 'holding';           // Payment captured, awaiting session
    const STATUS_SESSION_COMPLETE = 'session_complete'; // Trainer marked complete
    const STATUS_CONFIRMED = 'confirmed';       // Parent confirmed
    const STATUS_DISPUTED = 'disputed';         // Parent disputed
    const STATUS_RELEASED = 'released';         // Funds sent to trainer
    const STATUS_REFUNDED = 'refunded';         // Funds returned to parent
    
    public static function init() {
        // AJAX endpoints
        add_action('wp_ajax_ptp_trainer_complete_session', array(__CLASS__, 'trainer_complete_session'));
        add_action('wp_ajax_ptp_parent_confirm_session', array(__CLASS__, 'parent_confirm_session'));
        add_action('wp_ajax_ptp_parent_dispute_session', array(__CLASS__, 'parent_dispute_session'));
        // NOTE: admin_resolve_dispute handled by PTP_Admin_Payouts_V3 to avoid duplicate registration
        add_action('wp_ajax_ptp_admin_release_funds', array(__CLASS__, 'admin_release_funds'));
        
        // One-click email confirmation (no login required)
        add_action('init', array(__CLASS__, 'handle_email_confirm'), 20);
        
        // Cron for auto-release
        add_action('ptp_process_escrow_releases', array(__CLASS__, 'process_auto_releases'));
        
        // Schedule cron if not scheduled
        if (!wp_next_scheduled('ptp_process_escrow_releases')) {
            wp_schedule_event(time(), 'hourly', 'ptp_process_escrow_releases');
        }
    }
    
    /**
     * Create escrow hold when payment is captured
     * Called after successful payment
     */
    public static function create_hold($booking_id, $payment_intent_id, $amount) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            return new WP_Error('booking_not_found', 'Booking not found');
        }
        
        // Calculate graduated fee based on parent-trainer session history
        $session_number = self::get_session_number($booking->trainer_id, $booking->parent_id);
        $fee_rate = self::get_fee_rate($session_number);
        $platform_fee = round($amount * $fee_rate, 2);
        $trainer_amount = $amount - $platform_fee;
        
        // Create escrow record
        $result = $wpdb->insert(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'booking_id' => $booking_id,
                'trainer_id' => $booking->trainer_id,
                'parent_id' => $booking->parent_id,
                'payment_intent_id' => $payment_intent_id,
                'total_amount' => $amount,
                'platform_fee' => $platform_fee,
                'trainer_amount' => $trainer_amount,
                'fee_rate' => $fee_rate,
                'session_number' => $session_number,
                'status' => self::STATUS_HOLDING,
                'session_date' => $booking->session_date,
                'session_time' => $booking->start_time,
                'created_at' => current_time('mysql'),
                'release_eligible_at' => null, // Set when trainer marks complete
            ),
            array('%d', '%d', '%d', '%s', '%f', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        
        if (!$result) {
            return new WP_Error('escrow_failed', 'Failed to create escrow hold');
        }
        
        $escrow_id = $wpdb->insert_id;
        
        // Update booking with escrow info
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'escrow_id' => $escrow_id,
                'escrow_status' => self::STATUS_HOLDING,
                'funds_held' => 1,
            ),
            array('id' => $booking_id)
        );
        
        // Log the hold
        self::log_event($escrow_id, 'hold_created', sprintf(
            'Payment of $%s held in escrow (session #%d with this parent, %d%% platform fee)',
            number_format($amount, 2),
            $session_number,
            intval($fee_rate * 100)
        ));
        
        return $escrow_id;
    }
    
    /**
     * Get the session number for this parent-trainer pair
     * Counts completed/held/released escrow records + 1 for the current session
     * Excludes free sessions ($0 total) so they don't consume graduated tiers
     * 
     * @param int $trainer_id
     * @param int $parent_id
     * @return int Session number (1-indexed)
     */
    public static function get_session_number($trainer_id, $parent_id) {
        global $wpdb;
        
        $completed_statuses = array(
            self::STATUS_HOLDING,
            self::STATUS_SESSION_COMPLETE,
            self::STATUS_CONFIRMED,
            self::STATUS_RELEASED,
        );
        $placeholders = implode(',', array_fill(0, count($completed_statuses), '%s'));
        
        $prior_sessions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_escrow 
             WHERE trainer_id = %d AND parent_id = %d AND status IN ($placeholders)
             AND total_amount > 0",
            array_merge(array($trainer_id, $parent_id), $completed_statuses)
        ));
        
        // +1 because this is the NEXT session
        return $prior_sessions + 1;
    }
    
    /**
     * Get platform fee rate based on session number in parent-trainer relationship
     * 
     * Schedule: 50% / 50% / 25% / 25% / 15% thereafter
     * 
     * @param int $session_number 1-indexed session number
     * @return float Fee rate (e.g. 0.50 for 50%)
     */
    public static function get_fee_rate($session_number) {
        if (isset(self::FEE_SCHEDULE[$session_number])) {
            return self::FEE_SCHEDULE[$session_number];
        }
        return self::FEE_ONGOING;
    }
    
    /**
     * Trainer marks session as complete
     */
    public static function trainer_complete_session() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Invalid booking'));
        }
        
        try {
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        global $wpdb;
        
        // Get escrow record — safely check if escrow table exists first
        $escrow = null;
        $escrow_table = $wpdb->prefix . 'ptp_escrow';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$escrow_table}'") === $escrow_table) {
            $escrow = $wpdb->get_row($wpdb->prepare("
                SELECT e.*, b.session_date, b.start_time
                FROM {$escrow_table} e
                JOIN {$wpdb->prefix}ptp_bookings b ON e.booking_id = b.id
                WHERE e.booking_id = %d AND e.trainer_id = %d
            ", $booking_id, $trainer->id));
        }
        
        // FALLBACK: If no escrow record exists (legacy booking), complete directly
        if (!$escrow) {
            // Get the booking to verify ownership and get details
            $booking = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}ptp_bookings 
                WHERE id = %d AND trainer_id = %d
            ", $booking_id, $trainer->id));
            
            if (!$booking) {
                wp_send_json_error(array('message' => 'Booking not found or access denied'));
            }
            
            // For legacy bookings without escrow, complete directly (no 24hr hold)
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'status' => 'completed',
                    'trainer_confirmed' => 1,
                    'trainer_confirmed_at' => current_time('mysql'),
                    'completed_at' => current_time('mysql'),
                ),
                array('id' => $booking_id)
            );
            
            // Fire completion hooks safely
            try {
                do_action('ptp_session_completed', $booking_id, $booking);
                do_action('ptp_booking_completed', $booking_id, $booking);
            } catch (\Throwable $hook_e) {
                ptp_log('PTP Escrow: Hook error on legacy complete for booking ' . $booking_id . ': ' . $hook_e->getMessage());
            }
            
            wp_send_json_success(array(
                'message' => 'Session marked complete!',
                'legacy_mode' => true,
            ));
            return;
        }
        
        if ($escrow->status !== self::STATUS_HOLDING) {
            wp_send_json_error(array('message' => 'Session already processed'));
        }
        
        // Check if session time has passed — use start_time from joined booking data
        $session_time = $escrow->start_time ?: ($escrow->session_time ?? '00:00:00');
        $session_datetime = strtotime(($escrow->session_date ?: date('Y-m-d')) . ' ' . $session_time);
        // Skip time check for very old sessions (more than 7 days ago)
        if ($session_datetime && (time() - $session_datetime) < -300) {
            // Only block if session is more than 5 minutes in the future
            wp_send_json_error(array('message' => 'Cannot complete session before scheduled time'));
        }
        
        // Calculate when funds become eligible for auto-release
        $release_eligible = date('Y-m-d H:i:s', strtotime('+' . self::AUTO_RELEASE_HOURS . ' hours'));
        
        // Update escrow status
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => self::STATUS_SESSION_COMPLETE,
                'trainer_completed_at' => current_time('mysql'),
                'release_eligible_at' => $release_eligible,
            ),
            array('id' => $escrow->id)
        );
        
        // Update booking — only use columns that exist
        $booking_update = array(
            'status' => 'completed',
            'completed_at' => current_time('mysql'),
        );
        // Only set escrow_status if column exists
        $booking_cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings", 0);
        if (in_array('escrow_status', $booking_cols)) {
            $booking_update['escrow_status'] = self::STATUS_SESSION_COMPLETE;
        }
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            $booking_update,
            array('id' => $booking_id)
        );
        
        // Log event
        if (method_exists(__CLASS__, 'log_event')) {
            self::log_event($escrow->id, 'trainer_completed', 'Trainer marked session as complete');
        }
        
        // v216: Fire completion hooks safely (triggers recap prompt, camp cross-sell, SMS follow-up, stats update)
        $booking_obj = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d", $booking_id
        ));
        try {
            do_action('ptp_session_completed', $booking_id, $booking_obj);
            do_action('ptp_booking_completed', $booking_id, $booking_obj);
        } catch (\Throwable $hook_e) {
            ptp_log('PTP Escrow: Hook error on complete for booking ' . $booking_id . ': ' . $hook_e->getMessage());
        }
        
        // Update trainer session count
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_trainers SET total_sessions = total_sessions + 1 WHERE id = %d",
            $trainer->id
        ));
        
        // Notify parent to confirm
        try {
            self::notify_parent_to_confirm($escrow->id);
        } catch (\Throwable $notify_e) {
            ptp_log('PTP Escrow: Notification error for escrow ' . $escrow->id . ': ' . $notify_e->getMessage());
        }
        
        // Notify trainer that session is awaiting confirmation
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_trainer_awaiting_confirmation')) {
            try {
                PTP_Email::send_trainer_awaiting_confirmation($booking_id);
            } catch (\Throwable $email_e) {
                ptp_log('PTP Escrow: Email error for booking ' . $booking_id . ': ' . $email_e->getMessage());
            }
        }
        
        wp_send_json_success(array(
            'message' => 'Session marked complete! The parent has ' . self::AUTO_RELEASE_HOURS . ' hours to confirm. Funds will be released automatically after that.',
            'release_eligible_at' => $release_eligible,
        ));
        
        } catch (\Throwable $e) {
            ptp_log('PTP Escrow trainer_complete_session fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(array('message' => 'Server error: ' . $e->getMessage()));
        }
    }
    
    /**
     * Parent confirms session was completed satisfactorily
     */
    public static function parent_confirm_session() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
        $feedback = isset($_POST['feedback']) ? sanitize_textarea_field($_POST['feedback']) : '';
        
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Invalid booking'));
        }
        
        global $wpdb;
        
        // Get parent
        $parent = PTP_Parent::get_by_user_id(get_current_user_id());
        if (!$parent) {
            wp_send_json_error(array('message' => 'Parent not found'));
        }
        
        // Get escrow record
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_escrow
            WHERE booking_id = %d AND parent_id = %d
        ", $booking_id, $parent->id));
        
        if (!$escrow) {
            wp_send_json_error(array('message' => 'Escrow record not found'));
        }
        
        if (!in_array($escrow->status, array(self::STATUS_SESSION_COMPLETE, self::STATUS_HOLDING))) {
            wp_send_json_error(array('message' => 'Session already processed'));
        }
        
        // Update escrow status
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => self::STATUS_CONFIRMED,
                'parent_confirmed_at' => current_time('mysql'),
                'parent_rating' => $rating ?: null,
                'parent_feedback' => $feedback ?: null,
            ),
            array('id' => $escrow->id)
        );
        
        // Log event
        self::log_event($escrow->id, 'parent_confirmed', 'Parent confirmed session completion');
        
        // Release funds immediately
        $release_result = self::release_funds($escrow->id);
        
        if (is_wp_error($release_result)) {
            // Still mark as confirmed, admin will need to manually release
            wp_send_json_success(array(
                'message' => 'Session confirmed! Funds will be released to the trainer shortly.',
            ));
        }
        
        wp_send_json_success(array(
            'message' => 'Session confirmed! Funds have been released to the trainer.',
        ));
    }
    
    /**
     * Parent disputes session
     */
    public static function parent_dispute_session() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';
        
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Invalid booking'));
        }
        
        if (empty($reason)) {
            wp_send_json_error(array('message' => 'Please provide a reason for the dispute'));
        }
        
        global $wpdb;
        
        // Get parent
        $parent = PTP_Parent::get_by_user_id(get_current_user_id());
        if (!$parent) {
            wp_send_json_error(array('message' => 'Parent not found'));
        }
        
        // Get escrow record
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_escrow
            WHERE booking_id = %d AND parent_id = %d
        ", $booking_id, $parent->id));
        
        if (!$escrow) {
            wp_send_json_error(array('message' => 'Escrow record not found'));
        }
        
        if (!in_array($escrow->status, array(self::STATUS_SESSION_COMPLETE, self::STATUS_HOLDING))) {
            wp_send_json_error(array('message' => 'Cannot dispute this session'));
        }
        
        // Update escrow status
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => self::STATUS_DISPUTED,
                'disputed_at' => current_time('mysql'),
                'dispute_reason' => $reason,
            ),
            array('id' => $escrow->id)
        );
        
        // Update booking
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array('escrow_status' => self::STATUS_DISPUTED),
            array('id' => $booking_id)
        );
        
        // Log event
        self::log_event($escrow->id, 'disputed', 'Parent disputed session: ' . $reason);
        
        // Notify admin
        self::notify_admin_dispute($escrow->id);
        
        // Notify trainer
        self::notify_trainer_dispute($escrow->id);
        
        wp_send_json_success(array(
            'message' => 'Dispute submitted. Our team will review and contact both parties within 24-48 hours.',
        ));
    }
    
    /**
     * Release funds to trainer
     */
    public static function release_funds($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, t.stripe_account_id, t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_escrow e
             JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
             WHERE e.id = %d",
            $escrow_id
        ));
        
        if (!$escrow) {
            return new WP_Error('not_found', 'Escrow record not found');
        }
        
        if ($escrow->status === self::STATUS_RELEASED) {
            return new WP_Error('already_released', 'Funds already released');
        }
        
        if ($escrow->status === self::STATUS_REFUNDED) {
            return new WP_Error('already_refunded', 'Funds already refunded');
        }
        
        // v228: CRITICAL — Check if this booking was already paid out by the dashboard
        // or cron payout system before the escrow auto-release fired.
        // Without this check: trainer clicks "Cash Out" (sets payout_status=completed),
        // then 24hrs later escrow auto-release fires and pays the same booking again.
        $booking_payout_status = $wpdb->get_var($wpdb->prepare(
            "SELECT payout_status FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $escrow->booking_id
        ));
        
        if ($booking_payout_status === 'completed') {
            // Already paid via dashboard or cron — mark escrow as released without transferring
            $wpdb->update(
                $wpdb->prefix . 'ptp_escrow',
                array(
                    'status' => self::STATUS_RELEASED,
                    'released_at' => current_time('mysql'),
                    'release_method' => 'already_paid',
                    'release_notes' => 'Booking already paid out via dashboard/cron. Escrow closed without duplicate transfer.',
                ),
                array('id' => $escrow_id)
            );
            
            self::log_event($escrow_id, 'release_skipped', 'Booking #' . $escrow->booking_id . ' already has payout_status=completed — skipped to prevent double payment');
            ptp_log('[PTP Escrow v228] DOUBLE PAYMENT PREVENTED: Escrow #' . $escrow_id . ' booking #' . $escrow->booking_id . ' already paid out');
            
            return array('status' => 'already_paid', 'amount' => $escrow->trainer_amount);
        }
        
        // Check if trainer has Stripe Connect
        if (empty($escrow->stripe_account_id)) {
            // Mark as pending manual payout
            $wpdb->update(
                $wpdb->prefix . 'ptp_escrow',
                array(
                    'status' => self::STATUS_RELEASED,
                    'released_at' => current_time('mysql'),
                    'release_method' => 'pending_manual',
                    'release_notes' => 'Trainer does not have Stripe Connect. Manual payout required.',
                ),
                array('id' => $escrow_id)
            );
            
            // v228: Record in ptp_payouts so Instant Pay balance calc doesn't double-count
            $wpdb->insert(
                $wpdb->prefix . 'ptp_payouts',
                array(
                    'trainer_id'       => $escrow->trainer_id,
                    'amount'           => $escrow->trainer_amount,
                    'fee'              => 0,
                    'gross_amount'     => $escrow->trainer_amount,
                    'status'           => 'pending_manual',
                    'payout_method'    => 'pending_manual',
                    'booking_count'    => 1,
                    'created_at'       => current_time('mysql'),
                )
            );
            
            self::log_event($escrow_id, 'release_pending', 'Funds marked for manual payout - trainer needs Stripe Connect');
            
            return array('status' => 'pending_manual');
        }
        
        // Transfer funds to trainer via Stripe Connect
        // v228: Idempotency key prevents duplicate transfers if PHP times out after
        // Stripe processes the transfer but before our DB update succeeds
        $idempotency_key = 'escrow_release_' . $escrow_id . '_' . $escrow->booking_id;
        
        $transfer = PTP_Stripe::create_transfer(
            round($escrow->trainer_amount * 100), // cents
            $escrow->stripe_account_id,
            'Session payment - Booking #' . $escrow->booking_id,
            $idempotency_key
        );
        
        if (is_wp_error($transfer)) {
            self::log_event($escrow_id, 'release_failed', 'Transfer failed: ' . $transfer->get_error_message());
            return $transfer;
        }
        
        // Update escrow record
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => self::STATUS_RELEASED,
                'released_at' => current_time('mysql'),
                'stripe_transfer_id' => $transfer['id'],
                'release_method' => 'stripe_connect',
            ),
            array('id' => $escrow_id)
        );
        
        // Update booking
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'escrow_status' => self::STATUS_RELEASED,
                'payout_status' => 'completed',
                'payout_date' => current_time('mysql'),
                'stripe_transfer_id' => $transfer['id'],
            ),
            array('id' => $escrow->booking_id)
        );
        
        // v228: Record in ptp_payouts table — CRITICAL
        // Without this, PTP_Instant_Pay::get_earnings_data() calculates:
        //   available = SUM(bookings.trainer_payout) - SUM(payouts.amount)
        // If the escrow transfer isn't in ptp_payouts, the balance shows the
        // full amount as available and the trainer can request a second payout.
        $wpdb->insert(
            $wpdb->prefix . 'ptp_payouts',
            array(
                'trainer_id'         => $escrow->trainer_id,
                'amount'             => $escrow->trainer_amount,
                'fee'                => 0,
                'gross_amount'       => $escrow->trainer_amount,
                'status'             => 'completed',
                'payout_method'      => 'escrow_release',
                'payout_reference'   => $transfer['id'],
                'stripe_transfer_id' => $transfer['id'],
                'booking_count'      => 1,
                'created_at'         => current_time('mysql'),
                'processed_at'       => current_time('mysql'),
            )
        );
        if ($wpdb->insert_id) {
            ptp_log('[PTP Escrow v228] Payout record created: #' . $wpdb->insert_id . ' for $' . number_format($escrow->trainer_amount, 2));
        } else {
            ptp_log('[PTP Escrow v228] WARNING: Failed to create payout record for escrow #' . $escrow_id . ': ' . $wpdb->last_error);
        }
        
        // Log event
        self::log_event($escrow_id, 'funds_released', 'Transferred $' . number_format($escrow->trainer_amount, 2) . ' to trainer');
        
        // Notify trainer
        self::notify_trainer_payment($escrow_id);
        
        return array(
            'status' => 'released',
            'transfer_id' => $transfer['id'],
            'amount' => $escrow->trainer_amount,
        );
    }
    
    /**
     * Refund payment to parent
     */
    public static function refund($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, t.stripe_account_id 
             FROM {$wpdb->prefix}ptp_escrow e
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
             WHERE e.id = %d",
            $escrow_id
        ));
        
        if (!$escrow) {
            return new WP_Error('not_found', 'Escrow record not found');
        }
        
        if ($escrow->status === self::STATUS_REFUNDED) {
            return new WP_Error('already_refunded', 'Already refunded');
        }
        
        if ($escrow->status === self::STATUS_RELEASED) {
            return new WP_Error('already_released', 'Cannot refund - funds already released to trainer');
        }
        
        // Process Stripe refund if we have a payment intent
        if (!empty($escrow->payment_intent_id) && class_exists('PTP_Stripe')) {
            $refund = PTP_Stripe::create_refund($escrow->payment_intent_id, round($escrow->total_amount * 100));
            
            if (is_wp_error($refund)) {
                self::log_event($escrow_id, 'refund_failed', 'Stripe refund failed: ' . $refund->get_error_message());
                // Continue anyway to mark as refunded for manual processing
            } else {
                self::log_event($escrow_id, 'refund_processed', 'Stripe refund processed: ' . ($refund['id'] ?? 'success'));
            }
        }
        
        // Update escrow status
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => self::STATUS_REFUNDED,
                'refunded_at' => current_time('mysql'),
            ),
            array('id' => $escrow_id)
        );
        
        // Update booking
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'escrow_status' => self::STATUS_REFUNDED,
                'status' => 'refunded',
            ),
            array('id' => $escrow->booking_id)
        );
        
        // Log event
        self::log_event($escrow_id, 'refunded', 'Refunded $' . number_format($escrow->total_amount, 2) . ' to parent');
        
        // Notify parent
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_refund_notification')) {
            PTP_Email::send_refund_notification($escrow->parent_id, $escrow->total_amount, $escrow->booking_id);
        }
        
        return array(
            'status' => 'refunded',
            'amount' => $escrow->total_amount,
        );
    }
    
    /**
     * Process automatic releases for confirmed sessions past the waiting period
     */
    public static function process_auto_releases() {
        global $wpdb;
        
        // Get escrow records eligible for auto-release
        $eligible = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_escrow
            WHERE status = %s
            AND release_eligible_at IS NOT NULL
            AND release_eligible_at <= NOW()
        ", self::STATUS_SESSION_COMPLETE));
        
        foreach ($eligible as $escrow) {
            // Auto-confirm and release
            $wpdb->update(
                $wpdb->prefix . 'ptp_escrow',
                array(
                    'status' => self::STATUS_CONFIRMED,
                    'parent_confirmed_at' => current_time('mysql'),
                    'auto_confirmed' => 1,
                ),
                array('id' => $escrow->id)
            );
            
            self::log_event($escrow->id, 'auto_confirmed', 'Auto-confirmed after ' . self::AUTO_RELEASE_HOURS . ' hours with no dispute');
            
            // Release funds
            self::release_funds($escrow->id);
        }
        
        return count($eligible);
    }
    
    /**
     * Admin resolves dispute
     */
    public static function admin_resolve_dispute() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $escrow_id = isset($_POST['escrow_id']) ? intval($_POST['escrow_id']) : 0;
        $resolution = isset($_POST['resolution']) ? sanitize_text_field($_POST['resolution']) : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        $refund_percent = isset($_POST['refund_percent']) ? intval($_POST['refund_percent']) : 0;
        
        if (!$escrow_id || !in_array($resolution, array('release', 'refund', 'partial'))) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }
        
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_escrow WHERE id = %d",
            $escrow_id
        ));
        
        if (!$escrow) {
            wp_send_json_error(array('message' => 'Escrow not found'));
        }
        
        switch ($resolution) {
            case 'release':
                // Release full amount to trainer
                $result = self::release_funds($escrow_id);
                $wpdb->update(
                    $wpdb->prefix . 'ptp_escrow',
                    array(
                        'dispute_resolution' => 'released_to_trainer',
                        'dispute_resolved_at' => current_time('mysql'),
                        'dispute_resolved_by' => get_current_user_id(),
                        'resolution_notes' => $notes,
                    ),
                    array('id' => $escrow_id)
                );
                self::log_event($escrow_id, 'dispute_resolved', 'Admin released funds to trainer: ' . $notes);
                break;
                
            case 'refund':
                // Full refund to parent
                $refund = PTP_Stripe::create_refund($escrow->payment_intent_id);
                if (!is_wp_error($refund)) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_escrow',
                        array(
                            'status' => self::STATUS_REFUNDED,
                            'dispute_resolution' => 'refunded_to_parent',
                            'dispute_resolved_at' => current_time('mysql'),
                            'dispute_resolved_by' => get_current_user_id(),
                            'resolution_notes' => $notes,
                            'refund_amount' => $escrow->total_amount,
                        ),
                        array('id' => $escrow_id)
                    );
                    self::log_event($escrow_id, 'dispute_resolved', 'Admin issued full refund: ' . $notes);
                }
                break;
                
            case 'partial':
                // Partial refund and partial release
                $refund_amount = round($escrow->total_amount * ($refund_percent / 100), 2);
                $trainer_gets = $escrow->trainer_amount - round($escrow->trainer_amount * ($refund_percent / 100), 2);
                
                // Process partial refund
                $refund = PTP_Stripe::create_refund($escrow->payment_intent_id, $refund_amount);
                
                // Release remaining to trainer
                if (!is_wp_error($refund) && $trainer_gets > 0) {
                    $trainer_stripe_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                        $escrow->trainer_id
                    ));
                    
                    if ($trainer_stripe_id) {
                        // Transfer reduced amount
                        $transfer = PTP_Stripe::create_transfer(
                            round($trainer_gets * 100),
                            $trainer_stripe_id,
                            'Partial dispute resolution - Booking #' . $escrow->booking_id
                        );
                    }
                }
                
                $wpdb->update(
                    $wpdb->prefix . 'ptp_escrow',
                    array(
                        'status' => self::STATUS_RELEASED,
                        'dispute_resolution' => 'partial_refund',
                        'dispute_resolved_at' => current_time('mysql'),
                        'dispute_resolved_by' => get_current_user_id(),
                        'resolution_notes' => $notes,
                        'refund_amount' => $refund_amount,
                        'released_at' => current_time('mysql'),
                    ),
                    array('id' => $escrow_id)
                );
                self::log_event($escrow_id, 'dispute_resolved', "Partial resolution: {$refund_percent}% refunded, rest to trainer. {$notes}");
                break;
        }
        
        // Notify both parties
        self::notify_dispute_resolved($escrow_id, $resolution);
        
        PTP_Monitor::info('escrow', "Dispute resolved: escrow={$escrow_id} resolution={$resolution}", array('escrow_id' => $escrow_id, 'resolution' => $resolution, 'admin' => get_current_user_id()));
        wp_send_json_success(array('message' => 'Dispute resolved successfully'));
    }
    
    /**
     * Admin manually releases funds
     */
    public static function admin_release_funds() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $escrow_id = isset($_POST['escrow_id']) ? intval($_POST['escrow_id']) : 0;
        
        if (!$escrow_id) {
            wp_send_json_error(array('message' => 'Invalid escrow ID'));
        }
        
        $result = self::release_funds($escrow_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => 'Funds released successfully'));
    }
    
    /**
     * Get escrow status for booking
     */
    public static function get_status($booking_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare("
            SELECT e.*, 
                   t.display_name as trainer_name,
                   p.name as player_name,
                   pa.display_name as parent_name
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_bookings b ON e.booking_id = b.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON e.parent_id = pa.id
            WHERE e.booking_id = %d
        ", $booking_id));
    }
    
    /**
     * Log escrow event
     */
    private static function log_event($escrow_id, $event_type, $message) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_escrow_log',
            array(
                'escrow_id' => $escrow_id,
                'event_type' => $event_type,
                'message' => $message,
                'user_id' => get_current_user_id() ?: 0,
                'created_at' => current_time('mysql'),
            )
        );
    }
    
    // ═══════════════════════════════════════════════════════
    // ONE-CLICK EMAIL CONFIRMATION (no login required)
    // ═══════════════════════════════════════════════════════
    
    /**
     * Generate a secure HMAC token for one-click email confirmation.
     */
    public static function generate_confirm_token($booking_id) {
        return hash_hmac('sha256', 'ptp_confirm|' . intval($booking_id), wp_salt('auth'));
    }
    
    /**
     * Build the one-click confirmation URL for email buttons
     */
    public static function get_email_confirm_url($booking_id) {
        $token = self::generate_confirm_token($booking_id);
        return home_url('/?ptp_email_confirm=' . intval($booking_id) . '&token=' . $token);
    }
    
    /**
     * Handle GET request for one-click email confirmation
     */
    public static function handle_email_confirm() {
        if (!isset($_GET['ptp_email_confirm']) || !isset($_GET['token'])) {
            return;
        }
        
        $booking_id = intval($_GET['ptp_email_confirm']);
        $token = sanitize_text_field($_GET['token']);
        
        if (!$booking_id || !$token) {
            self::render_confirm_page('error', 'Invalid confirmation link.');
            return;
        }
        
        // Verify HMAC token
        $expected = self::generate_confirm_token($booking_id);
        if (!hash_equals($expected, $token)) {
            self::render_confirm_page('error', 'This confirmation link is invalid or has expired.');
            return;
        }
        
        global $wpdb;
        
        // Get the booking with trainer info
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, t.display_name as trainer_name, 
                   COALESCE(pl.name, 'Your Player') as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) {
            self::render_confirm_page('error', 'Session not found.');
            return;
        }
        
        // Check if already completed/confirmed
        if ($booking->status === 'completed' && !empty($booking->completed_at)) {
            self::render_confirm_page('already', 'This session has already been confirmed. Thank you!', $booking);
            return;
        }
        
        // Try escrow confirmation first
        $escrow_table = $wpdb->prefix . 'ptp_escrow';
        $escrow = null;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$escrow_table}'") === $escrow_table) {
            $escrow = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$escrow_table} WHERE booking_id = %d", $booking_id
            ));
        }
        
        if ($escrow && in_array($escrow->status, array(self::STATUS_SESSION_COMPLETE, self::STATUS_HOLDING))) {
            $wpdb->update(
                $escrow_table,
                array(
                    'status' => self::STATUS_CONFIRMED,
                    'parent_confirmed_at' => current_time('mysql'),
                ),
                array('id' => $escrow->id)
            );
            if (method_exists(__CLASS__, 'log_event')) {
                self::log_event($escrow->id, 'parent_confirmed_email', 'Parent confirmed via one-click email link');
            }
            try {
                self::release_funds($escrow->id);
            } catch (\Throwable $e) {
                ptp_log('PTP Email Confirm: Fund release error for escrow ' . $escrow->id . ': ' . $e->getMessage());
            }
        }
        
        // Update booking directly (works for both escrow and non-escrow)
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'completed',
                'parent_confirmed' => 1,
                'parent_confirmed_at' => current_time('mysql'),
                'completed_at' => current_time('mysql'),
            ),
            array('id' => $booking_id)
        );
        
        // Fire hooks safely
        try {
            do_action('ptp_session_completed', $booking_id, $booking);
            do_action('ptp_booking_completed', $booking_id, $booking);
        } catch (\Throwable $e) {
            ptp_log('PTP Email Confirm: Hook error for booking ' . $booking_id . ': ' . $e->getMessage());
        }
        
        self::render_confirm_page('success', 'Session confirmed! Payment has been released to ' . $booking->trainer_name . '.', $booking);
    }
    
    /**
     * Render a simple branded confirmation result page and exit
     */
    private static function render_confirm_page($status, $message, $booking = null) {
        $icon_success = '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="9 12 12 15 16 10"/></svg>';
        $icon_error = '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
        $icon_already = '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="9 12 12 15 16 10"/></svg>';
        
        $icon = $status === 'success' ? $icon_success : ($status === 'already' ? $icon_already : $icon_error);
        $bg = $status === 'success' ? '#F0FDF4' : ($status === 'already' ? '#EFF6FF' : '#FEF2F2');
        $heading = $status === 'success' ? 'Session Confirmed!' : ($status === 'already' ? 'Already Confirmed' : 'Confirmation Error');
        
        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($heading); ?> - PTP</title>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',-apple-system,sans-serif;background:#0A0A0A;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:16px;padding:48px 32px;max-width:440px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.3)}
.icon{margin-bottom:20px}
h1{font-family:'Oswald',sans-serif;font-size:24px;text-transform:uppercase;letter-spacing:0.02em;margin-bottom:12px;color:#0A0A0A}
.msg{font-size:15px;color:#6B7280;line-height:1.6;margin-bottom:24px}
.detail{background:<?php echo $bg; ?>;border-radius:10px;padding:16px 20px;margin-bottom:24px;text-align:left}
.detail-row{display:flex;justify-content:space-between;padding:6px 0;font-size:14px;color:#374151}
.detail-label{font-weight:600;color:#0A0A0A}
.btn{display:inline-block;background:#FCB900;color:#0A0A0A;font-family:'Oswald',sans-serif;font-weight:700;font-size:14px;text-transform:uppercase;letter-spacing:0.05em;padding:14px 32px;border-radius:0;text-decoration:none;border:2px solid #0A0A0A}
.btn:hover{background:#E5A800}
.logo{font-family:'Oswald',sans-serif;font-weight:700;font-size:14px;color:#FCB900;text-transform:uppercase;letter-spacing:0.1em;margin-top:24px}
</style>
</head><body>
<div class="card">
    <div class="icon"><?php echo $icon; ?></div>
    <h1><?php echo esc_html($heading); ?></h1>
    <p class="msg"><?php echo esc_html($message); ?></p>
    <?php if ($booking && $status !== 'error'): ?>
    <div class="detail">
        <div class="detail-row"><span class="detail-label">Player</span><span><?php echo esc_html($booking->player_name ?? 'Player'); ?></span></div>
        <div class="detail-row"><span class="detail-label">Trainer</span><span><?php echo esc_html($booking->trainer_name); ?></span></div>
        <div class="detail-row"><span class="detail-label">Date</span><span><?php echo esc_html(date('M j, Y', strtotime($booking->session_date))); ?></span></div>
    </div>
    <?php endif; ?>
    <a href="<?php echo esc_url(home_url('/parent-dashboard/')); ?>" class="btn">Go to Dashboard</a>
    <div class="logo">Players Teaching Players</div>
</div>
</body></html><?php
        exit;
    }
    
    /**
     * Notify parent to confirm session
     */
    private static function notify_parent_to_confirm($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, t.display_name as trainer_name, pa.user_id as parent_user_id
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_parents pa ON e.parent_id = pa.id
            WHERE e.id = %d
        ", $escrow_id));
        
        if (!$escrow) return;
        
        // Send email using the proper template
        if (class_exists('PTP_Email')) {
            PTP_Email::send_session_completion_request($escrow->booking_id);
        }
        
        // Send SMS if enabled
        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            PTP_SMS::send_completion_request($escrow->booking_id);
        }
        
        // Send push notification
        if (class_exists('PTP_Push_Notifications')) {
            PTP_Push_Notifications::send(
                $escrow->parent_user_id,
                'Please Confirm Your Session',
                "Your session with {$escrow->trainer_name} is marked complete. Tap to confirm.",
                array('type' => 'session_confirm', 'booking_id' => $escrow->booking_id)
            );
        }
    }
    
    /**
     * Notify admin of dispute
     */
    private static function notify_admin_dispute($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, t.display_name as trainer_name, pa.display_name as parent_name
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_parents pa ON e.parent_id = pa.id
            WHERE e.id = %d
        ", $escrow_id));
        
        $admin_email = get_option('admin_email');
        $subject = "[PTP] Payment Dispute - Booking #{$escrow->booking_id}";
        $message = "A payment dispute has been filed.\n\n";
        $message .= "Booking: #{$escrow->booking_id}\n";
        $message .= "Trainer: {$escrow->trainer_name}\n";
        $message .= "Parent: {$escrow->parent_name}\n";
        $message .= "Amount: $" . number_format($escrow->total_amount, 2) . "\n";
        $message .= "Reason: {$escrow->dispute_reason}\n\n";
        $message .= "Review at: " . admin_url('admin.php?page=ptp-disputes&escrow=' . $escrow_id);
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Notify trainer of dispute
     */
    private static function notify_trainer_dispute($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, t.user_id as trainer_user_id
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            WHERE e.id = %d
        ", $escrow_id));
        
        $trainer_user = get_user_by('ID', $escrow->trainer_user_id);
        if (!$trainer_user) return;
        
        $subject = "Session Payment Under Review";
        $message = "A parent has raised a concern about a recent session. Your payment of $" . number_format($escrow->trainer_amount, 2) . " is on hold while our team reviews.\n\n";
        $message .= "We'll contact you within 24-48 hours if we need any information.\n\n";
        $message .= "View details: " . home_url('/trainer-dashboard/');
        
        wp_mail($trainer_user->user_email, $subject, $message);
    }
    
    /**
     * Notify trainer of payment release
     */
    private static function notify_trainer_payment($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, t.user_id as trainer_user_id, t.display_name as trainer_name, t.phone as trainer_phone
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            WHERE e.id = %d
        ", $escrow_id));
        
        if (!$escrow) return;
        
        // Send email
        if (class_exists('PTP_Email')) {
            PTP_Email::send_payout_processed($escrow->trainer_id, $escrow->trainer_amount, 'Stripe Connect');
        }
        
        // Send SMS
        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled() && !empty($escrow->trainer_phone)) {
            PTP_SMS::send_payout_notification($escrow->trainer_phone, $escrow->trainer_amount);
        }
        
        // Send push notification
        if (class_exists('PTP_Push_Notifications')) {
            PTP_Push_Notifications::send(
                $escrow->trainer_user_id,
                '💰 Payout Sent!',
                '$' . number_format($escrow->trainer_amount, 2) . ' is on its way to your bank',
                array('type' => 'payout', 'amount' => $escrow->trainer_amount)
            );
        }
    }
    
    /**
     * Notify both parties of dispute resolution
     */
    private static function notify_dispute_resolved($escrow_id, $resolution) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, 
                   t.user_id as trainer_user_id,
                   pa.user_id as parent_user_id
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_parents pa ON e.parent_id = pa.id
            WHERE e.id = %d
        ", $escrow_id));
        
        // Email both parties about resolution
        $resolution_text = array(
            'release' => 'Funds have been released to the trainer.',
            'refund' => 'A full refund has been issued.',
            'partial' => 'A partial resolution has been applied.',
        );
        
        $trainer_user = get_user_by('ID', $escrow->trainer_user_id);
        $parent_user = get_user_by('ID', $escrow->parent_user_id);
        
        $subject = "Dispute Resolved - Booking #{$escrow->booking_id}";
        $message = "Your dispute has been reviewed and resolved.\n\n";
        $message .= "Resolution: " . ($resolution_text[$resolution] ?? $resolution) . "\n\n";
        $message .= "If you have questions, please contact support.";
        
        if ($trainer_user) {
            wp_mail($trainer_user->user_email, $subject, $message);
        }
        if ($parent_user) {
            wp_mail($parent_user->user_email, $subject, $message);
        }
    }
}

// Initialize
PTP_Escrow::init();
