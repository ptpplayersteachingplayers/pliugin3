<?php
/**
 * PTP Thank You Handler v161
 * 
 * DEPRECATED: Thank-you page handling is now done by PTP_Training_Thankyou
 * This class is kept for backwards compatibility but its main hook is disabled.
 * 
 * v161: Disabled template_redirect hook to prevent conflicts with PTP_Training_Thankyou
 */

if (!defined('ABSPATH')) exit;

class PTP_Thankyou_Handler {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // v161: DISABLED - PTP_Training_Thankyou now handles /thank-you/ on init
        // This avoids conflicts between multiple handlers
        // add_action('template_redirect', array($this, 'handle_thankyou'), 1);
        
        // Ensure page exists on init
        add_action('init', array($this, 'ensure_page_exists'), 20);
    }
    
    /**
     * Ensure thank-you page exists in WordPress
     */
    public function ensure_page_exists() {
        // Only run once
        if (get_option('ptp_thankyou_page_created_v133')) {
            return;
        }
        
        $existing = get_page_by_path('thank-you', OBJECT, 'page');
        if (!$existing) {
            $page_id = wp_insert_post(array(
                'post_title'     => 'Thank You',
                'post_name'      => 'thank-you',
                'post_content'   => '<!-- PTP Thank You Page -->',
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ));
            
            if ($page_id && !is_wp_error($page_id)) {
                ptp_log('[PTP Thankyou v133] Created thank-you page: ' . $page_id);
                flush_rewrite_rules();
            }
        }
        
        update_option('ptp_thankyou_page_created_v133', true);
    }
    
    /**
     * Handle thank-you page requests
     */
    public function handle_thankyou() {
        // Check if this is a thank-you URL
        $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        
        if (!in_array($uri, array('thank-you', 'thankyou'))) {
            return;
        }
        
        ptp_log('[PTP Thankyou v133] ===== THANK YOU PAGE =====');
        ptp_log('[PTP Thankyou v133] GET: ' . json_encode($_GET));
        
        // Suppress WP notices during output
        @ini_set('display_errors', 0);
        
        // Get booking data
        $data = $this->get_booking_data();
        
        ptp_log('[PTP Thankyou v133] Booking data: ' . json_encode($data));
        
        // Render the page
        $this->render($data);
        exit;
    }
    
    /**
     * Get booking data from URL params or database
     */
    private function get_booking_data() {
        global $wpdb;
        
        $data = array(
            'type' => 'unknown',
            'booking_id' => 0,
            'order_id' => 0,
            'trainer_name' => '',
            'trainer_photo' => '',
            'player_name' => '',
            'session_date' => '',
            'session_time' => '',
            'location' => '',
            'package' => 'single',
            'sessions' => 1,
            'amount' => 0,
            'parent_email' => '',
        );
        
        // Check for booking ID (training)
        $booking_id = 0;
        
        // Try various URL params
        if (!empty($_GET['bookings'])) {
            $ids = array_map('intval', explode(',', sanitize_text_field($_GET['bookings'])));
            $booking_id = $ids[0] ?? 0;
        } elseif (!empty($_GET['booking_id'])) {
            $booking_id = intval($_GET['booking_id']);
        } elseif (!empty($_GET['booking'])) {
            $booking_id = intval($_GET['booking']);
        }
        
        // Try payment_intent lookup
        if (!$booking_id && !empty($_GET['payment_intent'])) {
            $pi = sanitize_text_field($_GET['payment_intent']);
            $booking_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s LIMIT 1",
                $pi
            ));
            if ($booking_id) {
                ptp_log('[PTP Thankyou v133] Found booking by payment_intent: ' . $booking_id);
            }
        }
        
        // Try session transient lookup
        if (!$booking_id && !empty($_GET['session'])) {
            $session_key = sanitize_text_field($_GET['session']);
            $session_data = get_transient('ptp_checkout_' . $session_key);
            
            if ($session_data) {
                ptp_log('[PTP Thankyou v133] Found session transient: ' . json_encode($session_data));
                
                // Try to find booking by trainer + recent time
                $trainer_id = intval($session_data['trainer_id'] ?? 0);
                if ($trainer_id) {
                    $booking_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}ptp_bookings 
                         WHERE trainer_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                         ORDER BY id DESC LIMIT 1",
                        $trainer_id
                    ));
                    
                    if ($booking_id) {
                        ptp_log('[PTP Thankyou v133] Found booking by trainer from session: ' . $booking_id);
                    }
                }
                
                // Even if no booking found, use session data for display
                if (!$booking_id && $session_data) {
                    return $this->data_from_session($session_data);
                }
            }
        }
        
        // Try recent user booking
        if (!$booking_id && is_user_logged_in()) {
            $user_id = get_current_user_id();
            $parent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $user_id
            ));
            
            if ($parent_id) {
                $booking_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_bookings 
                     WHERE parent_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                     ORDER BY id DESC LIMIT 1",
                    $parent_id
                ));
                
                if ($booking_id) {
                    ptp_log('[PTP Thankyou v133] Found recent booking for user: ' . $booking_id);
                }
            }
        }
        
        // Load booking from database
        if ($booking_id) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, 
                        t.display_name as trainer_name, 
                        t.photo_url as trainer_photo,
                        t.slug as trainer_slug,
                        p.email as parent_email,
                        pl.first_name as player_first,
                        pl.last_name as player_last
                 FROM {$wpdb->prefix}ptp_bookings b
                 LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
                 LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
                 WHERE b.id = %d",
                $booking_id
            ));
            
            if ($booking) {
                $data['type'] = 'training';
                $data['booking_id'] = $booking_id;
                $data['trainer_name'] = $booking->trainer_name ?? '';
                $data['trainer_photo'] = $booking->trainer_photo ?? '';
                $data['trainer_slug'] = $booking->trainer_slug ?? '';
                $data['player_name'] = trim(($booking->player_first ?? '') . ' ' . ($booking->player_last ?? ''));
                $data['session_date'] = $booking->session_date ?? '';
                $data['session_time'] = $booking->start_time ?? '';
                $data['location'] = $booking->location ?? '';
                $data['package'] = $booking->package_type ?? 'single';
                $data['sessions'] = $booking->total_sessions ?? 1;
                $data['amount'] = $booking->total_amount ?? 0;
                $data['parent_email'] = $booking->parent_email ?? '';
                $data['booking_number'] = $booking->booking_number ?? '';
                
                ptp_log('[PTP Thankyou v133] Loaded booking: ' . json_encode($data));
                return $data;
            }
        }
        
        // Check for PTP Native order (camp purchase)
        $order_id = intval($_GET['order_id'] ?? $_GET['order'] ?? 0);
        if ($order_id && false) {
            $order = PTP_Native_Order_Manager::get_order($order_id);
            if ($order) {
                $data['type'] = 'camp';
                $data['order_id'] = $order_id;
                // Let PTP Native handle camp thank-you
                return $data;
            }
        }
        
        return $data;
    }
    
    /**
     * Build data from session transient (fallback if booking not created)
     */
    private function data_from_session($session) {
        $data = array(
            'type' => 'training',
            'booking_id' => 0,
            'trainer_name' => '',
            'trainer_photo' => '',
            'player_name' => '',
            'session_date' => $session['session_date'] ?? '',
            'session_time' => $session['session_time'] ?? '',
            'location' => $session['session_location'] ?? '',
            'package' => $session['training_package'] ?? 'single',
            'sessions' => 1,
            'amount' => $session['training_total'] ?? 0,
            'parent_email' => $session['parent_data']['email'] ?? '',
        );
        
        // Get trainer info
        if (!empty($session['trainer_id'])) {
            global $wpdb;
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT display_name, photo_url, slug FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                intval($session['trainer_id'])
            ));
            if ($trainer) {
                $data['trainer_name'] = $trainer->display_name;
                $data['trainer_photo'] = $trainer->photo_url;
                $data['trainer_slug'] = $trainer->slug;
            }
        }
        
        // Get player name
        if (!empty($session['camper_data'])) {
            $data['player_name'] = trim(($session['camper_data']['first_name'] ?? '') . ' ' . ($session['camper_data']['last_name'] ?? ''));
        }
        
        $sessions_map = array('single' => 1, 'pack3' => 3, 'pack5' => 5);
        $data['sessions'] = $sessions_map[$data['package']] ?? 1;
        
        return $data;
    }
    
    /**
     * Render the thank-you page
     */
    private function render($data) {
        // For camp orders, redirect to PTP Native thank-you or show basic
        if ($data['type'] === 'camp' && $data['order_id']) {
            // Try to use PTP Native thank-you
            $thanks_url = home_url('/ptp-checkout/') . 'order-received/' . $data['order_id'] . '/';
            // Or just show our template with order info
        }
        
        // Training confirmation
        $this->render_training_thankyou($data);
    }
    
    /**
     * Render training thank-you page
     */
    private function render_training_thankyou($data) {
        $trainer_name = esc_html($data['trainer_name'] ?: 'Your Trainer');
        $trainer_photo = esc_url($data['trainer_photo'] ?: 'https://ptpsummercamps.com/wp-content/uploads/2025/01/default-trainer.jpg');
        $trainer_slug = $data['trainer_slug'] ?? '';
        $player_name = esc_html($data['player_name'] ?: 'Your Player');
        $player_upper = strtoupper($player_name);
        
        // Format date - validate properly to avoid "November 30, -0001" 
        $date_display = 'TBD';
        if (!empty($data['session_date']) && !preg_match('/^0000/', $data['session_date'])) {
            $timestamp = strtotime($data['session_date']);
            if ($timestamp && $timestamp > 0 && intval(date('Y', $timestamp)) >= 2020) {
                $date_display = date('l, F j, Y', $timestamp);
            }
        }
        
        // Format time - validate properly
        $time_display = 'TBD';
        if (!empty($data['session_time']) && strpos($data['session_time'], ':') !== false) {
            $parts = explode(':', $data['session_time']);
            $hour = intval($parts[0]);
            $min = isset($parts[1]) ? str_pad(intval($parts[1]), 2, '0', STR_PAD_LEFT) : '00';
            // Validate hour is reasonable (0-23)
            if ($hour >= 0 && $hour <= 23) {
                $ampm = $hour >= 12 ? 'PM' : 'AM';
                $hour12 = $hour > 12 ? $hour - 12 : ($hour ?: 12);
                $time_display = $hour12 . ':' . $min . ' ' . $ampm;
            }
        }
        
        $location = esc_html($data['location'] ?: 'Location TBD');
        
        // Package info
        $package_labels = array(
            'single' => 'Single Session',
            'pack3' => '3-Session Pack',
            'pack5' => '5-Session Pack'
        );
        $package_display = $package_labels[$data['package']] ?? 'Training Session';
        $sessions = intval($data['sessions'] ?: 1);
        
        $amount = number_format(floatval($data['amount']), 2);
        $booking_number = esc_html($data['booking_number'] ?? ('PTP-' . ($data['booking_id'] ?: rand(10000, 99999))));
        
        // Trainer profile link
        $trainer_link = $trainer_slug ? home_url('/trainer/' . $trainer_slug . '/') : home_url('/find-trainers/');
        
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
        
        :root {
            --gold: #FCB900;
            --black: #0A0A0A;
            --white: #FFFFFF;
            --gray: rgba(255,255,255,0.7);
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--black);
            color: var(--white);
            min-height: 100vh;
            min-height: 100dvh;
        }
        
        .ty-header {
            background: var(--gold);
            padding: 60px 20px 40px;
            text-align: center;
        }
        
        .ty-check {
            width: 80px;
            height: 80px;
            background: var(--black);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 40px;
        }
        
        .ty-title {
            font-family: 'Oswald', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: var(--black);
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .ty-subtitle {
            font-size: 16px;
            color: rgba(0,0,0,0.7);
        }
        
        .ty-content {
            max-width: 500px;
            margin: 0 auto;
            padding: 0 20px 40px;
        }
        
        .ty-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            margin-top: -30px;
            overflow: hidden;
        }
        
        .ty-trainer {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .ty-trainer-photo {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            border: 3px solid var(--gold);
            object-fit: cover;
        }
        
        .ty-trainer-info h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .ty-trainer-info p {
            font-size: 13px;
            color: var(--gray);
        }
        
        .ty-details {
            padding: 20px;
        }
        
        .ty-detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        
        .ty-detail-row:last-child {
            border-bottom: none;
        }
        
        .ty-detail-label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--gray);
            letter-spacing: 0.5px;
        }
        
        .ty-detail-value {
            font-weight: 600;
            text-align: right;
            max-width: 60%;
        }
        
        .ty-detail-value.gold {
            color: var(--gold);
        }
        
        .ty-booking-number {
            background: var(--gold);
            color: var(--black);
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
        }
        
        .ty-booking-number span:first-child {
            font-size: 12px;
            text-transform: uppercase;
        }
        
        .ty-booking-number span:last-child {
            font-family: 'Oswald', sans-serif;
            font-size: 18px;
        }
        
        .ty-cta {
            margin-top: 24px;
        }
        
        .ty-btn {
            display: block;
            width: 100%;
            padding: 16px;
            background: var(--gold);
            color: var(--black);
            text-align: center;
            text-decoration: none;
            font-family: 'Oswald', sans-serif;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            border-radius: 8px;
            border: none;
            cursor: pointer;
        }
        
        .ty-btn-outline {
            background: transparent;
            border: 2px solid var(--gold);
            color: var(--gold);
            margin-top: 12px;
        }
        
        .ty-message {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 8px;
            padding: 16px;
            margin-top: 24px;
            text-align: center;
        }
        
        .ty-message p {
            font-size: 14px;
            color: #22C55E;
        }
        
        .ty-footer {
            text-align: center;
            padding: 20px;
            color: var(--gray);
            font-size: 13px;
        }
        
        .ty-footer a {
            color: var(--gold);
            text-decoration: none;
        }
        
        @media (max-width: 400px) {
            .ty-header { padding: 40px 16px 30px; }
            .ty-title { font-size: 26px; }
            .ty-trainer { flex-direction: column; text-align: center; }
            .ty-detail-row { flex-direction: column; gap: 4px; }
            .ty-detail-value { text-align: left; max-width: 100%; }
        }
    </style>
</head>
<body>
    <div class="ty-header">
        <div class="ty-check">✓</div>
        <h1 class="ty-title">You're Locked In!</h1>
        <p class="ty-subtitle"><?php echo $player_upper; ?> is training with <?php echo $trainer_name; ?></p>
    </div>
    
    <div class="ty-content">
        <div class="ty-card">
            <div class="ty-trainer">
                <img src="<?php echo $trainer_photo; ?>" alt="<?php echo $trainer_name; ?>" class="ty-trainer-photo">
                <div class="ty-trainer-info">
                    <h3><?php echo $trainer_name; ?></h3>
                    <p>PTP Pro Trainer</p>
                </div>
            </div>
            
            <div class="ty-details">
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Player</span>
                    <span class="ty-detail-value"><?php echo $player_name; ?></span>
                </div>
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Package</span>
                    <span class="ty-detail-value"><?php echo $package_display; ?></span>
                </div>
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Date</span>
                    <span class="ty-detail-value"><?php echo $date_display; ?></span>
                </div>
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Time</span>
                    <span class="ty-detail-value"><?php echo $time_display; ?></span>
                </div>
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Location</span>
                    <span class="ty-detail-value"><?php echo $location; ?></span>
                </div>
                <?php if ($sessions > 1): ?>
                <div class="ty-detail-row">
                    <span class="ty-detail-label">Sessions Remaining</span>
                    <span class="ty-detail-value gold"><?php echo $sessions - 1; ?> more sessions</span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="ty-booking-number">
                <span>Booking #</span>
                <span><?php echo $booking_number; ?></span>
            </div>
        </div>
        
        <div class="ty-message">
            <p>📧 Confirmation email sent! Your trainer will reach out to confirm details.</p>
        </div>
        
        <div class="ty-cta">
            <a href="<?php echo esc_url($trainer_link); ?>" class="ty-btn">Book Another Session</a>
            <a href="<?php echo esc_url(home_url('/my-training/')); ?>" class="ty-btn ty-btn-outline">View My Training</a>
        </div>
        
        <div class="ty-footer">
            <p>Questions? <a href="mailto:' . esc_attr(ptp_email_brand('support_email')) . '">Contact Support</a></p>
        </div>
    </div>
</body>
</html>
        <?php
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Thankyou_Handler::instance();
}, 5);
