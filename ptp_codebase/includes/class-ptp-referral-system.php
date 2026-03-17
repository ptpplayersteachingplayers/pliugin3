<?php
/**
 * PTP Referral System v88
 * 
 * Complete referral program for parents and trainers
 * 
 * Features:
 * - Unique referral codes for each user
 * - Track referrals and conversions
 * - Credit rewards for successful referrals
 * - Referral dashboard widget
 * - Social sharing integration
 * - Admin reporting
 */

defined('ABSPATH') || exit;

class PTP_Referral_System {
    
    private static $instance = null;
    
    // Reward amounts (in dollars)
    const PARENT_REWARD = 25;      // Parent gets $25 credit
    const REFERRED_REWARD = 15;    // New family gets $15 off first booking
    const TRAINER_REWARD = 50;     // Trainer gets $50 for referring new trainer
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Create tables on activation
        add_action('init', array($this, 'maybe_create_tables'));
        
        // Track referral codes in URL
        add_action('init', array($this, 'track_referral_code'));
        
        // Apply referral discount at checkout
        // REMOVED: add_action('ptp-native_cart_calculate_fees', array($this, 'apply_referral_discount'));
        
        // Award credits after purchase
        // REMOVED: add_action('ptp-native_order_status_completed', array($this, 'process_referral_reward'));
        add_action('ptp_booking_completed', array($this, 'process_training_referral'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_get_referral_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_ptp_generate_referral_link', array($this, 'ajax_generate_link'));
        add_action('wp_ajax_ptp_share_referral', array($this, 'ajax_share_referral'));
        
        // Shortcodes
        add_shortcode('ptp_referral_widget', array($this, 'render_referral_widget'));
        add_shortcode('ptp_referral_leaderboard', array($this, 'render_leaderboard'));
        
        // Admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    /**
     * Create database tables
     */
    public function maybe_create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ptp_referrals';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                referrer_id bigint(20) UNSIGNED NOT NULL,
                referrer_type enum('parent','trainer') NOT NULL DEFAULT 'parent',
                referred_id bigint(20) UNSIGNED DEFAULT NULL,
                referred_email varchar(255) DEFAULT NULL,
                referral_code varchar(32) NOT NULL,
                status enum('pending','converted','expired','credited') NOT NULL DEFAULT 'pending',
                reward_amount decimal(10,2) DEFAULT 0,
                reward_type varchar(50) DEFAULT NULL,
                conversion_order_id bigint(20) UNSIGNED DEFAULT NULL,
                conversion_booking_id bigint(20) UNSIGNED DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                converted_at datetime DEFAULT NULL,
                credited_at datetime DEFAULT NULL,
                ip_address varchar(45) DEFAULT NULL,
                utm_source varchar(100) DEFAULT NULL,
                utm_medium varchar(100) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY referrer_id (referrer_id),
                KEY referred_id (referred_id),
                KEY referral_code (referral_code),
                KEY status (status)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            // Also create credits table if not exists
            $credits_table = $wpdb->prefix . 'ptp_referral_credits';
            
            $sql2 = "CREATE TABLE $credits_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id bigint(20) UNSIGNED NOT NULL,
                user_type enum('parent','trainer') NOT NULL DEFAULT 'parent',
                amount decimal(10,2) NOT NULL,
                balance decimal(10,2) NOT NULL,
                type enum('earned','used','expired','adjusted') NOT NULL,
                referral_id bigint(20) UNSIGNED DEFAULT NULL,
                order_id bigint(20) UNSIGNED DEFAULT NULL,
                booking_id bigint(20) UNSIGNED DEFAULT NULL,
                description varchar(255) DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY type (type)
            ) $charset_collate;";
            
            dbDelta($sql2);
        }
    }
    
    /**
     * Generate unique referral code for user
     */
    public static function generate_code($user_id, $type = 'parent') {
        global $wpdb;
        
        // Validate user_id
        if (empty($user_id)) {
            return '';
        }
        
        // Check if user already has a code
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT referral_code FROM {$wpdb->prefix}ptp_referrals 
             WHERE referrer_id = %d AND referrer_type = %s 
             LIMIT 1",
            $user_id, $type
        ));
        
        if ($existing) {
            return $existing;
        }
        
        // Generate new code
        $user = get_user_by('ID', $user_id);
        
        // v128.2.8: Handle case where user doesn't exist
        if (!$user) {
            // Generate code without name prefix
            $name_part = 'PTP';
        } else {
            $name_part = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $user->display_name), 0, 4));
        }
        
        // Ensure we have at least something for name_part
        if (empty($name_part)) {
            $name_part = 'REF';
        }
        $random = strtoupper(wp_generate_password(4, false, false));
        $code = $name_part . $random;
        
        // Ensure uniqueness — v223.1: Added max iteration guard to prevent infinite loop
        $max_attempts = 100;
        $attempt = 0;
        while ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_referrals WHERE referral_code = %s",
            $code
        ))) {
            $attempt++;
            if ($attempt >= $max_attempts) {
                return new WP_Error('ptp_referral_code_fail', 'Could not generate unique referral code after ' . $max_attempts . ' attempts');
            }
            $random = strtoupper(wp_generate_password(4, false, false));
            $code = $name_part . $random;
        }
        
        // Store the code
        $wpdb->insert(
            $wpdb->prefix . 'ptp_referrals',
            array(
                'referrer_id' => $user_id,
                'referrer_type' => $type,
                'referral_code' => $code,
                'status' => 'pending',
            ),
            array('%d', '%s', '%s', '%s')
        );
        
        return $code;
    }
    
    /**
     * Get referral link for user
     */
    public static function get_referral_link($user_id, $type = 'parent') {
        $code = self::generate_code($user_id, $type);
        return add_query_arg('ref', $code, home_url('/'));
    }
    
    /**
     * Track referral code from URL
     */
    public function track_referral_code() {
        if (isset($_GET['ref']) && !empty($_GET['ref'])) {
            $code = sanitize_text_field($_GET['ref']);
            
            // Store in cookie for 30 days
            setcookie('ptp_referral_code', $code, time() + (30 * DAY_IN_SECONDS), '/');
            
            // Also store in session if available
            if (false && ptp_session()) {
                ptp_session()->set('ptp_referral_code', $code);
            }
            
            // Log the visit
            $this->log_referral_visit($code);
        }
    }
    
    /**
     * Log referral visit
     */
    private function log_referral_visit($code) {
        global $wpdb;
        
        $referral = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_referrals WHERE referral_code = %s",
            $code
        ));
        
        if (!$referral) {
            return;
        }
        
        // Update visit count (could add a visits column)
        // For now, just track the source
        $utm_source = isset($_GET['utm_source']) ? sanitize_text_field($_GET['utm_source']) : '';
        $utm_medium = isset($_GET['utm_medium']) ? sanitize_text_field($_GET['utm_medium']) : '';
        
        if ($utm_source || $utm_medium) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_referrals',
                array(
                    'utm_source' => $utm_source,
                    'utm_medium' => $utm_medium,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                ),
                array('id' => $referral->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }
    }
    
    /**
     * Get current referral code from cookie/session
     */
    public static function get_current_referral_code() {
        // Check PTP Native session first
        if (false && ptp_session()) {
            $code = ptp_session()->get('ptp_referral_code');
            if ($code) return $code;
        }
        
        // Check cookie
        if (isset($_COOKIE['ptp_referral_code'])) {
            return sanitize_text_field($_COOKIE['ptp_referral_code']);
        }
        
        return null;
    }
    
    /**
     * Apply referral discount at checkout
     */
    public function apply_referral_discount($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        
        $code = self::get_current_referral_code();
        if (!$code) return;
        
        // Check if this is a new customer
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            
            // Check if user has already used a referral
            global $wpdb;
            $used = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_referrals 
                 WHERE referred_id = %d AND status IN ('converted', 'credited')",
                $user_id
            ));
            
            if ($used) return; // Already used a referral
        }
        
        // Verify the referral code exists and belongs to different user
        global $wpdb;
        $referral = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_referrals WHERE referral_code = %s",
            $code
        ));
        
        if (!$referral) return;
        
        // Don't allow self-referral
        if (is_user_logged_in() && $referral->referrer_id == get_current_user_id()) {
            return;
        }
        
        // Apply discount
        $discount = self::REFERRED_REWARD;
        $cart->add_fee(__('Referral Discount', 'ptp'), -$discount, false);
    }
    
    /**
     * Process referral reward after order completion
     */
    public function process_referral_reward($order_id) {
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order) return;
        
        $code = self::get_current_referral_code();
        if (!$code) return;
        
        global $wpdb;
        
        // Get the referral
        $referral = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_referrals WHERE referral_code = %s",
            $code
        ));
        
        if (!$referral || $referral->status === 'credited') return;
        
        $referred_id = $order->get_user_id();
        
        // Don't allow self-referral
        if ($referred_id == $referral->referrer_id) return;
        
        // Mark as converted
        $wpdb->update(
            $wpdb->prefix . 'ptp_referrals',
            array(
                'referred_id' => $referred_id,
                'referred_email' => $order->get_billing_email(),
                'status' => 'converted',
                'conversion_order_id' => $order_id,
                'converted_at' => current_time('mysql'),
            ),
            array('id' => $referral->id),
            array('%d', '%s', '%s', '%d', '%s'),
            array('%d')
        );
        
        // Award credit to referrer
        $this->award_credit(
            $referral->referrer_id,
            $referral->referrer_type,
            self::PARENT_REWARD,
            $referral->id,
            'Referral bonus - ' . $order->get_billing_first_name()
        );
        
        // Update referral status
        $wpdb->update(
            $wpdb->prefix . 'ptp_referrals',
            array(
                'status' => 'credited',
                'reward_amount' => self::PARENT_REWARD,
                'reward_type' => 'credit',
                'credited_at' => current_time('mysql'),
            ),
            array('id' => $referral->id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );
        
        // Send notification to referrer
        $this->send_referral_notification($referral->referrer_id, self::PARENT_REWARD);
        
        // Clear the referral cookie
        setcookie('ptp_referral_code', '', time() - 3600, '/');
        if (false && ptp_session()) {
            ptp_session()->set('ptp_referral_code', null);
        }
    }
    
    /**
     * Award credit to user
     */
    private function award_credit($user_id, $user_type, $amount, $referral_id, $description) {
        global $wpdb;
        
        // Get current balance
        $balance = $this->get_credit_balance($user_id, $user_type);
        $new_balance = $balance + $amount;
        
        // Insert credit record
        $wpdb->insert(
            $wpdb->prefix . 'ptp_referral_credits',
            array(
                'user_id' => $user_id,
                'user_type' => $user_type,
                'amount' => $amount,
                'balance' => $new_balance,
                'type' => 'earned',
                'referral_id' => $referral_id,
                'description' => $description,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
            ),
            array('%d', '%s', '%f', '%f', '%s', '%d', '%s', '%s')
        );
        
        return $new_balance;
    }
    
    /**
     * Get user's credit balance
     */
    public static function get_credit_balance($user_id, $user_type = 'parent') {
        global $wpdb;
        
        $earned = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ptp_referral_credits 
             WHERE user_id = %d AND user_type = %s AND type = 'earned'
             AND (expires_at IS NULL OR expires_at > NOW())",
            $user_id, $user_type
        ));
        
        $used = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ptp_referral_credits 
             WHERE user_id = %d AND user_type = %s AND type = 'used'",
            $user_id, $user_type
        ));
        
        return max(0, floatval($earned) - floatval($used));
    }
    
    /**
     * Get referral stats for user
     */
    public static function get_user_stats($user_id, $user_type = 'parent') {
        global $wpdb;
        
        $stats = array(
            'code' => self::generate_code($user_id, $user_type),
            'link' => self::get_referral_link($user_id, $user_type),
            'total_referrals' => 0,
            'converted' => 0,
            'pending' => 0,
            'total_earned' => 0,
            'balance' => self::get_credit_balance($user_id, $user_type),
        );
        
        // Get referral counts
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count, SUM(reward_amount) as earned
             FROM {$wpdb->prefix}ptp_referrals 
             WHERE referrer_id = %d AND referrer_type = %s AND referred_id IS NOT NULL
             GROUP BY status",
            $user_id, $user_type
        ));
        
        foreach ($results as $row) {
            $stats['total_referrals'] += $row->count;
            if ($row->status === 'credited') {
                $stats['converted'] += $row->count;
                $stats['total_earned'] += floatval($row->earned);
            } elseif ($row->status === 'pending' || $row->status === 'converted') {
                $stats['pending'] += $row->count;
            }
        }
        
        return $stats;
    }
    
    /**
     * Send notification to referrer
     */
    private function send_referral_notification($user_id, $amount) {
        $user = get_user_by('ID', $user_id);
        if (!$user) return;
        
        $subject = "You earned \${$amount} in referral credit! 🎉";
        
        $body = "Great news!\n\n";
        $body .= "Someone you referred just completed their first booking with PTP. ";
        $body .= "We've added \${$amount} in credit to your account.\n\n";
        $body .= "Your current credit balance: \$" . self::get_credit_balance($user_id, 'parent') . "\n\n";
        $body .= "Keep sharing and earning!\n\n";
        $body .= "- The PTP Team";
        
        wp_mail($user->user_email, $subject, $body);
    }
    
    /**
     * AJAX: Get referral stats
     */
    public function ajax_get_stats() {
        check_ajax_referer('ptp_referral_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }
        
        $user_id = get_current_user_id();
        $user_type = isset($_POST['user_type']) ? sanitize_text_field($_POST['user_type']) : 'parent';
        
        $stats = self::get_user_stats($user_id, $user_type);
        
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Generate referral link
     */
    public function ajax_generate_link() {
        check_ajax_referer('ptp_referral_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }
        
        $user_id = get_current_user_id();
        $user_type = isset($_POST['user_type']) ? sanitize_text_field($_POST['user_type']) : 'parent';
        
        wp_send_json_success(array(
            'link' => self::get_referral_link($user_id, $user_type),
            'code' => self::generate_code($user_id, $user_type),
        ));
    }
    
    /**
     * Render referral widget
     */
    public function render_referral_widget($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to access your referral link.</p>';
        }
        
        $atts = shortcode_atts(array(
            'type' => 'parent',
        ), $atts);
        
        $user_id = get_current_user_id();
        $stats = self::get_user_stats($user_id, $atts['type']);
        
        ob_start();
        ?>
        <style>
        .ptp-ref {
            --gold: #FCB900;
            --black: #0A0A0A;
            --gray-100: #F3F4F6;
            --gray-400: #9CA3AF;
            --green: #22C55E;
            font-family: 'Inter', -apple-system, sans-serif;
        }
        .ptp-ref-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }
        .ptp-ref-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .ptp-ref-title {
            font-family: 'Oswald', sans-serif;
            font-size: 20px;
            font-weight: 700;
            text-transform: uppercase;
            margin: 0 0 8px;
        }
        .ptp-ref-subtitle {
            color: var(--gray-400);
            font-size: 14px;
            margin: 0;
        }
        .ptp-ref-reward {
            background: linear-gradient(135deg, var(--gold) 0%, #E5A800 100%);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        .ptp-ref-reward-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(0,0,0,0.6);
            margin-bottom: 4px;
        }
        .ptp-ref-reward-amount {
            font-family: 'Oswald', sans-serif;
            font-size: 36px;
            font-weight: 700;
            color: var(--black);
        }
        .ptp-ref-link-box {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }
        .ptp-ref-link-input {
            flex: 1;
            padding: 12px 14px;
            border: 2px solid var(--gray-100);
            border-radius: 8px;
            font-size: 13px;
            color: var(--gray-400);
        }
        .ptp-ref-copy-btn {
            background: var(--gold);
            color: var(--black);
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            font-family: 'Oswald', sans-serif;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            cursor: pointer;
            transition: transform 0.15s;
        }
        .ptp-ref-copy-btn:active {
            transform: scale(0.95);
        }
        .ptp-ref-share {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .ptp-ref-share-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            text-decoration: none;
            transition: transform 0.15s;
        }
        .ptp-ref-share-btn:hover {
            transform: scale(1.1);
        }
        .ptp-ref-share-btn.sms { background: var(--green); }
        .ptp-ref-share-btn.email { background: #3B82F6; }
        .ptp-ref-share-btn.facebook { background: #1877F2; }
        .ptp-ref-share-btn.twitter { background: #000; }
        .ptp-ref-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding-top: 20px;
            border-top: 1px solid var(--gray-100);
        }
        .ptp-ref-stat {
            text-align: center;
        }
        .ptp-ref-stat-value {
            font-family: 'Oswald', sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: var(--black);
        }
        .ptp-ref-stat-value.green { color: var(--green); }
        .ptp-ref-stat-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--gray-400);
            margin-top: 2px;
        }
        </style>
        
        <div class="ptp-ref">
            <div class="ptp-ref-card">
                <div class="ptp-ref-header">
                    <h3 class="ptp-ref-title">Share & Earn</h3>
                    <p class="ptp-ref-subtitle">Get $<?php echo self::PARENT_REWARD; ?> for every family you refer</p>
                </div>
                
                <div class="ptp-ref-reward">
                    <div class="ptp-ref-reward-label">Your Credit Balance</div>
                    <div class="ptp-ref-reward-amount">$<?php echo number_format($stats['balance']); ?></div>
                </div>
                
                <div class="ptp-ref-link-box">
                    <input type="text" class="ptp-ref-link-input" value="<?php echo esc_url($stats['link']); ?>" readonly id="refLink">
                    <button class="ptp-ref-copy-btn" onclick="copyRefLink()">Copy</button>
                </div>
                
                <div class="ptp-ref-share">
                    <a href="sms:?body=Check out PTP Soccer! My kids love their training. Use my link to get $<?php echo self::REFERRED_REWARD; ?> off: <?php echo esc_url($stats['link']); ?>" class="ptp-ref-share-btn sms">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </a>
                    <a href="mailto:?subject=Check out PTP Soccer!&body=My kids love their training with PTP. They have amazing pro and college coaches! Use my link to get $<?php echo self::REFERRED_REWARD; ?> off your first booking: <?php echo esc_url($stats['link']); ?>" class="ptp-ref-share-btn email">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($stats['link']); ?>" target="_blank" class="ptp-ref-share-btn facebook">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    </a>
                    <a href="https://twitter.com/intent/tweet?text=Check out PTP Soccer - amazing youth training with pro coaches!&url=<?php echo urlencode($stats['link']); ?>" target="_blank" class="ptp-ref-share-btn twitter">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                    </a>
                </div>
                
                <div class="ptp-ref-stats">
                    <div class="ptp-ref-stat">
                        <div class="ptp-ref-stat-value"><?php echo $stats['converted']; ?></div>
                        <div class="ptp-ref-stat-label">Referrals</div>
                    </div>
                    <div class="ptp-ref-stat">
                        <div class="ptp-ref-stat-value green">$<?php echo number_format($stats['total_earned']); ?></div>
                        <div class="ptp-ref-stat-label">Earned</div>
                    </div>
                    <div class="ptp-ref-stat">
                        <div class="ptp-ref-stat-value"><?php echo $stats['pending']; ?></div>
                        <div class="ptp-ref-stat-label">Pending</div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        function copyRefLink() {
            var input = document.getElementById('refLink');
            input.select();
            input.setSelectionRange(0, 99999);
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value).then(function() {
                    alert('Link copied!');
                });
            } else {
                document.execCommand('copy');
                alert('Link copied!');
            }
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render leaderboard
     */
    public function render_leaderboard($atts) {
        global $wpdb;
        
        $atts = shortcode_atts(array(
            'limit' => 10,
        ), $atts);
        
        $leaders = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                r.referrer_id,
                u.display_name,
                COUNT(DISTINCT r.referred_id) as referrals,
                SUM(r.reward_amount) as earned
             FROM {$wpdb->prefix}ptp_referrals r
             JOIN {$wpdb->users} u ON r.referrer_id = u.ID
             WHERE r.status = 'credited'
             GROUP BY r.referrer_id
             ORDER BY referrals DESC
             LIMIT %d",
            intval($atts['limit'])
        ));
        
        ob_start();
        ?>
        <div class="ptp-ref-leaderboard">
            <h3 style="font-family:Oswald,sans-serif;text-transform:uppercase;margin-bottom:16px;">Top Referrers</h3>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:2px solid #E5E7EB;">
                        <th style="text-align:left;padding:12px 0;font-size:11px;text-transform:uppercase;color:#9CA3AF;">Rank</th>
                        <th style="text-align:left;padding:12px 0;font-size:11px;text-transform:uppercase;color:#9CA3AF;">Name</th>
                        <th style="text-align:right;padding:12px 0;font-size:11px;text-transform:uppercase;color:#9CA3AF;">Referrals</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaders as $i => $leader): ?>
                    <tr style="border-bottom:1px solid #F3F4F6;">
                        <td style="padding:14px 0;font-weight:600;"><?php echo $i + 1; ?></td>
                        <td style="padding:14px 0;"><?php echo esc_html($leader->display_name); ?></td>
                        <td style="padding:14px 0;text-align:right;font-weight:600;color:#FCB900;"><?php echo $leader->referrals; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'ptp-admin',
            'Referrals',
            '🎁 Referrals',
            'manage_options',
            'ptp-referrals',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        global $wpdb;
        
        // Get stats
        $total_referrals = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_referrals WHERE status = 'credited'"
        );
        
        $total_credits = $wpdb->get_var(
            "SELECT COALESCE(SUM(reward_amount), 0) FROM {$wpdb->prefix}ptp_referrals WHERE status = 'credited'"
        );
        
        $pending = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_referrals WHERE status IN ('pending', 'converted')"
        );
        
        // Recent referrals
        $recent = $wpdb->get_results(
            "SELECT r.*, 
                    referrer.display_name as referrer_name,
                    referred.display_name as referred_name
             FROM {$wpdb->prefix}ptp_referrals r
             LEFT JOIN {$wpdb->users} referrer ON r.referrer_id = referrer.ID
             LEFT JOIN {$wpdb->users} referred ON r.referred_id = referred.ID
             WHERE r.referred_id IS NOT NULL
             ORDER BY r.created_at DESC
             LIMIT 20"
        );
        
        ?>
        <div class="wrap">
            <h1>🎁 Referral Program</h1>
            
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:20px 0;">
                <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#22C55E;"><?php echo $total_referrals; ?></div>
                    <div style="color:#6B7280;font-size:14px;">Successful Referrals</div>
                </div>
                <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#FCB900;">$<?php echo number_format($total_credits); ?></div>
                    <div style="color:#6B7280;font-size:14px;">Credits Awarded</div>
                </div>
                <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#3B82F6;"><?php echo $pending; ?></div>
                    <div style="color:#6B7280;font-size:14px;">Pending</div>
                </div>
            </div>
            
            <h2>Recent Referrals</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Referrer</th>
                        <th>Referred</th>
                        <th>Status</th>
                        <th>Reward</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?php echo date('M j, Y', strtotime($r->created_at)); ?></td>
                        <td><?php echo esc_html($r->referrer_name); ?></td>
                        <td><?php echo esc_html($r->referred_name ?: $r->referred_email); ?></td>
                        <td>
                            <span style="display:inline-block;padding:4px 12px;border-radius:50px;font-size:12px;font-weight:600;
                                background:<?php echo $r->status === 'credited' ? '#DCFCE7' : ($r->status === 'converted' ? '#FEF3C7' : '#F3F4F6'); ?>;
                                color:<?php echo $r->status === 'credited' ? '#166534' : ($r->status === 'converted' ? '#92400E' : '#6B7280'); ?>;">
                                <?php echo ucfirst($r->status); ?>
                            </span>
                        </td>
                        <td>$<?php echo number_format($r->reward_amount, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

// Initialize
PTP_Referral_System::instance();
