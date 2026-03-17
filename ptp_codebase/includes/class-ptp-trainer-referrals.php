<?php
/**
 * PTP Trainer Referrals v1.0.0
 * 
 * Enables trainers to recruit other trainers with incentives
 * - Unique referral codes per trainer
 * - $50 bonus when referred trainer completes first paid session
 * - Dashboard tracking
 * - Email notifications
 */

defined('ABSPATH') || exit;

class PTP_Trainer_Referrals {
    
    private static $instance = null;
    
    const REFERRER_BONUS = 50;  // $ for existing trainer
    const REFEREE_BONUS = 25;   // $ for new trainer (first payout boost)
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Track referral code on apply page
        add_action('init', array($this, 'capture_referral_code'));
        
        // Add referral field to application
        add_action('ptp_after_application_submit', array($this, 'save_referral_to_application'), 10, 2);
        
        // When trainer is approved, link referral
        add_action('ptp_trainer_approved', array($this, 'link_referral_on_approval'));
        
        // When trainer completes first session, pay bonus
        add_action('ptp_session_completed', array($this, 'check_referral_bonus'));
        
        // AJAX endpoints
        add_action('wp_ajax_ptp_get_trainer_referral_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_ptp_send_trainer_invite', array($this, 'ajax_send_invite'));
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_trainer_referrals (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            referrer_trainer_id bigint(20) UNSIGNED NOT NULL,
            referrer_code varchar(20) NOT NULL,
            referred_email varchar(255) DEFAULT NULL,
            referred_trainer_id bigint(20) UNSIGNED DEFAULT NULL,
            referred_application_id bigint(20) UNSIGNED DEFAULT NULL,
            status enum('invited','applied','approved','completed','paid') DEFAULT 'invited',
            referrer_bonus decimal(10,2) DEFAULT 50.00,
            referee_bonus decimal(10,2) DEFAULT 25.00,
            invited_at datetime DEFAULT NULL,
            applied_at datetime DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            paid_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY referrer_trainer_id (referrer_trainer_id),
            KEY referrer_code (referrer_code),
            KEY referred_email (referred_email),
            KEY status (status)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Get or create referral code for trainer
     */
    public static function get_referral_code($trainer_id) {
        global $wpdb;
        
        // Check if trainer already has a code
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT referrer_code FROM {$wpdb->prefix}ptp_trainer_referrals 
             WHERE referrer_trainer_id = %d LIMIT 1",
            $trainer_id
        ));
        
        if ($existing) return $existing;
        
        // Generate new code based on trainer name
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT display_name FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return null;
        
        $name_part = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $trainer->display_name), 0, 4));
        $code = 'TRAIN' . $name_part . rand(100, 999);
        
        // Insert placeholder row to reserve code
        $wpdb->insert(
            $wpdb->prefix . 'ptp_trainer_referrals',
            array(
                'referrer_trainer_id' => $trainer_id,
                'referrer_code' => $code,
                'status' => 'invited',
            )
        );
        
        return $code;
    }
    
    /**
     * Capture referral code from URL
     */
    public function capture_referral_code() {
        if (isset($_GET['ref']) && !empty($_GET['ref'])) {
            $code = sanitize_text_field($_GET['ref']);
            setcookie('ptp_trainer_ref', $code, time() + (30 * 24 * 3600), '/'); // 30 days
            $_COOKIE['ptp_trainer_ref'] = $code; // Make available immediately
        }
    }
    
    /**
     * Save referral code when application is submitted
     */
    public function save_referral_to_application($application_id, $data) {
        global $wpdb;
        
        $ref_code = $_COOKIE['ptp_trainer_ref'] ?? ($_POST['referral_code'] ?? '');
        
        if (empty($ref_code)) return;
        
        // Find referrer
        $referrer = $wpdb->get_row($wpdb->prepare(
            "SELECT referrer_trainer_id FROM {$wpdb->prefix}ptp_trainer_referrals 
             WHERE referrer_code = %s LIMIT 1",
            $ref_code
        ));
        
        if (!$referrer) return;
        
        // Create referral record
        $wpdb->insert(
            $wpdb->prefix . 'ptp_trainer_referrals',
            array(
                'referrer_trainer_id' => $referrer->referrer_trainer_id,
                'referrer_code' => $ref_code,
                'referred_email' => sanitize_email($data['email']),
                'referred_application_id' => $application_id,
                'status' => 'applied',
                'applied_at' => current_time('mysql'),
            )
        );
        
        // Notify referrer
        $this->notify_referrer_application($referrer->referrer_trainer_id, $data['name']);
        
        // Clear cookie
        setcookie('ptp_trainer_ref', '', time() - 3600, '/');
    }
    
    /**
     * Link referral when trainer is approved
     */
    public function link_referral_on_approval($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return;
        
        // Find pending referral by email
        $referral = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainer_referrals 
             WHERE referred_email = %s AND status = 'applied'",
            $trainer->email
        ));
        
        if (!$referral) return;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainer_referrals',
            array(
                'referred_trainer_id' => $trainer_id,
                'status' => 'approved',
                'approved_at' => current_time('mysql'),
            ),
            array('id' => $referral->id)
        );
        
        // Notify referrer
        $this->notify_referrer_approved($referral->referrer_trainer_id, $trainer->display_name);
    }
    
    /**
     * Check and pay referral bonus when trainer completes first session
     */
    public function check_referral_bonus($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking) return;
        
        // Check if this trainer has a pending referral
        $referral = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainer_referrals 
             WHERE referred_trainer_id = %d AND status = 'approved'",
            $booking->trainer_id
        ));
        
        if (!$referral) return;
        
        // Check if this is their first completed session
        $completed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed'",
            $booking->trainer_id
        ));
        
        if ($completed_count > 1) return; // Not first session
        
        // Mark referral as completed
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainer_referrals',
            array(
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
            ),
            array('id' => $referral->id)
        );
        
        // Credit referrer
        $referrer_trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $referral->referrer_trainer_id
        ));
        
        if ($referrer_trainer) {
            // Add to pending payout
            $current_bonus = get_user_meta($referrer_trainer->user_id, 'ptp_referral_bonus_pending', true) ?: 0;
            update_user_meta($referrer_trainer->user_id, 'ptp_referral_bonus_pending', $current_bonus + self::REFERRER_BONUS);
            
            // Notify
            $this->notify_referrer_bonus($referral->referrer_trainer_id, self::REFERRER_BONUS);
        }
        
        // Credit referee (new trainer)
        $referee_trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $referral->referred_trainer_id
        ));
        
        if ($referee_trainer) {
            $current_bonus = get_user_meta($referee_trainer->user_id, 'ptp_referral_bonus_pending', true) ?: 0;
            update_user_meta($referee_trainer->user_id, 'ptp_referral_bonus_pending', $current_bonus + self::REFEREE_BONUS);
        }
    }
    
    /**
     * Send invite email
     */
    public function ajax_send_invite() {
        check_ajax_referer('ptp_trainer_referral', 'nonce');
        
        $trainer_id = $this->get_current_trainer_id();
        if (!$trainer_id) {
            wp_send_json_error('Not authorized');
        }
        
        $email = sanitize_email($_POST['email'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        
        if (!is_email($email)) {
            wp_send_json_error('Invalid email');
        }
        
        global $wpdb;
        
        // Check if already invited
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainer_referrals 
             WHERE referrer_trainer_id = %d AND referred_email = %s",
            $trainer_id, $email
        ));
        
        if ($existing) {
            wp_send_json_error('Already invited this person');
        }
        
        $ref_code = self::get_referral_code($trainer_id);
        $apply_url = home_url('/apply/?ref=' . $ref_code);
        
        // Get referrer name
        $referrer = $wpdb->get_row($wpdb->prepare(
            "SELECT display_name FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        // Record invite
        $wpdb->insert(
            $wpdb->prefix . 'ptp_trainer_referrals',
            array(
                'referrer_trainer_id' => $trainer_id,
                'referrer_code' => $ref_code,
                'referred_email' => $email,
                'status' => 'invited',
                'invited_at' => current_time('mysql'),
            )
        );
        
        // Send email
        $subject = ($referrer ? $referrer->display_name : 'A fellow athlete') . " thinks you'd be a great trainer!";
        
        $logo_url = class_exists('PTP_Images') ? PTP_Images::logo() : '';
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head><meta charset="utf-8"></head>
        <body style="margin:0;padding:0;background:#F3F4F6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">
            <div style="max-width:600px;margin:0 auto;padding:20px">
                <div style="text-align:center;padding:20px 0">
                    <img src="' . esc_url($logo_url) . '" alt="PTP Training" style="height:40px">
                </div>
                <div style="background:#fff;border-radius:12px;overflow:hidden">
                    <div style="background:#0A0A0A;padding:32px;text-align:center">
                        <h1 style="margin:0;color:#FCB900;font-size:28px">Join Our Trainer Team</h1>
                        <p style="margin:12px 0 0;color:#fff;opacity:0.9">Earn money doing what you love</p>
                    </div>
                    <div style="padding:32px">
                        <p style="margin:0 0 16px;font-size:16px;color:#374151">Hey' . ($name ? ' ' . esc_html($name) : '') . ',</p>
                        <p style="margin:0 0 16px;color:#374151">' . esc_html($referrer->display_name) . ' invited you to join PTP Training as a trainer!</p>
                        <p style="margin:0 0 24px;color:#374151">As a trainer, you\'ll:</p>
                        <ul style="margin:0 0 24px;padding-left:20px;color:#374151">
                            <li style="margin-bottom:8px">Set your own rates ($50-150+/hour)</li>
                            <li style="margin-bottom:8px">Choose your own schedule</li>
                            <li style="margin-bottom:8px">Train athletes in your area</li>
                            <li style="margin-bottom:8px">Get paid weekly via direct deposit</li>
                        </ul>
                        <div style="background:#FEF3C7;border-radius:8px;padding:16px;margin-bottom:24px;text-align:center">
                            <p style="margin:0;font-weight:600;color:#92400E">🎁 Bonus: Get an extra $' . self::REFEREE_BONUS . ' added to your first payout!</p>
                        </div>
                        <div style="text-align:center">
                            <a href="' . esc_url($apply_url) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px">Apply Now</a>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_training'),
        );
        
        wp_mail($email, $subject, $body, $headers);
        
        wp_send_json_success(array('message' => 'Invite sent!'));
    }
    
    /**
     * Get referral stats for trainer dashboard
     */
    public function ajax_get_stats() {
        $trainer_id = $this->get_current_trainer_id();
        if (!$trainer_id) {
            wp_send_json_error('Not authorized');
        }
        
        global $wpdb;
        
        $ref_code = self::get_referral_code($trainer_id);
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(CASE WHEN status = 'invited' THEN 1 END) as invited,
                COUNT(CASE WHEN status = 'applied' THEN 1 END) as applied,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                COUNT(CASE WHEN status IN ('completed', 'paid') THEN 1 END) as completed,
                SUM(CASE WHEN status IN ('completed', 'paid') THEN referrer_bonus ELSE 0 END) as total_earned
             FROM {$wpdb->prefix}ptp_trainer_referrals 
             WHERE referrer_trainer_id = %d AND id != (
                SELECT MIN(id) FROM {$wpdb->prefix}ptp_trainer_referrals WHERE referrer_trainer_id = %d
             )",
            $trainer_id, $trainer_id
        ));
        
        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.*, t.display_name as referred_name, t.photo_url
             FROM {$wpdb->prefix}ptp_trainer_referrals tr
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON tr.referred_trainer_id = t.id
             WHERE tr.referrer_trainer_id = %d AND tr.referred_email IS NOT NULL
             ORDER BY tr.created_at DESC
             LIMIT 10",
            $trainer_id
        ));
        
        wp_send_json_success(array(
            'code' => $ref_code,
            'link' => home_url('/apply/?ref=' . $ref_code),
            'stats' => $stats,
            'recent' => $recent,
            'bonus_amount' => self::REFERRER_BONUS,
        ));
    }
    
    /**
     * Get current trainer ID
     */
    private function get_current_trainer_id() {
        if (!is_user_logged_in()) return null;
        
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d AND status = 'active'",
            get_current_user_id()
        ));
    }
    
    /**
     * Notification helpers
     */
    private function notify_referrer_application($trainer_id, $applicant_name) {
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return;
        
        $subject = "🎉 Your referral {$applicant_name} just applied!";
        $body = "Great news! {$applicant_name} used your referral link to apply as a trainer. You'll earn $" . self::REFERRER_BONUS . " when they complete their first paid session!";
        
        wp_mail($trainer->user_email, $subject, $body);
    }
    
    private function notify_referrer_approved($trainer_id, $new_trainer_name) {
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return;
        
        $subject = "✅ {$new_trainer_name} was approved as a trainer!";
        $body = "{$new_trainer_name} is now an approved trainer! You'll earn $" . self::REFERRER_BONUS . " when they complete their first paid session.";
        
        wp_mail($trainer->user_email, $subject, $body);
    }
    
    private function notify_referrer_bonus($trainer_id, $amount) {
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return;
        
        $subject = "💰 You earned a \${$amount} referral bonus!";
        $body = "Congrats! Your referred trainer just completed their first session. \${$amount} has been added to your next payout!";
        
        wp_mail($trainer->user_email, $subject, $body);
    }
    
    /**
     * Render referral widget for trainer dashboard
     */
    public static function render_dashboard_widget() {
        $nonce = wp_create_nonce('ptp_trainer_referral');
        ?>
        <div class="ptp-referral-widget" id="trainer-referral-widget">
            <div class="ptp-referral-header">
                <h3>🎁 Invite Trainers, Earn $<?php echo self::REFERRER_BONUS; ?></h3>
                <p>Know great athletes who'd make awesome trainers? Invite them and earn $<?php echo self::REFERRER_BONUS; ?> when they complete their first session!</p>
            </div>
            
            <div class="ptp-referral-code-box">
                <label>Your Referral Link</label>
                <div class="ptp-referral-code-row">
                    <input type="text" id="trainer-ref-link" readonly value="Loading...">
                    <button type="button" onclick="copyRefLink()">Copy</button>
                </div>
            </div>
            
            <div class="ptp-referral-invite-form">
                <h4>Send a Direct Invite</h4>
                <div class="ptp-referral-form-row">
                    <input type="text" id="invite-name" placeholder="Friend's name">
                    <input type="email" id="invite-email" placeholder="Friend's email">
                    <button type="button" onclick="sendTrainerInvite()">Send Invite</button>
                </div>
            </div>
            
            <div class="ptp-referral-stats">
                <div class="ptp-referral-stat">
                    <span class="stat-value" id="ref-stat-invited">-</span>
                    <span class="stat-label">Invited</span>
                </div>
                <div class="ptp-referral-stat">
                    <span class="stat-value" id="ref-stat-applied">-</span>
                    <span class="stat-label">Applied</span>
                </div>
                <div class="ptp-referral-stat">
                    <span class="stat-value" id="ref-stat-approved">-</span>
                    <span class="stat-label">Approved</span>
                </div>
                <div class="ptp-referral-stat highlight">
                    <span class="stat-value" id="ref-stat-earned">$0</span>
                    <span class="stat-label">Earned</span>
                </div>
            </div>
        </div>
        
        <style>
        .ptp-referral-widget {
            background: linear-gradient(135deg, #0A0A0A 0%, #1F2937 100%);
            border-radius: 12px;
            padding: 24px;
            color: #fff;
            margin-bottom: 24px;
        }
        .ptp-referral-header h3 {
            margin: 0 0 8px;
            font-size: 20px;
        }
        .ptp-referral-header p {
            margin: 0;
            opacity: 0.8;
            font-size: 14px;
        }
        .ptp-referral-code-box {
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 16px;
            margin: 20px 0;
        }
        .ptp-referral-code-box label {
            display: block;
            font-size: 12px;
            opacity: 0.7;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .ptp-referral-code-row {
            display: flex;
            gap: 8px;
        }
        .ptp-referral-code-row input {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            color: #111;
        }
        .ptp-referral-code-row button {
            padding: 12px 20px;
            background: #FCB900;
            color: #0A0A0A;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
        }
        .ptp-referral-invite-form {
            margin: 20px 0;
        }
        .ptp-referral-invite-form h4 {
            margin: 0 0 12px;
            font-size: 14px;
            opacity: 0.9;
        }
        .ptp-referral-form-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .ptp-referral-form-row input {
            flex: 1;
            min-width: 150px;
            padding: 10px 12px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
        }
        .ptp-referral-form-row button {
            padding: 10px 16px;
            background: #10B981;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }
        .ptp-referral-stats {
            display: flex;
            gap: 16px;
            margin-top: 20px;
        }
        .ptp-referral-stat {
            flex: 1;
            text-align: center;
            background: rgba(255,255,255,0.1);
            padding: 12px;
            border-radius: 8px;
        }
        .ptp-referral-stat.highlight {
            background: rgba(252,185,0,0.2);
        }
        .ptp-referral-stat .stat-value {
            display: block;
            font-size: 24px;
            font-weight: 700;
        }
        .ptp-referral-stat .stat-label {
            font-size: 11px;
            opacity: 0.7;
            text-transform: uppercase;
        }
        @media (max-width: 600px) {
            .ptp-referral-stats { flex-wrap: wrap; }
            .ptp-referral-stat { flex: 0 0 calc(50% - 8px); }
        }
        </style>
        
        <script>
        (function() {
            var nonce = '<?php echo $nonce; ?>';
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            
            // Load stats
            fetch(ajaxUrl + '?action=ptp_get_trainer_referral_stats&nonce=' + nonce)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('trainer-ref-link').value = data.data.link;
                        document.getElementById('ref-stat-invited').textContent = data.data.stats?.invited || 0;
                        document.getElementById('ref-stat-applied').textContent = data.data.stats?.applied || 0;
                        document.getElementById('ref-stat-approved').textContent = data.data.stats?.approved || 0;
                        document.getElementById('ref-stat-earned').textContent = '$' + (data.data.stats?.total_earned || 0);
                    }
                });
            
            window.copyRefLink = function() {
                var input = document.getElementById('trainer-ref-link');
                input.select();
                document.execCommand('copy');
                
                var btn = input.nextElementSibling;
                btn.textContent = 'Copied!';
                setTimeout(() => btn.textContent = 'Copy', 2000);
            };
            
            window.sendTrainerInvite = function() {
                var name = document.getElementById('invite-name').value;
                var email = document.getElementById('invite-email').value;
                
                if (!email) {
                    alert('Please enter an email');
                    return;
                }
                
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=ptp_send_trainer_invite&nonce=' + nonce + '&name=' + encodeURIComponent(name) + '&email=' + encodeURIComponent(email)
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Invite sent to ' + email + '!');
                        document.getElementById('invite-name').value = '';
                        document.getElementById('invite-email').value = '';
                    } else {
                        alert(data.data || 'Error sending invite');
                    }
                });
            };
        })();
        </script>
        <?php
    }
}

// Initialize
PTP_Trainer_Referrals::instance();
