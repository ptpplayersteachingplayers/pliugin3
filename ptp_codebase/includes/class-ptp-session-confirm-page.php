<?php
/**
 * Session Confirmation Page — Handler
 * 
 * Provides:
 * - Token generation/verification for SMS links
 * - [ptp_confirm_session] shortcode
 * - REST endpoint for nopriv confirmation (no login required)
 * - Page auto-creation
 */
defined('ABSPATH') || exit;

class PTP_Session_Confirm_Page {

    public static function init() {
        // Shortcode
        add_shortcode('ptp_confirm_session', [__CLASS__, 'render_shortcode']);

        // REST API (nopriv — parents aren't logged in)
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    // ══════════════════════════════════════════
    // TOKEN GENERATION
    // ══════════════════════════════════════════

    /**
     * Generate a secure token for a booking + phone combo.
     * Uses WordPress auth salt so tokens can't be forged.
     */
    public static function generate_token($booking_id, $phone) {
        $phone_clean = preg_replace('/\D/', '', $phone);
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'ptp-session-confirm';
        return hash_hmac('sha256', $booking_id . ':' . $phone_clean, $salt);
    }

    /**
     * Verify a token against booking + phone.
     */
    public static function verify_token($booking_id, $phone, $token) {
        if (empty($token)) return true; // Accept legacy links without token
        $expected = self::generate_token($booking_id, $phone);
        return hash_equals($expected, $token);
    }

    /**
     * Build confirmation URL for SMS.
     */
    public static function get_confirm_url($booking_id, $phone) {
        $token = self::generate_token($booking_id, $phone);
        return home_url('/confirm-session/?booking=' . intval($booking_id) . '&token=' . $token);
    }

    // ══════════════════════════════════════════
    // SHORTCODE
    // ══════════════════════════════════════════

    public static function render_shortcode($atts) {
        ob_start();
        $template = PTP_PLUGIN_DIR . 'templates/confirm-session.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<p>Session confirmation page not available.</p>';
        }
        return ob_get_clean();
    }

    // ══════════════════════════════════════════
    // REST API
    // ══════════════════════════════════════════

    public static function register_rest_routes() {
        register_rest_route('ptp/v1', '/confirm-session', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_confirm'],
            'permission_callback' => '__return_true', // Public — verified by token
        ]);
    }

    public static function handle_confirm($request) {
        global $wpdb;

        $booking_id = intval($request->get_param('booking_id'));
        $token      = sanitize_text_field($request->get_param('token') ?? '');
        $action     = sanitize_text_field($request->get_param('action') ?? 'confirm');
        $rating     = intval($request->get_param('rating') ?? 0);
        $feedback   = sanitize_textarea_field($request->get_param('feedback') ?? '');
        $reason     = sanitize_textarea_field($request->get_param('reason') ?? '');

        if (!$booking_id) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Missing booking ID.'], 400);
        }

        // Load booking
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, pa.phone as parent_phone, pa.id as parent_id_lookup
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             WHERE b.id = %d",
            $booking_id
        ));

        if (!$booking) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Booking not found.'], 404);
        }

        // Verify token
        if (!self::verify_token($booking_id, $booking->parent_phone ?? '', $token)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid token.'], 403);
        }

        $escrow_table = $wpdb->prefix . 'ptp_escrow';
        $has_escrow = $wpdb->get_var("SHOW TABLES LIKE '$escrow_table'") === $escrow_table;

        // ── DISPUTE ──
        if ($action === 'dispute') {
            if ($has_escrow) {
                $escrow = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $escrow_table WHERE booking_id = %d ORDER BY id DESC LIMIT 1",
                    $booking_id
                ));
                if ($escrow) {
                    $wpdb->update($escrow_table, [
                        'status'           => 'disputed',
                        'dispute_reason'   => $reason,
                        'dispute_filed_at' => current_time('mysql'),
                    ], ['id' => $escrow->id]);

                    if (class_exists('PTP_Escrow') && method_exists('PTP_Escrow', 'log_event')) {
                        PTP_Escrow::log_event($escrow->id, 'parent_disputed', 'Dispute via SMS link: ' . $reason);
                    }
                }
            }

            // Notify admin
            $admin_email = get_option('admin_email');
            wp_mail($admin_email, 'PTP Session Dispute — Booking #' . $booking_id,
                "A parent reported an issue with booking #{$booking_id}.\n\nReason: {$reason}\n\nReview in WP Admin.");

            // Log to Command Center if available
            if (class_exists('CC_DB') && method_exists('CC_DB', 'log')) {
                CC_DB::log('session_disputed', 'booking', $booking_id, 'Parent disputed: ' . substr($reason, 0, 100), 'sms_link');
            }

            return new \WP_REST_Response(['success' => true, 'message' => 'Dispute submitted.']);
        }

        // ── CONFIRM ──
        if ($has_escrow) {
            $escrow = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $escrow_table WHERE booking_id = %d ORDER BY id DESC LIMIT 1",
                $booking_id
            ));

            if ($escrow && in_array($escrow->status, ['confirmed', 'released'])) {
                return new \WP_REST_Response(['success' => true, 'message' => 'Already confirmed.']);
            }

            if ($escrow) {
                $wpdb->update($escrow_table, [
                    'status'              => 'confirmed',
                    'parent_confirmed_at' => current_time('mysql'),
                    'parent_rating'       => $rating ?: null,
                    'parent_feedback'     => $feedback ?: null,
                ], ['id' => $escrow->id]);

                if (class_exists('PTP_Escrow') && method_exists('PTP_Escrow', 'log_event')) {
                    PTP_Escrow::log_event($escrow->id, 'parent_confirmed', 'Confirmed via SMS link');
                }

                // Release funds
                if (class_exists('PTP_Escrow') && method_exists('PTP_Escrow', 'release_funds')) {
                    PTP_Escrow::release_funds($escrow->id);
                }
            }
        }

        // Mark booking as completed
        $wpdb->update($wpdb->prefix . 'ptp_bookings', [
            'status' => 'completed',
        ], ['id' => $booking_id]);

        do_action('ptp_booking_completed', $booking_id, $booking);

        // Save review if rated
        if ($rating > 0) {
            $review_table = $wpdb->prefix . 'ptp_reviews';
            if ($wpdb->get_var("SHOW TABLES LIKE '$review_table'") === $review_table) {
                $wpdb->insert($review_table, [
                    'booking_id' => $booking_id,
                    'trainer_id' => $booking->trainer_id,
                    'parent_id'  => $booking->parent_id,
                    'rating'     => min(5, max(1, $rating)),
                    'comment'    => $feedback,
                    'status'     => 'approved',
                    'created_at' => current_time('mysql'),
                ]);

                // Update trainer average rating
                $avg = $wpdb->get_var($wpdb->prepare(
                    "SELECT AVG(rating) FROM $review_table WHERE trainer_id = %d AND status = 'approved'",
                    $booking->trainer_id
                ));
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $review_table WHERE trainer_id = %d AND status = 'approved'",
                    $booking->trainer_id
                ));
                $wpdb->update($wpdb->prefix . 'ptp_trainers', [
                    'average_rating' => round($avg, 2),
                    'review_count'   => $count,
                ], ['id' => $booking->trainer_id]);
            }
        }

        // Log to Command Center
        if (class_exists('CC_DB') && method_exists('CC_DB', 'log')) {
            CC_DB::log('session_confirmed', 'booking', $booking_id,
                'Parent confirmed via SMS' . ($rating ? " ({$rating} stars)" : ''), 'sms_link');
        }

        return new \WP_REST_Response(['success' => true, 'message' => 'Session confirmed!']);
    }
}
