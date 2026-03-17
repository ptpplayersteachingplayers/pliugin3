<?php
/**
 * PTP Viral Enhancements v116
 * 
 * Comprehensive viral/share features to drive organic growth:
 * 1. Enhanced post-booking share prompt on thank-you page
 * 2. Post-session review + share flow
 * 3. Trainer profile share buttons
 * 4. SMS auto-share links in booking confirmations
 * 
 * @since 116.0.0
 */

defined('ABSPATH') || exit;

class PTP_Viral_Enhancements {
    
    private static $instance = null;
    
    // Share message templates
    const SHARE_MSG_TRAINER = "I just booked soccer training with %s on PTP! They get 20%% off their first session: %s";
    const SHARE_MSG_SESSION = "My kid just had an amazing training session with %s! Book yours and get 20%% off: %s";
    const SHARE_MSG_REVIEW = "⭐ Just gave %s a %d-star review on PTP Soccer! If your kid needs training, check them out: %s";
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // 1. Enhanced thank-you page share prompt
        add_action('ptp_thankyou_after_referral_card', array($this, 'render_enhanced_share_prompt'), 10, 2);
        
        // 2. Post-session review + share flow
        add_action('wp_ajax_ptp_submit_review_with_share', array($this, 'ajax_submit_review_with_share'));
        add_action('ptp_parent_dashboard_after_sessions', array($this, 'render_review_prompts'));
        
        // 3. Trainer profile share (handled via filter for cleaner integration)
        add_filter('ptp_trainer_profile_after_stats', array($this, 'render_trainer_share_buttons'), 10, 2);
        
        // 4. SMS share links - hook into existing SMS system
        add_filter('ptp_sms_booking_confirmation_message', array($this, 'add_share_link_to_sms'), 10, 2);
        add_action('ptp_booking_confirmed', array($this, 'send_share_sms_followup'), 15, 1);
        
        // Enqueue assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Shortcodes
        add_shortcode('ptp_trainer_share', array($this, 'shortcode_trainer_share'));
        add_shortcode('ptp_review_share_modal', array($this, 'shortcode_review_share_modal'));
    }
    
    /**
     * Enqueue viral enhancement assets
     */
    public function enqueue_assets() {
        // Only on relevant pages
        if (!is_page(array('thank-you', 'trainer-dashboard', 'parent-dashboard', 'my-training'))) {
            // Check for trainer profile pages
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($uri, '/trainer/') === false) {
                return;
            }
        }
        
        wp_enqueue_style(
            'ptp-viral-enhancements',
            PTP_PLUGIN_URL . 'assets/css/viral-enhancements.css',
            array(),
            PTP_VERSION . '.116'
        );
        
        wp_enqueue_script(
            'ptp-viral-enhancements',
            PTP_PLUGIN_URL . 'assets/js/viral-enhancements.js',
            array('jquery'),
            PTP_VERSION . '.116',
            true
        );
        
        $user_id = get_current_user_id();
        $referral_code = '';
        
        // v116 fix: Use PTP_Referral_System which creates table record for checkout discount
        if ($user_id && class_exists('PTP_Referral_System')) {
            $referral_code = PTP_Referral_System::generate_code($user_id, 'parent');
        } elseif ($user_id && class_exists('PTP_Viral_Engine')) {
            $viral = PTP_Viral_Engine::instance();
            $referral_code = $viral->get_user_referral_code($user_id);
        } elseif ($user_id) {
            $referral_code = get_user_meta($user_id, 'ptp_referral_code', true);
            if (!$referral_code) {
                $referral_code = strtoupper(substr(md5($user_id . 'ptp'), 0, 8));
            }
        }
        
        wp_localize_script('ptp-viral-enhancements', 'ptpViralEnhance', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_viral_enhance'),
            'siteUrl' => home_url(),
            'referralCode' => $referral_code,
            'referralLink' => $referral_code ? home_url('/?ref=' . $referral_code) : home_url(),
            'shareTexts' => array(
                'trainer' => __('I just booked training with %s on PTP Soccer! Use my link for 20% off:', 'ptp-training'),
                'session' => __('Amazing training session with %s! Get 20% off your first session:', 'ptp-training'),
                'review' => __('Just gave %s a great review on PTP! Check them out:', 'ptp-training'),
            ),
            'i18n' => array(
                'copied' => __('Copied!', 'ptp-training'),
                'shareSuccess' => __('Thanks for sharing!', 'ptp-training'),
                'reviewSubmitted' => __('Review submitted! Thanks for sharing!', 'ptp-training'),
            ),
        ));
    }
    
    // =========================================
    // 1. ENHANCED THANK-YOU SHARE PROMPT
    // =========================================
    
    /**
     * Render enhanced share prompt on thank-you page
     * Hooked after the referral card for maximum visibility
     */
    public function render_enhanced_share_prompt($order, $booking) {
        $user_id = get_current_user_id();
        $referral_link = home_url();
        
        if ($user_id) {
            // v116 fix: Use PTP_Referral_System for checkout discount to work
            $referral_code = '';
            if (class_exists('PTP_Referral_System')) {
                $referral_code = PTP_Referral_System::generate_code($user_id, 'parent');
            } elseif (class_exists('PTP_Viral_Engine')) {
                $viral = PTP_Viral_Engine::instance();
                $referral_code = $viral->get_user_referral_code($user_id);
            } else {
                $referral_code = get_user_meta($user_id, 'ptp_referral_code', true);
            }
            if ($referral_code) {
                $referral_link = home_url('/?ref=' . $referral_code);
            }
        }
        
        $trainer_name = '';
        if ($booking && !empty($booking->trainer_name)) {
            $trainer_name = $booking->trainer_name;
        }
        
        $share_text = $trainer_name 
            ? sprintf(self::SHARE_MSG_TRAINER, $trainer_name, $referral_link)
            : "I just booked soccer training on PTP! Get 20% off your first session: " . $referral_link;
        
        $encoded_text = urlencode($share_text);
        $encoded_link = urlencode($referral_link);
        ?>
        <div class="ptp-viral-share-cta" id="share-trainer-cta">
            <div class="ptp-viral-share-header">
                <span class="ptp-viral-share-emoji">📣</span>
                <div>
                    <div class="ptp-viral-share-title">Share Your Trainer</div>
                    <div class="ptp-viral-share-subtitle">Friends get 20% off • You get $25 credit</div>
                </div>
            </div>
            
            <div class="ptp-viral-share-body">
                <p class="ptp-viral-share-desc">
                    <?php if ($trainer_name): ?>
                    Know someone whose kid could benefit from training with <?php echo esc_html(explode(' ', $trainer_name)[0]); ?>? Share now!
                    <?php else: ?>
                    Know families whose kids would love professional soccer training? Share and you both win!
                    <?php endif; ?>
                </p>
                
                <div class="ptp-viral-share-buttons">
                    <!-- SMS/iMessage - Primary for mobile -->
                    <a href="sms:?body=<?php echo $encoded_text; ?>" 
                       class="ptp-viral-btn ptp-viral-btn-primary"
                       onclick="ptpViralTrack('sms', 'thankyou')">
                        <span class="ptp-viral-btn-icon">💬</span>
                        <span>Text a Friend</span>
                    </a>
                    
                    <!-- WhatsApp -->
                    <a href="https://wa.me/?text=<?php echo $encoded_text; ?>" 
                       target="_blank"
                       class="ptp-viral-btn ptp-viral-btn-whatsapp"
                       onclick="ptpViralTrack('whatsapp', 'thankyou')">
                        <span class="ptp-viral-btn-icon">📱</span>
                        <span>WhatsApp</span>
                    </a>
                    
                    <!-- Email -->
                    <a href="mailto:?subject=<?php echo urlencode('Check out this soccer training!'); ?>&body=<?php echo $encoded_text; ?>" 
                       class="ptp-viral-btn ptp-viral-btn-email"
                       onclick="ptpViralTrack('email', 'thankyou')">
                        <span class="ptp-viral-btn-icon">✉️</span>
                        <span>Email</span>
                    </a>
                    
                    <!-- Copy Link -->
                    <button type="button" 
                            class="ptp-viral-btn ptp-viral-btn-copy"
                            onclick="ptpViralCopyLink('<?php echo esc_js($referral_link); ?>', this)">
                        <span class="ptp-viral-btn-icon">🔗</span>
                        <span>Copy Link</span>
                    </button>
                </div>
                
                <div class="ptp-viral-share-hint">
                    <span>💡</span> Pre-written message included — just hit send!
                </div>
            </div>
        </div>
        <?php
    }
    
    // =========================================
    // 2. POST-SESSION REVIEW + SHARE FLOW
    // =========================================
    
    /**
     * Render review prompts for completed sessions
     */
    public function render_review_prompts() {
        if (!is_user_logged_in()) return;
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        // Get parent
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if (!$parent) return;
        
        // Get completed sessions without reviews
        $unreviewed = $wpdb->get_results($wpdb->prepare("
            SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug,
                   p.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_reviews r ON b.id = r.booking_id
            WHERE b.parent_id = %d 
            AND b.status = 'completed'
            AND r.id IS NULL
            AND b.session_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            ORDER BY b.session_date DESC
            LIMIT 3
        ", $parent->id));
        
        if (empty($unreviewed)) return;
        
        // v116 fix: Use PTP_Referral_System for checkout discount to work
        $referral_code = '';
        if (class_exists('PTP_Referral_System')) {
            $referral_code = PTP_Referral_System::generate_code($user_id, 'parent');
        } else {
            $referral_code = get_user_meta($user_id, 'ptp_referral_code', true);
        }
        $referral_link = $referral_code ? home_url('/?ref=' . $referral_code) : home_url();
        
        ?>
        <div class="ptp-review-prompts" id="review-prompts">
            <div class="ptp-review-prompts-header">
                <h3>⭐ Rate Your Recent Sessions</h3>
                <p>Share your experience & help other families find great trainers</p>
            </div>
            
            <?php foreach ($unreviewed as $session): 
                $trainer_first = explode(' ', $session->trainer_name)[0];
                $trainer_photo = $session->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($session->trainer_name) . '&background=FCB900&color=0A0A0A';
                $session_date = date('M j', strtotime($session->session_date));
            ?>
            <div class="ptp-review-card" data-booking="<?php echo $session->id; ?>">
                <div class="ptp-review-card-info">
                    <img src="<?php echo esc_url($trainer_photo); ?>" alt="" class="ptp-review-card-photo">
                    <div class="ptp-review-card-details">
                        <div class="ptp-review-card-trainer"><?php echo esc_html($session->trainer_name); ?></div>
                        <div class="ptp-review-card-meta">
                            <?php echo esc_html($session->player_name); ?> • <?php echo $session_date; ?>
                        </div>
                    </div>
                </div>
                
                <div class="ptp-review-card-stars" id="stars-<?php echo $session->id; ?>">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="ptp-star" data-rating="<?php echo $i; ?>" onclick="ptpSelectRating(<?php echo $session->id; ?>, <?php echo $i; ?>)">☆</span>
                    <?php endfor; ?>
                </div>
                
                <div class="ptp-review-card-form" id="review-form-<?php echo $session->id; ?>" style="display:none;">
                    <textarea 
                        id="review-text-<?php echo $session->id; ?>" 
                        placeholder="What did you love about this session? (optional)"
                        rows="2"></textarea>
                    
                    <button type="button" 
                            class="ptp-review-submit-btn"
                            onclick="ptpSubmitReview(<?php echo $session->id; ?>, '<?php echo esc_js($session->trainer_name); ?>', '<?php echo esc_js($session->trainer_slug); ?>', '<?php echo esc_js($referral_link); ?>')">
                        Submit & Share
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Share Modal (appears after review) -->
        <div class="ptp-review-share-modal" id="review-share-modal" style="display:none;">
            <div class="ptp-review-share-modal-content">
                <button type="button" class="ptp-review-share-close" onclick="ptpCloseShareModal()">×</button>
                
                <div class="ptp-review-share-success">
                    <span class="ptp-review-share-check">✓</span>
                    <h3>Review Submitted!</h3>
                    <p>Thanks for helping other families find great trainers.</p>
                </div>
                
                <div class="ptp-review-share-cta">
                    <h4>📣 Share with Friends</h4>
                    <p>They get 20% off • You get $25 credit</p>
                    
                    <div class="ptp-review-share-buttons" id="review-share-buttons">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Submit review with share tracking
     */
    public function ajax_submit_review_with_share() {
        check_ajax_referer('ptp_viral_enhance', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $rating = intval($_POST['rating'] ?? 0);
        $review_text = sanitize_textarea_field($_POST['review_text'] ?? '');
        
        if (!$booking_id || $rating < 1 || $rating > 5) {
            wp_send_json_error(array('message' => 'Invalid rating'));
        }
        
        // Create review using existing system
        if (class_exists('PTP_Reviews')) {
            $result = PTP_Reviews::create($booking_id, $rating, $review_text);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            
            // Fire share trigger action
            do_action('ptp_after_review_submit', $result, $booking_id, $rating);
            
            wp_send_json_success(array(
                'review_id' => $result,
                'message' => 'Review submitted successfully!'
            ));
        } else {
            wp_send_json_error(array('message' => 'Review system unavailable'));
        }
    }
    
    // =========================================
    // 3. TRAINER PROFILE SHARE BUTTONS
    // =========================================
    
    /**
     * Render share buttons on trainer profile
     */
    public function render_trainer_share_buttons($trainer, $context = 'profile') {
        if (!$trainer) return '';
        
        $trainer_url = home_url('/trainer/' . $trainer->slug . '/');
        $trainer_name = $trainer->display_name;
        $trainer_first = explode(' ', $trainer_name)[0];
        
        // Get referral link if user is logged in
        // v116 fix: Use PTP_Referral_System for checkout discount to work
        $user_id = get_current_user_id();
        $share_url = $trainer_url;
        
        if ($user_id) {
            $referral_code = '';
            if (class_exists('PTP_Referral_System')) {
                $referral_code = PTP_Referral_System::generate_code($user_id, 'parent');
            } else {
                $referral_code = get_user_meta($user_id, 'ptp_referral_code', true);
            }
            if ($referral_code) {
                $share_url = $trainer_url . '?ref=' . $referral_code;
            }
        }
        
        $share_text = sprintf(
            "Check out %s on PTP Soccer! Professional training that actually works. Book here:",
            $trainer_first
        );
        
        $encoded_text = urlencode($share_text . ' ' . $share_url);
        $encoded_url = urlencode($share_url);
        
        ob_start();
        ?>
        <div class="ptp-trainer-share" id="trainer-share-buttons">
            <button type="button" class="ptp-trainer-share-toggle" onclick="ptpToggleTrainerShare()">
                <span>📤</span> Share <?php echo esc_html($trainer_first); ?>
            </button>
            
            <div class="ptp-trainer-share-dropdown" id="trainer-share-dropdown" style="display:none;">
                <div class="ptp-trainer-share-dropdown-header">
                    Share & Earn $25 Credit
                </div>
                
                <a href="sms:?body=<?php echo $encoded_text; ?>" 
                   class="ptp-trainer-share-option"
                   onclick="ptpViralTrack('sms', 'trainer_profile')">
                    💬 Text
                </a>
                
                <a href="https://wa.me/?text=<?php echo $encoded_text; ?>" 
                   target="_blank"
                   class="ptp-trainer-share-option"
                   onclick="ptpViralTrack('whatsapp', 'trainer_profile')">
                    📱 WhatsApp
                </a>
                
                <a href="mailto:?subject=<?php echo urlencode('Check out this soccer trainer!'); ?>&body=<?php echo $encoded_text; ?>" 
                   class="ptp-trainer-share-option"
                   onclick="ptpViralTrack('email', 'trainer_profile')">
                    ✉️ Email
                </a>
                
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $encoded_url; ?>" 
                   target="_blank"
                   class="ptp-trainer-share-option"
                   onclick="ptpViralTrack('facebook', 'trainer_profile')">
                    📘 Facebook
                </a>
                
                <button type="button" 
                        class="ptp-trainer-share-option"
                        onclick="ptpViralCopyLink('<?php echo esc_js($share_url); ?>', this)">
                    🔗 Copy Link
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Trainer share buttons
     * Usage: [ptp_trainer_share trainer_id="123"]
     */
    public function shortcode_trainer_share($atts) {
        $atts = shortcode_atts(array(
            'trainer_id' => 0,
            'trainer_slug' => '',
        ), $atts);
        
        global $wpdb;
        $trainer = null;
        
        if ($atts['trainer_id']) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
                intval($atts['trainer_id'])
            ));
        } elseif ($atts['trainer_slug']) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s AND status = 'active'",
                sanitize_title($atts['trainer_slug'])
            ));
        }
        
        if (!$trainer) return '';
        
        return $this->render_trainer_share_buttons($trainer, 'shortcode');
    }
    
    // =========================================
    // 4. SMS AUTO-SHARE LINKS
    // =========================================
    
    /**
     * Add share link to booking confirmation SMS
     */
    public function add_share_link_to_sms($message, $booking) {
        if (!$booking) return $message;
        
        // Get parent's referral link
        $parent_user_id = 0;
        if (isset($booking->parent_id)) {
            global $wpdb;
            $parent_user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}ptp_parents WHERE id = %d",
                $booking->parent_id
            ));
        }
        
        if (!$parent_user_id) return $message;
        
        // v116 fix: Use PTP_Referral_System for checkout discount to work
        $referral_code = '';
        if (class_exists('PTP_Referral_System')) {
            $referral_code = PTP_Referral_System::generate_code($parent_user_id, 'parent');
        } else {
            $referral_code = get_user_meta($parent_user_id, 'ptp_referral_code', true);
        }
        if (!$referral_code) return $message;
        
        $referral_link = home_url('/?ref=' . $referral_code);
        
        // Append share prompt to message
        $message .= "\n\n📣 Know someone who'd love this? Share & get $25: " . $referral_link;
        
        return $message;
    }
    
    /**
     * Send follow-up share SMS after booking confirmation
     * Sent 1 hour after booking to not overwhelm
     */
    public function send_share_sms_followup($booking_id) {
        // Schedule follow-up SMS for 1 hour later
        if (!wp_next_scheduled('ptp_send_share_sms_followup', array($booking_id))) {
            wp_schedule_single_event(
                time() + 3600, // 1 hour
                'ptp_send_share_sms_followup',
                array($booking_id)
            );
        }
    }
    
    /**
     * Actually send the share follow-up SMS
     */
    public static function do_send_share_sms_followup($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name,
                   pa.phone as parent_phone, pa.user_id as parent_user_id, pa.display_name as parent_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking || !$booking->parent_phone) return;
        
        // v116 fix: Use PTP_Referral_System for checkout discount to work
        $referral_code = '';
        if (class_exists('PTP_Referral_System')) {
            $referral_code = PTP_Referral_System::generate_code($booking->parent_user_id, 'parent');
        } else {
            $referral_code = get_user_meta($booking->parent_user_id, 'ptp_referral_code', true);
        }
        if (!$referral_code) return;
        
        $referral_link = home_url('/?ref=' . $referral_code);
        $trainer_first = explode(' ', $booking->trainer_name)[0];
        
        $message = "Hey! 👋 Excited for your session with {$trainer_first}!\n\n";
        $message .= "Know other soccer families? Share your link & you BOTH get rewards:\n";
        $message .= "• They get 20% off\n";
        $message .= "• You get \$25 credit\n\n";
        $message .= "Your link: {$referral_link}";
        
        // Send via SMS system
        if (class_exists('PTP_SMS_V71') && PTP_SMS_V71::is_enabled()) {
            PTP_SMS_V71::send($booking->parent_phone, $message);
        } elseif (class_exists('PTP_SMS') && method_exists('PTP_SMS', 'send')) {
            PTP_SMS::send($booking->parent_phone, $message);
        }
    }
    
    /**
     * Get standalone share buttons HTML
     * Can be used anywhere via shortcode or direct call
     */
    public static function get_share_buttons_html($url, $text, $context = 'general') {
        $encoded_text = urlencode($text . ' ' . $url);
        $encoded_url = urlencode($url);
        
        $html = '<div class="ptp-share-buttons-inline">';
        
        // SMS
        $html .= '<a href="sms:?body=' . $encoded_text . '" class="ptp-share-btn-inline ptp-share-sms" onclick="ptpViralTrack(\'sms\', \'' . esc_js($context) . '\')">💬 Text</a>';
        
        // WhatsApp
        $html .= '<a href="https://wa.me/?text=' . $encoded_text . '" target="_blank" class="ptp-share-btn-inline ptp-share-whatsapp" onclick="ptpViralTrack(\'whatsapp\', \'' . esc_js($context) . '\')">📱 WhatsApp</a>';
        
        // Email
        $html .= '<a href="mailto:?subject=' . urlencode('Check this out!') . '&body=' . $encoded_text . '" class="ptp-share-btn-inline ptp-share-email" onclick="ptpViralTrack(\'email\', \'' . esc_js($context) . '\')">✉️ Email</a>';
        
        // Copy
        $html .= '<button type="button" class="ptp-share-btn-inline ptp-share-copy" onclick="ptpViralCopyLink(\'' . esc_js($url) . '\', this)">🔗 Copy</button>';
        
        $html .= '</div>';
        
        return $html;
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Viral_Enhancements::instance();
}, 20);

// Hook for scheduled SMS
add_action('ptp_send_share_sms_followup', array('PTP_Viral_Enhancements', 'do_send_share_sms_followup'));
