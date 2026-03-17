<?php
/**
 * PTP Viral Engine v1.0.0
 * 
 * Comprehensive sharing, referrals, and virality features
 * Mobile-first, conversion-optimized
 * 
 * Features:
 * - Parent referral program with rewards
 * - Trainer referral tracking
 * - One-tap social sharing
 * - Share cards for social media
 * - SMS/WhatsApp sharing
 * - Real-time social proof notifications
 * - Review collection & Google review push
 * - Achievement badges & milestones
 * 
 * @since 57.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PTP_Viral_Engine {
    
    private static $instance = null;
    
    // Referral rewards
    const REFERRER_CREDIT = 25;      // $25 credit for referrer
    const REFEREE_DISCOUNT = 20;     // 20% off first booking for referee
    const TRAINER_REFERRAL_BONUS = 50; // $50 for trainer referrals
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Initialize
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Referral tracking
        add_action('init', array($this, 'capture_referral_code'), 5);
        add_action('ptp_booking_completed', array($this, 'process_referral_reward'), 10, 2);
        add_action('user_register', array($this, 'assign_referral_code'));
        
        // Share triggers
        add_action('ptp_after_booking_confirm', array($this, 'show_share_prompt'), 20, 2);
        // REMOVED: add_action('ptp-native_thankyou', array($this, 'show_share_prompt_woo'), 20);
        add_action('ptp_after_review_submit', array($this, 'show_share_after_review'));
        
        // Social proof - DISABLED v123: removes popup notifications
        // add_action('wp_footer', array($this, 'render_social_proof_notifications'));
        add_action('wp_footer', array($this, 'render_share_modal'));
        add_action('wp_footer', array($this, 'render_referral_modal'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_get_referral_stats', array($this, 'ajax_get_referral_stats'));
        add_action('wp_ajax_ptp_generate_share_card', array($this, 'ajax_generate_share_card'));
        add_action('wp_ajax_nopriv_ptp_generate_share_card', array($this, 'ajax_generate_share_card'));
        add_action('wp_ajax_ptp_track_share', array($this, 'ajax_track_share'));
        add_action('wp_ajax_nopriv_ptp_track_share', array($this, 'ajax_track_share'));
        // add_action('wp_ajax_ptp_get_social_proof', array($this, 'ajax_get_social_proof')); // v126: Disabled
        // add_action('wp_ajax_nopriv_ptp_get_social_proof', array($this, 'ajax_get_social_proof')); // v126: Disabled
        add_action('wp_ajax_ptp_validate_referral', array($this, 'ajax_validate_referral'));
        add_action('wp_ajax_nopriv_ptp_validate_referral', array($this, 'ajax_validate_referral'));
        add_action('wp_ajax_ptp_redeem_credit', array($this, 'ajax_redeem_credit'));
        
        // Shortcodes
        add_shortcode('ptp_referral_dashboard', array($this, 'shortcode_referral_dashboard'));
        add_shortcode('ptp_share_buttons', array($this, 'shortcode_share_buttons'));
        // add_shortcode('ptp_social_proof', array($this, 'shortcode_social_proof')); // v126: Disabled
        add_shortcode('ptp_leaderboard', array($this, 'shortcode_leaderboard'));
        
        // REST API for share cards
        add_action('rest_api_init', array($this, 'register_share_endpoints'));
    }
    
    /**
     * Initialize
     */
    public function init() {
        $this->maybe_create_tables();
        
        // Rewrite rules for share cards
        add_rewrite_rule(
            'share/trainer/([^/]+)/?$',
            'index.php?ptp_share=trainer&ptp_share_slug=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            'share/booking/([^/]+)/?$',
            'index.php?ptp_share=booking&ptp_share_id=$matches[1]',
            'top'
        );
        add_rewrite_tag('%ptp_share%', '([^&]+)');
        add_rewrite_tag('%ptp_share_slug%', '([^&]+)');
        add_rewrite_tag('%ptp_share_id%', '([^&]+)');
        
        // Handle share page requests
        add_action('template_redirect', array($this, 'handle_share_page'));
    }
    
    /**
     * Create database tables
     */
    private function maybe_create_tables() {
        if (get_option('ptp_viral_tables_version') === '1.0') {
            return;
        }
        
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_referrals (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            referrer_user_id bigint(20) UNSIGNED NOT NULL,
            referrer_code varchar(32) NOT NULL,
            referee_user_id bigint(20) UNSIGNED DEFAULT NULL,
            referee_email varchar(255) DEFAULT NULL,
            referee_booking_id bigint(20) UNSIGNED DEFAULT NULL,
            referrer_reward decimal(10,2) DEFAULT 0,
            referee_discount decimal(10,2) DEFAULT 0,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            converted_at datetime DEFAULT NULL,
            rewarded_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY referrer_user_id (referrer_user_id),
            KEY referrer_code (referrer_code),
            KEY referee_user_id (referee_user_id),
            KEY status (status)
        ) $charset;
        
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_referral_credits (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(10,2) NOT NULL,
            type varchar(32) NOT NULL,
            reference_id bigint(20) UNSIGNED DEFAULT NULL,
            description varchar(255) DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            redeemed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type)
        ) $charset;
        
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_shares (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            share_type varchar(32) NOT NULL,
            share_id bigint(20) UNSIGNED DEFAULT NULL,
            platform varchar(32) NOT NULL,
            clicks int DEFAULT 0,
            conversions int DEFAULT 0,
            share_url varchar(500) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY share_type (share_type),
            KEY platform (platform)
        ) $charset;
        
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_achievements (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            achievement_key varchar(64) NOT NULL,
            achievement_name varchar(128) NOT NULL,
            points int DEFAULT 0,
            unlocked_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_achievement (user_id, achievement_key),
            KEY user_id (user_id)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        update_option('ptp_viral_tables_version', '1.0');
    }
    
    /**
     * Enqueue assets
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'ptp-viral',
            PTP_PLUGIN_URL . 'assets/css/viral.css',
            array(),
            PTP_VERSION
        );
        
        wp_enqueue_script(
            'ptp-viral',
            PTP_PLUGIN_URL . 'assets/js/viral.js',
            array('jquery'),
            PTP_VERSION,
            true
        );
        
        $user_id = get_current_user_id();
        $referral_code = $user_id ? $this->get_user_referral_code($user_id) : '';
        
        wp_localize_script('ptp-viral', 'ptpViral', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_viral'),
            'siteUrl' => home_url(),
            'referralCode' => $referral_code,
            'rewards' => array(
                'referrerCredit' => self::REFERRER_CREDIT,
                'refereeDiscount' => self::REFEREE_DISCOUNT,
            ),
            'i18n' => array(
                'copied' => __('Copied!', 'ptp-training'),
                'shareSuccess' => __('Thanks for sharing!', 'ptp-training'),
                'referralApplied' => __('Referral discount applied!', 'ptp-training'),
            ),
        ));
    }
    
    /**
     * Get or generate user's referral code
     */
    public function get_user_referral_code($user_id) {
        $code = get_user_meta($user_id, 'ptp_referral_code', true);
        
        if (!$code) {
            $code = $this->generate_referral_code($user_id);
            update_user_meta($user_id, 'ptp_referral_code', $code);
        }
        
        return $code;
    }
    
    /**
     * Generate unique referral code
     */
    private function generate_referral_code($user_id) {
        $user = get_user_by('ID', $user_id);
        $base = $user ? sanitize_title(strtok($user->display_name, ' ')) : 'PTP';
        $base = strtoupper(substr($base, 0, 4));
        $suffix = strtoupper(substr(md5($user_id . wp_salt()), 0, 4));
        
        return $base . $suffix;
    }
    
    /**
     * Assign referral code on registration
     */
    public function assign_referral_code($user_id) {
        $this->get_user_referral_code($user_id);
    }
    
    /**
     * Capture referral code from URL
     */
    public function capture_referral_code() {
        if (isset($_GET['ref']) && !empty($_GET['ref'])) {
            $code = sanitize_text_field($_GET['ref']);
            
            // Store in cookie for 30 days
            setcookie('ptp_referral', $code, time() + 30 * DAY_IN_SECONDS, '/');
            
            // Store in session too
            if (false && ptp_session()) {
                ptp_session()->set('ptp_referral_code', $code);
            }
            
            // Track the click
            $this->track_referral_click($code);
        }
    }
    
    /**
     * Track referral link click
     */
    private function track_referral_click($code) {
        global $wpdb;
        
        // Find referrer
        $referrer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ptp_referral_code' AND meta_value = %s",
            $code
        ));
        
        if ($referrer_id) {
            // Increment click count
            $clicks = (int) get_user_meta($referrer_id, 'ptp_referral_clicks', true);
            update_user_meta($referrer_id, 'ptp_referral_clicks', $clicks + 1);
        }
    }
    
    /**
     * Process referral reward after booking
     */
    public function process_referral_reward($booking_id, $booking) {
        global $wpdb;
        
        // Get referral code
        $ref_code = '';
        if (isset($_COOKIE['ptp_referral'])) {
            $ref_code = sanitize_text_field($_COOKIE['ptp_referral']);
        } elseif (false && ptp_session()) {
            $ref_code = ptp_session()->get('ptp_referral_code');
        }
        
        if (empty($ref_code)) {
            return;
        }
        
        // Find referrer
        $referrer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ptp_referral_code' AND meta_value = %s",
            $ref_code
        ));
        
        if (!$referrer_id) {
            return;
        }
        
        // Get parent/user from booking
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE id = %d",
            $booking->parent_id
        ));
        
        if (!$parent || $parent->user_id == $referrer_id) {
            return; // Can't refer yourself
        }
        
        // Check if this user was already referred
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_referrals WHERE referee_user_id = %d AND status = 'completed'",
            $parent->user_id
        ));
        
        if ($existing) {
            return; // Already got referral credit
        }
        
        // Create referral record
        $wpdb->insert(
            $wpdb->prefix . 'ptp_referrals',
            array(
                'referrer_user_id' => $referrer_id,
                'referrer_code' => $ref_code,
                'referee_user_id' => $parent->user_id,
                'referee_booking_id' => $booking_id,
                'referrer_reward' => self::REFERRER_CREDIT,
                'referee_discount' => 0, // Already applied at checkout
                'status' => 'completed',
                'converted_at' => current_time('mysql'),
            )
        );
        
        // Add credit to referrer
        $this->add_credit($referrer_id, self::REFERRER_CREDIT, 'referral', $booking_id, 
            sprintf(__('Referral bonus for %s', 'ptp-training'), $parent->display_name ?: 'New user'));
        
        // Notify referrer
        $this->notify_referral_success($referrer_id, $parent->user_id);
        
        // Clear cookie
        setcookie('ptp_referral', '', time() - 3600, '/');
        
        // Check for achievements
        $this->check_referral_achievements($referrer_id);
    }
    
    /**
     * Add credit to user account
     */
    public function add_credit($user_id, $amount, $type = 'referral', $reference_id = null, $description = '') {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_referral_credits',
            array(
                'user_id' => $user_id,
                'amount' => $amount,
                'type' => $type,
                'reference_id' => $reference_id,
                'description' => $description,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
            )
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get user's available credit balance
     */
    public function get_credit_balance($user_id) {
        global $wpdb;
        
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) 
             FROM {$wpdb->prefix}ptp_referral_credits 
             WHERE user_id = %d 
             AND redeemed_at IS NULL 
             AND (expires_at IS NULL OR expires_at > NOW())",
            $user_id
        ));
    }
    
    /**
     * Notify referrer of successful referral
     */
    private function notify_referral_success($referrer_id, $referee_id) {
        $referrer = get_user_by('ID', $referrer_id);
        $referee = get_user_by('ID', $referee_id);
        
        if (!$referrer) return;
        
        $subject = sprintf(__('🎉 You earned $%d! Your friend just booked training', 'ptp-training'), self::REFERRER_CREDIT);
        
        $message = sprintf(
            __("Great news! Your referral %s just completed their first booking.\n\nYou've earned $%d in PTP credit!\n\nYour current credit balance: $%s\n\nKeep sharing your referral link to earn more:\n%s\n\nThanks for spreading the word!\n\n- The PTP Team", 'ptp-training'),
            $referee ? $referee->display_name : 'A friend',
            self::REFERRER_CREDIT,
            number_format($this->get_credit_balance($referrer_id), 2),
            home_url('?ref=' . $this->get_user_referral_code($referrer_id))
        );
        
        wp_mail($referrer->user_email, $subject, $message);
    }
    
    /**
     * Check and award referral achievements
     */
    private function check_referral_achievements($user_id) {
        global $wpdb;
        
        $referral_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_referrals 
             WHERE referrer_user_id = %d AND status = 'completed'",
            $user_id
        ));
        
        $achievements = array(
            1 => array('key' => 'first_referral', 'name' => 'First Referral', 'points' => 10),
            5 => array('key' => 'referral_5', 'name' => 'High Five', 'points' => 50),
            10 => array('key' => 'referral_10', 'name' => 'Perfect 10', 'points' => 100),
            25 => array('key' => 'referral_25', 'name' => 'Quarter Century', 'points' => 250),
            50 => array('key' => 'referral_50', 'name' => 'Half Century', 'points' => 500),
            100 => array('key' => 'referral_100', 'name' => 'Century Club', 'points' => 1000),
        );
        
        foreach ($achievements as $count => $achievement) {
            if ($referral_count >= $count) {
                $this->award_achievement($user_id, $achievement);
            }
        }
    }
    
    /**
     * Award achievement to user
     */
    private function award_achievement($user_id, $achievement) {
        global $wpdb;
        
        // Check if already has this achievement
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_achievements 
             WHERE user_id = %d AND achievement_key = %s",
            $user_id, $achievement['key']
        ));
        
        if ($existing) return;
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_achievements',
            array(
                'user_id' => $user_id,
                'achievement_key' => $achievement['key'],
                'achievement_name' => $achievement['name'],
                'points' => $achievement['points'],
            )
        );
    }
    
    /**
     * Show share prompt after booking
     */
    public function show_share_prompt($booking_id, $booking) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $booking->trainer_id
        ));
        
        if (!$trainer) return;
        
        $share_url = home_url('/trainer/' . $trainer->slug);
        $share_text = sprintf(
            __("Just booked 1-on-1 soccer training with %s! 🎯⚽ Level up your game at PTP Training.", 'ptp-training'),
            $trainer->display_name
        );
        
        ?>
        <div class="ptp-share-prompt" style="background:#F0FDF4;border:2px solid #10B981;border-radius:12px;padding:20px;margin:24px 0;text-align:center;">
            <h3 style="font-family:'Oswald',sans-serif;font-size:18px;margin:0 0 8px;color:#059669;">
                🎉 Booking Confirmed!
            </h3>
            <p style="margin:0 0 16px;color:#666;">Share with friends & earn $<?php echo self::REFERRER_CREDIT; ?> for each referral</p>
            
            <div class="ptp-share-buttons" style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <?php echo $this->render_share_buttons($share_url, $share_text, 'booking_confirm'); ?>
            </div>
            
            <div style="margin-top:16px;padding-top:16px;border-top:1px solid #D1FAE5;">
                <p style="font-size:13px;color:#666;margin:0 0 8px;">Your referral link:</p>
                <div style="display:flex;gap:8px;max-width:400px;margin:0 auto;">
                    <input type="text" value="<?php echo esc_url(home_url('?ref=' . $this->get_user_referral_code(get_current_user_id()))); ?>" 
                           readonly id="referral-link"
                           style="flex:1;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                    <button onclick="ptpViral.copyReferralLink()" 
                            style="background:#0A0A0A;color:#fff;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;font-weight:600;">
                        Copy
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Show share prompt after PTP Native purchase
     */
    public function show_share_prompt_woo($order_id) {
        $share_url = home_url('/ptp-find-a-camp');
        $share_text = __("Just signed up for PTP Soccer Camp! ⚽🔥 Best training in the area.", 'ptp-training');
        
        ?>
        <div class="ptp-share-prompt" style="background:#FEF3C7;border:2px solid #FCB900;border-radius:12px;padding:20px;margin:24px 0;text-align:center;">
            <h3 style="font-family:'Oswald',sans-serif;font-size:18px;margin:0 0 8px;">
                ⚽ Share the Love!
            </h3>
            <p style="margin:0 0 16px;color:#666;">Know other players who'd love this? Share & earn rewards!</p>
            
            <div class="ptp-share-buttons" style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <?php echo $this->render_share_buttons($share_url, $share_text, 'woo_thankyou'); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render share buttons HTML
     */
    public function render_share_buttons($url, $text, $context = '') {
        $encoded_url = urlencode($url);
        $encoded_text = urlencode($text);
        $user_id = get_current_user_id();
        $ref_url = $user_id ? urlencode(home_url('?ref=' . $this->get_user_referral_code($user_id))) : $encoded_url;
        
        $buttons = array(
            'sms' => array(
                'label' => 'Text',
                'icon' => '💬',
                'url' => 'sms:?body=' . $encoded_text . '%20' . $ref_url,
                'color' => '#25D366',
            ),
            'whatsapp' => array(
                'label' => 'WhatsApp',
                'icon' => '',
                'url' => 'https://wa.me/?text=' . $encoded_text . '%20' . $ref_url,
                'color' => '#25D366',
            ),
            'facebook' => array(
                'label' => 'Facebook',
                'icon' => '',
                'url' => 'https://www.facebook.com/sharer/sharer.php?u=' . $ref_url . '&quote=' . $encoded_text,
                'color' => '#1877F2',
            ),
            'twitter' => array(
                'label' => 'X',
                'icon' => '',
                'url' => 'https://twitter.com/intent/tweet?text=' . $encoded_text . '&url=' . $ref_url,
                'color' => '#000',
            ),
            'copy' => array(
                'label' => 'Copy Link',
                'icon' => '🔗',
                'url' => '#',
                'color' => '#6B7280',
                'onclick' => "ptpViral.copyLink('" . esc_js($url) . "')",
            ),
        );
        
        $html = '';
        foreach ($buttons as $platform => $btn) {
            $onclick = isset($btn['onclick']) ? $btn['onclick'] : "ptpViral.trackShare('" . esc_js($platform) . "', '" . esc_js($context) . "')";
            
            $html .= sprintf(
                '<a href="%s" target="_blank" rel="noopener" 
                    onclick="%s"
                    style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;
                           background:%s;color:#fff;border-radius:8px;text-decoration:none;
                           font-weight:600;font-size:14px;transition:opacity 0.2s;"
                    onmouseover="this.style.opacity=\'0.9\'" onmouseout="this.style.opacity=\'1\'">
                    <span>%s</span>
                    <span>%s</span>
                </a>',
                esc_url($btn['url']),
                $onclick,
                $btn['color'],
                $btn['icon'],
                esc_html($btn['label'])
            );
        }
        
        return $html;
    }
    
    /**
     * Render social proof notifications
     */
    public function render_social_proof_notifications() {
        // Only on public pages
        if (is_admin() || is_page('ptp-checkout') || is_page('ptp-cart')) {
            return;
        }
        
        ?>
        <div id="ptp-social-proof" style="position:fixed;bottom:20px;left:20px;z-index:9998;pointer-events:none;">
            <!-- Notifications injected by JS -->
        </div>
        
        <style>
        .ptp-proof-notification {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 320px;
            animation: slideIn 0.3s ease, slideOut 0.3s ease 4.7s forwards;
            pointer-events: auto;
            margin-bottom: 12px;
        }
        .ptp-proof-notification img {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
        }
        .ptp-proof-content {
            flex: 1;
        }
        .ptp-proof-content strong {
            display: block;
            font-size: 14px;
            color: #0A0A0A;
        }
        .ptp-proof-content span {
            font-size: 12px;
            color: #6B7280;
        }
        @keyframes slideIn {
            from { transform: translateX(-100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(-100%); opacity: 0; }
        }
        @media (max-width: 600px) {
            #ptp-social-proof { left: 10px; right: 10px; bottom: 10px; }
            .ptp-proof-notification { max-width: 100%; }
        }
        </style>
        
        <script>
        (function() {
            // CRITICAL: Check if ptpViral is defined before using it
            // This prevents the 404 error with undefined variables
            if (typeof ptpViral === 'undefined' || !ptpViral.ajaxUrl || !ptpViral.nonce) {
                console.log('PTP Social Proof: Waiting for ptpViral to initialize...');
                return;
            }
            
            var container = document.getElementById('ptp-social-proof');
            var shown = 0;
            var maxShow = 3;
            var interval = 15000; // 15 seconds between notifications
            
            function showProof() {
                if (shown >= maxShow) return;
                
                // Double-check ptpViral is still available
                if (typeof ptpViral === 'undefined' || !ptpViral.ajaxUrl) return;
                
                fetch(ptpViral.ajaxUrl + '?action=ptp_get_social_proof&nonce=' + ptpViral.nonce)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.data.notification) {
                            var n = data.data.notification;
                            var div = document.createElement('div');
                            div.className = 'ptp-proof-notification';
                            div.innerHTML = '<img src="' + (n.image || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(n.name)) + '" alt="">' +
                                '<div class="ptp-proof-content"><strong>' + n.title + '</strong><span>' + n.subtitle + '</span></div>';
                            container.appendChild(div);
                            shown++;
                            
                            setTimeout(function() {
                                if (div.parentNode) div.parentNode.removeChild(div);
                            }, 5000);
                        }
                    });
            }
            
            // Start after 5 seconds
            setTimeout(function() {
                showProof();
                setInterval(showProof, interval);
            }, 5000);
        })();
        </script>
        <?php
    }
    
    /**
     * Render share modal
     */
    public function render_share_modal() {
        ?>
        <div id="ptp-share-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:99999;
                                          align-items:center;justify-content:center;padding:20px;">
            <div style="background:#fff;border-radius:16px;max-width:400px;width:100%;overflow:hidden;">
                <div style="background:#0A0A0A;color:#fff;padding:20px;text-align:center;">
                    <button onclick="document.getElementById('ptp-share-modal').style.display='none'" 
                            style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#fff;">&times;</button>
                    <h3 style="font-family:'Oswald',sans-serif;font-size:22px;margin:0;text-transform:uppercase;">
                        🎯 Share & Earn
                    </h3>
                </div>
                <div style="padding:24px;text-align:center;">
                    <p style="font-size:32px;margin:0;">💰</p>
                    <h4 style="font-size:18px;margin:12px 0 8px;">Earn $<?php echo self::REFERRER_CREDIT; ?> for every friend!</h4>
                    <p style="color:#666;margin:0 0 20px;font-size:14px;">
                        Plus, they get <?php echo self::REFEREE_DISCOUNT; ?>% off their first booking
                    </p>
                    
                    <div id="share-modal-buttons" style="display:flex;flex-direction:column;gap:12px;">
                        <!-- Filled by JS -->
                    </div>
                    
                    <div style="margin-top:20px;padding-top:20px;border-top:1px solid #eee;">
                        <p style="font-size:13px;color:#666;margin:0 0 8px;">Your referral link:</p>
                        <div style="display:flex;gap:8px;">
                            <input type="text" id="share-modal-link" readonly
                                   style="flex:1;padding:10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                            <button onclick="ptpViral.copyReferralLink()" 
                                    style="background:#FCB900;color:#0A0A0A;border:none;padding:10px 16px;border-radius:6px;cursor:pointer;font-weight:700;">
                                Copy
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render referral dashboard modal
     */
    public function render_referral_modal() {
        if (!is_user_logged_in()) return;
        
        ?>
        <div id="ptp-referral-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:99999;
                                             align-items:center;justify-content:center;padding:20px;">
            <div style="background:#fff;border-radius:16px;max-width:500px;width:100%;max-height:90vh;overflow-y:auto;">
                <div style="background:#0A0A0A;color:#fff;padding:20px;">
                    <button onclick="document.getElementById('ptp-referral-modal').style.display='none'" 
                            style="float:right;background:none;border:none;font-size:24px;cursor:pointer;color:#fff;">&times;</button>
                    <h3 style="font-family:'Oswald',sans-serif;font-size:20px;margin:0;text-transform:uppercase;">
                        💰 Your Referral Dashboard
                    </h3>
                </div>
                <div id="referral-modal-content" style="padding:24px;">
                    <div style="text-align:center;padding:40px;">
                        <div class="spinner"></div>
                        <p>Loading...</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Handle share page
     */
    public function handle_share_page() {
        $share_type = get_query_var('ptp_share');
        
        if (!$share_type) return;
        
        if ($share_type === 'trainer') {
            $slug = get_query_var('ptp_share_slug');
            $this->render_trainer_share_card($slug);
            exit;
        }
        
        if ($share_type === 'booking') {
            $booking_id = get_query_var('ptp_share_id');
            $this->render_booking_share_card($booking_id);
            exit;
        }
    }
    
    /**
     * Render trainer share card (OG image)
     */
    private function render_trainer_share_card($slug) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s",
            $slug
        ));
        
        if (!$trainer) {
            wp_redirect(home_url());
            exit;
        }
        
        // Generate OG-optimized page
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($trainer->display_name); ?> - PTP Training</title>
            
            <!-- Open Graph -->
            <meta property="og:title" content="Train with <?php echo esc_attr($trainer->display_name); ?>">
            <meta property="og:description" content="<?php echo esc_attr($trainer->headline ?: '1-on-1 soccer training'); ?> • $<?php echo esc_attr($trainer->hourly_rate); ?>/hr • <?php echo esc_attr($trainer->average_rating); ?>★">
            <meta property="og:image" content="<?php echo esc_url($trainer->photo_url ?: PTP_PLUGIN_URL . 'assets/images/share-default.jpg'); ?>">
            <meta property="og:url" content="<?php echo esc_url(home_url('/trainer/' . $trainer->slug)); ?>">
            <meta property="og:type" content="profile">
            
            <!-- Twitter -->
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="Train with <?php echo esc_attr($trainer->display_name); ?>">
            <meta name="twitter:description" content="<?php echo esc_attr($trainer->headline); ?>">
            <meta name="twitter:image" content="<?php echo esc_url($trainer->photo_url); ?>">
            
            <!-- Redirect to actual profile -->
            <meta http-equiv="refresh" content="0;url=<?php echo esc_url(home_url('/trainer/' . $trainer->slug)); ?>">
        </head>
        <body>
            <p>Redirecting to trainer profile...</p>
            <script>window.location.href = '<?php echo esc_url(home_url('/trainer/' . $trainer->slug)); ?>';</script>
        </body>
        </html>
        <?php
    }
    
    /**
     * AJAX: Get referral stats
     */
    public function ajax_get_referral_stats() {
        check_ajax_referer('ptp_viral', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }
        
        global $wpdb;
        
        $stats = array(
            'referral_code' => $this->get_user_referral_code($user_id),
            'referral_link' => home_url('?ref=' . $this->get_user_referral_code($user_id)),
            'credit_balance' => $this->get_credit_balance($user_id),
            'total_referrals' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_referrals WHERE referrer_user_id = %d AND status = 'completed'",
                $user_id
            )),
            'pending_referrals' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_referrals WHERE referrer_user_id = %d AND status = 'pending'",
                $user_id
            )),
            'total_earned' => (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(referrer_reward), 0) FROM {$wpdb->prefix}ptp_referrals WHERE referrer_user_id = %d AND status = 'completed'",
                $user_id
            )),
            'clicks' => (int) get_user_meta($user_id, 'ptp_referral_clicks', true),
        );
        
        // Get recent referrals
        $stats['recent_referrals'] = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name as referee_name
             FROM {$wpdb->prefix}ptp_referrals r
             LEFT JOIN {$wpdb->users} u ON r.referee_user_id = u.ID
             WHERE r.referrer_user_id = %d
             ORDER BY r.created_at DESC
             LIMIT 10",
            $user_id
        ));
        
        // Get achievements
        $stats['achievements'] = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_achievements WHERE user_id = %d ORDER BY unlocked_at DESC",
            $user_id
        ));
        
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Get social proof notification
     */
    public function ajax_get_social_proof() {
        global $wpdb;
        
        // Get recent bookings/purchases
        $notifications = array();
        
        // Recent training bookings
        $recent_booking = $wpdb->get_row(
            "SELECT b.*, t.display_name as trainer_name, t.photo_url, t.location,
                    p.display_name as parent_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             WHERE b.status IN ('confirmed', 'completed')
             AND b.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY RAND()
             LIMIT 1"
        );
        
        if ($recent_booking) {
            $first_name = strtok($recent_booking->parent_name ?: 'Someone', ' ');
            $location = strtok($recent_booking->location ?: 'nearby', ',');
            
            $notifications[] = array(
                'type' => 'booking',
                'title' => $first_name . ' from ' . $location,
                'subtitle' => 'Just booked training with ' . $recent_booking->trainer_name,
                'image' => $recent_booking->photo_url,
                'time' => human_time_diff(strtotime($recent_booking->created_at)) . ' ago',
            );
        }
        
        // Recent camp purchases
        if (false) {
            $recent_orders = PTP_Native_Order_Manager::get_orders(array(
                'limit' => 5,
                'status' => array('completed', 'processing'),
                'date_created' => '>' . date('Y-m-d', strtotime('-24 hours')),
            ));
            
            foreach ($recent_orders as $order) {
                foreach ($order->get_items() as $item) {
                    $cats = wp_get_post_terms($item->get_product_id(), 'product_cat', array('fields' => 'slugs'));
                    if (array_intersect($cats, array('camps', 'clinics'))) {
                        $billing_city = $order->get_billing_city();
                        $first_name = $order->get_billing_first_name();
                        
                        $notifications[] = array(
                            'type' => 'camp',
                            'title' => ($first_name ?: 'Someone') . ' from ' . ($billing_city ?: 'nearby'),
                            'subtitle' => 'Just signed up for ' . $item->get_name(),
                            'image' => get_the_post_thumbnail_url($item->get_product_id(), 'thumbnail'),
                            'time' => human_time_diff($order->get_date_created()->getTimestamp()) . ' ago',
                        );
                        break;
                    }
                }
            }
        }
        
        // Return random notification
        if (empty($notifications)) {
            wp_send_json_success(array('notification' => null));
        }
        
        $notification = $notifications[array_rand($notifications)];
        wp_send_json_success(array('notification' => $notification));
    }
    
    /**
     * AJAX: Track share
     */
    public function ajax_track_share() {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_shares',
            array(
                'user_id' => get_current_user_id() ?: null,
                'share_type' => sanitize_text_field($_POST['share_type'] ?? 'general'),
                'share_id' => absint($_POST['share_id'] ?? 0),
                'platform' => sanitize_text_field($_POST['platform'] ?? ''),
                'share_url' => esc_url_raw($_POST['share_url'] ?? ''),
            )
        );
        
        wp_send_json_success();
    }
    
    /**
     * AJAX: Validate referral code
     */
    public function ajax_validate_referral() {
        $code = strtoupper(sanitize_text_field($_POST['code'] ?? ''));
        
        if (empty($code)) {
            wp_send_json_error('No code provided');
        }
        
        global $wpdb;
        
        // v193: Check ptp_referral_codes table first (has discount amount)
        $ref_table = $wpdb->prefix . 'ptp_referral_codes';
        $ref_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$ref_table'") === $ref_table;
        
        if ($ref_table_exists) {
            $ref = $wpdb->get_row($wpdb->prepare(
                "SELECT rc.*, u.display_name FROM $ref_table rc 
                 JOIN {$wpdb->users} u ON rc.user_id = u.ID 
                 WHERE rc.code = %s AND rc.status = 'active'",
                $code
            ));
            
            if ($ref) {
                $discount = floatval($ref->discount ?: 15);
                wp_send_json_success(array(
                    'valid' => true,
                    'referrer_name' => $ref->display_name ?: 'A PTP family',
                    'referral_id' => $ref->id,
                    'discount' => $discount,
                    'message' => '$' . number_format($discount) . ' off — referred by ' . ($ref->display_name ?: 'a PTP family') . '!',
                ));
                return;
            }
        }
        
        // Fallback: check user meta (legacy referral codes)
        $referrer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ptp_referral_code' AND meta_value = %s",
            $code
        ));
        
        if (!$referrer_id) {
            wp_send_json_error(array('message' => 'Invalid referral code. Check the code and try again.'));
        }
        
        $referrer = get_user_by('ID', $referrer_id);
        $discount = 15; // v193: flat $15 friend discount
        
        wp_send_json_success(array(
            'valid' => true,
            'referrer_name' => $referrer ? $referrer->display_name : 'A PTP family',
            'discount' => $discount,
            'message' => '$' . $discount . ' off — referred by ' . ($referrer ? $referrer->display_name : 'a PTP family') . '!',
        ));
    }
    
    /**
     * AJAX: Redeem credit
     */
    public function ajax_redeem_credit() {
        check_ajax_referer('ptp_viral', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }
        
        $amount = floatval($_POST['amount'] ?? 0);
        $balance = $this->get_credit_balance($user_id);
        
        if ($amount <= 0 || $amount > $balance) {
            wp_send_json_error('Invalid amount');
        }
        
        // Create coupon for PTP Native
        if (false) {
            $coupon_code = 'PTP-CREDIT-' . strtoupper(substr(md5(uniqid()), 0, 6));
            
            $coupon = ptp_create_coupon();
            $coupon->set_code($coupon_code);
            $coupon->set_discount_type('fixed_cart');
            $coupon->set_amount($amount);
            $coupon->set_usage_limit(1);
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_date_expires(strtotime('+30 days'));
            $coupon->save();
            
            // Mark credits as redeemed
            global $wpdb;
            $remaining = $amount;
            
            $credits = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_referral_credits 
                 WHERE user_id = %d AND redeemed_at IS NULL 
                 AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY created_at ASC",
                $user_id
            ));
            
            foreach ($credits as $credit) {
                if ($remaining <= 0) break;
                
                if ($credit->amount <= $remaining) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_referral_credits',
                        array('redeemed_at' => current_time('mysql')),
                        array('id' => $credit->id)
                    );
                    $remaining -= $credit->amount;
                } else {
                    // Partial redemption - update amount
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_referral_credits',
                        array('amount' => $credit->amount - $remaining),
                        array('id' => $credit->id)
                    );
                    $remaining = 0;
                }
            }
            
            wp_send_json_success(array(
                'coupon_code' => $coupon_code,
                'amount' => $amount,
                'new_balance' => $this->get_credit_balance($user_id),
            ));
        }
        
        wp_send_json_error('Could not create coupon');
    }
    
    /**
     * Shortcode: Referral dashboard
     */
    public function shortcode_referral_dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . __('Please log in to view your referral dashboard.', 'ptp-training') . '</p>';
        }
        
        $user_id = get_current_user_id();
        $referral_code = $this->get_user_referral_code($user_id);
        $referral_link = home_url('?ref=' . $referral_code);
        $credit_balance = $this->get_credit_balance($user_id);
        
        global $wpdb;
        $total_referrals = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_referrals WHERE referrer_user_id = %d AND status = 'completed'",
            $user_id
        ));
        $total_earned = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(referrer_reward), 0) FROM {$wpdb->prefix}ptp_referrals WHERE referrer_user_id = %d AND status = 'completed'",
            $user_id
        ));
        
        ob_start();
        ?>
        <div class="ptp-referral-dashboard" style="font-family:'Inter',-apple-system,sans-serif;">
            
            <!-- Stats -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px;">
                <div style="background:#F0FDF4;border-radius:12px;padding:20px;text-align:center;">
                    <div style="font-size:32px;font-weight:700;color:#059669;">$<?php echo number_format($credit_balance, 0); ?></div>
                    <div style="font-size:13px;color:#666;">Available Credit</div>
                </div>
                <div style="background:#FEF3C7;border-radius:12px;padding:20px;text-align:center;">
                    <div style="font-size:32px;font-weight:700;color:#D97706;"><?php echo $total_referrals; ?></div>
                    <div style="font-size:13px;color:#666;">Friends Referred</div>
                </div>
                <div style="background:#EDE9FE;border-radius:12px;padding:20px;text-align:center;">
                    <div style="font-size:32px;font-weight:700;color:#7C3AED;">$<?php echo number_format($total_earned, 0); ?></div>
                    <div style="font-size:13px;color:#666;">Total Earned</div>
                </div>
            </div>
            
            <!-- Referral Link -->
            <div style="background:#0A0A0A;border-radius:12px;padding:24px;color:#fff;margin-bottom:24px;">
                <h3 style="font-family:'Oswald',sans-serif;font-size:18px;margin:0 0 8px;text-transform:uppercase;">
                    🎯 Your Referral Link
                </h3>
                <p style="color:#9CA3AF;margin:0 0 16px;font-size:14px;">
                    Share this link to earn $<?php echo self::REFERRER_CREDIT; ?> for every friend who books!
                </p>
                <div style="display:flex;gap:12px;">
                    <input type="text" value="<?php echo esc_url($referral_link); ?>" readonly
                           style="flex:1;padding:12px;border:none;border-radius:8px;font-size:14px;background:#1F1F1F;color:#fff;">
                    <button onclick="navigator.clipboard.writeText('<?php echo esc_js($referral_link); ?>');this.textContent='Copied!';"
                            style="background:#FCB900;color:#0A0A0A;border:none;padding:12px 24px;border-radius:8px;
                                   font-weight:700;cursor:pointer;white-space:nowrap;">
                        Copy Link
                    </button>
                </div>
                
                <div style="display:flex;gap:12px;margin-top:16px;flex-wrap:wrap;">
                    <?php echo $this->render_share_buttons($referral_link, 'Check out PTP Training! Use my link for ' . self::REFEREE_DISCOUNT . '% off your first session:', 'dashboard'); ?>
                </div>
            </div>
            
            <!-- How it Works -->
            <div style="background:#F9FAFB;border-radius:12px;padding:24px;margin-bottom:24px;">
                <h3 style="font-family:'Oswald',sans-serif;font-size:16px;margin:0 0 16px;text-transform:uppercase;">
                    How It Works
                </h3>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;text-align:center;">
                    <div>
                        <div style="font-size:32px;margin-bottom:8px;">📤</div>
                        <strong style="display:block;font-size:14px;">1. Share</strong>
                        <span style="font-size:12px;color:#666;">Send your link to friends</span>
                    </div>
                    <div>
                        <div style="font-size:32px;margin-bottom:8px;">⚽</div>
                        <strong style="display:block;font-size:14px;">2. They Book</strong>
                        <span style="font-size:12px;color:#666;">They get <?php echo self::REFEREE_DISCOUNT; ?>% off</span>
                    </div>
                    <div>
                        <div style="font-size:32px;margin-bottom:8px;">💰</div>
                        <strong style="display:block;font-size:14px;">3. You Earn</strong>
                        <span style="font-size:12px;color:#666;">Get $<?php echo self::REFERRER_CREDIT; ?> credit</span>
                    </div>
                </div>
            </div>
            
            <?php if ($credit_balance >= 10): ?>
            <!-- Redeem Credit -->
            <div style="background:#F0FDF4;border:2px solid #10B981;border-radius:12px;padding:20px;text-align:center;margin-bottom:24px;">
                <h4 style="margin:0 0 8px;">Ready to use your credit?</h4>
                <p style="color:#666;margin:0 0 16px;font-size:14px;">
                    Convert your $<?php echo number_format($credit_balance, 0); ?> credit into a discount code
                </p>
                <button onclick="ptpViral.redeemCredit(<?php echo $credit_balance; ?>)"
                        style="background:#10B981;color:#fff;border:none;padding:12px 32px;border-radius:8px;
                               font-weight:700;cursor:pointer;font-size:16px;">
                    Redeem $<?php echo number_format($credit_balance, 0); ?> Credit
                </button>
            </div>
            <?php endif; ?>
            
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Share buttons
     */
    public function shortcode_share_buttons($atts) {
        $atts = shortcode_atts(array(
            'url' => '',
            'text' => '',
            'context' => 'shortcode',
        ), $atts);
        
        $url = $atts['url'] ?: get_permalink();
        $text = $atts['text'] ?: get_the_title();
        
        return '<div class="ptp-share-buttons-container" style="display:flex;gap:12px;flex-wrap:wrap;">' . 
               $this->render_share_buttons($url, $text, $atts['context']) . 
               '</div>';
    }
    
    /**
     * Shortcode: Leaderboard
     */
    public function shortcode_leaderboard($atts) {
        $atts = shortcode_atts(array(
            'limit' => 10,
            'period' => 'all', // 'month', 'year', 'all'
        ), $atts);
        
        global $wpdb;
        
        $date_filter = '';
        if ($atts['period'] === 'month') {
            $date_filter = "AND r.converted_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
        } elseif ($atts['period'] === 'year') {
            $date_filter = "AND r.converted_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
        }
        
        $leaders = $wpdb->get_results($wpdb->prepare(
            "SELECT r.referrer_user_id, u.display_name, COUNT(*) as referral_count, SUM(r.referrer_reward) as total_earned
             FROM {$wpdb->prefix}ptp_referrals r
             JOIN {$wpdb->users} u ON r.referrer_user_id = u.ID
             WHERE r.status = 'completed' $date_filter
             GROUP BY r.referrer_user_id
             ORDER BY referral_count DESC
             LIMIT %d",
            absint($atts['limit'])
        ));
        
        if (empty($leaders)) {
            return '<p>' . __('No referrals yet. Be the first!', 'ptp-training') . '</p>';
        }
        
        ob_start();
        ?>
        <div class="ptp-leaderboard" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
            <div style="background:#0A0A0A;color:#fff;padding:16px 20px;">
                <h3 style="font-family:'Oswald',sans-serif;font-size:18px;margin:0;text-transform:uppercase;">
                    🏆 Top Referrers
                </h3>
            </div>
            <table style="width:100%;border-collapse:collapse;">
                <tbody>
                    <?php foreach ($leaders as $i => $leader): ?>
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:12px 16px;width:40px;text-align:center;">
                            <?php 
                            $medals = array('🥇', '🥈', '🥉');
                            echo isset($medals[$i]) ? $medals[$i] : ($i + 1);
                            ?>
                        </td>
                        <td style="padding:12px 16px;">
                            <strong><?php echo esc_html($leader->display_name); ?></strong>
                        </td>
                        <td style="padding:12px 16px;text-align:right;">
                            <span style="background:#FCB900;color:#0A0A0A;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700;">
                                <?php echo $leader->referral_count; ?> referrals
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Register share endpoints
     */
    public function register_share_endpoints() {
        register_rest_route('ptp/v1', '/share-card/(?P<type>\w+)/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_share_card'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * REST: Get share card data
     */
    public function rest_get_share_card($request) {
        $type = $request->get_param('type');
        $id = $request->get_param('id');
        
        if ($type === 'trainer') {
            global $wpdb;
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $id
            ));
            
            if (!$trainer) {
                return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
            }
            
            return rest_ensure_response(array(
                'title' => 'Train with ' . $trainer->display_name,
                'description' => $trainer->headline,
                'image' => $trainer->photo_url,
                'url' => home_url('/trainer/' . $trainer->slug),
            ));
        }
        
        return new WP_Error('invalid_type', 'Invalid share type', array('status' => 400));
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Viral_Engine::instance();
}, 15);
