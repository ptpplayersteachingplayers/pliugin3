<?php
/**
 * PTP REST API for Mobile App Integration
 * Provides JWT-authenticated endpoints for all platform features
 * 
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_REST_API {
    
    const NAMESPACE = 'ptp/v1';
    const JWT_SECRET_OPTION = 'ptp_jwt_secret';
    const REFRESH_SECRET_OPTION = 'ptp_refresh_secret';
    const TOKEN_EXPIRY = HOUR_IN_SECONDS; // SECURITY: Short-lived access tokens (1 hour)
    const REFRESH_EXPIRY = 7 * DAY_IN_SECONDS; // Refresh tokens last 7 days
    
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        
        // Generate JWT secret if not exists
        if (!get_option(self::JWT_SECRET_OPTION)) {
            update_option(self::JWT_SECRET_OPTION, wp_generate_password(64, true, true));
        }
    }
    
    /**
     * Register all REST routes
     */
    public static function register_routes() {
        
        // ============================================================
        // AUTHENTICATION
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/auth/login', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'login'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/auth/register', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'register'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/auth/register-trainer', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'register_trainer'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/auth/refresh', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'refresh_token'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/auth/me', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_current_user'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/auth/forgot-password', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'forgot_password'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/auth/update-password', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'update_password'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/auth/fcm-token', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'save_fcm_token'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        // ============================================================
        // TRAINERS (Public)
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/trainers', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainers'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/trainers/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/trainers/slug/(?P<slug>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer_by_slug'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/trainers/(?P<id>\d+)/availability', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer_availability'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/trainers/(?P<id>\d+)/reviews', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer_reviews'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/trainers/(?P<id>\d+)/group-sessions', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer_group_sessions'),
            'permission_callback' => '__return_true',
        ));
        
        // ============================================================
        // CHECKOUT (Public - Uses WP Cookie Auth)
        // These endpoints bypass admin-ajax.php for servers that block it
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/checkout/test', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'checkout_test'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
        
        register_rest_route(self::NAMESPACE, '/checkout/create-intent', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'checkout_create_intent'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/checkout/process', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'checkout_process'),
            'permission_callback' => '__return_true',
        ));
        
        // ============================================================
        // TRAINER DASHBOARD (Authenticated - Trainer Only)
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/trainer/profile', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer_profile'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/trainer/profile', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'update_trainer_profile'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/trainer/availability', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_my_availability'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/trainer/availability', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'update_availability'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/trainer/bookings', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer_bookings'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/trainer/earnings', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer_earnings'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/trainer/stats', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer_stats'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/trainer/players', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer_players'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/trainer/stripe/connect', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'trainer_stripe_connect'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/trainer/stripe/status', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'trainer_stripe_status'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        // ============================================================
        // BOOKINGS
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/bookings', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'create_booking'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/bookings/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_booking'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/bookings/(?P<id>\d+)/cancel', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'cancel_booking'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/bookings/(?P<id>\d+)/confirm', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'confirm_booking'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/bookings/(?P<id>\d+)/complete', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'complete_booking'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/bookings/(?P<id>\d+)/review', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'submit_review'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/bookings/(?P<id>\d+)/session-notes', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'add_session_notes'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        // ============================================================
        // GROUP SESSIONS
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/group-sessions', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_group_sessions'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/group-sessions', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'create_group_session'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/group-sessions/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_group_session'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/group-sessions/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'update_group_session'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/group-sessions/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'delete_group_session'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/group-sessions/(?P<id>\d+)/join', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'join_group_session'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/group-sessions/(?P<id>\d+)/leave', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'leave_group_session'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/group-sessions/(?P<id>\d+)/participants', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_group_participants'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/group-sessions/(?P<id>\d+)/complete', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'complete_group_session'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        // ============================================================
        // TRAINING PLANS
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/training-plans', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_training_plans'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/training-plans', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'create_training_plan'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/training-plans/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_training_plan'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/training-plans/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'update_training_plan'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/training-plans/(?P<id>\d+)/activate', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'activate_training_plan'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/training-plans/(?P<id>\d+)/milestones', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'add_milestone'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/training-plans/milestones/(?P<id>\d+)/complete', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'complete_milestone'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/skill-categories', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_skill_categories'),
            'permission_callback' => '__return_true',
        ));
        
        // ============================================================
        // ASSESSMENTS
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/assessments', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'create_assessment'),
            'permission_callback' => array(__CLASS__, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/assessments/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_assessment'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/players/(?P<id>\d+)/assessments', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_player_assessments'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/players/(?P<id>\d+)/progress', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_player_progress'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        // ============================================================
        // PLAYERS (Parent manages)
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/players', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_my_players'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/players', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'create_player'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/players/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_player'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/players/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'update_player'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/players/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array(__CLASS__, 'delete_player'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        // ============================================================
        // PARENT DASHBOARD
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/parent/profile', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_parent_profile'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/parent/profile', array(
            'methods' => 'PUT',
            'callback' => array(__CLASS__, 'update_parent_profile'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/parent/bookings', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_parent_bookings'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/parent/group-sessions', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_parent_group_sessions'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        // ============================================================
        // MESSAGING
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/conversations', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_conversations'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/conversations/(?P<id>\d+)/messages', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_messages'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/conversations/(?P<id>\d+)/messages', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'send_message'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/conversations/start', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'start_conversation'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/conversations/(?P<id>\d+)/read', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'mark_read'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        // ============================================================
        // PAYMENTS
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/payments/create-intent', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'create_payment_intent'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/payments/confirm', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'confirm_payment'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        // ============================================================
        // NOTIFICATIONS
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/notifications', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_notifications'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/notifications/(?P<id>\d+)/read', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'mark_notification_read'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/notifications/read-all', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'mark_all_notifications_read'),
            'permission_callback' => array(__CLASS__, 'check_auth'),
        ));
        
        // ============================================================
        // APP CONFIG (Public)
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/config', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_app_config'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/skill-categories', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_skill_categories'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * Get app configuration for mobile startup
     */
    public static function get_app_config($request) {
        $stripe_enabled = class_exists('PTP_Stripe') && PTP_Stripe::is_enabled();
        $stripe_test_mode = get_option('ptp_stripe_test_mode', 1);
        $stripe_key = $stripe_test_mode 
            ? get_option('ptp_stripe_test_publishable') 
            : get_option('ptp_stripe_live_publishable');
        
        return rest_ensure_response(array(
            'app_name' => 'PTP Training',
            'api_version' => '1.0',
            'platform_fee' => floatval(get_option('ptp_platform_fee_percent', 25)),
            'trainer_payout_percent' => 100 - floatval(get_option('ptp_platform_fee_percent', 25)),
            'currency' => 'USD',
            'min_booking_hours_ahead' => 2,
            'cancellation_hours' => 24,
            'stripe' => array(
                'enabled' => $stripe_enabled,
                'publishable_key' => $stripe_key,
                'test_mode' => (bool)$stripe_test_mode,
            ),
            'features' => array(
                'group_sessions' => true,
                'training_plans' => true,
                'skill_assessments' => true,
                'messaging' => true,
                'reviews' => true,
                'recurring_bookings' => true,
            ),
            'skill_levels' => array(
                'beginner' => 'Beginner',
                'intermediate' => 'Intermediate',
                'advanced' => 'Advanced',
                'elite' => 'Elite',
            ),
            'session_durations' => array(
                30 => '30 minutes',
                45 => '45 minutes',
                60 => '1 hour',
                90 => '1.5 hours',
                120 => '2 hours',
            ),
            'support_email' => get_option('ptp_from_email', get_option('admin_email')),
            'terms_url' => home_url('/terms'),
            'privacy_url' => home_url('/privacy'),
        ));
    }
    
    /**
     * Get skill categories for assessments
     */
    public static function get_skill_categories($request) {
        if (class_exists('PTP_Training_Plans')) {
            return rest_ensure_response(PTP_Training_Plans::get_skill_categories());
        }
        
        // Fallback
        return rest_ensure_response(array(
            'technical' => array(
                'name' => 'Technical Skills',
                'skills' => array(
                    'dribbling' => 'Dribbling',
                    'passing' => 'Passing',
                    'receiving' => 'First Touch/Receiving',
                    'shooting' => 'Shooting',
                    'heading' => 'Heading',
                    'ball_control' => 'Ball Control',
                ),
            ),
            'tactical' => array(
                'name' => 'Tactical Awareness',
                'skills' => array(
                    'positioning' => 'Positioning',
                    'decision_making' => 'Decision Making',
                    'game_reading' => 'Reading the Game',
                ),
            ),
            'physical' => array(
                'name' => 'Physical Attributes',
                'skills' => array(
                    'speed' => 'Speed',
                    'agility' => 'Agility',
                    'strength' => 'Strength',
                    'endurance' => 'Endurance',
                ),
            ),
            'mental' => array(
                'name' => 'Mental & Character',
                'skills' => array(
                    'focus' => 'Focus',
                    'confidence' => 'Confidence',
                    'work_ethic' => 'Work Ethic',
                ),
            ),
        ));
    }
    
    // ================================================================
    // JWT AUTHENTICATION
    // ================================================================
    
    /**
     * Generate JWT token
     */
    private static function generate_jwt($user_id, $user_type = 'parent') {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $user_id,
            'user_type' => $user_type,
            'iat' => time(),
            'exp' => time() + self::TOKEN_EXPIRY,
        ]);
        
        $base64Header = self::base64url_encode($header);
        $base64Payload = self::base64url_encode($payload);
        $signature = hash_hmac('sha256', "$base64Header.$base64Payload", get_option(self::JWT_SECRET_OPTION), true);
        $base64Signature = self::base64url_encode($signature);
        
        return "$base64Header.$base64Payload.$base64Signature";
    }
    
    /**
     * Verify JWT token
     */
    private static function verify_jwt($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return false;
        
        list($base64Header, $base64Payload, $base64Signature) = $parts;
        
        $signature = hash_hmac('sha256', "$base64Header.$base64Payload", get_option(self::JWT_SECRET_OPTION), true);
        $expectedSignature = self::base64url_encode($signature);
        
        if (!hash_equals($expectedSignature, $base64Signature)) return false;
        
        $payload = json_decode(self::base64url_decode($base64Payload), true);
        
        if ($payload['exp'] < time()) return false;
        
        return $payload;
    }
    
    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Get token from request
     */
    private static function get_token_from_request($request) {
        $auth_header = $request->get_header('Authorization');
        if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Permission check - any authenticated user
     */
    public static function check_auth($request) {
        $token = self::get_token_from_request($request);
        if (!$token) return false;
        
        $payload = self::verify_jwt($token);
        if (!$payload) return false;
        
        // Store payload in request for later use
        $request->set_param('jwt_payload', $payload);
        return true;
    }
    
    /**
     * Permission check - trainer only
     */
    public static function check_trainer_auth($request) {
        if (!self::check_auth($request)) return false;
        
        $payload = $request->get_param('jwt_payload');
        return $payload['user_type'] === 'trainer';
    }
    
    /**
     * Format user data for API response
     */
    public static function format_user_response($user) {
        global $wpdb;
        
        if (is_numeric($user)) {
            $user = get_user_by('ID', $user);
        }
        
        if (!$user) return null;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user->ID
        ));
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user->ID
        ));
        
        $user_type = $trainer ? 'trainer' : 'parent';
        $profile = $trainer ?: $parent;
        
        $data = array(
            'id' => $user->ID,
            'email' => $user->user_email,
            'type' => $user_type,
            'profile_id' => $profile ? $profile->id : null,
            'display_name' => $profile ? $profile->display_name : $user->display_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'avatar_url' => get_user_meta($user->ID, 'ptp_avatar_url', true) ?: ($trainer ? $trainer->photo_url : null),
            'phone' => $profile ? $profile->phone : null,
            'email_verified' => (bool) get_user_meta($user->ID, 'ptp_email_verified', true),
        );
        
        if ($trainer) {
            $data['trainer'] = array(
                'id' => (int) $trainer->id,
                'slug' => $trainer->slug,
                'headline' => $trainer->headline,
                'hourly_rate' => (float) $trainer->hourly_rate,
                'status' => $trainer->status,
                'is_verified' => (bool) $trainer->is_verified,
                'is_onboarded' => !empty($trainer->hourly_rate) && !empty($trainer->bio),
                'stripe_connected' => !empty($trainer->stripe_account_id),
                'average_rating' => (float) $trainer->average_rating,
                'review_count' => (int) $trainer->review_count,
                'total_sessions' => (int) $trainer->total_sessions,
            );
        }
        
        if ($parent) {
            $players = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d AND is_active = 1",
                $parent->id
            ));
            
            $data['players'] = array_map(function($p) {
                return array(
                    'id' => (int) $p->id,
                    'name' => $p->name,
                    'age' => (int) $p->age,
                    'skill_level' => $p->skill_level,
                    'position' => $p->position,
                );
            }, $players);
        }
        
        return $data;
    }
    
    /**
     * Get authenticated user info
     */
    public static function get_auth_user($request) {
        $payload = $request->get_param('jwt_payload');
        if (!$payload) {
            $token = self::get_token_from_request($request);
            $payload = self::verify_jwt($token);
        }
        return $payload;
    }
    
    // ================================================================
    // AUTH ENDPOINTS
    // ================================================================
    
    /**
     * Login
     */
    public static function login($request) {
        $email = sanitize_email($request->get_param('email'));
        $password = $request->get_param('password');
        
        if (empty($email) || empty($password)) {
            return new WP_Error('missing_credentials', 'Email and password required', array('status' => 400));
        }
        
        $user = wp_authenticate($email, $password);
        
        if (is_wp_error($user)) {
            return new WP_Error('invalid_credentials', 'Invalid email or password', array('status' => 401));
        }
        
        // Determine user type
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user->ID
        ));
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user->ID
        ));
        
        $user_type = $trainer ? 'trainer' : 'parent';
        $profile = $trainer ?: $parent;
        
        $token = self::generate_jwt($user->ID, $user_type);
        
        return rest_ensure_response(array(
            'token' => $token,
            'expires_in' => self::TOKEN_EXPIRY,
            'user' => array(
                'id' => $user->ID,
                'email' => $user->user_email,
                'type' => $user_type,
                'profile_id' => $profile ? $profile->id : null,
                'display_name' => $profile ? $profile->display_name : $user->display_name,
                'photo_url' => $trainer ? $trainer->photo_url : null,
                'onboarding_complete' => $trainer ? (bool)$trainer->is_onboarded : true,
            ),
        ));
    }
    
    /**
     * Register parent
     */
    public static function register($request) {
        $email = sanitize_email($request->get_param('email'));
        $password = $request->get_param('password');
        $name = sanitize_text_field($request->get_param('name'));
        $phone = sanitize_text_field($request->get_param('phone'));
        
        if (empty($email) || empty($password) || empty($name)) {
            return new WP_Error('missing_fields', 'Email, password, and name required', array('status' => 400));
        }
        
        if (email_exists($email)) {
            return new WP_Error('email_exists', 'Email already registered', array('status' => 400));
        }
        
        $user_id = wp_create_user($email, $password, $email);
        
        if (is_wp_error($user_id)) {
            return new WP_Error('registration_failed', $user_id->get_error_message(), array('status' => 400));
        }
        
        wp_update_user(array('ID' => $user_id, 'display_name' => $name));
        
        // Create parent profile
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ptp_parents', array(
            'user_id' => $user_id,
            'display_name' => $name,
            'phone' => $phone,
        ));
        $parent_id = $wpdb->insert_id;
        
        $user = get_user_by('ID', $user_id);
        $user->set_role('ptp_parent');
        
        $token = self::generate_jwt($user_id, 'parent');
        
        return rest_ensure_response(array(
            'token' => $token,
            'expires_in' => self::TOKEN_EXPIRY,
            'user' => array(
                'id' => $user_id,
                'email' => $email,
                'type' => 'parent',
                'profile_id' => $parent_id,
                'display_name' => $name,
            ),
        ));
    }
    
    /**
     * Register trainer (submit application)
     */
    public static function register_trainer($request) {
        $email = sanitize_email($request->get_param('email'));
        $name = sanitize_text_field($request->get_param('name'));
        $phone = sanitize_text_field($request->get_param('phone'));
        $playing_level = sanitize_text_field($request->get_param('playing_level'));
        $college = sanitize_text_field($request->get_param('college'));
        $bio = sanitize_textarea_field($request->get_param('bio'));
        
        if (empty($email) || empty($name) || empty($playing_level)) {
            return new WP_Error('missing_fields', 'Required fields missing', array('status' => 400));
        }
        
        global $wpdb;
        
        // Check if already applied
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_applications WHERE email = %s",
            $email
        ));
        
        if ($existing) {
            return new WP_Error('already_applied', 'Application already submitted', array('status' => 400));
        }
        
        // Create application
        $wpdb->insert($wpdb->prefix . 'ptp_applications', array(
            'email' => $email,
            'name' => $name,
            'phone' => $phone,
            'playing_level' => $playing_level,
            'college' => $college,
            'bio' => $bio,
            'status' => 'pending',
        ));
        
        return rest_ensure_response(array(
            'message' => 'Application submitted successfully',
            'application_id' => $wpdb->insert_id,
        ));
    }
    
    /**
     * Refresh token
     */
    public static function refresh_token($request) {
        $auth = self::get_auth_user($request);
        $token = self::generate_jwt($auth['user_id'], $auth['user_type']);
        
        return rest_ensure_response(array(
            'token' => $token,
            'expires_in' => self::TOKEN_EXPIRY,
        ));
    }
    
    /**
     * Get current user
     */
    public static function get_current_user($request) {
        $auth = self::get_auth_user($request);
        $user = get_user_by('ID', $auth['user_id']);
        
        global $wpdb;
        
        if ($auth['user_type'] === 'trainer') {
            $profile = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
                $auth['user_id']
            ));
            
            return rest_ensure_response(array(
                'id' => $user->ID,
                'email' => $user->user_email,
                'type' => 'trainer',
                'profile' => $profile,
            ));
        } else {
            $profile = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $auth['user_id']
            ));
            
            $players = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d",
                $profile->id
            ));
            
            return rest_ensure_response(array(
                'id' => $user->ID,
                'email' => $user->user_email,
                'type' => 'parent',
                'profile' => $profile,
                'players' => $players,
            ));
        }
    }
    
    /**
     * Forgot password
     */
    public static function forgot_password($request) {
        $email = sanitize_email($request->get_param('email'));
        
        $user = get_user_by('email', $email);
        if (!$user) {
            // Don't reveal if email exists
            return rest_ensure_response(array('message' => 'If this email exists, a reset link has been sent'));
        }
        
        $result = retrieve_password($email);
        
        return rest_ensure_response(array('message' => 'If this email exists, a reset link has been sent'));
    }
    
    /**
     * Update password
     */
    public static function update_password($request) {
        $auth = self::get_auth_user($request);
        $current = $request->get_param('current_password');
        $new = $request->get_param('new_password');
        
        $user = get_user_by('ID', $auth['user_id']);
        
        if (!wp_check_password($current, $user->user_pass, $user->ID)) {
            return new WP_Error('invalid_password', 'Current password is incorrect', array('status' => 400));
        }
        
        wp_set_password($new, $user->ID);
        
        // Generate new token since password changed
        $token = self::generate_jwt($auth['user_id'], $auth['user_type']);
        
        return rest_ensure_response(array(
            'message' => 'Password updated',
            'token' => $token,
        ));
    }
    
    /**
     * Save FCM token for push notifications
     */
    public static function save_fcm_token($request) {
        $auth = self::get_auth_user($request);
        $fcm_token = sanitize_text_field($request->get_param('fcm_token'));
        $device_type = sanitize_text_field($request->get_param('device_type')); // ios/android
        
        global $wpdb;
        
        // Store FCM token
        $wpdb->replace($wpdb->prefix . 'ptp_fcm_tokens', array(
            'user_id' => $auth['user_id'],
            'fcm_token' => $fcm_token,
            'device_type' => $device_type,
            'updated_at' => current_time('mysql'),
        ));
        
        return rest_ensure_response(array('message' => 'Token saved'));
    }
    
    // ================================================================
    // TRAINER ENDPOINTS (Public)
    // ================================================================
    
    /**
     * Get trainers list
     */
    public static function get_trainers($request) {
        global $wpdb;
        
        $per_page = intval($request->get_param('per_page') ?: 20);
        $page = intval($request->get_param('page') ?: 1);
        $offset = ($page - 1) * $per_page;
        
        $where = array("t.status = 'active'");
        $params = array();
        
        // Filters
        if ($location = $request->get_param('location')) {
            $where[] = "t.location LIKE %s";
            $params[] = '%' . $location . '%';
        }
        
        if ($specialty = $request->get_param('specialty')) {
            $where[] = "t.specialties LIKE %s";
            $params[] = '%' . $specialty . '%';
        }
        
        if ($min_rate = $request->get_param('min_rate')) {
            $where[] = "t.hourly_rate >= %d";
            $params[] = intval($min_rate);
        }
        
        if ($max_rate = $request->get_param('max_rate')) {
            $where[] = "t.hourly_rate <= %d";
            $params[] = intval($max_rate);
        }
        
        $where_sql = implode(' AND ', $where);
        
        $sql = "SELECT t.id, t.slug, t.display_name, t.photo_url, t.location, t.hourly_rate, 
                       t.specialties, t.college, t.average_rating, t.total_sessions, t.bio
                FROM {$wpdb->prefix}ptp_trainers t
                WHERE {$where_sql}
                ORDER BY t.average_rating DESC, t.total_sessions DESC
                LIMIT %d OFFSET %d";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $trainers = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers t WHERE {$where_sql}";
        $total = $wpdb->get_var($wpdb->prepare($count_sql, array_slice($params, 0, -2)));
        
        return rest_ensure_response(array(
            'trainers' => $trainers,
            'pagination' => array(
                'total' => intval($total),
                'per_page' => $per_page,
                'page' => $page,
                'total_pages' => ceil($total / $per_page),
            ),
        ));
    }
    
    /**
     * Get single trainer
     */
    public static function get_trainer($request) {
        global $wpdb;
        $id = intval($request->get_param('id'));
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email 
             FROM {$wpdb->prefix}ptp_trainers t
             JOIN {$wpdb->users} u ON t.user_id = u.ID
             WHERE t.id = %d AND t.status = 'active'",
            $id
        ));
        
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
        }
        
        // Get availability
        $availability = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_availability WHERE trainer_id = %d AND is_active = 1",
            $id
        ));
        
        // Get reviews
        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.display_name as parent_name
             FROM {$wpdb->prefix}ptp_reviews r
             JOIN {$wpdb->prefix}ptp_parents p ON r.parent_id = p.id
             WHERE r.trainer_id = %d AND r.status = 'approved'
             ORDER BY r.created_at DESC
             LIMIT 10",
            $id
        ));
        
        // Get upcoming group sessions
        $group_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_group_sessions
             WHERE trainer_id = %d AND status = 'open' AND session_date >= CURDATE()
             ORDER BY session_date ASC
             LIMIT 5",
            $id
        ));
        
        // Parse JSON fields
        $trainer->specialties = maybe_unserialize($trainer->specialties);
        $trainer->training_locations = json_decode($trainer->training_locations, true);
        // v213: Fallback for WP magic quotes corrupted JSON
        if (!is_array($trainer->training_locations) && !empty($trainer->training_locations)) {
            $trainer->training_locations = json_decode(wp_unslash($trainer->training_locations), true);
        }
        $trainer->gallery_images = json_decode($trainer->gallery_images, true);
        
        return rest_ensure_response(array(
            'trainer' => $trainer,
            'availability' => $availability,
            'reviews' => $reviews,
            'group_sessions' => $group_sessions,
        ));
    }
    
    /**
     * Get trainer by slug
     */
    public static function get_trainer_by_slug($request) {
        global $wpdb;
        $slug = sanitize_text_field($request->get_param('slug'));
        
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s AND status = 'active'",
            $slug
        ));
        
        if (!$trainer_id) {
            return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
        }
        
        $request->set_param('id', $trainer_id);
        return self::get_trainer($request);
    }
    
    /**
     * Get trainer availability slots for booking
     */
    public static function get_trainer_availability($request) {
        global $wpdb;
        $trainer_id = intval($request->get_param('id'));
        $date = sanitize_text_field($request->get_param('date'));
        
        if (!$date) {
            $date = date('Y-m-d');
        }
        
        // Get 14 days of availability
        $slots = array();
        $start = new DateTime($date);
        
        for ($i = 0; $i < 14; $i++) {
            $current_date = clone $start;
            $current_date->modify("+{$i} days");
            $day_of_week = $current_date->format('w');
            $date_str = $current_date->format('Y-m-d');
            
            // Get availability for this day
            $avail = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_availability 
                 WHERE trainer_id = %d AND day_of_week = %d AND is_active = 1",
                $trainer_id, $day_of_week
            ));
            
            if (!$avail) continue;
            
            // Get booked slots
            $booked = $wpdb->get_col($wpdb->prepare(
                "SELECT session_time FROM {$wpdb->prefix}ptp_bookings
                 WHERE trainer_id = %d AND session_date = %s AND status NOT IN ('cancelled', 'rejected')",
                $trainer_id, $date_str
            ));
            
            // Generate time slots
            $start_time = new DateTime($avail->start_time);
            $end_time = new DateTime($avail->end_time);
            
            $day_slots = array();
            while ($start_time < $end_time) {
                $time_str = $start_time->format('H:i:s');
                $day_slots[] = array(
                    'time' => $time_str,
                    'available' => !in_array($time_str, $booked),
                );
                $start_time->modify('+1 hour');
            }
            
            $slots[$date_str] = array(
                'date' => $date_str,
                'day_name' => $current_date->format('l'),
                'slots' => $day_slots,
            );
        }
        
        return rest_ensure_response(array('availability' => array_values($slots)));
    }
    
    /**
     * Get trainer reviews
     */
    public static function get_trainer_reviews($request) {
        global $wpdb;
        $trainer_id = intval($request->get_param('id'));
        $per_page = intval($request->get_param('per_page') ?: 20);
        $page = intval($request->get_param('page') ?: 1);
        $offset = ($page - 1) * $per_page;
        
        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.display_name as parent_name
             FROM {$wpdb->prefix}ptp_reviews r
             JOIN {$wpdb->prefix}ptp_parents p ON r.parent_id = p.id
             WHERE r.trainer_id = %d AND r.status = 'approved'
             ORDER BY r.created_at DESC
             LIMIT %d OFFSET %d",
            $trainer_id, $per_page, $offset
        ));
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_reviews WHERE trainer_id = %d AND status = 'approved'",
            $trainer_id
        ));
        
        return rest_ensure_response(array(
            'reviews' => $reviews,
            'pagination' => array(
                'total' => intval($total),
                'per_page' => $per_page,
                'page' => $page,
            ),
        ));
    }
    
    /**
     * Get trainer's open group sessions
     */
    public static function get_trainer_group_sessions($request) {
        global $wpdb;
        $trainer_id = intval($request->get_param('id'));
        
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_group_sessions
             WHERE trainer_id = %d AND status = 'open' AND session_date >= CURDATE()
             ORDER BY session_date ASC, start_time ASC",
            $trainer_id
        ));
        
        return rest_ensure_response(array('sessions' => $sessions));
    }
    
    // ================================================================
    // TRAINER DASHBOARD ENDPOINTS
    // ================================================================
    
    /**
     * Get trainer's own profile
     */
    public static function get_trainer_profile($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email
             FROM {$wpdb->prefix}ptp_trainers t
             JOIN {$wpdb->users} u ON t.user_id = u.ID
             WHERE t.user_id = %d",
            $auth['user_id']
        ));
        
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer profile not found', array('status' => 404));
        }
        
        // Parse JSON fields
        $trainer->specialties = maybe_unserialize($trainer->specialties);
        $trainer->training_locations = json_decode($trainer->training_locations, true);
        // v213: Fallback for WP magic quotes corrupted JSON
        if (!is_array($trainer->training_locations) && !empty($trainer->training_locations)) {
            $trainer->training_locations = json_decode(wp_unslash($trainer->training_locations), true);
        }
        $trainer->gallery_images = json_decode($trainer->gallery_images, true);
        
        // Get availability
        $availability = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_availability WHERE trainer_id = %d",
            $trainer->id
        ));
        
        return rest_ensure_response(array(
            'trainer' => $trainer,
            'availability' => $availability,
        ));
    }
    
    /**
     * Update trainer profile
     */
    public static function update_trainer_profile($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
        }
        
        $data = array();
        
        // Allowed fields to update
        $allowed = array('display_name', 'bio', 'hourly_rate', 'phone', 'location');
        foreach ($allowed as $field) {
            if ($request->has_param($field)) {
                $data[$field] = sanitize_text_field($request->get_param($field));
            }
        }
        
        if ($request->has_param('specialties')) {
            $data['specialties'] = maybe_serialize($request->get_param('specialties'));
        }
        
        if ($request->has_param('training_locations')) {
            $data['training_locations'] = wp_json_encode($request->get_param('training_locations'));
        }
        
        if (!empty($data)) {
            $wpdb->update($wpdb->prefix . 'ptp_trainers', $data, array('id' => $trainer->id));
        }
        
        return self::get_trainer_profile($request);
    }
    
    /**
     * Get trainer's availability settings
     */
    public static function get_my_availability($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $availability = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_availability WHERE trainer_id = %d ORDER BY day_of_week",
            $trainer->id
        ));
        
        return rest_ensure_response(array('availability' => $availability));
    }
    
    /**
     * Update trainer availability
     */
    public static function update_availability($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $slots = $request->get_param('availability');
        
        // Clear existing
        $wpdb->delete($wpdb->prefix . 'ptp_availability', array('trainer_id' => $trainer->id));
        
        // Insert new
        foreach ($slots as $slot) {
            $wpdb->insert($wpdb->prefix . 'ptp_availability', array(
                'trainer_id' => $trainer->id,
                'day_of_week' => intval($slot['day_of_week']),
                'start_time' => sanitize_text_field($slot['start_time']),
                'end_time' => sanitize_text_field($slot['end_time']),
                'is_active' => isset($slot['is_active']) ? intval($slot['is_active']) : 1,
            ));
        }
        
        return self::get_my_availability($request);
    }
    
    /**
     * Get trainer bookings
     */
    public static function get_trainer_bookings($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $status = $request->get_param('status');
        $upcoming = $request->get_param('upcoming');
        
        $where = "b.trainer_id = %d";
        $params = array($trainer->id);
        
        if ($status) {
            $where .= " AND b.status = %s";
            $params[] = $status;
        }
        
        if ($upcoming) {
            $where .= " AND b.session_date >= CURDATE()";
        }
        
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, COALESCE(p.name, CONCAT(p.first_name, ' ', p.last_name)) as player_name, p.age as player_age,
                    pa.display_name as parent_name, pa.phone as parent_phone
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             WHERE {$where}
             ORDER BY b.session_date ASC, COALESCE(b.start_time, b.session_time) ASC",
            $params
        ));
        
        return rest_ensure_response(array('bookings' => $bookings));
    }
    
    /**
     * Get trainer earnings
     */
    public static function get_trainer_earnings($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        // Get earnings summary - v222.1: Read from bookings (source of truth), not payouts log
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed'",
            $trainer->id
        ));
        
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'",
            $trainer->id
        ));
        
        // Monthly breakdown
        $monthly = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, '%%Y-%%m') as month, SUM(amount) as total
             FROM {$wpdb->prefix}ptp_payouts
             WHERE trainer_id = %d AND status = 'completed'
             GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')
             ORDER BY month DESC
             LIMIT 12",
            $trainer->id
        ));
        
        // Recent payouts
        $payouts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_payouts 
             WHERE trainer_id = %d
             ORDER BY created_at DESC
             LIMIT 20",
            $trainer->id
        ));
        
        return rest_ensure_response(array(
            'total_earned' => floatval($total),
            'pending' => floatval($pending),
            'monthly' => $monthly,
            'payouts' => $payouts,
        ));
    }
    
    /**
     * Get trainer stats
     */
    public static function get_trainer_stats($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        // Upcoming sessions
        $upcoming = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'confirmed' AND session_date >= CURDATE()",
            $trainer->id
        ));
        
        // This month sessions
        $this_month = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' 
             AND MONTH(session_date) = MONTH(CURDATE()) AND YEAR(session_date) = YEAR(CURDATE())",
            $trainer->id
        ));
        
        // This month earnings - v222.1: Read from bookings not payouts log
        $month_earnings = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed'
             AND MONTH(session_date) = MONTH(CURDATE()) AND YEAR(session_date) = YEAR(CURDATE())",
            $trainer->id
        ));
        
        // Active players
        $active_players = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT player_id) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
            $trainer->id
        ));
        
        // Pending bookings
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'pending'",
            $trainer->id
        ));
        
        return rest_ensure_response(array(
            'total_sessions' => intval($trainer->total_sessions),
            'average_rating' => floatval($trainer->average_rating),
            'total_reviews' => intval($trainer->total_reviews),
            'upcoming_sessions' => intval($upcoming),
            'this_month_sessions' => intval($this_month),
            'this_month_earnings' => floatval($month_earnings),
            'active_players' => intval($active_players),
            'pending_bookings' => intval($pending),
        ));
    }
    
    /**
     * Get trainer's players list
     */
    public static function get_trainer_players($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, pa.display_name as parent_name, pa.phone as parent_phone,
                    COUNT(b.id) as total_sessions,
                    MAX(b.session_date) as last_session
             FROM {$wpdb->prefix}ptp_players p
             JOIN {$wpdb->prefix}ptp_parents pa ON p.parent_id = pa.id
             JOIN {$wpdb->prefix}ptp_bookings b ON b.player_id = p.id
             WHERE b.trainer_id = %d
             GROUP BY p.id
             ORDER BY last_session DESC",
            $trainer->id
        ));
        
        return rest_ensure_response(array('players' => $players));
    }
    
    /**
     * Trainer Stripe Connect
     */
    public static function trainer_stripe_connect($request) {
        $auth = self::get_auth_user($request);
        
        if (!class_exists('PTP_Stripe') || !get_option('ptp_stripe_connect_enabled')) {
            return new WP_Error('not_available', 'Stripe Connect is not enabled', array('status' => 400));
        }
        
        $trainer = PTP_Trainer::get_by_user_id($auth['user_id']);
        $user = get_user_by('ID', $auth['user_id']);
        
        // Create or get existing account
        if (!$trainer->stripe_account_id) {
            $account = PTP_Stripe::create_connect_account($trainer->id, $user->user_email);
            if (is_wp_error($account)) {
                return new WP_Error('stripe_error', $account->get_error_message(), array('status' => 400));
            }
            $account_id = $account['id'];
        } else {
            $account_id = $trainer->stripe_account_id;
        }
        
        // Create onboarding link
        $return_url = $request->get_param('return_url') ?: home_url('/account/?tab=payments');
        $refresh_url = $request->get_param('refresh_url') ?: home_url('/account/?tab=payments');
        
        $link = PTP_Stripe::create_account_link($account_id, $refresh_url, $return_url);
        
        if (is_wp_error($link)) {
            return new WP_Error('stripe_error', $link->get_error_message(), array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'onboarding_url' => $link['url'],
        ));
    }
    
    /**
     * Get trainer Stripe status
     */
    public static function trainer_stripe_status($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT stripe_account_id, stripe_charges_enabled, stripe_payouts_enabled 
             FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        return rest_ensure_response(array(
            'connected' => !empty($trainer->stripe_account_id),
            'charges_enabled' => (bool)$trainer->stripe_charges_enabled,
            'payouts_enabled' => (bool)$trainer->stripe_payouts_enabled,
            'connect_available' => (bool)get_option('ptp_stripe_connect_enabled'),
        ));
    }
    
    // ================================================================
    // BOOKING ENDPOINTS
    // ================================================================
    
    /**
     * Create a booking
     */
    public static function create_booking($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        
        // Get parent
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        if (!$parent) {
            return new WP_Error('not_parent', 'Parent account required', array('status' => 403));
        }
        
        $trainer_id = intval($request->get_param('trainer_id'));
        $player_id = intval($request->get_param('player_id'));
        $session_date = sanitize_text_field($request->get_param('session_date'));
        $session_time = sanitize_text_field($request->get_param('session_time'));
        $duration = intval($request->get_param('duration') ?: 60);
        $location = sanitize_text_field($request->get_param('location'));
        $notes = sanitize_textarea_field($request->get_param('notes'));
        
        // Verify player belongs to parent
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d AND parent_id = %d",
            $player_id, $parent->id
        ));
        
        if (!$player) {
            return new WP_Error('invalid_player', 'Player not found', array('status' => 400));
        }
        
        // Get trainer
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('invalid_trainer', 'Trainer not found', array('status' => 400));
        }
        
        // Check availability
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date = %s 
             AND (start_time = %s OR session_time = %s)
             AND status NOT IN ('cancelled', 'rejected')",
            $trainer_id, $session_date, $session_time, $session_time
        ));
        
        if ($existing) {
            return new WP_Error('slot_taken', 'This time slot is no longer available', array('status' => 400));
        }
        
        // Calculate price and fees
        $hourly_rate = floatval($trainer->hourly_rate ?: 70);
        $hours = $duration / 60;
        $total_amount = round($hourly_rate * $hours, 2);
        $platform_fee_rate = function_exists('ptp_get_platform_fee') ? ptp_get_platform_fee() : 0.15;
        $platform_fee = round($total_amount * $platform_fee_rate, 2);
        $trainer_payout = $total_amount - $platform_fee;
        
        // Generate booking number
        $booking_number = 'PTP-' . strtoupper(substr(md5(uniqid()), 0, 8));
        
        // v216.1: Write correct column names
        $wpdb->insert($wpdb->prefix . 'ptp_bookings', array(
            'booking_number' => $booking_number,
            'trainer_id' => $trainer_id,
            'parent_id' => $parent->id,
            'player_id' => $player_id,
            'session_date' => $session_date,
            'start_time' => $session_time,
            'session_time' => $session_time,
            'duration_minutes' => $duration,
            'location' => $location,
            'notes' => $notes,
            'hourly_rate' => $hourly_rate,
            'total_amount' => $total_amount,
            'platform_fee' => $platform_fee,
            'trainer_payout' => $trainer_payout,
            'status' => 'pending',
            'payment_status' => 'pending',
            'created_at' => current_time('mysql'),
        ));
        
        $booking_id = $wpdb->insert_id;
        
        // v216.1: Fire hook — triggers Supabase sync, push notification
        do_action('ptp_booking_created', $booking_id);
        
        // Send notification to trainer
        // v235.9: Fixed — send_booking_request() doesn't exist, use booking_created()
        if (class_exists('PTP_Notifications') && method_exists('PTP_Notifications', 'booking_created')) {
            PTP_Notifications::booking_created($booking_id);
        }
        
        return rest_ensure_response(array(
            'booking_id' => $booking_id,
            'booking_number' => $booking_number,
            'amount' => $total_amount,
            'message' => 'Booking request sent',
        ));
    }
    
    /**
     * Get booking details
     */
    public static function get_booking($request) {
        $auth = self::get_auth_user($request);
        $booking_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, 
                    t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug,
                    COALESCE(p.name, CONCAT(p.first_name, ' ', p.last_name)) as player_name, p.age as player_age,
                    pa.display_name as parent_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            return new WP_Error('not_found', 'Booking not found', array('status' => 404));
        }
        
        // Verify access
        $user_trainer = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $user_parent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        if ($booking->trainer_id != $user_trainer && $booking->parent_id != $user_parent) {
            return new WP_Error('forbidden', 'Access denied', array('status' => 403));
        }
        
        // Get session notes if completed
        if ($booking->status === 'completed') {
            $booking->session_notes = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_session_notes WHERE booking_id = %d AND is_visible_to_parent = 1",
                $booking_id
            ));
        }
        
        return rest_ensure_response(array('booking' => $booking));
    }
    
    /**
     * Cancel booking
     */
    public static function cancel_booking($request) {
        $auth = self::get_auth_user($request);
        $booking_id = intval($request->get_param('id'));
        $reason = sanitize_textarea_field($request->get_param('reason'));
        
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            return new WP_Error('not_found', 'Booking not found', array('status' => 404));
        }
        
        // Verify ownership
        $user_parent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        if ($booking->parent_id != $user_parent) {
            return new WP_Error('forbidden', 'Access denied', array('status' => 403));
        }
        
        if (!in_array($booking->status, array('pending', 'confirmed'))) {
            return new WP_Error('invalid_status', 'Cannot cancel this booking', array('status' => 400));
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'cancelled',
                'cancelled_by' => 'parent',
                'cancellation_reason' => $reason,
                'cancelled_at' => current_time('mysql'),
                'refund_status' => (!empty($booking->stripe_payment_id) && $booking->payment_status === 'paid') ? 'pending' : 'none',
            ),
            array('id' => $booking_id)
        );
        
        // Process refund if escrow exists and funds haven't been released
        $escrow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_escrow WHERE booking_id = %d AND status IN ('holding', 'session_complete')",
            $booking_id
        ));
        if ($escrow && class_exists('PTP_Escrow')) {
            PTP_Escrow::refund($escrow->id);
        }
        
        return rest_ensure_response(array('message' => 'Booking cancelled'));
    }
    
    /**
     * Trainer confirms booking
     */
    public static function confirm_booking($request) {
        $auth = self::get_auth_user($request);
        $booking_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d AND trainer_id = %d",
            $booking_id, $trainer->id
        ));
        
        if (!$booking) {
            return new WP_Error('not_found', 'Booking not found', array('status' => 404));
        }
        
        if ($booking->status !== 'pending') {
            return new WP_Error('invalid_status', 'Booking is not pending', array('status' => 400));
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array('status' => 'confirmed', 'trainer_confirmed_at' => current_time('mysql')),
            array('id' => $booking_id)
        );
        
        // v216.1: Fire proper hook — triggers SMS, Google Calendar, Supabase sync, push notifications
        do_action('ptp_booking_confirmed', $booking_id);
        
        // v235.9: Removed call to PTP_Notifications::send_booking_confirmed() — method doesn't exist.
        // Emails handled by ptp_booking_confirmed hook chain above.
        
        return rest_ensure_response(array('message' => 'Booking confirmed'));
    }
    
    /**
     * Mark booking as completed
     */
    public static function complete_booking($request) {
        $auth = self::get_auth_user($request);
        $booking_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d AND trainer_id = %d",
            $booking_id, $trainer->id
        ));
        
        if (!$booking) {
            return new WP_Error('not_found', 'Booking not found', array('status' => 404));
        }
        
        if ($booking->status !== 'confirmed') {
            return new WP_Error('invalid_status', 'Booking must be confirmed first', array('status' => 400));
        }
        
        // v216.1: Route through escrow system instead of bypassing it
        $escrow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_escrow WHERE booking_id = %d",
            $booking_id
        ));
        
        if ($escrow && class_exists('PTP_Escrow') && method_exists('PTP_Escrow', 'mark_session_complete')) {
            // Use escrow flow — marks session complete, sets auto-release timer, fires hooks
            $result = PTP_Escrow::mark_session_complete($booking_id);
            
            if (is_wp_error($result)) {
                ptp_log("[PTP REST] Escrow mark_session_complete failed for booking {$booking_id}: " . $result->get_error_message());
                // Fall through to manual completion
            } else {
                // Escrow handled everything — get payout info
                $session_number = PTP_Escrow::get_session_number($trainer->id, $booking->parent_id);
                $fee_rate = PTP_Escrow::get_fee_rate($session_number);
                $total = floatval($booking->total_amount);
                $trainer_amount = $total - round($total * $fee_rate, 2);
                
                return rest_ensure_response(array(
                    'message' => 'Session completed',
                    'payout_amount' => $trainer_amount,
                    'escrow_status' => 'session_complete',
                    'auto_release_hours' => PTP_Escrow::AUTO_RELEASE_HOURS ?? 24,
                ));
            }
        }
        
        // Fallback: no escrow record — complete directly
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array('status' => 'completed', 'completed_at' => current_time('mysql')),
            array('id' => $booking_id)
        );
        
        // Update trainer stats
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_trainers SET total_sessions = total_sessions + 1 WHERE id = %d",
            $trainer->id
        ));
        
        // v216.1: Use total_amount (not 'amount' which doesn't exist)
        $total = floatval($booking->total_amount);
        $session_number = PTP_Escrow::get_session_number($trainer->id, $booking->parent_id);
        $fee_rate = PTP_Escrow::get_fee_rate($session_number);
        $platform_fee = round($total * $fee_rate, 2);
        $trainer_amount = $total - $platform_fee;
        
        // Fire hooks for downstream systems (SMS, push, Supabase sync, camp cross-sell)
        do_action('ptp_session_completed', $booking_id, $booking);
        do_action('ptp_booking_completed', $booking_id, $booking);
        
        return rest_ensure_response(array('message' => 'Session completed', 'payout_amount' => $trainer_amount));
    }
    
    /**
     * Submit review for completed session
     */
    public static function submit_review($request) {
        $auth = self::get_auth_user($request);
        $booking_id = intval($request->get_param('id'));
        $rating = intval($request->get_param('rating'));
        $comment = sanitize_textarea_field($request->get_param('comment'));
        
        if ($rating < 1 || $rating > 5) {
            return new WP_Error('invalid_rating', 'Rating must be 1-5', array('status' => 400));
        }
        
        global $wpdb;
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d AND parent_id = %d AND status = 'completed'",
            $booking_id, $parent->id
        ));
        
        if (!$booking) {
            return new WP_Error('not_found', 'Completed booking not found', array('status' => 404));
        }
        
        // Check if already reviewed
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_reviews WHERE booking_id = %d",
            $booking_id
        ));
        
        if ($existing) {
            return new WP_Error('already_reviewed', 'Already reviewed', array('status' => 400));
        }
        
        $wpdb->insert($wpdb->prefix . 'ptp_reviews', array(
            'trainer_id' => $booking->trainer_id,
            'parent_id' => $parent->id,
            'booking_id' => $booking_id,
            'rating' => $rating,
            'comment' => $comment,
            'status' => 'approved', // Auto-approve for now
        ));
        
        // Update trainer rating
        $avg = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(rating) FROM {$wpdb->prefix}ptp_reviews WHERE trainer_id = %d AND status = 'approved'",
            $booking->trainer_id
        ));
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_reviews WHERE trainer_id = %d AND status = 'approved'",
            $booking->trainer_id
        ));
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('average_rating' => round($avg, 1), 'total_reviews' => $count),
            array('id' => $booking->trainer_id)
        );
        
        return rest_ensure_response(array('message' => 'Review submitted'));
    }
    
    /**
     * Add session notes
     */
    public static function add_session_notes($request) {
        $auth = self::get_auth_user($request);
        $booking_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d AND trainer_id = %d",
            $booking_id, $trainer->id
        ));
        
        if (!$booking) {
            return new WP_Error('not_found', 'Booking not found', array('status' => 404));
        }
        
        $wpdb->replace($wpdb->prefix . 'ptp_session_notes', array(
            'booking_id' => $booking_id,
            'player_id' => $booking->player_id,
            'trainer_id' => $trainer->id,
            'session_date' => $booking->session_date,
            'focus_worked_on' => sanitize_textarea_field($request->get_param('focus_worked_on')),
            'drills_performed' => sanitize_textarea_field($request->get_param('drills_performed')),
            'achievements' => sanitize_textarea_field($request->get_param('achievements')),
            'areas_to_improve' => sanitize_textarea_field($request->get_param('areas_to_improve')),
            'homework' => sanitize_textarea_field($request->get_param('homework')),
            'player_effort' => intval($request->get_param('player_effort')),
            'player_attitude' => intval($request->get_param('player_attitude')),
            'private_notes' => sanitize_textarea_field($request->get_param('private_notes')),
            'is_visible_to_parent' => $request->get_param('is_visible_to_parent') !== false ? 1 : 0,
        ));
        
        return rest_ensure_response(array('message' => 'Notes saved'));
    }
    
    // ================================================================
    // GROUP SESSION ENDPOINTS
    // ================================================================
    
    /**
     * Get open group sessions
     */
    public static function get_group_sessions($request) {
        $filters = array(
            'trainer_id' => $request->get_param('trainer_id'),
            'skill_level' => $request->get_param('skill_level'),
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
            'location' => $request->get_param('location'),
            'limit' => $request->get_param('limit') ?: 20,
        );
        
        $sessions = PTP_Groups::get_open_sessions($filters);
        
        return rest_ensure_response(array('sessions' => $sessions));
    }
    
    /**
     * Create group session
     */
    public static function create_group_session($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $data = array(
            'title' => $request->get_param('title'),
            'description' => $request->get_param('description'),
            'session_date' => $request->get_param('session_date'),
            'start_time' => $request->get_param('start_time'),
            'duration_minutes' => $request->get_param('duration_minutes') ?: 60,
            'location' => $request->get_param('location'),
            'location_notes' => $request->get_param('location_notes'),
            'min_players' => $request->get_param('min_players') ?: 2,
            'max_players' => $request->get_param('max_players') ?: 6,
            'total_price' => $request->get_param('total_price'),
            'skill_level' => $request->get_param('skill_level') ?: 'all',
            'age_min' => $request->get_param('age_min'),
            'age_max' => $request->get_param('age_max'),
            'focus_area' => $request->get_param('focus_area'),
            'is_public' => $request->get_param('is_public') !== false ? 1 : 0,
        );
        
        $group_id = PTP_Groups::create_group_session($trainer->id, $data);
        
        return rest_ensure_response(array(
            'group_session_id' => $group_id,
            'message' => 'Group session created',
        ));
    }
    
    /**
     * Get single group session
     */
    public static function get_group_session($request) {
        $id = intval($request->get_param('id'));
        $session = PTP_Groups::get_group_session($id);
        
        if (!$session) {
            return new WP_Error('not_found', 'Group session not found', array('status' => 404));
        }
        
        return rest_ensure_response(array('session' => $session));
    }
    
    /**
     * Update group session
     */
    public static function update_group_session($request) {
        $auth = self::get_auth_user($request);
        $id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_group_sessions WHERE id = %d AND trainer_id = %d",
            $id, $trainer->id
        ));
        
        if (!$session) {
            return new WP_Error('not_found', 'Group session not found', array('status' => 404));
        }
        
        $data = array();
        $allowed = array('title', 'description', 'location', 'location_notes', 'max_players', 'skill_level', 'age_min', 'age_max', 'focus_area');
        
        foreach ($allowed as $field) {
            if ($request->has_param($field)) {
                $data[$field] = sanitize_text_field($request->get_param($field));
            }
        }
        
        if (!empty($data)) {
            $wpdb->update($wpdb->prefix . 'ptp_group_sessions', $data, array('id' => $id));
        }
        
        return self::get_group_session($request);
    }
    
    /**
     * Delete/cancel group session
     */
    public static function delete_group_session($request) {
        $auth = self::get_auth_user($request);
        $id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_group_sessions WHERE id = %d AND trainer_id = %d",
            $id, $trainer->id
        ));
        
        if (!$session) {
            return new WP_Error('not_found', 'Group session not found', array('status' => 404));
        }
        
        // Cancel instead of delete if has participants
        if ($session->current_players > 0) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_group_sessions',
                array('status' => 'cancelled'),
                array('id' => $id)
            );
            
            // Refund all participant escrow holds
            $escrows = $wpdb->get_results($wpdb->prepare(
                "SELECT e.id FROM {$wpdb->prefix}ptp_escrow e
                 JOIN {$wpdb->prefix}ptp_bookings b ON e.booking_id = b.id
                 WHERE b.group_session_id = %d AND e.status IN ('holding', 'session_complete')",
                $id
            ));
            foreach ($escrows as $esc) {
                if (class_exists('PTP_Escrow')) {
                    PTP_Escrow::refund($esc->id);
                }
            }
            
            // Notify admin for any manual follow-up
            $admin_email = get_option('admin_email');
            wp_mail($admin_email, '[PTP] Group Session #' . $id . ' Cancelled',
                "Trainer cancelled group session #{$id} with {$session->current_players} participants.\nEscrow refunds have been processed automatically. Please verify all participants were notified."
            );
            
            return rest_ensure_response(array('message' => 'Group session cancelled'));
        }
        
        $wpdb->delete($wpdb->prefix . 'ptp_group_sessions', array('id' => $id));
        
        return rest_ensure_response(array('message' => 'Group session deleted'));
    }
    
    /**
     * Join group session
     */
    public static function join_group_session($request) {
        $auth = self::get_auth_user($request);
        $group_id = intval($request->get_param('id'));
        $player_id = intval($request->get_param('player_id'));
        
        global $wpdb;
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        if (!$parent) {
            return new WP_Error('not_parent', 'Parent account required', array('status' => 403));
        }
        
        // Verify player belongs to parent
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d AND parent_id = %d",
            $player_id, $parent->id
        ));
        
        if (!$player) {
            return new WP_Error('invalid_player', 'Player not found', array('status' => 400));
        }
        
        $result = PTP_Groups::join_group_session($group_id, $parent->id, $player_id);
        
        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), array('status' => 400));
        }
        
        $session = PTP_Groups::get_group_session($group_id);
        
        return rest_ensure_response(array(
            'participant_id' => $result,
            'price_per_player' => $session->price_per_player,
            'message' => 'Successfully joined group session',
        ));
    }
    
    /**
     * Leave group session
     */
    public static function leave_group_session($request) {
        $auth = self::get_auth_user($request);
        $group_id = intval($request->get_param('id'));
        $player_id = intval($request->get_param('player_id'));
        
        global $wpdb;
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        // Verify player belongs to parent
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d AND parent_id = %d",
            $player_id, $parent->id
        ));
        
        if (!$player) {
            return new WP_Error('invalid_player', 'Player not found', array('status' => 400));
        }
        
        PTP_Groups::leave_group_session($group_id, $player_id);
        
        return rest_ensure_response(array('message' => 'Left group session'));
    }
    
    /**
     * Get group participants (trainer only)
     */
    public static function get_group_participants($request) {
        $auth = self::get_auth_user($request);
        $group_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_group_sessions WHERE id = %d AND trainer_id = %d",
            $group_id, $trainer->id
        ));
        
        if (!$session) {
            return new WP_Error('not_found', 'Group session not found', array('status' => 404));
        }
        
        $participants = PTP_Groups::get_participants($group_id);
        
        return rest_ensure_response(array('participants' => $participants));
    }
    
    /**
     * Complete group session
     */
    public static function complete_group_session($request) {
        $auth = self::get_auth_user($request);
        $group_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_group_sessions WHERE id = %d AND trainer_id = %d",
            $group_id, $trainer->id
        ));
        
        if (!$session) {
            return new WP_Error('not_found', 'Group session not found', array('status' => 404));
        }
        
        $result = PTP_Groups::complete_session($group_id);
        
        return rest_ensure_response(array('message' => 'Group session completed'));
    }
    
    // ================================================================
    // TRAINING PLANS ENDPOINTS
    // ================================================================
    
    /**
     * Get training plans
     */
    public static function get_training_plans($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        
        if ($auth['user_type'] === 'trainer') {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
                $auth['user_id']
            ));
            
            $plans = $wpdb->get_results($wpdb->prepare(
                "SELECT tp.*, p.name as player_name, p.age as player_age
                 FROM {$wpdb->prefix}ptp_training_plans tp
                 JOIN {$wpdb->prefix}ptp_players p ON tp.player_id = p.id
                 WHERE tp.trainer_id = %d
                 ORDER BY tp.created_at DESC",
                $trainer->id
            ));
        } else {
            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $auth['user_id']
            ));
            
            $plans = $wpdb->get_results($wpdb->prepare(
                "SELECT tp.*, p.name as player_name, t.display_name as trainer_name
                 FROM {$wpdb->prefix}ptp_training_plans tp
                 JOIN {$wpdb->prefix}ptp_players p ON tp.player_id = p.id
                 JOIN {$wpdb->prefix}ptp_trainers t ON tp.trainer_id = t.id
                 WHERE p.parent_id = %d
                 ORDER BY tp.created_at DESC",
                $parent->id
            ));
        }
        
        // Add progress to each plan
        foreach ($plans as $plan) {
            $plan->progress = PTP_Training_Plans::calculate_plan_progress($plan->id);
            $plan->focus_areas = maybe_unserialize($plan->focus_areas);
        }
        
        return rest_ensure_response(array('plans' => $plans));
    }
    
    /**
     * Create training plan
     */
    public static function create_training_plan($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $data = array(
            'player_id' => $request->get_param('player_id'),
            'trainer_id' => $trainer->id,
            'title' => $request->get_param('title'),
            'description' => $request->get_param('description'),
            'focus_areas' => $request->get_param('focus_areas'),
            'goals' => $request->get_param('goals'),
            'duration_weeks' => $request->get_param('duration_weeks') ?: 12,
            'sessions_per_week' => $request->get_param('sessions_per_week') ?: 1,
            'start_date' => $request->get_param('start_date'),
            'milestones' => $request->get_param('milestones'),
        );
        
        $plan_id = PTP_Training_Plans::create_plan($data);
        
        return rest_ensure_response(array(
            'plan_id' => $plan_id,
            'message' => 'Training plan created',
        ));
    }
    
    /**
     * Get single training plan
     */
    public static function get_training_plan($request) {
        $id = intval($request->get_param('id'));
        $plan = PTP_Training_Plans::get_plan($id);
        
        if (!$plan) {
            return new WP_Error('not_found', 'Training plan not found', array('status' => 404));
        }
        
        // Get session notes for this plan
        global $wpdb;
        $plan->session_notes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_session_notes 
             WHERE plan_id = %d AND is_visible_to_parent = 1
             ORDER BY session_date DESC",
            $id
        ));
        
        return rest_ensure_response(array('plan' => $plan));
    }
    
    /**
     * Update training plan
     */
    public static function update_training_plan($request) {
        $auth = self::get_auth_user($request);
        $id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_training_plans WHERE id = %d AND trainer_id = %d",
            $id, $trainer->id
        ));
        
        if (!$plan) {
            return new WP_Error('not_found', 'Training plan not found', array('status' => 404));
        }
        
        $data = array();
        $allowed = array('title', 'description', 'goals', 'duration_weeks', 'sessions_per_week');
        
        foreach ($allowed as $field) {
            if ($request->has_param($field)) {
                $data[$field] = sanitize_text_field($request->get_param($field));
            }
        }
        
        if ($request->has_param('focus_areas')) {
            $data['focus_areas'] = maybe_serialize($request->get_param('focus_areas'));
        }
        
        if (!empty($data)) {
            $wpdb->update($wpdb->prefix . 'ptp_training_plans', $data, array('id' => $id));
        }
        
        return self::get_training_plan($request);
    }
    
    /**
     * Activate training plan
     */
    public static function activate_training_plan($request) {
        $auth = self::get_auth_user($request);
        $id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_training_plans WHERE id = %d AND trainer_id = %d",
            $id, $trainer->id
        ));
        
        if (!$plan) {
            return new WP_Error('not_found', 'Training plan not found', array('status' => 404));
        }
        
        $start_date = $request->get_param('start_date') ?: date('Y-m-d');
        $end = new DateTime($start_date);
        $end->modify('+' . $plan->duration_weeks . ' weeks');
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_training_plans',
            array(
                'status' => 'active',
                'start_date' => $start_date,
                'end_date' => $end->format('Y-m-d'),
            ),
            array('id' => $id)
        );
        
        return rest_ensure_response(array('message' => 'Training plan activated'));
    }
    
    /**
     * Add milestone to plan
     */
    public static function add_milestone($request) {
        $auth = self::get_auth_user($request);
        $plan_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_training_plans WHERE id = %d AND trainer_id = %d",
            $plan_id, $trainer->id
        ));
        
        if (!$plan) {
            return new WP_Error('not_found', 'Training plan not found', array('status' => 404));
        }
        
        $data = array(
            'title' => $request->get_param('title'),
            'description' => $request->get_param('description'),
            'target_week' => $request->get_param('target_week'),
            'skill_category' => $request->get_param('skill_category'),
            'skill_name' => $request->get_param('skill_name'),
            'target_level' => $request->get_param('target_level'),
        );
        
        PTP_Training_Plans::add_milestone($plan_id, $data);
        
        return rest_ensure_response(array('message' => 'Milestone added'));
    }
    
    /**
     * Complete milestone
     */
    public static function complete_milestone($request) {
        $auth = self::get_auth_user($request);
        $milestone_id = intval($request->get_param('id'));
        $notes = sanitize_textarea_field($request->get_param('notes'));
        
        // Verify trainer owns the plan
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $milestone = $wpdb->get_row($wpdb->prepare(
            "SELECT m.*, p.trainer_id FROM {$wpdb->prefix}ptp_plan_milestones m
             JOIN {$wpdb->prefix}ptp_training_plans p ON m.plan_id = p.id
             WHERE m.id = %d",
            $milestone_id
        ));
        
        if (!$milestone || $milestone->trainer_id != $trainer->id) {
            return new WP_Error('not_found', 'Milestone not found', array('status' => 404));
        }
        
        PTP_Training_Plans::complete_milestone($milestone_id, $notes);
        
        return rest_ensure_response(array('message' => 'Milestone completed'));
    }
    
    // ================================================================
    // ASSESSMENT ENDPOINTS
    // ================================================================
    
    /**
     * Create assessment
     */
    public static function create_assessment($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $data = array(
            'player_id' => $request->get_param('player_id'),
            'trainer_id' => $trainer->id,
            'plan_id' => $request->get_param('plan_id'),
            'booking_id' => $request->get_param('booking_id'),
            'assessment_date' => $request->get_param('assessment_date') ?: date('Y-m-d'),
            'assessment_type' => $request->get_param('assessment_type') ?: 'progress',
            'notes' => $request->get_param('notes'),
            'ratings' => $request->get_param('ratings'),
            'rating_notes' => $request->get_param('rating_notes'),
        );
        
        $assessment_id = PTP_Training_Plans::create_assessment($data);
        
        return rest_ensure_response(array(
            'assessment_id' => $assessment_id,
            'message' => 'Assessment created',
        ));
    }
    
    /**
     * Get assessment
     */
    public static function get_assessment($request) {
        $id = intval($request->get_param('id'));
        $assessment = PTP_Training_Plans::get_assessment($id);
        
        if (!$assessment) {
            return new WP_Error('not_found', 'Assessment not found', array('status' => 404));
        }
        
        return rest_ensure_response(array('assessment' => $assessment));
    }
    
    /**
     * Get player assessments
     */
    public static function get_player_assessments($request) {
        $player_id = intval($request->get_param('id'));
        $limit = intval($request->get_param('limit') ?: 10);
        
        $assessments = PTP_Training_Plans::get_player_assessments($player_id, $limit);
        
        return rest_ensure_response(array('assessments' => $assessments));
    }
    
    /**
     * Get player progress (skill trends over time)
     */
    public static function get_player_progress($request) {
        $player_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        // Get all assessments with ratings
        $assessments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.assessment_date, a.overall_rating, a.assessment_type
             FROM {$wpdb->prefix}ptp_skill_assessments a
             WHERE a.player_id = %d
             ORDER BY a.assessment_date ASC",
            $player_id
        ));
        
        // Get skill progress over time
        $skill_progress = $wpdb->get_results($wpdb->prepare(
            "SELECT r.skill_category, r.skill_name, a.assessment_date, r.rating
             FROM {$wpdb->prefix}ptp_skill_ratings r
             JOIN {$wpdb->prefix}ptp_skill_assessments a ON r.assessment_id = a.id
             WHERE a.player_id = %d
             ORDER BY r.skill_category, r.skill_name, a.assessment_date",
            $player_id
        ));
        
        // Get latest ratings
        $latest = array();
        foreach ($skill_progress as $item) {
            $key = $item->skill_category . '.' . $item->skill_name;
            $latest[$key] = array(
                'category' => $item->skill_category,
                'skill' => $item->skill_name,
                'rating' => intval($item->rating),
                'date' => $item->assessment_date,
            );
        }
        
        // Get active training plan
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_training_plans 
             WHERE player_id = %d AND status = 'active'
             ORDER BY created_at DESC LIMIT 1",
            $player_id
        ));
        
        if ($plan) {
            $plan->progress = PTP_Training_Plans::calculate_plan_progress($plan->id);
            $plan->milestones = PTP_Training_Plans::get_plan_milestones($plan->id);
        }
        
        return rest_ensure_response(array(
            'assessments' => $assessments,
            'skill_progress' => $skill_progress,
            'current_skills' => array_values($latest),
            'active_plan' => $plan,
        ));
    }
    
    // ================================================================
    // PLAYER ENDPOINTS
    // ================================================================
    
    /**
     * Get parent's players
     */
    public static function get_my_players($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d ORDER BY name",
            $parent->id
        ));
        
        return rest_ensure_response(array('players' => $players));
    }
    
    /**
     * Create player
     */
    public static function create_player($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $wpdb->insert($wpdb->prefix . 'ptp_players', array(
            'parent_id' => $parent->id,
            'name' => sanitize_text_field($request->get_param('name')),
            'age' => intval($request->get_param('age')),
            'gender' => sanitize_text_field($request->get_param('gender')),
            'skill_level' => sanitize_text_field($request->get_param('skill_level')),
            'position' => sanitize_text_field($request->get_param('position')),
            'current_team' => sanitize_text_field($request->get_param('current_team')),
            'goals' => sanitize_textarea_field($request->get_param('goals')),
            'notes' => sanitize_textarea_field($request->get_param('notes')),
        ));
        
        $player_id = $wpdb->insert_id;
        
        return rest_ensure_response(array(
            'player_id' => $player_id,
            'message' => 'Player created',
        ));
    }
    
    /**
     * Get single player
     */
    public static function get_player($request) {
        $auth = self::get_auth_user($request);
        $player_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        // Verify access (parent owns player, or trainer has worked with them)
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d",
            $player_id
        ));
        
        if (!$player) {
            return new WP_Error('not_found', 'Player not found', array('status' => 404));
        }
        
        // Check parent access
        if ($parent && $player->parent_id != $parent->id) {
            return new WP_Error('forbidden', 'Access denied', array('status' => 403));
        }
        
        // Check trainer access
        if ($trainer) {
            $has_access = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND player_id = %d LIMIT 1",
                $trainer->id, $player_id
            ));
            if (!$has_access) {
                return new WP_Error('forbidden', 'Access denied', array('status' => 403));
            }
        }
        
        return rest_ensure_response(array('player' => $player));
    }
    
    /**
     * Update player
     */
    public static function update_player($request) {
        $auth = self::get_auth_user($request);
        $player_id = intval($request->get_param('id'));
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d AND parent_id = %d",
            $player_id, $parent->id
        ));
        
        if (!$player) {
            return new WP_Error('not_found', 'Player not found', array('status' => 404));
        }
        
        $data = array();
        $allowed = array('name', 'age', 'gender', 'skill_level', 'position', 'current_team', 'goals', 'notes');
        
        foreach ($allowed as $field) {
            if ($request->has_param($field)) {
                $data[$field] = sanitize_text_field($request->get_param($field));
            }
        }
        
        if (!empty($data)) {
            $wpdb->update($wpdb->prefix . 'ptp_players', $data, array('id' => $player_id));
        }
        
        return self::get_player($request);
    }
    
    /**
     * Delete player
     */
    public static function delete_player($request) {
        $auth = self::get_auth_user($request);
        $player_id = intval($request->get_param('id'));
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d AND parent_id = %d",
            $player_id, $parent->id
        ));
        
        if (!$player) {
            return new WP_Error('not_found', 'Player not found', array('status' => 404));
        }
        
        // Soft delete - just deactivate
        $wpdb->update(
            $wpdb->prefix . 'ptp_players',
            array('is_active' => 0),
            array('id' => $player_id)
        );
        
        return rest_ensure_response(array('message' => 'Player removed'));
    }
    
    // ================================================================
    // PARENT DASHBOARD ENDPOINTS
    // ================================================================
    
    /**
     * Get parent profile
     */
    public static function get_parent_profile($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, u.user_email 
             FROM {$wpdb->prefix}ptp_parents p
             JOIN {$wpdb->users} u ON p.user_id = u.ID
             WHERE p.user_id = %d",
            $auth['user_id']
        ));
        
        if (!$parent) {
            return new WP_Error('not_found', 'Profile not found', array('status' => 404));
        }
        
        return rest_ensure_response(array('profile' => $parent));
    }
    
    /**
     * Update parent profile
     */
    public static function update_parent_profile($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $data = array();
        if ($request->has_param('display_name')) {
            $data['display_name'] = sanitize_text_field($request->get_param('display_name'));
        }
        if ($request->has_param('phone')) {
            $data['phone'] = sanitize_text_field($request->get_param('phone'));
        }
        if ($request->has_param('location')) {
            $data['location'] = sanitize_text_field($request->get_param('location'));
        }
        
        if (!empty($data)) {
            $wpdb->update($wpdb->prefix . 'ptp_parents', $data, array('id' => $parent->id));
        }
        
        return self::get_parent_profile($request);
    }
    
    /**
     * Get parent's bookings
     */
    public static function get_parent_bookings($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $status = $request->get_param('status');
        $upcoming = $request->get_param('upcoming');
        
        $where = "b.parent_id = %d";
        $params = array($parent->id);
        
        if ($status) {
            $where .= " AND b.status = %s";
            $params[] = $status;
        }
        
        if ($upcoming) {
            $where .= " AND b.session_date >= CURDATE()";
        }
        
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug,
                    COALESCE(p.name, CONCAT(p.first_name, ' ', p.last_name)) as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE {$where}
             ORDER BY b.session_date DESC, COALESCE(b.start_time, b.session_time) DESC",
            $params
        ));
        
        return rest_ensure_response(array('bookings' => $bookings));
    }
    
    /**
     * Get parent's group session registrations
     */
    public static function get_parent_group_sessions($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $registrations = PTP_Groups::get_parent_registrations($parent->id);
        
        return rest_ensure_response(array('registrations' => $registrations));
    }
    
    // ================================================================
    // MESSAGING ENDPOINTS
    // ================================================================
    
    /**
     * Get conversations
     */
    public static function get_conversations($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        
        // Check if user is trainer or parent
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        if ($trainer) {
            $conversations = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, p.display_name as other_name, p.user_id as other_user_id,
                        c.trainer_unread_count as unread_count
                 FROM {$wpdb->prefix}ptp_conversations c
                 JOIN {$wpdb->prefix}ptp_parents p ON c.parent_id = p.id
                 WHERE c.trainer_id = %d
                 ORDER BY c.last_message_at DESC",
                $trainer->id
            ));
            
            foreach ($conversations as $conv) {
                $conv->other_user = array(
                    'id' => $conv->other_user_id,
                    'name' => $conv->other_name,
                    'type' => 'parent',
                );
            }
        } elseif ($parent) {
            $conversations = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, t.display_name as other_name, t.photo_url as other_photo, t.user_id as other_user_id,
                        c.parent_unread_count as unread_count
                 FROM {$wpdb->prefix}ptp_conversations c
                 JOIN {$wpdb->prefix}ptp_trainers t ON c.trainer_id = t.id
                 WHERE c.parent_id = %d
                 ORDER BY c.last_message_at DESC",
                $parent->id
            ));
            
            foreach ($conversations as $conv) {
                $conv->other_user = array(
                    'id' => $conv->other_user_id,
                    'name' => $conv->other_name,
                    'photo_url' => $conv->other_photo,
                    'type' => 'trainer',
                );
            }
        } else {
            return rest_ensure_response(array('conversations' => array()));
        }
        
        return rest_ensure_response(array('conversations' => $conversations));
    }
    
    /**
     * Get messages in conversation
     */
    public static function get_messages($request) {
        $auth = self::get_auth_user($request);
        $conversation_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        // Verify access
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        if ($trainer) {
            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_conversations WHERE id = %d AND trainer_id = %d",
                $conversation_id, $trainer->id
            ));
        } elseif ($parent) {
            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_conversations WHERE id = %d AND parent_id = %d",
                $conversation_id, $parent->id
            ));
        } else {
            $conversation = null;
        }
        
        if (!$conversation) {
            return new WP_Error('not_found', 'Conversation not found', array('status' => 404));
        }
        
        $limit = intval($request->get_param('limit') ?: 50);
        $before_id = intval($request->get_param('before_id') ?: PHP_INT_MAX);
        
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_messages 
             WHERE conversation_id = %d AND id < %d
             ORDER BY created_at DESC
             LIMIT %d",
            $conversation_id, $before_id, $limit
        ));
        
        // Mark as read
        if ($trainer) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_conversations',
                array('trainer_unread_count' => 0),
                array('id' => $conversation_id)
            );
        } elseif ($parent) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_conversations',
                array('parent_unread_count' => 0),
                array('id' => $conversation_id)
            );
        }
        
        return rest_ensure_response(array('messages' => array_reverse($messages)));
    }
    
    /**
     * Send message
     */
    public static function send_message($request) {
        $auth = self::get_auth_user($request);
        $conversation_id = intval($request->get_param('id'));
        $content = sanitize_textarea_field($request->get_param('content'));
        
        if (empty($content)) {
            return new WP_Error('empty_message', 'Message cannot be empty', array('status' => 400));
        }
        
        global $wpdb;
        
        // Verify access and get conversation
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        if ($trainer) {
            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_conversations WHERE id = %d AND trainer_id = %d",
                $conversation_id, $trainer->id
            ));
        } elseif ($parent) {
            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_conversations WHERE id = %d AND parent_id = %d",
                $conversation_id, $parent->id
            ));
        } else {
            $conversation = null;
        }
        
        if (!$conversation) {
            return new WP_Error('not_found', 'Conversation not found', array('status' => 404));
        }
        
        $wpdb->insert($wpdb->prefix . 'ptp_messages', array(
            'conversation_id' => $conversation_id,
            'sender_id' => $auth['user_id'],
            'message' => $content,
        ));
        
        $message_id = $wpdb->insert_id;
        
        // Update conversation and increment unread for other party
        if ($trainer) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_conversations 
                 SET last_message_id = %d, last_message_at = %s, parent_unread_count = parent_unread_count + 1
                 WHERE id = %d",
                $message_id, current_time('mysql'), $conversation_id
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_conversations 
                 SET last_message_id = %d, last_message_at = %s, trainer_unread_count = trainer_unread_count + 1
                 WHERE id = %d",
                $message_id, current_time('mysql'), $conversation_id
            ));
        }
        
        // TODO: Send push notification
        
        return rest_ensure_response(array(
            'message_id' => $message_id,
            'message' => 'Message sent',
        ));
    }
    
    /**
     * Start new conversation
     */
    public static function start_conversation($request) {
        $auth = self::get_auth_user($request);
        $trainer_id = intval($request->get_param('trainer_id'));
        $content = sanitize_textarea_field($request->get_param('content'));
        
        global $wpdb;
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $auth['user_id']
        ));
        
        if (!$parent) {
            return new WP_Error('not_parent', 'Parent account required to message trainers', array('status' => 403));
        }
        
        // Get trainer's internal ID
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d OR user_id = %d",
            $trainer_id, $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('trainer_not_found', 'Trainer not found', array('status' => 404));
        }
        
        // Check if conversation exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_conversations 
             WHERE trainer_id = %d AND parent_id = %d",
            $trainer->id, $parent->id
        ));
        
        if ($existing) {
            $conversation_id = $existing->id;
        } else {
            $wpdb->insert($wpdb->prefix . 'ptp_conversations', array(
                'trainer_id' => $trainer->id,
                'parent_id' => $parent->id,
            ));
            $conversation_id = $wpdb->insert_id;
        }
        
        // Send first message
        if ($content) {
            $wpdb->insert($wpdb->prefix . 'ptp_messages', array(
                'conversation_id' => $conversation_id,
                'sender_id' => $auth['user_id'],
                'message' => $content,
            ));
            
            $message_id = $wpdb->insert_id;
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_conversations',
                array(
                    'last_message_id' => $message_id,
                    'last_message_at' => current_time('mysql'),
                    'trainer_unread_count' => 1,
                ),
                array('id' => $conversation_id)
            );
        }
        
        return rest_ensure_response(array('conversation_id' => $conversation_id));
    }
    
    /**
     * Mark messages as read
     */
    public static function mark_read($request) {
        $auth = self::get_auth_user($request);
        $conversation_id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $auth['user_id']
        ));
        
        if ($trainer) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_conversations',
                array('trainer_unread_count' => 0),
                array('id' => $conversation_id, 'trainer_id' => $trainer->id)
            );
        } else {
            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $auth['user_id']
            ));
            
            if ($parent) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_conversations',
                    array('parent_unread_count' => 0),
                    array('id' => $conversation_id, 'parent_id' => $parent->id)
                );
            }
        }
        
        return rest_ensure_response(array('message' => 'Marked as read'));
    }
    
    // ================================================================
    // PAYMENT ENDPOINTS
    // ================================================================
    
    /**
     * Create payment intent
     */
    public static function create_payment_intent($request) {
        $auth = self::get_auth_user($request);
        
        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            return new WP_Error('not_configured', 'Payments not configured', array('status' => 400));
        }
        
        $amount = floatval($request->get_param('amount'));
        $booking_id = intval($request->get_param('booking_id'));
        $group_session_id = intval($request->get_param('group_session_id'));
        
        if ($amount <= 0) {
            return new WP_Error('invalid_amount', 'Invalid amount', array('status' => 400));
        }
        
        $metadata = array('user_id' => $auth['user_id']);
        if ($booking_id) $metadata['booking_id'] = $booking_id;
        if ($group_session_id) $metadata['group_session_id'] = $group_session_id;
        
        // Add authenticated user's email and name for customer attachment
        $rest_user = get_user_by('ID', $auth['user_id']);
        if ($rest_user) {
            $metadata['customer_email'] = $rest_user->user_email;
            $metadata['customer_name'] = $rest_user->display_name;
        }
        
        $intent = PTP_Stripe::create_payment_intent($amount, $metadata);
        
        if (is_wp_error($intent)) {
            return new WP_Error('stripe_error', $intent->get_error_message(), array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'client_secret' => $intent['client_secret'],
            'payment_intent_id' => $intent['id'],
        ));
    }
    
    /**
     * Confirm payment completed
     */
    public static function confirm_payment($request) {
        $auth = self::get_auth_user($request);
        $payment_intent_id = sanitize_text_field($request->get_param('payment_intent_id'));
        $booking_id = intval($request->get_param('booking_id'));
        $group_session_id = intval($request->get_param('group_session_id'));
        
        global $wpdb;
        
        // Update booking payment status
        if ($booking_id) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'payment_status' => 'paid',
                    'payment_intent_id' => $payment_intent_id,
                    'paid_at' => current_time('mysql'),
                ),
                array('id' => $booking_id)
            );
            
            // v216.1: Create escrow hold for trainer payout system
            if (class_exists('PTP_Escrow')) {
                $existing_escrow = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_escrow WHERE booking_id = %d",
                    $booking_id
                ));
                if (!$existing_escrow) {
                    $booking = $wpdb->get_row($wpdb->prepare(
                        "SELECT total_amount FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                        $booking_id
                    ));
                    if ($booking && $booking->total_amount > 0) {
                        PTP_Escrow::create_hold($booking_id, $payment_intent_id, $booking->total_amount);
                    }
                }
            }
            
            // v216.1: Fire booking confirmed — triggers SMS, email, Google Calendar, Supabase sync, push
            do_action('ptp_booking_confirmed', $booking_id);
            
            // v136: Removed direct PTP_Email calls — handled by ptp_booking_confirmed hook above
            
            // Send SMS
            if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
                PTP_SMS::send_booking_confirmation($booking_id);
                PTP_SMS::send_trainer_new_booking($booking_id);
            }
        }
        
        // Update group session participant
        if ($group_session_id) {
            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $auth['user_id']
            ));
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_group_participants',
                array('payment_status' => 'paid', 'status' => 'confirmed'),
                array('group_session_id' => $group_session_id, 'parent_id' => $parent->id)
            );
        }
        
        return rest_ensure_response(array('message' => 'Payment confirmed'));
    }
    
    // ================================================================
    // NOTIFICATION ENDPOINTS
    // ================================================================
    
    /**
     * Get notifications
     */
    public static function get_notifications($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        
        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_notifications 
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT 50",
            $auth['user_id']
        ));
        
        $unread = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_notifications 
             WHERE user_id = %d AND is_read = 0",
            $auth['user_id']
        ));
        
        return rest_ensure_response(array(
            'notifications' => $notifications,
            'unread_count' => intval($unread),
        ));
    }
    
    /**
     * Mark notification as read
     */
    public static function mark_notification_read($request) {
        $auth = self::get_auth_user($request);
        $id = intval($request->get_param('id'));
        
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_notifications',
            array('is_read' => 1),
            array('id' => $id, 'user_id' => $auth['user_id'])
        );
        
        return rest_ensure_response(array('message' => 'Marked as read'));
    }
    
    /**
     * Mark all notifications as read
     */
    public static function mark_all_notifications_read($request) {
        $auth = self::get_auth_user($request);
        
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_notifications',
            array('is_read' => 1),
            array('user_id' => $auth['user_id'])
        );
        
        return rest_ensure_response(array('message' => 'All marked as read'));
    }
    
    // ============================================================
    // CHECKOUT ENDPOINTS (Bypass admin-ajax.php)
    // ============================================================
    
    /**
     * REST API: Checkout test endpoint
     * Verifies the API is working and checks configuration
     */
    public static function checkout_test($request) {
        $cart_available = function_exists('ptp_cart') && ptp_cart() ? 'yes' : 'no';
        $cart_total = 0;
        $cart_count = 0;
        
        if ($cart_available === 'yes') {
            ptp_cart()->calculate_totals();
            $cart_total = ptp_cart()->get_total('edit');
            $cart_count = ptp_cart()->get_item_count();
        }
        
        $test_mode = get_option('ptp_stripe_test_mode', true);
        $has_secret = !empty(get_option($test_mode ? 'ptp_stripe_test_secret' : 'ptp_stripe_live_secret'));
        if (!$has_secret) {
            $has_secret = !empty(get_option('ptp_stripe_secret_key'));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'status' => 'ok',
                'rest_api_working' => true,
                'ptp-native_cart' => $cart_available,
                'cart_total' => $cart_total,
                'cart_items' => $cart_count,
                'stripe_configured' => $has_secret ? 'yes' : 'no',
                'user_logged_in' => is_user_logged_in() ? 'yes' : 'no',
                'time' => current_time('mysql'),
            )
        ));
    }
    
    /**
     * REST API: Create payment intent
     * Bypasses admin-ajax.php which may be blocked by server firewalls
     */
    public static function checkout_create_intent($request) {
        ptp_log('PTP REST: checkout_create_intent called');
        
        // Verify nonce from request
        $nonce = $request->get_param('nonce');
        if (empty($nonce)) {
            ptp_log('PTP REST: Missing nonce');
            return new WP_Error('missing_nonce', 'Security token missing. Please refresh the page.', array('status' => 400));
        }
        
        if (!wp_verify_nonce($nonce, 'ptp_checkout') && !wp_verify_nonce($nonce, 'wp_rest')) {
            ptp_log('PTP REST: Invalid nonce');
            return new WP_Error('invalid_nonce', 'Session expired. Please refresh the page.', array('status' => 403));
        }
        
        // Ensure PTP Native is available
        if (!function_exists('ptp_cart') || !ptp_cart()) {
            ptp_log('PTP REST: PTP Native cart not available');
            return new WP_Error('cart_unavailable', 'Cart not available. Please refresh and try again.', array('status' => 500));
        }
        
        // Calculate cart total
        ptp_cart()->calculate_totals();
        $total = floatval(ptp_cart()->get_total('edit'));
        
        ptp_log('PTP REST: Cart total = ' . $total);
        
        if ($total < 0.50) {
            return new WP_Error('total_too_low', 'Order total too low ($' . number_format($total, 2) . ')', array('status' => 400));
        }
        
        // Get Stripe secret key
        $test_mode = get_option('ptp_stripe_test_mode', true);
        $secret_key = '';
        
        if ($test_mode) {
            $secret_key = get_option('ptp_stripe_test_secret', '');
            if (empty($secret_key)) {
                $secret_key = get_option('ptp_stripe_secret_key', '');
            }
        } else {
            $secret_key = get_option('ptp_stripe_live_secret', '');
            if (empty($secret_key)) {
                $secret_key = get_option('ptp_stripe_secret_key', '');
            }
        }
        
        if (empty($secret_key)) {
            ptp_log('PTP REST: No Stripe secret key configured');
            return new WP_Error('stripe_not_configured', 'Payment system not configured. Please contact support.', array('status' => 500));
        }
        
        // Create payment intent via Stripe API
        $amount = intval($total * 100);
        $currency = strtolower(ptp_get_currency());
        
        $stripe_body = array(
            'amount' => $amount,
            'currency' => $currency,
            'automatic_payment_methods[enabled]' => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
            'metadata[site]' => get_bloginfo('name'),
            'metadata[order_source]' => 'ptp_rest_checkout',
        );
        
        // v114.1: Removed receipt_email - PTP Native handles confirmation emails
        
        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Stripe-Version' => '2023-10-16',
            ),
            'body' => $stripe_body,
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('PTP REST: Stripe API error - ' . $response->get_error_message());
            return new WP_Error('stripe_error', 'Payment service unavailable. Please try again.', array('status' => 502));
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        ptp_log('PTP REST: Stripe response code: ' . $http_code);
        
        if ($http_code !== 200 || isset($body['error'])) {
            $error_msg = $body['error']['message'] ?? 'Payment initialization failed';
            ptp_log('PTP REST: Stripe error - ' . $error_msg);
            return new WP_Error('stripe_error', $error_msg, array('status' => 400));
        }
        
        if (empty($body['client_secret']) || empty($body['id'])) {
            ptp_log('PTP REST: Invalid Stripe response');
            return new WP_Error('invalid_response', 'Invalid payment response. Please try again.', array('status' => 500));
        }
        
        ptp_log('PTP REST: Payment intent created - ' . $body['id']);
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'clientSecret' => $body['client_secret'],
                'intentId' => $body['id'],
                'amount' => $amount,
                'currency' => $currency
            )
        ));
    }
    
    /**
     * REST API: Process checkout
     */
    public static function checkout_process($request) {
        ptp_log('PTP REST: checkout_process called');
        
        // Verify nonce
        $nonce = $request->get_param('nonce');
        if (!wp_verify_nonce($nonce, 'ptp_checkout') && !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('invalid_nonce', 'Session expired. Please refresh the page.', array('status' => 403));
        }
        
        $payment_intent_id = sanitize_text_field($request->get_param('payment_intent_id'));
        
        if (empty($payment_intent_id)) {
            return new WP_Error('missing_payment', 'Payment information missing.', array('status' => 400));
        }
        
        // Get billing info
        $billing = array(
            'first_name' => sanitize_text_field($request->get_param('billing_first_name')),
            'last_name' => sanitize_text_field($request->get_param('billing_last_name')),
            'email' => sanitize_email($request->get_param('billing_email')),
            'phone' => sanitize_text_field($request->get_param('billing_phone')),
        );
        
        // Create PTP Native order
        if (!function_exists('ptp_cart') || !ptp_cart()) {
            return new WP_Error('cart_unavailable', 'Cart not available.', array('status' => 500));
        }
        
        ptp_cart()->calculate_totals();
        
        $order = PTP_Native_Order_Manager::create_order();
        
        // Add cart items to order
        foreach (ptp_cart()->get_items() as $cart_item) {
            $order->add_product($cart_item['data'], $cart_item['quantity']);
        }
        
        // Set billing
        $order->set_billing_first_name($billing['first_name']);
        $order->set_billing_last_name($billing['last_name']);
        $order->set_billing_email($billing['email']);
        $order->set_billing_phone($billing['phone']);
        
        // Set payment
        $order->set_payment_method('stripe');
        $order->set_payment_method_title('Credit Card');
        $order->set_transaction_id($payment_intent_id);
        
        // Calculate totals and save
        $order->calculate_totals();
        $order->payment_complete($payment_intent_id);
        
        // Clear cart
        ptp_cart()->clear();
        
        // Get redirect URL
        $redirect = $order->get_checkout_order_received_url();
        
        ptp_log('PTP REST: Order created - ' . $order->get_id());
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'order_id' => $order->get_id(),
                'redirect' => $redirect
            )
        ));
    }
}
