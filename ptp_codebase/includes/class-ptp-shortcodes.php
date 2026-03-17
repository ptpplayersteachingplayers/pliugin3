<?php
/**
 * Shortcodes Class - Embedded CSS in templates
 * Auto-hides page titles on PTP shortcode pages
 */

defined('ABSPATH') || exit;

class PTP_Shortcodes {
    
    private static $css_output = false;
    
    // PTP Images array
    public static $images = array(
        'hero' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1915.jpg',
        'hero2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1899.jpg',
        'training1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1874.jpg',
        'training2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1847.jpg',
        'action1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1797.jpg',
        'action2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1790.jpg',
        'coach1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1595.jpg',
        'coach2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1563.jpg',
        'drill1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1539.jpg',
        'drill2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1520.jpg',
        'group1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1393.jpg',
        'group2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1356.jpg',
        'skill1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1288.jpg',
        'skill2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1283.jpg',
        'skill3' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1281.jpg',
        'skill4' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1279.jpg',
        'skill5' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1278.jpg',
        'skill6' => 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1272.jpg',
        'celebration' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/august-18-soccer-camp-goal-celebration.jpg-scaled.jpg',
        'signing1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-guests-signing-gear-3.jpg-scaled.jpg',
        'signing2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-guests-signing-2.jpg-scaled.jpg',
        'campers1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-guests-picture-summer-camp-5.jpg-scaled.jpg',
        'campers2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-guests-picture-summer-camp-4.jpg-scaled.jpg',
        'guest' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-guest-isiah-lefore-banner-photo-camp.jpg.jpg',
        'workout' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-coach-workout-cole-mcevoy.jpg.jpg',
        '1v1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-coach-versus-ptp-player-1v1-soccer-training.jpg.jpg',
        'longball' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-coach-training-session-long-ball.jpg.jpg',
        'winning1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-coaches-versus-camps-winning-pic-july-25.jpg-scaled.jpg',
        'winning2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-coaches-versus-campers-winning-pic.jpg.jpg',
        'coaches' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-coaches-july-16-group-photo.jpg.jpg',
        'feedback' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-coaches-feedback-with-campers.jpg.jpg',
        'drew1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-coach-drew-skill-show.jpg.jpg',
        'drew2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-coach-drew-skills-clinic.jpg.jpg',
        'receiving' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-clinic-receiving-a-ball.jpg.jpg',
        'dribbling' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/soccer-dribbling-small-game-.jpg-scaled.jpg',
        'ritas1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-summer-camp-ritas-party.jpg.jpg',
        'ritas2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-summer-camp-eating-ritas-on-bench-group-pic.jpg.jpg',
        'closing' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-summer-camp-closing-cermony.jpg.jpg',
        'clinic1v1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-skills-clinic-1v1.jpg.jpg',
        'ritas3' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-receiving-ritas-on-hot-day-soccer-camp.jpg.jpg',
        'july16' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-july-16-summer-camp-group-photo.jpg.jpg',
        'individual1' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-individual-training-john-2.jpg.jpg',
        'individual2' => 'https://ptpsummercamps.com/wp-content/uploads/2025/09/ptp-individual-training-coach-luke.jpg.jpg',
    );
    
    public static function init() {
        add_shortcode('ptp_home', array(__CLASS__, 'home_page'));
        add_shortcode('ptp_trainers_grid', array(__CLASS__, 'trainers_grid'));
        add_shortcode('ptp_trainer_profile', array(__CLASS__, 'trainer_profile'));
        add_shortcode('ptp_book', array(__CLASS__, 'booking_form'));
        add_shortcode('ptp_booking_form', array(__CLASS__, 'booking_form'));
        add_shortcode('ptp_booking_confirmation', array(__CLASS__, 'booking_confirmation'));
        add_shortcode('ptp_login', array(__CLASS__, 'login'));
        add_shortcode('ptp_register', array(__CLASS__, 'register'));
        add_shortcode('ptp_forgot_password', array(__CLASS__, 'forgot_password'));
        add_shortcode('ptp_reset_password', array(__CLASS__, 'reset_password'));
        add_shortcode('ptp_logout', array(__CLASS__, 'logout'));
        add_shortcode('ptp_apply', array(__CLASS__, 'trainer_application'));
        add_shortcode('ptp_trainer_application', array(__CLASS__, 'trainer_application'));
        add_shortcode('ptp_trainer_pending', array(__CLASS__, 'trainer_pending'));
        add_shortcode('ptp_debug', array(__CLASS__, 'debug_info'));
        add_shortcode('ptp_trainer_dashboard', array(__CLASS__, 'trainer_dashboard'));
        add_shortcode('ptp_trainer_onboarding', array(__CLASS__, 'trainer_onboarding'));
        add_shortcode('ptp_trainer_profile_editor', array(__CLASS__, 'trainer_profile_editor'));
        add_shortcode('ptp_parent_dashboard', array(__CLASS__, 'parent_dashboard'));
        add_shortcode('ptp_my_training', array(__CLASS__, 'my_training'));
        add_shortcode('ptp_messaging', array(__CLASS__, 'messaging'));
        add_shortcode('ptp_account', array(__CLASS__, 'account'));
        add_shortcode('ptp_find_trainers', array(__CLASS__, 'trainers_grid'));
        add_shortcode('ptp_review_page', array(__CLASS__, 'review_page'));
        
        // Advanced features
        add_shortcode('ptp_player_progress', array(__CLASS__, 'player_progress'));
        add_shortcode('ptp_training_plans', array(__CLASS__, 'training_plans'));
        // v193: ptp_training shortcode serves training-hub-v198.php
        // add_shortcode('ptp_training', array(__CLASS__, 'training_landing'));
        add_shortcode('ptp_group_sessions', array(__CLASS__, 'group_sessions'));
        
        // Bundle checkout
        add_shortcode('ptp_bundle_checkout', array(__CLASS__, 'bundle_checkout'));
        
        // Training checkout (for /checkout/?trainer_id=X&date=Y&time=Z URLs)
        add_shortcode('ptp_training_checkout', array(__CLASS__, 'training_checkout'));
        
        // Thank you page (v100 viral machine)
        add_shortcode('ptp_thank_you', array(__CLASS__, 'thank_you_page'));
        
        // v219: [ptp_homepage] is registered by ptp-homepage-builder plugin — do NOT override it here.
        // add_shortcode('ptp_homepage', array(__CLASS__, 'training_landing'));
        
        // v211: /training/ intercept at priority 0 — must fire BEFORE fix_training_homepage_conflict
        // at priority 1. This prevents the homepage conflict fixer from redirecting endlessly
        // when the training page is set as the WP front page.
        add_action('template_redirect', array(__CLASS__, 'intercept_training_page'), 0);
        
        // v211: /trainer/slug intercept at priority 2 — bulletproof trainer profile routing.
        // Catches /trainer/* URLs directly from the path before WordPress can 404, redirect,
        // or misroute them. Same pattern as intercept_training_page.
        add_action('template_redirect', array(__CLASS__, 'intercept_trainer_profile'), 2);
        
        // v187: Intercept full-page template pages at template_redirect
        // This fires BEFORE WordPress renders any theme header, preventing double headers
        add_action('template_redirect', array(__CLASS__, 'intercept_fullpage_templates'), 5);
        
        // Auto-hide page titles on PTP shortcode pages
        add_filter('body_class', array(__CLASS__, 'add_ptp_body_class'));
        add_action('wp_head', array(__CLASS__, 'hide_page_title_css'));
    }
    
    /**
     * v219: /training → serve fullpage directly (bypasses theme to prevent double header).
     * Homepage (/) is completely unaffected by this plugin.
     */
    public static function intercept_training_page() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        $request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        // v219: /training → serve fullpage directly (bypasses theme = no double header).
        // Homepage (/) is NEVER affected — training hub only lives at /training.
        if ($request_path === 'training') {
            self::serve_training_fullpage();
            exit;
        }
    }

    /**
     * v211: Dedicated /trainer/{slug} interceptor at priority 2.
     *
     * Bulletproof trainer profile routing. Checks the raw URL path for
     * /trainer/{slug}, looks up the trainer in the DB, sets globals,
     * and includes the profile template. This fires BEFORE WordPress
     * can 404, redirect, or misinterpret the URL as a child page.
     *
     * handle_trainer_profile() at priority 10 is kept as a legacy fallback.
     */
    public static function intercept_trainer_profile() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        $request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        // Match /trainer/{slug} — but not /trainer/ alone or /trainer-dashboard etc.
        if (!preg_match('#^trainer/([^/]+)/?$#', $request_path, $matches)) {
            return;
        }

        $slug = sanitize_text_field($matches[1]);

        // Skip static files
        if (strpos($slug, '.') !== false) {
            return;
        }

        // Look up the trainer
        global $wpdb;
        $trainer = null;

        if (class_exists('PTP_Trainer')) {
            $trainer = PTP_Trainer::get_by_slug($slug);
            if (!$trainer) {
                $trainer = PTP_Trainer::get_by_slug(sanitize_title($slug));
            }
            if (!$trainer && is_numeric($slug)) {
                $trainer = PTP_Trainer::get(intval($slug));
            }
        }

        // Direct DB fallback
        if (!$trainer) {
            $table = $wpdb->prefix . 'ptp_trainers';
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE slug = %s OR LOWER(slug) = LOWER(%s) 
                 OR LOWER(REPLACE(REPLACE(display_name, ' ', '-'), '.', '')) = LOWER(%s)
                 LIMIT 1",
                $slug, $slug, $slug
            ));
        }

        // Trainer not found or not active → let WordPress handle (404 or shortcode fallback)
        if (!$trainer || $trainer->status !== 'active') {
            return;
        }

        // Set globals for template
        $GLOBALS['ptp_trainer'] = $trainer;
        $GLOBALS['ptp_trainer_id'] = $trainer->id;
        $GLOBALS['ptp_current_trainer'] = $trainer;

        // Serve profile template directly
        status_header(200);
        nocache_headers();
        include PTP_PLUGIN_DIR . 'templates/trainer-profile-v177.php';
        exit;
    }

    /**
     * v187: Intercept pages that use full-page templates at template_redirect.
     * This fires BEFORE WordPress renders the theme header, preventing double
     * <!DOCTYPE html> / double header rendering on mobile and desktop.
     *
     * Pages intercepted: login, register, trainer-dashboard, parent-dashboard
     * NOTE: /training/ is handled by intercept_training_page() at priority 0.
     */
    public static function intercept_fullpage_templates() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        $request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        // / (homepage) → NOT a PTP page, skip entirely (v219)
        if ($request_path === '' || is_front_page()) {
            return;
        }

        if (!is_page()) {
            return;
        }

        $queried = get_queried_object();
        $page_slug = isset($queried->post_name) ? $queried->post_name : '';

        if (empty($page_slug)) {
            return;
        }

        switch ($page_slug) {
            case 'login':
                self::login(array());
                exit;

            case 'register':
                self::serve_register_fullpage();
                exit;

            case 'forgot-password':
                self::forgot_password(array());
                exit;

            case 'reset-password':
                self::reset_password(array());
                exit;

            case 'trainer-dashboard':
                self::trainer_dashboard(array());
                exit;

            case 'trainer-onboarding':
                self::trainer_onboarding(array());
                exit;

            case 'parent-dashboard':
                self::parent_dashboard(array());
                exit;

            case 'ptp-training':
                // v219: /ptp-training/ redirects to /training/
                wp_redirect(home_url('/training/'), 301);
                exit;

            case 'training':
                // v219: Safety net — normally caught by intercept_training_page() at priority 0
                self::serve_training_fullpage();
                exit;

            case 'free-training-promo':
            case 'free-session':
            case 'worldcup2026':
                // v231: Free session landing removed — redirect to training page
                wp_redirect(home_url('/training/'), 301);
                exit;

            case 'apply':
            case 'become-a-trainer':
                self::serve_apply_fullpage();
                exit;

            case 'mentorship':
            case 'mentorship-dashboard':
                include PTP_PLUGIN_DIR . 'templates/mentorship-landing.php';
                exit;

            case 'mentorship-signup':
                include PTP_PLUGIN_DIR . 'templates/mentorship-signup.php';
                exit;

            case 'mentorship-checkout':
                include PTP_PLUGIN_DIR . 'templates/mentorship-checkout.php';
                exit;
        }
    }

    /**
     * v187: Serve register template as a full-page (no get_header() needed).
     * The register.php template calls get_header() which would cause a double
     * header when loaded inside a shortcode. We serve it directly here.
     */
    private static function serve_register_fullpage() {
        if (is_user_logged_in() && !current_user_can('manage_options')) {
            wp_redirect(PTP_User::get_dashboard_url());
            exit;
        }

        include PTP_PLUGIN_DIR . 'templates/register.php';
        exit;
    }

    /**
     * v187.1: Serve training page as full-page, bypassing Astra footer.
     * 
     * The training page was loading via [ptp_training] shortcode inside the
     * WordPress theme, which meant Astra's get_footer() always fired and
     * rendered its footer HTML. No amount of CSS could reliably kill it
     * across all Astra configurations and Elementor footer widgets.
     *
     * Solution: Intercept at template_redirect, call get_header() for the
     * theme navigation, include the training template directly, then call
     * wp_footer() for scripts WITHOUT calling get_footer(). This means
     * the Astra footer HTML never renders at all.
     */
    private static function serve_training_fullpage() {
        // v198: New training hub page — utility page for existing/returning clients
        // v228: Free session promo removed 
        define('PTP_SERVING_FULLPAGE', true);
        include PTP_PLUGIN_DIR . 'templates/training-hub-v198.php';
        exit;
    }

    /**
     * v220: Serve /apply/ as fullpage — consistent with login/register.
     */
    private static function serve_apply_fullpage() {
        if (is_user_logged_in()) {
            $uid = get_current_user_id();
            if (class_exists('PTP_Trainer')) {
                $trainer = PTP_Trainer::get_by_user_id($uid);
                if ($trainer) { wp_redirect(home_url('/trainer-dashboard/')); exit; }
            }
            $u = wp_get_current_user();
            if (in_array('ptp_trainer', (array) $u->roles)) {
                wp_redirect(home_url('/trainer-dashboard/')); exit;
            }
        }
        get_header();
        include PTP_PLUGIN_DIR . 'templates/apply.php';
        get_footer();
        exit;
    }

    /**
     * Add body class when page contains PTP shortcode
     */
    public static function add_ptp_body_class($classes) {
        // Only run on singular pages when post is available
        if (!is_singular()) {
            return $classes;
        }

        // v219: Never add PTP classes to the front page
        $request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        if ($request_path === '' || is_front_page()) {
            return $classes;
        }
        
        global $post;
        if (!$post || !is_object($post)) {
            return $classes;
        }
        
        if (self::page_has_ptp_shortcode()) {
            $classes[] = 'ptp-page';
            $classes[] = 'ptp-hide-title';
        }
        return $classes;
    }
    
    /**
     * Check if current page has a PTP shortcode
     */
    public static function page_has_ptp_shortcode() {
        global $post;
        if (!$post || !is_object($post) || empty($post->post_content)) {
            return false;
        }
        
        // Simple string check - faster and safer than has_shortcode
        return (strpos($post->post_content, '[ptp_') !== false);
    }
    
    /**
     * Output CSS to hide page title on PTP pages
     */
    public static function hide_page_title_css() {
        // Only run on singular pages
        if (!is_singular()) {
            return;
        }

        // v219: Never inject PTP CSS on the front page
        $request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        if ($request_path === '' || is_front_page()) {
            return;
        }
        
        global $post;
        if (!$post || !is_object($post)) {
            return;
        }
        
        if (!self::page_has_ptp_shortcode()) {
            return;
        }
        ?>
        <style id="ptp-hide-title">
        /* Hide page title on PTP shortcode pages */
        .ptp-hide-title .entry-title,
        .ptp-hide-title .page-title,
        .ptp-hide-title article > header .entry-title,
        .ptp-hide-title .wp-block-post-title,
        .ptp-hide-title h1.entry-title,
        .ptp-hide-title h1.page-title,
        .ptp-hide-title .hentry > header,
        .ptp-hide-title .ast-the-title, /* Astra theme */
        .ptp-hide-title .elementor-page-title, /* Elementor */
        .ptp-hide-title .kadence-page-title, /* Kadence */
        .ptp-hide-title .generate-page-header, /* GeneratePress */
        .ptp-hide-title .site-content > article > .entry-header,
        .ptp-hide-title main article > header:first-child,
        .ptp-hide-title .single-post-title,
        .ptp-hide-title .page-header {
            display: none !important;
        }
        /* Also remove extra spacing from hidden titles */
        .ptp-hide-title .entry-content {
            margin-top: 0 !important;
        }
        .ptp-hide-title article > .entry-content:first-child {
            padding-top: 0 !important;
        }
        </style>
        <?php
    }
    
    /**
     * Output embedded CSS - call at start of each template
     */
    public static function embed_css() {
        if (self::$css_output) return '';
        self::$css_output = true;
        
        return '<style id="ptp-embedded-css">
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap");
.ptp-wrap{font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif!important;color:#111827!important;line-height:1.6!important;-webkit-font-smoothing:antialiased!important;box-sizing:border-box!important}
.ptp-wrap *,.ptp-wrap *::before,.ptp-wrap *::after{box-sizing:border-box!important}
.ptp-wrap a{text-decoration:none!important;color:inherit!important}
.ptp-wrap h1,.ptp-wrap h2,.ptp-wrap h3,.ptp-wrap h4{font-family:Inter,sans-serif!important;font-weight:800!important;line-height:1.1!important;letter-spacing:-0.02em!important;margin:0!important;padding:0!important}
.ptp-wrap p{margin:0!important}
.ptp-container{max-width:1280px!important;margin:0 auto!important;padding:0 24px!important}
.ptp-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;padding:14px 28px!important;font-family:Inter,sans-serif!important;font-size:15px!important;font-weight:600!important;letter-spacing:-0.01em!important;border-radius:12px!important;border:none!important;cursor:pointer!important;transition:all 0.2s ease!important;text-decoration:none!important;line-height:1!important}
.ptp-btn-primary{background:#FCB900!important;color:#0A0A0A!important}
.ptp-btn-primary:hover{background:#E5A800!important;transform:translateY(-2px)!important;box-shadow:0 8px 20px rgba(252,185,0,0.3)!important}
.ptp-btn-dark{background:#0A0A0A!important;color:#FFFFFF!important}
.ptp-btn-dark:hover{background:#1F2937!important}
.ptp-btn-outline{background:transparent!important;border:2px solid #E5E7EB!important;color:#374151!important}
.ptp-btn-outline:hover{border-color:#0A0A0A!important;color:#0A0A0A!important}
.ptp-btn-white{background:#FFFFFF!important;color:#0A0A0A!important}
.ptp-btn-sm{padding:10px 18px!important;font-size:14px!important}
.ptp-btn-lg{padding:18px 36px!important;font-size:17px!important}
.ptp-hero{position:relative!important;min-height:100vh!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:120px 24px!important;background:#0A0A0A!important;overflow:hidden!important;margin:0!important}
.ptp-hero-bg{position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;background-size:cover!important;background-position:center!important;opacity:0.5!important}
.ptp-hero-overlay{position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;background:linear-gradient(to bottom,rgba(14,15,17,0.4),rgba(14,15,17,0.9))!important}
.ptp-hero-content{position:relative!important;z-index:2!important;text-align:center!important;max-width:800px!important}
.ptp-hero-badge{display:inline-flex!important;align-items:center!important;gap:8px!important;background:rgba(252,185,0,0.15)!important;color:#FCB900!important;padding:8px 16px!important;border-radius:50px!important;font-size:14px!important;font-weight:600!important;margin-bottom:24px!important}
.ptp-hero-title{font-size:64px!important;font-weight:900!important;color:#FFFFFF!important;margin-bottom:24px!important;line-height:1.05!important;letter-spacing:-0.03em!important}
.ptp-hero-title span{color:#FCB900!important}
.ptp-hero-subtitle{font-size:20px!important;color:#9CA3AF!important;margin-bottom:48px!important;font-weight:400!important;line-height:1.6!important}
.ptp-location-search{background:#FFFFFF!important;border-radius:16px!important;padding:24px!important;max-width:480px!important;margin:0 auto 48px!important;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25)!important}
.ptp-location-search h3{font-size:18px!important;font-weight:700!important;color:#0A0A0A!important;margin-bottom:16px!important;text-align:center!important}
.ptp-location-input-wrap{display:flex!important;gap:12px!important}
.ptp-location-input{flex:1!important;padding:16px 20px!important;border:2px solid #E5E7EB!important;border-radius:12px!important;font-family:Inter,sans-serif!important;font-size:16px!important;outline:none!important;transition:border-color 0.2s!important}
.ptp-location-input:focus{border-color:#FCB900!important}
.ptp-location-btn{padding:16px 24px!important;background:#FCB900!important;color:#0A0A0A!important;border:none!important;border-radius:12px!important;font-family:Inter,sans-serif!important;font-size:15px!important;font-weight:600!important;cursor:pointer!important;transition:all 0.2s!important;white-space:nowrap!important}
.ptp-location-btn:hover{background:#E5A800!important}
.ptp-location-or{text-align:center!important;color:#6B7280!important;font-size:14px!important;margin:16px 0!important}
.ptp-use-location{display:flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;color:#0A0A0A!important;font-weight:600!important;cursor:pointer!important;padding:12px!important;border-radius:8px!important;transition:background 0.2s!important}
.ptp-use-location:hover{background:#F3F4F6!important}
.ptp-trust-badges{display:flex!important;justify-content:center!important;gap:24px!important;flex-wrap:wrap!important}
.ptp-trust-badge{display:flex!important;align-items:center!important;gap:8px!important;color:rgba(255,255,255,0.8)!important;font-size:14px!important;font-weight:500!important}
.ptp-trust-badge svg{width:20px!important;height:20px!important;color:#FCB900!important}
.ptp-section{padding:100px 0!important;margin:0!important}
.ptp-section-dark{background:#0A0A0A!important;color:#FFFFFF!important}
.ptp-section-gray{background:#F9FAFB!important}
.ptp-section-header{display:flex!important;justify-content:space-between!important;align-items:flex-end!important;margin-bottom:48px!important;gap:24px!important;flex-wrap:wrap!important}
.ptp-section-title{font-size:42px!important;font-weight:800!important;margin-bottom:12px!important;letter-spacing:-0.02em!important}
.ptp-section-subtitle{color:#6B7280!important;font-size:18px!important;font-weight:400!important}
.ptp-section-dark .ptp-section-subtitle{color:#9CA3AF!important}
.ptp-trainers-section{padding:100px 0!important;background:#FFFFFF!important}
.ptp-trainers-grid{display:grid!important;grid-template-columns:repeat(auto-fill,minmax(320px,1fr))!important;gap:28px!important}
.ptp-trainer-card{background:#FFFFFF!important;border-radius:20px!important;overflow:hidden!important;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1)!important;border:1px solid #F3F4F6!important;transition:all 0.3s ease!important;display:block!important}
.ptp-trainer-card:hover{transform:translateY(-8px)!important;box-shadow:0 25px 50px -12px rgba(0,0,0,0.15)!important}
.ptp-trainer-image{position:relative!important;height:280px!important;overflow:hidden!important;background:#F3F4F6!important}
.ptp-trainer-image img{width:100%!important;height:100%!important;object-fit:cover!important;transition:transform 0.3s ease!important}
.ptp-trainer-card:hover .ptp-trainer-image img{transform:scale(1.05)!important}
.ptp-trainer-badges{position:absolute!important;top:16px!important;left:16px!important;display:flex!important;gap:8px!important;flex-wrap:wrap!important}
.ptp-badge{padding:6px 12px!important;border-radius:50px!important;font-size:12px!important;font-weight:600!important}
.ptp-badge-featured{background:#FCB900!important;color:#0A0A0A!important}
.ptp-badge-verified{background:#10B981!important;color:#FFFFFF!important}
.ptp-badge-new{background:#3B82F6!important;color:#FFFFFF!important}
.ptp-trainer-info{padding:24px!important}
.ptp-trainer-rating{display:flex!important;align-items:center!important;gap:6px!important;font-size:15px!important;font-weight:600!important;margin-bottom:10px!important}
.ptp-trainer-rating .star{color:#FCB900!important}
.ptp-trainer-rating .count{color:#9CA3AF!important;font-weight:400!important}
.ptp-trainer-name{font-size:20px!important;font-weight:700!important;margin-bottom:6px!important;color:#0A0A0A!important}
.ptp-trainer-headline{color:#4B5563!important;font-size:15px!important;margin-bottom:10px!important}
.ptp-trainer-location{color:#6B7280!important;font-size:14px!important;margin-bottom:6px!important;display:flex!important;align-items:center!important;gap:6px!important}
.ptp-trainer-availability{color:#10B981!important;font-size:14px!important;font-weight:500!important;margin-bottom:20px!important}
.ptp-trainer-meta{display:flex!important;justify-content:space-between!important;align-items:center!important;padding-top:20px!important;border-top:1px solid #F3F4F6!important}
.ptp-trainer-price{font-size:28px!important;font-weight:800!important;color:#0A0A0A!important}
.ptp-trainer-price span{font-size:15px!important;font-weight:400!important;color:#6B7280!important}
.ptp-empty-state{text-align:center!important;padding:80px 24px!important;background:#F9FAFB!important;border-radius:20px!important}
.ptp-empty-icon{width:100px!important;height:100px!important;margin:0 auto 24px!important;background:#FFFFFF!important;border-radius:50%!important;display:flex!important;align-items:center!important;justify-content:center!important;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1)!important}
.ptp-empty-title{font-size:28px!important;font-weight:700!important;margin-bottom:12px!important}
.ptp-empty-text{color:#6B7280!important;margin-bottom:24px!important;font-size:16px!important}
.ptp-stats-row{display:grid!important;grid-template-columns:repeat(3,1fr)!important;gap:48px!important;max-width:900px!important;margin:0 auto!important;text-align:center!important}
.ptp-stat-value{font-size:64px!important;font-weight:900!important;color:#FCB900!important;line-height:1!important;margin-bottom:12px!important;letter-spacing:-0.02em!important}
.ptp-stat-label{color:#6B7280!important;font-size:16px!important;font-weight:500!important}
.ptp-section-dark .ptp-stat-label{color:#9CA3AF!important}
.ptp-gallery{display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:16px!important}
.ptp-gallery-item{aspect-ratio:1!important;border-radius:16px!important;overflow:hidden!important}
.ptp-gallery-item img{width:100%!important;height:100%!important;object-fit:cover!important;transition:transform 0.3s!important}
.ptp-gallery-item:hover img{transform:scale(1.1)!important}
.ptp-auth-page{min-height:100vh!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:40px 24px!important;background:#F9FAFB!important}
.ptp-auth-box{width:100%!important;max-width:440px!important;background:#FFFFFF!important;border-radius:24px!important;box-shadow:0 25px 50px -12px rgba(0,0,0,0.1)!important;padding:48px 40px!important}
.ptp-auth-logo{text-align:center!important;margin-bottom:32px!important}
.ptp-auth-title{font-size:32px!important;font-weight:800!important;text-align:center!important;margin-bottom:8px!important}
.ptp-auth-subtitle{text-align:center!important;color:#6B7280!important;margin-bottom:32px!important;font-size:16px!important}
.ptp-auth-footer{text-align:center!important;margin-top:24px!important;color:#6B7280!important;font-size:15px!important}
.ptp-auth-footer a{color:#0A0A0A!important;font-weight:600!important}
.ptp-auth-divider{display:flex!important;align-items:center!important;gap:16px!important;margin:24px 0!important;color:#9CA3AF!important;font-size:14px!important}
.ptp-auth-divider::before,.ptp-auth-divider::after{content:""!important;flex:1!important;height:1px!important;background:#E5E7EB!important}
.ptp-form-group{margin-bottom:20px!important}
.ptp-form-label{display:block!important;font-size:14px!important;font-weight:600!important;color:#374151!important;margin-bottom:8px!important}
.ptp-form-input,.ptp-form-select,.ptp-form-textarea{width:100%!important;padding:14px 16px!important;border:2px solid #E5E7EB!important;border-radius:12px!important;font-family:Inter,sans-serif!important;font-size:16px!important;transition:border-color 0.2s!important;background:#FFFFFF!important;color:#111827!important;outline:none!important}
.ptp-form-input:focus,.ptp-form-select:focus,.ptp-form-textarea:focus{border-color:#FCB900!important;box-shadow:0 0 0 3px rgba(252,185,0,0.1)!important}
.ptp-form-row{display:grid!important;grid-template-columns:1fr 1fr!important;gap:16px!important}
.ptp-form-hint{font-size:13px!important;color:#6B7280!important;margin-top:6px!important}
.ptp-card{background:#FFFFFF!important;border-radius:20px!important;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1)!important;border:1px solid #F3F4F6!important;overflow:hidden!important}
.ptp-card-header{padding:24px!important;border-bottom:1px solid #F3F4F6!important}
.ptp-card-header-dark{background:#0A0A0A!important;color:#FFFFFF!important;border-bottom:none!important}
.ptp-card-title{font-size:18px!important;font-weight:700!important;margin:0!important}
.ptp-card-body{padding:24px!important}
.ptp-dashboard{background:#F9FAFB!important;min-height:100vh!important;padding:32px 0!important}
.ptp-dashboard-header{background:#0A0A0A!important;padding:40px!important;border-radius:24px!important;margin-bottom:32px!important;color:#FFFFFF!important}
.ptp-dashboard-title{font-size:36px!important;font-weight:800!important;margin-bottom:8px!important;color:#FFFFFF!important}
.ptp-dashboard-subtitle{color:#9CA3AF!important;font-size:16px!important}
.ptp-stat-cards{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))!important;gap:24px!important}
.ptp-stat-card{background:#FFFFFF!important;border-radius:20px!important;padding:28px!important;border:1px solid #F3F4F6!important}
.ptp-stat-card-icon{width:56px!important;height:56px!important;border-radius:14px!important;display:flex!important;align-items:center!important;justify-content:center!important;margin-bottom:20px!important}
.ptp-stat-card-icon.yellow{background:#FEF3C7!important}
.ptp-stat-card-icon.green{background:#D1FAE5!important}
.ptp-stat-card-icon.blue{background:#DBEAFE!important}
.ptp-stat-card-value{font-size:36px!important;font-weight:800!important;color:#0A0A0A!important;margin-bottom:4px!important}
.ptp-stat-card-label{color:#6B7280!important;font-size:15px!important}
.ptp-table-wrap{overflow-x:auto!important}
.ptp-table{width:100%!important;border-collapse:collapse!important}
.ptp-table th{text-align:left!important;padding:14px 16px!important;font-size:13px!important;font-weight:600!important;text-transform:uppercase!important;letter-spacing:0.5px!important;color:#6B7280!important;border-bottom:1px solid #E5E7EB!important}
.ptp-table td{padding:18px 16px!important;border-bottom:1px solid #F3F4F6!important}
.ptp-table tr:hover td{background:#F9FAFB!important}
.ptp-status{display:inline-flex!important;align-items:center!important;padding:6px 12px!important;border-radius:50px!important;font-size:13px!important;font-weight:600!important}
.ptp-status-confirmed,.ptp-status-active,.ptp-status-completed{background:#D1FAE5!important;color:#065F46!important}
.ptp-status-pending{background:#FEF3C7!important;color:#92400E!important}
.ptp-status-cancelled,.ptp-status-rejected{background:#FEE2E2!important;color:#991B1B!important}
.ptp-alert{padding:16px 20px!important;border-radius:12px!important;margin-bottom:20px!important;font-weight:500!important}
.ptp-alert-success{background:#D1FAE5!important;color:#065F46!important}
.ptp-alert-error{background:#FEE2E2!important;color:#991B1B!important}
.ptp-alert-warning{background:#FEF3C7!important;color:#92400E!important}
.ptp-alert-info{background:#DBEAFE!important;color:#1E40AF!important}
.ptp-filters{display:flex!important;gap:12px!important}
.ptp-filter-select{padding:12px 16px!important;border:2px solid #E5E7EB!important;border-radius:12px!important;font-family:Inter,sans-serif!important;font-size:14px!important;font-weight:500!important;background:#FFFFFF!important;cursor:pointer!important;min-width:160px!important}
@media(max-width:768px){.ptp-hero{padding:80px 16px!important;min-height:auto!important}.ptp-hero-title{font-size:40px!important}.ptp-hero-subtitle{font-size:17px!important}.ptp-location-input-wrap{flex-direction:column!important}.ptp-trust-badges{gap:16px!important}.ptp-section{padding:60px 0!important}.ptp-section-header{flex-direction:column!important;text-align:center!important}.ptp-section-title{font-size:32px!important}.ptp-trainers-grid{grid-template-columns:1fr!important}.ptp-stats-row{grid-template-columns:1fr!important;gap:32px!important}.ptp-stat-value{font-size:48px!important}.ptp-gallery{grid-template-columns:repeat(2,1fr)!important}.ptp-form-row{grid-template-columns:1fr!important}.ptp-auth-box{padding:32px 24px!important}.ptp-container{padding:0 16px!important}}
</style>';
    }
    
    public static function home_page($atts) {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/home.php';
        return ob_get_clean();
    }
    
    public static function trainers_grid($atts) {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/trainers-grid.php';
        return ob_get_clean();
    }
    
    public static function trainer_profile($atts) {
        // Try multiple methods to get the trainer identifier
        $trainer_param = '';
        $debug_info = array();
        
        // Method 1: Query var from rewrite rule
        $trainer_param = get_query_var('trainer_slug');
        if ($trainer_param) $debug_info[] = 'query_var: ' . $trainer_param;
        
        // Method 2: GET parameter (?trainer=slug or ?trainer=123)
        if (empty($trainer_param)) {
            $trainer_param = isset($_GET['trainer']) ? sanitize_text_field($_GET['trainer']) : '';
            if ($trainer_param) $debug_info[] = 'get_param: ' . $trainer_param;
        }
        
        // Method 3: Parse from pretty URL /trainer/slug/
        if (empty($trainer_param)) {
            $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
            if (preg_match('#trainer/([^/]+)/?$#', $path, $matches)) {
                $trainer_param = sanitize_text_field($matches[1]);
                if ($trainer_param) $debug_info[] = 'url_path: ' . $trainer_param;
            }
        }
        
        // Method 4: Check for trainer ID in path /trainer-profile/123
        if (empty($trainer_param)) {
            $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
            if (preg_match('#trainer-profile/(\d+)/?$#', $path, $matches)) {
                $trainer_param = sanitize_text_field($matches[1]);
                if ($trainer_param) $debug_info[] = 'profile_path: ' . $trainer_param;
            }
        }
        
        // Method 5: Check URL for any trainer-like segment
        if (empty($trainer_param)) {
            $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
            $segments = explode('/', $path);
            foreach ($segments as $i => $segment) {
                if ($segment === 'trainer' && isset($segments[$i + 1]) && !empty($segments[$i + 1])) {
                    $trainer_param = sanitize_text_field($segments[$i + 1]);
                    if ($trainer_param) $debug_info[] = 'segment: ' . $trainer_param;
                    break;
                }
            }
        }
        
        if (empty($trainer_param)) {
            ptp_log('PTP Trainer Profile: No trainer parameter found. URL: ' . $_SERVER['REQUEST_URI']);
            return '<div class="ptp-wrap">' . self::embed_css() . '
                <div style="padding: 40px; text-align: center;">
                    <h2 style="margin-bottom: 16px;">Trainer Not Found</h2>
                    <p style="color: #6B7280;">Please select a trainer from our directory.</p>
                    <a href="' . esc_url(home_url('/find-trainers/')) . '" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #FCB900; color: #0A0A0A; font-weight: 600; border-radius: 8px; text-decoration: none;">Find a Trainer</a>
                </div>
            </div>';
        }
        
        // Try to find trainer by ID first, then slug
        $trainer = null;
        
        // If numeric, try as ID
        if (is_numeric($trainer_param)) {
            $trainer = PTP_Trainer::get(intval($trainer_param));
            if ($trainer) $debug_info[] = 'found_by_id';
        }
        
        // Try exact slug match
        if (!$trainer) {
            $trainer = PTP_Trainer::get_by_slug($trainer_param);
            if ($trainer) $debug_info[] = 'found_by_slug';
        }
        
        // Try with sanitized slug (handles URL encoding)
        if (!$trainer) {
            $trainer = PTP_Trainer::get_by_slug(sanitize_title($trainer_param));
            if ($trainer) $debug_info[] = 'found_by_sanitized_slug';
        }
        
        // Try URL decoded version
        if (!$trainer) {
            $trainer = PTP_Trainer::get_by_slug(urldecode($trainer_param));
            if ($trainer) $debug_info[] = 'found_by_decoded_slug';
        }
        
        // Log debug info for troubleshooting
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log('PTP Trainer Profile Debug: ' . implode(', ', $debug_info));
        }
        
        if (!$trainer) {
            ptp_log('PTP Trainer Profile: Trainer not found for param: ' . $trainer_param . ' | Debug: ' . implode(', ', $debug_info));
            return '<div class="ptp-wrap">' . self::embed_css() . '
                <div style="padding: 40px; text-align: center;">
                    <h2 style="margin-bottom: 16px;">Trainer Not Found</h2>
                    <p style="color: #6B7280;">This trainer may no longer be available or the link may be incorrect.</p>
                    <a href="' . esc_url(home_url('/find-trainers/')) . '" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #FCB900; color: #0A0A0A; font-weight: 600; border-radius: 8px; text-decoration: none;">Browse All Trainers</a>
                </div>
            </div>';
        }
        
        if ($trainer->status !== 'active') {
            ptp_log('PTP Trainer Profile: Trainer ' . $trainer_param . ' (ID: ' . $trainer->id . ') status is: ' . $trainer->status);
            return '<div class="ptp-wrap">' . self::embed_css() . '
                <div style="padding: 40px; text-align: center;">
                    <h2 style="margin-bottom: 16px;">Trainer Unavailable</h2>
                    <p style="color: #6B7280;">This trainer is not currently accepting bookings.</p>
                    <a href="' . esc_url(home_url('/find-trainers/')) . '" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #FCB900; color: #0A0A0A; font-weight: 600; border-radius: 8px; text-decoration: none;">Find Another Trainer</a>
                </div>
            </div>';
        }
        
        // Pass trainer to template via global (template will use this instead of re-fetching)
        global $ptp_current_trainer;
        $ptp_current_trainer = $trainer;
        
        // v132.0.2: Output full-page template directly and exit
        // The trainer profile template is a complete HTML document
        include PTP_PLUGIN_DIR . 'templates/trainer-profile-v177.php';
        
        // Clean up global
        $ptp_current_trainer = null;
        
        exit; // Full-page template
    }
    
    public static function booking_form($atts) {
        $css = self::embed_css();
        
        // Accept trainer_id, trainer, or slug parameters
        $trainer_param = isset($_GET['trainer_id']) ? sanitize_text_field($_GET['trainer_id']) : '';
        if (empty($trainer_param)) {
            $trainer_param = isset($_GET['trainer']) ? sanitize_text_field($_GET['trainer']) : '';
        }
        
        if (empty($trainer_param)) {
            return '<div class="ptp-wrap">' . $css . '<p>Please select a trainer first.</p></div>';
        }

        if (is_numeric($trainer_param)) {
            $trainer = PTP_Trainer::get(intval($trainer_param));
        } else {
            $trainer = PTP_Trainer::get_by_slug($trainer_param);
        }

        if (!$trainer || $trainer->status !== 'active') {
            return '<div class="ptp-wrap">' . $css . '<p>Trainer not found.</p></div>';
        }
        
        // If we have date/time/location, use the new checkout template
        $date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : '';
        $time = isset($_GET['time']) ? sanitize_text_field($_GET['time']) : '';
        
        if ($date && $time) {
            // Use the new book-session checkout template
            ob_start();
            $template = PTP_PLUGIN_DIR . 'templates/book-session.php';
            if (file_exists($template)) {
                include $template;
            } else {
                echo '<div class="ptp-wrap" style="padding: 40px; text-align: center;">
                    <h2>Book Session</h2>
                    <p>Booking with ' . esc_html($trainer->display_name) . '</p>
                    <p>Date: ' . esc_html($date) . ' at ' . esc_html($time) . '</p>
                    <p><a href="' . home_url('/trainer/' . $trainer->slug . '/') . '">← Back to Trainer Profile</a></p>
                </div>';
            }
            return ob_get_clean();
        }
        
        // Otherwise, redirect to trainer profile to select date/time
        wp_redirect(home_url('/trainer/' . $trainer->slug . '/'));
        exit;
    }
    
    public static function booking_confirmation($atts) {
        $css = self::embed_css();
        
        // v117.2.23: Support multiple param formats
        // Priority: booking_id (numeric) > bn (booking_number) > booking (legacy - could be either)
        $booking = null;
        $booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
        $booking_number = sanitize_text_field($_GET['bn'] ?? '');
        $legacy_booking = sanitize_text_field($_GET['booking'] ?? '');
        
        // Try by numeric ID first
        if ($booking_id) {
            $booking = PTP_Booking::get_full($booking_id);
        }
        
        // Try by booking_number (bn param)
        if (!$booking && $booking_number) {
            $booking_by_number = PTP_Booking::get_by_number($booking_number);
            if ($booking_by_number) {
                $booking = PTP_Booking::get_full($booking_by_number->id);
            }
        }
        
        // Try legacy 'booking' param - could be number or alphanumeric
        if (!$booking && $legacy_booking) {
            // First try as numeric ID
            if (is_numeric($legacy_booking)) {
                $booking = PTP_Booking::get_full(intval($legacy_booking));
            }
            // Then try as booking_number (alphanumeric)
            if (!$booking) {
                $booking_by_number = PTP_Booking::get_by_number($legacy_booking);
                if ($booking_by_number) {
                    $booking = PTP_Booking::get_full($booking_by_number->id);
                }
            }
        }
        
        // Try cookie fallback
        if (!$booking && isset($_COOKIE['ptp_last_booking'])) {
            $cookie_id = intval($_COOKIE['ptp_last_booking']);
            if ($cookie_id) {
                $booking = PTP_Booking::get_full($cookie_id);
            }
        }
        
        if (!$booking) {
            return '<div class="ptp-wrap">' . $css . '<p>Booking not found. Please check your confirmation email for details.</p></div>';
        }
        
        // v117.2.23: Try to use thank-you-v100 template if it exists (better UX)
        $v100_template = PTP_PLUGIN_DIR . 'templates/thank-you-v100.php';
        if (file_exists($v100_template)) {
            // Set variables expected by thank-you-v100.php
            $_GET['booking_id'] = $booking->id;
            $booking_id = $booking->id;
            ob_start();
            include $v100_template;
            return ob_get_clean();
        }
        
        // Fallback to legacy template
        ob_start();
        $template = PTP_PLUGIN_DIR . 'templates/booking-confirmation.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="ptp-wrap" style="padding: 40px; text-align: center;">
                <h2>Booking Confirmed!</h2>
                <p>Your session has been booked successfully.</p>
                <p>Booking #: ' . esc_html($booking->booking_number ?? $booking->id) . '</p>
                <p><a href="' . home_url('/parent-dashboard/') . '">View My Dashboard →</a></p>
            </div>';
        }
        return ob_get_clean();
    }
    
    public static function my_training($atts) {
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/'));
            exit;
        }
        
        $parent = PTP_Parent::get_by_user_id(get_current_user_id());
        if (!$parent) {
            $user = wp_get_current_user();
            $parent_id = PTP_Parent::create(get_current_user_id(), array(
                'display_name' => $user->display_name,
            ));
            $parent = PTP_Parent::get($parent_id);
        }
        
        $players = PTP_Player::get_by_parent($parent->id);
        $upcoming = PTP_Parent::get_upcoming_sessions($parent->id);
        $history = PTP_Parent::get_past_sessions($parent->id);
        
        ob_start();
        $tmpl = PTP_PLUGIN_DIR . 'templates/my-training.php';
        if (file_exists($tmpl)) {
            include $tmpl;
        } else {
            echo '<div class="ptp-wrap" style="padding:40px;text-align:center;"><h2>My Training</h2><p>Coming soon.</p></div>';
        }
        return ob_get_clean();
    }
    
    /**
     * Review page - reads booking_id from URL parameter
     */
    public static function review_page($atts) {
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/?redirect=' . urlencode($_SERVER['REQUEST_URI'])));
            exit;
        }
        
        // Get booking ID from URL
        $booking_id = isset($_GET['booking']) ? intval($_GET['booking']) : 0;
        
        if (!$booking_id) {
            return '<div class="ptp-wrap" style="padding:40px;text-align:center;">
                <h2>Session Not Found</h2>
                <p style="color:#6B7280;">No session specified for review.</p>
                <a href="' . esc_url(home_url('/my-training/')) . '" style="display:inline-block;margin-top:20px;padding:12px 24px;background:#FCB900;color:#0A0A0A;font-weight:600;text-decoration:none;">Back to My Training</a>
            </div>';
        }
        
        // Use the quality control review form
        if (class_exists('PTP_Quality_Control')) {
            $qc = PTP_Quality_Control::instance();
            return $qc->shortcode_review_form(array('booking_id' => $booking_id));
        }
        
        // Fallback if Quality Control class not available
        return '<div class="ptp-wrap" style="padding:40px;text-align:center;">
            <h2>Review Unavailable</h2>
            <p style="color:#6B7280;">The review system is temporarily unavailable.</p>
            <a href="' . esc_url(home_url('/my-training/')) . '" style="display:inline-block;margin-top:20px;padding:12px 24px;background:#FCB900;color:#0A0A0A;font-weight:600;text-decoration:none;">Back to My Training</a>
        </div>';
    }
    
    public static function trainer_dashboard($atts) {
        // Start output buffering to capture any errors
        ob_start();
        
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/?redirect_to=' . urlencode(home_url('/trainer-dashboard/'))));
            exit;
        }
        
        $current_user_id = get_current_user_id();
        $current_user = wp_get_current_user();
        
        global $wpdb;
        
        // First check if trainer tables exist (v227: cached)
        $table_name = $wpdb->prefix . 'ptp_trainers';
        $table_exists = class_exists('PTP_Query_Cache') ? PTP_Query_Cache::table_exists($table_name) : $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        
        if (!$table_exists) {
            // Tables don't exist - try to create them
            if (class_exists('PTP_Database')) {
                PTP_Database::create_tables();
                // Recheck (cache was flushed by create_tables)
                $table_exists = class_exists('PTP_Query_Cache') ? PTP_Query_Cache::table_exists($table_name) : $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
            }
            
            if (!$table_exists) {
                ob_end_clean();
                return '<div style="padding:40px;text-align:center;font-family:Inter,sans-serif;">
                    <h2>Database Setup Required</h2>
                    <p>The trainer tables need to be created. Please contact an administrator.</p>
                    <a href="' . home_url() . '" style="color:#FCB900;">Return to Home</a>
                </div>';
            }
        }
        
        // Get trainer record by user_id
        $trainer = null;
        if (class_exists('PTP_Trainer') && method_exists('PTP_Trainer', 'get_by_user_id')) {
            $trainer = PTP_Trainer::get_by_user_id($current_user_id);
        }
        
        // Fallback: direct query
        if (!$trainer) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
                $current_user_id
            ));
        }
        
        // Try to find by email if not found by user_id
        if (!$trainer) {
            $user_email = $current_user->user_email;
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE email = %s",
                $user_email
            ));
            
            // v176: Only auto-link if user has the ptp_trainer role (admin-approved)
            // or is an admin. Prevents account takeover via email registration.
            if ($trainer && (empty($trainer->user_id) || $trainer->user_id == 0)) {
                $is_approved_trainer = in_array('ptp_trainer', (array) $current_user->roles);
                $is_admin = current_user_can('manage_options');
                
                if ($is_approved_trainer || $is_admin) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_trainers',
                        array('user_id' => $current_user_id),
                        array('id' => $trainer->id),
                        array('%d'),
                        array('%d')
                    );
                    $trainer->user_id = $current_user_id;
                } else {
                    // User has matching email but no trainer role — don't grant access
                    ptp_log('PTP Security: Blocked auto-link for user ' . $current_user_id . ' to trainer ' . $trainer->id . ' (no ptp_trainer role)');
                    $trainer = null;
                }
            }
        }
        
        if (!$trainer) {
            // No trainer record - check if they have the trainer role
            if (in_array('ptp_trainer', (array) $current_user->roles)) {
                // They have the role but no trainer record = pending approval
                ob_end_clean();
                include PTP_PLUGIN_DIR . 'templates/trainer-pending.php';
                exit;
            }
            
            // Check if admin viewing specific trainer
            if (current_user_can('manage_options') && isset($_GET['trainer_id'])) {
                $trainer_id = intval($_GET['trainer_id']);
                if (class_exists('PTP_Trainer')) {
                    $trainer = PTP_Trainer::get($trainer_id);
                } else {
                    $trainer = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                        $trainer_id
                    ));
                }
            }
            
            if (!$trainer) {
                // No record, no role - redirect to apply
                wp_redirect(home_url('/apply/'));
                exit;
            }
        }
        
        // Ensure trainer has required fields
        if (empty($trainer->slug)) {
            $trainer->slug = sanitize_title($trainer->display_name ?: $trainer->first_name . ' ' . $trainer->last_name);
            if (!empty($trainer->slug)) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    array('slug' => $trainer->slug),
                    array('id' => $trainer->id)
                );
            }
        }
        
        // Check onboarding status
        $onboarding_complete = get_user_meta($trainer->user_id, 'ptp_onboarding_completed', true);
        if (empty($onboarding_complete) && !isset($_GET['skip_onboarding'])) {
            // Check if profile is basically complete
            $has_basics = !empty($trainer->display_name) && !empty($trainer->hourly_rate) && $trainer->hourly_rate > 0;
            if (!$has_basics) {
                wp_redirect(home_url('/trainer-onboarding/'));
                exit;
            }
        }
        
        // Initialize variables with safe defaults
        $upcoming = array();
        $pending_confirmations = array();
        $earnings = array(
            'this_month' => 0,
            'total_earnings' => 0,
            'pending_payout' => 0,
            'last_payout' => null
        );
        $availability = array();
        $completion = array('percentage' => 0, 'missing' => array());
        
        // Get bookings
        if (class_exists('PTP_Booking')) {
            try {
                if (method_exists('PTP_Booking', 'get_trainer_bookings')) {
                    $upcoming = PTP_Booking::get_trainer_bookings($trainer->id, null, true);
                }
                if (method_exists('PTP_Booking', 'get_pending_confirmations')) {
                    $pending_confirmations = PTP_Booking::get_pending_confirmations($trainer->id);
                }
            } catch (Exception $e) {
                ptp_log('PTP Dashboard: Booking error - ' . $e->getMessage());
            }
        }
        $upcoming = is_array($upcoming) ? $upcoming : array();
        $pending_confirmations = is_array($pending_confirmations) ? $pending_confirmations : array();
        
        // Get earnings
        if (class_exists('PTP_Payments') && method_exists('PTP_Payments', 'get_trainer_earnings')) {
            try {
                $earnings_data = PTP_Payments::get_trainer_earnings($trainer->id);
                if (is_array($earnings_data)) {
                    $earnings = array_merge($earnings, $earnings_data);
                }
            } catch (Exception $e) {
                ptp_log('PTP Dashboard: Payments error - ' . $e->getMessage());
            }
        }
        
        // Get availability
        if (class_exists('PTP_Availability') && method_exists('PTP_Availability', 'get_weekly')) {
            try {
                $availability = PTP_Availability::get_weekly($trainer->id);
            } catch (Exception $e) {
                ptp_log('PTP Dashboard: Availability error - ' . $e->getMessage());
            }
        }
        $availability = is_array($availability) ? $availability : array();
        
        // Get profile completion
        if (class_exists('PTP_Trainer') && method_exists('PTP_Trainer', 'get_profile_completion_status')) {
            try {
                $completion = PTP_Trainer::get_profile_completion_status($trainer);
            } catch (Exception $e) {
                ptp_log('PTP Dashboard: Profile status error - ' . $e->getMessage());
            }
        }
        
        $show_welcome = isset($_GET['welcome']) && $_GET['welcome'] == '1';
        
        ob_end_clean();
        
        // Pass variables to template via $GLOBALS for compatibility
        $GLOBALS['earnings'] = $earnings;
        $GLOBALS['upcoming'] = $upcoming;
        
        // Load full-page trainer dashboard template (v200 - premium redesign)
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/trainer-dashboard-v200.php';
        $content = ob_get_clean();
        echo $content;
        exit;
    }
    
    public static function trainer_onboarding($atts) {
        // v213: Full-page template now handled by template_redirect (handle_trainer_onboarding)
        // This shortcode only fires as a fallback if template_redirect didn't match.
        // Redirect to ensure template_redirect picks it up cleanly.
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/'));
            exit;
        }
        
        // If we got here, template_redirect didn't fire. Try loading directly.
        $current_user_id = get_current_user_id();
        $current_user = wp_get_current_user();
        
        $trainer = null;
        if (class_exists('PTP_Trainer')) {
            $trainer = PTP_Trainer::get_by_user_id($current_user_id);
        }
        
        if (!$trainer) {
            global $wpdb;
            $user_email = $current_user->user_email;
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE email = %s",
                $user_email
            ));
            
            if ($trainer && (empty($trainer->user_id) || $trainer->user_id == 0)) {
                $is_approved_trainer = in_array('ptp_trainer', (array) $current_user->roles);
                $is_admin = current_user_can('manage_options');
                
                if ($is_approved_trainer || $is_admin) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_trainers',
                        array('user_id' => $current_user_id),
                        array('id' => $trainer->id)
                    );
                    $trainer->user_id = $current_user_id;
                } else {
                    $trainer = null;
                }
            }
        }
        
        if (!$trainer) {
            wp_redirect(home_url('/apply/'));
            exit;
        }
        
        if (class_exists('PTP_Trainer') && empty($trainer->slug)) {
            $trainer = PTP_Trainer::ensure_slug($trainer);
        }
        
        $has_basics = !empty($trainer->display_name) && !empty($trainer->hourly_rate) && $trainer->hourly_rate > 0;
        $onboarding_complete = get_user_meta($trainer->user_id, 'ptp_onboarding_completed', true);
        
        if ($has_basics && !empty($onboarding_complete) && !isset($_GET['edit'])) {
            wp_redirect(home_url('/trainer-dashboard/'));
            exit;
        }
        
        // v213: Load full-page template directly (exits after output)
        include PTP_PLUGIN_DIR . 'templates/trainer-onboarding-v133.php';
        exit;
    }
    
    /**
     * Trainer Profile Editor v60
     * Comprehensive profile management with completeness tracking
     */
    public static function trainer_profile_editor($atts) {
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/'));
            exit;
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        
        if (!$trainer) {
            wp_redirect(home_url('/apply/'));
            exit;
        }
        
        // Load full-page profile editor template (v2 redesign)
        $template = PTP_PLUGIN_DIR . 'templates/trainer-profile-editor-v2.php';
        if (file_exists($template)) {
            include $template;
        } else {
            // Fallback - redirect to dashboard
            wp_redirect(home_url('/trainer-dashboard/'));
        }
        exit; // Full-page template
    }
    
    public static function messaging($atts) {
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/'));
            exit;
        }
        
        $conversations = PTP_Messaging::get_conversations_for_user(get_current_user_id());
        $active_conversation = null;
        $messages = array();
        
        if (!empty($_GET['conversation'])) {
            $conversation_id = intval($_GET['conversation']);
            $active_conversation = PTP_Messaging::get_conversation($conversation_id);
            if ($active_conversation) {
                $messages = PTP_Messaging::get_messages($conversation_id);
                PTP_Messaging::mark_as_read($conversation_id, get_current_user_id());
            }
        } elseif (!empty($_GET['trainer'])) {
            $trainer_id = intval($_GET['trainer']);
            $parent = PTP_Parent::get_by_user_id(get_current_user_id());
            if ($parent) {
                $active_conversation = PTP_Messaging::get_or_create_conversation($trainer_id, $parent->id);
                $messages = PTP_Messaging::get_messages($active_conversation->id);
            }
        }
        
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/messaging.php';
        return ob_get_clean();
    }
    
    public static function account($atts) {
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/'));
            exit;
        }
        
        $user = wp_get_current_user();
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        $parent = PTP_Parent::get_by_user_id(get_current_user_id());
        
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/account.php';
        return ob_get_clean();
    }
    
    public static function login($atts) {
        // Redirect logged-in users to their dashboard
        if (is_user_logged_in()) {
            $url = PTP_User::get_dashboard_url();
            wp_redirect($url);
            exit;
        }
        
        // v132.0.2: Output full-page template directly and exit
        include PTP_PLUGIN_DIR . 'templates/login.php';
        exit; // Full-page template
    }
    
    public static function register($atts) {
        // Redirect logged-in users to their dashboard
        if (is_user_logged_in()) {
            wp_redirect(PTP_User::get_dashboard_url());
            exit;
        }
        
        // v132.0.2: Output full-page template directly and exit
        include PTP_PLUGIN_DIR . 'templates/register.php';
        exit; // Full-page template
    }
    
    // v214: Forgot password page
    public static function forgot_password($atts) {
        if (is_user_logged_in()) { wp_redirect(PTP_User::get_dashboard_url()); exit; }
        include PTP_PLUGIN_DIR . 'templates/forgot-password.php';
        exit;
    }
    
    // v214: Reset password page (handles link from email)
    public static function reset_password($atts) {
        if (is_user_logged_in()) { wp_redirect(PTP_User::get_dashboard_url()); exit; }
        include PTP_PLUGIN_DIR . 'templates/reset-password.php';
        exit;
    }
    
    public static function logout($atts) {
        // Template handles everything including full HTML output
        include PTP_PLUGIN_DIR . 'templates/logout.php';
        exit; // Prevent theme wrapping around full-page template
    }
    
    /**
     * Trainer pending page - shown while application is under review
     */
    public static function trainer_pending($atts) {
        // v132.0.2: Output full-page template directly and exit
        include PTP_PLUGIN_DIR . 'templates/trainer-pending.php';
        exit; // Full-page template
    }
    
    public static function trainer_application($atts) {
        // If logged in, check for existing application
        if (is_user_logged_in()) {
            $current_user_id = get_current_user_id();
            $current_user = wp_get_current_user();
            
            // Check if already a trainer
            $trainer = PTP_Trainer::get_by_user_id($current_user_id);
            if ($trainer) {
                wp_redirect(home_url('/trainer-dashboard/'));
                exit;
            }
            
            // Check if they have the trainer role (means they already applied)
            if (in_array('ptp_trainer', (array) $current_user->roles)) {
                wp_redirect(home_url('/trainer-dashboard/'));
                exit;
            }
        }
        
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/apply.php';
        return ob_get_clean();
    }
    
    public static function parent_dashboard($atts) {
        // v176: Auth guard (was missing entirely)
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/?redirect_to=' . urlencode(home_url('/parent-dashboard/'))));
            exit;
        }
        
        $current_user_id = get_current_user_id();
        $current_user = wp_get_current_user();
        
        // If user is a trainer (not a parent), redirect to trainer dashboard
        if (class_exists('PTP_Trainer')) {
            $trainer = PTP_Trainer::get_by_user_id($current_user_id);
            $is_parent = class_exists('PTP_Parent') && PTP_Parent::get_by_user_id($current_user_id);
            if ($trainer && !$is_parent) {
                wp_redirect(home_url('/trainer-dashboard/'));
                exit;
            }
        }
        
        // Ensure parent record exists (auto-create if missing)
        if (class_exists('PTP_Parent')) {
            $parent = PTP_Parent::get_by_user_id($current_user_id);
            if (!$parent) {
                $parent_id = PTP_Parent::create($current_user_id, array(
                    'display_name' => $current_user->display_name,
                    'email' => $current_user->user_email,
                ));
                $parent = PTP_Parent::get($parent_id);
            }
        }
        
        // Load full-page parent dashboard template (v200 rewrite — command center integration)
        include PTP_PLUGIN_DIR . 'templates/parent-dashboard-v200.php';
        exit; // Full-page template
    }
    
    /**
     * Debug shortcode - shows system status
     */
    public static function debug_info($atts) {
        if (!current_user_can('manage_options')) {
            return '<p style="color: red;">Debug info is only visible to administrators.</p>';
        }
        
        global $wpdb;
        
        $output = '<div style="font-family: monospace; background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;">';
        $output .= '<h3 style="margin-top: 0;">PTP Training Platform - Debug Info</h3>';
        
        // Check tables
        $tables = array(
            'ptp_applications',
            'ptp_trainers',
            'ptp_parents',
            'ptp_bookings',
        );
        
        $output .= '<h4>Database Tables:</h4><ul>';
        foreach ($tables as $table) {
            $full_table = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;
            $count = $exists ? $wpdb->get_var("SELECT COUNT(*) FROM $full_table") : 'N/A';
            $status = $exists ? '✅' : '❌';
            $output .= "<li>{$status} <strong>{$table}</strong>: " . ($exists ? "exists ({$count} rows)" : "MISSING") . "</li>";
        }
        $output .= '</ul>';
        
        // Check AJAX actions
        $output .= '<h4>AJAX Actions Registered:</h4><ul>';
        $ajax_actions = array(
            'ptp_submit_application' => has_action('wp_ajax_ptp_submit_application'),
            'ptp_submit_application (nopriv)' => has_action('wp_ajax_nopriv_ptp_submit_application'),
            'ptp_login (nopriv)' => has_action('wp_ajax_nopriv_ptp_login'),
            'ptp_register (nopriv)' => has_action('wp_ajax_nopriv_ptp_register'),
        );
        foreach ($ajax_actions as $action => $registered) {
            $status = $registered ? '✅' : '❌';
            $output .= "<li>{$status} {$action}</li>";
        }
        $output .= '</ul>';
        
        // Check if scripts are enqueued
        $output .= '<h4>Scripts Status:</h4><ul>';
        $output .= '<li>ptp_ajax URL: ' . admin_url('admin-ajax.php') . '</li>';
        $output .= '<li>Plugin Version: ' . PTP_VERSION . '</li>';
        $output .= '<li>WordPress Version: ' . get_bloginfo('version') . '</li>';
        $output .= '</ul>';
        
        // Recent applications
        $apps_table = $wpdb->prefix . 'ptp_applications';
        if ($wpdb->get_var("SHOW TABLES LIKE '$apps_table'") === $apps_table) {
            $recent = $wpdb->get_results("SELECT id, name, email, status, created_at FROM $apps_table ORDER BY created_at DESC LIMIT 5");
            $output .= '<h4>Recent Applications:</h4>';
            if ($recent) {
                $output .= '<table style="width: 100%; border-collapse: collapse; font-size: 12px;">';
                $output .= '<tr style="background: #ddd;"><th style="padding: 5px; text-align: left;">ID</th><th style="padding: 5px; text-align: left;">Name</th><th style="padding: 5px; text-align: left;">Email</th><th style="padding: 5px; text-align: left;">Status</th><th style="padding: 5px; text-align: left;">Date</th></tr>';
                foreach ($recent as $app) {
                    $output .= "<tr><td style='padding: 5px;'>{$app->id}</td><td style='padding: 5px;'>{$app->name}</td><td style='padding: 5px;'>{$app->email}</td><td style='padding: 5px;'>{$app->status}</td><td style='padding: 5px;'>{$app->created_at}</td></tr>";
                }
                $output .= '</table>';
            } else {
                $output .= '<p>No applications found.</p>';
            }
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Player Progress Report (for parents)
     */
    public static function player_progress($atts) {
        if (!is_user_logged_in()) {
            return '<div class="ptp-alert ptp-alert-warning">Please <a href="' . home_url('/login/') . '">log in</a> to view player progress.</div>';
        }
        
        echo self::embed_css();
        ob_start();
        $template = PTP_PLUGIN_DIR . 'templates/player-progress.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="ptp-wrap" style="padding: 40px; text-align: center;">
                <h2>Player Progress</h2>
                <p>Track your player\'s development over time.</p>
                <p><a href="' . home_url('/parent-dashboard/') . '">← Back to Dashboard</a></p>
            </div>';
        }
        return ob_get_clean();
    }
    
    /**
     * Training Plans Management (for trainers)
     */
    public static function training_plans($atts) {
        if (!is_user_logged_in()) {
            return '<div class="ptp-alert ptp-alert-warning">Please <a href="' . home_url('/login/') . '">log in</a> to access training plans.</div>';
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            return '<div class="ptp-alert ptp-alert-error">This page is for trainers only.</div>';
        }
        
        echo self::embed_css();
        ob_start();
        $template = PTP_PLUGIN_DIR . 'templates/training-plans.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="ptp-wrap" style="padding: 40px; text-align: center;">
                <h2>Training Plans</h2>
                <p>Create and manage training plans for your players.</p>
                <p><a href="' . home_url('/trainer-dashboard/') . '">← Back to Dashboard</a></p>
            </div>';
        }
        return ob_get_clean();
    }
    
    /**
     * Training Landing Page - Public landing page for /training/
     * v158: Mobile-first redesign with improved UX
     */
    public static function training_landing($atts) {
        // v219: NEVER render training content on the homepage.
        // If the "training" WP page is accidentally set as the static front page,
        // or [ptp_homepage] is placed on the front page, bail completely.
        $request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        if ($request_path === '' || is_front_page()) {
            return '';
        }

        // training-hub-v198.php is a complete HTML document (<!DOCTYPE>, <head>, etc.)
        // and must NEVER be rendered inside a theme page via shortcode — that causes a
        // double header. The /training page is served as a fullpage by
        // intercept_training_page() at template_redirect priority 0.
        if (defined('PTP_SERVING_FULLPAGE') && PTP_SERVING_FULLPAGE) {
            // We're already inside serve_training_fullpage() — safe to include.
            ob_start();
            $template = PTP_PLUGIN_DIR . 'templates/training-hub-v198.php';
            if (file_exists($template)) {
                include $template;
            }
            return ob_get_clean();
        }

        // Shortcode fired inside a normal WP page (not / and not fullpage) — return empty.
        // The /training URL is handled by intercept_training_page() at template_redirect.
        return '';
    }
    
    /**
     * Group Sessions Browser
     */
    public static function group_sessions($atts) {
        $atts = shortcode_atts(array(
            'trainer' => '',
            'limit' => 12,
        ), $atts);
        
        echo self::embed_css();
        
        $filters = array('limit' => intval($atts['limit']));
        if ($atts['trainer']) {
            global $wpdb;
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s",
                sanitize_text_field($atts['trainer'])
            ));
            if ($trainer) {
                $filters['trainer_id'] = $trainer->id;
            }
        }
        
        $sessions = PTP_Groups::get_open_sessions($filters);
        
        ob_start();
        ?>
        <div class="ptp-group-sessions">
            <?php if (empty($sessions)): ?>
            <div class="ptp-empty-state" style="text-align: center; padding: 60px 20px;">
                <div style="font-size: 48px; margin-bottom: 16px;">👥</div>
                <h3 style="margin: 0 0 8px;">No Group Sessions Available</h3>
                <p style="color: var(--ptp-gray-500);">Check back soon for upcoming group training sessions.</p>
            </div>
            <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px;">
                <?php foreach ($sessions as $session): ?>
                <div class="ptp-card" style="overflow: hidden;">
                    <div style="padding: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div>
                                <h3 style="margin: 0 0 4px; font-size: 18px;"><?php echo esc_html($session->title); ?></h3>
                                <p style="margin: 0; font-size: 14px; color: var(--ptp-gray-500);">with <?php echo esc_html($session->trainer_name); ?></p>
                            </div>
                            <span class="ptp-badge ptp-badge-<?php echo $session->status === 'open' ? 'success' : 'warning'; ?>">
                                <?php echo $session->current_players; ?>/<?php echo $session->max_players; ?> spots
                            </span>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; font-size: 14px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span>📅</span>
                                <span><?php echo date('l, M j, Y', strtotime($session->session_date)); ?></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span>🕐</span>
                                <span><?php echo date('g:i A', strtotime($session->start_time)); ?> - <?php echo date('g:i A', strtotime($session->end_time)); ?></span>
                            </div>
                            <?php if ($session->location): ?>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span>📍</span>
                                <span><?php echo esc_html($session->location); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($session->skill_level !== 'all'): ?>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span>🎯</span>
                                <span><?php echo ucfirst($session->skill_level); ?> level</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($session->description): ?>
                        <p style="margin: 0 0 16px; font-size: 14px; color: var(--ptp-gray-600);"><?php echo esc_html(substr($session->description, 0, 100)); ?><?php echo strlen($session->description) > 100 ? '...' : ''; ?></p>
                        <?php endif; ?>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 16px; border-top: 1px solid var(--ptp-gray-100);">
                            <div style="font-size: 20px; font-weight: 700;">$<?php echo number_format($session->price_per_player, 0); ?><span style="font-size: 14px; font-weight: 400; color: var(--ptp-gray-500);">/player</span></div>
                            <?php if ($session->status === 'open'): ?>
                            <a href="<?php echo home_url('/book-group/?session=' . $session->id); ?>" class="ptp-btn ptp-btn-primary">Join Session</a>
                            <?php else: ?>
                            <span class="ptp-btn ptp-btn-outline" style="opacity: 0.5; pointer-events: none;">Full</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Bundle Checkout - Unified training + camp checkout
     */
    public static function bundle_checkout($atts) {
        ob_start();
        PTP_Templates::get_template('bundle-checkout', $atts);
        return ob_get_clean();
    }
    
    /**
     * Training Checkout - Redirects to unified checkout with training in bundle
     * URL: /training-checkout/?trainer_id=X&date=Y&time=Z&location=L&package=P
     * 
     * v60.5.0: Now creates a bundle and redirects to unified checkout instead of
     * having a separate checkout flow. This ensures camps + training are combined.
     * v60.6.2: Added debugging for bundle creation issues
     */
    public static function training_checkout($atts) {
        global $wpdb;
        
        // Debug mode - add ?debug=1 to URL to see diagnostic info
        $debug_mode = isset($_GET['debug']) && current_user_can('manage_options');
        $debug_info = array();
        
        // Get booking data from URL params
        $trainer_id = isset($_GET['trainer_id']) ? intval($_GET['trainer_id']) : 0;
        $date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : '';
        $time = isset($_GET['time']) ? sanitize_text_field($_GET['time']) : '';
        $location = isset($_GET['location']) ? sanitize_text_field($_GET['location']) : '';
        $package_type = isset($_GET['package']) ? sanitize_text_field($_GET['package']) : (isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'single');
        
        if ($debug_mode) {
            $debug_info['params'] = compact('trainer_id', 'date', 'time', 'location', 'package_type');
        }
        
        // If missing required params, redirect to find trainers
        if (!$trainer_id || !$date || !$time) {
            return '<div class="ptp-wrap" style="padding: 40px; text-align: center;">
                <h2 style="margin-bottom: 16px;">Session Details Missing</h2>
                <p style="color: #6B7280;">Please select a trainer and choose a time slot.</p>
                <a href="' . esc_url(home_url('/find-trainers/')) . '" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #FCB900; color: #0A0A0A; font-weight: 600; border-radius: 8px; text-decoration: none;">Find a Trainer</a>
            </div>';
        }
        
        // Get trainer
        $trainer = PTP_Trainer::get($trainer_id);
        
        if ($debug_mode) {
            $debug_info['trainer'] = $trainer ? array(
                'id' => $trainer->id,
                'name' => $trainer->display_name,
                'status' => $trainer->status,
                'hourly_rate' => $trainer->hourly_rate,
            ) : 'NOT FOUND';
        }
        
        if (!$trainer || $trainer->status !== 'active') {
            return '<div class="ptp-wrap" style="padding: 40px; text-align: center;">
                <h2 style="margin-bottom: 16px;">Trainer Not Available</h2>
                <p style="color: #6B7280;">This trainer is no longer available.</p>
                <a href="' . esc_url(home_url('/find-trainers/')) . '" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #FCB900; color: #0A0A0A; font-weight: 600; border-radius: 8px; text-decoration: none;">Find Another Trainer</a>
            </div>';
        }
        
        // Map package type to sessions
        $sessions = 1;
        if ($package_type === '5pack' || $package_type === 'package_5') {
            $sessions = 5;
            $package_type = '5pack';
        } elseif ($package_type === '10pack' || $package_type === 'package_10') {
            $sessions = 10;
            $package_type = '10pack';
        } else {
            $package_type = 'single';
        }
        
        // Calculate amount — use PTP_Packages as single source of truth
        $rate = floatval($trainer->hourly_rate ?: 80);
        if (class_exists('PTP_Packages')) {
            $pkg_key_sc = 'single';
            if ($sessions >= 10) $pkg_key_sc = 'pack10';
            elseif ($sessions >= 5) $pkg_key_sc = 'pack5';
            elseif ($sessions >= 3) $pkg_key_sc = 'pack3';
            $pricing = PTP_Packages::calculate_price($rate, $pkg_key_sc);
            $amount = round($pricing['total'], 2);
        } else {
            if ($sessions >= 10) {
                $amount = round($rate * 10 * 0.8, 2);
            } elseif ($sessions >= 5) {
                $amount = round($rate * 5 * 0.85, 2);
            } elseif ($sessions >= 3) {
                $amount = round($rate * 3 * 0.9, 2);
            } else {
                $amount = $rate;
            }
        }
        
        if ($debug_mode) {
            $debug_info['calculated'] = compact('sessions', 'package_type', 'rate', 'amount');
            
            // Check table
            $table = $wpdb->prefix . 'ptp_bundles';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
            $debug_info['table_exists'] = $table_exists;
            
            if ($table_exists) {
                $columns = $wpdb->get_results("DESCRIBE $table");
                $debug_info['table_columns'] = array_map(function($c) { return $c->Field; }, $columns);
            }
        }
        
        // Create bundle with training data
        if (class_exists('PTP_Bundle_Checkout')) {
            $bundle_checkout = PTP_Bundle_Checkout::instance();
            
            $bundle_data = array(
                'trainer_id' => $trainer_id,
                'date' => $date,
                'time' => $time,
                'location' => $location,
                'package' => $package_type,
                'sessions' => $sessions,
                'amount' => $amount,
            );
            
            if ($debug_mode) {
                $debug_info['bundle_data'] = $bundle_data;
            }
            
            $result = $bundle_checkout->create_bundle_with_training($bundle_data);
            
            if ($debug_mode) {
                $debug_info['result'] = is_wp_error($result) ? array(
                    'error' => true,
                    'code' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ) : $result;
                $debug_info['last_query'] = $wpdb->last_query;
                $debug_info['last_error'] = $wpdb->last_error;
                
                // Output debug info
                return '<div class="ptp-wrap" style="padding: 40px; max-width: 800px; margin: 0 auto;">
                    <h2 style="margin-bottom: 16px;">🔧 Debug Mode</h2>
                    <pre style="background: #f5f5f5; padding: 20px; overflow: auto; font-size: 12px; border-radius: 8px;">' . 
                    esc_html(json_encode($debug_info, JSON_PRETTY_PRINT)) . 
                    '</pre>
                    <p style="margin-top: 20px;"><a href="' . esc_url(remove_query_arg('debug')) . '">Run without debug</a></p>
                </div>';
            }
            
            if (!is_wp_error($result)) {
                // Invalidate cart cache
                if (class_exists('PTP_Cart_Helper')) {
                    PTP_Cart_Helper::invalidate_cart_cache();
                }
                
                // Redirect to cart page to see the full cart
                $redirect_url = home_url('/ptp-cart/');
                
                // If they already have camps in cart, go straight to checkout
                if (false && ptp_cart() && ptp_cart()->get_item_count() > 0) {
                    $redirect_url = home_url('/ptp-checkout/');
                }
                
                // Use JavaScript redirect since headers may already be sent in shortcode context
                return '<script>window.location.href = "' . esc_js($redirect_url) . '";</script>
                    <div style="text-align:center; padding:60px 20px;">
                        <div style="font-size:32px; margin-bottom:20px;">⏳</div>
                        <p style="font-size:16px; color:#6B7280;">Adding training to your cart...</p>
                    </div>';
            } else {
                // Bundle creation failed - show error with details
                $error_msg = $result->get_error_message();
                ptp_log('PTP Training Checkout Error: ' . $error_msg . ' | Last Query: ' . $wpdb->last_query . ' | Last Error: ' . $wpdb->last_error);
                return '<div class="ptp-wrap" style="padding: 40px; text-align: center;">
                    <h2 style="margin-bottom: 16px;">Something Went Wrong</h2>
                    <p style="color: #6B7280; margin-bottom: 20px;">We couldn\'t add training to your cart. Please try again.</p>
                    <p style="color: #9CA3AF; font-size: 12px; margin-bottom: 20px;">Error: ' . esc_html($error_msg) . '</p>
                    <a href="' . esc_url(home_url('/trainer/' . ($trainer->slug ?: sanitize_title($trainer->display_name)) . '/')) . '" style="display: inline-block; padding: 12px 24px; background: #FCB900; color: #0A0A0A; font-weight: 600; text-decoration: none;">Back to Trainer Profile</a>
                </div>';
            }
        } else {
            if ($debug_mode) {
                $debug_info['error'] = 'PTP_Bundle_Checkout class does not exist';
                return '<pre>' . esc_html(json_encode($debug_info, JSON_PRETTY_PRINT)) . '</pre>';
            }
        }
        
        // PTP_Bundle_Checkout class not available - show error
        return '<div class="ptp-wrap" style="padding: 40px; text-align: center;">
            <h2 style="margin-bottom: 16px;">Checkout Unavailable</h2>
            <p style="color: #6B7280; margin-bottom: 20px;">Training checkout is temporarily unavailable. Please try again later.</p>
            <a href="' . esc_url(home_url('/find-trainers/')) . '" style="display: inline-block; padding: 12px 24px; background: #FCB900; color: #0A0A0A; font-weight: 600; text-decoration: none;">Find a Trainer</a>
        </div>';
    }
    
    /**
     * Thank You Page (v100 Viral Machine)
     * Features: Instagram opt-in, referral sharing, training CTA
     */
    public static function thank_you_page($atts) {
        ob_start();
        
        // Use v175 thank you template (handles session, order_id, and payment_intent params)
        $template = PTP_PLUGIN_DIR . 'templates/thank-you-v175.php';
        
        if (file_exists($template)) {
            include $template;
        } else {
            // Fallback to v100 then basic
            $fallback = PTP_PLUGIN_DIR . 'templates/thank-you-v100.php';
            if (file_exists($fallback)) {
                include $fallback;
            } else {
                $fallback2 = PTP_PLUGIN_DIR . 'templates/thank-you.php';
                if (file_exists($fallback2)) {
                    include $fallback2;
                } else {
                    echo '<div class="ptp-wrap" style="padding: 40px; text-align: center;">
                        <h2>Thank You!</h2>
                        <p>Your order has been confirmed.</p>
                    </div>';
                }
            }
        }
        
        return ob_get_clean();
    }
}

// ===========================================
// FALLBACK SHORTCODE REGISTRATION
// Ensures key shortcodes work even if init() timing fails
// ===========================================
add_action('init', function() {
    // Only register if not already registered
    if (!shortcode_exists('ptp_training')) {
        add_shortcode('ptp_training', array('PTP_Shortcodes', 'training_landing'));
    }
    if (!shortcode_exists('ptp_trainers_grid')) {
        add_shortcode('ptp_trainers_grid', array('PTP_Shortcodes', 'trainers_grid'));
    }
    if (!shortcode_exists('ptp_trainer_profile')) {
        add_shortcode('ptp_trainer_profile', array('PTP_Shortcodes', 'trainer_profile'));
    }
    if (!shortcode_exists('ptp_parent_dashboard')) {
        add_shortcode('ptp_parent_dashboard', array('PTP_Shortcodes', 'parent_dashboard'));
    }
    if (!shortcode_exists('ptp_trainer_dashboard')) {
        add_shortcode('ptp_trainer_dashboard', array('PTP_Shortcodes', 'trainer_dashboard'));
    }
}, 1);
