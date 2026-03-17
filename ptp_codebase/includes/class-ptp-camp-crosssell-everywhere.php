<?php
/**
 * PTP Camp Cross-Sell Everywhere System
 * 
 * Makes camps visible at EVERY touchpoint:
 * - Trainer profiles
 * - Post-booking confirmation
 * - Post-session emails (24hr, 48hr, 7 days)
 * - Parent dashboard
 * - My Training page
 * - After leaving reviews
 * - SMS follow-ups
 * - Checkout page
 * - Sticky banner site-wide
 * - Push notifications
 * - Abandoned cart recovery
 * 
 * The goal: Training covers CAC, camps are pure profit
 * Every training parent should see camp offers 5-7 times
 * 
 * @since 59.5.0
 */

defined('ABSPATH') || exit;

class PTP_Camp_Crosssell_Everywhere {
    
    private static $instance = null;
    
    // Cross-sell timing triggers
    const TRIGGER_AFTER_BOOKING = 'after_booking';
    const TRIGGER_AFTER_SESSION_1 = 'after_session_1';
    const TRIGGER_AFTER_SESSION_3 = 'after_session_3';
    const TRIGGER_AFTER_REVIEW = 'after_review';
    const TRIGGER_WEEKLY_DIGEST = 'weekly_digest';
    const TRIGGER_ABANDONED = 'abandoned';
    const TRIGGER_SEASONAL = 'seasonal';
    
    // Discount for training families
    const TRAINING_FAMILY_DISCOUNT = 15; // 15% off camps
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // ============================================
        // DISPLAY HOOKS - Where camps appear
        // ============================================
        
        // 1. Trainer profiles - "This trainer coaches at..."
        add_action('ptp_trainer_profile_after_reviews', array($this, 'display_trainer_camps'), 10);
        add_action('ptp_trainer_profile_sidebar_bottom', array($this, 'display_trainer_camps_sidebar'), 10);
        
        // 2. After booking confirmation - immediate upsell
        add_action('ptp_booking_confirmed', array($this, 'display_post_booking_camps'), 10, 2);
        add_action('ptp_after_booking_success', array($this, 'display_post_booking_camps'), 10, 2);
        
        // 3. Parent dashboard - prominent camp section
        add_action('ptp_parent_dashboard_before_bookings', array($this, 'display_dashboard_camp_banner'), 10);
        add_action('ptp_parent_dashboard_sidebar', array($this, 'display_dashboard_camp_card'), 10);
        
        // 4. My Training page - after session list
        add_action('ptp_my_training_after_sessions', array($this, 'display_my_training_camps'), 10);
        
        // 5. After review submission - thank you + camp offer
        add_action('ptp_after_review_submitted', array($this, 'display_post_review_camp_offer'), 10, 2);
        
        // 6. Checkout page - order bump
        add_action('ptp_checkout_before_payment', array($this, 'display_checkout_camp_bump'), 10);
        
        // 7. Site-wide sticky banner (seasonal) - DISABLED: interferes with mobile UX
        // add_action('wp_footer', array($this, 'display_sticky_camp_banner'), 10);
        
        // 8. Booking wizard - step 4 upsell
        add_action('ptp_booking_wizard_step_4', array($this, 'display_wizard_camp_upsell'), 10);
        
        // ============================================
        // EMAIL HOOKS - Camp offers in emails
        // ============================================
        
        // 9. Post-booking email - include camp CTA
        add_filter('ptp_email_booking_confirmation_content', array($this, 'add_camp_to_booking_email'), 10, 2);
        
        // 10. Post-session email (24hr) - "Continue the momentum"
        add_filter('ptp_email_post_session_content', array($this, 'add_camp_to_post_session_email'), 10, 2);
        
        // 11. Review request email - include camp offer
        add_filter('ptp_email_review_request_content', array($this, 'add_camp_to_review_email'), 10, 2);
        
        // 12. After 3rd session - dedicated camp email
        add_action('ptp_session_completed', array($this, 'maybe_send_camp_email_after_3rd_session'), 10, 2);
        
        // 13. Weekly digest for training families
        add_action('ptp_send_weekly_digest', array($this, 'send_camp_digest_to_training_families'));
        
        // ============================================
        // SMS HOOKS - Camp offers in texts
        // ============================================
        
        // 14. Post-session SMS (48hr)
        add_action('ptp_send_post_session_sms', array($this, 'add_camp_to_post_session_sms'), 10, 2);
        
        // ============================================
        // DISCOUNT & TRACKING
        // ============================================
        
        // PTP Native discount for training families
        // REMOVED: add_action('ptp-native_cart_calculate_fees', array($this, 'apply_training_family_discount'));
        // REMOVED: add_filter('ptp-native_get_price_html', array($this, 'show_training_family_price'), 10, 2);
        
        // Track conversions
        // REMOVED: add_action('ptp-native_thankyou', array($this, 'track_camp_conversion'), 10);
        
        // AJAX
        add_action('wp_ajax_ptp_get_camp_recommendations', array($this, 'ajax_get_camp_recommendations'));
        add_action('wp_ajax_nopriv_ptp_get_camp_recommendations', array($this, 'ajax_get_camp_recommendations'));
        add_action('wp_ajax_ptp_dismiss_camp_banner', array($this, 'ajax_dismiss_banner'));
        add_action('wp_ajax_nopriv_ptp_dismiss_camp_banner', array($this, 'ajax_dismiss_banner'));
        
        // Shortcodes
        add_shortcode('ptp_camp_crosssell', array($this, 'shortcode_camp_crosssell'));
        add_shortcode('ptp_trainer_camps', array($this, 'shortcode_trainer_camps'));
        add_shortcode('ptp_camp_banner', array($this, 'shortcode_camp_banner'));
        add_shortcode('ptp_training_family_camps', array($this, 'shortcode_training_family_camps'));
        
        // Schedule cron for camp emails
        if (!wp_next_scheduled('ptp_send_weekly_digest')) {
            wp_schedule_event(strtotime('next monday 9am'), 'weekly', 'ptp_send_weekly_digest');
        }
    }
    
    /**
     * ==========================================
     * CORE: Get camps for cross-sell
     * ==========================================
     */
    
    /**
     * Get upcoming camps (optionally by trainer)
     */
    public static function get_upcoming_camps($args = array()) {
        $defaults = array(
            'trainer_id' => null,
            'limit' => 4,
            'age_min' => null,
            'age_max' => null,
            'location' => null,
        );
        $args = wp_parse_args($args, $defaults);
        
        // Try PTP Native products first
        if (false) {
            $query_args = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => $args['limit'],
                'meta_query' => array(
                    array(
                        'key' => '_camp_start_date',
                        'value' => date('Y-m-d'),
                        'compare' => '>=',
                        'type' => 'DATE'
                    ),
                ),
                'orderby' => 'meta_value',
                'order' => 'ASC',
                'meta_key' => '_camp_start_date',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'slug',
                        'terms' => array('camps', 'clinics', 'camp', 'clinic'),
                    ),
                ),
            );
            
            // Filter by trainer if specified
            if ($args['trainer_id']) {
                $query_args['meta_query'][] = array(
                    'key' => '_camp_trainer_ids',
                    'value' => $args['trainer_id'],
                    'compare' => 'LIKE',
                );
            }
            
            $products = get_posts($query_args);
            
            $camps = array();
            foreach ($products as $product) {
                $ptp_product = ptp_get_camp_product($product->ID);
                if (!$ptp_product) continue;
                
                $camps[] = array(
                    'id' => $product->ID,
                    'type' => 'product',
                    'name' => $product->post_title,
                    'description' => wp_trim_words($product->post_excerpt, 20),
                    'url' => get_permalink($product->ID),
                    'image' => get_the_post_thumbnail_url($product->ID, 'medium'),
                    'price' => $ptp_product->get_price(),
                    'regular_price' => $ptp_product->get_regular_price(),
                    'formatted_price' => $ptp_product->get_price_html(),
                    'start_date' => get_post_meta($product->ID, '_camp_start_date', true),
                    'end_date' => get_post_meta($product->ID, '_camp_end_date', true),
                    'location' => get_post_meta($product->ID, '_camp_location', true),
                    'ages' => get_post_meta($product->ID, '_camp_ages', true),
                    'spots_left' => self::get_spots_left($product->ID),
                    'trainer_ids' => get_post_meta($product->ID, '_camp_trainer_ids', true),
                );
            }
            
            return $camps;
        }
        
        return array();
    }
    
    /**
     * Get spots left for a camp
     */
    private static function get_spots_left($product_id) {
        $ptp_product = ptp_get_camp_product($product_id);
        if (!$ptp_product) return null;
        
        if ($ptp_product->managing_stock()) {
            return $ptp_product->get_stock_quantity();
        }
        
        return null;
    }
    
    /**
     * Check if user is a training family
     */
    public static function is_training_family($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) return false;
        
        global $wpdb;
        
        // Check if they have any completed training sessions
        $has_training = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            WHERE p.user_id = %d AND b.status IN ('completed', 'confirmed')
        ", $user_id));
        
        return $has_training > 0;
    }
    
    /**
     * Get training family discount code
     */
    public static function get_training_family_code($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id || !self::is_training_family($user_id)) {
            return null;
        }
        
        // Generate or retrieve existing code
        $code = get_user_meta($user_id, 'ptp_training_family_code', true);
        
        if (!$code) {
            $code = 'PTPFAM' . strtoupper(substr(md5($user_id . 'training'), 0, 6));
            update_user_meta($user_id, 'ptp_training_family_code', $code);
            
            // Create PTP Native coupon if it doesn't exist
            if (false && !ptp_get_coupon_id_by_code($code)) {
                $coupon = ptp_create_coupon();
                $coupon->set_code($code);
                $coupon->set_discount_type('percent');
                $coupon->set_amount(self::TRAINING_FAMILY_DISCOUNT);
                $coupon->set_individual_use(false);
                $coupon->set_product_categories(array(
                    get_term_by('slug', 'camps', 'product_cat')->term_id ?? 0,
                    get_term_by('slug', 'clinics', 'product_cat')->term_id ?? 0,
                ));
                $coupon->set_usage_limit_per_user(0); // Unlimited
                $coupon->set_description('Training Family Discount');
                $coupon->save();
            }
        }
        
        return $code;
    }
    
    /**
     * Get parent's session count
     */
    private static function get_parent_session_count($user_id) {
        global $wpdb;
        
        return (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            WHERE p.user_id = %d AND b.status = 'completed'
        ", $user_id));
    }
    
    /**
     * Get parent's trainer(s)
     */
    private static function get_parent_trainers($user_id) {
        global $wpdb;
        
        return $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT b.trainer_id FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            WHERE p.user_id = %d AND b.status IN ('completed', 'confirmed')
        ", $user_id));
    }
    
    /**
     * ==========================================
     * DISPLAY: Trainer Profile
     * ==========================================
     */
    
    /**
     * Display camps on trainer profile (main section)
     */
    public function display_trainer_camps($trainer_id = null) {
        if (!$trainer_id) {
            global $ptp_trainer;
            $trainer_id = $ptp_trainer->id ?? null;
        }
        
        if (!$trainer_id) return;
        
        $camps = self::get_upcoming_camps(array(
            'trainer_id' => $trainer_id,
            'limit' => 3
        ));
        
        if (empty($camps)) {
            // Show general camps if trainer has none
            $camps = self::get_upcoming_camps(array('limit' => 2));
        }
        
        if (empty($camps)) return;
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT display_name FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        $trainer_name = $trainer->display_name ?? 'this trainer';
        $first_name = explode(' ', $trainer_name)[0];
        
        ?>
        <div class="ptp-trainer-camps-section" style="margin:40px 0;padding:30px;background:#FFFBEB;border:2px solid #FCB900;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
                <span style="font-size:28px;">🏕️</span>
                <div>
                    <h3 style="font-family:Oswald,sans-serif;font-size:20px;text-transform:uppercase;margin:0;letter-spacing:1px;">
                        Train With <?php echo esc_html($first_name); ?> All Week
                    </h3>
                    <p style="margin:4px 0 0;color:#666;font-size:14px;">
                        <?php echo esc_html($first_name); ?> coaches at these upcoming camps
                    </p>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;">
                <?php foreach ($camps as $camp): ?>
                <a href="<?php echo esc_url($camp['url']); ?>" 
                   class="ptp-camp-card"
                   style="display:block;background:#fff;border:2px solid #E5E5E5;text-decoration:none;color:inherit;transition:all 0.2s;">
                    <?php if ($camp['image']): ?>
                    <div style="height:140px;overflow:hidden;">
                        <img src="<?php echo esc_url($camp['image']); ?>" alt="" 
                             style="width:100%;height:100%;object-fit:cover;">
                    </div>
                    <?php endif; ?>
                    <div style="padding:16px;">
                        <?php if ($camp['spots_left'] && $camp['spots_left'] < 10): ?>
                        <span style="display:inline-block;background:#DC2626;color:#fff;padding:2px 8px;font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:8px;">
                            Only <?php echo $camp['spots_left']; ?> spots left
                        </span>
                        <?php endif; ?>
                        
                        <h4 style="font-family:Oswald,sans-serif;font-size:16px;margin:0 0 8px;line-height:1.3;">
                            <?php echo esc_html($camp['name']); ?>
                        </h4>
                        
                        <?php if ($camp['start_date']): ?>
                        <div style="font-size:13px;color:#666;margin-bottom:8px;">
                            📅 <?php echo date('M j', strtotime($camp['start_date'])); ?>
                            <?php if ($camp['end_date'] && $camp['end_date'] !== $camp['start_date']): ?>
                            - <?php echo date('M j', strtotime($camp['end_date'])); ?>
                            <?php endif; ?>
                            <?php if ($camp['location']): ?>
                            <br>📍 <?php echo esc_html($camp['location']); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:18px;font-weight:700;">$<?php echo number_format($camp['price']); ?></span>
                            <?php if (self::is_training_family()): ?>
                            <span style="background:#D1FAE5;color:#065F46;padding:4px 8px;font-size:11px;font-weight:600;">
                                <?php echo self::TRAINING_FAMILY_DISCOUNT; ?>% OFF FOR YOU
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <?php if (self::is_training_family()): 
                $code = self::get_training_family_code();
            ?>
            <div style="margin-top:20px;padding:16px;background:#D1FAE5;border:2px solid #10B981;text-align:center;">
                <p style="margin:0 0 8px;font-weight:600;color:#065F46;">
                    🎉 Training Family Discount: <?php echo self::TRAINING_FAMILY_DISCOUNT; ?>% OFF all camps!
                </p>
                <p style="margin:0;font-size:13px;color:#047857;">
                    Use code <strong style="background:#fff;padding:2px 8px;font-family:monospace;"><?php echo esc_html($code); ?></strong> at checkout
                </p>
            </div>
            <?php endif; ?>
            
            <style>
                .ptp-camp-card:hover{border-color:#FCB900;transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,0.1)}
            </style>
        </div>
        <?php
    }
    
    /**
     * Display camps in trainer sidebar
     */
    public function display_trainer_camps_sidebar($trainer_id = null) {
        if (!$trainer_id) {
            global $ptp_trainer;
            $trainer_id = $ptp_trainer->id ?? null;
        }
        
        $camps = self::get_upcoming_camps(array(
            'trainer_id' => $trainer_id,
            'limit' => 1
        ));
        
        if (empty($camps)) return;
        
        $camp = $camps[0];
        ?>
        <div class="ptp-sidebar-camp" style="background:#FFFBEB;border:2px solid #FCB900;padding:16px;margin-top:20px;">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:#B45309;margin-bottom:8px;">
                🏕️ Upcoming Camp
            </div>
            <h4 style="font-family:Oswald,sans-serif;font-size:14px;margin:0 0 8px;line-height:1.3;">
                <?php echo esc_html($camp['name']); ?>
            </h4>
            <?php if ($camp['start_date']): ?>
            <div style="font-size:12px;color:#666;margin-bottom:12px;">
                <?php echo date('M j', strtotime($camp['start_date'])); ?>
                <?php if ($camp['end_date'] && $camp['end_date'] !== $camp['start_date']): ?>
                - <?php echo date('M j', strtotime($camp['end_date'])); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <a href="<?php echo esc_url($camp['url']); ?>" 
               style="display:block;background:#FCB900;color:#0A0A0A;text-align:center;padding:10px;font-weight:700;font-size:13px;text-decoration:none;text-transform:uppercase;">
                Learn More
            </a>
        </div>
        <?php
    }
    
    /**
     * ==========================================
     * DISPLAY: Post-Booking
     * ==========================================
     */
    
    /**
     * Display camp offer after booking confirmation
     */
    public function display_post_booking_camps($booking_id, $trainer_id = null) {
        if (!$trainer_id) {
            global $wpdb;
            $trainer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT trainer_id FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                $booking_id
            ));
        }
        
        $camps = self::get_upcoming_camps(array(
            'trainer_id' => $trainer_id,
            'limit' => 2
        ));
        
        if (empty($camps)) {
            $camps = self::get_upcoming_camps(array('limit' => 2));
        }
        
        if (empty($camps)) return;
        
        ?>
        <div class="ptp-post-booking-camps" style="margin-top:30px;padding:24px;background:linear-gradient(135deg,#FFFBEB 0%,#FEF3C7 100%);border:2px solid #FCB900;">
            <h3 style="font-family:Oswald,sans-serif;font-size:18px;text-transform:uppercase;margin:0 0 8px;display:flex;align-items:center;gap:10px;">
                <span>🔥</span> Keep The Momentum Going
            </h3>
            <p style="margin:0 0 20px;color:#666;">
                Book a camp and train with your coach all week long!
            </p>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
                <?php foreach ($camps as $camp): ?>
                <a href="<?php echo esc_url($camp['url']); ?>" 
                   style="display:flex;gap:12px;background:#fff;padding:12px;border:2px solid #E5E5E5;text-decoration:none;color:inherit;transition:border-color 0.2s;">
                    <?php if ($camp['image']): ?>
                    <img src="<?php echo esc_url($camp['image']); ?>" alt="" 
                         style="width:60px;height:60px;object-fit:cover;flex-shrink:0;">
                    <?php endif; ?>
                    <div style="min-width:0;">
                        <div style="font-weight:700;font-size:14px;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo esc_html($camp['name']); ?>
                        </div>
                        <div style="font-size:12px;color:#666;">
                            <?php if ($camp['start_date']): ?>
                            <?php echo date('M j', strtotime($camp['start_date'])); ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-weight:700;color:#059669;font-size:13px;">
                            $<?php echo number_format($camp['price']); ?>
                            <?php if (self::is_training_family()): ?>
                            <span style="text-decoration:line-through;color:#999;font-weight:400;">
                                $<?php echo number_format($camp['price'] / (1 - self::TRAINING_FAMILY_DISCOUNT / 100)); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <?php if (self::is_training_family()): 
                $code = self::get_training_family_code();
            ?>
            <div style="margin-top:16px;text-align:center;font-size:13px;color:#065F46;font-weight:600;">
                Use code <span style="background:#fff;padding:2px 8px;font-family:monospace;border:1px solid #10B981;"><?php echo esc_html($code); ?></span> for <?php echo self::TRAINING_FAMILY_DISCOUNT; ?>% off!
            </div>
            <?php endif; ?>
        </div>
        
        <style>
            .ptp-post-booking-camps a:hover{border-color:#FCB900}
        </style>
        <?php
    }
    
    /**
     * ==========================================
     * DISPLAY: Parent Dashboard
     * ==========================================
     */
    
    /**
     * Display camp banner at top of parent dashboard
     */
    public function display_dashboard_camp_banner() {
        if (!is_user_logged_in() || !self::is_training_family()) return;
        
        // Check if dismissed recently
        $dismissed = get_user_meta(get_current_user_id(), 'ptp_camp_banner_dismissed', true);
        if ($dismissed && strtotime($dismissed) > strtotime('-7 days')) return;
        
        $camps = self::get_upcoming_camps(array('limit' => 1));
        if (empty($camps)) return;
        
        $camp = $camps[0];
        $code = self::get_training_family_code();
        $discount_price = $camp['price'] * (1 - self::TRAINING_FAMILY_DISCOUNT / 100);
        
        ?>
        <div class="ptp-dashboard-camp-banner" style="background:linear-gradient(135deg,#0A0A0A 0%,#1F1F1F 100%);color:#fff;padding:20px;margin-bottom:24px;position:relative;">
            <button onclick="dismissCampBanner()" style="position:absolute;top:10px;right:10px;background:none;border:none;color:#666;font-size:20px;cursor:pointer;">&times;</button>
            
            <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
                <?php if ($camp['image']): ?>
                <img src="<?php echo esc_url($camp['image']); ?>" alt="" 
                     style="width:100px;height:100px;object-fit:cover;border:2px solid #FCB900;">
                <?php endif; ?>
                
                <div style="flex:1;min-width:200px;">
                    <div style="color:#FCB900;font-size:12px;font-weight:700;text-transform:uppercase;margin-bottom:4px;">
                        🎉 Training Family Exclusive
                    </div>
                    <h3 style="font-family:Oswald,sans-serif;font-size:20px;margin:0 0 8px;text-transform:uppercase;">
                        <?php echo esc_html($camp['name']); ?>
                    </h3>
                    <p style="margin:0;font-size:14px;color:#9CA3AF;">
                        <?php if ($camp['start_date']): ?>
                        <?php echo date('F j', strtotime($camp['start_date'])); ?>
                        <?php if ($camp['end_date'] && $camp['end_date'] !== $camp['start_date']): ?>
                         - <?php echo date('j', strtotime($camp['end_date'])); ?>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($camp['location']): ?> • <?php echo esc_html($camp['location']); ?><?php endif; ?>
                    </p>
                </div>
                
                <div style="text-align:center;">
                    <div style="font-size:14px;text-decoration:line-through;color:#666;">$<?php echo number_format($camp['price']); ?></div>
                    <div style="font-size:28px;font-weight:700;color:#FCB900;">$<?php echo number_format($discount_price); ?></div>
                    <div style="font-size:11px;color:#10B981;">You save $<?php echo number_format($camp['price'] - $discount_price); ?>!</div>
                </div>
                
                <a href="<?php echo esc_url($camp['url']); ?>?discount_code=<?php echo esc_attr($code); ?>" 
                   style="background:#FCB900;color:#0A0A0A;padding:14px 28px;font-weight:700;font-size:14px;text-transform:uppercase;text-decoration:none;white-space:nowrap;">
                    Reserve Spot
                </a>
            </div>
        </div>
        
        <script>
        function dismissCampBanner() {
            document.querySelector('.ptp-dashboard-camp-banner').style.display = 'none';
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=ptp_dismiss_camp_banner&nonce=<?php echo wp_create_nonce('ptp_nonce'); ?>'
            });
        }
        </script>
        <?php
    }
    
    /**
     * Display camp card in dashboard sidebar
     */
    public function display_dashboard_camp_card() {
        $session_count = self::get_parent_session_count(get_current_user_id());
        
        // Only show if they've done at least 2 sessions
        if ($session_count < 2) return;
        
        $trainer_ids = self::get_parent_trainers(get_current_user_id());
        
        $camps = array();
        foreach ($trainer_ids as $tid) {
            $trainer_camps = self::get_upcoming_camps(array('trainer_id' => $tid, 'limit' => 1));
            $camps = array_merge($camps, $trainer_camps);
        }
        
        if (empty($camps)) {
            $camps = self::get_upcoming_camps(array('limit' => 1));
        }
        
        if (empty($camps)) return;
        
        $camp = $camps[0];
        ?>
        <div class="ptp-sidebar-card" style="background:#fff;border:2px solid #E5E5E5;padding:20px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                <span style="font-size:20px;">🏕️</span>
                <h4 style="font-family:Oswald,sans-serif;font-size:14px;margin:0;text-transform:uppercase;">
                    Your Trainer's Camp
                </h4>
            </div>
            
            <?php if ($camp['image']): ?>
            <img src="<?php echo esc_url($camp['image']); ?>" alt="" 
                 style="width:100%;height:120px;object-fit:cover;margin-bottom:12px;">
            <?php endif; ?>
            
            <h5 style="font-size:14px;font-weight:700;margin:0 0 8px;">
                <?php echo esc_html($camp['name']); ?>
            </h5>
            
            <div style="font-size:12px;color:#666;margin-bottom:12px;">
                <?php if ($camp['start_date']): ?>
                📅 <?php echo date('M j', strtotime($camp['start_date'])); ?>
                <?php endif; ?>
                <?php if ($camp['location']): ?>
                <br>📍 <?php echo esc_html($camp['location']); ?>
                <?php endif; ?>
            </div>
            
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <span style="font-size:18px;font-weight:700;">$<?php echo number_format($camp['price']); ?></span>
                <?php if (self::is_training_family()): ?>
                <span style="background:#D1FAE5;color:#065F46;padding:4px 8px;font-size:11px;font-weight:600;">
                    <?php echo self::TRAINING_FAMILY_DISCOUNT; ?>% OFF
                </span>
                <?php endif; ?>
            </div>
            
            <a href="<?php echo esc_url($camp['url']); ?>" 
               style="display:block;background:#FCB900;color:#0A0A0A;text-align:center;padding:12px;font-weight:700;font-size:13px;text-decoration:none;text-transform:uppercase;">
                View Camp
            </a>
        </div>
        <?php
    }
    
    /**
     * ==========================================
     * DISPLAY: My Training Page
     * ==========================================
     */
    
    /**
     * Display camps after session list
     */
    public function display_my_training_camps() {
        if (!is_user_logged_in()) return;
        
        $session_count = self::get_parent_session_count(get_current_user_id());
        
        // Show after 3+ sessions
        if ($session_count < 3) return;
        
        $trainer_ids = self::get_parent_trainers(get_current_user_id());
        $camps = self::get_upcoming_camps(array('limit' => 3));
        
        if (empty($camps)) return;
        
        $code = self::get_training_family_code();
        ?>
        <div class="ptp-my-training-camps" style="margin-top:40px;padding:24px;background:#F9FAFB;border:2px solid #E5E5E5;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3 style="font-family:Oswald,sans-serif;font-size:18px;margin:0;text-transform:uppercase;">
                        🏕️ Continue Your Player's Progress
                    </h3>
                    <p style="margin:4px 0 0;color:#666;font-size:14px;">
                        You've completed <?php echo $session_count; ?> sessions. Take it to the next level!
                    </p>
                </div>
                <?php if ($code): ?>
                <div style="background:#D1FAE5;border:1px solid #10B981;padding:8px 16px;text-align:center;">
                    <div style="font-size:11px;color:#065F46;font-weight:600;">YOUR DISCOUNT CODE</div>
                    <div style="font-family:monospace;font-size:16px;font-weight:700;color:#047857;"><?php echo esc_html($code); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                <?php foreach ($camps as $camp): ?>
                <a href="<?php echo esc_url($camp['url']); ?>" 
                   style="display:block;background:#fff;border:2px solid #E5E5E5;text-decoration:none;color:inherit;transition:all 0.2s;">
                    <?php if ($camp['image']): ?>
                    <img src="<?php echo esc_url($camp['image']); ?>" alt="" 
                         style="width:100%;height:120px;object-fit:cover;">
                    <?php endif; ?>
                    <div style="padding:12px;">
                        <h4 style="font-family:Oswald,sans-serif;font-size:14px;margin:0 0 6px;">
                            <?php echo esc_html($camp['name']); ?>
                        </h4>
                        <div style="font-size:12px;color:#666;margin-bottom:8px;">
                            <?php if ($camp['start_date']): ?>
                            <?php echo date('M j', strtotime($camp['start_date'])); ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-weight:700;">$<?php echo number_format($camp['price']); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <style>
            .ptp-my-training-camps a:hover{border-color:#FCB900;transform:translateY(-2px)}
        </style>
        <?php
    }
    
    /**
     * ==========================================
     * DISPLAY: After Review
     * ==========================================
     */
    
    /**
     * Display camp offer after review submission
     */
    public function display_post_review_camp_offer($review_id, $rating) {
        // Only show for 4+ star reviews
        if ($rating < 4) return;
        
        $camps = self::get_upcoming_camps(array('limit' => 1));
        if (empty($camps)) return;
        
        $camp = $camps[0];
        $code = self::get_training_family_code();
        ?>
        <div class="ptp-post-review-camp" style="margin-top:24px;padding:20px;background:#FFFBEB;border:2px solid #FCB900;text-align:center;">
            <h4 style="font-family:Oswald,sans-serif;font-size:16px;margin:0 0 8px;">
                🎉 Thanks for the great review!
            </h4>
            <p style="margin:0 0 16px;color:#666;font-size:14px;">
                Want even more training? Join us at camp!
            </p>
            
            <a href="<?php echo esc_url($camp['url']); ?>" 
               style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:12px 24px;font-weight:700;text-decoration:none;text-transform:uppercase;font-size:14px;">
                <?php echo esc_html($camp['name']); ?> - $<?php echo number_format($camp['price']); ?>
            </a>
            
            <?php if ($code): ?>
            <p style="margin:12px 0 0;font-size:12px;color:#065F46;">
                Use <strong><?php echo esc_html($code); ?></strong> for <?php echo self::TRAINING_FAMILY_DISCOUNT; ?>% off!
            </p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * ==========================================
     * DISPLAY: Checkout
     * ==========================================
     */
    
    /**
     * Display camp order bump on training checkout
     */
    public function display_checkout_camp_bump() {
        $camps = self::get_upcoming_camps(array('limit' => 1));
        if (empty($camps)) return;
        
        $camp = $camps[0];
        $camp_price = floatval($camp['price']);
        
        // Calculate potential bundle savings (15% off combined total)
        // We'll estimate based on typical training package
        $bundle_discount = 15;
        ?>
        <div class="ptp-checkout-camp-bump" style="margin:20px 0;padding:20px;background:linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);border:2px solid #FCB900;">
            <div style="display:flex;align-items:flex-start;gap:14px;">
                <input type="checkbox" id="add-camp-bump" name="add_camp" value="<?php echo $camp['id']; ?>" 
                       style="width:22px;height:22px;accent-color:#FCB900;margin-top:2px;flex-shrink:0;">
                <label for="add-camp-bump" style="flex:1;cursor:pointer;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="font-family:'Oswald',sans-serif;font-weight:700;font-size:16px;text-transform:uppercase;">
                            🎁 ADD CAMP & SAVE <?php echo $bundle_discount; ?>%
                        </span>
                    </div>
                    <div style="font-weight:600;font-size:15px;margin-bottom:4px;">
                        <?php echo esc_html($camp['name']); ?>
                    </div>
                    <div style="color:#666;font-size:13px;">
                        <?php if ($camp['start_date']): ?>
                        📅 <?php echo date('M j', strtotime($camp['start_date'])); ?>
                        <?php if ($camp['end_date'] && $camp['end_date'] !== $camp['start_date']): ?>
                         - <?php echo date('M j', strtotime($camp['end_date'])); ?>
                        <?php endif; ?>
                        &nbsp;•&nbsp;
                        <?php endif; ?>
                        +$<?php echo number_format($camp_price); ?> (before bundle discount)
                    </div>
                    <div style="margin-top:8px;background:#059669;color:#fff;padding:6px 12px;display:inline-block;font-size:12px;font-weight:700;">
                        BUNDLE DISCOUNT APPLIES AT CHECKOUT →
                    </div>
                </label>
            </div>
        </div>
        <?php
    }
    
    /**
     * ==========================================
     * DISPLAY: Sticky Banner
     * ==========================================
     */
    
    /**
     * Display sticky camp banner (seasonal promotion)
     */
    public function display_sticky_camp_banner() {
        // DISABLED: Sticky banners interfere with cart/checkout
        // Re-enable in the future if needed
        return;
        
        // Only show on PTP pages
        if (!$this->is_ptp_page()) return;
        
        // Check if dismissed
        if (isset($_COOKIE['ptp_camp_banner_dismissed'])) return;
        
        // Only show during camp season (March-August)
        $month = (int) date('n');
        if ($month < 3 || $month > 8) return;
        
        $camps = self::get_upcoming_camps(array('limit' => 1));
        if (empty($camps)) return;
        
        $camp = $camps[0];
        ?>
        <div id="ptp-sticky-camp-banner" style="position:fixed;bottom:0;left:0;right:0;background:#0A0A0A;color:#fff;padding:12px 20px;z-index:9999;display:flex;align-items:center;justify-content:center;gap:20px;flex-wrap:wrap;">
            <span style="font-size:16px;">🏕️</span>
            <span style="font-weight:700;"><?php echo esc_html($camp['name']); ?></span>
            <span style="color:#9CA3AF;">
                <?php if ($camp['start_date']): ?>
                <?php echo date('M j', strtotime($camp['start_date'])); ?>
                <?php endif; ?>
            </span>
            <?php if (self::is_training_family()): ?>
            <span style="background:#FCB900;color:#0A0A0A;padding:4px 10px;font-size:12px;font-weight:700;">
                <?php echo self::TRAINING_FAMILY_DISCOUNT; ?>% OFF FOR YOU
            </span>
            <?php endif; ?>
            <a href="<?php echo esc_url($camp['url']); ?>" 
               style="background:#FCB900;color:#0A0A0A;padding:8px 20px;font-weight:700;text-decoration:none;font-size:13px;text-transform:uppercase;">
                Register Now
            </a>
            <button onclick="dismissStickyBanner()" style="background:none;border:none;color:#666;font-size:20px;cursor:pointer;margin-left:10px;">&times;</button>
        </div>
        
        <div style="height:60px;"></div> <!-- Spacer -->
        
        <script>
        function dismissStickyBanner() {
            document.getElementById('ptp-sticky-camp-banner').style.display = 'none';
            document.cookie = 'ptp_camp_banner_dismissed=1; path=/; max-age=' + (7 * 24 * 60 * 60);
        }
        </script>
        <?php
    }
    
    /**
     * ==========================================
     * EMAIL: Add camps to emails
     * ==========================================
     */
    
    /**
     * Add camp CTA to booking confirmation email
     */
    public function add_camp_to_booking_email($content, $booking) {
        $trainer_id = $booking->trainer_id ?? null;
        
        $camps = self::get_upcoming_camps(array(
            'trainer_id' => $trainer_id,
            'limit' => 1
        ));
        
        if (empty($camps)) return $content;
        
        $camp = $camps[0];
        $code = self::get_training_family_code($booking->parent_user_id ?? null);
        
        $camp_html = "
        <div style='margin-top:30px;padding:20px;background:#FFFBEB;border:2px solid #FCB900;'>
            <h3 style='font-family:Oswald,sans-serif;margin:0 0 10px;font-size:18px;'>
                🏕️ Want More Training Time?
            </h3>
            <p style='margin:0 0 15px;color:#666;'>
                Your trainer coaches at <strong>{$camp['name']}</strong>. 
                Train all week and accelerate your progress!
            </p>
            <p style='margin:0 0 15px;'>
                <strong>" . date('M j', strtotime($camp['start_date'])) . "</strong> • 
                <strong>\${$camp['price']}</strong>
            </p>";
        
        if ($code) {
            $camp_html .= "
            <p style='margin:0 0 15px;background:#D1FAE5;padding:10px;color:#065F46;'>
                Use code <strong>{$code}</strong> for " . self::TRAINING_FAMILY_DISCOUNT . "% off!
            </p>";
        }
        
        $camp_html .= "
            <a href='{$camp['url']}' style='display:inline-block;background:#FCB900;color:#0A0A0A;padding:12px 24px;text-decoration:none;font-weight:700;'>
                LEARN MORE
            </a>
        </div>";
        
        return $content . $camp_html;
    }
    
    /**
     * Add camp CTA to post-session email
     */
    public function add_camp_to_post_session_email($content, $session) {
        $session_count = self::get_parent_session_count($session->parent_user_id ?? 0);
        
        // Different messaging based on session count
        if ($session_count >= 3) {
            $headline = "🔥 You've done {$session_count} sessions! Time for camp?";
            $subhead = "Take the next step with a full week of training.";
        } else {
            $headline = "🏕️ Continue the Momentum";
            $subhead = "Your trainer also coaches at our camps!";
        }
        
        $camps = self::get_upcoming_camps(array(
            'trainer_id' => $session->trainer_id ?? null,
            'limit' => 1
        ));
        
        if (empty($camps)) return $content;
        
        $camp = $camps[0];
        $code = self::get_training_family_code($session->parent_user_id ?? null);
        
        $camp_html = "
        <div style='margin-top:30px;padding:20px;background:#FFFBEB;border:2px solid #FCB900;'>
            <h3 style='font-family:Oswald,sans-serif;margin:0 0 10px;font-size:18px;'>
                {$headline}
            </h3>
            <p style='margin:0 0 15px;color:#666;'>{$subhead}</p>
            <p style='margin:0 0 15px;'>
                <strong>{$camp['name']}</strong><br>
                " . date('F j', strtotime($camp['start_date'])) . " • \${$camp['price']}
            </p>";
        
        if ($code) {
            $camp_html .= "
            <p style='margin:0 0 15px;background:#D1FAE5;padding:10px;color:#065F46;text-align:center;'>
                Your code: <strong style='font-size:18px;'>{$code}</strong> = " . self::TRAINING_FAMILY_DISCOUNT . "% OFF
            </p>";
        }
        
        $camp_html .= "
            <a href='{$camp['url']}' style='display:inline-block;background:#FCB900;color:#0A0A0A;padding:12px 24px;text-decoration:none;font-weight:700;'>
                SAVE MY SPOT
            </a>
        </div>";
        
        return $content . $camp_html;
    }
    
    /**
     * Add camp to review request email
     */
    public function add_camp_to_review_email($content, $session) {
        $camps = self::get_upcoming_camps(array('limit' => 1));
        if (empty($camps)) return $content;
        
        $camp = $camps[0];
        $code = self::get_training_family_code($session->parent_user_id ?? null);
        
        $camp_html = "
        <div style='margin-top:20px;padding:15px;background:#F9FAFB;border:1px solid #E5E5E5;text-align:center;'>
            <p style='margin:0 0 10px;font-size:14px;color:#666;'>
                P.S. Training families get <strong>" . self::TRAINING_FAMILY_DISCOUNT . "% off</strong> camps!
            </p>
            <a href='{$camp['url']}' style='color:#FCB900;font-weight:700;'>
                Check out {$camp['name']} →
            </a>
        </div>";
        
        return $content . $camp_html;
    }
    
    /**
     * Send dedicated camp email after 3rd session
     */
    public function maybe_send_camp_email_after_3rd_session($booking_id, $trainer_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, p.user_id as parent_user_id, p.display_name as parent_name, u.user_email
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             JOIN {$wpdb->prefix}users u ON p.user_id = u.ID
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) return;
        
        $session_count = self::get_parent_session_count($booking->parent_user_id);
        
        // Only on exactly 3rd session
        if ($session_count !== 3) return;
        
        // Check if already sent
        $already_sent = get_user_meta($booking->parent_user_id, 'ptp_camp_email_3rd_sent', true);
        if ($already_sent) return;
        
        $camps = self::get_upcoming_camps(array(
            'trainer_id' => $trainer_id,
            'limit' => 1
        ));
        
        if (empty($camps)) return;
        
        $camp = $camps[0];
        $code = self::get_training_family_code($booking->parent_user_id);
        $discount_price = $camp['price'] * (1 - self::TRAINING_FAMILY_DISCOUNT / 100);
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT display_name FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        $trainer_name = explode(' ', $trainer->display_name ?? 'Your trainer')[0];
        $parent_name = explode(' ', $booking->parent_name)[0];
        
        $subject = "🏕️ {$parent_name}, train with {$trainer_name} all week!";
        
        $message = "
        <div style='font-family:Inter,-apple-system,sans-serif;max-width:600px;margin:0 auto;'>
            <div style='background:#0A0A0A;padding:30px;text-align:center;'>
                <h1 style='color:#FCB900;font-family:Oswald,sans-serif;margin:0;font-size:28px;text-transform:uppercase;'>
                    You've Hit 3 Sessions! 🎉
                </h1>
            </div>
            
            <div style='padding:30px;'>
                <p style='font-size:16px;'>Hi {$parent_name},</p>
                
                <p style='font-size:16px;line-height:1.6;'>
                    You've completed <strong>3 training sessions</strong> with {$trainer_name} and we can already see the progress!
                </p>
                
                <p style='font-size:16px;line-height:1.6;'>
                    Want to take it to the next level? {$trainer_name} is coaching at our upcoming camp:
                </p>
                
                <div style='background:#FFFBEB;border:2px solid #FCB900;padding:24px;margin:24px 0;text-align:center;'>
                    <h2 style='font-family:Oswald,sans-serif;margin:0 0 10px;font-size:22px;'>
                        {$camp['name']}
                    </h2>
                    <p style='margin:0 0 15px;color:#666;'>
                        " . date('F j', strtotime($camp['start_date'])) . "
                        " . ($camp['end_date'] !== $camp['start_date'] ? ' - ' . date('j', strtotime($camp['end_date'])) : '') . "
                        " . ($camp['location'] ? " • {$camp['location']}" : "") . "
                    </p>
                    
                    <div style='margin:20px 0;'>
                        <span style='font-size:18px;text-decoration:line-through;color:#999;'>\${$camp['price']}</span>
                        <span style='font-size:28px;font-weight:700;color:#059669;margin-left:10px;'>\$" . number_format($discount_price) . "</span>
                    </div>
                    
                    <p style='margin:0 0 20px;background:#D1FAE5;padding:12px;color:#065F46;'>
                        Your exclusive code: <strong style='font-size:20px;'>{$code}</strong>
                    </p>
                    
                    <a href='{$camp['url']}?discount_code={$code}' 
                       style='display:inline-block;background:#FCB900;color:#0A0A0A;padding:16px 40px;text-decoration:none;font-weight:700;font-size:16px;text-transform:uppercase;'>
                        RESERVE MY SPOT
                    </a>
                </div>
                
                <p style='font-size:14px;color:#666;'>
                    <strong>Why camp?</strong>
                </p>
                <ul style='color:#666;font-size:14px;'>
                    <li>5 days of intensive training vs 1 hour sessions</li>
                    <li>Build deeper connection with {$trainer_name}</li>
                    <li>See accelerated improvement</li>
                    <li>Make friends with similar skill level</li>
                </ul>
                
                <p style='font-size:14px;color:#666;margin-top:24px;'>
                    See you on the field!<br>
                    <strong>The PTP Team</strong>
                </p>
            </div>
        </div>";
        
        // Send email
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($booking->user_email, $subject, $message, $headers);
        
        // Mark as sent
        update_user_meta($booking->parent_user_id, 'ptp_camp_email_3rd_sent', current_time('mysql'));
    }
    
    /**
     * Send weekly digest to training families
     */
    public function send_camp_digest_to_training_families() {
        global $wpdb;
        
        // Get training families who haven't bought a camp recently
        $families = $wpdb->get_results("
            SELECT DISTINCT p.user_id, p.display_name, u.user_email
            FROM {$wpdb->prefix}ptp_parents p
            JOIN {$wpdb->prefix}users u ON p.user_id = u.ID
            JOIN {$wpdb->prefix}ptp_bookings b ON b.parent_id = p.id
            WHERE b.status = 'completed'
            AND b.session_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
            GROUP BY p.user_id
            HAVING COUNT(b.id) >= 3
        ");
        
        $camps = self::get_upcoming_camps(array('limit' => 3));
        if (empty($camps)) return;
        
        foreach ($families as $family) {
            // Check if opted out
            if (get_user_meta($family->user_id, 'ptp_camp_digest_optout', true)) continue;
            
            // Check if sent this week
            $last_sent = get_user_meta($family->user_id, 'ptp_camp_digest_last', true);
            if ($last_sent && strtotime($last_sent) > strtotime('-6 days')) continue;
            
            $code = self::get_training_family_code($family->user_id);
            $first_name = explode(' ', $family->display_name)[0];
            
            $subject = "🏕️ {$first_name}, your training family camp picks this week";
            
            // Build email... (simplified)
            $message = $this->build_digest_email($family, $camps, $code);
            
            $headers = array('Content-Type: text/html; charset=UTF-8');
            wp_mail($family->user_email, $subject, $message, $headers);
            
            update_user_meta($family->user_id, 'ptp_camp_digest_last', current_time('mysql'));
        }
    }
    
    /**
     * Build digest email HTML
     */
    private function build_digest_email($family, $camps, $code) {
        $first_name = explode(' ', $family->display_name)[0];
        
        $camps_html = '';
        foreach ($camps as $camp) {
            $discount_price = $camp['price'] * (1 - self::TRAINING_FAMILY_DISCOUNT / 100);
            $camps_html .= "
            <div style='border:1px solid #E5E5E5;margin-bottom:15px;'>
                <div style='padding:15px;'>
                    <h3 style='margin:0 0 8px;font-size:16px;'>{$camp['name']}</h3>
                    <p style='margin:0 0 8px;color:#666;font-size:14px;'>
                        " . date('M j', strtotime($camp['start_date'])) . "
                    </p>
                    <p style='margin:0;'>
                        <span style='text-decoration:line-through;color:#999;'>\${$camp['price']}</span>
                        <strong style='color:#059669;margin-left:8px;'>\$" . number_format($discount_price) . "</strong>
                    </p>
                </div>
            </div>";
        }
        
        return "
        <div style='font-family:Inter,-apple-system,sans-serif;max-width:600px;margin:0 auto;'>
            <div style='background:#0A0A0A;padding:20px;text-align:center;'>
                <h1 style='color:#FCB900;font-family:Oswald,sans-serif;margin:0;font-size:22px;'>
                    THIS WEEK'S CAMPS
                </h1>
            </div>
            
            <div style='padding:20px;'>
                <p>Hi {$first_name}!</p>
                <p>As a training family, you get <strong>" . self::TRAINING_FAMILY_DISCOUNT . "% off</strong> all camps with code <strong>{$code}</strong>.</p>
                
                {$camps_html}
                
                <p style='text-align:center;margin-top:20px;'>
                    <a href='" . home_url('/ptp-find-a-camp/') . "' style='background:#FCB900;color:#0A0A0A;padding:12px 24px;text-decoration:none;font-weight:700;'>
                        VIEW ALL CAMPS
                    </a>
                </p>
            </div>
        </div>";
    }
    
    /**
     * ==========================================
     * DISCOUNTS & TRACKING
     * ==========================================
     */
    
    /**
     * Apply training family discount automatically
     */
    public function apply_training_family_discount($cart) {
        if (!self::is_training_family()) return;
        
        // Check if cart has camp products
        $has_camp = false;
        foreach ($cart->get_cart() as $item) {
            $product_cats = wp_get_post_terms($item['product_id'], 'product_cat', array('fields' => 'slugs'));
            if (array_intersect($product_cats, array('camps', 'clinics', 'camp', 'clinic'))) {
                $has_camp = true;
                break;
            }
        }
        
        if (!$has_camp) return;
        
        // Check if coupon already applied
        $applied_coupons = $cart->get_applied_coupons();
        $family_code = self::get_training_family_code();
        
        if ($family_code && !in_array(strtolower($family_code), array_map('strtolower', $applied_coupons))) {
            // Show notice but don't auto-apply (let them use their code)
            if (!ptp_has_notice('You\'re a training family! Use code ' . $family_code . ' for ' . self::TRAINING_FAMILY_DISCOUNT . '% off camps.', 'notice')) {
                ptp_add_notice('You\'re a training family! Use code <strong>' . $family_code . '</strong> for ' . self::TRAINING_FAMILY_DISCOUNT . '% off camps.', 'notice');
            }
        }
    }
    
    /**
     * Show training family price on product pages
     */
    public function show_training_family_price($price_html, $product) {
        if (!self::is_training_family()) return $price_html;
        
        $product_cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'slugs'));
        if (!array_intersect($product_cats, array('camps', 'clinics', 'camp', 'clinic'))) {
            return $price_html;
        }
        
        $regular_price = $product->get_price();
        $family_price = $regular_price * (1 - self::TRAINING_FAMILY_DISCOUNT / 100);
        
        return '<del>' . ptp_format_price($regular_price) . '</del> <ins style="color:#059669;font-weight:700;">' . ptp_format_price($family_price) . '</ins> <span style="background:#D1FAE5;color:#065F46;padding:2px 6px;font-size:11px;font-weight:600;">' . self::TRAINING_FAMILY_DISCOUNT . '% OFF</span>';
    }
    
    /**
     * Track camp conversion from training family
     */
    public function track_camp_conversion($order_id) {
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order) return;
        
        $user_id = $order->get_user_id();
        if (!$user_id || !self::is_training_family($user_id)) return;
        
        // Check if order has camp products
        foreach ($order->get_items() as $item) {
            $product_cats = wp_get_post_terms($item->get_product_id(), 'product_cat', array('fields' => 'slugs'));
            if (array_intersect($product_cats, array('camps', 'clinics', 'camp', 'clinic'))) {
                // Log conversion
                global $wpdb;
                $wpdb->insert($wpdb->prefix . 'ptp_crosssell_clicks', array(
                    'user_id' => $user_id,
                    'session_id' => $this->get_session_id(),
                    'source_type' => 'training',
                    'target_type' => 'camp',
                    'target_id' => $item->get_product_id(),
                    'context' => 'training_family_conversion',
                    'converted' => 1,
                    'converted_at' => current_time('mysql'),
                ));
                
                // Fire action for analytics
                do_action('ptp_camp_crosssell_converted', $user_id, $item->get_product_id(), $order_id);
                
                break;
            }
        }
    }
    
    /**
     * ==========================================
     * AJAX & HELPERS
     * ==========================================
     */
    
    /**
     * AJAX: Get camp recommendations
     */
    public function ajax_get_camp_recommendations() {
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $limit = intval($_POST['limit'] ?? 4);
        
        $camps = self::get_upcoming_camps(array(
            'trainer_id' => $trainer_id ?: null,
            'limit' => $limit,
        ));
        
        wp_send_json_success(array(
            'camps' => $camps,
            'is_training_family' => self::is_training_family(),
            'discount_code' => self::get_training_family_code(),
            'discount_percent' => self::TRAINING_FAMILY_DISCOUNT,
        ));
    }
    
    /**
     * AJAX: Dismiss banner
     */
    public function ajax_dismiss_banner() {
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'ptp_camp_banner_dismissed', current_time('mysql'));
        }
        wp_send_json_success();
    }
    
    /**
     * Check if on PTP page
     */
    private function is_ptp_page() {
        global $post;
        
        $ptp_slugs = array('trainer', 'book', 'my-training', 'training', 'parent-dashboard');
        
        if ($post && in_array($post->post_name, $ptp_slugs)) {
            return true;
        }
        
        if (isset($_GET['trainer']) || isset($_GET['booking'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get session ID
     */
    private function get_session_id() {
        if (!isset($_COOKIE['ptp_session'])) {
            return wp_generate_uuid4();
        }
        return sanitize_text_field($_COOKIE['ptp_session']);
    }
    
    /**
     * ==========================================
     * SHORTCODES
     * ==========================================
     */
    
    /**
     * Shortcode: [ptp_camp_crosssell]
     */
    public function shortcode_camp_crosssell($atts) {
        $atts = shortcode_atts(array(
            'trainer_id' => '',
            'limit' => 3,
            'style' => 'cards', // cards, list, banner
        ), $atts);
        
        $camps = self::get_upcoming_camps(array(
            'trainer_id' => $atts['trainer_id'] ?: null,
            'limit' => intval($atts['limit']),
        ));
        
        if (empty($camps)) return '';
        
        ob_start();
        
        if ($atts['style'] === 'banner') {
            $this->render_camp_banner($camps[0]);
        } else {
            $this->render_camp_cards($camps);
        }
        
        return ob_get_clean();
    }
    
    /**
     * Shortcode: [ptp_trainer_camps trainer_id="123"]
     */
    public function shortcode_trainer_camps($atts) {
        $atts = shortcode_atts(array('trainer_id' => ''), $atts);
        
        if (!$atts['trainer_id']) return '';
        
        ob_start();
        $this->display_trainer_camps(intval($atts['trainer_id']));
        return ob_get_clean();
    }
    
    /**
     * Shortcode: [ptp_camp_banner]
     */
    public function shortcode_camp_banner($atts) {
        $camps = self::get_upcoming_camps(array('limit' => 1));
        if (empty($camps)) return '';
        
        ob_start();
        $this->render_camp_banner($camps[0]);
        return ob_get_clean();
    }
    
    /**
     * Shortcode: [ptp_training_family_camps]
     */
    public function shortcode_training_family_camps($atts) {
        if (!self::is_training_family()) return '';
        
        $trainer_ids = self::get_parent_trainers(get_current_user_id());
        
        $camps = array();
        foreach ($trainer_ids as $tid) {
            $trainer_camps = self::get_upcoming_camps(array('trainer_id' => $tid, 'limit' => 2));
            $camps = array_merge($camps, $trainer_camps);
        }
        
        if (empty($camps)) {
            $camps = self::get_upcoming_camps(array('limit' => 3));
        }
        
        if (empty($camps)) return '';
        
        ob_start();
        ?>
        <div class="ptp-training-family-camps">
            <div style="background:#D1FAE5;border:2px solid #10B981;padding:16px;margin-bottom:20px;text-align:center;">
                <p style="margin:0;font-weight:600;color:#065F46;">
                    🎉 As a training family, you get <strong><?php echo self::TRAINING_FAMILY_DISCOUNT; ?>% off</strong> all camps!
                </p>
                <p style="margin:8px 0 0;font-size:13px;color:#047857;">
                    Your code: <strong style="font-family:monospace;font-size:15px;"><?php echo esc_html(self::get_training_family_code()); ?></strong>
                </p>
            </div>
            <?php $this->render_camp_cards($camps); ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render camp cards
     */
    private function render_camp_cards($camps) {
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;">
            <?php foreach ($camps as $camp): 
                $discount_price = self::is_training_family() 
                    ? $camp['price'] * (1 - self::TRAINING_FAMILY_DISCOUNT / 100) 
                    : $camp['price'];
            ?>
            <a href="<?php echo esc_url($camp['url']); ?>" 
               style="display:block;background:#fff;border:2px solid #E5E5E5;text-decoration:none;color:inherit;transition:all 0.2s;">
                <?php if ($camp['image']): ?>
                <img src="<?php echo esc_url($camp['image']); ?>" alt="" 
                     style="width:100%;height:150px;object-fit:cover;">
                <?php endif; ?>
                <div style="padding:16px;">
                    <?php if ($camp['spots_left'] && $camp['spots_left'] < 10): ?>
                    <span style="display:inline-block;background:#DC2626;color:#fff;padding:2px 8px;font-size:10px;font-weight:700;margin-bottom:8px;">
                        <?php echo $camp['spots_left']; ?> SPOTS LEFT
                    </span>
                    <?php endif; ?>
                    <h4 style="font-family:Oswald,sans-serif;font-size:16px;margin:0 0 8px;">
                        <?php echo esc_html($camp['name']); ?>
                    </h4>
                    <div style="font-size:13px;color:#666;margin-bottom:12px;">
                        <?php if ($camp['start_date']): ?>
                        📅 <?php echo date('M j', strtotime($camp['start_date'])); ?>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <?php if (self::is_training_family()): ?>
                        <div>
                            <span style="text-decoration:line-through;color:#999;font-size:14px;">$<?php echo number_format($camp['price']); ?></span>
                            <span style="font-size:18px;font-weight:700;color:#059669;margin-left:6px;">$<?php echo number_format($discount_price); ?></span>
                        </div>
                        <?php else: ?>
                        <span style="font-size:18px;font-weight:700;">$<?php echo number_format($camp['price']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <style>.ptp-training-family-camps a:hover{border-color:#FCB900;transform:translateY(-2px)}</style>
        <?php
    }
    
    /**
     * Render camp banner
     */
    private function render_camp_banner($camp) {
        $code = self::get_training_family_code();
        $discount_price = self::is_training_family() 
            ? $camp['price'] * (1 - self::TRAINING_FAMILY_DISCOUNT / 100) 
            : $camp['price'];
        ?>
        <div style="background:linear-gradient(135deg,#0A0A0A 0%,#1F1F1F 100%);color:#fff;padding:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <?php if ($camp['image']): ?>
            <img src="<?php echo esc_url($camp['image']); ?>" alt="" 
                 style="width:120px;height:120px;object-fit:cover;border:2px solid #FCB900;">
            <?php endif; ?>
            <div style="flex:1;min-width:200px;">
                <div style="color:#FCB900;font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:4px;">
                    🏕️ Upcoming Camp
                </div>
                <h3 style="font-family:Oswald,sans-serif;font-size:20px;margin:0 0 8px;text-transform:uppercase;">
                    <?php echo esc_html($camp['name']); ?>
                </h3>
                <p style="margin:0;font-size:14px;color:#9CA3AF;">
                    <?php if ($camp['start_date']): ?>
                    <?php echo date('F j', strtotime($camp['start_date'])); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div style="text-align:center;">
                <?php if (self::is_training_family()): ?>
                <div style="font-size:14px;text-decoration:line-through;color:#666;">$<?php echo number_format($camp['price']); ?></div>
                <?php endif; ?>
                <div style="font-size:28px;font-weight:700;color:#FCB900;">$<?php echo number_format($discount_price); ?></div>
            </div>
            <a href="<?php echo esc_url($camp['url']); ?><?php echo $code ? '?discount_code=' . esc_attr($code) : ''; ?>" 
               style="background:#FCB900;color:#0A0A0A;padding:14px 28px;font-weight:700;font-size:14px;text-transform:uppercase;text-decoration:none;">
                Register Now
            </a>
        </div>
        <?php
    }
}

// Initialize
PTP_Camp_Crosssell_Everywhere::instance();
