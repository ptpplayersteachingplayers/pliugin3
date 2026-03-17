<?php
/**
 * PTP Booking Fix v121
 * 
 * Fixes:
 * 1. Thank You page "PAYMENT RECEIVED - BOOKING PROCESSING" warning
 * 2. Emails not sending to parents/trainers
 * 3. Bookings not appearing in parent dashboard
 * 
 * @since 121.0.0
 */

defined('ABSPATH') || exit;

class PTP_Booking_Fix_V121 {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hook into payment confirmation to ensure booking and emails work
        add_action('init', array($this, 'register_hooks'), 5);
        
        // Fix for thank-you page booking lookup
        add_action('template_redirect', array($this, 'fix_thankyou_booking_lookup'), 5);
        
        // Ensure parent gets linked after login/register
        add_action('wp_login', array($this, 'link_parent_after_login'), 10, 2);
        add_action('user_register', array($this, 'link_parent_after_register'), 10, 1);
        
        // v225: DISABLED — emails now handled by order-email-wiring + PTP_Email
        // This was sending duplicate emails with its own inline HTML templates
        // add_action('ptp_booking_confirmed', array($this, 'ensure_booking_emails'), 10, 1);
    }
    
    public function register_hooks() {
        // Override the confirm_checkout_payment result to ensure proper redirect
        add_filter('ptp_checkout_redirect_url', array($this, 'fix_checkout_redirect'), 10, 2);
    }
    
    /**
     * Fix thank-you page booking lookup
     * Sets booking_id from payment_intent if not in URL
     */
    public function fix_thankyou_booking_lookup() {
        // Only run on thank-you or booking-confirmation pages
        if (!is_page('thank-you') && !is_page('booking-confirmation')) {
            return;
        }
        
        global $wpdb;
        
        $booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
        $payment_intent = isset($_GET['payment_intent']) ? sanitize_text_field($_GET['payment_intent']) : '';
        
        // If we have a payment_intent but no booking_id, find the booking
        if (!$booking_id && $payment_intent) {
            $booking_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s ORDER BY id DESC LIMIT 1",
                $payment_intent
            ));
            
            if ($booking_id) {
                $_GET['booking_id'] = $booking_id;
                ptp_log("[PTP Fix v121] Found booking $booking_id from payment_intent $payment_intent");
                
                // Update status if still pending
                $status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                    $booking_id
                ));
                
                if ($status === 'pending') {
                    // Verify payment with Stripe if possible
                    if (class_exists('PTP_Stripe')) {
                        try {
                            $pi = PTP_Stripe::get_payment_intent($payment_intent);
                            if (!is_wp_error($pi) && isset($pi['status']) && $pi['status'] === 'succeeded') {
                                $wpdb->update(
                                    $wpdb->prefix . 'ptp_bookings',
                                    array(
                                        'status' => 'confirmed',
                                        'payment_status' => 'paid',
                                        'paid_at' => current_time('mysql')
                                    ),
                                    array('id' => $booking_id)
                                );
                                ptp_log("[PTP Fix v121] Updated booking $booking_id to confirmed");
                                
                                // Trigger emails
                                $this->ensure_booking_emails($booking_id);
                            }
                        } catch (Exception $e) {
                            ptp_log("[PTP Fix v121] Stripe check error: " . $e->getMessage());
                        }
                    }
                }
            }
        }
        
        // If we have booking_id, ensure booking_number is set
        if ($booking_id) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                $booking_id
            ));
            
            if ($booking && (empty($booking->booking_number) || $booking->booking_number === 'PENDING')) {
                $new_number = 'PTP-' . strtoupper(substr(md5($booking_id . time()), 0, 8));
                $wpdb->update(
                    $wpdb->prefix . 'ptp_bookings',
                    array('booking_number' => $new_number),
                    array('id' => $booking_id)
                );
                ptp_log("[PTP Fix v121] Set booking_number to $new_number for booking $booking_id");
            }
        }
        
        // Also check cookies
        if (!$booking_id && isset($_COOKIE['ptp_last_booking'])) {
            $_GET['booking_id'] = intval($_COOKIE['ptp_last_booking']);
        }
    }
    
    /**
     * Ensure booking confirmation emails are sent
     */
    public function ensure_booking_emails($booking_id) {
        global $wpdb;
        
        // Check if already sent
        $sent_check = get_transient('ptp_training_email_sent_' . $booking_id);
        if ($sent_check) {
            ptp_log("[PTP Fix v121] Emails already sent for booking $booking_id");
            return;
        }
        
        // Get full booking data
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, 
                    t.display_name as trainer_name, 
                    t.user_id as trainer_user_id, 
                    t.email as trainer_email,
                    t.location as trainer_location,
                    t.city as trainer_city,
                    t.state as trainer_state,
                    COALESCE(p.name, 'Player') as player_name,
                    COALESCE(pa.display_name, 'Guest') as parent_name,
                    COALESCE(pa.user_id, 0) as parent_user_id,
                    COALESCE(pa.email, '') as parent_email,
                    COALESCE(pa.phone, '') as parent_phone
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            ptp_log("[PTP Fix v121] Booking $booking_id not found for emails");
            return;
        }
        
        // Get email addresses with multiple fallbacks
        $parent_email = '';
        
        // Priority 1: WordPress user email
        if ($booking->parent_user_id) {
            $wp_user = get_user_by('ID', $booking->parent_user_id);
            if ($wp_user) {
                $parent_email = $wp_user->user_email;
            }
        }
        
        // Priority 2: Parent record email
        if (!$parent_email && !empty($booking->parent_email)) {
            $parent_email = $booking->parent_email;
        }
        
        // Priority 3: Guest email from booking
        if (!$parent_email && !empty($booking->guest_email)) {
            $parent_email = $booking->guest_email;
        }
        
        // Priority 4: Parse from notes
        if (!$parent_email && !empty($booking->notes)) {
            if (preg_match('/Email:\s*([^\s\n]+)/i', $booking->notes, $matches)) {
                $parent_email = trim($matches[1]);
            }
        }
        
        // Priority 5: Current logged in user
        if (!$parent_email && is_user_logged_in()) {
            $current = wp_get_current_user();
            $parent_email = $current->user_email;
        }
        
        // Get trainer email
        $trainer_email = '';
        if ($booking->trainer_user_id) {
            $trainer_user = get_user_by('ID', $booking->trainer_user_id);
            if ($trainer_user) {
                $trainer_email = $trainer_user->user_email;
            }
        }
        if (!$trainer_email && !empty($booking->trainer_email)) {
            $trainer_email = $booking->trainer_email;
        }
        
        ptp_log("[PTP Fix v121] Sending emails: parent=$parent_email, trainer=$trainer_email");
        
        // Format data
        $session_date = strtotime($booking->session_date);
        $email_data = array(
            'booking_id' => $booking_id,
            'booking_number' => $booking->booking_number,
            'trainer_name' => $booking->trainer_name,
            'player_name' => $booking->player_name,
            'parent_name' => $booking->parent_name,
            'parent_phone' => $booking->parent_phone,
            'date' => date('l, F j, Y', $session_date),
            'time' => date('g:i A', strtotime($booking->start_time)),
            // v211: Prefer trainer's location from profile, fall back to booking record
            'location' => (!empty($booking->trainer_location) ? $booking->trainer_location : 
                          (!empty($booking->trainer_city) ? $booking->trainer_city . (!empty($booking->trainer_state) ? ', ' . $booking->trainer_state : '') : 
                          ($booking->location ?: 'To be confirmed'))),
            'total' => number_format($booking->total_amount, 2),
            'trainer_earnings' => number_format($booking->trainer_payout, 2),
        );
        
        $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . ptp_email_brand('from_training'));
        
        // Send parent email
        if ($parent_email && is_email($parent_email)) {
            $subject = "Booking Confirmed! Session with {$email_data['trainer_name']}";
            $body = $this->get_parent_email_html($email_data);
            $sent = wp_mail($parent_email, $subject, $body, $headers);
            ptp_log("[PTP Fix v121] Parent email to $parent_email: " . ($sent ? 'sent' : 'failed'));
        }
        
        // Send trainer email
        if ($trainer_email && is_email($trainer_email)) {
            $subject = "New Booking! {$email_data['player_name']} - {$email_data['date']}";
            $body = $this->get_trainer_email_html($email_data);
            $sent = wp_mail($trainer_email, $subject, $body, $headers);
            ptp_log("[PTP Fix v121] Trainer email to $trainer_email: " . ($sent ? 'sent' : 'failed'));
        }
        
        // Mark as sent
        set_transient('ptp_training_email_sent_' . $booking_id, array(
            'time' => time(),
            'parent_email' => $parent_email,
            'trainer_email' => $trainer_email
        ), 3600);
    }
    
    /**
     * Link parent to user after login
     */
    public function link_parent_after_login($user_login, $user) {
        global $wpdb;
        
        // Check if user already has a parent record
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user->ID
        ));
        
        if ($existing) {
            return;
        }
        
        // Check for orphaned parent by email
        $orphaned = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE email = %s AND (user_id = 0 OR user_id IS NULL)",
            $user->user_email
        ));
        
        if ($orphaned) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_parents',
                array('user_id' => $user->ID),
                array('id' => $orphaned->id)
            );
            ptp_log("[PTP Fix v121] Linked orphaned parent {$orphaned->id} to user {$user->ID}");
            
            // Also link any bookings that were made as guest
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_bookings 
                 SET parent_id = %d 
                 WHERE (parent_id = 0 OR parent_id IS NULL) AND guest_email = %s",
                $orphaned->id, $user->user_email
            ));
        }
    }
    
    /**
     * Create parent record after registration
     */
    public function link_parent_after_register($user_id) {
        global $wpdb;
        
        $user = get_user_by('ID', $user_id);
        if (!$user) return;
        
        // Check if already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if (!$existing) {
            // Create parent record
            $wpdb->insert(
                $wpdb->prefix . 'ptp_parents',
                array(
                    'user_id' => $user_id,
                    'email' => $user->user_email,
                    'display_name' => $user->display_name ?: $user->user_login,
                    'created_at' => current_time('mysql')
                )
            );
            ptp_log("[PTP Fix v121] Created parent record for new user $user_id");
        }
    }
    
    /**
     * Generate parent confirmation email HTML
     */
    private function get_parent_email_html($data) {
        $dashboard_url = home_url('/my-training/');
        
        return "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f5f5f5;padding:40px 20px;'>
<tr><td align='center'>
<table width='100%' style='max-width:500px;' cellpadding='0' cellspacing='0'>
    <!-- Header -->
    <tr><td style='background:#0E0F11;padding:32px;text-align:center;border-radius:12px 12px 0 0;'>
        <h1 style='margin:0;font-size:28px;font-weight:700;color:#FCB900;'>✓ BOOKING CONFIRMED</h1>
        <p style='margin:8px 0 0;color:rgba(255,255,255,0.7);font-size:14px;'>Reference: {$data['booking_number']}</p>
    </td></tr>
    
    <!-- Body -->
    <tr><td style='background:#fff;padding:32px;'>
        <h2 style='margin:0 0 20px;font-size:18px;color:#111;'>Session Details</h2>
        <table width='100%' style='border-collapse:collapse;'>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Trainer</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['trainer_name']}</td>
            </tr>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Player</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['player_name']}</td>
            </tr>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Date</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['date']}</td>
            </tr>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Time</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['time']}</td>
            </tr>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Location</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['location']}</td>
            </tr>
            <tr>
                <td style='padding:20px 0 0;color:#111;font-size:16px;font-weight:700;'>Total Paid</td>
                <td style='padding:20px 0 0;color:#111;font-size:22px;font-weight:800;text-align:right;'>\${$data['total']}</td>
            </tr>
        </table>
        
        <div style='margin-top:32px;padding-top:24px;border-top:1px solid #e5e7eb;'>
            <h3 style='margin:0 0 16px;font-size:15px;color:#FCB900;font-weight:700;'>WHAT'S NEXT</h3>
            <div style='display:flex;gap:12px;margin-bottom:12px;'>
                <span style='background:#FCB900;color:#0E0F11;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;'>1</span>
                <span style='color:#374151;font-size:14px;'><strong>Your trainer will reach out</strong> to confirm location details</span>
            </div>
            <div style='display:flex;gap:12px;'>
                <span style='background:#FCB900;color:#0E0F11;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;'>2</span>
                <span style='color:#374151;font-size:14px;'><strong>Show up ready to train!</strong> Bring water, cleats, and a ball</span>
            </div>
        </div>
    </td></tr>
    
    <!-- CTA -->
    <tr><td style='background:#fff;padding:0 32px 32px;border-radius:0 0 12px 12px;'>
        <a href='{$dashboard_url}' style='display:block;background:#FCB900;color:#0E0F11;padding:16px;text-align:center;text-decoration:none;font-weight:700;font-size:15px;border-radius:8px;'>VIEW MY SESSIONS</a>
    </td></tr>
    
    <!-- Footer -->
    <tr><td style='padding:24px;text-align:center;'>
        <p style='margin:0;color:#9ca3af;font-size:12px;'>Questions? Reply to this email or call (610) 761-5230</p>
        <p style='margin:8px 0 0;color:#9ca3af;font-size:12px;'>Free cancellation up to 24 hours before your session.</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>";
    }
    
    /**
     * Generate trainer notification email HTML
     */
    private function get_trainer_email_html($data) {
        $dashboard_url = home_url('/trainer-dashboard/');
        
        return "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f5f5f5;padding:40px 20px;'>
<tr><td align='center'>
<table width='100%' style='max-width:500px;' cellpadding='0' cellspacing='0'>
    <!-- Header -->
    <tr><td style='background:#0E0F11;padding:32px;text-align:center;border-radius:12px 12px 0 0;'>
        <h1 style='margin:0;font-size:28px;font-weight:700;color:#FCB900;'>🎉 NEW BOOKING!</h1>
        <p style='margin:8px 0 0;color:rgba(255,255,255,0.7);font-size:14px;'>You have a new training session</p>
    </td></tr>
    
    <!-- Body -->
    <tr><td style='background:#fff;padding:32px;'>
        <h2 style='margin:0 0 20px;font-size:18px;color:#111;'>Session Details</h2>
        <table width='100%' style='border-collapse:collapse;'>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Player</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['player_name']}</td>
            </tr>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Parent</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['parent_name']}</td>
            </tr>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Phone</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['parent_phone']}</td>
            </tr>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Date</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['date']}</td>
            </tr>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Time</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['time']}</td>
            </tr>
            <tr style='border-bottom:1px solid #f3f4f6;'>
                <td style='padding:14px 0;color:#6b7280;font-size:14px;'>Location</td>
                <td style='padding:14px 0;color:#111;font-size:15px;font-weight:600;text-align:right;'>{$data['location']}</td>
            </tr>
            <tr>
                <td style='padding:20px 0 0;color:#059669;font-size:16px;font-weight:700;'>Your Earnings</td>
                <td style='padding:20px 0 0;color:#059669;font-size:22px;font-weight:800;text-align:right;'>\${$data['trainer_earnings']}</td>
            </tr>
        </table>
    </td></tr>
    
    <!-- CTA -->
    <tr><td style='background:#fff;padding:0 32px 32px;border-radius:0 0 12px 12px;'>
        <a href='{$dashboard_url}' style='display:block;background:#FCB900;color:#0E0F11;padding:16px;text-align:center;text-decoration:none;font-weight:700;font-size:15px;border-radius:8px;'>VIEW IN DASHBOARD</a>
    </td></tr>
    
    <!-- Footer -->
    <tr><td style='padding:24px;text-align:center;'>
        <p style='margin:0;color:#9ca3af;font-size:12px;'>Please reach out to the parent to confirm location details.</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>";
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Booking_Fix_V121::instance();
}, 5);
