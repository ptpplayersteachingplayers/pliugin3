<?php
/**
 * PTP Session Operations v200
 * 
 * Unified cancellation, no-show, refund management with AJAX endpoints
 * for both parent and trainer dashboards.
 * 
 * Cancellation Policy:
 * - 24+ hours before: Full refund
 * - 2-24 hours before: 50% refund (parent cancel only)
 * - Under 2 hours: No refund (parent cancel only)
 * - Trainer cancels: Always full refund
 * - No-show (marked by trainer): No refund, session counted
 * 
 * Features:
 * - Parent cancel from dashboard
 * - Trainer cancel from dashboard
 * - Trainer mark no-show
 * - Automatic refund calculation based on timing
 * - Cancellation notifications to both parties
 * - Recurring booking setup from trainer profile
 * - Cancellation policy display helper
 */

defined('ABSPATH') || exit;

class PTP_Session_Ops {

    public static function init() {
        // Parent actions
        add_action('wp_ajax_ptp_cancel_booking', array(__CLASS__, 'ajax_cancel_booking'));
        add_action('wp_ajax_ptp_request_refund', array(__CLASS__, 'ajax_request_refund'));
        add_action('wp_ajax_ptp_get_cancellation_info', array(__CLASS__, 'ajax_get_cancellation_info'));

        // Trainer actions
        add_action('wp_ajax_ptp_trainer_cancel_session', array(__CLASS__, 'ajax_trainer_cancel'));
        add_action('wp_ajax_ptp_mark_no_show', array(__CLASS__, 'ajax_mark_no_show'));

        // Recurring
        add_action('wp_ajax_ptp_setup_recurring', array(__CLASS__, 'ajax_setup_recurring'));
        add_action('wp_ajax_ptp_cancel_recurring', array(__CLASS__, 'ajax_cancel_recurring'));
        add_action('wp_ajax_ptp_get_recurring', array(__CLASS__, 'ajax_get_recurring'));

        // v135: Reschedule
        add_action('wp_ajax_ptp_get_reschedule_dates', array(__CLASS__, 'ajax_get_reschedule_dates'));
        add_action('wp_ajax_ptp_get_reschedule_slots', array(__CLASS__, 'ajax_get_reschedule_slots'));
        add_action('wp_ajax_ptp_reschedule_booking', array(__CLASS__, 'ajax_reschedule_booking'));

        // Profile view tracking
        add_action('wp_ajax_ptp_track_profile_view', array(__CLASS__, 'ajax_track_view'));
        add_action('wp_ajax_nopriv_ptp_track_profile_view', array(__CLASS__, 'ajax_track_view'));
    }

    /**
     * Get cancellation policy info for a booking
     */
    public static function get_cancellation_info($booking_id) {
        $booking = PTP_Booking::get_full($booking_id);
        if (!$booking) return null;

        $session_start = strtotime($booking->session_date . ' ' . $booking->start_time);
        $now = current_time('timestamp');
        $hours_until = max(0, ($session_start - $now) / 3600);

        $can_cancel = in_array($booking->status, array('pending', 'confirmed'));
        $is_past = $session_start < $now;

        // Determine refund percentage
        if ($is_past) {
            $refund_percent = 0;
            $policy_text = 'This session has already occurred. No refund available.';
        } elseif ($hours_until >= 24) {
            $refund_percent = 100;
            $policy_text = 'Full refund — more than 24 hours before session.';
        } elseif ($hours_until >= 2) {
            $refund_percent = 50;
            $policy_text = '50% refund — less than 24 hours before session.';
        } else {
            $refund_percent = 0;
            $policy_text = 'No refund — less than 2 hours before session.';
        }

        $refund_amount = ($booking->total_amount ?: $booking->hourly_rate) * ($refund_percent / 100);

        return array(
            'booking_id' => $booking_id,
            'can_cancel' => $can_cancel,
            'is_past' => $is_past,
            'hours_until' => round($hours_until, 1),
            'refund_percent' => $refund_percent,
            'refund_amount' => round($refund_amount, 2),
            'total_paid' => floatval($booking->total_amount ?: $booking->hourly_rate),
            'policy_text' => $policy_text,
            'session_date' => $booking->session_date,
            'start_time' => $booking->start_time,
            'trainer_name' => $booking->trainer_name ?? '',
            'player_name' => $booking->player_name ?? '',
        );
    }

    /**
     * AJAX: Get cancellation info (parent)
     */
    public static function ajax_get_cancellation_info() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) wp_send_json_error(array('message' => 'Invalid booking'));

        // Verify parent owns this booking
        $booking = PTP_Booking::get($booking_id);
        if (!$booking) wp_send_json_error(array('message' => 'Booking not found'));

        global $wpdb;
        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            get_current_user_id()
        ));

        if ($booking->parent_id != $parent_id) {
            wp_send_json_error(array('message' => 'Access denied'));
        }

        $info = self::get_cancellation_info($booking_id);
        wp_send_json_success($info);
    }

    /**
     * AJAX: Cancel booking (parent-initiated)
     */
    public static function ajax_cancel_booking() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (!$booking_id) wp_send_json_error(array('message' => 'Invalid booking'));

        global $wpdb;

        // Verify parent owns this booking
        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            get_current_user_id()
        ));

        $booking = PTP_Booking::get_full($booking_id);
        if (!$booking || $booking->parent_id != $parent_id) {
            wp_send_json_error(array('message' => 'Booking not found'));
        }

        if (!in_array($booking->status, array('pending', 'confirmed'))) {
            wp_send_json_error(array('message' => 'This booking cannot be cancelled'));
        }

        $info = self::get_cancellation_info($booking_id);

        // Update booking
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'cancelled',
                'cancelled_by' => 'parent',
                'cancellation_reason' => $reason,
                'cancelled_at' => current_time('mysql'),
                'refund_status' => $info['refund_percent'] > 0 ? 'pending' : 'none',
            ),
            array('id' => $booking_id)
        );

        // Send notifications
        self::notify_cancellation($booking, 'parent', $reason, $info);

        // If there's a payment and refund is due, process it
        if ($info['refund_percent'] > 0 && !empty($booking->stripe_payment_id)) {
            self::process_refund($booking_id, $info['refund_percent']);
        }

        wp_send_json_success(array(
            'message' => 'Booking cancelled successfully.',
            'refund_percent' => $info['refund_percent'],
            'refund_amount' => $info['refund_amount'],
        ));
    }

    /**
     * AJAX: Trainer cancel session
     */
    public static function ajax_trainer_cancel() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (!$booking_id) wp_send_json_error(array('message' => 'Invalid booking'));

        try {

        global $wpdb;

        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));

        // Try get_full, fall back to basic get
        $booking = null;
        if (class_exists('PTP_Booking') && method_exists('PTP_Booking', 'get_full')) {
            $booking = PTP_Booking::get_full($booking_id);
        }
        if (!$booking) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                $booking_id
            ));
        }
        if (!$booking || $booking->trainer_id != $trainer_id) {
            wp_send_json_error(array('message' => 'Booking not found'));
        }

        if (!in_array($booking->status, array('pending', 'confirmed'))) {
            wp_send_json_error(array('message' => 'This session cannot be cancelled'));
        }

        // Trainer cancels always get full refund
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'cancelled',
                'cancelled_by' => 'trainer',
                'cancellation_reason' => $reason,
                'cancelled_at' => current_time('mysql'),
                'refund_status' => (!empty($booking->stripe_payment_id) && ($booking->payment_status ?? '') === 'paid') ? 'pending' : 'none',
            ),
            array('id' => $booking_id)
        );

        // Full refund for trainer cancellations
        if (!empty($booking->stripe_payment_id) && ($booking->payment_status ?? '') === 'paid') {
            if (method_exists(__CLASS__, 'process_refund')) {
                self::process_refund($booking_id, 100);
            }
        }

        $info = array(
            'refund_percent' => 100,
            'refund_amount' => floatval($booking->total_amount ?: ($booking->hourly_rate ?? 0)),
        );

        if (method_exists(__CLASS__, 'notify_cancellation')) {
            try {
                self::notify_cancellation($booking, 'trainer', $reason, $info);
            } catch (\Throwable $ne) {
                ptp_log('PTP: Cancel notification error: ' . $ne->getMessage());
            }
        }

        wp_send_json_success(array('message' => 'Session cancelled. Parent will receive a full refund.'));

        } catch (\Throwable $e) {
            ptp_log('PTP ajax_trainer_cancel fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(array('message' => 'Server error: ' . $e->getMessage()));
        }
    }

    /**
     * AJAX: Trainer marks no-show
     */
    public static function ajax_mark_no_show() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) wp_send_json_error(array('message' => 'Invalid booking'));

        try {

        global $wpdb;

        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));

        // Try get_full, fall back to basic get
        $booking = null;
        if (class_exists('PTP_Booking') && method_exists('PTP_Booking', 'get_full')) {
            $booking = PTP_Booking::get_full($booking_id);
        }
        if (!$booking) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, t.display_name as trainer_name, t.user_id as trainer_user_id
                 FROM {$wpdb->prefix}ptp_bookings b
                 LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 WHERE b.id = %d",
                $booking_id
            ));
        }
        if (!$booking || $booking->trainer_id != $trainer_id) {
            wp_send_json_error(array('message' => 'Booking not found'));
        }

        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'no_show',
                'notes' => trim(($booking->notes ?? '') . "\nMarked as no-show by trainer on " . current_time('mysql')),
            ),
            array('id' => $booking_id)
        );

        // No refund for no-shows — trainer still gets compensated
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'payout_status' => 'pending',
                'refund_status' => 'none',
            ),
            array('id' => $booking_id)
        );

        // Notify parent safely
        if (!empty($booking->parent_user_id) && class_exists('PTP_Notifications') && method_exists('PTP_Notifications', 'create')) {
            try {
                PTP_Notifications::create(
                    $booking->parent_user_id,
                    'no_show',
                    'Session Marked as No-Show',
                    'Your session on ' . date('M j', strtotime($booking->session_date)) . ' was marked as a no-show. No refund will be issued per our cancellation policy.',
                    array('booking_id' => $booking_id)
                );
            } catch (\Throwable $ne) {
                ptp_log('PTP: No-show notification error: ' . $ne->getMessage());
            }
        }

        // Send email to parent safely
        $parent_email = $booking->parent_email ?? ($booking->guest_email ?? '');
        if (!empty($parent_email) && class_exists('PTP_Email') && method_exists('PTP_Email', 'send')) {
            try {
                PTP_Email::send(
                    $parent_email,
                    'Session No-Show — ' . date('M j', strtotime($booking->session_date)),
                    method_exists(__CLASS__, 'no_show_email_body') ? self::no_show_email_body($booking) : 'Your session was marked as a no-show.'
                );
            } catch (\Throwable $ee) {
                ptp_log('PTP: No-show email error: ' . $ee->getMessage());
            }
        }

        wp_send_json_success(array('message' => 'Session marked as no-show'));

        } catch (\Throwable $e) {
            ptp_log('PTP ajax_mark_no_show fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(array('message' => 'Server error: ' . $e->getMessage()));
        }
    }

    /**
     * Process a refund via Stripe
     */
    private static function process_refund($booking_id, $refund_percent) {
        $booking = PTP_Booking::get($booking_id);
        if (!$booking || empty($booking->stripe_payment_id)) return false;

        $refund_amount_cents = intval(($booking->total_amount ?: $booking->hourly_rate) * ($refund_percent / 100) * 100);

        if ($refund_amount_cents <= 0) return false;

        global $wpdb;

        if (class_exists('PTP_Stripe') && method_exists('PTP_Stripe', 'create_refund')) {
            $result = PTP_Stripe::create_refund($booking->stripe_payment_id, $refund_amount_cents);

            if (!is_wp_error($result)) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_bookings',
                    array(
                        'refund_status' => 'completed',
                        'refund_amount' => $refund_amount_cents / 100,
                        'stripe_refund_id' => $result['id'] ?? '',
                    ),
                    array('id' => $booking_id)
                );
                return true;
            } else {
                // Log failure, keep pending for cron retry
                ptp_log('PTP Refund failed for booking #' . $booking_id . ': ' . $result->get_error_message());
            }
        }

        return false;
    }

    /**
     * Notify both parties of cancellation
     */
    private static function notify_cancellation($booking, $cancelled_by, $reason, $info) {
        // In-app notification to the other party
        if ($cancelled_by === 'parent' && !empty($booking->trainer_user_id) && class_exists('PTP_Notifications')) {
            PTP_Notifications::create(
                $booking->trainer_user_id,
                'cancellation',
                'Session Cancelled',
                ($booking->player_name ?? 'A player') . '\'s session on ' . date('M j', strtotime($booking->session_date)) . ' has been cancelled.' . (!empty($reason) ? ' Reason: ' . $reason : ''),
                array('booking_id' => $booking->id)
            );
        }

        if ($cancelled_by === 'trainer' && !empty($booking->parent_user_id) && class_exists('PTP_Notifications')) {
            PTP_Notifications::create(
                $booking->parent_user_id,
                'cancellation',
                'Session Cancelled by Trainer',
                $booking->trainer_name . ' cancelled your session on ' . date('M j', strtotime($booking->session_date)) . '. A full refund of $' . number_format($info['refund_amount'], 2) . ' will be processed.' . (!empty($reason) ? ' Reason: ' . $reason : ''),
                array('booking_id' => $booking->id)
            );
        }

        // Email notifications
        if (class_exists('PTP_Email')) {
            if ($cancelled_by === 'parent' && !empty($booking->trainer_email)) {
                PTP_Email::send(
                    $booking->trainer_email,
                    'Session Cancelled — ' . date('M j', strtotime($booking->session_date)),
                    self::cancellation_email_body($booking, 'trainer', $reason, $info)
                );
            }
            if (!empty($booking->parent_email)) {
                PTP_Email::send(
                    $booking->parent_email,
                    'Session Cancelled — ' . date('M j', strtotime($booking->session_date)),
                    self::cancellation_email_body($booking, 'parent', $reason, $info)
                );
            }
        }

        // SMS
        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            if ($cancelled_by === 'parent' && !empty($booking->trainer_phone)) {
                PTP_SMS::send($booking->trainer_phone, 
                    "PTP: Session with " . ($booking->player_name ?? 'player') . " on " . date('M j g:iA', strtotime($booking->session_date . ' ' . $booking->start_time)) . " has been cancelled."
                );
            }
        }
    }

    /**
     * Email body for cancellation
     */
    private static function cancellation_email_body($booking, $recipient, $reason, $info) {
        $date = date('l, F j, Y', strtotime($booking->session_date));
        $time = date('g:i A', strtotime($booking->start_time));

        $body = "<h2>Session Cancelled</h2>";
        $body .= "<p>The following session has been cancelled:</p>";
        $body .= "<table style='width:100%;border-collapse:collapse;margin:16px 0'>";
        $body .= "<tr><td style='padding:8px;border-bottom:1px solid #eee;font-weight:600'>Date</td><td style='padding:8px;border-bottom:1px solid #eee'>{$date}</td></tr>";
        $body .= "<tr><td style='padding:8px;border-bottom:1px solid #eee;font-weight:600'>Time</td><td style='padding:8px;border-bottom:1px solid #eee'>{$time}</td></tr>";

        if ($recipient === 'trainer') {
            $body .= "<tr><td style='padding:8px;border-bottom:1px solid #eee;font-weight:600'>Player</td><td style='padding:8px;border-bottom:1px solid #eee'>" . esc_html($booking->player_name ?? 'Player') . "</td></tr>";
        } else {
            $body .= "<tr><td style='padding:8px;border-bottom:1px solid #eee;font-weight:600'>Trainer</td><td style='padding:8px;border-bottom:1px solid #eee'>" . esc_html($booking->trainer_name ?? 'Trainer') . "</td></tr>";
        }

        $body .= "</table>";

        if (!empty($reason)) {
            $body .= "<p><strong>Reason:</strong> " . esc_html($reason) . "</p>";
        }

        if ($recipient === 'parent' && $info['refund_percent'] > 0) {
            $body .= "<p style='padding:12px;background:#E8F5E9;border-radius:8px;color:#2E7D32'>A refund of <strong>$" . number_format($info['refund_amount'], 2) . "</strong> (" . $info['refund_percent'] . "%) will be processed to your payment method within 5-10 business days.</p>";
        } elseif ($recipient === 'parent' && $info['refund_percent'] === 0) {
            $body .= "<p style='padding:12px;background:#FFF3E0;border-radius:8px;color:#E65100'>Per our cancellation policy, no refund is available for cancellations made less than 2 hours before the session.</p>";
        }

        return $body;
    }

    /**
     * Email body for no-show
     */
    private static function no_show_email_body($booking) {
        $date = date('l, F j, Y', strtotime($booking->session_date));
        $time = date('g:i A', strtotime($booking->start_time));

        $body = "<h2>Session No-Show</h2>";
        $body .= "<p>Your trainer <strong>" . esc_html($booking->trainer_name) . "</strong> has reported that no one arrived for the scheduled session:</p>";
        $body .= "<p><strong>Date:</strong> {$date}<br><strong>Time:</strong> {$time}</p>";
        $body .= "<p style='padding:12px;background:#FFF3E0;border-radius:8px;color:#E65100'>Per our no-show policy, no refund will be issued. The trainer is compensated for their reserved time.</p>";
        $body .= "<p>If you believe this was marked in error, please contact us or your trainer directly.</p>";

        return $body;
    }

    /**
     * AJAX: Setup recurring booking
     */
    public static function ajax_setup_recurring() {
        check_ajax_referer('ptp_nonce', 'nonce');

        global $wpdb;

        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $player_id = intval($_POST['player_id'] ?? 0);
        $day_of_week = intval($_POST['day_of_week'] ?? -1);
        $start_time = sanitize_text_field($_POST['start_time'] ?? '');
        $location = sanitize_text_field($_POST['location'] ?? '');
        $total_sessions = intval($_POST['total_sessions'] ?? 4);

        if (!$trainer_id || !$player_id || $day_of_week < 0 || empty($start_time)) {
            wp_send_json_error(array('message' => 'Missing required fields'));
        }

        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            get_current_user_id()
        ));

        if (!$parent_id) wp_send_json_error(array('message' => 'Parent account not found'));

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));

        if (!$trainer) wp_send_json_error(array('message' => 'Trainer not found'));

        // Calculate next session date
        $day_names = array(0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday');
        $next_date = date('Y-m-d', strtotime('next ' . $day_names[$day_of_week]));

        // Check if recurring table exists
        $table = $wpdb->prefix . 'ptp_recurring';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;

        if (!$table_exists && class_exists('PTP_Recurring')) {
            PTP_Recurring::create_tables();
        }

        $wpdb->insert($wpdb->prefix . 'ptp_recurring', array(
            'parent_id' => $parent_id,
            'trainer_id' => $trainer_id,
            'player_id' => $player_id,
            'day_of_week' => $day_of_week,
            'start_time' => $start_time,
            'location' => $location,
            'total_sessions' => $total_sessions,
            'sessions_remaining' => $total_sessions,
            'sessions_completed' => 0,
            'hourly_rate' => floatval($trainer->hourly_rate ?: 60),
            'status' => 'active',
            'start_date' => $next_date,
            'next_session_date' => $next_date,
            'payment_type' => 'per_session',
            'created_at' => current_time('mysql'),
        ));

        wp_send_json_success(array(
            'message' => 'Recurring sessions set up! Your next session is ' . date('l, M j', strtotime($next_date)) . '.',
            'recurring_id' => $wpdb->insert_id,
            'next_date' => $next_date,
        ));
    }

    /**
     * AJAX: Get recurring bookings for current user
     */
    public static function ajax_get_recurring() {
        check_ajax_referer('ptp_nonce', 'nonce');

        global $wpdb;

        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            get_current_user_id()
        ));

        $table = $wpdb->prefix . 'ptp_recurring';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            wp_send_json_success(array('recurring' => array()));
            return;
        }

        $recurring = $wpdb->get_results($wpdb->prepare("
            SELECT r.*, t.display_name as trainer_name, t.photo_url as trainer_photo, 
                   pl.name as player_name
            FROM {$table} r
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON r.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON r.player_id = pl.id
            WHERE r.parent_id = %d AND r.status IN ('active', 'paused')
            ORDER BY r.next_session_date ASC
        ", $parent_id));

        $days = array(0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday');
        foreach ($recurring as &$r) {
            $r->day_name = $days[$r->day_of_week] ?? 'Unknown';
        }

        wp_send_json_success(array('recurring' => $recurring ?: array()));
    }

    /**
     * AJAX: Cancel recurring booking
     */
    public static function ajax_cancel_recurring() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $recurring_id = intval($_POST['recurring_id'] ?? 0);
        if (!$recurring_id) wp_send_json_error(array('message' => 'Invalid'));

        global $wpdb;

        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            get_current_user_id()
        ));

        $recurring = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_recurring WHERE id = %d AND parent_id = %d",
            $recurring_id, $parent_id
        ));

        if (!$recurring) wp_send_json_error(array('message' => 'Not found'));

        $wpdb->update(
            $wpdb->prefix . 'ptp_recurring',
            array('status' => 'cancelled'),
            array('id' => $recurring_id)
        );

        // Cancel future unconfirmed recurring bookings
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_bookings 
             SET status = 'cancelled', cancelled_by = 'parent', cancelled_at = NOW(), cancellation_reason = 'Recurring series cancelled'
             WHERE recurring_id = %d AND session_date > CURDATE() AND status IN ('pending')",
            $recurring_id
        ));

        wp_send_json_success(array('message' => 'Recurring sessions cancelled'));
    }

    /**
     * AJAX: Track profile view
     */
    public static function ajax_track_view() {
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        if (!$trainer_id) wp_send_json_error();

        global $wpdb;

        // Use a daily counter table
        $table = $wpdb->prefix . 'ptp_profile_views';

        // Ensure table exists (lightweight check)
        if (get_transient('ptp_views_table_ok') === false) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id bigint(20) UNSIGNED NOT NULL,
                view_date date NOT NULL,
                view_count int(11) DEFAULT 1,
                unique_count int(11) DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY trainer_date (trainer_id, view_date),
                KEY trainer_id (trainer_id)
            ) {$wpdb->get_charset_collate()}");
            set_transient('ptp_views_table_ok', '1', DAY_IN_SECONDS);
        }

        $today = date('Y-m-d');
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (trainer_id, view_date, view_count, unique_count) 
             VALUES (%d, %s, 1, 1) 
             ON DUPLICATE KEY UPDATE view_count = view_count + 1",
            $trainer_id, $today
        ));

        wp_send_json_success();
    }

    /**
     * Get cancellation policy as displayable HTML
     */
    /* =========================================================================
       v135: RESCHEDULE SYSTEM
       ========================================================================= */
    
    /**
     * Get available dates for rescheduling (next 30 days with open slots)
     */
    public static function ajax_get_reschedule_dates() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) wp_send_json_error(array('message' => 'Invalid booking'));
        
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.id = %d", $booking_id
        ));
        if (!$booking) wp_send_json_error(array('message' => 'Booking not found'));
        
        // Verify caller owns this booking (parent or trainer)
        $user_id = get_current_user_id();
        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d", $user_id
        ));
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", $user_id
        ));
        
        if ($booking->parent_id != $parent_id && $booking->trainer_id != $trainer_id) {
            wp_send_json_error(array('message' => 'Access denied'));
        }
        
        if (!in_array($booking->status, array('pending', 'confirmed'))) {
            wp_send_json_error(array('message' => 'This session cannot be rescheduled'));
        }
        
        // Check reschedule limit
        $reschedule_count = intval(get_post_meta($booking_id, '_ptp_reschedule_count', true) ?: 
            $wpdb->get_var($wpdb->prepare("SELECT COALESCE(
                (SELECT meta_value FROM {$wpdb->prefix}ptp_booking_meta WHERE booking_id = %d AND meta_key = 'reschedule_count'),
                0
            )", $booking_id)));
        // Fallback: use notes field
        if (!$reschedule_count) {
            $reschedule_count = substr_count($booking->notes ?? '', 'Rescheduled from');
        }
        if ($reschedule_count >= 3) {
            wp_send_json_error(array('message' => 'This session has been rescheduled the maximum number of times (3). Please cancel and rebook instead.'));
        }
        
        // Must be at least 2 hours before session
        $session_start = strtotime($booking->session_date . ' ' . $booking->start_time);
        if ($session_start < time() + 7200) {
            wp_send_json_error(array('message' => 'Sessions can only be rescheduled at least 2 hours in advance'));
        }
        
        // Get available dates for next 30 days
        $dates = array();
        if (class_exists('PTP_Availability')) {
            $today = date('Y-m-d');
            for ($i = 0; $i <= 30; $i++) {
                $check_date = date('Y-m-d', strtotime("+{$i} days"));
                $slots = PTP_Availability::get_available_slots($booking->trainer_id, $check_date);
                if (!empty($slots)) {
                    $dates[] = array(
                        'date' => $check_date,
                        'display' => date('D, M j', strtotime($check_date)),
                        'day_name' => date('l', strtotime($check_date)),
                        'slot_count' => count($slots),
                        'is_current' => ($check_date === $booking->session_date),
                    );
                }
            }
        }
        
        wp_send_json_success(array(
            'dates' => $dates,
            'booking' => array(
                'id' => $booking->id,
                'current_date' => $booking->session_date,
                'current_time' => $booking->start_time,
                'current_display' => date('l, M j', strtotime($booking->session_date)) . ' at ' . date('g:i A', strtotime($booking->start_time)),
                'trainer_name' => $booking->trainer_name,
                'duration' => intval($booking->duration_minutes),
                'reschedule_count' => $reschedule_count,
            ),
        ));
    }
    
    /**
     * Get available time slots for a specific date
     */
    public static function ajax_get_reschedule_slots() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $date = sanitize_text_field($_POST['date'] ?? '');
        
        if (!$booking_id || !$date) wp_send_json_error(array('message' => 'Missing data'));
        
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d", $booking_id
        ));
        if (!$booking) wp_send_json_error(array('message' => 'Booking not found'));
        
        // Get available slots, excluding the current booking's slot (so it shows as available)
        $slots = array();
        if (class_exists('PTP_Availability')) {
            $all_slots = PTP_Availability::get_available_slots($booking->trainer_id, $date);
            
            // If the date is the same as current booking, add back the current time slot
            if ($date === $booking->session_date) {
                $current_time = $booking->start_time;
                $already_listed = false;
                foreach ($all_slots as $s) {
                    if ($s['time'] === $current_time) {
                        $already_listed = true;
                        break;
                    }
                }
                if (!$already_listed) {
                    $all_slots[] = array(
                        'time' => $current_time,
                        'start' => substr($current_time, 0, 5),
                        'display' => date('g:i A', strtotime($current_time)),
                        'available' => true,
                        'is_current' => true,
                    );
                    usort($all_slots, function($a, $b) {
                        return strcmp($a['time'], $b['time']);
                    });
                }
            }
            
            foreach ($all_slots as $s) {
                $s['is_current'] = ($date === $booking->session_date && $s['time'] === $booking->start_time);
                $slots[] = $s;
            }
        }
        
        wp_send_json_success(array('slots' => $slots, 'date' => $date));
    }
    
    /**
     * Execute the reschedule
     */
    public static function ajax_reschedule_booking() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $new_date = sanitize_text_field($_POST['new_date'] ?? '');
        $new_time = sanitize_text_field($_POST['new_time'] ?? '');
        
        if (!$booking_id || !$new_date || !$new_time) {
            wp_send_json_error(array('message' => 'Missing date or time'));
        }
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date) || !preg_match('/^\d{2}:\d{2}/', $new_time)) {
            wp_send_json_error(array('message' => 'Invalid date or time format'));
        }
        
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.user_id as trainer_user_id, t.phone as trainer_phone, t.email as trainer_email,
                    p.display_name as parent_name, p.user_id as parent_user_id, p.email as parent_email, p.phone as parent_phone,
                    pl.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE b.id = %d", $booking_id
        ));
        
        if (!$booking) wp_send_json_error(array('message' => 'Booking not found'));
        
        // Verify ownership
        $user_id = get_current_user_id();
        $is_parent = ($booking->parent_user_id == $user_id);
        $is_trainer = ($booking->trainer_user_id == $user_id);
        if (!$is_parent && !$is_trainer) {
            wp_send_json_error(array('message' => 'Access denied'));
        }
        
        if (!in_array($booking->status, array('pending', 'confirmed'))) {
            wp_send_json_error(array('message' => 'This session cannot be rescheduled'));
        }
        
        // Must be at least 2 hours before current session
        $session_start = strtotime($booking->session_date . ' ' . $booking->start_time);
        if ($session_start < time() + 7200) {
            wp_send_json_error(array('message' => 'Too late to reschedule — less than 2 hours before session'));
        }
        
        // Don't allow rescheduling to the past
        $new_start = strtotime($new_date . ' ' . $new_time);
        if ($new_start < time() + 3600) {
            wp_send_json_error(array('message' => 'Cannot reschedule to a time less than 1 hour from now'));
        }
        
        // Same date/time = no change
        if ($new_date === $booking->session_date && substr($new_time, 0, 5) === substr($booking->start_time, 0, 5)) {
            wp_send_json_error(array('message' => 'The new date and time are the same as the current session'));
        }
        
        // Check reschedule limit
        $reschedule_count = substr_count($booking->notes ?? '', 'Rescheduled from');
        if ($reschedule_count >= 3) {
            wp_send_json_error(array('message' => 'Maximum reschedule limit reached (3)'));
        }
        
        // Verify slot is actually available (exclude current booking from conflict check)
        if (class_exists('PTP_Availability')) {
            $slots = PTP_Availability::get_available_slots($booking->trainer_id, $new_date);
            $slot_found = false;
            foreach ($slots as $s) {
                if (substr($s['time'], 0, 5) === substr($new_time, 0, 5)) {
                    $slot_found = true;
                    break;
                }
            }
            // Also allow if it's the current booking's own slot
            if (!$slot_found && !($new_date === $booking->session_date && substr($new_time, 0, 5) === substr($booking->start_time, 0, 5))) {
                wp_send_json_error(array('message' => 'This time slot is no longer available'));
            }
        }
        
        // Store original date/time in notes
        $old_display = date('M j, Y', strtotime($booking->session_date)) . ' at ' . date('g:i A', strtotime($booking->start_time));
        $new_display = date('M j, Y', strtotime($new_date)) . ' at ' . date('g:i A', strtotime($new_time));
        $who = $is_parent ? 'parent' : 'trainer';
        
        $note = 'Rescheduled from ' . $old_display . ' to ' . $new_display . ' by ' . $who . ' on ' . current_time('M j g:iA');
        $updated_notes = trim(($booking->notes ?? '') . "\n" . $note);
        
        // Calculate new end_time
        $duration = intval($booking->duration_minutes) ?: 60;
        $new_end_time = date('H:i:s', strtotime($new_time) + ($duration * 60));
        
        // Update booking
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'session_date' => $new_date,
                'start_time' => $new_time,
                'end_time' => $new_end_time,
                'notes' => $updated_notes,
                'reminder_sent' => 0,
                'hour_reminder_sent' => 0,
            ),
            array('id' => $booking_id)
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Database update failed'));
        }
        
        ptp_log("[PTP v135] Booking #{$booking_id} rescheduled: {$old_display} -> {$new_display} by {$who}");
        
        // Notify the OTHER party
        $other_user_id = $is_parent ? $booking->trainer_user_id : $booking->parent_user_id;
        $other_name = $is_parent ? $booking->trainer_name : ($booking->parent_name ?? 'Parent');
        $actor_name = $is_parent ? ($booking->parent_name ?? 'Parent') : $booking->trainer_name;
        
        // In-app notification
        if ($other_user_id && class_exists('PTP_Notifications') && method_exists('PTP_Notifications', 'create')) {
            try {
                PTP_Notifications::create(
                    $other_user_id,
                    'reschedule',
                    'Session Rescheduled',
                    $actor_name . ' rescheduled your session' . (!empty($booking->player_name) ? ' with ' . $booking->player_name : '') . ' from ' . $old_display . ' to ' . $new_display . '.',
                    array('booking_id' => $booking_id)
                );
            } catch (\Throwable $e) {
                ptp_log('PTP: Reschedule notification error: ' . $e->getMessage());
            }
        }
        
        // Email notification
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send')) {
            $email_to = $is_parent ? $booking->trainer_email : ($booking->parent_email ?? $booking->guest_email ?? '');
            if ($email_to) {
                $email_body = '<div style="font-family:-apple-system,sans-serif;max-width:500px;margin:0 auto;padding:30px 0">';
                $email_body .= '<div style="background:#0A0A0A;padding:20px 30px;border-radius:12px 12px 0 0;text-align:center"><h1 style="color:#FCB900;margin:0;font-size:22px">PTP Training</h1></div>';
                $email_body .= '<div style="background:#fff;padding:30px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px">';
                $email_body .= '<h2 style="margin:0 0 16px;font-size:20px;color:#0A0A0A">Session Rescheduled</h2>';
                $email_body .= '<p>' . esc_html($actor_name) . ' has rescheduled ' . (!empty($booking->player_name) ? esc_html($booking->player_name) . '\'s ' : 'the ') . 'training session.</p>';
                $email_body .= '<div style="background:#fef9c3;border:1px solid #fde047;padding:16px;border-radius:8px;margin:16px 0">';
                $email_body .= '<div style="text-decoration:line-through;color:#92400e;font-size:13px;margin-bottom:6px">Was: ' . esc_html($old_display) . '</div>';
                $email_body .= '<div style="font-weight:700;color:#0A0A0A;font-size:15px">New: ' . esc_html($new_display) . '</div>';
                $email_body .= '</div>';
                if (!empty($booking->location)) {
                    $email_body .= '<p style="font-size:14px;color:#6b7280">Location: ' . esc_html($booking->location) . '</p>';
                }
                $email_body .= '<p style="font-size:13px;color:#9ca3af;margin-top:16px">If this doesn\'t work for you, you can reschedule again or cancel from your dashboard.</p>';
                $email_body .= '</div></div>';
                
                try {
                    PTP_Email::send($email_to, 'Session Rescheduled — ' . date('M j', strtotime($new_date)), $email_body);
                } catch (\Throwable $e) {
                    ptp_log('PTP: Reschedule email error: ' . $e->getMessage());
                }
            }
        }
        
        // SMS notification
        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            $sms_to = $is_parent ? ($booking->trainer_phone ?? '') : ($booking->parent_phone ?? '');
            if ($sms_to) {
                try {
                    PTP_SMS::send($sms_to,
                        'PTP: Session rescheduled by ' . $actor_name . '. New time: ' . date('M j', strtotime($new_date)) . ' at ' . date('g:iA', strtotime($new_time))
                    );
                } catch (\Throwable $e) {
                    ptp_log('PTP: Reschedule SMS error: ' . $e->getMessage());
                }
            }
        }
        
        wp_send_json_success(array(
            'message' => 'Session rescheduled to ' . $new_display,
            'new_date' => $new_date,
            'new_time' => $new_time,
            'new_display' => $new_display,
        ));
    }

    public static function get_policy_html() {
        return '
        <div style="font-size:13px;color:#6B6B63;line-height:1.6">
            <strong style="color:#1A1A1A">Cancellation &amp; Reschedule Policy</strong>
            <ul style="margin:8px 0 0 16px;padding:0">
                <li><strong>Reschedule:</strong> Free, up to 2 hours before session (max 3 times)</li>
                <li><strong>24+ hours before:</strong> Full refund</li>
                <li><strong>2–24 hours before:</strong> 50% refund</li>
                <li><strong>Under 2 hours:</strong> No refund</li>
                <li><strong>Trainer cancels:</strong> Always full refund</li>
                <li><strong>No-show:</strong> No refund</li>
            </ul>
        </div>';
    }
}
