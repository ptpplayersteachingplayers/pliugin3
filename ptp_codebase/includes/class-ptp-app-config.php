<?php
/**
 * PTP App Config - Mobile App Configuration API
 * Provides dynamic theming, feature flags, and app content
 * 
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_App_Config {
    
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }
    
    public static function register_routes() {
        $ns = 'ptp/v1';
        
        // Full app config
        register_rest_route($ns, '/config', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_config'),
            'permission_callback' => '__return_true',
        ));
        
        // Theme/styling
        register_rest_route($ns, '/config/theme', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_theme'),
            'permission_callback' => '__return_true',
        ));
        
        // Feature flags
        register_rest_route($ns, '/config/features', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_features'),
            'permission_callback' => '__return_true',
        ));
        
        // App assets (images, logos)
        register_rest_route($ns, '/config/assets', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_assets'),
            'permission_callback' => '__return_true',
        ));
        
        // Locations/markets
        register_rest_route($ns, '/config/locations', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_locations'),
            'permission_callback' => '__return_true',
        ));
        
        // Onboarding screens
        register_rest_route($ns, '/config/onboarding', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_onboarding'),
            'permission_callback' => '__return_true',
        ));
        
        // FAQ
        register_rest_route($ns, '/config/faq', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_faq'),
            'permission_callback' => '__return_true',
        ));
        
        // Legal docs
        register_rest_route($ns, '/config/legal', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_legal'),
            'permission_callback' => '__return_true',
        ));
        
        // Contact info
        register_rest_route($ns, '/config/contact', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_contact'),
            'permission_callback' => '__return_true',
        ));
        
        // Version info
        register_rest_route($ns, '/config/version', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_version'),
            'permission_callback' => '__return_true',
        ));
        
        // Home screen content
        register_rest_route($ns, '/config/home', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_home_content'),
            'permission_callback' => '__return_true',
        ));
        
        // Specialties list
        register_rest_route($ns, '/config/specialties', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_specialties'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * Get full app configuration
     */
    public static function get_config($request) {
        $app_version = $request->get_header('X-App-Version');
        
        return rest_ensure_response(array(
            'app_name' => get_option('ptp_app_name', 'PTP Training'),
            'tagline' => get_option('ptp_tagline', 'Players Teaching Players'),
            'api_version' => PTP_VERSION,
            'theme' => self::get_theme_data(),
            'features' => self::get_features_data(),
            'assets' => self::get_assets_data(),
            'locations' => self::get_locations_data(),
            'settings' => self::get_settings_data(),
            'contact' => self::get_contact_data(),
            'legal' => self::get_legal_data(),
            'stripe' => array(
                'publishable_key' => PTP_Stripe::get_publishable_key(),
                'enabled' => PTP_Stripe::is_enabled(),
            ),
        ));
    }
    
    /**
     * Get theme configuration
     */
    public static function get_theme($request) {
        return rest_ensure_response(self::get_theme_data());
    }
    
    private static function get_theme_data() {
        return array(
            'colors' => array(
                'primary' => get_option('ptp_color_primary', '#FCB900'),
                'secondary' => get_option('ptp_color_secondary', '#0A0A0A'),
                'accent' => get_option('ptp_color_accent', '#FCB900'),
                'background' => '#FFFFFF',
                'surface' => '#F5F5F5',
                'error' => '#DC2626',
                'success' => '#16A34A',
                'warning' => '#F59E0B',
                'info' => '#3B82F6',
                'text' => array(
                    'primary' => '#0A0A0A',
                    'secondary' => '#666666',
                    'disabled' => '#999999',
                    'inverse' => '#FFFFFF',
                ),
            ),
            'typography' => array(
                'heading_family' => 'Oswald',
                'body_family' => 'Inter',
                'sizes' => array(
                    'xs' => 12,
                    'sm' => 14,
                    'base' => 16,
                    'lg' => 18,
                    'xl' => 20,
                    '2xl' => 24,
                    '3xl' => 30,
                    '4xl' => 36,
                    '5xl' => 48,
                ),
                'weights' => array(
                    'normal' => 400,
                    'medium' => 500,
                    'semibold' => 600,
                    'bold' => 700,
                    'extrabold' => 800,
                ),
            ),
            'spacing' => array(
                'xs' => 4,
                'sm' => 8,
                'md' => 16,
                'lg' => 24,
                'xl' => 32,
                '2xl' => 48,
            ),
            'border_radius' => array(
                'none' => 0,
                'sm' => 2,
                'md' => 4,
                'lg' => 8,
                'xl' => 12,
                'full' => 9999,
            ),
            'shadows' => array(
                'sm' => '0 1px 2px rgba(0,0,0,0.05)',
                'md' => '0 4px 6px rgba(0,0,0,0.1)',
                'lg' => '0 10px 15px rgba(0,0,0,0.1)',
                'xl' => '0 20px 25px rgba(0,0,0,0.15)',
            ),
            'components' => array(
                'button' => array(
                    'primary' => array(
                        'background' => '#FCB900',
                        'text' => '#0A0A0A',
                        'border' => '2px solid #0A0A0A',
                        'border_radius' => 0,
                        'text_transform' => 'uppercase',
                        'font_weight' => 700,
                        'letter_spacing' => 0.5,
                    ),
                    'secondary' => array(
                        'background' => 'transparent',
                        'text' => '#0A0A0A',
                        'border' => '2px solid #0A0A0A',
                        'border_radius' => 0,
                    ),
                ),
                'card' => array(
                    'background' => '#FFFFFF',
                    'border' => '2px solid #0A0A0A',
                    'border_radius' => 0,
                    'shadow' => '4px 4px 0 #FCB900',
                ),
                'input' => array(
                    'background' => '#FFFFFF',
                    'border' => '2px solid #0A0A0A',
                    'border_radius' => 0,
                    'focus_border' => '#FCB900',
                ),
            ),
            'dark_mode' => array(
                'colors' => array(
                    'primary' => '#FCB900',
                    'secondary' => '#FFFFFF',
                    'background' => '#0A0A0A',
                    'surface' => '#1A1A1A',
                    'text' => array(
                        'primary' => '#FFFFFF',
                        'secondary' => '#AAAAAA',
                    ),
                ),
            ),
        );
    }
    
    /**
     * Get feature flags
     */
    public static function get_features($request) {
        return rest_ensure_response(array('features' => self::get_features_data()));
    }
    
    private static function get_features_data() {
        return array(
            'private_training' => true,
            'group_sessions' => (bool) get_option('ptp_feature_groups', true),
            'training_plans' => (bool) get_option('ptp_feature_training_plans', true),
            'messaging' => true,
            'reviews' => true,
            'session_notes' => true,
            'player_progress' => true,
            'push_notifications' => (bool) get_option('ptp_push_enabled', false),
            'sms_notifications' => class_exists('PTP_SMS') && PTP_SMS::is_enabled(),
            'camps_clinics' => true,
            'recurring_sessions' => (bool) get_option('ptp_feature_recurring', true),
            'favorites' => true,
            'search_filters' => true,
            'calendar_sync' => (bool) get_option('ptp_feature_calendar_sync', false),
            'video_intro' => true,
            'social_login' => array(
                'google' => !empty(get_option('ptp_google_client_id')),
                'apple' => !empty(get_option('ptp_apple_client_id')),
            ),
            'stripe_connect' => PTP_Stripe::is_connect_enabled(),
            'escrow_payments' => (bool) get_option('ptp_escrow_enabled', true),
            'trainer_verification' => true,
            'background_check' => true,
            'safesport' => true,
        );
    }
    
    /**
     * Get app assets
     */
    public static function get_assets($request) {
        return rest_ensure_response(self::get_assets_data());
    }
    
    private static function get_assets_data() {
        return array(
            'logo' => PTP_Images::logo(),
            'logo_dark' => get_option('ptp_logo_dark_url', PTP_Images::logo()),
            'favicon' => get_site_icon_url(),
            'placeholder_avatar' => 'https://ui-avatars.com/api/?name=PTP&size=200&background=FCB900&color=0A0A0A&bold=true',
            'placeholder_trainer' => PTP_Images::for_context('auth_login'),
            'hero_images' => array(
                'home' => PTP_Images::get('BG7A1915'),
                'trainers' => PTP_Images::get('BG7A1874'),
                'apply' => PTP_Images::get('BG7A1642'),
                'login' => PTP_Images::get('BG7A1797'),
                'register' => PTP_Images::get('BG7A1790'),
            ),
            'gallery' => PTP_Images::all(),
            'camp_photos' => array_values(PTP_Images::CAMP_PHOTOS),
            'og_image' => PTP_Images::og_image(),
            'app_icon' => get_option('ptp_app_icon_url', ''),
            'splash_screen' => get_option('ptp_splash_url', PTP_Images::get('BG7A1915')),
        );
    }
    
    /**
     * Get supported locations
     */
    public static function get_locations($request) {
        return rest_ensure_response(array('locations' => self::get_locations_data()));
    }
    
    private static function get_locations_data() {
        return array(
            'states' => array(
                array('code' => 'PA', 'name' => 'Pennsylvania', 'active' => true),
                array('code' => 'NJ', 'name' => 'New Jersey', 'active' => true),
                array('code' => 'DE', 'name' => 'Delaware', 'active' => true),
                array('code' => 'MD', 'name' => 'Maryland', 'active' => true),
                array('code' => 'NY', 'name' => 'New York', 'active' => true),
            ),
            'markets' => array(
                array(
                    'id' => 'philadelphia',
                    'name' => 'Philadelphia Metro',
                    'state' => 'PA',
                    'latitude' => 39.9526,
                    'longitude' => -75.1652,
                    'radius' => 30,
                ),
                array(
                    'id' => 'south-jersey',
                    'name' => 'South Jersey',
                    'state' => 'NJ',
                    'latitude' => 39.8680,
                    'longitude' => -75.0365,
                    'radius' => 25,
                ),
                array(
                    'id' => 'delaware',
                    'name' => 'Delaware',
                    'state' => 'DE',
                    'latitude' => 39.1582,
                    'longitude' => -75.5244,
                    'radius' => 20,
                ),
            ),
            'default_radius' => 15,
            'max_radius' => 50,
        );
    }
    
    /**
     * Get settings
     */
    private static function get_settings_data() {
        return array(
            'platform_fee' => (float) get_option('ptp_platform_fee_percent', 25),
            'min_hourly_rate' => (float) get_option('ptp_min_hourly_rate', 50),
            'max_hourly_rate' => (float) get_option('ptp_max_hourly_rate', 150),
            'default_hourly_rate' => 70,
            'session_durations' => array(60, 90, 120),
            'default_duration' => 60,
            'booking_lead_time_hours' => 24,
            'cancellation_window_hours' => 24,
            'max_advance_booking_days' => 60,
            'session_types' => array(
                array('id' => 'private', 'name' => 'Private Training', 'description' => '1-on-1 session'),
                array('id' => 'group', 'name' => 'Group Session', 'description' => '2-4 players'),
            ),
            'skill_levels' => array(
                array('id' => 'beginner', 'name' => 'Beginner', 'description' => 'Just starting out'),
                array('id' => 'intermediate', 'name' => 'Intermediate', 'description' => 'Developing skills'),
                array('id' => 'advanced', 'name' => 'Advanced', 'description' => 'Competitive player'),
                array('id' => 'elite', 'name' => 'Elite', 'description' => 'High-level competitive'),
            ),
            'age_groups' => array(
                array('id' => 'u8', 'name' => 'U8', 'min_age' => 5, 'max_age' => 8),
                array('id' => 'u10', 'name' => 'U10', 'min_age' => 8, 'max_age' => 10),
                array('id' => 'u12', 'name' => 'U12', 'min_age' => 10, 'max_age' => 12),
                array('id' => 'u14', 'name' => 'U14', 'min_age' => 12, 'max_age' => 14),
                array('id' => 'u16', 'name' => 'U16', 'min_age' => 14, 'max_age' => 16),
                array('id' => 'u18', 'name' => 'U18', 'min_age' => 16, 'max_age' => 18),
            ),
        );
    }
    
    /**
     * Get onboarding screens
     */
    public static function get_onboarding($request) {
        return rest_ensure_response(array(
            'screens' => array(
                array(
                    'id' => 'welcome',
                    'title' => 'Train Like a Pro',
                    'subtitle' => 'Learn from current professional and NCAA Division 1 athletes',
                    'image' => PTP_Images::get('BG7A1915'),
                    'button_text' => 'Next',
                ),
                array(
                    'id' => 'trainers',
                    'title' => 'Elite Trainers',
                    'subtitle' => 'Our trainers compete at the highest levels - professional leagues and top college programs',
                    'image' => PTP_Images::get('BG7A1874'),
                    'button_text' => 'Next',
                ),
                array(
                    'id' => 'booking',
                    'title' => 'Easy Booking',
                    'subtitle' => 'Find trainers near you, check their availability, and book sessions instantly',
                    'image' => PTP_Images::get('BG7A1797'),
                    'button_text' => 'Next',
                ),
                array(
                    'id' => 'progress',
                    'title' => 'Track Progress',
                    'subtitle' => 'Get session notes, skill assessments, and personalized training plans',
                    'image' => PTP_Images::get('BG7A1642'),
                    'button_text' => 'Get Started',
                ),
            ),
            'role_selection' => array(
                array(
                    'id' => 'parent',
                    'title' => 'I\'m a Parent',
                    'subtitle' => 'Find trainers for my child',
                    'icon' => 'users',
                ),
                array(
                    'id' => 'trainer',
                    'title' => 'I\'m a Trainer',
                    'subtitle' => 'Become a PTP trainer',
                    'icon' => 'award',
                ),
            ),
        ));
    }
    
    /**
     * Get FAQ
     */
    public static function get_faq($request) {
        $category = $request->get_param('category');
        
        $faq = array(
            array(
                'id' => 1,
                'category' => 'general',
                'question' => 'What is PTP Training?',
                'answer' => 'PTP (Players Teaching Players) connects young athletes with elite trainers - current professional players and NCAA Division 1 athletes who provide personalized 1-on-1 training.',
            ),
            array(
                'id' => 2,
                'category' => 'general',
                'question' => 'Who are the trainers?',
                'answer' => 'Our trainers are active professional and college athletes. They compete at the highest levels including professional leagues and top NCAA Division 1 programs. All trainers are background checked and verified.',
            ),
            array(
                'id' => 3,
                'category' => 'booking',
                'question' => 'How do I book a session?',
                'answer' => 'Browse trainers, select one you like, choose an available time slot, and complete payment. You\'ll receive confirmation and can message your trainer before the session.',
            ),
            array(
                'id' => 4,
                'category' => 'booking',
                'question' => 'What is the cancellation policy?',
                'answer' => 'You can cancel for a full refund up to 24 hours before your session. Cancellations within 24 hours may be subject to a fee.',
            ),
            array(
                'id' => 5,
                'category' => 'payments',
                'question' => 'How do payments work?',
                'answer' => 'Payment is collected when you book. Funds are held securely until the session is completed. Trainers receive payment after the session.',
            ),
            array(
                'id' => 6,
                'category' => 'trainers',
                'question' => 'How do I become a trainer?',
                'answer' => 'Apply through our app or website. We review your playing background, conduct a background check, and verify SafeSport certification. Approved trainers set their own rates and availability.',
            ),
        );
        
        if ($category) {
            $faq = array_filter($faq, function($item) use ($category) {
                return $item['category'] === $category;
            });
        }
        
        return rest_ensure_response(array('faq' => array_values($faq)));
    }
    
    /**
     * Get legal documents
     */
    public static function get_legal($request) {
        return rest_ensure_response(self::get_legal_data());
    }
    
    private static function get_legal_data() {
        return array(
            'terms_url' => get_option('ptp_terms_url', site_url('/terms')),
            'privacy_url' => get_option('ptp_privacy_url', site_url('/privacy')),
            'refund_url' => get_option('ptp_refund_url', site_url('/refund-policy')),
            'trainer_agreement_url' => get_option('ptp_trainer_agreement_url', site_url('/trainer-agreement')),
            'parent_waiver_url' => get_option('ptp_waiver_url', site_url('/waiver')),
        );
    }
    
    /**
     * Get contact info
     */
    public static function get_contact($request) {
        return rest_ensure_response(self::get_contact_data());
    }
    
    private static function get_contact_data() {
        return array(
            'email' => get_option('ptp_contact_email', 'info@ptptraining.com'),
            'support_email' => get_option('ptp_support_email', 'support@ptptraining.com'),
            'phone' => get_option('ptp_contact_phone', ''),
            'website' => site_url(),
            'social' => array(
                'instagram' => get_option('ptp_instagram', 'ptp.training'),
                'facebook' => get_option('ptp_facebook', ''),
                'twitter' => get_option('ptp_twitter', ''),
                'youtube' => get_option('ptp_youtube', ''),
                'tiktok' => get_option('ptp_tiktok', ''),
            ),
        );
    }
    
    /**
     * Get version info
     */
    public static function get_version($request) {
        return rest_ensure_response(array(
            'api_version' => PTP_VERSION,
            'min_app_version' => get_option('ptp_min_app_version', '1.0.0'),
            'current_app_version' => get_option('ptp_current_app_version', '1.0.0'),
            'force_update' => (bool) get_option('ptp_force_update', false),
            'update_message' => get_option('ptp_update_message', 'A new version is available. Please update for the best experience.'),
            'maintenance_mode' => (bool) get_option('ptp_maintenance_mode', false),
            'maintenance_message' => get_option('ptp_maintenance_message', 'We\'re currently performing maintenance. Please try again shortly.'),
        ));
    }
    
    /**
     * Get home screen content
     */
    public static function get_home_content($request) {
        global $wpdb;
        
        // Get featured trainers
        $featured = $wpdb->get_results("
            SELECT t.*, u.user_email 
            FROM {$wpdb->prefix}ptp_trainers t
            JOIN {$wpdb->users} u ON t.user_id = u.ID
            WHERE t.status = 'active' AND t.is_featured = 1
            ORDER BY t.average_rating DESC
            LIMIT 6
        ");
        
        // Get stats
        $trainer_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'");
        $session_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE status IN ('completed', 'confirmed')");
        $review_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_reviews WHERE status = 'approved'");
        
        return rest_ensure_response(array(
            'hero' => array(
                'title' => get_option('ptp_hero_title', 'Train Like a Pro'),
                'subtitle' => get_option('ptp_hero_subtitle', 'Learn from current professional and NCAA Division 1 athletes'),
                'image' => PTP_Images::get('BG7A1915'),
                'cta_text' => 'Find Trainers',
                'cta_action' => 'trainers',
            ),
            'stats' => array(
                array('label' => 'Elite Trainers', 'value' => (int) $trainer_count),
                array('label' => 'Sessions Completed', 'value' => (int) $session_count),
                array('label' => '5-Star Reviews', 'value' => (int) $review_count),
            ),
            'featured_trainers' => array_map(function($t) {
                return array(
                    'id' => (int) $t->id,
                    'name' => $t->display_name,
                    'slug' => $t->slug,
                    'photo' => $t->photo_url ?: PTP_Images::avatar($t->display_name),
                    'headline' => $t->headline,
                    'hourly_rate' => (float) $t->hourly_rate,
                    'rating' => (float) $t->average_rating,
                    'review_count' => (int) $t->review_count,
                    'city' => $t->city,
                    'state' => $t->state,
                    'college' => $t->college,
                    'team' => $t->team,
                    'is_verified' => (bool) $t->is_verified,
                );
            }, $featured),
            'value_props' => array(
                array(
                    'icon' => 'star',
                    'title' => 'Elite Trainers',
                    'description' => 'Current Professional and D1 athletes',
                ),
                array(
                    'icon' => 'shield',
                    'title' => 'Safe & Verified',
                    'description' => 'Background checked & SafeSport certified',
                ),
                array(
                    'icon' => 'calendar',
                    'title' => 'Easy Booking',
                    'description' => 'Book sessions in seconds',
                ),
                array(
                    'icon' => 'trending-up',
                    'title' => 'Track Progress',
                    'description' => 'Session notes & skill assessments',
                ),
            ),
            'testimonials' => self::get_testimonials(),
        ));
    }
    
    private static function get_testimonials() {
        global $wpdb;
        
        $reviews = $wpdb->get_results("
            SELECT r.*, t.display_name as trainer_name, t.photo_url as trainer_photo
            FROM {$wpdb->prefix}ptp_reviews r
            JOIN {$wpdb->prefix}ptp_trainers t ON r.trainer_id = t.id
            WHERE r.status = 'approved' AND r.rating >= 5
            ORDER BY RAND()
            LIMIT 5
        ");
        
        return array_map(function($r) {
            return array(
                'id' => (int) $r->id,
                'text' => $r->review_text,
                'rating' => (float) $r->rating,
                'trainer_name' => $r->trainer_name,
                'trainer_photo' => $r->trainer_photo ?: PTP_Images::avatar($r->trainer_name),
                'created_at' => $r->created_at,
            );
        }, $reviews);
    }
    
    /**
     * Get specialties list
     */
    public static function get_specialties($request) {
        return rest_ensure_response(array(
            'specialties' => array(
                array('id' => 'dribbling', 'name' => 'Dribbling', 'icon' => 'zap'),
                array('id' => 'shooting', 'name' => 'Shooting', 'icon' => 'target'),
                array('id' => 'passing', 'name' => 'Passing', 'icon' => 'send'),
                array('id' => 'first-touch', 'name' => 'First Touch', 'icon' => 'hand'),
                array('id' => 'defending', 'name' => 'Defending', 'icon' => 'shield'),
                array('id' => 'goalkeeping', 'name' => 'Goalkeeping', 'icon' => 'box'),
                array('id' => 'speed', 'name' => 'Speed & Agility', 'icon' => 'activity'),
                array('id' => 'tactical', 'name' => 'Tactical IQ', 'icon' => 'compass'),
                array('id' => 'fitness', 'name' => 'Fitness', 'icon' => 'heart'),
                array('id' => 'mental', 'name' => 'Mental Game', 'icon' => 'brain'),
            ),
            'positions' => array(
                array('id' => 'goalkeeper', 'name' => 'Goalkeeper', 'abbreviation' => 'GK'),
                array('id' => 'defender', 'name' => 'Defender', 'abbreviation' => 'DEF'),
                array('id' => 'midfielder', 'name' => 'Midfielder', 'abbreviation' => 'MID'),
                array('id' => 'forward', 'name' => 'Forward', 'abbreviation' => 'FWD'),
            ),
            'playing_levels' => array(
                array('id' => 'mls', 'name' => 'MLS', 'tier' => 1),
                array('id' => 'usl-championship', 'name' => 'USL Championship', 'tier' => 2),
                array('id' => 'usl-league-one', 'name' => 'USL League One', 'tier' => 3),
                array('id' => 'mls-next-pro', 'name' => 'MLS NEXT Pro', 'tier' => 2),
                array('id' => 'ncaa-d1', 'name' => 'NCAA Division 1', 'tier' => 2),
                array('id' => 'ncaa-d2', 'name' => 'NCAA Division 2', 'tier' => 3),
                array('id' => 'ncaa-d3', 'name' => 'NCAA Division 3', 'tier' => 4),
                array('id' => 'mls-academy', 'name' => 'MLS Academy', 'tier' => 3),
            ),
        ));
    }
}

// Initialize
PTP_App_Config::init();
