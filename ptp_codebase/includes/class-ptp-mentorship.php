<?php
/**
 * PTP Mentorship v3 — Restructured Orchestrator
 * 
 * Camps → Training → Mentorship pipeline.
 * Everything feeds back to camps as the top-of-funnel.
 * 
 * Flow: Camp week → Kid bonds with coach → Interest form (auto or manual) →
 *       Free intro call → Package purchase (weekly billing) → Sessions → 
 *       Package expiring → Re-up outreach → Next camp season upsell
 * 
 * Sub-classes:
 *   PTP_Mentorship_Database   — Table creation & migrations
 *   PTP_Mentorship_Pipeline   — Camp→mentorship funnel hooks & URL rewrites
 *   PTP_Mentorship_Interest   — Interest form submission
 *   PTP_Mentorship_Sessions   — Session scheduling, completion, Zoom/Jitsi
 *   PTP_Mentorship_Billing    — Stripe purchase, cancel, webhooks, add-ons
 *   PTP_Mentorship_Content    — Videos, goals, challenges, plans, milestones
 *   PTP_Mentorship_Admin      — Admin page, stats, trainer management
 *   PTP_Mentorship_Touchpoints — Post-camp outreach sequences (separate file)
 */

if (!defined('ABSPATH')) exit;

class PTP_Mentorship {

    const VERSION = '3.1';

    // ================================================================
    // PACKAGES (session-based, not monthly subscription)
    // ================================================================
    const PACKAGES = array(
        'single' => array(
            'name'           => 'Single Session',
            'sessions'       => 1,
            'session_length' => 30,
            'per_session'    => 4900,
            'per_session_display' => 49,
            'total_display'  => 49,
            'billing'        => 'one-time',
            'desc'           => 'Try one session. No commitment.',
            'best_for'       => array('Try before you commit', 'One-off film review', 'Get to know your coach'),
            'recommended_age'=> '8-18',
            'color'          => '#3B82F6',
            'camp_pitch'     => 'Try one mentorship session with your camp coach.',
        ),
        'kickstart' => array(
            'name'           => 'Kickstart',
            'sessions'       => 12,
            'session_length' => 30,
            'per_session'    => 4900,
            'per_session_display' => 49,
            'total_display'  => 588,
            'billing'        => 'weekly',
            'desc'           => 'Build the relationship. Weekly 30-min calls with your coach.',
            'best_for'       => array('Building confidence', 'Goal setting', 'Having a role model who plays with them at camp'),
            'recommended_age'=> '8-13',
            'color'          => '#525252',
            'camp_pitch'     => 'Keep the connection going after camp week.',
        ),
        'development' => array(
            'name'           => 'Development',
            'sessions'       => 24,
            'session_length' => 45,
            'per_session'    => 6900,
            'per_session_display' => 69,
            'total_display'  => 1656,
            'billing'        => 'weekly',
            'desc'           => 'Go deeper. Film review, skill work, and real accountability.',
            'best_for'       => array('Film review & technique', 'Confidence & motivation', 'Goal setting & accountability', 'Position-specific coaching'),
            'recommended_age'=> '10-16',
            'color'          => '#FCB900',
            'camp_pitch'     => 'Your coach reviews your game film and builds a plan.',
        ),
        'elite' => array(
            'name'           => 'Elite',
            'sessions'       => 36,
            'session_length' => 60,
            'per_session'    => 8900,
            'per_session_display' => 89,
            'total_display'  => 3204,
            'billing'        => 'weekly',
            'desc'           => 'The full experience. Tryout prep, film breakdowns, mental game.',
            'best_for'       => array('Film review & technique', 'Tryout & college prep', 'Mental game coaching', 'Position mastery', 'Custom training plans'),
            'recommended_age'=> '12-18',
            'color'          => '#0A0A0A',
            'camp_pitch'     => 'Full 1:1 development with your camp coach year-round.',
        ),
    );

    // Landing page display tiers
    const TIERS = array(
        'single' => array(
            'name'              => 'Single Session',
            'badge'             => 'Try It',
            'price_display'     => '49',
            'desc'              => 'Try one session. See if the fit is right. No commitment.',
            'sessions'          => 1,
            'session_length'    => '30 min',
            'has_dm_access'     => false,
            'video_reviews'     => 0,
            'has_training_plan' => false,
            'one_on_one_calls'  => 1,
            'has_tryout_prep'   => false,
            'has_recruiting'    => false,
            'color'             => '#3B82F6',
            'class'             => '',
        ),
        'kickstart' => array(
            'name'              => 'Kickstart',
            'badge'             => '',
            'price_display'     => '49',
            'desc'              => 'Build the relationship. Weekly 30-min calls with your coach.',
            'sessions'          => 12,
            'session_length'    => '30 min',
            'has_dm_access'     => false,
            'video_reviews'     => 0,
            'has_training_plan' => false,
            'one_on_one_calls'  => 12,
            'has_tryout_prep'   => false,
            'has_recruiting'    => false,
            'color'             => '#525252',
            'class'             => '',
        ),
        'development' => array(
            'name'              => 'Development',
            'badge'             => 'Most Popular',
            'price_display'     => '69',
            'desc'              => 'Go deeper. Film review, skill work, and real accountability.',
            'sessions'          => 24,
            'session_length'    => '45 min',
            'has_dm_access'     => true,
            'video_reviews'     => 2,
            'has_training_plan' => true,
            'one_on_one_calls'  => 24,
            'has_tryout_prep'   => false,
            'has_recruiting'    => false,
            'color'             => '#FCB900',
            'class'             => 'popular',
        ),
        'elite' => array(
            'name'              => 'Elite',
            'badge'             => 'Pro Track',
            'price_display'     => '89',
            'desc'              => 'The full experience. Tryout prep, film breakdowns, mental game.',
            'sessions'          => 36,
            'session_length'    => '60 min',
            'has_dm_access'     => true,
            'video_reviews'     => 4,
            'has_training_plan' => true,
            'one_on_one_calls'  => 36,
            'has_tryout_prep'   => true,
            'has_recruiting'    => true,
            'color'             => '#0A0A0A',
            'class'             => 'premium',
        ),
    );

    const ADDONS = array(
        'video_review_5' => array(
            'name'   => 'Video Review Pack',
            'desc'   => '5 async video reviews from your coach',
            'price'  => 9900,
            'price_display' => 99,
            'credits'=> 5,
            'type'   => 'video_reviews',
        ),
        'film_breakdown' => array(
            'name'   => 'Film Breakdown Session',
            'desc'   => '1x 60-min deep-dive film session',
            'price'  => 12900,
            'price_display' => 129,
            'credits'=> 1,
            'type'   => 'film_breakdowns',
        ),
    );

    // Camp → Mentorship pipeline sources
    const SOURCES = array(
        'camp_upsell'     => 'Thank-you page after camp purchase',
        'camp_followup'   => 'Post-camp email/SMS sequence',
        'camp_coach_card' => 'Coach trading card QR code at camp',
        'training_upsell' => 'After 1:1 training session',
        'trainer_profile' => 'Trainer profile page on site',
        'direct'          => 'Direct / organic',
        'referral'        => 'Referred by another family',
    );

    const PLATFORM_FEE = 0.20;

    // ================================================================
    // INIT — delegates to sub-classes
    // ================================================================
    public static function init() {
        PTP_Mentorship_Database::init();
        PTP_Mentorship_Pipeline::init();
        PTP_Mentorship_Interest::init();
        PTP_Mentorship_Sessions::init();
        PTP_Mentorship_Billing::init();
        PTP_Mentorship_Content::init();
        PTP_Mentorship_Admin::init();

        // Shortcodes (public entry points)
        add_shortcode('ptp_mentorship_hub', array(__CLASS__, 'render_mentorship_hub'));
        add_shortcode('ptp_mentorship_signup', array(__CLASS__, 'render_signup_page'));
        add_shortcode('ptp_mentorship_interest', array(__CLASS__, 'render_interest_form'));
        add_shortcode('ptp_mentorship_checkout', array(__CLASS__, 'render_checkout_page'));

        // Notification hook wiring
        self::register_notification_hooks();
    }

    private static function register_notification_hooks() {
        // v233 L5: V2 handles all notification hooks — skip legacy registration
        if (class_exists('PTP_Mentorship_Notifications_V2')) return;

        $hooks = array(
            'ptp_mentorship_interest_submitted'  => 'mentorship_interest_submitted',
            'ptp_mentorship_intro_completed'     => 'mentorship_intro_completed',
            'ptp_mentorship_package_purchased'   => 'mentorship_package_purchased',
            'ptp_mentorship_video_submitted'     => 'mentorship_video_submitted',
            'ptp_mentorship_video_reviewed'      => 'mentorship_video_reviewed',
            'ptp_mentorship_session_scheduled'   => 'mentorship_session_scheduled',
            'ptp_mentorship_cancelled'           => 'mentorship_cancelled',
            'ptp_mentorship_package_completed'   => 'mentorship_package_completed',
            'ptp_mentorship_package_expiring'    => 'mentorship_package_expiring',
            'ptp_mentorship_payment_failed'      => 'mentorship_payment_failed',
        );
        foreach ($hooks as $action => $method) {
            add_action($action, array('PTP_Notifications', $method));
        }
        add_action('ptp_mentorship_session_recap_ready', array('PTP_Notifications', 'mentorship_session_recap_ready'), 10, 2);
    }

    // ================================================================
    // SHORTCODE RENDERERS
    // ================================================================
    public static function render_mentorship_hub() {
        ob_start();
        if (is_user_logged_in()) {
            include PTP_PLUGIN_DIR . 'templates/mentorship-dashboard.php';
        } else {
            include PTP_PLUGIN_DIR . 'templates/mentorship-landing.php';
        }
        return ob_get_clean();
    }

    public static function render_signup_page() {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/mentorship-signup.php';
        return ob_get_clean();
    }

    public static function render_interest_form() {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/mentorship-interest-form.php';
        return ob_get_clean();
    }

    public static function render_checkout_page() {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/mentorship-checkout.php';
        return ob_get_clean();
    }

    // ================================================================
    // SHARED UTILITIES (used across sub-classes)
    // ================================================================
    public static function get_fee() {
        if (function_exists('ptp_get_platform_fee')) {
            return ptp_get_platform_fee();
        }
        return self::PLATFORM_FEE;
    }

    public static function verify_nonce() {
        $nonce = $_POST['nonce'] ?? $_GET['nonce'] ?? '';
        if (wp_verify_nonce($nonce, 'ptp_nonce') || wp_verify_nonce($nonce, 'ptp_ajax_nonce')) return true;
        wp_send_json_error('Security check failed. Please refresh and try again.');
        exit;
    }

    public static function get_current_trainer() {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", get_current_user_id()
        ));
    }

    public static function get_stripe_key() {
        $settings = get_option('ptp_settings', array());
        $mode = $settings['stripe_mode'] ?? 'test';
        return ($mode === 'live')
            ? ($settings['stripe_live_secret_key'] ?? '')
            : ($settings['stripe_test_secret_key'] ?? '');
    }

    // ================================================================
    // BACKWARD COMPATIBILITY PROXIES
    // Any external code calling PTP_Mentorship::method() still works.
    // ================================================================
    public static function create_tables() {
        PTP_Mentorship_Database::create_tables();
    }

    public static function maybe_create_tables() {
        PTP_Mentorship_Database::maybe_create_tables();
    }

    public static function generate_meeting_url($trainer, $session_id, $type = 'one_on_one') {
        return PTP_Mentorship_Sessions::generate_meeting_url($trainer, $session_id, $type);
    }

    public static function get_meeting_info($url) {
        return PTP_Mentorship_Sessions::get_meeting_info($url);
    }

    public static function get_trainer_stats($trainer_id) {
        return PTP_Mentorship_Admin::get_trainer_stats($trainer_id);
    }

    public static function get_player_mentorship($player_id) {
        return PTP_Mentorship_Admin::get_player_mentorship($player_id);
    }
}
