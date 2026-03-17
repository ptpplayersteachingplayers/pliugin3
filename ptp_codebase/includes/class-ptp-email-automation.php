<?php
/**
 * PTP Email Automation System v1.0.0
 * 
 * Handles:
 * - Abandoned checkout recovery
 * - Post-session follow-up sequences
 * - Review request emails
 * - Referral program emails
 * - Re-engagement campaigns
 */

defined('ABSPATH') || exit;

class PTP_Email_Automation {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Track checkout starts
        add_action('wp_ajax_ptp_track_checkout_start', array($this, 'track_checkout_start'));
        add_action('wp_ajax_nopriv_ptp_track_checkout_start', array($this, 'track_checkout_start'));
        
        // Cron jobs for automated emails
        add_action('ptp_send_abandoned_cart_emails', array($this, 'send_abandoned_cart_emails'));
        add_action('ptp_send_post_session_emails', array($this, 'send_post_session_emails'));
        add_action('ptp_send_review_request_emails', array($this, 'send_review_request_emails'));
        add_action('ptp_send_reengagement_emails', array($this, 'send_reengagement_emails'));
        
        // Schedule cron if not scheduled
        if (!wp_next_scheduled('ptp_send_abandoned_cart_emails')) {
            wp_schedule_event(time(), 'hourly', 'ptp_send_abandoned_cart_emails');
        }
        if (!wp_next_scheduled('ptp_send_post_session_emails')) {
            wp_schedule_event(time(), 'twicedaily', 'ptp_send_post_session_emails');
        }
        if (!wp_next_scheduled('ptp_send_review_request_emails')) {
            wp_schedule_event(time(), 'daily', 'ptp_send_review_request_emails');
        }
        if (!wp_next_scheduled('ptp_send_reengagement_emails')) {
            wp_schedule_event(time(), 'daily', 'ptp_send_reengagement_emails');
        }
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_abandoned_carts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            email varchar(255) NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            session_date date DEFAULT NULL,
            session_time varchar(20) DEFAULT NULL,
            package_type varchar(20) DEFAULT 'single',
            cart_data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            reminder_1_sent datetime DEFAULT NULL,
            reminder_2_sent datetime DEFAULT NULL,
            reminder_3_sent datetime DEFAULT NULL,
            recovered tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY email (email),
            KEY created_at (created_at)
        ) $charset;
        
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_email_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            email varchar(255) NOT NULL,
            email_type varchar(50) NOT NULL,
            subject varchar(255) NOT NULL,
            sent_at datetime DEFAULT CURRENT_TIMESTAMP,
            opened_at datetime DEFAULT NULL,
            clicked_at datetime DEFAULT NULL,
            metadata longtext,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY email_type (email_type),
            KEY sent_at (sent_at)
        ) $charset;
        
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_referrals (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            referrer_id bigint(20) UNSIGNED NOT NULL,
            referrer_code varchar(20) NOT NULL,
            referred_email varchar(255) DEFAULT NULL,
            referred_user_id bigint(20) UNSIGNED DEFAULT NULL,
            status enum('pending','completed','paid') DEFAULT 'pending',
            referrer_credit decimal(10,2) DEFAULT 20.00,
            referred_discount decimal(10,2) DEFAULT 20.00,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY referrer_code (referrer_code),
            KEY referrer_id (referrer_id),
            KEY status (status)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Track when someone starts checkout but doesn't complete
     */
    public function track_checkout_start() {
        global $wpdb;
        
        $email = sanitize_email($_POST['email'] ?? '');
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $session_date = sanitize_text_field($_POST['session_date'] ?? '');
        $session_time = sanitize_text_field($_POST['session_time'] ?? '');
        $package_type = sanitize_text_field($_POST['package_type'] ?? 'single');
        
        if (empty($email) || empty($trainer_id)) {
            wp_send_json_error('Missing data');
        }
        
        $user_id = get_current_user_id() ?: null;
        
        // Check if we already have this cart
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_abandoned_carts 
             WHERE email = %s AND trainer_id = %d AND recovered = 0 
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $email, $trainer_id
        ));
        
        if ($existing) {
            // Update existing
            $wpdb->update(
                $wpdb->prefix . 'ptp_abandoned_carts',
                array(
                    'session_date' => $session_date,
                    'session_time' => $session_time,
                    'package_type' => $package_type,
                    'cart_data' => json_encode($_POST),
                ),
                array('id' => $existing)
            );
        } else {
            // Insert new
            $wpdb->insert(
                $wpdb->prefix . 'ptp_abandoned_carts',
                array(
                    'user_id' => $user_id,
                    'email' => $email,
                    'trainer_id' => $trainer_id,
                    'session_date' => $session_date,
                    'session_time' => $session_time,
                    'package_type' => $package_type,
                    'cart_data' => json_encode($_POST),
                )
            );
        }
        
        wp_send_json_success();
    }
    
    /**
     * Mark cart as recovered when booking completes
     */
    public static function mark_cart_recovered($email, $trainer_id) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_abandoned_carts',
            array('recovered' => 1),
            array('email' => $email, 'trainer_id' => $trainer_id, 'recovered' => 0)
        );
    }
    
    /**
     * Send abandoned cart recovery emails
     * v222.1: DEPRECATED - Consolidated into PTP_Abandoned_Cart_Recovery::process_all_abandoned_carts()
     * which handles both camp and training carts with SMS + email deduplication.
     * Both were hooked to ptp_send_abandoned_cart_emails, causing duplicate emails.
     */
    public function send_abandoned_cart_emails() {
        return; // Handled by PTP_Abandoned_Cart_Recovery
    }
    
    /**
     * Abandoned cart email 1 - Gentle reminder
     */
    private function send_abandoned_email_1($cart) {
        $trainer = $this->get_trainer($cart->trainer_id);
        if (!$trainer) return;
        
        $checkout_url = $this->get_checkout_url($cart);
        $first_name = $this->get_first_name($cart->email);
        
        $subject = "Still thinking about training with {$trainer->display_name}?";
        
        $body = $this->get_email_template('abandoned_1', array(
            'first_name' => $first_name,
            'trainer_name' => $trainer->display_name,
            'trainer_photo' => $trainer->photo_url,
            'checkout_url' => $checkout_url,
            'session_date' => $cart->session_date,
            'session_time' => $cart->session_time,
        ));
        
        $this->send_email($cart->email, $subject, $body, 'abandoned_cart_1', $cart->user_id);
    }
    
    /**
     * Abandoned cart email 2 - Urgency
     */
    private function send_abandoned_email_2($cart) {
        $trainer = $this->get_trainer($cart->trainer_id);
        if (!$trainer) return;
        
        $checkout_url = $this->get_checkout_url($cart);
        $first_name = $this->get_first_name($cart->email);
        
        $subject = "⚡ {$trainer->display_name}'s schedule is filling up";
        
        $body = $this->get_email_template('abandoned_2', array(
            'first_name' => $first_name,
            'trainer_name' => $trainer->display_name,
            'trainer_photo' => $trainer->photo_url,
            'checkout_url' => $checkout_url,
        ));
        
        $this->send_email($cart->email, $subject, $body, 'abandoned_cart_2', $cart->user_id);
    }
    
    /**
     * Abandoned cart email 3 - Discount offer
     */
    private function send_abandoned_email_3($cart) {
        $trainer = $this->get_trainer($cart->trainer_id);
        if (!$trainer) return;
        
        // Create a discount code
        $discount_code = $this->create_discount_code($cart->email, 10);
        $checkout_url = $this->get_checkout_url($cart) . '&discount=' . $discount_code;
        $first_name = $this->get_first_name($cart->email);
        
        $subject = "🎁 $10 off your first session with {$trainer->display_name}";
        
        $body = $this->get_email_template('abandoned_3', array(
            'first_name' => $first_name,
            'trainer_name' => $trainer->display_name,
            'trainer_photo' => $trainer->photo_url,
            'checkout_url' => $checkout_url,
            'discount_code' => $discount_code,
            'discount_amount' => 10,
        ));
        
        $this->send_email($cart->email, $subject, $body, 'abandoned_cart_3', $cart->user_id);
    }
    
    /**
     * Send post-session follow-up emails
     */
    public function send_post_session_emails() {
        global $wpdb;
        
        // Get sessions completed yesterday
        $sessions = $wpdb->get_results(
            "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug,
                    u.user_email, u.display_name as parent_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             JOIN {$wpdb->users} u ON pa.user_id = u.ID
             WHERE b.status = 'completed'
             AND b.session_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
             AND b.id NOT IN (
                 SELECT CAST(JSON_EXTRACT(metadata, '$.booking_id') AS UNSIGNED) 
                 FROM {$wpdb->prefix}ptp_email_logs 
                 WHERE email_type = 'post_session'
             )"
        );
        
        foreach ($sessions as $session) {
            $this->send_post_session_email($session);
        }
    }
    
    /**
     * Post-session email with review request and rebooking CTA
     */
    private function send_post_session_email($session) {
        $first_name = explode(' ', $session->parent_name)[0];
        $trainer_first = explode(' ', $session->trainer_name)[0];
        
        $review_url = home_url("/trainer/{$session->trainer_slug}/?review=1#reviews");
        $rebook_url = home_url("/trainer/{$session->trainer_slug}/");
        
        $subject = "How was training with {$trainer_first}? 🌟";
        
        $body = $this->get_email_template('post_session', array(
            'first_name' => $first_name,
            'trainer_name' => $session->trainer_name,
            'trainer_first' => $trainer_first,
            'trainer_photo' => $session->trainer_photo,
            'review_url' => $review_url,
            'rebook_url' => $rebook_url,
            'session_date' => date('l, F j', strtotime($session->session_date)),
        ));
        
        $this->send_email(
            $session->user_email, 
            $subject, 
            $body, 
            'post_session', 
            $session->user_id,
            array('booking_id' => $session->id)
        );
    }
    
    /**
     * Send review request emails (7 days after session if no review)
     */
    public function send_review_request_emails() {
        global $wpdb;
        
        // v222.1: Configurable delay (default 3 days, was 7)
        $delay_days = apply_filters('ptp_review_request_delay_days', 3);
        
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.slug as trainer_slug,
                    u.user_email, u.display_name as parent_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             JOIN {$wpdb->users} u ON pa.user_id = u.ID
             LEFT JOIN {$wpdb->prefix}ptp_reviews r ON r.booking_id = b.id
             WHERE b.status = 'completed'
             AND b.session_date = DATE_SUB(CURDATE(), INTERVAL %d DAY)
             AND r.id IS NULL
             AND b.id NOT IN (
                 SELECT CAST(JSON_EXTRACT(metadata, '$.booking_id') AS UNSIGNED) 
                 FROM {$wpdb->prefix}ptp_email_logs 
                 WHERE email_type = 'review_request'
             )",
            $delay_days
        ));
        
        foreach ($sessions as $session) {
            $first_name = explode(' ', $session->parent_name)[0];
            $review_url = home_url("/trainer/{$session->trainer_slug}/?review=1#reviews");
            
            $subject = "Quick favor? Share your experience with {$session->trainer_name}";
            
            $body = $this->get_email_template('review_request', array(
                'first_name' => $first_name,
                'trainer_name' => $session->trainer_name,
                'review_url' => $review_url,
            ));
            
            $this->send_email(
                $session->user_email, 
                $subject, 
                $body, 
                'review_request', 
                $session->user_id,
                array('booking_id' => $session->id)
            );
        }
    }
    
    /**
     * Send re-engagement emails to inactive users
     */
    public function send_reengagement_emails() {
        global $wpdb;
        
        // Users who booked 30 days ago but haven't booked since
        $users = $wpdb->get_results(
            "SELECT u.ID, u.user_email, u.display_name,
                    MAX(b.session_date) as last_session,
                    t.display_name as trainer_name, t.slug as trainer_slug
             FROM {$wpdb->users} u
             JOIN {$wpdb->prefix}ptp_parents pa ON pa.user_id = u.ID
             JOIN {$wpdb->prefix}ptp_bookings b ON b.parent_id = pa.id
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.status = 'completed'
             GROUP BY u.ID
             HAVING last_session = DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             AND u.ID NOT IN (
                 SELECT user_id FROM {$wpdb->prefix}ptp_email_logs 
                 WHERE email_type = 'reengagement' 
                 AND sent_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             )"
        );
        
        foreach ($users as $user) {
            $first_name = explode(' ', $user->display_name)[0];
            $trainer_url = home_url("/trainer/{$user->trainer_slug}/");
            $browse_url = home_url('/find-trainers/');
            
            $subject = "We miss you! Book your next training session 💪";
            
            $body = $this->get_email_template('reengagement', array(
                'first_name' => $first_name,
                'trainer_name' => $user->trainer_name,
                'trainer_url' => $trainer_url,
                'browse_url' => $browse_url,
                'days_since' => 30,
            ));
            
            $this->send_email($user->user_email, $subject, $body, 'reengagement', $user->ID);
        }
    }
    
    /**
     * Get email template
     */
    private function get_email_template($template, $vars) {
        $logo_url = class_exists('PTP_Images') ? PTP_Images::logo() : '';
        
        $header = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin:0;padding:0;background:#F3F4F6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">
            <div style="max-width:600px;margin:0 auto;padding:20px">
                <div style="text-align:center;padding:20px 0">
                    <img src="' . esc_url($logo_url) . '" alt="' . esc_attr(ptp_email_brand('company')) . '" style="height:40px;width:auto">
                </div>
                <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">';
        
        $footer = '
                </div>
                <div style="text-align:center;padding:24px;color:#6B7280;font-size:13px">
                    <p style="margin:0 0 8px">' . esc_html(ptp_email_brand('company')) . ' - ' . esc_html(ptp_email_brand('tagline')) . '</p>
                    <p style="margin:0">
                        <a href="' . home_url() . '" style="color:#6B7280">Website</a> · 
                        <a href="mailto:' . esc_attr(ptp_email_brand('support_email')) . '" style="color:#6B7280">Support</a>
                    </p>
                    <p style="margin:16px 0 0;font-size:11px">
                        <a href="{unsubscribe_url}" style="color:#9CA3AF">Unsubscribe</a>
                    </p>
                </div>
            </div>
        </body>
        </html>';
        
        $content = '';
        
        switch ($template) {
            case 'abandoned_1':
                $content = '
                    <div style="padding:32px 24px;text-align:center">
                        <img src="' . esc_url($vars['trainer_photo']) . '" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #FCB900;margin-bottom:16px">
                        <h1 style="margin:0 0 8px;font-size:24px;color:#111">Hi ' . esc_html($vars['first_name']) . '!</h1>
                        <p style="margin:0 0 24px;color:#6B7280;font-size:16px">You were so close to booking a session with <strong>' . esc_html($vars['trainer_name']) . '</strong>.</p>
                        <p style="margin:0 0 24px;color:#6B7280">Complete your booking now to secure your spot!</p>
                        <a href="' . esc_url($vars['checkout_url']) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px">Complete Booking</a>
                    </div>';
                break;
                
            case 'abandoned_2':
                $content = '
                    <div style="background:#0A0A0A;padding:24px;text-align:center">
                        <p style="margin:0;color:#FCB900;font-size:14px;font-weight:600">⚡ SPOTS FILLING UP</p>
                    </div>
                    <div style="padding:32px 24px;text-align:center">
                        <h1 style="margin:0 0 16px;font-size:24px;color:#111">' . esc_html($vars['trainer_name']) . '\'s schedule is getting busy</h1>
                        <p style="margin:0 0 24px;color:#6B7280;font-size:16px">Other parents are booking sessions. Don\'t miss out on your preferred time!</p>
                        <a href="' . esc_url($vars['checkout_url']) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px">Book Now</a>
                    </div>';
                break;
                
            case 'abandoned_3':
                $content = '
                    <div style="background:linear-gradient(135deg,#FCB900,#F59E0B);padding:24px;text-align:center">
                        <p style="margin:0;color:#0A0A0A;font-size:20px;font-weight:700">🎁 Special Offer: $' . intval($vars['discount_amount']) . ' OFF</p>
                    </div>
                    <div style="padding:32px 24px;text-align:center">
                        <h1 style="margin:0 0 16px;font-size:24px;color:#111">We want you to experience great training!</h1>
                        <p style="margin:0 0 16px;color:#6B7280;font-size:16px">Use code <strong style="background:#FEF3C7;padding:4px 8px;border-radius:4px">' . esc_html($vars['discount_code']) . '</strong> at checkout.</p>
                        <p style="margin:0 0 24px;color:#6B7280;font-size:14px">Expires in 48 hours</p>
                        <a href="' . esc_url($vars['checkout_url']) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px">Claim $' . intval($vars['discount_amount']) . ' Off</a>
                    </div>';
                break;
                
            case 'post_session':
                $content = '
                    <div style="padding:32px 24px;text-align:center">
                        <h1 style="margin:0 0 8px;font-size:24px;color:#111">How was training? 🌟</h1>
                        <p style="margin:0 0 24px;color:#6B7280;font-size:16px">We hope ' . esc_html($vars['first_name']) . ' had a great session with ' . esc_html($vars['trainer_first']) . ' on ' . esc_html($vars['session_date']) . '!</p>
                        <div style="background:#F9FAFB;border-radius:8px;padding:20px;margin-bottom:24px">
                            <p style="margin:0 0 12px;color:#111;font-weight:600">Help other parents find great trainers</p>
                            <a href="' . esc_url($vars['review_url']) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700">Leave a Review</a>
                        </div>
                        <div style="border-top:1px solid #E5E7EB;padding-top:24px">
                            <p style="margin:0 0 12px;color:#111;font-weight:600">Ready for more training?</p>
                            <a href="' . esc_url($vars['rebook_url']) . '" style="display:inline-block;border:2px solid #0A0A0A;color:#0A0A0A;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700">Book Another Session</a>
                        </div>
                    </div>';
                break;
                
            case 'review_request':
                $content = '
                    <div style="padding:32px 24px;text-align:center">
                        <h1 style="margin:0 0 16px;font-size:24px;color:#111">Quick favor, ' . esc_html($vars['first_name']) . '?</h1>
                        <p style="margin:0 0 24px;color:#6B7280;font-size:16px">Your review helps other parents find great trainers like ' . esc_html($vars['trainer_name']) . '. It only takes 30 seconds!</p>
                        <div style="display:inline-block;margin-bottom:24px">
                            <span style="font-size:32px">⭐⭐⭐⭐⭐</span>
                        </div>
                        <br>
                        <a href="' . esc_url($vars['review_url']) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px">Write a Quick Review</a>
                    </div>';
                break;
                
            case 'reengagement':
                $content = '
                    <div style="padding:32px 24px;text-align:center">
                        <h1 style="margin:0 0 16px;font-size:24px;color:#111">We miss you, ' . esc_html($vars['first_name']) . '! 💪</h1>
                        <p style="margin:0 0 24px;color:#6B7280;font-size:16px">It\'s been ' . intval($vars['days_since']) . ' days since your last training session. Ready to get back on the field?</p>
                        <a href="' . esc_url($vars['trainer_url']) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px;margin-bottom:12px">Book with ' . esc_html($vars['trainer_name']) . '</a>
                        <br><br>
                        <a href="' . esc_url($vars['browse_url']) . '" style="color:#6B7280;font-size:14px">or browse all trainers →</a>
                    </div>';
                break;
                
            case 'referral_invite':
                $content = '
                    <div style="background:linear-gradient(135deg,#0A0A0A,#1F2937);padding:32px 24px;text-align:center">
                        <h1 style="margin:0 0 8px;font-size:28px;color:#fff">Give $20, Get $20</h1>
                        <p style="margin:0;color:#9CA3AF;font-size:16px">Share the love of great training!</p>
                    </div>
                    <div style="padding:32px 24px;text-align:center">
                        <p style="margin:0 0 16px;color:#6B7280;font-size:16px">Hi ' . esc_html($vars['first_name']) . '! Share your referral code with friends:</p>
                        <div style="background:#FEF3C7;border:2px dashed #FCB900;border-radius:8px;padding:16px;margin-bottom:24px">
                            <p style="margin:0;font-size:24px;font-weight:700;color:#0A0A0A;letter-spacing:2px">' . esc_html($vars['referral_code']) . '</p>
                        </div>
                        <p style="margin:0 0 8px;color:#111;font-weight:600">How it works:</p>
                        <p style="margin:0 0 24px;color:#6B7280;font-size:14px">Your friend gets $20 off their first booking.<br>You get $20 credit when they complete a session!</p>
                        <a href="' . esc_url($vars['share_url']) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px">Share Now</a>
                    </div>';
                break;
        }
        
        return $header . $content . $footer;
    }
    
    /**
     * Send email and log it
     */
    private function send_email($to, $subject, $body, $type, $user_id = null, $metadata = array()) {
        global $wpdb;
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_training'),
        );
        
        $sent = wp_mail($to, $subject, $body, $headers);
        
        if ($sent) {
            $wpdb->insert(
                $wpdb->prefix . 'ptp_email_logs',
                array(
                    'user_id' => $user_id,
                    'email' => $to,
                    'email_type' => $type,
                    'subject' => $subject,
                    'metadata' => json_encode($metadata),
                )
            );
        }
        
        return $sent;
    }
    
    /**
     * Helper functions
     */
    private function get_trainer($trainer_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
    }
    
    private function get_checkout_url($cart) {
        return home_url('/ptp-checkout/') . '?' . http_build_query(array(
            'trainer_id' => $cart->trainer_id,
            'date' => $cart->session_date,
            'time' => $cart->session_time,
            'package' => $cart->package_type,
            'recover' => $cart->id,
        ));
    }
    
    private function get_first_name($email) {
        $user = get_user_by('email', $email);
        if ($user && $user->first_name) {
            return $user->first_name;
        }
        return explode('@', $email)[0];
    }
    
    private function create_discount_code($email, $amount) {
        $code = 'SAVE' . strtoupper(substr(md5($email . time()), 0, 6));
        update_option('ptp_discount_' . $code, array(
            'amount' => $amount,
            'email' => $email,
            'expires' => time() + (48 * 3600),
            'used' => false,
        ));
        return $code;
    }
    
    /**
     * Referral system
     */
    public static function get_referral_code($user_id) {
        global $wpdb;
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT referrer_code FROM {$wpdb->prefix}ptp_referrals WHERE referrer_id = %d LIMIT 1",
            $user_id
        ));
        
        if ($existing) return $existing;
        
        // Generate new code
        $user = get_user_by('ID', $user_id);
        $name = $user ? strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $user->first_name ?: $user->display_name), 0, 4)) : 'PTP';
        $code = $name . rand(1000, 9999);
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_referrals',
            array(
                'referrer_id' => $user_id,
                'referrer_code' => $code,
            )
        );
        
        return $code;
    }
    
    public static function apply_referral_code($code, $referred_user_id) {
        global $wpdb;
        
        $referral = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_referrals WHERE referrer_code = %s AND referred_user_id IS NULL",
            $code
        ));
        
        if (!$referral) return false;
        
        // Don't let users refer themselves
        if ($referral->referrer_id == $referred_user_id) return false;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_referrals',
            array('referred_user_id' => $referred_user_id),
            array('id' => $referral->id)
        );
        
        return $referral->referred_discount;
    }
    
    public static function complete_referral($referred_user_id) {
        global $wpdb;
        
        $referral = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_referrals WHERE referred_user_id = %d AND status = 'pending'",
            $referred_user_id
        ));
        
        if (!$referral) return;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_referrals',
            array(
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
            ),
            array('id' => $referral->id)
        );
        
        // Credit the referrer
        $current_credit = get_user_meta($referral->referrer_id, 'ptp_account_credit', true) ?: 0;
        update_user_meta($referral->referrer_id, 'ptp_account_credit', $current_credit + $referral->referrer_credit);
        
        // Send email to referrer
        $referrer = get_user_by('ID', $referral->referrer_id);
        if ($referrer) {
            wp_mail(
                $referrer->user_email,
                "You earned $" . intval($referral->referrer_credit) . " credit! 🎉",
                "Your friend completed their first session. You now have $" . ($current_credit + $referral->referrer_credit) . " in account credit!",
                array('Content-Type: text/html; charset=UTF-8')
            );
        }
    }
}

// Initialize
PTP_Email_Automation::instance();
