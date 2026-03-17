<?php
/**
 * PTP Admin Class
 */

defined('ABSPATH') || exit;

class PTP_Admin {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'handle_actions'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_get_players', array($this, 'ajax_get_players'));
        add_action('wp_ajax_ptp_delete_record', array($this, 'ajax_delete_record'));
        add_action('wp_ajax_ptp_get_realtime_stats', array($this, 'ajax_get_realtime_stats'));
        
        // Trainer ranking AJAX handlers (v54)
        add_action('wp_ajax_ptp_save_trainer_order', array($this, 'ajax_save_trainer_order'));
        add_action('wp_ajax_ptp_toggle_featured', array($this, 'ajax_toggle_featured'));
        add_action('wp_ajax_ptp_bulk_feature', array($this, 'ajax_bulk_feature'));
    }
    
    /**
     * AJAX: Get realtime stats for dashboard
     */
    public function ajax_get_realtime_stats() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $stats = PTP_Analytics::get_realtime_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Get players by parent ID
     */
    public function ajax_get_players() {
        // v242: Security — was completely open to any logged-in user
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        global $wpdb;
        $parent_id = intval($_GET['parent_id'] ?? $_POST['parent_id'] ?? 0);
        
        if (!$parent_id) {
            wp_send_json_error('Invalid parent ID');
        }
        
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) as age 
             FROM {$wpdb->prefix}ptp_players 
             WHERE parent_id = %d AND is_active = 1 
             ORDER BY name",
            $parent_id
        ));
        
        wp_send_json_success($players);
    }
    
    /**
     * AJAX: Delete a record
     */
    public function ajax_delete_record() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $table = sanitize_text_field($_POST['table']);
        $id = intval($_POST['id']);
        
        $allowed_tables = array('ptp_trainers', 'ptp_parents', 'ptp_players', 'ptp_bookings', 'ptp_applications');
        
        if (!in_array($table, $allowed_tables)) {
            wp_send_json_error('Invalid table');
        }
        
        $result = $wpdb->delete($wpdb->prefix . $table, array('id' => $id), array('%d'));
        
        if ($result) {
            wp_send_json_success('Deleted');
        } else {
            wp_send_json_error('Delete failed');
        }
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only on PTP pages
        if (!isset($_GET['page']) || strpos($_GET['page'], 'ptp') === false) {
            return;
        }
        
        // Google Fonts - Oswald for display
        wp_enqueue_style('ptp-admin-fonts', 'https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&display=swap', array(), null);
        
        // Main admin styles - Modern refined theme v6
        wp_enqueue_style('ptp-admin-v6', PTP_PLUGIN_URL . 'assets/css/ptp-admin-v6.css', array('ptp-admin-fonts'), PTP_VERSION);
    }
    
    public function add_menu() {
        add_menu_page(
            'PTP Training',
            'PTP Training', 
            'manage_options', 
            'ptp-dashboard', 
            array($this, 'dashboard_page'), 
            'dashicons-universal-access', 
            30
        );
        
        // ========================================
        // CLEAN MENU STRUCTURE - Logical Flow
        // Core Ops → People → Money → Growth → System
        // ========================================
        
        // 1. Dashboard - Overview
        add_submenu_page('ptp-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'ptp-dashboard', array($this, 'dashboard_page'));
        
        // 2. Bookings - All bookings list
        add_submenu_page('ptp-dashboard', 'Bookings', 'Bookings', 'manage_options', 'ptp-bookings', array($this, 'bookings_page'));
        
        // 3. Trainers - Manage trainers
        add_submenu_page('ptp-dashboard', 'Trainers', 'Trainers', 'manage_options', 'ptp-trainers', array($this, 'trainers_page'));
        
        // 4. Parents - Manage parents
        add_submenu_page('ptp-dashboard', 'Parents', 'Parents', 'manage_options', 'ptp-parents', array($this, 'parents_page'));
        
        // 5. Payments - Unified escrow/payouts (v130.3: Using V3 redesign)
        if (class_exists('PTP_Admin_Payouts_V3')) {
            add_submenu_page('ptp-dashboard', 'Payments', 'Payments', 'manage_options', 'ptp-payments', array($this, 'payments_page_wrapper'));
        }
        
        // 6. Applications - Trainer applications
        add_submenu_page('ptp-dashboard', 'Applications', 'Applications', 'manage_options', 'ptp-applications', array($this, 'applications_page'));
        
        // 7. Messages - Communications
        add_submenu_page('ptp-dashboard', 'Messages', 'Messages', 'manage_options', 'ptp-messages', array($this, 'messages_page'));
        
        // 8. Quality - Quality control
        add_submenu_page('ptp-dashboard', 'Quality', 'Quality', 'manage_options', 'ptp-quality', array($this, 'quality_page'));
        
        // 9. Analytics - Reports
        add_submenu_page('ptp-dashboard', 'Analytics', 'Analytics', 'manage_options', 'ptp-analytics', array($this, 'analytics_page'));
        
        // 10. Settings - Configuration
        add_submenu_page('ptp-dashboard', 'Settings', 'Settings', 'manage_options', 'ptp-settings', array($this, 'settings_page'));
        
        // ========================================
        // HIDDEN PAGES (accessible via links only)
        // ========================================
        add_submenu_page(null, 'Schedule', 'Schedule', 'manage_options', 'ptp-schedule', array($this, 'schedule_page'));
        add_submenu_page(null, 'Trainer Ranking', 'Trainer Ranking', 'manage_options', 'ptp-trainer-ranking', array($this, 'trainer_ranking_page'));
    }
    
    public function register_settings() {
        register_setting('ptp_general', 'ptp_from_email');
        register_setting('ptp_general', 'ptp_platform_fee');
        register_setting('ptp_general', 'ptp_min_payout');
        register_setting('ptp_general', 'ptp_refund_window');
        register_setting('ptp_general', 'ptp_google_maps_key');
        register_setting('ptp_general', 'ptp_google_place_id');
        
        // Checkout/Pricing settings
        register_setting('ptp_checkout', 'ptp_bundle_discount_percent');
        register_setting('ptp_checkout', 'ptp_processing_fee_percent');
        register_setting('ptp_checkout', 'ptp_processing_fee_fixed');
        register_setting('ptp_checkout', 'ptp_processing_fee_enabled');
        
        register_setting('ptp_company', 'ptp_company_name');
        register_setting('ptp_company', 'ptp_company_ein');
        register_setting('ptp_company', 'ptp_company_address');
        register_setting('ptp_company', 'ptp_company_city');
        register_setting('ptp_company', 'ptp_company_state');
        register_setting('ptp_company', 'ptp_company_zip');
        register_setting('ptp_company', 'ptp_company_phone');
        register_setting('ptp_company', 'ptp_1099_threshold');
        register_setting('ptp_company', 'ptp_require_w9');
        register_setting('ptp_company', 'ptp_require_safesport');
        
        register_setting('ptp_openphone', 'ptp_openphone_api_key');
        register_setting('ptp_openphone', 'ptp_openphone_from');
        register_setting('ptp_openphone', 'ptp_openphone_user_id');
        register_setting('ptp_openphone', 'ptp_sms_enabled');
        
        register_setting('ptp_stripe', 'ptp_stripe_test_mode');
        register_setting('ptp_stripe', 'ptp_stripe_test_publishable');
        register_setting('ptp_stripe', 'ptp_stripe_test_secret');
        register_setting('ptp_stripe', 'ptp_stripe_live_publishable');
        register_setting('ptp_stripe', 'ptp_stripe_live_secret');
        register_setting('ptp_stripe', 'ptp_stripe_webhook_secret');
        register_setting('ptp_stripe', 'ptp_stripe_connect_enabled');
        
        register_setting('ptp_notifications', 'ptp_email_booking_confirmation');
        register_setting('ptp_notifications', 'ptp_email_session_reminder');
        register_setting('ptp_notifications', 'ptp_sms_booking_confirmation');
        register_setting('ptp_notifications', 'ptp_sms_session_reminder');
        register_setting('ptp_notifications', 'ptp_fcm_server_key');
    }
    
    private function get_stats() {
        global $wpdb;
        
        // Safely get stats - tables might not exist
        $stats = array(
            'trainers' => 0,
            'parents' => 0,
            'players' => 0,
            'bookings' => 0,
            'upcoming' => 0,
            'completed' => 0,
            'revenue' => 0,
            'pending_apps' => 0,
        );
        
        // Check if tables exist first
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        if ($wpdb->get_var("SHOW TABLES LIKE '$trainers_table'") == $trainers_table) {
            $stats['trainers'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'") ?: 0;
        }
        
        $parents_table = $wpdb->prefix . 'ptp_parents';
        if ($wpdb->get_var("SHOW TABLES LIKE '$parents_table'") == $parents_table) {
            $stats['parents'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_parents") ?: 0;
        }
        
        $players_table = $wpdb->prefix . 'ptp_players';
        if ($wpdb->get_var("SHOW TABLES LIKE '$players_table'") == $players_table) {
            $stats['players'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_players WHERE is_active = 1") ?: 0;
        }
        
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") == $bookings_table) {
            $stats['bookings'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings") ?: 0;
            $stats['completed'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE status = 'completed'") ?: 0;
            $stats['upcoming'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE status = 'confirmed' AND session_date >= %s", current_time('Y-m-d'))) ?: 0;
            $stats['revenue'] = $wpdb->get_var("SELECT COALESCE(SUM(total_amount), 0) FROM {$wpdb->prefix}ptp_bookings WHERE status IN ('completed', 'confirmed')") ?: 0;
        }
        
        $apps_table = $wpdb->prefix . 'ptp_applications';
        if ($wpdb->get_var("SHOW TABLES LIKE '$apps_table'") == $apps_table) {
            $stats['pending_apps'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_applications WHERE status = 'pending'") ?: 0;
        }
        
        return $stats;
    }
    
    /**
     * Render navigation helper - Clean tab navigation
     */
    public function render_nav($active = 'dashboard') {
        $stats = $this->get_stats();
        
        // Get active quality flags count
        $quality_flags = 0;
        if (class_exists('PTP_Quality_Control')) {
            global $wpdb;
            $quality_flags = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_quality_flags WHERE resolved = 0");
        }
        
        // Get disputes count
        global $wpdb;
        $disputes = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_escrow'") === $wpdb->prefix . 'ptp_escrow') {
            $disputes = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'disputed'");
        }
        
        // Get unread message count
        $unread_messages = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_conversations'") === $wpdb->prefix . 'ptp_conversations') {
            $unread_messages = (int) $wpdb->get_var("SELECT COALESCE(SUM(trainer_unread_count) + SUM(parent_unread_count), 0) FROM {$wpdb->prefix}ptp_conversations");
        }
        
        // ── Logical nav order ──
        // Core ops → People → Money → Growth → System
        $nav_items = array(
            'dashboard'    => array('icon' => 'dashicons-dashboard',       'label' => 'Dashboard'),
            'bookings'     => array('icon' => 'dashicons-calendar-alt',    'label' => 'Bookings'),
            'trainers'     => array('icon' => 'dashicons-groups',          'label' => 'Trainers'),
            'parents'      => array('icon' => 'dashicons-admin-users',     'label' => 'Parents'),
            'payments'     => array('icon' => 'dashicons-money-alt',       'label' => 'Payments', 'count' => $disputes),
            'applications' => array('icon' => 'dashicons-portfolio',       'label' => 'Applications', 'count' => $stats['pending_apps']),
            'messages'     => array('icon' => 'dashicons-email',           'label' => 'Messages', 'count' => $unread_messages),
            'quality'      => array('icon' => 'dashicons-shield',          'label' => 'Quality', 'count' => $quality_flags),
            'analytics'    => array('icon' => 'dashicons-chart-bar',       'label' => 'Analytics'),
            'settings'     => array('icon' => 'dashicons-admin-settings',  'label' => 'Settings'),
        );
        ?>
        <nav class="ptp-admin-nav">
            <?php foreach ($nav_items as $key => $item): ?>
            <a href="<?php echo admin_url('admin.php?page=ptp-' . $key); ?>" 
               class="ptp-admin-nav-item <?php echo $active === $key ? 'active' : ''; ?>">
                <span class="dashicons <?php echo $item['icon']; ?>"></span>
                <?php echo $item['label']; ?>
                <?php if (!empty($item['count']) && $item['count'] > 0): ?>
                <span class="count alert"><?php echo $item['count']; ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }
    
    /**
     * Render nav with inline CSS for pages that have their own header/layout
     * (trainers template, parents CRM, analytics dashboard, payments V3)
     */
    public function render_standalone_nav($active = 'dashboard') {
        ?>
        <style>
        .ptp-admin-nav{display:flex;gap:8px;margin:20px 20px 0 0;padding:12px;background:#fff;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.1);flex-wrap:wrap}
        .ptp-admin-nav-item{display:flex;align-items:center;gap:8px;padding:10px 16px;color:#4B5563;text-decoration:none;border-radius:10px;font-weight:500;transition:all .2s;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px}
        .ptp-admin-nav-item:hover{background:#F3F4F6;color:#0A0A0A}
        .ptp-admin-nav-item.active{background:#0A0A0A;color:#fff}
        .ptp-admin-nav-item .count{padding:2px 8px;font-size:12px;background:#E5E7EB;border-radius:20px}
        .ptp-admin-nav-item .count.alert{background:#EF4444;color:#fff}
        .ptp-admin-nav-item.active .count{background:rgba(255,255,255,.2)}
        .ptp-admin-nav-item.active .count.alert{background:#EF4444}
        </style>
        <?php
        $this->render_nav($active);
    }
    
    /**
     * Output critical CSS content (no style tags)
     */
    private function output_critical_css_content() {
        ?>
        /* Critical PTP Admin Styles */
        .ptp-admin-wrap {
            margin: 20px 20px 40px 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .ptp-admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            padding: 24px 32px;
            background: linear-gradient(135deg, #0A0A0A 0%, #1a1a1a 50%, #252525 100%);
            border-radius: 20px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
            position: relative;
        }
        .ptp-admin-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #FCB900, #C99200, #FCB900);
        }
        .ptp-admin-header-content {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .ptp-admin-logo {
            width: 56px;
            height: 56px;
            background: #FCB900;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ptp-admin-logo .dashicons {
            font-size: 28px;
            width: 28px;
            height: 28px;
            color: #0A0A0A;
        }
        .ptp-admin-title {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            color: #fff;
        }
        .ptp-admin-title span {
            color: #FCB900;
        }
        .ptp-admin-subtitle {
            margin: 4px 0 0;
            color: #9CA3AF;
            font-size: 14px;
        }
        .ptp-admin-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 32px;
            padding: 12px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            flex-wrap: wrap;
        }
        .ptp-admin-nav-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            color: #4B5563;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .ptp-admin-nav-item:hover {
            background: #F3F4F6;
            color: #0A0A0A;
        }
        .ptp-admin-nav-item.active {
            background: #0A0A0A;
            color: #fff;
        }
        .ptp-admin-nav-item .count {
            padding: 2px 8px;
            font-size: 12px;
            background: #E5E7EB;
            border-radius: 20px;
        }
        .ptp-admin-nav-item .count.alert {
            background: #EF4444;
            color: #fff;
        }
        .ptp-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }
        .ptp-stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            border: 1px solid #E5E7EB;
        }
        .ptp-stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #6B7280;
        }
        .ptp-stat-card.yellow::before { background: #FCB900; }
        .ptp-stat-card.green::before { background: #10B981; }
        .ptp-stat-card.blue::before { background: #3B82F6; }
        .ptp-stat-card.purple::before { background: #8B5CF6; }
        .ptp-stat-card.red::before { background: #EF4444; }
        .ptp-stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            background: #F3F4F6;
        }
        .ptp-stat-icon.yellow { background: #FFF8E1; color: #FCB900; }
        .ptp-stat-icon.green { background: #D1FAE5; color: #10B981; }
        .ptp-stat-icon.blue { background: #DBEAFE; color: #3B82F6; }
        .ptp-stat-icon.purple { background: #EDE9FE; color: #8B5CF6; }
        .ptp-stat-icon.red { background: #FEE2E2; color: #EF4444; }
        .ptp-stat-icon .dashicons {
            font-size: 24px;
            width: 24px;
            height: 24px;
        }
        .ptp-stat-value {
            font-size: 36px;
            font-weight: 700;
            color: #0A0A0A;
            line-height: 1.2;
        }
        .ptp-stat-label {
            color: #6B7280;
            font-size: 14px;
            margin-top: 4px;
        }
        .ptp-stat-footer {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #E5E7EB;
        }
        .ptp-stat-footer a {
            color: #3B82F6;
            text-decoration: none;
            font-weight: 500;
        }
        .ptp-dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        .ptp-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #E5E7EB;
            overflow: hidden;
        }
        .ptp-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #E5E7EB;
        }
        .ptp-card-header.dark {
            background: #0A0A0A;
            color: #fff;
            border-bottom: none;
        }
        .ptp-card-title {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .ptp-card-body {
            padding: 24px;
        }
        .ptp-card-body.no-padding {
            padding: 0;
        }
        .ptp-empty-state {
            text-align: center;
            padding: 48px 24px;
        }
        .ptp-empty-state-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: #F3F4F6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ptp-empty-state-icon .dashicons {
            font-size: 32px;
            width: 32px;
            height: 32px;
            color: #9CA3AF;
        }
        .ptp-empty-state h3 {
            margin: 0 0 8px;
            color: #0A0A0A;
        }
        .ptp-empty-state p {
            margin: 0;
            color: #6B7280;
        }
        .ptp-quick-links {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .ptp-quick-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: #F9FAFB;
            border-radius: 10px;
            color: #374151;
            text-decoration: none;
            transition: all 0.2s;
        }
        .ptp-quick-link:hover {
            background: #F3F4F6;
            transform: translateX(4px);
        }
        .ptp-quick-link .arrow {
            margin-left: auto;
            color: #9CA3AF;
        }
        .ptp-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
        }
        .ptp-btn-primary {
            background: #FCB900;
            color: #0A0A0A;
        }
        .ptp-btn-dark {
            background: #0A0A0A;
            color: #fff;
        }
        .ptp-btn-success {
            background: #10B981;
            color: #fff;
        }
        .ptp-btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }
        .ptp-btn-outline {
            background: transparent;
            border: 2px solid #E5E7EB;
            color: #374151;
        }
        .ptp-btn-outline:hover {
            border-color: #D1D5DB;
            background: #F9FAFB;
        }

        /* Filter Tabs */
        .ptp-filter-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 20px;
            padding: 4px;
            background: #F3F4F6;
            border-radius: 14px;
            width: fit-content;
        }
        .ptp-filter-tab {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            color: #4B5563;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .ptp-filter-tab:hover {
            color: #111827;
            background: rgba(255,255,255,0.5);
        }
        .ptp-filter-tab.active {
            background: #fff;
            color: #111827;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .ptp-filter-tab .count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            background: #E5E7EB;
            color: #6B7280;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .ptp-filter-tab.active .count {
            background: #FCB900;
            color: #0A0A0A;
        }

        /* Toolbar */
        .ptp-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .ptp-search-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ptp-search-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .ptp-search-input-wrap .dashicons {
            position: absolute;
            left: 12px;
            color: #9CA3AF;
            font-size: 18px;
        }
        .ptp-search-input {
            padding: 10px 16px 10px 40px;
            border: 2px solid #E5E7EB;
            border-radius: 10px;
            font-size: 14px;
            min-width: 280px;
            transition: all 0.2s;
        }
        .ptp-search-input:focus {
            outline: none;
            border-color: #FCB900;
            box-shadow: 0 0 0 3px rgba(252,185,0,0.1);
        }
        .ptp-btn-secondary {
            background: #F3F4F6;
            color: #374151;
        }
        .ptp-btn-secondary:hover {
            background: #E5E7EB;
        }

        /* Tables */
        .ptp-table-wrap {
            overflow-x: auto;
            border-radius: 14px;
        }
        .ptp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .ptp-table thead th {
            text-align: left;
            padding: 14px 16px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6B7280;
            background: #F9FAFB;
            border-bottom: 2px solid #E5E7EB;
            white-space: nowrap;
        }
        .ptp-table thead th:first-child {
            padding-left: 24px;
        }
        .ptp-table thead th:last-child {
            padding-right: 24px;
        }
        .ptp-table tbody td {
            padding: 16px;
            border-bottom: 1px solid #F3F4F6;
            color: #374151;
            vertical-align: middle;
        }
        .ptp-table tbody td:first-child {
            padding-left: 24px;
        }
        .ptp-table tbody td:last-child {
            padding-right: 24px;
        }
        .ptp-table tbody tr:last-child td {
            border-bottom: none;
        }
        .ptp-table tbody tr {
            transition: background 0.15s;
        }
        .ptp-table tbody tr:hover {
            background: #F9FAFB;
        }
        .ptp-table-user {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .ptp-table-user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: linear-gradient(135deg, #FCB900 0%, #C99200 100%);
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #0A0A0A;
            font-size: 16px;
        }
        .ptp-table-user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .ptp-table-user-name {
            font-weight: 600;
            color: #111827;
        }
        .ptp-table-user-email {
            font-size: 13px;
            color: #6B7280;
        }

        /* Status Badges */
        .ptp-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .ptp-status-pending {
            background: #FEF3C7;
            color: #92400E;
        }
        .ptp-status-active, .ptp-status-confirmed, .ptp-status-completed, .ptp-status-approved {
            background: #D1FAE5;
            color: #065F46;
        }
        .ptp-status-inactive, .ptp-status-cancelled, .ptp-status-rejected {
            background: #FEE2E2;
            color: #DC2626;
        }

        /* Notices */
        .ptp-notice {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .ptp-notice-success {
            background: #D1FAE5;
            color: #065F46;
            border: 1px solid #BBF7D0;
        }
        .ptp-notice-warning {
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid #FDE68A;
        }
        .ptp-notice-error {
            background: #FEE2E2;
            color: #DC2626;
            border: 1px solid #FECACA;
        }
        .ptp-notice-info {
            background: #DBEAFE;
            color: #1E40AF;
            border: 1px solid #BFDBFE;
        }

        /* Actions Dropdown */
        .ptp-actions-dropdown {
            position: relative;
            display: inline-block;
        }
        .ptp-actions-dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            padding: 8px;
            min-width: 160px;
            z-index: 100;
            display: none;
        }
        .ptp-actions-dropdown.open .ptp-actions-dropdown-menu {
            display: block;
        }
        .ptp-actions-dropdown-menu a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            color: #374151;
            text-decoration: none;
            border-radius: 6px;
            font-size: 13px;
            transition: all 0.15s;
        }
        .ptp-actions-dropdown-menu a:hover {
            background: #F3F4F6;
        }

        /* Forms */
        .ptp-form-row {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
            align-items: flex-start;
        }
        .ptp-form-row > div {
            flex: 1;
        }
        .ptp-form-row label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #374151;
        }
        .ptp-form-row input,
        .ptp-form-row select,
        .ptp-form-row textarea {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #E5E7EB;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.2s;
        }
        .ptp-form-row input:focus,
        .ptp-form-row select:focus,
        .ptp-form-row textarea:focus {
            outline: none;
            border-color: #FCB900;
            box-shadow: 0 0 0 3px rgba(252,185,0,0.1);
        }

        /* Settings Tabs */
        .ptp-settings-tabs {
            display: flex;
            gap: 0;
            background: #fff;
            border-radius: 14px 14px 0 0;
            border-bottom: 2px solid #E5E7EB;
            overflow-x: auto;
            margin-bottom: 0;
        }
        .ptp-settings-tab {
            padding: 14px 20px;
            font-size: 13px;
            font-weight: 600;
            color: #6B7280;
            text-decoration: none;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .ptp-settings-tab:hover {
            color: #111827;
            background: #F9FAFB;
        }
        .ptp-settings-tab.active {
            color: #0A0A0A;
            border-bottom-color: #FCB900;
            background: #FFFBEB;
        }
        
        /* Alert Banners */
        .ptp-alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
        }
        .ptp-alert-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .ptp-alert-icon .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }
        .ptp-alert-content {
            flex: 1;
            min-width: 0;
        }
        .ptp-alert-content strong {
            display: block;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .ptp-alert-content span {
            color: inherit;
            opacity: 0.8;
            font-size: 13px;
        }
        .ptp-alert-warning {
            background: #FEF3C7;
            border: 1px solid #F59E0B;
            color: #92400E;
        }
        .ptp-alert-warning .ptp-alert-icon {
            background: #F59E0B;
            color: #0A0A0A;
        }
        .ptp-alert-info {
            background: #EFF6FF;
            border: 1px solid #3B82F6;
            color: #1E40AF;
        }
        .ptp-alert-info .ptp-alert-icon {
            background: #3B82F6;
            color: #fff;
        }
        .ptp-alert-success {
            background: #D1FAE5;
            border: 1px solid #10B981;
            color: #065F46;
        }
        .ptp-alert-success .ptp-alert-icon {
            background: #10B981;
            color: #fff;
        }
        
        /* Small Button */
        .ptp-btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            background: #0A0A0A;
            color: #FCB900;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            white-space: nowrap;
        }
        .ptp-btn-sm:hover {
            background: #1a1a1a;
            color: #FCB900;
        }

        @media (max-width: 1200px) {
            .ptp-stats-grid { grid-template-columns: repeat(2, 1fr); }
            .ptp-dashboard-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 782px) {
            .ptp-stats-grid { grid-template-columns: 1fr; }
            .ptp-admin-nav { flex-wrap: wrap; }
            .ptp-filter-tabs { width: 100%; overflow-x: auto; }
            .ptp-toolbar { flex-direction: column; align-items: stretch; }
            .ptp-search-input { min-width: 100%; }
            .ptp-settings-tabs { gap: 0; }
            .ptp-settings-tab { padding: 12px 14px; font-size: 12px; }
            .ptp-alert { flex-wrap: wrap; }
        }
        <?php
    }

    /* =========================================================================
       DASHBOARD PAGE
       ========================================================================= */
    public function dashboard_page() {
        global $wpdb;
        $stats = $this->get_stats();
        $today = current_time('Y-m-d');
        $now_ts = current_time('timestamp');
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $month_start = date('Y-m-01');
        $prev_week_start = date('Y-m-d', strtotime($week_start . ' -7 days'));
        $prev_month_start = date('Y-m-01', strtotime('-1 month'));
        $prev_month_end = date('Y-m-t', strtotime('-1 month'));
        
        // ── REVENUE DATA ──
        $today_revenue = (float)($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}ptp_bookings WHERE DATE(created_at)=%s AND payment_status IN('paid','free_session')", $today)) ?: 0);
        $week_revenue = (float)($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}ptp_bookings WHERE created_at>=%s AND payment_status IN('paid','free_session')", $week_start)) ?: 0);
        $prev_week_revenue = (float)($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}ptp_bookings WHERE created_at>=%s AND created_at<%s AND payment_status IN('paid','free_session')", $prev_week_start, $week_start)) ?: 0);
        $month_revenue = (float)($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}ptp_bookings WHERE created_at>=%s AND payment_status IN('paid','free_session')", $month_start)) ?: 0);
        $prev_month_revenue = (float)($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}ptp_bookings WHERE created_at>=%s AND created_at<=%s AND payment_status IN('paid','free_session')", $prev_month_start, $prev_month_end)) ?: 0);
        
        // ── PIPELINE DATA ──
        $app_table = $wpdb->prefix . 'ptp_session_applications';
        $has_app_table = $wpdb->get_var("SHOW TABLES LIKE '{$app_table}'") === $app_table;
        $pipeline = array('pending' => 0, 'accepted' => 0, 'booked' => 0, 'total' => 0);
        if ($has_app_table) {
            $pipeline['pending'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$app_table} WHERE status='pending'") ?: 0;
            $pipeline['accepted'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$app_table} WHERE status='accepted' AND first_booking_id IS NULL") ?: 0;
            $pipeline['booked'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$app_table} WHERE first_booking_id IS NOT NULL") ?: 0;
            $pipeline['total'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$app_table}") ?: 0;
        }
        
        // ── TRAINER APPLICATIONS ──
        $pending_trainer_apps = (int)($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_applications WHERE status='pending'") ?: 0);
        
        // ── TODAY'S SESSIONS ──
        $todays_sessions = $wpdb->get_results($wpdb->prepare("
            SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.phone as trainer_phone,
                   p.display_name as parent_name, p.email as parent_email, p.phone as parent_phone,
                   pl.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE b.session_date = %s AND b.status IN('confirmed','pending')
            ORDER BY b.start_time ASC
        ", $today));
        
        // ── UPCOMING SESSIONS (next 7 days, excluding today) ──
        $upcoming = $wpdb->get_results($wpdb->prepare("
            SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
                   p.display_name as parent_name, pl.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE b.status='confirmed' AND b.session_date > %s AND b.session_date <= %s
            ORDER BY b.session_date, b.start_time LIMIT 8
        ", $today, date('Y-m-d', strtotime('+7 days'))));
        
        // ── RECENT BOOKINGS ──
        $recent = $wpdb->get_results("
            SELECT b.*, t.display_name as trainer_name, p.display_name as parent_name, pl.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            ORDER BY b.created_at DESC LIMIT 6
        ");
        
        // ── TOP TRAINERS ──
        $top_trainers = $wpdb->get_results($wpdb->prepare("
            SELECT t.id, t.display_name, t.photo_url, t.hourly_rate,
                   COUNT(b.id) as booking_count,
                   COALESCE(SUM(b.total_amount),0) as total_revenue
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->prefix}ptp_bookings b ON t.id=b.trainer_id AND b.created_at>=%s AND b.payment_status IN('paid','free_session')
            WHERE t.status='active'
            GROUP BY t.id ORDER BY booking_count DESC, total_revenue DESC LIMIT 5
        ", $month_start));
        
        // ── ACTION ITEMS ──
        $actions = array();
        if ($pending_trainer_apps > 0) {
            $actions[] = array('t'=>'warning','icon'=>'portfolio','n'=>$pending_trainer_apps.' Trainer App'.($pending_trainer_apps>1?'s':''),'d'=>'Waiting for review','url'=>admin_url('admin.php?page=ptp-applications&status=pending'),'btn'=>'Review');
        }
        if ($pipeline['pending'] > 0) {
            $actions[] = array('t'=>'info','icon'=>'heart','n'=>$pipeline['pending'].' Free Session App'.($pipeline['pending']>1?'s':''),'d'=>'Parents waiting for trainer match','url'=>admin_url('admin.php?page=ptp-settings&tab=free-sessions'),'btn'=>'Match');
        }
        $no_photo = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE (photo_url IS NULL OR photo_url='') AND status='active'") ?: 0;
        if ($no_photo > 0) {
            $actions[] = array('t'=>'info','icon'=>'camera','n'=>$no_photo.' Missing Photo'.($no_photo>1?'s':''),'d'=>'Profiles without photos get fewer bookings','url'=>admin_url('admin.php?page=ptp-trainers'),'btn'=>'Fix');
        }
        $incomplete_onboarding = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status='active' AND (onboarding_completed_at IS NULL OR onboarding_completed_at='0000-00-00 00:00:00') AND approved_at IS NOT NULL AND approved_at!='0000-00-00 00:00:00'") ?: 0;
        if ($incomplete_onboarding > 0) {
            $actions[] = array('t'=>'danger','icon'=>'warning','n'=>$incomplete_onboarding.' Incomplete Onboarding','d'=>'Approved trainers who haven\'t finished setup','url'=>admin_url('admin.php?page=ptp-trainers&filter=incomplete_onboarding'),'btn'=>'Nudge');
        }
        $unread = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_messages WHERE is_read=0") ?: 0;
        if ($unread > 0) {
            $actions[] = array('t'=>'info','icon'=>'email','n'=>$unread.' Unread Message'.($unread>1?'s':''),'d'=>'Parent-trainer conversations','url'=>admin_url('admin.php?page=ptp-messages'),'btn'=>'View');
        }
        
        // Helper: % change
        $pct = function($new, $old) { return $old > 0 ? round((($new - $old) / $old) * 100) : ($new > 0 ? 100 : 0); };
        $week_pct = $pct($week_revenue, $prev_week_revenue);
        $month_pct = $pct($month_revenue, $prev_month_revenue);
        
        // ── New parents + bookings today ──
        $new_parents_week = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_parents WHERE created_at>=%s", $week_start)) ?: 0;
        $bookings_today = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE DATE(created_at)=%s", $today)) ?: 0;
        
        ?>
        <div class="ptp-admin-wrap">
            <div class="ptp-admin-header">
                <div class="ptp-admin-header-content">
                    <div class="ptp-admin-logo"><span class="dashicons dashicons-universal-access"></span></div>
                    <div class="ptp-admin-title-wrap">
                        <h1 class="ptp-admin-title">PTP <span>Training</span></h1>
                        <p class="ptp-admin-subtitle"><?php echo date('l, F j, Y'); ?></p>
                    </div>
                </div>
                <div class="ptp-admin-header-actions">
                    <a href="<?php echo admin_url('admin.php?page=ptp-schedule'); ?>" class="ptp-btn ptp-btn-primary" style="margin-right:8px;">
                        <span class="dashicons dashicons-plus-alt2"></span> New Session
                    </a>
                    <a href="<?php echo home_url('/find-trainers/'); ?>" class="ptp-admin-header-btn" target="_blank">
                        <span class="dashicons dashicons-external"></span> View Site
                    </a>
                </div>
            </div>
            
            <?php $this->render_nav('dashboard'); ?>
            
            <style>
            .ptp-d-pipeline{display:flex;gap:2px;margin-bottom:28px;border-radius:14px;overflow:hidden;background:#E5E7EB;}
            .ptp-d-pipe-stage{flex:1;padding:20px 24px;background:#fff;text-align:center;position:relative;}
            .ptp-d-pipe-stage:not(:last-child)::after{content:'';position:absolute;right:-8px;top:50%;transform:translateY(-50%);width:0;height:0;border:8px solid transparent;border-left-color:#E5E7EB;z-index:2;}
            .ptp-d-pipe-num{font-size:32px;font-weight:700;line-height:1.1;font-family:'Oswald',sans-serif;}
            .ptp-d-pipe-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;font-weight:600;margin-top:4px;}
            .ptp-d-pipe-stage.pending .ptp-d-pipe-num{color:#F59E0B;}
            .ptp-d-pipe-stage.matched .ptp-d-pipe-num{color:#3B82F6;}
            .ptp-d-pipe-stage.booked .ptp-d-pipe-num{color:#10B981;}
            .ptp-d-pipe-stage.total .ptp-d-pipe-num{color:#0A0A0A;}
            
            .ptp-d-metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;}
            .ptp-d-metric{background:#fff;border-radius:14px;padding:20px 24px;border:1px solid #E5E7EB;display:flex;flex-direction:column;gap:4px;}
            .ptp-d-metric-top{display:flex;justify-content:space-between;align-items:flex-start;}
            .ptp-d-metric-val{font-size:28px;font-weight:700;font-family:'Oswald',sans-serif;color:#0A0A0A;line-height:1.1;}
            .ptp-d-metric-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;font-weight:600;margin-top:6px;}
            .ptp-d-metric-delta{font-size:12px;font-weight:600;padding:2px 8px;border-radius:20px;white-space:nowrap;}
            .ptp-d-metric-delta.up{background:#D1FAE5;color:#065F46;}
            .ptp-d-metric-delta.down{background:#FEE2E2;color:#DC2626;}
            .ptp-d-metric-delta.flat{background:#F3F4F6;color:#6B7280;}
            
            .ptp-d-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-bottom:28px;}
            .ptp-d-action{display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:12px;border:1px solid;text-decoration:none;transition:transform .15s,box-shadow .15s;}
            .ptp-d-action:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.08);}
            .ptp-d-action.warning{background:#FFFBEB;border-color:#FDE68A;color:#92400E;}
            .ptp-d-action.info{background:#EFF6FF;border-color:#BFDBFE;color:#1E40AF;}
            .ptp-d-action.danger{background:#FEF2F2;border-color:#FECACA;color:#991B1B;}
            .ptp-d-action-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
            .ptp-d-action.warning .ptp-d-action-icon{background:#F59E0B;color:#fff;}
            .ptp-d-action.info .ptp-d-action-icon{background:#3B82F6;color:#fff;}
            .ptp-d-action.danger .ptp-d-action-icon{background:#EF4444;color:#fff;}
            .ptp-d-action-icon .dashicons{font-size:18px;width:18px;height:18px;}
            .ptp-d-action-text{flex:1;min-width:0;}
            .ptp-d-action-title{font-weight:700;font-size:14px;}
            .ptp-d-action-desc{font-size:12px;opacity:.8;margin-top:1px;}
            .ptp-d-action-btn{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
            
            .ptp-d-today{margin-bottom:28px;}
            .ptp-d-today-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
            .ptp-d-section-title{font-size:15px;font-weight:700;color:#0A0A0A;display:flex;align-items:center;gap:8px;}
            .ptp-d-section-title .dashicons{color:#FCB900;}
            .ptp-d-today-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;}
            .ptp-d-session-card{background:#fff;border-radius:14px;padding:18px 20px;border:1px solid #E5E7EB;transition:box-shadow .15s;}
            .ptp-d-session-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.06);}
            .ptp-d-session-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
            .ptp-d-session-time{font-size:18px;font-weight:700;font-family:'Oswald',sans-serif;color:#0A0A0A;}
            .ptp-d-session-status{font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.04em;}
            .ptp-d-session-status.confirmed{background:#D1FAE5;color:#065F46;}
            .ptp-d-session-status.pending{background:#FEF3C7;color:#92400E;}
            .ptp-d-session-people{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
            .ptp-d-session-person{font-size:13px;}
            .ptp-d-session-person-label{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;font-weight:600;}
            .ptp-d-session-person-name{font-weight:600;color:#111827;margin-top:1px;}
            .ptp-d-session-person-phone{font-size:12px;color:#6B7280;margin-top:1px;}
            .ptp-d-session-person-phone a{color:#3B82F6;text-decoration:none;}
            .ptp-d-session-footer{display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid #F3F4F6;}
            .ptp-d-session-loc{font-size:12px;color:#6B7280;display:flex;align-items:center;gap:4px;max-width:60%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
            .ptp-d-session-amt{font-weight:700;color:#0A0A0A;font-family:'Oswald',sans-serif;font-size:16px;}
            .ptp-d-session-free{color:#10B981;}
            
            .ptp-d-grid{display:grid;grid-template-columns:1fr 340px;gap:24px;}
            @media(max-width:1200px){.ptp-d-grid{grid-template-columns:1fr;}.ptp-d-metrics{grid-template-columns:repeat(2,1fr);}}
            @media(max-width:640px){.ptp-d-metrics{grid-template-columns:1fr;}.ptp-d-pipeline{flex-direction:column;}.ptp-d-pipe-stage::after{display:none;}}
            </style>
            
            <!-- ═══ PIPELINE ═══ -->
            <?php if ($has_app_table && $pipeline['total'] > 0): ?>
            <div class="ptp-d-pipeline">
                <div class="ptp-d-pipe-stage total">
                    <div class="ptp-d-pipe-num"><?php echo $pipeline['total']; ?></div>
                    <div class="ptp-d-pipe-label">Total Applications</div>
                </div>
                <div class="ptp-d-pipe-stage pending">
                    <div class="ptp-d-pipe-num"><?php echo $pipeline['pending']; ?></div>
                    <div class="ptp-d-pipe-label">Awaiting Match</div>
                </div>
                <div class="ptp-d-pipe-stage matched">
                    <div class="ptp-d-pipe-num"><?php echo $pipeline['accepted']; ?></div>
                    <div class="ptp-d-pipe-label">Matched / Code Sent</div>
                </div>
                <div class="ptp-d-pipe-stage booked">
                    <div class="ptp-d-pipe-num"><?php echo $pipeline['booked']; ?></div>
                    <div class="ptp-d-pipe-label">Converted to Booking</div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ═══ REVENUE METRICS ═══ -->
            <div class="ptp-d-metrics">
                <div class="ptp-d-metric">
                    <div class="ptp-d-metric-top">
                        <div class="ptp-d-metric-val">$<?php echo number_format($today_revenue, 0); ?></div>
                    </div>
                    <div class="ptp-d-metric-label">Today's Revenue</div>
                </div>
                <div class="ptp-d-metric">
                    <div class="ptp-d-metric-top">
                        <div class="ptp-d-metric-val">$<?php echo number_format($week_revenue, 0); ?></div>
                        <?php if ($week_pct != 0): ?>
                        <span class="ptp-d-metric-delta <?php echo $week_pct > 0 ? 'up' : ($week_pct < 0 ? 'down' : 'flat'); ?>"><?php echo ($week_pct > 0 ? '+' : '') . $week_pct; ?>%</span>
                        <?php endif; ?>
                    </div>
                    <div class="ptp-d-metric-label">This Week</div>
                </div>
                <div class="ptp-d-metric">
                    <div class="ptp-d-metric-top">
                        <div class="ptp-d-metric-val">$<?php echo number_format($month_revenue, 0); ?></div>
                        <?php if ($month_pct != 0): ?>
                        <span class="ptp-d-metric-delta <?php echo $month_pct > 0 ? 'up' : ($month_pct < 0 ? 'down' : 'flat'); ?>"><?php echo ($month_pct > 0 ? '+' : '') . $month_pct; ?>%</span>
                        <?php endif; ?>
                    </div>
                    <div class="ptp-d-metric-label">This Month</div>
                </div>
                <div class="ptp-d-metric">
                    <div class="ptp-d-metric-top">
                        <div class="ptp-d-metric-val">$<?php echo number_format($stats['revenue'], 0); ?></div>
                    </div>
                    <div class="ptp-d-metric-label">All Time</div>
                </div>
            </div>
            
            <!-- ═══ ACTION ITEMS ═══ -->
            <?php if (!empty($actions)): ?>
            <div class="ptp-d-actions">
                <?php foreach ($actions as $a): ?>
                <a href="<?php echo esc_url($a['url']); ?>" class="ptp-d-action <?php echo $a['t']; ?>">
                    <div class="ptp-d-action-icon"><span class="dashicons dashicons-<?php echo $a['icon']; ?>"></span></div>
                    <div class="ptp-d-action-text">
                        <div class="ptp-d-action-title"><?php echo esc_html($a['n']); ?></div>
                        <div class="ptp-d-action-desc"><?php echo esc_html($a['d']); ?></div>
                    </div>
                    <div class="ptp-d-action-btn"><?php echo esc_html($a['btn']); ?> &rarr;</div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- ═══ TODAY'S SESSIONS ═══ -->
            <div class="ptp-d-today">
                <div class="ptp-d-today-header">
                    <div class="ptp-d-section-title">
                        <span class="dashicons dashicons-clock"></span>
                        Today's Sessions (<?php echo count($todays_sessions); ?>)
                    </div>
                    <a href="<?php echo admin_url('admin.php?page=ptp-schedule'); ?>" style="font-size:13px;color:#3B82F6;text-decoration:none;font-weight:600;">Full Schedule &rarr;</a>
                </div>
                
                <?php if ($todays_sessions): ?>
                <div class="ptp-d-today-grid">
                    <?php foreach ($todays_sessions as $s): 
                        $is_free = ($s->payment_status ?? '') === 'free_session';
                    ?>
                    <div class="ptp-d-session-card">
                        <div class="ptp-d-session-top">
                            <div class="ptp-d-session-time"><?php echo $s->start_time ? date('g:i A', strtotime($s->start_time)) : 'TBD'; ?><?php echo $s->end_time ? ' - '.date('g:i A', strtotime($s->end_time)) : ''; ?></div>
                            <span class="ptp-d-session-status <?php echo esc_attr($s->status); ?>"><?php echo ucfirst($s->status); ?></span>
                        </div>
                        <div class="ptp-d-session-people">
                            <div class="ptp-d-session-person">
                                <div class="ptp-d-session-person-label">Trainer</div>
                                <div class="ptp-d-session-person-name"><?php echo esc_html($s->trainer_name ?: '—'); ?></div>
                                <?php if ($s->trainer_phone): ?>
                                <div class="ptp-d-session-person-phone"><a href="tel:<?php echo esc_attr($s->trainer_phone); ?>"><?php echo esc_html($s->trainer_phone); ?></a></div>
                                <?php endif; ?>
                            </div>
                            <div class="ptp-d-session-person">
                                <div class="ptp-d-session-person-label">Parent / Player</div>
                                <div class="ptp-d-session-person-name"><?php echo esc_html(($s->player_name ?: '') . ($s->parent_name ? ' ('.$s->parent_name.')' : '')); ?></div>
                                <?php if ($s->parent_phone): ?>
                                <div class="ptp-d-session-person-phone"><a href="tel:<?php echo esc_attr($s->parent_phone); ?>"><?php echo esc_html($s->parent_phone); ?></a></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ptp-d-session-footer">
                            <div class="ptp-d-session-loc"><span class="dashicons dashicons-location" style="font-size:14px;width:14px;height:14px;"></span> <?php echo esc_html($s->location ?: 'TBD'); ?></div>
                            <div class="ptp-d-session-amt <?php echo $is_free ? 'ptp-d-session-free' : ''; ?>"><?php echo $is_free ? 'FREE' : '$'.number_format($s->total_amount, 0); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div style="background:#fff;border-radius:14px;padding:32px;text-align:center;border:1px solid #E5E7EB;color:#9CA3AF;">
                    No sessions scheduled for today
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ═══ MAIN GRID ═══ -->
            <div class="ptp-d-grid">
                <div>
                    <!-- UPCOMING SESSIONS -->
                    <div class="ptp-card" style="margin-bottom:24px;">
                        <div class="ptp-card-header">
                            <h3 class="ptp-card-title"><span class="dashicons dashicons-calendar-alt"></span> Upcoming This Week</h3>
                            <a href="<?php echo admin_url('admin.php?page=ptp-bookings&status=confirmed'); ?>" style="font-size:13px;color:#3B82F6;text-decoration:none;font-weight:500;">All Bookings &rarr;</a>
                        </div>
                        <div class="ptp-card-body" style="padding:0;">
                            <?php if ($upcoming): ?>
                            <table class="ptp-table" style="font-size:13px;">
                                <thead><tr><th>Date</th><th>Trainer</th><th>Player</th><th>Time</th><th style="text-align:right;">Amt</th></tr></thead>
                                <tbody>
                                <?php foreach ($upcoming as $u): ?>
                                <tr>
                                    <td><span style="font-weight:600;color:#111827;"><?php echo date('D M j', strtotime($u->session_date)); ?></span></td>
                                    <td style="display:flex;align-items:center;gap:8px;">
                                        <?php if ($u->trainer_photo): ?><img src="<?php echo esc_url($u->trainer_photo); ?>" style="width:28px;height:28px;border-radius:8px;object-fit:cover;"><?php endif; ?>
                                        <?php echo esc_html($u->trainer_name ?: '—'); ?>
                                    </td>
                                    <td><?php echo esc_html($u->player_name ?: '—'); ?></td>
                                    <td><?php echo $u->start_time ? date('g:i A', strtotime($u->start_time)) : 'TBD'; ?></td>
                                    <td style="text-align:right;font-weight:600;">$<?php echo number_format($u->total_amount, 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                            <div style="padding:40px;text-align:center;color:#9CA3AF;">No upcoming sessions this week</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- RECENT BOOKINGS -->
                    <div class="ptp-card">
                        <div class="ptp-card-header">
                            <h3 class="ptp-card-title"><span class="dashicons dashicons-update"></span> Recent Activity</h3>
                        </div>
                        <div class="ptp-card-body" style="padding:0;">
                            <?php if ($recent): ?>
                            <table class="ptp-table" style="font-size:13px;">
                                <thead><tr><th>Booking</th><th>Trainer</th><th>Player</th><th>Status</th><th style="text-align:right;">Amt</th></tr></thead>
                                <tbody>
                                <?php foreach ($recent as $r): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:600;"><?php echo esc_html($r->booking_number ?: '#'.$r->id); ?></div>
                                        <div style="font-size:11px;color:#9CA3AF;"><?php echo human_time_diff(strtotime($r->created_at), $now_ts); ?> ago</div>
                                    </td>
                                    <td><?php echo esc_html($r->trainer_name ?: '—'); ?></td>
                                    <td><?php echo esc_html($r->player_name ?: '—'); ?></td>
                                    <td><span class="ptp-status ptp-status-<?php echo esc_attr($r->status); ?>"><?php echo ucfirst($r->status); ?></span></td>
                                    <td style="text-align:right;font-weight:600;">$<?php echo number_format($r->total_amount, 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                            <div style="padding:40px;text-align:center;color:#9CA3AF;">No bookings yet</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- SIDEBAR -->
                <div>
                    <!-- QUICK STATS -->
                    <div class="ptp-card ptp-card-dark" style="margin-bottom:20px;">
                        <div class="ptp-card-header" style="background:#0A0A0A;border-bottom:1px solid #222;">
                            <h3 class="ptp-card-title" style="color:#fff;"><span class="dashicons dashicons-chart-pie" style="color:#FCB900;"></span> Platform</h3>
                        </div>
                        <div class="ptp-card-body" style="background:#0A0A0A;padding:20px 24px;">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                                <div><div style="font-size:24px;font-weight:700;color:#FCB900;font-family:'Oswald',sans-serif;"><?php echo $stats['trainers']; ?></div><div style="font-size:11px;color:#9CA3AF;text-transform:uppercase;letter-spacing:.05em;">Trainers</div></div>
                                <div><div style="font-size:24px;font-weight:700;color:#fff;font-family:'Oswald',sans-serif;"><?php echo $stats['parents']; ?></div><div style="font-size:11px;color:#9CA3AF;text-transform:uppercase;letter-spacing:.05em;">Parents</div></div>
                                <div><div style="font-size:24px;font-weight:700;color:#fff;font-family:'Oswald',sans-serif;"><?php echo $stats['bookings']; ?></div><div style="font-size:11px;color:#9CA3AF;text-transform:uppercase;letter-spacing:.05em;">Bookings</div></div>
                                <div><div style="font-size:24px;font-weight:700;color:#10B981;font-family:'Oswald',sans-serif;"><?php echo $new_parents_week; ?></div><div style="font-size:11px;color:#9CA3AF;text-transform:uppercase;letter-spacing:.05em;">New This Week</div></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- TOP TRAINERS -->
                    <div class="ptp-card" style="margin-bottom:20px;">
                        <div class="ptp-card-header">
                            <h3 class="ptp-card-title"><span class="dashicons dashicons-star-filled" style="color:#FCB900;"></span> Top Trainers</h3>
                            <span style="font-size:11px;color:#9CA3AF;text-transform:uppercase;">This month</span>
                        </div>
                        <div class="ptp-card-body" style="padding:12px 20px;">
                            <?php if ($top_trainers): foreach ($top_trainers as $i => $t): ?>
                            <div style="display:flex;align-items:center;gap:12px;padding:10px 0;<?php echo $i < count($top_trainers)-1 ? 'border-bottom:1px solid #F3F4F6;' : ''; ?>">
                                <span style="width:22px;height:22px;border-radius:50%;background:<?php echo $i===0?'#FCB900':($i===1?'#C0C0C0':($i===2?'#CD7F32':'#E5E7EB')); ?>;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:<?php echo $i<3?'#fff':'#6B7280'; ?>;flex-shrink:0;"><?php echo $i+1; ?></span>
                                <?php if ($t->photo_url): ?>
                                <img src="<?php echo esc_url($t->photo_url); ?>" style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                                <?php else: ?>
                                <div style="width:32px;height:32px;border-radius:8px;background:#FCB900;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#0A0A0A;flex-shrink:0;"><?php echo strtoupper(substr($t->display_name,0,2)); ?></div>
                                <?php endif; ?>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:600;font-size:13px;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html($t->display_name); ?></div>
                                    <div style="font-size:11px;color:#9CA3AF;"><?php echo $t->booking_count; ?> sessions &middot; $<?php echo number_format($t->total_revenue, 0); ?></div>
                                </div>
                            </div>
                            <?php endforeach; else: ?>
                            <div style="text-align:center;padding:20px;color:#9CA3AF;font-size:13px;">No data yet</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- QUICK ACTIONS -->
                    <div class="ptp-card">
                        <div class="ptp-card-header">
                            <h3 class="ptp-card-title"><span class="dashicons dashicons-admin-links"></span> Quick Actions</h3>
                        </div>
                        <div class="ptp-card-body" style="padding:12px 16px;">
                            <?php
                            $links = array(
                                array('icon'=>'calendar','label'=>'Schedule','url'=>admin_url('admin.php?page=ptp-schedule')),
                                array('icon'=>'portfolio','label'=>'Applications','url'=>admin_url('admin.php?page=ptp-applications'),'badge'=>$pending_trainer_apps),
                                array('icon'=>'groups','label'=>'Trainers','url'=>admin_url('admin.php?page=ptp-trainers')),
                                array('icon'=>'money-alt','label'=>'Payments','url'=>admin_url('admin.php?page=ptp-payments')),
                                array('icon'=>'chart-bar','label'=>'Analytics','url'=>admin_url('admin.php?page=ptp-analytics')),
                                array('icon'=>'admin-settings','label'=>'Settings','url'=>admin_url('admin.php?page=ptp-settings')),
                            );
                            foreach ($links as $l): ?>
                            <a href="<?php echo esc_url($l['url']); ?>" style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;color:#374151;text-decoration:none;font-weight:500;font-size:13px;transition:background .15s;" onmouseover="this.style.background='#F3F4F6'" onmouseout="this.style.background='transparent'">
                                <span class="dashicons dashicons-<?php echo $l['icon']; ?>" style="font-size:18px;width:18px;height:18px;color:#6B7280;"></span>
                                <?php echo esc_html($l['label']); ?>
                                <?php if (!empty($l['badge']) && $l['badge'] > 0): ?>
                                <span style="margin-left:auto;background:#EF4444;color:#fff;font-size:11px;font-weight:600;padding:1px 7px;border-radius:20px;"><?php echo $l['badge']; ?></span>
                                <?php else: ?>
                                <span style="margin-left:auto;color:#D1D5DB;">&rarr;</span>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    
    /* =========================================================================
       ANALYTICS PAGE - Full Analytics Dashboard
       ========================================================================= */
    public function analytics_page() {
        // Render standalone nav (analytics dashboard has its own header)
        $this->render_standalone_nav('analytics');
        
        // v211: Delegate to the full analytics dashboard class
        if (class_exists('PTP_Analytics_Dashboard')) {
            PTP_Analytics_Dashboard::instance()->render_dashboard();
            return;
        }
        $tmpl = PTP_PLUGIN_DIR . 'templates/admin-analytics.php';
        if (file_exists($tmpl)) { include $tmpl; } else { echo '<div class="wrap"><h1>Analytics</h1><p>Analytics dashboard loading...</p></div>'; }
    }
    
    /* =========================================================================
       TRAINER REFERRALS PAGE - Track Trainer-to-Trainer Referrals
       ========================================================================= */
    public function trainer_referrals_page() {
        $tmpl = PTP_PLUGIN_DIR . 'templates/admin-trainer-referrals.php';
        if (file_exists($tmpl)) { include $tmpl; } else { echo '<div class="wrap"><h1>Trainer Referrals</h1><p>Template not found.</p></div>'; }
    }
    
    /* =========================================================================
       SCHEDULE PAGE - Admin Calendar for Creating Sessions
       ========================================================================= */
    public function schedule_page() {
        // v2: Delegate to Acuity-style schedule calendar
        if (class_exists('PTP_Schedule_Calendar_V2')) {
            PTP_Schedule_Calendar_V2::instance()->render_calendar();
            return;
        }
        
        // Fallback: old schedule (should not reach here)
        global $wpdb;
        
        $success_message = '';
        $error_message = '';
        
        // Handle session creation/update
        if (isset($_POST['ptp_create_session']) && wp_verify_nonce($_POST['_wpnonce'], 'ptp_create_session')) {
            $session_id = intval($_POST['session_id'] ?? 0);
            $trainer_id = intval($_POST['trainer_id']);
            $parent_id = intval($_POST['parent_id']);
            $player_id = intval($_POST['player_id']);
            $session_date = sanitize_text_field($_POST['session_date']);
            $start_time = sanitize_text_field($_POST['start_time']);
            $end_time = sanitize_text_field($_POST['end_time']);
            $location = sanitize_text_field($_POST['location']);
            $hourly_rate = floatval($_POST['hourly_rate']);
            $notes = sanitize_textarea_field($_POST['notes']);
            $status = sanitize_text_field($_POST['status']);
            $send_notification = !empty($_POST['send_notification']);
            
            $start = strtotime($start_time);
            $end = strtotime($end_time);
            $duration = ($end - $start) / 60;
            $total_amount = ($duration / 60) * $hourly_rate;
            
            $data = array(
                'trainer_id' => $trainer_id, 'parent_id' => $parent_id, 'player_id' => $player_id,
                'session_date' => $session_date, 'start_time' => $start_time, 'end_time' => $end_time,
                'duration_minutes' => $duration, 'location' => $location, 'hourly_rate' => $hourly_rate,
                'total_amount' => $total_amount, 'notes' => $notes, 'status' => $status,
            );
            $format = array('%d','%d','%d','%s','%s','%s','%d','%s','%f','%f','%s','%s');
            
            // v223-fix: Overlap-aware double booking prevention
            if (class_exists('PTP_Booking') && method_exists('PTP_Booking', 'has_conflict')) {
                $conflict = PTP_Booking::has_conflict($trainer_id, $session_date, $start_time, $duration, $session_id);
                if ($conflict) {
                    $cb = $wpdb->get_row($wpdb->prepare("SELECT booking_number, start_time, end_time FROM {$wpdb->prefix}ptp_bookings WHERE id = %d", $conflict));
                    $error_message = 'Double booking prevented — trainer already has a session on ' . $session_date;
                    if ($cb) $error_message .= ' from ' . date('g:i A', strtotime($cb->start_time)) . '-' . date('g:i A', strtotime($cb->end_time)) . ' (#' . $cb->booking_number . ')';
                }
            }
            
            if (empty($error_message) && $session_id > 0) {
                $result = $wpdb->update($wpdb->prefix.'ptp_bookings', $data, array('id'=>$session_id), $format, array('%d'));
                if ($result !== false) {
                    $success_message = "Session updated successfully!";
                    if ($send_notification && class_exists('PTP_Email')) { PTP_Email::send_booking_confirmation($session_id); PTP_Email::send_trainer_new_booking($session_id); }
                } else { $error_message = "Failed to update session."; }
            } elseif (empty($error_message)) {
                $booking_number = 'PTP-' . strtoupper(substr(md5(uniqid()), 0, 8));
                $data['booking_number'] = $booking_number;
                $data['created_at'] = current_time('mysql');
                $data['created_by'] = get_current_user_id();
                $format = array_merge($format, array('%s','%s','%d'));
                $result = $wpdb->insert($wpdb->prefix.'ptp_bookings', $data, $format);
                if ($result) {
                    $new_id = $wpdb->insert_id;
                    $success_message = "Session created! Booking #: {$booking_number}";
                    if ($send_notification && class_exists('PTP_Email')) { PTP_Email::send_booking_confirmation($new_id); PTP_Email::send_trainer_new_booking($new_id); }
                } else { $error_message = "Failed to create session."; }
            }
        }
        
        // Handle deletion
        if (isset($_GET['action']) && $_GET['action'] === 'delete_session' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_session_' . $_GET['id'])) {
                $wpdb->delete($wpdb->prefix.'ptp_bookings', array('id'=>intval($_GET['id'])), array('%d'));
                $success_message = "Session deleted.";
            }
        }
        
        // ── DATA ──
        $trainers = $wpdb->get_results("SELECT id, display_name, hourly_rate, photo_url FROM {$wpdb->prefix}ptp_trainers WHERE status='active' ORDER BY display_name");
        $parents = $wpdb->get_results("SELECT id, display_name FROM {$wpdb->prefix}ptp_parents ORDER BY display_name");
        
        // Filters
        $filter_trainer = intval($_GET['trainer'] ?? 0);
        $filter_status = sanitize_text_field($_GET['status'] ?? '');
        $view_mode = sanitize_text_field($_GET['view'] ?? 'calendar');
        
        $week_start = isset($_GET['week']) ? sanitize_text_field($_GET['week']) : date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
        $today = current_time('Y-m-d');
        
        // Build query with filters
        $where = array("b.session_date BETWEEN '{$week_start}' AND '{$week_end}'");
        if ($filter_trainer > 0) $where[] = $wpdb->prepare("b.trainer_id=%d", $filter_trainer);
        if ($filter_status) $where[] = $wpdb->prepare("b.status=%s", $filter_status);
        $where_sql = implode(' AND ', $where);
        
        $sessions = $wpdb->get_results("
            SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
                   p.display_name as parent_name, p.phone as parent_phone,
                   pl.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE {$where_sql}
            ORDER BY b.session_date, b.start_time
        ");
        
        $sessions_by_date = array();
        foreach ($sessions as $s) { $sessions_by_date[$s->session_date][] = $s; }
        
        // Week stats
        $week_count = count($sessions);
        $week_revenue = 0; $week_confirmed = 0; $week_pending = 0;
        foreach ($sessions as $s) {
            $week_revenue += (float)$s->total_amount;
            if ($s->status === 'confirmed') $week_confirmed++;
            if ($s->status === 'pending') $week_pending++;
        }
        
        // Trainer colors for calendar
        $trainer_colors = array('#3B82F6','#8B5CF6','#10B981','#F59E0B','#EF4444','#EC4899','#14B8A6','#F97316','#6366F1','#84CC16');
        $trainer_color_map = array();
        foreach ($trainers as $i => $t) { $trainer_color_map[$t->id] = $trainer_colors[$i % count($trainer_colors)]; }
        
        // Build filter URL helper
        $base_url = admin_url('admin.php?page=ptp-schedule&week=' . $week_start);
        $filter_url = function($params = array()) use ($base_url, $filter_trainer, $filter_status, $view_mode) {
            $p = array_merge(array('trainer'=>$filter_trainer,'status'=>$filter_status,'view'=>$view_mode), $params);
            $url = $base_url;
            if ($p['trainer']) $url .= '&trainer='.$p['trainer'];
            if ($p['status']) $url .= '&status='.$p['status'];
            if ($p['view'] && $p['view'] !== 'calendar') $url .= '&view='.$p['view'];
            return $url;
        };
        
        ?>
        <div class="ptp-admin-wrap">
            <div class="ptp-admin-header">
                <div class="ptp-admin-header-content">
                    <div class="ptp-admin-logo"><span class="dashicons dashicons-universal-access"></span></div>
                    <div class="ptp-admin-title-wrap">
                        <h1 class="ptp-admin-title">PTP <span>Training</span></h1>
                        <p class="ptp-admin-subtitle">Schedule &amp; Session Manager</p>
                    </div>
                </div>
                <div class="ptp-admin-header-actions">
                    <button type="button" onclick="openModal();" class="ptp-btn ptp-btn-primary">
                        <span class="dashicons dashicons-plus-alt2"></span> Create Session
                    </button>
                </div>
            </div>
            
            <?php $this->render_nav('bookings'); /* Schedule is a sub-page of bookings */ ?>
            
            <?php if ($success_message): ?>
            <div class="ptp-notice ptp-notice-success" style="margin-bottom:20px;"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
            <div class="ptp-notice ptp-notice-error" style="margin-bottom:20px;"><span class="dashicons dashicons-warning"></span> <?php echo esc_html($error_message); ?></div>
            <?php endif; ?>
            
            <style>
            .ptp-s-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;}
            .ptp-s-toolbar-left{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
            .ptp-s-toolbar-right{display:flex;align-items:center;gap:8px;}
            .ptp-s-select{padding:8px 12px;border:2px solid #E5E7EB;border-radius:10px;font-size:13px;font-weight:500;background:#fff;cursor:pointer;min-width:140px;}
            .ptp-s-select:focus{outline:none;border-color:#FCB900;box-shadow:0 0 0 3px rgba(252,185,0,.1);}
            .ptp-s-view-toggle{display:flex;background:#F3F4F6;border-radius:10px;overflow:hidden;}
            .ptp-s-view-btn{padding:8px 14px;font-size:12px;font-weight:600;text-decoration:none;color:#6B7280;display:flex;align-items:center;gap:5px;transition:all .15s;}
            .ptp-s-view-btn.active{background:#0A0A0A;color:#fff;}
            .ptp-s-view-btn:hover:not(.active){background:#E5E7EB;color:#111827;}
            
            .ptp-s-stats{display:flex;gap:20px;margin-bottom:20px;padding:16px 24px;background:#fff;border-radius:14px;border:1px solid #E5E7EB;}
            .ptp-s-stat{display:flex;align-items:center;gap:10px;}
            .ptp-s-stat-num{font-size:22px;font-weight:700;font-family:'Oswald',sans-serif;color:#0A0A0A;line-height:1;}
            .ptp-s-stat-label{font-size:11px;color:#6B7280;text-transform:uppercase;letter-spacing:.05em;font-weight:600;}
            .ptp-s-stat-divider{width:1px;height:32px;background:#E5E7EB;}
            
            .ptp-s-week-nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;}
            .ptp-s-week-title{font-size:18px;font-weight:700;font-family:'Oswald',sans-serif;color:#0A0A0A;}
            .ptp-s-week-btns{display:flex;align-items:center;gap:6px;}
            .ptp-s-week-btn{padding:7px 14px;background:#F3F4F6;border-radius:8px;text-decoration:none;color:#374151;font-size:13px;font-weight:600;transition:all .15s;border:none;cursor:pointer;}
            .ptp-s-week-btn:hover{background:#E5E7EB;color:#111827;}
            .ptp-s-week-btn.today{background:#0A0A0A;color:#FCB900;}
            
            .ptp-s-cal{background:#fff;border-radius:14px;border:1px solid #E5E7EB;overflow:hidden;}
            .ptp-s-cal-head{display:grid;grid-template-columns:repeat(7,1fr);border-bottom:2px solid #E5E7EB;}
            .ptp-s-cal-hcell{padding:14px 8px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;}
            .ptp-s-cal-hcell.is-today{background:#FFFBEB;}
            .ptp-s-cal-hcell .day-num{font-size:20px;font-family:'Oswald',sans-serif;color:#0A0A0A;display:block;margin-top:2px;}
            .ptp-s-cal-hcell.is-today .day-num{color:#FCB900;}
            .ptp-s-cal-body{display:grid;grid-template-columns:repeat(7,1fr);}
            .ptp-s-cal-cell{min-height:120px;border-right:1px solid #F3F4F6;border-bottom:1px solid #F3F4F6;padding:8px;cursor:pointer;transition:background .1s;}
            .ptp-s-cal-cell:nth-child(7n){border-right:none;}
            .ptp-s-cal-cell:hover{background:#FAFBFC;}
            .ptp-s-cal-cell.is-today{background:#FFFDF5;}
            .ptp-s-cal-cell.is-past{opacity:.6;}
            
            .ptp-s-event{padding:6px 8px;border-radius:8px;margin-bottom:4px;cursor:pointer;font-size:12px;border-left:3px solid;transition:transform .1s,box-shadow .1s;}
            .ptp-s-event:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,.1);}
            .ptp-s-event-time{font-weight:700;font-size:11px;}
            .ptp-s-event-name{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
            .ptp-s-event-player{color:#6B7280;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
            .ptp-s-cell-empty{font-size:11px;color:#D1D5DB;text-align:center;padding:20px 4px;}
            
            .ptp-s-legend{display:flex;gap:16px;margin-top:14px;flex-wrap:wrap;}
            .ptp-s-legend-item{display:flex;align-items:center;gap:6px;font-size:12px;color:#6B7280;}
            .ptp-s-legend-dot{width:10px;height:10px;border-radius:3px;}
            
            .ptp-s-list{background:#fff;border-radius:14px;border:1px solid #E5E7EB;overflow:hidden;}
            .ptp-s-list-day{border-bottom:1px solid #E5E7EB;}
            .ptp-s-list-day:last-child{border-bottom:none;}
            .ptp-s-list-day-header{padding:12px 20px;background:#F9FAFB;font-weight:700;font-size:13px;color:#374151;display:flex;justify-content:space-between;align-items:center;}
            .ptp-s-list-day-count{font-size:11px;font-weight:600;background:#E5E7EB;padding:2px 8px;border-radius:20px;color:#6B7280;}
            .ptp-s-list-row{display:grid;grid-template-columns:80px 1fr 1fr 1fr 80px 60px;gap:12px;padding:14px 20px;align-items:center;border-bottom:1px solid #F3F4F6;font-size:13px;transition:background .1s;cursor:pointer;}
            .ptp-s-list-row:hover{background:#FAFBFC;}
            .ptp-s-list-row:last-child{border-bottom:none;}
            .ptp-s-list-time{font-weight:700;font-family:'Oswald',sans-serif;font-size:14px;}
            .ptp-s-list-trainer{display:flex;align-items:center;gap:8px;}
            .ptp-s-list-trainer img{width:28px;height:28px;border-radius:8px;object-fit:cover;}
            .ptp-s-list-amt{font-weight:700;text-align:right;font-family:'Oswald',sans-serif;}
            .ptp-s-list-actions{text-align:right;}
            
            @media(max-width:900px){
                .ptp-s-cal-hcell .day-num{font-size:16px;}
                .ptp-s-cal-cell{min-height:80px;padding:4px;}
                .ptp-s-event{padding:4px 6px;}
                .ptp-s-list-row{grid-template-columns:60px 1fr 1fr 60px;}.ptp-s-list-row>:nth-child(4),.ptp-s-list-row>:nth-child(6){display:none;}
                .ptp-s-stats{flex-wrap:wrap;gap:12px;}
            }
            </style>
            
            <!-- ═══ TOOLBAR ═══ -->
            <div class="ptp-s-toolbar">
                <div class="ptp-s-toolbar-left">
                    <select class="ptp-s-select" onchange="location.href=this.value">
                        <option value="<?php echo esc_url($filter_url(array('trainer'=>0))); ?>" <?php echo !$filter_trainer ? 'selected' : ''; ?>>All Trainers</option>
                        <?php foreach ($trainers as $t): ?>
                        <option value="<?php echo esc_url($filter_url(array('trainer'=>$t->id))); ?>" <?php echo $filter_trainer==$t->id ? 'selected' : ''; ?>><?php echo esc_html($t->display_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="ptp-s-select" onchange="location.href=this.value" style="min-width:120px;">
                        <option value="<?php echo esc_url($filter_url(array('status'=>''))); ?>" <?php echo !$filter_status ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="<?php echo esc_url($filter_url(array('status'=>'confirmed'))); ?>" <?php echo $filter_status==='confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="<?php echo esc_url($filter_url(array('status'=>'pending'))); ?>" <?php echo $filter_status==='pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="<?php echo esc_url($filter_url(array('status'=>'completed'))); ?>" <?php echo $filter_status==='completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="<?php echo esc_url($filter_url(array('status'=>'cancelled'))); ?>" <?php echo $filter_status==='cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                    <?php if ($filter_trainer || $filter_status): ?>
                    <a href="<?php echo admin_url('admin.php?page=ptp-schedule&week='.$week_start); ?>" style="font-size:12px;color:#EF4444;text-decoration:none;font-weight:600;">Clear Filters &times;</a>
                    <?php endif; ?>
                </div>
                <div class="ptp-s-toolbar-right">
                    <div class="ptp-s-view-toggle">
                        <a href="<?php echo esc_url($filter_url(array('view'=>'calendar'))); ?>" class="ptp-s-view-btn <?php echo $view_mode!=='list'?'active':''; ?>"><span class="dashicons dashicons-calendar" style="font-size:15px;width:15px;height:15px;"></span> Calendar</a>
                        <a href="<?php echo esc_url($filter_url(array('view'=>'list'))); ?>" class="ptp-s-view-btn <?php echo $view_mode==='list'?'active':''; ?>"><span class="dashicons dashicons-list-view" style="font-size:15px;width:15px;height:15px;"></span> List</a>
                    </div>
                </div>
            </div>
            
            <!-- ═══ WEEK STATS ═══ -->
            <div class="ptp-s-stats">
                <div class="ptp-s-stat">
                    <div class="ptp-s-stat-num"><?php echo $week_count; ?></div>
                    <div class="ptp-s-stat-label">Sessions<br>This Week</div>
                </div>
                <div class="ptp-s-stat-divider"></div>
                <div class="ptp-s-stat">
                    <div class="ptp-s-stat-num" style="color:#10B981;"><?php echo $week_confirmed; ?></div>
                    <div class="ptp-s-stat-label">Confirmed</div>
                </div>
                <div class="ptp-s-stat-divider"></div>
                <div class="ptp-s-stat">
                    <div class="ptp-s-stat-num" style="color:#F59E0B;"><?php echo $week_pending; ?></div>
                    <div class="ptp-s-stat-label">Pending</div>
                </div>
                <div class="ptp-s-stat-divider"></div>
                <div class="ptp-s-stat">
                    <div class="ptp-s-stat-num">$<?php echo number_format($week_revenue, 0); ?></div>
                    <div class="ptp-s-stat-label">Revenue</div>
                </div>
            </div>
            
            <!-- ═══ WEEK NAVIGATION ═══ -->
            <div class="ptp-s-week-nav">
                <div class="ptp-s-week-title">
                    <?php echo date('M j', strtotime($week_start)); ?> &ndash; <?php echo date('M j, Y', strtotime($week_end)); ?>
                </div>
                <div class="ptp-s-week-btns">
                    <a href="<?php echo esc_url($filter_url(array()) . '&week=' . date('Y-m-d', strtotime($week_start.' -7 days'))); ?>" class="ptp-s-week-btn">&larr; Prev</a>
                    <a href="<?php echo esc_url($filter_url(array()) . '&week=' . date('Y-m-d', strtotime('monday this week'))); ?>" class="ptp-s-week-btn today">Today</a>
                    <a href="<?php echo esc_url($filter_url(array()) . '&week=' . date('Y-m-d', strtotime($week_start.' +7 days'))); ?>" class="ptp-s-week-btn">Next &rarr;</a>
                </div>
            </div>
            
            <?php if ($view_mode === 'list'): ?>
            <!-- ═══ LIST VIEW ═══ -->
            <div class="ptp-s-list">
                <?php
                $has_any = false;
                for ($i = 0; $i < 7; $i++):
                    $date = date('Y-m-d', strtotime($week_start . " +{$i} days"));
                    $day_sessions = $sessions_by_date[$date] ?? array();
                    if (empty($day_sessions) && !($date === $today)) continue;
                    $has_any = true;
                    $is_today = $date === $today;
                ?>
                <div class="ptp-s-list-day">
                    <div class="ptp-s-list-day-header" style="<?php echo $is_today ? 'background:#FFFBEB;color:#0A0A0A;' : ''; ?>">
                        <span><?php echo $is_today ? 'TODAY &mdash; ' : ''; ?><?php echo date('l, M j', strtotime($date)); ?></span>
                        <span class="ptp-s-list-day-count"><?php echo count($day_sessions); ?> session<?php echo count($day_sessions)!==1?'s':''; ?></span>
                    </div>
                    <?php if ($day_sessions): foreach ($day_sessions as $s):
                        $color = $trainer_color_map[$s->trainer_id] ?? '#6B7280';
                    ?>
                    <div class="ptp-s-list-row" onclick="viewSession(<?php echo $s->id; ?>)">
                        <div class="ptp-s-list-time"><?php echo date('g:i A', strtotime($s->start_time)); ?></div>
                        <div class="ptp-s-list-trainer">
                            <?php if ($s->trainer_photo): ?><img src="<?php echo esc_url($s->trainer_photo); ?>"><?php else: ?><span style="width:28px;height:28px;border-radius:8px;background:<?php echo $color; ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700;"><?php echo strtoupper(substr($s->trainer_name ?: '?',0,2)); ?></span><?php endif; ?>
                            <span style="font-weight:600;"><?php echo esc_html($s->trainer_name ?: '—'); ?></span>
                        </div>
                        <div>
                            <div style="font-weight:500;"><?php echo esc_html($s->player_name ?: '—'); ?></div>
                            <div style="font-size:11px;color:#9CA3AF;"><?php echo esc_html($s->parent_name ?: ''); ?></div>
                        </div>
                        <div style="font-size:12px;color:#6B7280;"><?php echo esc_html($s->location ?: 'TBD'); ?></div>
                        <div class="ptp-s-list-amt">$<?php echo number_format($s->total_amount, 0); ?></div>
                        <div class="ptp-s-list-actions">
                            <span class="ptp-status ptp-status-<?php echo esc_attr($s->status); ?>" style="font-size:10px;padding:2px 8px;"><?php echo ucfirst($s->status); ?></span>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <div style="padding:20px;text-align:center;color:#D1D5DB;font-size:13px;">No sessions</div>
                    <?php endif; ?>
                </div>
                <?php endfor;
                if (!$has_any): ?>
                <div style="padding:60px;text-align:center;color:#9CA3AF;">
                    <span class="dashicons dashicons-calendar-alt" style="font-size:40px;width:40px;height:40px;display:block;margin:0 auto 12px;"></span>
                    No sessions this week<?php echo ($filter_trainer || $filter_status) ? ' with current filters' : ''; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php else: ?>
            <!-- ═══ CALENDAR VIEW ═══ -->
            <div class="ptp-s-cal">
                <div class="ptp-s-cal-head">
                    <?php
                    $days = array('Mon','Tue','Wed','Thu','Fri','Sat','Sun');
                    for ($i = 0; $i < 7; $i++):
                        $date = date('Y-m-d', strtotime($week_start." +{$i} days"));
                        $is_today = $date === $today;
                    ?>
                    <div class="ptp-s-cal-hcell <?php echo $is_today ? 'is-today' : ''; ?>">
                        <?php echo $days[$i]; ?>
                        <span class="day-num"><?php echo date('j', strtotime($date)); ?></span>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="ptp-s-cal-body">
                    <?php for ($i = 0; $i < 7; $i++):
                        $date = date('Y-m-d', strtotime($week_start." +{$i} days"));
                        $day_sessions = $sessions_by_date[$date] ?? array();
                        $is_today = $date === $today;
                        $is_past = $date < $today;
                    ?>
                    <div class="ptp-s-cal-cell <?php echo $is_today ? 'is-today' : ''; ?> <?php echo $is_past ? 'is-past' : ''; ?>" data-date="<?php echo $date; ?>" ondblclick="openModal('<?php echo $date; ?>')">
                        <?php foreach ($day_sessions as $s):
                            $color = $trainer_color_map[$s->trainer_id] ?? '#6B7280';
                            $status_bg = array('confirmed'=>'#F0FDF4','pending'=>'#FFFBEB','completed'=>'#EFF6FF','cancelled'=>'#FEF2F2');
                            $bg = $status_bg[$s->status] ?? '#F9FAFB';
                        ?>
                        <div class="ptp-s-event" onclick="viewSession(<?php echo $s->id; ?>);event.stopPropagation();" style="background:<?php echo $bg; ?>;border-left-color:<?php echo $color; ?>;">
                            <div class="ptp-s-event-time"><?php echo date('g:i A', strtotime($s->start_time)); ?></div>
                            <div class="ptp-s-event-name"><?php echo esc_html($s->trainer_name ?: '—'); ?></div>
                            <div class="ptp-s-event-player"><?php echo esc_html($s->player_name ?: $s->parent_name ?: '—'); ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($day_sessions)): ?>
                        <div class="ptp-s-cell-empty"><?php echo $is_past ? '—' : 'No sessions'; ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            
            <!-- Legend -->
            <div class="ptp-s-legend">
                <span style="font-size:11px;font-weight:600;color:#9CA3AF;text-transform:uppercase;letter-spacing:.05em;margin-right:4px;">Trainers:</span>
                <?php foreach ($trainers as $i => $t):
                    if ($i >= 8) break;
                    $c = $trainer_color_map[$t->id] ?? '#6B7280';
                ?>
                <span class="ptp-s-legend-item"><span class="ptp-s-legend-dot" style="background:<?php echo $c; ?>;"></span> <?php echo esc_html(explode(' ', $t->display_name)[0]); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
        </div>
        
        <!-- ═══ CREATE/EDIT SESSION MODAL ═══ -->
        <div id="create-session-modal" class="ptp-modal-overlay">
            <div class="ptp-modal" style="max-width:640px;">
                <div class="ptp-modal-header">
                    <h2 id="modal-title">Create Training Session</h2>
                    <button type="button" onclick="closeModal();" class="ptp-modal-close">&times;</button>
                </div>
                <form method="post" id="session-form" class="ptp-modal-body">
                    <?php wp_nonce_field('ptp_create_session'); ?>
                    <input type="hidden" name="ptp_create_session" value="1">
                    <input type="hidden" name="session_id" id="session-id" value="">
                    
                    <div class="ptp-form-grid">
                        <div class="ptp-form-group">
                            <label>Trainer *</label>
                            <select name="trainer_id" id="modal-trainer" required onchange="updateRate(this)">
                                <option value="">Select Trainer</option>
                                <?php foreach ($trainers as $t): ?>
                                <option value="<?php echo $t->id; ?>" data-rate="<?php echo $t->hourly_rate; ?>"><?php echo esc_html($t->display_name); ?> ($<?php echo number_format($t->hourly_rate, 0); ?>/hr)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ptp-form-group">
                            <label>Parent *</label>
                            <select name="parent_id" required id="modal-parent" onchange="loadPlayers(this.value)">
                                <option value="">Select Parent</option>
                                <?php foreach ($parents as $p): ?>
                                <option value="<?php echo $p->id; ?>"><?php echo esc_html($p->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ptp-form-group">
                            <label>Player</label>
                            <select name="player_id" id="modal-player"><option value="">Select Player</option></select>
                        </div>
                        <div class="ptp-form-group">
                            <label>Date *</label>
                            <input type="date" name="session_date" id="modal-date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="ptp-form-group">
                            <label>Start *</label>
                            <input type="time" name="start_time" id="modal-start" required value="09:00">
                        </div>
                        <div class="ptp-form-group">
                            <label>End *</label>
                            <input type="time" name="end_time" id="modal-end" required value="10:00">
                        </div>
                        <div class="ptp-form-group">
                            <label>Rate ($/hr)</label>
                            <input type="number" name="hourly_rate" id="modal-rate" value="70" min="0" step="5">
                        </div>
                        <div class="ptp-form-group">
                            <label>Status</label>
                            <select name="status" id="modal-status">
                                <option value="confirmed">Confirmed</option>
                                <option value="pending">Pending</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                    <div class="ptp-form-group" style="margin-top:16px;">
                        <label>Location</label>
                        <input type="text" name="location" id="modal-location" placeholder="e.g., Main Field, Indoor Gym">
                    </div>
                    <div class="ptp-form-group" style="margin-top:16px;">
                        <label>Notes</label>
                        <textarea name="notes" id="modal-notes" rows="2" placeholder="Any notes..." style="resize:vertical;"></textarea>
                    </div>
                    <div style="margin-top:16px;padding:10px 14px;background:#F0FDF4;border-radius:8px;border:1px solid #BBF7D0;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#166534;">
                            <input type="checkbox" name="send_notification" id="modal-notify" value="1" checked style="width:16px;height:16px;">
                            Send email notification to parent and trainer
                        </label>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:10px;padding-top:20px;">
                        <button type="button" onclick="closeModal();" class="ptp-btn ptp-btn-secondary ptp-btn-sm">Cancel</button>
                        <button type="submit" id="modal-submit" class="ptp-btn ptp-btn-primary">Create Session</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- ═══ SESSION DETAIL MODAL ═══ -->
        <div id="session-details-modal" class="ptp-modal-overlay">
            <div class="ptp-modal" style="max-width:480px;">
                <div class="ptp-modal-header">
                    <h2>Session Details</h2>
                    <button type="button" onclick="document.getElementById('session-details-modal').classList.remove('active');" class="ptp-modal-close">&times;</button>
                </div>
                <div class="ptp-modal-body" id="session-details-content"></div>
                <div class="ptp-modal-footer" style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="editSession()" class="ptp-btn ptp-btn-primary ptp-btn-sm">Edit</button>
                    <button type="button" onclick="deleteSession()" class="ptp-btn ptp-btn-sm" style="background:#EF4444;color:#fff;">Delete</button>
                </div>
            </div>
        </div>
        
        <script>
        var currentSessionId = null, currentSession = null;
        var sessionsData = <?php echo json_encode(array_map(function($s) {
            return array('id'=>$s->id,'trainer_id'=>$s->trainer_id,'parent_id'=>$s->parent_id,'player_id'=>$s->player_id,
                'session_date'=>$s->session_date,'start_time'=>$s->start_time,'end_time'=>$s->end_time,'location'=>$s->location,
                'hourly_rate'=>$s->hourly_rate,'status'=>$s->status,'notes'=>$s->notes,'trainer_name'=>$s->trainer_name,
                'parent_name'=>$s->parent_name,'player_name'=>$s->player_name,'total_amount'=>$s->total_amount,'parent_phone'=>$s->parent_phone);
        }, $sessions)); ?>;
        var deleteNonces = {<?php foreach ($sessions as $s) echo $s->id.":'".wp_create_nonce('delete_session_'.$s->id)."',"; ?>};
        
        function openModal(date) {
            document.getElementById('modal-title').textContent = 'Create Training Session';
            document.getElementById('modal-submit').textContent = 'Create Session';
            document.getElementById('session-id').value = '';
            document.getElementById('modal-date').value = date || '<?php echo date('Y-m-d'); ?>';
            document.getElementById('modal-trainer').value = '';
            document.getElementById('modal-parent').value = '';
            document.getElementById('modal-player').innerHTML = '<option value="">Select Player</option>';
            document.getElementById('modal-start').value = '16:00';
            document.getElementById('modal-end').value = '17:00';
            document.getElementById('modal-rate').value = '70';
            document.getElementById('modal-status').value = 'confirmed';
            document.getElementById('modal-location').value = '';
            document.getElementById('modal-notes').value = '';
            document.getElementById('modal-notify').checked = true;
            document.getElementById('create-session-modal').classList.add('active');
        }
        function closeModal() { document.getElementById('create-session-modal').classList.remove('active'); }
        
        function viewSession(id) {
            currentSessionId = id;
            var s = sessionsData.find(function(x){return x.id==id;});
            if (!s) return;
            currentSession = s;
            var sc = {confirmed:'#10B981',pending:'#F59E0B',completed:'#3B82F6',cancelled:'#EF4444'};
            document.getElementById('session-details-content').innerHTML = '<div style="margin-bottom:16px;"><span style="display:inline-block;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;color:#fff;background:'+(sc[s.status]||'#6B7280')+';text-transform:uppercase;letter-spacing:.04em;">'+s.status+'</span></div>'
                +'<div style="display:grid;gap:14px;">'
                +'<div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;font-weight:600;">Trainer</div><div style="font-weight:700;font-size:16px;margin-top:2px;">'+(s.trainer_name||'—')+'</div></div>'
                +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;"><div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;font-weight:600;">Parent</div><div style="font-weight:500;margin-top:2px;">'+(s.parent_name||'—')+'</div>'+(s.parent_phone?'<div style="font-size:12px;color:#3B82F6;margin-top:2px;"><a href="tel:'+s.parent_phone+'" style="color:#3B82F6;text-decoration:none;">'+s.parent_phone+'</a></div>':'')+'</div><div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;font-weight:600;">Player</div><div style="font-weight:500;margin-top:2px;">'+(s.player_name||'—')+'</div></div></div>'
                +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;"><div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;font-weight:600;">Date</div><div style="font-weight:500;margin-top:2px;">'+formatDate(s.session_date)+'</div></div><div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;font-weight:600;">Time</div><div style="font-weight:500;margin-top:2px;">'+formatTime(s.start_time)+' - '+formatTime(s.end_time)+'</div></div></div>'
                +'<div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;font-weight:600;">Location</div><div style="font-weight:500;margin-top:2px;">'+(s.location||'TBD')+'</div></div>'
                +'<div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;font-weight:600;">Amount</div><div style="font-weight:700;font-size:20px;color:#10B981;font-family:Oswald,sans-serif;margin-top:2px;">$'+parseFloat(s.total_amount||0).toFixed(2)+'</div></div>'
                +(s.notes?'<div><div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;font-weight:600;">Notes</div><div style="margin-top:2px;color:#374151;">'+s.notes+'</div></div>':'')
                +'</div>';
            document.getElementById('session-details-modal').classList.add('active');
        }
        
        function editSession() {
            if (!currentSession) return;
            var s = currentSession;
            document.getElementById('session-details-modal').classList.remove('active');
            document.getElementById('modal-title').textContent = 'Edit Training Session';
            document.getElementById('modal-submit').textContent = 'Update Session';
            document.getElementById('session-id').value = s.id;
            document.getElementById('modal-date').value = s.session_date;
            document.getElementById('modal-trainer').value = s.trainer_id;
            document.getElementById('modal-parent').value = s.parent_id;
            document.getElementById('modal-start').value = s.start_time;
            document.getElementById('modal-end').value = s.end_time;
            document.getElementById('modal-rate').value = s.hourly_rate;
            document.getElementById('modal-status').value = s.status;
            document.getElementById('modal-location').value = s.location || '';
            document.getElementById('modal-notes').value = s.notes || '';
            if (s.parent_id) loadPlayers(s.parent_id, s.player_id);
            document.getElementById('create-session-modal').classList.add('active');
        }
        
        function deleteSession() {
            if (!currentSessionId || !confirm('Delete this session?')) return;
            var n = deleteNonces[currentSessionId];
            if (!n) { alert('Refresh the page and try again.'); return; }
            window.location.href = '?page=ptp-schedule&week=<?php echo esc_attr($week_start); ?>&action=delete_session&id=' + currentSessionId + '&_wpnonce=' + n;
        }
        
        function formatDate(d) { return new Date(d+'T00:00:00').toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'}); }
        function formatTime(t) { var p=t.split(':'),h=parseInt(p[0]),m=p[1],a=h>=12?'PM':'AM'; return (h%12||12)+':'+m+' '+a; }
        function updateRate(sel) { var r=sel.options[sel.selectedIndex].getAttribute('data-rate'); if(r) document.getElementById('modal-rate').value=r; }
        
        function loadPlayers(parentId, selectedId) {
            var ps = document.getElementById('modal-player');
            ps.innerHTML = '<option value="">Loading...</option>';
            if (!parentId) { ps.innerHTML = '<option value="">Select Player</option>'; return; }
            fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=ptp_get_players&parent_id='+parentId+'&nonce=<?php echo wp_create_nonce('ptp_admin_nonce'); ?>')
                .then(function(r){return r.json();})
                .then(function(d){
                    ps.innerHTML = '<option value="">Select Player</option>';
                    if (d.success && d.data) d.data.forEach(function(p){
                        var o = document.createElement('option'); o.value=p.id; o.textContent=p.name+' (Age '+p.age+')';
                        if (selectedId && p.id==selectedId) o.selected=true; ps.appendChild(o);
                    });
                }).catch(function(){ps.innerHTML='<option value="">Select Player</option>';});
        }
        </script>
        <?php
    }
    
    /* =========================================================================
       TRAINERS PAGE
       ========================================================================= */
    public function trainers_page() {
        global $wpdb;
        
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : '';
        
        $trainers = array();
        $counts = array();
        $total = 0;
        $incomplete_count = 0;
        
        $table = $wpdb->prefix . 'ptp_trainers';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") == $table) {
            $where = "WHERE 1=1";
            if ($status) $where .= $wpdb->prepare(" AND t.status = %s", $status);
            if ($search) $where .= $wpdb->prepare(" AND (t.display_name LIKE %s OR u.user_email LIKE %s)", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
            
            // v133: Filter for incomplete onboarding
            if ($filter === 'incomplete_onboarding') {
                $where .= " AND t.status = 'active'";
                $where .= " AND (t.onboarding_completed_at IS NULL OR t.onboarding_completed_at = '0000-00-00 00:00:00')";
                $where .= " AND t.approved_at IS NOT NULL AND t.approved_at != '0000-00-00 00:00:00'";
            }
            
            $trainers = $wpdb->get_results("
                SELECT t.*, COALESCE(u.user_email, t.email) as user_email FROM {$wpdb->prefix}ptp_trainers t 
                LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID {$where} 
                ORDER BY t.is_featured DESC, t.sort_order ASC, t.created_at DESC
            ");
            
            $counts = $wpdb->get_results("SELECT status, COUNT(*) as count FROM {$wpdb->prefix}ptp_trainers GROUP BY status", OBJECT_K);
            $total = array_sum(array_column((array)$counts, 'count'));
            
            // v133: Count incomplete onboarding
            $incomplete_count = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers 
                WHERE status = 'active'
                AND (onboarding_completed_at IS NULL OR onboarding_completed_at = '0000-00-00 00:00:00')
                AND approved_at IS NOT NULL AND approved_at != '0000-00-00 00:00:00'
            ") ?: 0;
        }
        
        
        // Render standalone nav (template has its own header)
        $this->render_standalone_nav('trainers');
        
        // Include template
        include PTP_PLUGIN_DIR . 'templates/admin/trainers.php';
    }

    /**
     * LEGACY trainers_page HTML — replaced by templates/admin/trainers.php in v196.2
     * Keeping this marker for reference
     */
    private function _trainers_page_legacy_removed() {
        /* removed — all HTML moved to templates/admin/trainers.php */
    }

    /* =========================================================================
       APPLICATIONS PAGE
       ========================================================================= */
    public function applications_page() {
        global $wpdb;
        
        // Actions (approve/reject/delete) are now handled in handle_actions() on admin_init
        
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $message = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : '';
        
        $apps = array();
        $counts = array();
        $total = 0;
        $pending = 0;
        
        $table = $wpdb->prefix . 'ptp_applications';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") == $table) {
            $where = $status ? $wpdb->prepare("WHERE status = %s", $status) : "";
            $apps = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ptp_applications {$where} ORDER BY created_at DESC");
            $counts = $wpdb->get_results("SELECT status, COUNT(*) as count FROM {$wpdb->prefix}ptp_applications GROUP BY status", OBJECT_K);
            $total = array_sum(array_column((array)$counts, 'count'));
            $pending = isset($counts['pending']) ? $counts['pending']->count : 0;
        }
        
        $levels = array('professional' => 'Professional', 'pro' => 'Professional', 'd1' => 'NCAA D1', 'college_d1' => 'NCAA D1', 'd2' => 'NCAA D2', 'college_d2' => 'NCAA D2', 'd3' => 'NCAA D3', 'college_d3' => 'NCAA D3', 'academy' => 'Elite Academy', 'semi_pro' => 'Semi-Pro', 'other' => 'Other');
        
        ?>
        <style>
            .ptp-modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 100000; }
            .ptp-modal-overlay.active { display: flex; align-items: center; justify-content: center; }
            .ptp-modal { background: #fff; border-radius: 12px; max-width: 700px; width: 90%; max-height: 90vh; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
            .ptp-modal-header { padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
            .ptp-modal-header h2 { margin: 0; font-size: 18px; }
            .ptp-modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; }
            .ptp-modal-body { padding: 24px; overflow-y: auto; max-height: 70vh; }
            .ptp-modal-footer { padding: 16px 24px; border-top: 1px solid #e5e7eb; display: flex; gap: 12px; justify-content: flex-end; }
            .ptp-detail-row { display: flex; padding: 12px 0; border-bottom: 1px solid #f3f4f6; }
            .ptp-detail-row:last-child { border-bottom: none; }
            .ptp-detail-label { width: 140px; font-weight: 500; color: #6b7280; font-size: 13px; }
            .ptp-detail-value { flex: 1; color: #111827; }
            .ptp-actions-dropdown { position: relative; display: inline-block; }
            .ptp-actions-btn { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 13px; display: flex; align-items: center; gap: 4px; }
            .ptp-actions-btn:hover { background: #e5e7eb; }
            .ptp-actions-menu { display: none; position: absolute; right: 0; top: 100%; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); min-width: 160px; z-index: 100; overflow: hidden; }
            .ptp-actions-dropdown.open .ptp-actions-menu { display: block; }
            .ptp-actions-menu a { display: flex; align-items: center; gap: 8px; padding: 10px 14px; color: #374151; text-decoration: none; font-size: 13px; }
            .ptp-actions-menu a:hover { background: #f9fafb; }
            .ptp-actions-menu a.danger { color: #dc2626; }
            .ptp-actions-menu a.danger:hover { background: #fef2f2; }
            .ptp-actions-menu a.success { color: #059669; }
            .ptp-actions-menu a.success:hover { background: #ecfdf5; }
            .ptp-actions-menu .divider { height: 1px; background: #e5e7eb; margin: 4px 0; }
            .ptp-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
            .ptp-alert-success { background: #ecfdf5; color: #065f46; }
            .ptp-alert-warning { background: #fef3c7; color: #92400e; }
            .ptp-alert-danger { background: #fef2f2; color: #991b1b; }
            .ptp-bio-preview { background: #f9fafb; padding: 12px; border-radius: 6px; font-size: 14px; line-height: 1.5; max-height: 150px; overflow-y: auto; }
        </style>
        
        <div class="ptp-admin-wrap">
            <div class="ptp-admin-header">
                <div class="ptp-admin-header-content">
                    <div class="ptp-admin-logo">
                        <span class="dashicons dashicons-universal-access"></span>
                    </div>
                    <div class="ptp-admin-title-wrap">
                        <h1 class="ptp-admin-title">PTP <span>Training</span></h1>
                        <p class="ptp-admin-subtitle">Review trainer applications</p>
                    </div>
                </div>
            </div>
            
            <?php $this->render_nav('applications'); ?>
            
            <?php if ($message === 'approved'): ?>
            <div class="ptp-alert ptp-alert-success">
                <span class="dashicons dashicons-yes-alt"></span>
                Application approved! The trainer has been sent login credentials.
            </div>
            <?php elseif ($message === 'rejected'): ?>
            <div class="ptp-alert ptp-alert-warning">
                <span class="dashicons dashicons-dismiss"></span>
                Application rejected. The applicant has been notified.
            </div>
            <?php elseif ($message === 'deleted'): ?>
            <div class="ptp-alert ptp-alert-danger">
                <span class="dashicons dashicons-trash"></span>
                Application permanently deleted.
            </div>
            <?php elseif ($message === 'error'): ?>
            <div class="ptp-alert ptp-alert-danger">
                <span class="dashicons dashicons-warning"></span>
                <?php 
                $error_type = isset($_GET['error_type']) ? sanitize_text_field($_GET['error_type']) : 'unknown';
                if ($error_type === 'approval_failed') {
                    echo 'Failed to approve application. Check the error log for details. The application or trainer record may already exist.';
                } else {
                    echo 'An error occurred. Please try again.';
                }
                ?>
            </div>
            <?php endif; ?>
            
            <?php if ($pending > 0 && !$status && !$message): ?>
            <div class="ptp-notice ptp-notice-warning">
                <span class="dashicons dashicons-warning"></span>
                <div class="ptp-notice-content">
                    <strong>Action Required:</strong> You have <?php echo $pending; ?> pending application<?php echo $pending > 1 ? 's' : ''; ?> waiting for review.
                </div>
                <a href="?page=ptp-applications&status=pending" class="ptp-btn ptp-btn-warning ptp-btn-sm">Review Now</a>
            </div>
            <?php endif; ?>
            
            <div class="ptp-filter-tabs">
                <a href="?page=ptp-applications" class="ptp-filter-tab <?php echo !$status ? 'active' : ''; ?>">
                    All <span class="count"><?php echo $total; ?></span>
                </a>
                <a href="?page=ptp-applications&status=pending" class="ptp-filter-tab <?php echo $status === 'pending' ? 'active' : ''; ?>">
                    Pending <span class="count"><?php echo $pending; ?></span>
                </a>
                <a href="?page=ptp-applications&status=approved" class="ptp-filter-tab <?php echo $status === 'approved' ? 'active' : ''; ?>">
                    Approved <span class="count"><?php echo isset($counts['approved']) ? $counts['approved']->count : 0; ?></span>
                </a>
                <a href="?page=ptp-applications&status=rejected" class="ptp-filter-tab <?php echo $status === 'rejected' ? 'active' : ''; ?>">
                    Rejected <span class="count"><?php echo isset($counts['rejected']) ? $counts['rejected']->count : 0; ?></span>
                </a>
            </div>
            
            <?php if ($apps): ?>
            <div class="ptp-card">
                <div class="ptp-card-body no-padding">
                    <div class="ptp-table-wrap">
                        <table class="ptp-table">
                            <thead>
                                <tr>
                                    <th>Applicant</th>
                                    <th>Contact</th>
                                    <th>Experience</th>
                                    <th>Rate</th>
                                    <th>Status</th>
                                    <th>Applied</th>
                                    <th style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($apps as $a): ?>
                                <tr>
                                    <td>
                                        <div class="ptp-table-user">
                                            <div class="ptp-table-user-avatar"><?php echo strtoupper(substr($a->name ?: 'A', 0, 1)); ?></div>
                                            <div class="ptp-table-user-info">
                                                <div class="ptp-table-user-name"><?php echo esc_html($a->name ?: 'No name'); ?></div>
                                                <div class="ptp-table-user-email"><?php echo esc_html($a->location ?: (trim(($a->city ?? '') . ', ' . ($a->state ?? ''), ', ') ?: 'No location')); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="mailto:<?php echo esc_attr($a->email); ?>"><?php echo esc_html($a->email); ?></a><br>
                                        <small style="color: #6b7280;"><?php echo esc_html($a->phone ?: 'No phone'); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo isset($levels[$a->playing_level]) ? $levels[$a->playing_level] : esc_html($a->playing_level ?: 'Not specified'); ?></strong><br>
                                        <small style="color: #6b7280;"><?php echo esc_html($a->college ?: $a->team ?: '—'); ?></small>
                                    </td>
                                    <td><strong>$<?php echo number_format($a->hourly_rate ?: 0, 0); ?></strong>/hr</td>
                                    <td><span class="ptp-status ptp-status-<?php echo esc_attr($a->status); ?>"><?php echo ucfirst($a->status); ?></span></td>
                                    <td><?php echo date('M j, Y', strtotime($a->created_at)); ?></td>
                                    <td>
                                        <?php if ($a->status === 'pending'): ?>
                                        <div style="display:flex;gap:6px;align-items:center;">
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-applications&action=approve&id=' . $a->id), 'ptp_app_action'); ?>" 
                                               class="ptp-btn ptp-btn-success ptp-btn-sm" 
                                               title="Approve"
                                               onclick="return confirm('Approve this application? This will create a trainer account and send login credentials.');">
                                                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                                            </a>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-applications&action=reject&id=' . $a->id), 'ptp_app_action'); ?>" 
                                               class="ptp-btn ptp-btn-outline ptp-btn-sm" 
                                               title="Reject"
                                               style="color:#DC2626;border-color:#DC2626;"
                                               onclick="return confirm('Reject this application?');">
                                                <span class="dashicons dashicons-no" style="font-size:16px;width:16px;height:16px;"></span>
                                            </a>
                                            <div class="ptp-actions-dropdown">
                                                <button class="ptp-actions-btn" onclick="this.parentElement.classList.toggle('open')" style="padding:4px 8px;">
                                                    <span class="dashicons dashicons-ellipsis" style="font-size:16px;"></span>
                                                </button>
                                                <div class="ptp-actions-menu">
                                                    <a href="#" onclick="viewApplication(<?php echo $a->id; ?>); return false;">
                                                        <span class="dashicons dashicons-visibility"></span> View Details
                                                    </a>
                                                    <div class="divider"></div>
                                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-applications&action=delete&id=' . $a->id), 'ptp_app_action'); ?>" class="danger" onclick="return confirm('Permanently delete this application? This cannot be undone.');">
                                                        <span class="dashicons dashicons-trash"></span> Delete
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <div class="ptp-actions-dropdown">
                                            <button class="ptp-actions-btn" onclick="this.parentElement.classList.toggle('open')">
                                                Actions <span class="dashicons dashicons-arrow-down-alt2" style="font-size: 14px;"></span>
                                            </button>
                                            <div class="ptp-actions-menu">
                                                <a href="#" onclick="viewApplication(<?php echo $a->id; ?>); return false;">
                                                    <span class="dashicons dashicons-visibility"></span> View Details
                                                </a>
                                                <div class="divider"></div>
                                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-applications&action=delete&id=' . $a->id), 'ptp_app_action'); ?>" class="danger" onclick="return confirm('Permanently delete this application? This cannot be undone.');">
                                                    <span class="dashicons dashicons-trash"></span> Delete
                                                </a>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="ptp-card">
                <div class="ptp-card-body">
                    <div class="ptp-empty-state">
                        <div class="ptp-empty-state-icon">
                            <span class="dashicons dashicons-portfolio"></span>
                        </div>
                        <h3>No applications found</h3>
                        <p>Applications will appear here when trainers submit their information.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Application Detail Modal -->
        <div class="ptp-modal-overlay" id="applicationModal">
            <div class="ptp-modal">
                <div class="ptp-modal-header">
                    <h2>Application Details</h2>
                    <button class="ptp-modal-close" onclick="closeModal()">&times;</button>
                </div>
                <div class="ptp-modal-body" id="applicationModalBody">
                    Loading...
                </div>
                <div class="ptp-modal-footer" id="applicationModalFooter">
                </div>
            </div>
        </div>
        
        <script>
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.ptp-actions-dropdown')) {
                document.querySelectorAll('.ptp-actions-dropdown.open').forEach(function(el) {
                    el.classList.remove('open');
                });
            }
        });
        
        // Application data for modal
        var applications = <?php echo json_encode(array_map(function($a) use ($levels) {
            return array(
                'id' => $a->id,
                'name' => $a->name,
                'email' => $a->email,
                'phone' => $a->phone,
                'location' => $a->location ?: (trim(($a->city ?? '') . ', ' . ($a->state ?? ''), ', ') ?: ''),
                'playing_level' => isset($levels[$a->playing_level]) ? $levels[$a->playing_level] : $a->playing_level,
                'college' => $a->college,
                'team' => $a->team,
                'specialties' => $a->specialties,
                'instagram' => $a->instagram ?? '',
                'headline' => $a->headline,
                'bio' => $a->bio,
                'hourly_rate' => $a->hourly_rate,
                'travel_radius' => $a->travel_radius,
                'status' => $a->status,
                'created_at' => date('M j, Y g:i A', strtotime($a->created_at)),
                'approve_url' => wp_nonce_url(admin_url('admin.php?page=ptp-applications&action=approve&id=' . $a->id), 'ptp_app_action'),
                'reject_url' => wp_nonce_url(admin_url('admin.php?page=ptp-applications&action=reject&id=' . $a->id), 'ptp_app_action'),
            );
        }, $apps)); ?>;
        
        function viewApplication(id) {
            var app = applications.find(function(a) { return a.id == id; });
            if (!app) return;
            
            document.querySelectorAll('.ptp-actions-dropdown.open').forEach(function(el) {
                el.classList.remove('open');
            });
            
            var html = '<div class="ptp-detail-row"><div class="ptp-detail-label">Name</div><div class="ptp-detail-value"><strong>' + (app.name || 'Not provided') + '</strong></div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Email</div><div class="ptp-detail-value"><a href="mailto:' + app.email + '">' + app.email + '</a></div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Phone</div><div class="ptp-detail-value">' + (app.phone || 'Not provided') + '</div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Location</div><div class="ptp-detail-value">' + (app.location || 'Not provided') + '</div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Playing Level</div><div class="ptp-detail-value">' + (app.playing_level || 'Not specified') + '</div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">College/Team</div><div class="ptp-detail-value">' + (app.college || app.team || 'Not provided') + '</div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Specialties</div><div class="ptp-detail-value">' + (app.specialties || 'None selected') + '</div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Instagram</div><div class="ptp-detail-value">' + (app.instagram ? '@' + app.instagram : 'Not provided') + '</div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Hourly Rate</div><div class="ptp-detail-value"><strong>$' + parseFloat(app.hourly_rate || 0).toFixed(0) + '/hr</strong></div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Travel Radius</div><div class="ptp-detail-value">' + (app.travel_radius || 15) + ' miles</div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Headline</div><div class="ptp-detail-value">' + (app.headline || 'Not provided') + '</div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Bio</div><div class="ptp-detail-value"><div class="ptp-bio-preview">' + (app.bio || 'Not provided') + '</div></div></div>';
            html += '<div class="ptp-detail-row"><div class="ptp-detail-label">Applied</div><div class="ptp-detail-value">' + app.created_at + '</div></div>';
            
            document.getElementById('applicationModalBody').innerHTML = html;
            
            var footer = '';
            if (app.status === 'pending') {
                footer = '<a href="' + app.reject_url + '" class="ptp-btn ptp-btn-outline" onclick="return confirm(\'Reject this application?\');">Reject</a>';
                footer += '<a href="' + app.approve_url + '" class="ptp-btn ptp-btn-success" onclick="return confirm(\'Approve this application? This will create a trainer account.\');">Approve Application</a>';
            } else {
                footer = '<span style="color: #6b7280; font-size: 14px;">Status: ' + app.status.charAt(0).toUpperCase() + app.status.slice(1) + '</span>';
                footer += '<button class="ptp-btn ptp-btn-outline" onclick="closeModal()">Close</button>';
            }
            document.getElementById('applicationModalFooter').innerHTML = footer;
            
            document.getElementById('applicationModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('applicationModal').classList.remove('active');
        }
        
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });
        
        // Close modal on overlay click
        document.getElementById('applicationModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        </script>
        <?php
    }
    
    /**
     * Approve an application and create trainer account
     */
    private function approve_application($app_id) {
        global $wpdb;
        
        ptp_log('PTP: Starting approval for application ID: ' . $app_id);
        
        $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_applications WHERE id = %d", $app_id));
        if (!$app) {
            ptp_log('PTP: Application not found: ' . $app_id);
            return false;
        }
        
        ptp_log('PTP: Application found - Name: ' . $app->name . ', Email: ' . $app->email . ', User ID: ' . ($app->user_id ?: 'NULL'));
        
        // Auto-migrate: ensure trainers table exists and has all required columns
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$trainers_table}'") === $trainers_table;
        
        if (!$table_exists) {
            ptp_log('PTP: Trainers table does not exist! Running create_tables...');
            PTP_Database::create_tables();
            // Re-check
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$trainers_table}'") === $trainers_table;
            if (!$table_exists) {
                ptp_log('PTP: CRITICAL - Failed to create trainers table!');
                return false;
            }
        }
        
        // Get existing columns
        $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM {$trainers_table}", 0);
        ptp_log('PTP: Existing trainer columns: ' . implode(', ', $existing_columns));
        
        // Comprehensive list of all columns that might be missing
        $required_columns = array(
            'email' => "ALTER TABLE {$trainers_table} ADD COLUMN email varchar(255) DEFAULT ''",
            'phone' => "ALTER TABLE {$trainers_table} ADD COLUMN phone varchar(20) DEFAULT ''",
            'headline' => "ALTER TABLE {$trainers_table} ADD COLUMN headline varchar(255) DEFAULT ''",
            'bio' => "ALTER TABLE {$trainers_table} ADD COLUMN bio text",
            'photo_url' => "ALTER TABLE {$trainers_table} ADD COLUMN photo_url varchar(500) DEFAULT ''",
            'hourly_rate' => "ALTER TABLE {$trainers_table} ADD COLUMN hourly_rate decimal(10,2) DEFAULT 0",
            'location' => "ALTER TABLE {$trainers_table} ADD COLUMN location varchar(255) DEFAULT ''",
            'latitude' => "ALTER TABLE {$trainers_table} ADD COLUMN latitude decimal(10,8) DEFAULT NULL",
            'longitude' => "ALTER TABLE {$trainers_table} ADD COLUMN longitude decimal(11,8) DEFAULT NULL",
            'travel_radius' => "ALTER TABLE {$trainers_table} ADD COLUMN travel_radius int(11) DEFAULT 15",
            'college' => "ALTER TABLE {$trainers_table} ADD COLUMN college varchar(255) DEFAULT ''",
            'team' => "ALTER TABLE {$trainers_table} ADD COLUMN team varchar(255) DEFAULT ''",
            'playing_level' => "ALTER TABLE {$trainers_table} ADD COLUMN playing_level varchar(50) DEFAULT ''",
            'position' => "ALTER TABLE {$trainers_table} ADD COLUMN position varchar(100) DEFAULT ''",
            'experience_years' => "ALTER TABLE {$trainers_table} ADD COLUMN experience_years int(11) DEFAULT 0",
            'specialties' => "ALTER TABLE {$trainers_table} ADD COLUMN specialties text",
            'instagram' => "ALTER TABLE {$trainers_table} ADD COLUMN instagram varchar(100) DEFAULT ''",
            'status' => "ALTER TABLE {$trainers_table} ADD COLUMN status varchar(20) DEFAULT 'pending'",
            'is_featured' => "ALTER TABLE {$trainers_table} ADD COLUMN is_featured tinyint(1) DEFAULT 0",
            'is_verified' => "ALTER TABLE {$trainers_table} ADD COLUMN is_verified tinyint(1) DEFAULT 0",
            'is_background_checked' => "ALTER TABLE {$trainers_table} ADD COLUMN is_background_checked tinyint(1) DEFAULT 0",
            'total_sessions' => "ALTER TABLE {$trainers_table} ADD COLUMN total_sessions int(11) DEFAULT 0",
            'total_earnings' => "ALTER TABLE {$trainers_table} ADD COLUMN total_earnings decimal(10,2) DEFAULT 0",
            'average_rating' => "ALTER TABLE {$trainers_table} ADD COLUMN average_rating decimal(3,2) DEFAULT 0",
            'review_count' => "ALTER TABLE {$trainers_table} ADD COLUMN review_count int(11) DEFAULT 0",
            // v187.1: approved_at was missing — caused insert to fail on every approval
            'approved_at' => "ALTER TABLE {$trainers_table} ADD COLUMN approved_at datetime DEFAULT NULL",
        );
        
        foreach ($required_columns as $col => $sql) {
            if (!in_array($col, $existing_columns)) {
                ptp_log('PTP: Adding missing column: ' . $col);
                $result = $wpdb->query($sql);
                if ($result === false) {
                    ptp_log('PTP: Failed to add column ' . $col . ': ' . $wpdb->last_error);
                }
            }
        }
        
        // Determine user_id - check if user already exists
        $user_id = $app->user_id;
        $password = null;
        $has_stored_password = !empty($app->password_hash);
        
        if (!$user_id || $user_id == 0) {
            ptp_log('PTP: No user_id in application, checking for existing user by email');
            // Legacy: user wasn't created during application, create now
            $user = get_user_by('email', $app->email);
            if (!$user) {
                ptp_log('PTP: No existing user found, creating new user');
                
                // Use stored password if available, otherwise generate one
                if ($has_stored_password) {
                    // Create user with a temp password first
                    $temp_pass = wp_generate_password(16, true);
                    $user_id = wp_create_user($app->email, $temp_pass, $app->email);
                    
                    if (!is_wp_error($user_id)) {
                        // Set the password hash directly from application
                        global $wpdb;
                        $wpdb->update(
                            $wpdb->users,
                            array('user_pass' => $app->password_hash),
                            array('ID' => $user_id)
                        );
                        wp_cache_delete($user_id, 'users');
                        ptp_log('PTP: Created user with password they set during application');
                    }
                } else {
                    // Generate password for new user (legacy applications)
                    $password = wp_generate_password(12, false);
                    $user_id = wp_create_user($app->email, $password, $app->email);
                }
                
                if (is_wp_error($user_id)) {
                    ptp_log('PTP: Failed to create user for application ' . $app_id . ': ' . $user_id->get_error_message());
                    return false;
                }
                
                ptp_log('PTP: Created new user with ID: ' . $user_id);
                
                // Update user meta
                wp_update_user(array(
                    'ID' => $user_id,
                    'first_name' => explode(' ', $app->name)[0],
                    'last_name' => implode(' ', array_slice(explode(' ', $app->name), 1)),
                    'display_name' => $app->name,
                ));
                
                // Add trainer role
                $user = get_user_by('ID', $user_id);
                if ($user) {
                    $user->add_role('ptp_trainer');
                    ptp_log('PTP: Added ptp_trainer role to new user');
                }
            } else {
                $user_id = $user->ID;
                ptp_log('PTP: Found existing user with ID: ' . $user_id);
                // Existing user - update their password to stored one or generate new
                if ($has_stored_password) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->users,
                        array('user_pass' => $app->password_hash),
                        array('ID' => $user_id)
                    );
                    wp_cache_delete($user_id, 'users');
                    ptp_log('PTP: Updated existing user with password from application');
                } else {
                    $password = wp_generate_password(12, false);
                    wp_set_password($password, $user_id);
                    ptp_log('PTP: Reset password for existing user (legacy)');
                }
            }
        } else {
            ptp_log('PTP: Application has user_id: ' . $user_id);
            // Use stored password if available
            if ($has_stored_password) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->users,
                    array('user_pass' => $app->password_hash),
                    array('ID' => $user_id)
                );
                wp_cache_delete($user_id, 'users');
                ptp_log('PTP: Set user password from application');
            } else {
                // Legacy - generate fresh password
                $password = wp_generate_password(12, false);
                wp_set_password($password, $user_id);
                ptp_log('PTP: Set fresh password for user ' . $user_id . ' (legacy)');
            }
        }
        
        // Verify user exists and add trainer role
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            ptp_log('PTP: CRITICAL - User ID ' . $user_id . ' does not exist! Creating new user.');
            // User was deleted, create a new one
            $password = wp_generate_password(12, false);
            $user_id = wp_create_user($app->email, $password, $app->email);
            
            if (is_wp_error($user_id)) {
                ptp_log('PTP: Failed to create replacement user: ' . $user_id->get_error_message());
                return false;
            }
            
            wp_update_user(array(
                'ID' => $user_id,
                'first_name' => explode(' ', $app->name)[0],
                'last_name' => implode(' ', array_slice(explode(' ', $app->name), 1)),
                'display_name' => $app->name,
            ));
            
            $user = get_user_by('ID', $user_id);
            ptp_log('PTP: Created replacement user with ID: ' . $user_id);
        }
        
        // Ensure user has trainer role
        if ($user && !in_array('ptp_trainer', (array) $user->roles)) {
            $user->add_role('ptp_trainer');
            ptp_log('PTP: Added ptp_trainer role to user ' . $user_id);
        }
        
        // Check if trainer record already exists for this user
        $existing_trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if ($existing_trainer) {
            // Trainer already exists - update status to active and migrate ALL app data
            $update_data = array(
                'status' => 'active',
                'approved_at' => current_time('mysql'),
                'email' => $app->email,
            );
            
            // v135: Migrate ALL non-empty application fields to trainer record
            // Only overwrite if the app field has data and trainer field is empty
            $existing_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $existing_trainer->id
            ));
            $current_columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_trainers", 0);
            
            $app_to_trainer_map = array(
                'phone'            => $app->phone ?? '',
                'location'         => $app->location ?? (trim(($app->city ?? '') . ', ' . ($app->state ?? ''), ', ') ?: ''),
                'city'             => $app->city ?? '',
                'state'            => $app->state ?? '',
                'college'          => $app->college ?? '',
                'team'             => $app->team ?? '',
                'playing_level'    => $app->playing_level ?? '',
                'specialties'      => $app->specialties ?? '',
                'instagram'        => $app->instagram ?? '',
                'headline'         => $app->headline ?? '',
                'bio'              => $app->bio ?? '',
                'coaching_why'     => $app->why_train ?? '',
                'experience_years' => intval($app->experience_years ?? 0),
                'hourly_rate'      => floatval($app->hourly_rate ?? 0),
                'travel_radius'    => intval($app->travel_radius ?? 15),
            );
            
            foreach ($app_to_trainer_map as $field => $value) {
                if (!in_array($field, $current_columns)) continue;
                if (empty($value) && $value !== 0) continue;
                // Only overwrite if trainer field is empty/default
                $existing_val = $existing_data->$field ?? '';
                if (empty($existing_val) || $existing_val === '0' || $existing_val === '0.00') {
                    $update_data[$field] = $value;
                }
            }
            
            // Update display_name if it's still the default
            if (empty($existing_data->display_name) || $existing_data->display_name === 'Trainer') {
                $update_data['display_name'] = $app->name;
            }
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                $update_data,
                array('id' => $existing_trainer->id)
            );
            ptp_log('PTP: Trainer record already exists (ID: ' . $existing_trainer->id . ') for user ' . $user_id . ', updated status + migrated ' . count($update_data) . ' fields');
            
            // v135: Sync WP user profile with trainer data
            $this->sync_wp_user_from_trainer($user_id, $app);
            
            // Fire action for trainer referrals, email, SMS and other integrations
            // Note: ptp_trainer_approved hook triggers both Email::send_trainer_approval_email and SMS::send_trainer_welcome
            do_action('ptp_trainer_approved', $existing_trainer->id);
            
            // Update application status
            $wpdb->update(
                $wpdb->prefix . 'ptp_applications',
                array('status' => 'approved', 'reviewed_at' => current_time('mysql'), 'reviewed_by' => get_current_user_id()),
                array('id' => $app_id)
            );
            
            // v178: Schedule compliance emails (SafeSport & W9) — approval email is sent by the hook above
            if (class_exists('PTP_Email')) {
                PTP_Email::schedule_compliance_emails($existing_trainer->id, $app->email, $app->name);
            }
            
            return true;
        }
        
        // Generate unique slug
        $base_slug = sanitize_title($app->name);
        if (empty($base_slug)) {
            $base_slug = 'trainer';
        }
        $slug = $base_slug . '-' . $user_id;
        
        // Make sure slug is unique
        $slug_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s",
            $slug
        ));
        if ($slug_exists) {
            $slug = $base_slug . '-' . $user_id . '-' . time();
        }
        
        ptp_log('PTP: Creating trainer with slug: ' . $slug);
        
        // Create trainer profile - only include columns that exist
        $trainer_data = array(
            'user_id' => $user_id,
            'display_name' => $app->name ?: 'Trainer',
            'slug' => $slug,
            'status' => 'active',
            'approved_at' => current_time('mysql'),
        );
        
        // Add optional fields only if they have values (prevents issues with missing columns)
        $optional_fields = array(
            'email' => $app->email ?: '',
            'phone' => $app->phone ?: '',
            'location' => $app->location ?: (trim(($app->city ?? '') . ', ' . ($app->state ?? ''), ', ') ?: ''),
            'city' => $app->city ?: '',
            'state' => $app->state ?: '',
            'college' => $app->college ?: '',
            'team' => $app->team ?: '',
            'playing_level' => $app->playing_level ?: '',
            'specialties' => $app->specialties ?: '',
            'instagram' => isset($app->instagram) ? ($app->instagram ?: '') : '',
            'headline' => isset($app->headline) ? ($app->headline ?: '') : '',
            'bio' => $app->bio ?: '',
            'coaching_why' => isset($app->why_train) ? ($app->why_train ?: '') : '',
            'experience_years' => intval($app->experience_years ?? 0),
            'hourly_rate' => floatval($app->hourly_rate) ?: 0,
            'travel_radius' => intval($app->travel_radius) ?: 15,
        );
        
        // Re-fetch columns after migration
        $current_columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_trainers", 0);
        
        foreach ($optional_fields as $field => $value) {
            if (in_array($field, $current_columns)) {
                $trainer_data[$field] = $value;
            } else {
                ptp_log('PTP: Skipping field ' . $field . ' - column does not exist');
            }
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log('PTP: Creating trainer record for user ' . $user_id);
        }
        
        $insert_result = $wpdb->insert($wpdb->prefix . 'ptp_trainers', $trainer_data);
        
        if ($insert_result === false) {
            ptp_log('PTP: FAILED to create trainer record for user ' . $user_id);
            ptp_log('PTP: Database error: ' . $wpdb->last_error);
            ptp_log('PTP: Last query: ' . $wpdb->last_query);
            return false;
        }
        
        $trainer_id = $wpdb->insert_id;
        ptp_log('PTP: Successfully created trainer record ID ' . $trainer_id . ' for user ' . $user_id);
        
        // v135: Sync WP user profile (display_name, phone, role cleanup)
        $this->sync_wp_user_from_trainer($user_id, $app);
        
        // Fire action for trainer referrals and other integrations
        do_action('ptp_trainer_approved', $trainer_id);
        
        // Update application status
        $wpdb->update(
            $wpdb->prefix . 'ptp_applications',
            array(
                'status' => 'approved', 
                'reviewed_at' => current_time('mysql'), 
                'reviewed_by' => get_current_user_id(),
                'user_id' => $user_id  // Update with correct user_id in case it changed
            ),
            array('id' => $app_id)
        );
        
        // Send approval email
        if (class_exists('PTP_Email')) {
            // v178: The ptp_trainer_approved hook above already triggers send_trainer_approval_email
            // Only send the password-specific email if there IS a generated password (legacy applications)
            if ($password) {
                PTP_Email::send_application_approved($app->email, $app->name, $password);
            }
            
            // Schedule compliance email sequence (SafeSport and W9)
            PTP_Email::schedule_compliance_emails($trainer_id, $app->email, $app->name);
        }
        
        ptp_log('PTP: Application ' . $app_id . ' approved successfully');
        return true;
    }
    
    /**
     * v135: Sync WP user profile with trainer/application data
     * Ensures display_name, first/last name, phone, and roles are correct
     */
    private function sync_wp_user_from_trainer($user_id, $app) {
        if (!$user_id) return;
        
        $user = get_user_by('ID', $user_id);
        if (!$user) return;
        
        $parts = explode(' ', trim($app->name), 2);
        $first_name = $parts[0] ?? '';
        $last_name = $parts[1] ?? '';
        
        // Update WP user profile with application data
        $user_update = array(
            'ID' => $user_id,
            'display_name' => $app->name,
            'first_name' => $first_name,
            'last_name' => $last_name,
        );
        
        // Only update email if it's different and not taken
        if ($app->email && $app->email !== $user->user_email) {
            $email_exists = get_user_by('email', $app->email);
            if (!$email_exists || $email_exists->ID == $user_id) {
                $user_update['user_email'] = $app->email;
            }
        }
        
        wp_update_user($user_update);
        
        // Save phone to user meta (used by WooCommerce, SMS, etc.)
        if (!empty($app->phone)) {
            update_user_meta($user_id, 'billing_phone', $app->phone);
            update_user_meta($user_id, 'ptp_phone', $app->phone);
        }
        
        // Ensure trainer role is set and clean up subscriber
        $user = new \WP_User($user_id); // Refresh
        if (!in_array('ptp_trainer', (array) $user->roles)) {
            $user->add_role('ptp_trainer');
        }
        // Remove subscriber role if trainer — subscriber is the default WP role
        if (in_array('subscriber', (array) $user->roles) && in_array('ptp_trainer', (array) $user->roles)) {
            $user->remove_role('subscriber');
        }
        
        ptp_log('[PTP v135] Synced WP user #' . $user_id . ': ' . $app->name . ' / ' . $app->email . ' / phone=' . ($app->phone ?: 'none'));
    }
    
    /* =========================================================================
       BOOKINGS PAGE
       ========================================================================= */
    public function bookings_page() {
        global $wpdb;
        
        $success_message = '';
        $error_message = '';
        
        // Handle booking actions
        if (isset($_GET['action']) && isset($_GET['id']) && wp_verify_nonce($_GET['_wpnonce'], 'ptp_booking_action')) {
            $booking_id = intval($_GET['id']);
            $action = sanitize_text_field($_GET['action']);
            
            switch ($action) {
                case 'complete':
                    $wpdb->update($wpdb->prefix . 'ptp_bookings', array('status' => 'completed', 'completed_at' => current_time('mysql')), array('id' => $booking_id), array('%s', '%s'), array('%d'));
                    $success_message = 'Booking marked as completed.';
                    break;
                case 'cancel':
                    $wpdb->update($wpdb->prefix . 'ptp_bookings', array('status' => 'cancelled', 'cancelled_at' => current_time('mysql')), array('id' => $booking_id), array('%s', '%s'), array('%d'));
                    $success_message = 'Booking cancelled.';
                    break;
                case 'delete':
                    $wpdb->delete($wpdb->prefix . 'ptp_bookings', array('id' => $booking_id), array('%d'));
                    $success_message = 'Booking deleted permanently.';
                    break;
            }
        }
        
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        
        $bookings = array();
        $counts = array();
        $total = 0;
        
        $table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") == $table) {
            $where = $status ? $wpdb->prepare("WHERE b.status = %s", $status) : "";
            $bookings = $wpdb->get_results("
                SELECT b.*, t.display_name as trainer_name, p.display_name as parent_name
                FROM {$wpdb->prefix}ptp_bookings b
                LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
                {$where}
                ORDER BY b.session_date DESC, b.start_time DESC
            ");
            $counts = $wpdb->get_results("SELECT status, COUNT(*) as count FROM {$wpdb->prefix}ptp_bookings GROUP BY status", OBJECT_K);
            $total = array_sum(array_column((array)$counts, 'count'));
        }
        
        
        ?>
        <div class="ptp-admin-wrap">
            <?php if ($success_message): ?>
            <div class="notice notice-success is-dismissible" style="margin: 10px 0;"><p>✅ <?php echo esc_html($success_message); ?></p></div>
            <?php endif; ?>
            
            <div class="ptp-admin-header">
                <div class="ptp-admin-header-content">
                    <div class="ptp-admin-logo">
                        <span class="dashicons dashicons-universal-access"></span>
                    </div>
                    <div class="ptp-admin-title-wrap">
                        <h1 class="ptp-admin-title">PTP <span>Training</span></h1>
                        <p class="ptp-admin-subtitle">Manage training sessions</p>
                    </div>
                </div>
                <div class="ptp-admin-actions">
                    <a href="<?php echo admin_url('admin.php?page=ptp-schedule'); ?>" class="ptp-btn ptp-btn-primary">
                        <span class="dashicons dashicons-plus-alt2"></span> Create Session
                    </a>
                </div>
            </div>
            
            <?php $this->render_nav('bookings'); ?>
            
            <div class="ptp-filter-tabs">
                <a href="?page=ptp-bookings" class="ptp-filter-tab <?php echo !$status ? 'active' : ''; ?>">
                    All <span class="count"><?php echo $total; ?></span>
                </a>
                <a href="?page=ptp-bookings&status=confirmed" class="ptp-filter-tab <?php echo $status === 'confirmed' ? 'active' : ''; ?>">
                    Confirmed <span class="count"><?php echo isset($counts['confirmed']) ? $counts['confirmed']->count : 0; ?></span>
                </a>
                <a href="?page=ptp-bookings&status=completed" class="ptp-filter-tab <?php echo $status === 'completed' ? 'active' : ''; ?>">
                    Completed <span class="count"><?php echo isset($counts['completed']) ? $counts['completed']->count : 0; ?></span>
                </a>
                <a href="?page=ptp-bookings&status=cancelled" class="ptp-filter-tab <?php echo $status === 'cancelled' ? 'active' : ''; ?>">
                    Cancelled <span class="count"><?php echo isset($counts['cancelled']) ? $counts['cancelled']->count : 0; ?></span>
                </a>
            </div>
            
            <div class="ptp-card">
                <div class="ptp-card-body no-padding">
                    <?php if ($bookings): ?>
                    <div class="ptp-table-wrap">
                        <table class="ptp-table">
                            <thead>
                                <tr>
                                    <th>Booking #</th>
                                    <th>Trainer</th>
                                    <th>Parent</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $b): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($b->booking_number); ?></strong></td>
                                    <td><?php echo esc_html($b->trainer_name ?: '-'); ?></td>
                                    <td><?php echo esc_html($b->parent_name ?: '-'); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($b->session_date)); ?><br><small style="color:#6B7280;"><?php echo date('g:i A', strtotime($b->start_time)); ?></small></td>
                                    <td><span class="ptp-status ptp-status-<?php echo esc_attr($b->status); ?>"><?php echo ucfirst($b->status); ?></span></td>
                                    <td class="ptp-table-amount positive">$<?php echo number_format($b->total_amount, 2); ?></td>
                                    <td>
                                        <div style="display: flex; gap: 6px;">
                                            <button type="button" class="button button-small ptp-edit-booking" data-id="<?php echo $b->id; ?>" title="Edit">✏️</button>
                                            <?php if ($b->status === 'confirmed'): ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-bookings&action=complete&id=' . $b->id), 'ptp_booking_action'); ?>" class="button button-small" title="Mark Complete">✓</a>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-bookings&action=cancel&id=' . $b->id), 'ptp_booking_action'); ?>" class="button button-small" title="Cancel" onclick="return confirm('Cancel this booking?');">✕</a>
                                            <?php endif; ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-bookings&action=delete&id=' . $b->id), 'ptp_booking_action'); ?>" class="button button-small" style="color:#DC2626;" title="Delete" onclick="return confirm('Permanently delete this booking?');">🗑</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="ptp-empty-state">
                        <div class="ptp-empty-state-icon">
                            <span class="dashicons dashicons-calendar-alt"></span>
                        </div>
                        <h3>No bookings yet</h3>
                        <p>Bookings will appear here as parents schedule training sessions.</p>
                        <a href="<?php echo admin_url('admin.php?page=ptp-schedule'); ?>" class="button button-primary">Create First Session</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Edit Booking Modal -->
            <div id="edit-booking-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100000;align-items:center;justify-content:center;">
                <div style="background:#fff;border-radius:16px;max-width:600px;width:90%;max-height:90vh;overflow-y:auto;padding:32px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
                        <h2 style="margin:0;font-size:20px;">Edit Booking</h2>
                        <button type="button" onclick="document.getElementById('edit-booking-modal').style.display='none';" style="background:none;border:none;font-size:24px;cursor:pointer;">&times;</button>
                    </div>
                    <form id="edit-booking-form">
                        <input type="hidden" name="booking_id" id="booking-id">
                        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('ptp_admin_nonce'); ?>">
                        
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;">Session Date</label>
                                <input type="date" name="session_date" id="booking-session_date" class="regular-text" style="width:100%;">
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;">Status</label>
                                <select name="status" id="booking-status" style="width:100%;">
                                    <option value="pending">Pending</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;">Start Time</label>
                                <input type="time" name="start_time" id="booking-start_time" class="regular-text" style="width:100%;">
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;">End Time</label>
                                <input type="time" name="end_time" id="booking-end_time" class="regular-text" style="width:100%;">
                            </div>
                        </div>
                        
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-weight:600;margin-bottom:4px;">Location</label>
                            <input type="text" name="location" id="booking-location" class="regular-text" style="width:100%;">
                        </div>
                        
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;">Hourly Rate ($)</label>
                                <input type="number" name="hourly_rate" id="booking-hourly_rate" class="regular-text" style="width:100%;" step="0.01">
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;">Total Amount</label>
                                <div id="booking-total-display" style="padding:8px 12px;background:#F3F4F6;border-radius:6px;font-weight:700;">$0.00</div>
                            </div>
                        </div>
                        
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-weight:600;margin-bottom:4px;">Notes</label>
                            <textarea name="notes" id="booking-notes" rows="3" style="width:100%;"></textarea>
                        </div>
                        
                        <div style="display:flex;gap:12px;justify-content:flex-end;">
                            <button type="button" onclick="document.getElementById('edit-booking-modal').style.display='none';" class="button">Cancel</button>
                            <button type="submit" class="button button-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // Edit booking
                $('.ptp-edit-booking').click(function() {
                    var bookingId = $(this).data('id');
                    $.post(ajaxurl, {
                        action: 'ptp_admin_get_booking',
                        booking_id: bookingId,
                        nonce: '<?php echo wp_create_nonce('ptp_admin_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            var b = response.data;
                            $('#booking-id').val(b.id);
                            $('#booking-session_date').val(b.session_date);
                            $('#booking-start_time').val(b.start_time);
                            $('#booking-end_time').val(b.end_time);
                            $('#booking-location').val(b.location);
                            $('#booking-hourly_rate').val(b.hourly_rate);
                            $('#booking-status').val(b.status);
                            $('#booking-notes').val(b.notes);
                            $('#booking-total-display').text('$' + parseFloat(b.total_amount).toFixed(2));
                            $('#edit-booking-modal').css('display', 'flex');
                        }
                    });
                });
                
                // Save booking
                $('#edit-booking-form').submit(function(e) {
                    e.preventDefault();
                    var formData = $(this).serialize();
                    formData += '&action=ptp_admin_update_booking';
                    
                    $.post(ajaxurl, formData, function(response) {
                        if (response.success) {
                            alert('Booking updated!');
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    });
                });
                
                // Calculate total on time/rate change
                $('#booking-start_time, #booking-end_time, #booking-hourly_rate').change(function() {
                    var start = $('#booking-start_time').val();
                    var end = $('#booking-end_time').val();
                    var rate = parseFloat($('#booking-hourly_rate').val()) || 0;
                    if (start && end) {
                        var startMin = parseInt(start.split(':')[0]) * 60 + parseInt(start.split(':')[1]);
                        var endMin = parseInt(end.split(':')[0]) * 60 + parseInt(end.split(':')[1]);
                        var duration = (endMin - startMin) / 60;
                        var total = duration * rate;
                        $('#booking-total-display').text('$' + total.toFixed(2));
                    }
                });
            });
            </script>
        </div>
        <?php
    }
    
    /* =========================================================================
       PARENTS PAGE
       ========================================================================= */
    public function parents_page() {
        global $wpdb;
        
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : '';
        $sort = isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'ltv';
        $parents = array();
        $stats = (object) ['total_parents' => 0, 'vip_count' => 0, 'active_count' => 0, 'engaged_count' => 0, 'new_count' => 0, 'avg_ltv' => 0, 'total_ltv' => 0];
        
        $table = $wpdb->prefix . 'ptp_parents';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") == $table) {
            $where = "WHERE 1=1";
            if ($search) {
                $where .= $wpdb->prepare(" AND (p.display_name LIKE %s OR u.user_email LIKE %s OR p.phone LIKE %s)", 
                    '%' . $wpdb->esc_like($search) . '%', 
                    '%' . $wpdb->esc_like($search) . '%',
                    '%' . $wpdb->esc_like($search) . '%'
                );
            }
            
            // LTV/Activity filters
            $having = "";
            switch ($filter) {
                case 'vip': $having = "HAVING lifetime_value >= 1000"; break;
                case 'active': $having = "HAVING lifetime_value >= 500 AND lifetime_value < 1000"; break;
                case 'engaged': $having = "HAVING lifetime_value >= 200 AND lifetime_value < 500"; break;
                case 'new': $having = "HAVING lifetime_value < 200"; break;
                case 'dormant': $having = "HAVING last_booking < DATE_SUB(NOW(), INTERVAL 60 DAY) OR last_booking IS NULL"; break;
                case 'multi_player': $having = "HAVING player_count > 1"; break;
                case 'camp_buyer': $having = "HAVING camp_orders > 0"; break;
            }
            
            // Sort options
            $order = "ORDER BY ";
            switch ($sort) {
                case 'ltv': $order .= "lifetime_value DESC"; break;
                case 'recent': $order .= "last_booking DESC"; break;
                case 'bookings': $order .= "total_bookings DESC"; break;
                case 'players': $order .= "player_count DESC"; break;
                case 'created': $order .= "p.created_at DESC"; break;
                default: $order .= "lifetime_value DESC";
            }
            
            // Check which optional tables exist
            $has_referral_codes = ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_referral_codes'") !== null);
            $has_referral_uses = ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_referral_uses'") !== null);
            
            $referral_code_sql = $has_referral_codes 
                ? "(SELECT code FROM {$wpdb->prefix}ptp_referral_codes WHERE user_id = p.user_id LIMIT 1)" 
                : "NULL";
            $referral_count_sql = $has_referral_uses 
                ? "(SELECT COUNT(*) FROM {$wpdb->prefix}ptp_referral_uses WHERE referrer_id = p.user_id)" 
                : "0";
            
            // Camp orders — check if WooCommerce is active
            $camp_orders_sql = "0";
            if (class_exists('WooCommerce') || $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'") !== null) {
                $camp_orders_sql = "(SELECT COUNT(DISTINCT order_id) FROM {$wpdb->postmeta} pm 
                    JOIN {$wpdb->posts} po ON pm.post_id = po.ID
                    WHERE pm.meta_key = '_billing_email' AND pm.meta_value = u.user_email AND po.post_type = 'shop_order')";
            }
            
            $parents = $wpdb->get_results("
                SELECT p.*, u.user_email,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_players WHERE parent_id = p.id) as player_count,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = p.id) as total_bookings,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = p.id AND status = 'completed') as completed_bookings,
                       (SELECT COALESCE(SUM(total_amount), 0) FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = p.id AND status IN ('confirmed', 'completed')) as lifetime_value,
                       (SELECT MAX(session_date) FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = p.id) as last_booking,
                       (SELECT MIN(created_at) FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = p.id) as first_booking,
                       {$camp_orders_sql} as camp_orders,
                       {$referral_code_sql} as referral_code,
                       {$referral_count_sql} as referral_count
                FROM {$wpdb->prefix}ptp_parents p 
                LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID 
                {$where}
                GROUP BY p.id
                {$having}
                {$order}
                LIMIT 100
            ");
            
            // Get aggregate stats
            $stats = $wpdb->get_row("
                SELECT 
                    COUNT(*) as total_parents,
                    SUM(CASE WHEN ltv >= 1000 THEN 1 ELSE 0 END) as vip_count,
                    SUM(CASE WHEN ltv >= 500 AND ltv < 1000 THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN ltv >= 200 AND ltv < 500 THEN 1 ELSE 0 END) as engaged_count,
                    SUM(CASE WHEN ltv < 200 THEN 1 ELSE 0 END) as new_count,
                    ROUND(AVG(ltv), 0) as avg_ltv,
                    ROUND(SUM(ltv), 0) as total_ltv
                FROM (
                    SELECT p.id, COALESCE(SUM(b.total_amount), 0) as ltv
                    FROM {$wpdb->prefix}ptp_parents p
                    LEFT JOIN {$wpdb->prefix}ptp_bookings b ON b.parent_id = p.id AND b.status IN ('confirmed', 'completed')
                    GROUP BY p.id
                ) sub
            ");
        }
        
        
        // Render standalone nav (template has its own header)
        $this->render_standalone_nav('parents');
        
        // Include template
        include PTP_PLUGIN_DIR . 'templates/admin/parents.php';
    }

    /* =========================================================================
       PAYMENTS PAGE WRAPPER - Nav + V3 delegate
       ========================================================================= */
    public function payments_page_wrapper() {
        // Render standalone nav (V3 payouts has its own header)
        $this->render_standalone_nav('payments');
        PTP_Admin_Payouts_V3::render_page();
    }

    /* =========================================================================
       PAYOUTS PAGE (legacy redirect)
       ========================================================================= */
    public function payouts_page() {
        // Redirect to new unified Payments page
        wp_redirect(admin_url('admin.php?page=ptp-payments'));
        exit;
    }
    
    /* =========================================================================
       QUALITY CONTROL PAGE
       ========================================================================= */
    public function quality_page() {
        global $wpdb;
        
        // Handle AJAX-like form submissions
        if (isset($_POST['action']) && $_POST['action'] === 'update_trainer_ranking') {
            check_admin_referer('ptp_trainer_ranking');
            $trainer_id = intval($_POST['trainer_id']);
            $updates = array();
            
            if (isset($_POST['is_supercoach'])) {
                $updates['is_supercoach'] = intval($_POST['is_supercoach']);
            }
            if (isset($_POST['is_featured'])) {
                $updates['is_featured'] = intval($_POST['is_featured']);
            }
            if (isset($_POST['sort_order'])) {
                $updates['sort_order'] = intval($_POST['sort_order']);
            }
            if (isset($_POST['boost_percent'])) {
                update_post_meta($trainer_id, '_ptp_boost_percent', intval($_POST['boost_percent']));
            }
            
            if (!empty($updates)) {
                $wpdb->update($wpdb->prefix . 'ptp_trainers', $updates, array('id' => $trainer_id));
            }
            
            echo '<div class="notice notice-success is-dismissible"><p>Trainer ranking updated!</p></div>';
        }
        
        // Get all active trainers with metrics
        $trainers = $wpdb->get_results("
            SELECT t.*,
                   COALESCE(t.average_rating, 5.0) as avg_rating,
                   COALESCE(t.review_count, 0) as review_count,
                   COALESCE(t.total_sessions, 0) as total_sessions,
                   (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = t.id AND status = 'completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as sessions_30d,
                   (SELECT COALESCE(SUM(total_amount), 0) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = t.id AND status IN ('confirmed', 'completed') AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as revenue_30d,
                   (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_quality_flags WHERE trainer_id = t.id AND resolved = 0) as active_flags,
                   (SELECT AVG(rating) FROM {$wpdb->prefix}ptp_reviews WHERE trainer_id = t.id AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) as recent_rating
            FROM {$wpdb->prefix}ptp_trainers t
            WHERE t.status = 'active'
            ORDER BY t.is_supercoach DESC, t.is_featured DESC, t.sort_order ASC, t.average_rating DESC
        ");
        
        // Stats
        $supercoach_count = 0;
        $featured_count = 0;
        $total_revenue_30d = 0;
        foreach ($trainers as $t) {
            if ($t->is_supercoach) $supercoach_count++;
            if ($t->is_featured) $featured_count++;
            $total_revenue_30d += $t->revenue_30d;
        }
        
        ?>
        <div class="ptp-admin-wrap">
            <div class="ptp-admin-header">
                <div class="ptp-admin-header-content">
                    <div class="ptp-admin-logo">
                        <span class="dashicons dashicons-universal-access"></span>
                    </div>
                    <div class="ptp-admin-title-wrap">
                        <h1 class="ptp-admin-title">PTP <span>Training</span></h1>
                        <p class="ptp-admin-subtitle">Trainer Quality &amp; Demand Steering</p>
                    </div>
                </div>
            </div>
            
            <?php $this->render_nav('quality'); ?>
            
            <!-- Quick Actions Bar -->
            <div style="display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap;">
                <a href="?page=ptp-trainer-ranking" class="ptp-btn ptp-btn-secondary" style="display:inline-flex;align-items:center;gap:8px;">
                    <span class="dashicons dashicons-sort"></span> Drag &amp; Drop Ranking
                </a>
                <a href="?page=ptp-trainers" class="ptp-btn ptp-btn-secondary" style="display:inline-flex;align-items:center;gap:8px;">
                    <span class="dashicons dashicons-admin-users"></span> All Trainers
                </a>
            </div>
            
            <!-- Stats Row -->
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px;">
                <div style="background:linear-gradient(135deg,#7C3AED 0%,#A78BFA 100%);padding:24px;text-align:center;border-radius:12px;color:#fff;">
                    <div style="font-family:Oswald,sans-serif;font-size:42px;font-weight:700;"><?php echo $supercoach_count; ?></div>
                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;opacity:0.9;margin-top:4px;">🏆 Super Coaches</div>
                </div>
                <div style="background:linear-gradient(135deg,#F59E0B 0%,#FBBF24 100%);padding:24px;text-align:center;border-radius:12px;color:#0A0A0A;">
                    <div style="font-family:Oswald,sans-serif;font-size:42px;font-weight:700;"><?php echo $featured_count; ?></div>
                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;opacity:0.8;margin-top:4px;">⭐ Featured</div>
                </div>
                <div style="background:#fff;border:2px solid #E5E5E5;padding:24px;text-align:center;border-radius:12px;">
                    <div style="font-family:Oswald,sans-serif;font-size:42px;font-weight:700;color:#059669;"><?php echo count($trainers); ?></div>
                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#6B7280;margin-top:4px;">Active Trainers</div>
                </div>
                <div style="background:#fff;border:2px solid #E5E5E5;padding:24px;text-align:center;border-radius:12px;">
                    <div style="font-family:Oswald,sans-serif;font-size:42px;font-weight:700;color:#059669;">$<?php echo number_format($total_revenue_30d / 1000, 1); ?>k</div>
                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#6B7280;margin-top:4px;">30-Day Revenue</div>
                </div>
                <div style="background:#fff;border:2px solid #E5E5E5;padding:24px;text-align:center;border-radius:12px;">
                    <?php 
                    $avg_rating = $wpdb->get_var("SELECT AVG(average_rating) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active' AND average_rating > 0");
                    ?>
                    <div style="font-family:Oswald,sans-serif;font-size:42px;font-weight:700;color:#F59E0B;"><?php echo number_format($avg_rating ?: 5, 1); ?></div>
                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#6B7280;margin-top:4px;">⭐ Avg Rating</div>
                </div>
            </div>
            
            <!-- Super Coach & Featured Management -->
            <div class="ptp-card" style="margin-bottom:24px;">
                <div class="ptp-card-header" style="background:#0A0A0A;color:#fff;padding:16px 20px;">
                    <h3 style="margin:0;font-family:Oswald,sans-serif;text-transform:uppercase;letter-spacing:1px;">🏆 Super Coach &amp; Demand Steering</h3>
                </div>
                <div class="ptp-card-body" style="padding:0;">
                    <div style="padding:16px 20px;background:#F9FAFB;border-bottom:1px solid #E5E5E5;">
                        <p style="margin:0;font-size:13px;color:#4B5563;">
                            <strong>Super Coach</strong> = Top-tier designation. Shown first everywhere, special badge on profile.<br>
                            <strong>Featured</strong> = Priority placement in search results and trainer grid.<br>
                            <strong>Sort Order</strong> = Lower number = higher in list (1 = first, 99 = last).
                        </p>
                    </div>
                    <table class="ptp-table" style="margin:0;">
                        <thead>
                            <tr>
                                <th style="width:40px;">Rank</th>
                                <th>Trainer</th>
                                <th style="width:100px;">Rating</th>
                                <th style="width:100px;">30d Sessions</th>
                                <th style="width:100px;">30d Revenue</th>
                                <th style="width:80px;">Flags</th>
                                <th style="width:100px;">Super Coach</th>
                                <th style="width:100px;">Featured</th>
                                <th style="width:80px;">Order</th>
                                <th style="width:100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach ($trainers as $t): 
                                $photo = $t->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($t->display_name) . '&size=80&background=FCB900&color=0A0A0A&bold=true';
                                $rating_color = $t->avg_rating >= 4.5 ? '#059669' : ($t->avg_rating >= 4.0 ? '#F59E0B' : '#DC2626');
                            ?>
                            <tr style="<?php echo $t->is_supercoach ? 'background:linear-gradient(90deg,#EDE9FE 0%,#fff 100%);' : ($t->is_featured ? 'background:#FFFBEB;' : ''); ?>">
                                <td style="text-align:center;">
                                    <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:<?php echo $rank <= 3 ? '#FCB900' : '#E5E5E5'; ?>;color:<?php echo $rank <= 3 ? '#0A0A0A' : '#6B7280'; ?>;border-radius:50%;font-weight:700;font-size:12px;">
                                        <?php echo $rank++; ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:12px;">
                                        <img src="<?php echo esc_url($photo); ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid <?php echo $t->is_supercoach ? '#7C3AED' : ($t->is_featured ? '#FCB900' : '#E5E5E5'); ?>;">
                                        <div>
                                            <div style="font-weight:600;display:flex;align-items:center;gap:6px;">
                                                <?php echo esc_html($t->display_name); ?>
                                                <?php if ($t->is_supercoach): ?>
                                                <span title="Super Coach" style="font-size:14px;">🏆</span>
                                                <?php endif; ?>
                                                <?php if ($t->is_featured): ?>
                                                <span title="Featured" style="font-size:14px;">⭐</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size:12px;color:#6B7280;"><?php echo esc_html($t->location ?: 'Philadelphia Area'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:4px;">
                                        <span style="color:<?php echo $rating_color; ?>;font-weight:700;">★ <?php echo number_format($t->avg_rating, 1); ?></span>
                                        <span style="color:#9CA3AF;font-size:11px;">(<?php echo $t->review_count; ?>)</span>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <strong><?php echo $t->sessions_30d; ?></strong>
                                </td>
                                <td>
                                    <span style="color:#059669;font-weight:600;">$<?php echo number_format($t->revenue_30d, 0); ?></span>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($t->active_flags > 0): ?>
                                    <span style="display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;background:#FEE2E2;color:#DC2626;border-radius:50%;font-size:12px;font-weight:700;">
                                        <?php echo $t->active_flags; ?>
                                    </span>
                                    <?php else: ?>
                                    <span style="color:#059669;">✓</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Update Super Coach status?');">
                                        <?php wp_nonce_field('ptp_trainer_ranking'); ?>
                                        <input type="hidden" name="action" value="update_trainer_ranking">
                                        <input type="hidden" name="trainer_id" value="<?php echo $t->id; ?>">
                                        <input type="hidden" name="is_supercoach" value="<?php echo $t->is_supercoach ? '0' : '1'; ?>">
                                        <button type="submit" style="background:none;border:none;cursor:pointer;font-size:24px;padding:4px;" title="<?php echo $t->is_supercoach ? 'Remove Super Coach' : 'Make Super Coach'; ?>">
                                            <?php echo $t->is_supercoach ? '🏆' : '⚪'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td style="text-align:center;">
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('ptp_trainer_ranking'); ?>
                                        <input type="hidden" name="action" value="update_trainer_ranking">
                                        <input type="hidden" name="trainer_id" value="<?php echo $t->id; ?>">
                                        <input type="hidden" name="is_featured" value="<?php echo $t->is_featured ? '0' : '1'; ?>">
                                        <button type="submit" style="background:none;border:none;cursor:pointer;font-size:24px;padding:4px;" title="<?php echo $t->is_featured ? 'Remove Featured' : 'Make Featured'; ?>">
                                            <?php echo $t->is_featured ? '⭐' : '☆'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" style="display:flex;gap:4px;">
                                        <?php wp_nonce_field('ptp_trainer_ranking'); ?>
                                        <input type="hidden" name="action" value="update_trainer_ranking">
                                        <input type="hidden" name="trainer_id" value="<?php echo $t->id; ?>">
                                        <input type="number" name="sort_order" value="<?php echo intval($t->sort_order); ?>" style="width:50px;padding:4px;border:1px solid #E5E5E5;border-radius:4px;text-align:center;" min="0" max="999">
                                        <button type="submit" style="padding:4px 8px;background:#0A0A0A;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11px;">Set</button>
                                    </form>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=ptp-trainers&action=view&id=' . $t->id); ?>" class="button button-small">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Quality Flags Section -->
            <div class="ptp-card">
                <div class="ptp-card-header" style="background:#DC2626;color:#fff;padding:16px 20px;">
                    <h3 style="margin:0;font-family:Oswald,sans-serif;text-transform:uppercase;letter-spacing:1px;">⚠️ Quality Flags &amp; Issues</h3>
                </div>
                <div class="ptp-card-body" style="padding:0;">
                    <?php
                    if (class_exists('PTP_Quality_Control')) {
                        $qc = PTP_Quality_Control::instance();
                        $qc->render_quality_dashboard();
                    } else {
                        echo '<div style="padding:40px;text-align:center;color:#6B7280;">Quality Control system not initialized.</div>';
                    }
                    ?>
                </div>
            </div>
            
            <style>
            .ptp-table th { background: #F9FAFB; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
            .ptp-table td { vertical-align: middle; }
            </style>
        </div>
        <?php
    }
    
    /* =========================================================================
       MESSAGES PAGE
       ========================================================================= */
    public function messages_page() {
        global $wpdb;
        $conv_table     = $wpdb->prefix . 'ptp_conversations';
        $msg_table      = $wpdb->prefix . 'ptp_messages';
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        $parents_table  = $wpdb->prefix . 'ptp_parents';

        // Check if tables exist
        $conv_exists = $wpdb->get_var("SHOW TABLES LIKE '$conv_table'") === $conv_table;
        $msg_exists  = $wpdb->get_var("SHOW TABLES LIKE '$msg_table'") === $msg_table;

        $conversations = array();
        $active_conv = isset($_GET['conv']) ? intval($_GET['conv']) : 0;
        $messages = array();

        if ($conv_exists && $msg_exists) {
            $conversations = $wpdb->get_results("
                SELECT c.*,
                    t.display_name as trainer_name,
                    p.display_name as parent_name,
                    tu.user_email as trainer_email,
                    pu.user_email as parent_email,
                    t.user_id as trainer_user_id,
                    p.user_id as parent_user_id,
                    (SELECT message FROM {$msg_table} WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_text,
                    (SELECT sender_id FROM {$msg_table} WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_sender_id,
                    (SELECT created_at FROM {$msg_table} WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_msg_time
                FROM {$conv_table} c
                LEFT JOIN {$trainers_table} t ON c.trainer_id = t.id
                LEFT JOIN {$parents_table} p ON c.parent_id = p.id
                LEFT JOIN {$wpdb->users} tu ON t.user_id = tu.ID
                LEFT JOIN {$wpdb->users} pu ON p.user_id = pu.ID
                WHERE c.is_archived = 0
                ORDER BY c.last_message_at DESC
                LIMIT 100
            ");

            // Auto-select first conversation if none selected
            if (!$active_conv && !empty($conversations)) {
                $active_conv = (int) $conversations[0]->id;
            }

            // Load messages for active conversation
            if ($active_conv) {
                $messages = $wpdb->get_results($wpdb->prepare("
                    SELECT m.*, u.display_name as sender_name
                    FROM {$msg_table} m
                    LEFT JOIN {$wpdb->users} u ON m.sender_id = u.ID
                    WHERE m.conversation_id = %d
                    ORDER BY m.created_at ASC
                    LIMIT 200
                ", $active_conv));
            }
        }

        // Find active conversation details
        $active_conv_data = null;
        foreach ($conversations as $c) {
            if ((int)$c->id === $active_conv) { $active_conv_data = $c; break; }
        }
        ?>
        <div class="ptp-admin-wrap">
            <div class="ptp-admin-header">
                <div class="ptp-admin-header-content">
                    <div class="ptp-admin-logo">
                        <span class="dashicons dashicons-universal-access"></span>
                    </div>
                    <div class="ptp-admin-title-wrap">
                        <h1 class="ptp-admin-title">PTP <span>Training</span></h1>
                        <p class="ptp-admin-subtitle">Platform conversations</p>
                    </div>
                </div>
            </div>
            
            <?php $this->render_nav('messages'); ?>
            
            <?php if (empty($conversations)): ?>
            <div class="ptp-card">
                <div class="ptp-card-body">
                    <div class="ptp-empty-state">
                        <div class="ptp-empty-state-icon"><span class="dashicons dashicons-email"></span></div>
                        <h3>No conversations yet</h3>
                        <p>Messages between trainers and parents will appear here.</p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div style="display:flex;gap:0;background:#fff;border-radius:12px;border:1px solid #E5E7EB;overflow:hidden;height:calc(100vh - 240px);min-height:500px">
                <!-- Conversation List -->
                <div style="width:340px;flex-shrink:0;border-right:1px solid #E5E7EB;overflow-y:auto;background:#FAFAFA">
                    <div style="padding:16px 16px 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9CA3AF"><?php echo count($conversations); ?> Conversation<?php echo count($conversations) !== 1 ? 's' : ''; ?></div>
                    <?php foreach ($conversations as $c):
                        $is_active = (int)$c->id === $active_conv;
                        $total_unread = intval($c->trainer_unread_count) + intval($c->parent_unread_count);
                        $preview = !empty($c->last_message_text) ? wp_trim_words($c->last_message_text, 12, '...') : 'No messages';
                        $time_ago = !empty($c->last_msg_time) ? human_time_diff(strtotime($c->last_msg_time), current_time('timestamp')) . ' ago' : '';
                    ?>
                    <a href="?page=ptp-messages&conv=<?php echo intval($c->id); ?>" style="display:block;padding:14px 16px;border-bottom:1px solid #F3F4F6;text-decoration:none;color:inherit;<?php echo $is_active ? 'background:#FFF;border-left:3px solid #FCB900;' : 'border-left:3px solid transparent;'; ?>transition:background .15s">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                            <div style="font-weight:700;font-size:13px;color:#111"><?php echo esc_html($c->trainer_name ?: 'Trainer #' . $c->trainer_id); ?></div>
                            <?php if ($total_unread > 0): ?>
                            <span style="background:#FCB900;color:#0A0A0A;font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;min-width:18px;text-align:center"><?php echo $total_unread; ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:12px;color:#6B7280;margin-bottom:2px"><span style="color:#9CA3AF">with</span> <?php echo esc_html($c->parent_name ?: 'Parent #' . $c->parent_id); ?></div>
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <div style="font-size:12px;color:#9CA3AF;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px"><?php echo esc_html($preview); ?></div>
                            <div style="font-size:10px;color:#9CA3AF;flex-shrink:0"><?php echo esc_html($time_ago); ?></div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Message Thread -->
                <div style="flex:1;display:flex;flex-direction:column;background:#fff">
                    <?php if ($active_conv_data): ?>
                    <!-- Thread Header -->
                    <div style="padding:16px 20px;border-bottom:1px solid #E5E7EB;flex-shrink:0">
                        <div style="font-weight:700;font-size:15px;color:#111"><?php echo esc_html($active_conv_data->trainer_name ?: 'Trainer'); ?> &harr; <?php echo esc_html($active_conv_data->parent_name ?: 'Parent'); ?></div>
                        <div style="font-size:12px;color:#9CA3AF;margin-top:2px">
                            <?php if (!empty($active_conv_data->trainer_email)): ?><?php echo esc_html($active_conv_data->trainer_email); ?> &middot; <?php endif; ?>
                            <?php if (!empty($active_conv_data->parent_email)): ?><?php echo esc_html($active_conv_data->parent_email); ?> &middot; <?php endif; ?>
                            Started <?php echo esc_html(date('M j, Y', strtotime($active_conv_data->created_at))); ?>
                        </div>
                    </div>
                    <!-- Messages -->
                    <div style="flex:1;overflow-y:auto;padding:20px" id="msgThread">
                        <?php if (empty($messages)): ?>
                        <div style="text-align:center;color:#9CA3AF;padding:40px;font-size:14px">No messages in this conversation.</div>
                        <?php else: ?>
                        <?php
                        $last_date = '';
                        $trainer_wp_id = !empty($active_conv_data->trainer_user_id) ? (int)$active_conv_data->trainer_user_id : 0;
                        foreach ($messages as $m):
                            $msg_date = date('M j, Y', strtotime($m->created_at));
                            $is_trainer = ((int)$m->sender_id === $trainer_wp_id);
                            if ($msg_date !== $last_date):
                                $last_date = $msg_date;
                        ?>
                        <div style="text-align:center;margin:16px 0 12px">
                            <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9CA3AF;background:#F3F4F6;padding:3px 10px;border-radius:10px"><?php echo esc_html($msg_date); ?></span>
                        </div>
                        <?php endif; ?>
                        <div style="display:flex;margin-bottom:12px;<?php echo $is_trainer ? 'justify-content:flex-start' : 'justify-content:flex-end'; ?>">
                            <div style="max-width:70%;<?php echo $is_trainer ? 'background:#F3F4F6;color:#111;border-radius:4px 16px 16px 16px' : 'background:#FCB900;color:#0A0A0A;border-radius:16px 4px 16px 16px'; ?>;padding:10px 14px;position:relative">
                                <div style="font-size:10px;font-weight:700;margin-bottom:3px;opacity:.6"><?php echo esc_html($m->sender_name ?: 'User #' . $m->sender_id); ?> &middot; <?php echo esc_html(date('g:i A', strtotime($m->created_at))); ?></div>
                                <div style="font-size:13px;line-height:1.5;white-space:pre-wrap"><?php echo esc_html($m->message); ?></div>
                                <?php if (!$m->is_read): ?>
                                <div style="position:absolute;top:8px;right:8px;width:6px;height:6px;border-radius:50%;background:#EF4444"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <!-- Admin note -->
                    <div style="padding:12px 20px;border-top:1px solid #E5E7EB;flex-shrink:0;background:#FAFAFA">
                        <div style="font-size:11px;color:#9CA3AF;text-align:center">Admin read-only view &middot; Messages are sent from trainer &amp; parent dashboards</div>
                    </div>
                    <?php else: ?>
                    <div style="flex:1;display:flex;align-items:center;justify-content:center;color:#9CA3AF;font-size:14px">Select a conversation</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var t = document.getElementById('msgThread');
            if (t) t.scrollTop = t.scrollHeight;
        });
        </script>
        <?php
    }
    
    /* =========================================================================
       SETTINGS PAGE
       ========================================================================= */
    public function settings_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        ?>
        <div class="ptp-admin-wrap">
            <div class="ptp-admin-header">
                <div class="ptp-admin-header-content">
                    <div class="ptp-admin-logo">
                        <span class="dashicons dashicons-universal-access"></span>
                    </div>
                    <div class="ptp-admin-title-wrap">
                        <h1 class="ptp-admin-title">PTP <span>Training</span></h1>
                        <p class="ptp-admin-subtitle">Platform configuration</p>
                    </div>
                </div>
            </div>
            
            <?php $this->render_nav('settings'); ?>
            
            <div class="ptp-settings-tabs">
                <a href="?page=ptp-settings&tab=general" class="ptp-settings-tab <?php echo $active_tab === 'general' ? 'active' : ''; ?>">General</a>
                <a href="?page=ptp-settings&tab=pages" class="ptp-settings-tab <?php echo $active_tab === 'pages' ? 'active' : ''; ?>">Pages</a>
                <a href="?page=ptp-settings&tab=checkout" class="ptp-settings-tab <?php echo $active_tab === 'checkout' ? 'active' : ''; ?>">Checkout</a>
                <a href="?page=ptp-settings&tab=thankyou" class="ptp-settings-tab <?php echo $active_tab === 'thankyou' ? 'active' : ''; ?>">Thank You</a>
                <a href="?page=ptp-settings&tab=emails" class="ptp-settings-tab <?php echo $active_tab === 'emails' ? 'active' : ''; ?>">Emails</a>
                <a href="?page=ptp-settings&tab=company" class="ptp-settings-tab <?php echo $active_tab === 'company' ? 'active' : ''; ?>">Company</a>
                <a href="?page=ptp-settings&tab=payments" class="ptp-settings-tab <?php echo $active_tab === 'payments' ? 'active' : ''; ?>">Payments</a>
                <a href="?page=ptp-settings&tab=notifications" class="ptp-settings-tab <?php echo $active_tab === 'notifications' ? 'active' : ''; ?>">Notifications</a>
                <a href="?page=ptp-settings&tab=sms" class="ptp-settings-tab <?php echo $active_tab === 'sms' ? 'active' : ''; ?>">SMS</a>
                <a href="?page=ptp-settings&tab=pixels" class="ptp-settings-tab <?php echo $active_tab === 'pixels' ? 'active' : ''; ?>">Tracking</a>
            </div>
            
            <?php if ($active_tab === 'general'): ?>
            <form method="post" action="options.php">
                <?php settings_fields('ptp_general'); ?>
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>General Settings</h3>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>Platform Fee (%)</label>
                            <input type="number" name="ptp_platform_fee" value="<?php echo esc_attr(get_option('ptp_platform_fee', 20)); ?>" min="0" max="50">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">PTP keeps this percentage, trainers receive the rest (currently <?php echo 100 - intval(get_option('ptp_platform_fee', 20)); ?>%)</p>
                        </div>
                        <div class="ptp-form-row">
                            <label>Minimum Payout ($)</label>
                            <input type="number" name="ptp_min_payout" value="<?php echo esc_attr(get_option('ptp_min_payout', 25)); ?>" min="1" max="500">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">Trainers must accumulate this amount before automatic payout</p>
                        </div>
                        <div class="ptp-form-row">
                            <label>Cancellation Refund Window (hours)</label>
                            <input type="number" name="ptp_refund_window" value="<?php echo esc_attr(get_option('ptp_refund_window', 24)); ?>" min="1" max="168">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">Full refund if cancelled this many hours before session</p>
                        </div>
                        <div class="ptp-form-row">
                            <label>From Email</label>
                            <input type="email" name="ptp_from_email" value="<?php echo esc_attr(get_option('ptp_from_email', get_option('admin_email'))); ?>">
                        </div>
                        <div class="ptp-form-row">
                            <label>Google Maps API Key</label>
                            <input type="text" name="ptp_google_maps_key" value="<?php echo esc_attr(get_option('ptp_google_maps_key')); ?>" placeholder="AIzaSy...">
                            <?php $has_maps_key = !empty(get_option('ptp_google_maps_key')); ?>
                            <div style="display: flex; align-items: center; gap: 6px; margin-top: 8px;">
                                <span style="color: <?php echo $has_maps_key ? '#059669' : '#DC2626'; ?>; font-size: 16px;">●</span>
                                <span style="font-size: 13px; color: <?php echo $has_maps_key ? '#059669' : '#DC2626'; ?>;">
                                    <?php echo $has_maps_key ? '✓ API Key configured' : '✗ API Key not set - Map will not display'; ?>
                                </span>
                            </div>
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">
                                Required for trainer map on Find Trainers page and location features.
                            </p>
                            <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 12px; margin-top: 10px;">
                                <h4 style="margin: 0 0 8px; color: #1E40AF; font-size: 13px;">📍 Google Maps Setup:</h4>
                                <ol style="margin: 0; padding-left: 16px; color: #1E40AF; font-size: 12px; line-height: 1.6;">
                                    <li>Go to <a href="https://console.cloud.google.com/google/maps-apis" target="_blank" style="color: #2563EB;">Google Cloud Console</a></li>
                                    <li>Create a project or select existing</li>
                                    <li>Enable these APIs: <strong>Maps JavaScript API</strong>, <strong>Places API</strong>, <strong>Geocoding API</strong></li>
                                    <li>Create an API key under "Credentials"</li>
                                    <li>Restrict the key to your domain for security</li>
                                </ol>
                            </div>
                        </div>
                        <div class="ptp-form-row">
                            <label>Google Place ID (for Reviews)</label>
                            <input type="text" name="ptp_google_place_id" value="<?php echo esc_attr(get_option('ptp_google_place_id')); ?>" placeholder="ChIJ...">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">
                                Used to display Google Reviews on the site. 
                                <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" style="color:#2563EB">Find your Place ID</a>
                            </p>
                        </div>
                    </div>
                </div>
                <p><button type="submit" class="ptp-btn ptp-btn-primary ptp-btn-lg">Save Settings</button></p>
            </form>
            
            <?php elseif ($active_tab === 'pages'): ?>
            <?php
            // Page Setup functionality
            $required_pages = array(
                'training' => array('title' => 'PTP Training', 'content' => '[ptp_home]'),
                'find-trainers' => array('title' => 'Find Trainers', 'content' => '[ptp_trainers_grid]'),
                'trainer' => array('title' => 'Trainer Profile', 'content' => '[ptp_trainer_profile]'),
                'book-session' => array('title' => 'Book Session', 'content' => '[ptp_booking_form]'),
                'booking-confirmation' => array('title' => 'Booking Confirmed', 'content' => '[ptp_booking_confirmation]'),
                'my-training' => array('title' => 'My Training', 'content' => '[ptp_my_training]'),
                'trainer-dashboard' => array('title' => 'Trainer Dashboard', 'content' => '[ptp_trainer_dashboard]'),
                'trainer-onboarding' => array('title' => 'Complete Your Profile', 'content' => '[ptp_trainer_onboarding]'),
                'messages' => array('title' => 'Messages', 'content' => '[ptp_messaging]'),
                'account' => array('title' => 'Account', 'content' => '[ptp_account]'),
                'login' => array('title' => 'Login', 'content' => '[ptp_login]'),
                'register' => array('title' => 'Register', 'content' => '[ptp_register]'),
                'apply' => array('title' => 'Become a Trainer', 'content' => '[ptp_apply]'),
                'parent-dashboard' => array('title' => 'Parent Dashboard', 'content' => '[ptp_parent_dashboard]'),
                'player-progress' => array('title' => 'Player Progress', 'content' => '[ptp_player_progress]'),
                'ptp-checkout' => array('title' => 'PTP Checkout', 'content' => '[ptp_checkout]'),
                'thank-you' => array('title' => 'Thank You', 'content' => '[ptp_thank_you]'),
            );
            
            $page_message = '';
            $page_message_type = '';
            
            if (isset($_POST['ptp_pages_action']) && wp_verify_nonce($_POST['ptp_pages_nonce'], 'ptp_pages')) {
                $action = sanitize_text_field($_POST['ptp_pages_action']);
                
                if ($action === 'create_all') {
                    $created = 0;
                    foreach ($required_pages as $slug => $page) {
                        if (!get_page_by_path($slug)) {
                            wp_insert_post(array(
                                'post_title' => $page['title'],
                                'post_name' => $slug,
                                'post_content' => $page['content'],
                                'post_status' => 'publish',
                                'post_type' => 'page',
                            ));
                            $created++;
                        }
                    }
                    $page_message = $created > 0 ? "Created {$created} missing pages." : "All pages already exist.";
                    $page_message_type = 'success';
                }
                
                if ($action === 'create_single' && !empty($_POST['page_slug'])) {
                    $slug = sanitize_text_field($_POST['page_slug']);
                    if (isset($required_pages[$slug])) {
                        $existing = get_page_by_path($slug);
                        if ($existing) {
                            wp_delete_post($existing->ID, true);
                        }
                        wp_insert_post(array(
                            'post_title' => $required_pages[$slug]['title'],
                            'post_name' => $slug,
                            'post_content' => $required_pages[$slug]['content'],
                            'post_status' => 'publish',
                            'post_type' => 'page',
                        ));
                        $page_message = "Page '{$slug}' has been created/recreated.";
                        $page_message_type = 'success';
                    }
                }
                
                if ($action === 'recreate_tables') {
                    PTP_Database::create_tables();
                    $page_message = "Database tables have been updated.";
                    $page_message_type = 'success';
                }
            }
            ?>
            
            <?php if ($page_message): ?>
            <div class="notice notice-<?php echo $page_message_type === 'success' ? 'success' : 'error'; ?>" style="margin: 0 0 20px; padding: 12px 15px;">
                <p style="margin: 0;"><?php echo esc_html($page_message); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="ptp-settings-section">
                <div class="ptp-settings-section-header">
                    <h3>Page Setup</h3>
                    <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">Create and manage required PTP pages</p>
                </div>
                <div class="ptp-settings-section-body">
                    <form method="post" style="margin-bottom: 20px;">
                        <?php wp_nonce_field('ptp_pages', 'ptp_pages_nonce'); ?>
                        <input type="hidden" name="ptp_pages_action" value="create_all">
                        <button type="submit" class="ptp-btn ptp-btn-primary">Create All Missing Pages</button>
                    </form>
                    
                    <table class="ptp-table" style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>Page</th>
                                <th>Slug</th>
                                <th>Shortcode</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($required_pages as $slug => $page): 
                                $existing = get_page_by_path($slug);
                                $exists = !empty($existing);
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($page['title']); ?></strong></td>
                                <td><code>/<?php echo esc_html($slug); ?>/</code></td>
                                <td><code><?php echo esc_html($page['content']); ?></code></td>
                                <td>
                                    <?php if ($exists): ?>
                                        <span style="color: #059669;">Exists</span>
                                    <?php else: ?>
                                        <span style="color: #DC2626;">Missing</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('ptp_pages', 'ptp_pages_nonce'); ?>
                                        <input type="hidden" name="ptp_pages_action" value="create_single">
                                        <input type="hidden" name="page_slug" value="<?php echo esc_attr($slug); ?>">
                                        <button type="submit" class="ptp-btn ptp-btn-sm"><?php echo $exists ? 'Recreate' : 'Create'; ?></button>
                                    </form>
                                    <?php if ($exists): ?>
                                        <a href="<?php echo get_permalink($existing->ID); ?>" target="_blank" class="ptp-btn ptp-btn-sm ptp-btn-outline">View</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="ptp-settings-section" style="margin-top: 20px;">
                <div class="ptp-settings-section-header">
                    <h3>Database Tools</h3>
                </div>
                <div class="ptp-settings-section-body">
                    <form method="post">
                        <?php wp_nonce_field('ptp_pages', 'ptp_pages_nonce'); ?>
                        <input type="hidden" name="ptp_pages_action" value="recreate_tables">
                        <button type="submit" class="ptp-btn ptp-btn-outline" onclick="return confirm('This will update all database tables. Continue?');">Update Database Tables</button>
                    </form>
                </div>
            </div>
            
            <?php elseif ($active_tab === 'checkout'): ?>
            <form method="post" action="options.php">
                <?php settings_fields('ptp_checkout'); ?>
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Bundle Discount</h3>
                        <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">Discount applied when customers book both camp AND training together</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>Bundle Discount Percentage (%)</label>
                            <input type="number" name="ptp_bundle_discount_percent" value="<?php echo esc_attr(get_option('ptp_bundle_discount_percent', 15)); ?>" min="0" max="50" step="1" style="width: 100px;">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">Currently: <strong><?php echo intval(get_option('ptp_bundle_discount_percent', 15)); ?>% off</strong> when Camp + Training purchased together</p>
                        </div>
                        
                        <div style="background: #ECFDF5; border: 1px solid #6EE7B7; border-radius: 8px; padding: 15px; margin-top: 15px;">
                            <h4 style="margin: 0 0 10px; color: #047857;">💰 Example Calculation</h4>
                            <?php 
                            $example_camp = 100;
                            $example_training = 120;
                            $bundle_pct = intval(get_option('ptp_bundle_discount_percent', 15));
                            $example_subtotal = $example_camp + $example_training;
                            $example_discount = round($example_subtotal * ($bundle_pct / 100), 2);
                            ?>
                            <div style="font-size: 13px; color: #065F46; line-height: 1.8;">
                                <div>Camp: $<?php echo $example_camp; ?></div>
                                <div>Training: $<?php echo $example_training; ?></div>
                                <div style="border-top: 1px solid #6EE7B7; padding-top: 5px; margin-top: 5px;">
                                    Subtotal: $<?php echo $example_subtotal; ?>
                                </div>
                                <div style="color: #059669; font-weight: 600;">
                                    Bundle Discount (<?php echo $bundle_pct; ?>%): -$<?php echo number_format($example_discount, 2); ?>
                                </div>
                                <div style="font-weight: 700; font-size: 15px;">
                                    Customer Pays: $<?php echo number_format($example_subtotal - $example_discount, 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Processing Fee</h3>
                        <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">Pass Stripe fees to customers (optional)</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>
                                <input type="checkbox" name="ptp_processing_fee_enabled" value="1" <?php checked(get_option('ptp_processing_fee_enabled', 1)); ?>>
                                <strong>Enable Processing Fee</strong>
                            </label>
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">When enabled, customers pay a small fee to cover payment processing costs</p>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px;">
                            <div class="ptp-form-row">
                                <label>Percentage Fee (%)</label>
                                <input type="number" name="ptp_processing_fee_percent" value="<?php echo esc_attr(get_option('ptp_processing_fee_percent', 3)); ?>" min="0" max="10" step="0.1" style="width: 100px;">
                            </div>
                            <div class="ptp-form-row">
                                <label>Fixed Fee ($)</label>
                                <input type="number" name="ptp_processing_fee_fixed" value="<?php echo esc_attr(get_option('ptp_processing_fee_fixed', 0.30)); ?>" min="0" max="5" step="0.01" style="width: 100px;">
                            </div>
                        </div>
                        
                        <div style="background: #FEF3C7; border: 1px solid #FCD34D; border-radius: 8px; padding: 15px; margin-top: 15px;">
                            <h4 style="margin: 0 0 10px; color: #92400E;">📊 Fee Calculation</h4>
                            <?php 
                            $fee_pct = floatval(get_option('ptp_processing_fee_percent', 3));
                            $fee_fixed = floatval(get_option('ptp_processing_fee_fixed', 0.30));
                            $fee_enabled = get_option('ptp_processing_fee_enabled', 1);
                            ?>
                            <div style="font-size: 13px; color: #78350F;">
                                <div style="font-family: monospace; background: #fff; padding: 10px; border-radius: 4px; margin-bottom: 10px;">
                                    Processing Fee = (Order Total × <?php echo $fee_pct; ?>%) + $<?php echo number_format($fee_fixed, 2); ?>
                                </div>
                                <div>
                                    <strong>Example:</strong> $100 order → $<?php echo number_format((100 * $fee_pct / 100) + $fee_fixed, 2); ?> fee → Customer pays $<?php echo number_format(100 + (100 * $fee_pct / 100) + $fee_fixed, 2); ?>
                                </div>
                                <?php if (!$fee_enabled): ?>
                                <div style="margin-top: 10px; color: #DC2626;">
                                    ⚠️ Processing fee is currently <strong>DISABLED</strong>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 15px; margin-top: 15px;">
                            <h4 style="margin: 0 0 8px; color: #1E40AF;">💳 Stripe's Actual Fees</h4>
                            <p style="margin: 0; color: #1E40AF; font-size: 12px; line-height: 1.6;">
                                Stripe charges <strong>2.9% + $0.30</strong> for card payments.<br>
                                Setting your fee to <strong>3% + $0.30</strong> covers this cost plus a small buffer for refunds/disputes.
                            </p>
                        </div>
                    </div>
                </div>
                
                <p><button type="submit" class="ptp-btn ptp-btn-primary ptp-btn-lg">Save Checkout Settings</button></p>
            </form>
            
            <?php elseif ($active_tab === 'thankyou'): ?>
            <?php
            global $wpdb;
            
            // Get current thank you values
            $training_cta_enabled = get_option('ptp_thankyou_training_cta_enabled', 'yes');
            $training_cta_url = get_option('ptp_thankyou_training_cta_url', '/find-trainers/');
            $announcement_enabled = get_option('ptp_thankyou_announcement_enabled', 'yes');
            $instagram_handle = get_option('ptp_instagram_handle', '@ptpsoccercamps');
            $referral_enabled = get_option('ptp_thankyou_referral_enabled', 'yes');
            $referral_amount = get_option('ptp_referral_amount', 25);
            
            // Handle save
            if (isset($_POST['ptp_thankyou_nonce']) && wp_verify_nonce($_POST['ptp_thankyou_nonce'], 'ptp_thankyou_save')) {
                update_option('ptp_thankyou_training_cta_enabled', isset($_POST['ptp_thankyou_training_cta_enabled']) ? 'yes' : 'no');
                update_option('ptp_thankyou_training_cta_url', sanitize_text_field($_POST['ptp_thankyou_training_cta_url']));
                update_option('ptp_thankyou_announcement_enabled', isset($_POST['ptp_thankyou_announcement_enabled']) ? 'yes' : 'no');
                update_option('ptp_instagram_handle', sanitize_text_field($_POST['ptp_instagram_handle']));
                update_option('ptp_thankyou_referral_enabled', isset($_POST['ptp_thankyou_referral_enabled']) ? 'yes' : 'no');
                update_option('ptp_referral_amount', intval($_POST['ptp_referral_amount']));
                
                $training_cta_enabled = get_option('ptp_thankyou_training_cta_enabled');
                $training_cta_url = get_option('ptp_thankyou_training_cta_url');
                $announcement_enabled = get_option('ptp_thankyou_announcement_enabled');
                $instagram_handle = get_option('ptp_instagram_handle');
                $referral_enabled = get_option('ptp_thankyou_referral_enabled');
                $referral_amount = get_option('ptp_referral_amount');
                
                echo '<div class="notice notice-success" style="margin: 0 0 20px; padding: 12px 15px;"><p style="margin: 0;">Thank You page settings saved.</p></div>';
            }
            
            // Get stats
            $announcements_table = $wpdb->prefix . 'ptp_social_announcements';
            $announcement_count = 0;
            $announcement_posted = 0;
            $announcement_pending = 0;
            
            if ($wpdb->get_var("SHOW TABLES LIKE '$announcements_table'") === $announcements_table) {
                $announcement_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $announcements_table") ?: 0;
                $announcement_posted = (int) $wpdb->get_var("SELECT COUNT(*) FROM $announcements_table WHERE status = 'posted'") ?: 0;
                $announcement_pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $announcements_table WHERE status = 'pending'") ?: 0;
            }
            ?>
            
            <form method="post">
                <?php wp_nonce_field('ptp_thankyou_save', 'ptp_thankyou_nonce'); ?>
                
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Training CTA</h3>
                        <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">Show call-to-action for private training</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>
                                <input type="checkbox" name="ptp_thankyou_training_cta_enabled" value="1" <?php checked($training_cta_enabled, 'yes'); ?>>
                                <strong>Show Training CTA</strong>
                            </label>
                        </div>
                        <div class="ptp-form-row">
                            <label>CTA Link URL</label>
                            <input type="text" name="ptp_thankyou_training_cta_url" value="<?php echo esc_attr($training_cta_url); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Social Announcements</h3>
                        <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">Let parents share their registration on social media</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>
                                <input type="checkbox" name="ptp_thankyou_announcement_enabled" value="1" <?php checked($announcement_enabled, 'yes'); ?>>
                                <strong>Enable Announcements</strong>
                            </label>
                        </div>
                        <div class="ptp-form-row">
                            <label>Instagram Handle</label>
                            <input type="text" name="ptp_instagram_handle" value="<?php echo esc_attr($instagram_handle); ?>" placeholder="@ptpsoccercamps">
                        </div>
                        
                        <?php if ($announcement_count > 0): ?>
                        <div style="background: #F3F4F6; border-radius: 8px; padding: 15px; margin-top: 15px;">
                            <h4 style="margin: 0 0 10px; font-size: 14px;">Announcement Stats</h4>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                                <div style="text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700;"><?php echo $announcement_count; ?></div>
                                    <div style="font-size: 12px; color: #6B7280;">Total</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700; color: #059669;"><?php echo $announcement_posted; ?></div>
                                    <div style="font-size: 12px; color: #6B7280;">Posted</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700; color: #F59E0B;"><?php echo $announcement_pending; ?></div>
                                    <div style="font-size: 12px; color: #6B7280;">Pending</div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Referral Program</h3>
                        <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">Encourage parents to refer friends</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>
                                <input type="checkbox" name="ptp_thankyou_referral_enabled" value="1" <?php checked($referral_enabled, 'yes'); ?>>
                                <strong>Show Referral Link</strong>
                            </label>
                        </div>
                        <div class="ptp-form-row">
                            <label>Referral Discount ($)</label>
                            <input type="number" name="ptp_referral_amount" value="<?php echo esc_attr($referral_amount); ?>" min="0" max="100" style="width: 100px;">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">Amount off for both referrer and new customer</p>
                        </div>
                    </div>
                </div>
                
                <p><button type="submit" class="ptp-btn ptp-btn-primary ptp-btn-lg">Save Thank You Settings</button></p>
            </form>
            
            <?php elseif ($active_tab === 'emails'): ?>
            <?php
            // Handle email settings save
            if (isset($_POST['ptp_email_settings_nonce']) && wp_verify_nonce($_POST['ptp_email_settings_nonce'], 'ptp_email_settings_save')) {
                update_option('ptp_email_enabled', isset($_POST['ptp_email_enabled']) ? 'yes' : 'no');
                update_option('ptp_email_logo_url', esc_url_raw($_POST['ptp_email_logo_url'] ?? ''));
                update_option('ptp_email_support_phone', sanitize_text_field($_POST['ptp_email_support_phone'] ?? ''));
                update_option('ptp_email_support_email', sanitize_email($_POST['ptp_email_support_email'] ?? ''));
                update_option('ptp_email_upsell_enabled', isset($_POST['ptp_email_upsell_enabled']) ? 'yes' : 'no');
                update_option('ptp_email_upsell_text', sanitize_textarea_field($_POST['ptp_email_upsell_text'] ?? ''));
                echo '<div class="notice notice-success"><p>Email settings saved!</p></div>';
            }
            
            $email_enabled = get_option('ptp_email_enabled', 'yes');
            $email_logo = get_option('ptp_email_logo_url', 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png');
            $support_phone = get_option('ptp_email_support_phone', '(610) 761-5230');
            $support_email = get_option('ptp_email_support_email', get_option('admin_email'));
            $upsell_enabled = get_option('ptp_email_upsell_enabled', 'yes');
            $upsell_text = get_option('ptp_email_upsell_text', 'Want more training? Book a private session with one of our pro coaches!');
            ?>
            <form method="post">
                <?php wp_nonce_field('ptp_email_settings_save', 'ptp_email_settings_nonce'); ?>
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Order Confirmation Emails</h3>
                        <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">Configure branded emails sent after camp/training purchases</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="ptp_email_enabled" value="1" <?php checked($email_enabled, 'yes'); ?> style="width: 18px; height: 18px;">
                                <span>Enable PTP Branded Emails</span>
                            </label>
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">When enabled, PTP sends custom branded emails instead of default PTP Native emails</p>
                        </div>
                        <div class="ptp-form-row">
                            <label>Logo URL</label>
                            <input type="url" name="ptp_email_logo_url" value="<?php echo esc_attr($email_logo); ?>" class="regular-text">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">Logo displayed at top of confirmation emails</p>
                        </div>
                        <div class="ptp-form-row">
                            <label>Support Phone</label>
                            <input type="text" name="ptp_email_support_phone" value="<?php echo esc_attr($support_phone); ?>" placeholder="(610) 761-5230">
                        </div>
                        <div class="ptp-form-row">
                            <label>Support Email</label>
                            <input type="email" name="ptp_email_support_email" value="<?php echo esc_attr($support_email); ?>" placeholder="support@example.com">
                        </div>
                    </div>
                </div>
                
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Upsell Section</h3>
                        <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">Optional upsell message in confirmation emails</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="ptp_email_upsell_enabled" value="1" <?php checked($upsell_enabled, 'yes'); ?> style="width: 18px; height: 18px;">
                                <span>Show Upsell in Emails</span>
                            </label>
                        </div>
                        <div class="ptp-form-row">
                            <label>Upsell Message</label>
                            <textarea name="ptp_email_upsell_text" rows="3" style="width: 100%;"><?php echo esc_textarea($upsell_text); ?></textarea>
                        </div>
                    </div>
                </div>
                
                <p><button type="submit" class="ptp-btn ptp-btn-primary ptp-btn-lg">Save Email Settings</button></p>
            </form>
            
            <?php elseif ($active_tab === 'company'): ?>
            <form method="post" action="options.php">
                <?php settings_fields('ptp_company'); ?>
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Company Information (for 1099-NEC)</h3>
                        <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">This information appears on 1099-NEC forms sent to trainers</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>Company Legal Name *</label>
                            <input type="text" name="ptp_company_name" value="<?php echo esc_attr(get_option('ptp_company_name', 'Players Teaching Players LLC')); ?>" required>
                        </div>
                        <div class="ptp-form-row">
                            <label>Company EIN *</label>
                            <input type="text" name="ptp_company_ein" value="<?php echo esc_attr(get_option('ptp_company_ein')); ?>" placeholder="XX-XXXXXXX">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">Your Employer Identification Number (required for 1099s)</p>
                        </div>
                        <div class="ptp-form-row">
                            <label>Street Address *</label>
                            <input type="text" name="ptp_company_address" value="<?php echo esc_attr(get_option('ptp_company_address')); ?>">
                        </div>
                        <div class="ptp-form-row">
                            <label>City *</label>
                            <input type="text" name="ptp_company_city" value="<?php echo esc_attr(get_option('ptp_company_city')); ?>">
                        </div>
                        <div class="ptp-form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div>
                                <label>State *</label>
                                <select name="ptp_company_state">
                                    <option value="">Select</option>
                                    <?php
                                    $states = array('AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC');
                                    $current_state = get_option('ptp_company_state', '');
                                    foreach ($states as $st): ?>
                                        <option value="<?php echo $st; ?>" <?php selected($current_state, $st); ?>><?php echo $st; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>ZIP Code *</label>
                                <input type="text" name="ptp_company_zip" value="<?php echo esc_attr(get_option('ptp_company_zip')); ?>">
                            </div>
                        </div>
                        <div class="ptp-form-row">
                            <label>Company Phone</label>
                            <input type="tel" name="ptp_company_phone" value="<?php echo esc_attr(get_option('ptp_company_phone')); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>1099 Filing Settings</h3>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>1099 Threshold</label>
                            <input type="number" name="ptp_1099_threshold" value="<?php echo esc_attr(get_option('ptp_1099_threshold', 600)); ?>" min="0">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">IRS requires 1099-NEC for payments of $600 or more</p>
                        </div>
                        <div class="ptp-form-row">
                            <label>
                                <input type="checkbox" name="ptp_require_w9" value="1" <?php checked(get_option('ptp_require_w9', 1)); ?>>
                                Require W-9 before trainer activation
                            </label>
                        </div>
                        <div class="ptp-form-row">
                            <label>
                                <input type="checkbox" name="ptp_require_safesport" value="1" <?php checked(get_option('ptp_require_safesport', 1)); ?>>
                                Require SafeSport certificate before trainer activation
                            </label>
                        </div>
                    </div>
                </div>
                
                <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 15px; margin: 20px 0;">
                    <p style="margin: 0; color: #1E40AF; font-size: 13px;">
                        <strong>📋 1099 Reporting:</strong> Go to <a href="<?php echo admin_url('admin.php?page=ptp-tax-reports'); ?>">PTP Training → 1099 Reports</a> to view trainers who need 1099s and export tax data.
                    </p>
                </div>
                
                <p><button type="submit" class="ptp-btn ptp-btn-primary ptp-btn-lg">Save Settings</button></p>
            </form>
            
            <?php elseif ($active_tab === 'payments'): ?>
            <form method="post" action="options.php">
                <?php settings_fields('ptp_stripe'); ?>
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Stripe Configuration</h3>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>Test Mode</label>
                            <select name="ptp_stripe_test_mode">
                                <option value="1" <?php selected(get_option('ptp_stripe_test_mode', 1), 1); ?>>Yes</option>
                                <option value="0" <?php selected(get_option('ptp_stripe_test_mode', 1), 0); ?>>No</option>
                            </select>
                        </div>
                        <div class="ptp-form-row">
                            <label>Test Publishable Key</label>
                            <input type="text" name="ptp_stripe_test_publishable" value="<?php echo esc_attr(get_option('ptp_stripe_test_publishable')); ?>">
                        </div>
                        <div class="ptp-form-row">
                            <label>Test Secret Key</label>
                            <input type="password" name="ptp_stripe_test_secret" value="<?php echo esc_attr(get_option('ptp_stripe_test_secret')); ?>">
                        </div>
                        <div class="ptp-form-row">
                            <label>Live Publishable Key</label>
                            <input type="text" name="ptp_stripe_live_publishable" value="<?php echo esc_attr(get_option('ptp_stripe_live_publishable')); ?>">
                        </div>
                        <div class="ptp-form-row">
                            <label>Live Secret Key</label>
                            <input type="password" name="ptp_stripe_live_secret" value="<?php echo esc_attr(get_option('ptp_stripe_live_secret')); ?>">
                        </div>
                        <div class="ptp-form-row">
                            <label>Webhook Secret</label>
                            <input type="password" name="ptp_stripe_webhook_secret" value="<?php echo esc_attr(get_option('ptp_stripe_webhook_secret')); ?>" placeholder="whsec_...">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">From Stripe Dashboard → Developers → Webhooks</p>
                        </div>
                        
                        <!-- Webhook URL Info -->
                        <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 15px; margin-top: 15px;">
                            <h4 style="margin: 0 0 10px; color: #1E40AF;">🔗 Webhook Configuration</h4>
                            <p style="margin: 0 0 10px; color: #1E40AF; font-size: 13px;">Add this URL in Stripe Dashboard → Developers → Webhooks:</p>
                            <code style="display: block; background: #fff; padding: 10px; border-radius: 4px; font-size: 12px; word-break: break-all; color: #0A0A0A;">
                                <?php echo esc_url(rest_url('ptp/v1/stripe-webhook')); ?>
                            </code>
                            <p style="margin: 10px 0 0; color: #1E40AF; font-size: 12px;">
                                <strong>Required events:</strong> payment_intent.succeeded, payment_intent.payment_failed, charge.refunded, account.updated
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Stripe Connect (Trainer Payouts)</h3>
                        <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">Enable direct deposits to trainers via Stripe Connect</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div style="background: #FEF3C7; border: 1px solid #FCD34D; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                            <h4 style="margin: 0 0 10px; color: #92400E;">⚠️ Setup Required in Stripe Dashboard</h4>
                            <p style="margin: 0 0 10px; color: #78350F; font-size: 13px;">Before enabling Stripe Connect, you must:</p>
                            <ol style="margin: 0; padding-left: 20px; color: #78350F; font-size: 13px;">
                                <li style="margin-bottom: 5px;">Log in to <a href="https://dashboard.stripe.com" target="_blank" style="color: #1D4ED8;">Stripe Dashboard</a></li>
                                <li style="margin-bottom: 5px;">Go to <strong>Settings → Connect → Get Started</strong></li>
                                <li style="margin-bottom: 5px;">Complete the Connect platform application</li>
                                <li style="margin-bottom: 5px;">Set platform type as <strong>"Express"</strong></li>
                                <li style="margin-bottom: 5px;">Configure your branding and payout settings</li>
                                <li>Wait for Stripe to approve your Connect application</li>
                            </ol>
                            <p style="margin: 10px 0 0; color: #78350F; font-size: 12px;"><a href="https://stripe.com/docs/connect" target="_blank" style="color: #1D4ED8;">📖 Stripe Connect Documentation</a></p>
                        </div>
                        
                        <div class="ptp-form-row">
                            <label>Enable Stripe Connect</label>
                            <select name="ptp_stripe_connect_enabled">
                                <option value="0" <?php selected(get_option('ptp_stripe_connect_enabled', 0), 0); ?>>Disabled - Setup not complete</option>
                                <option value="1" <?php selected(get_option('ptp_stripe_connect_enabled', 0), 1); ?>>Enabled - Connect is ready</option>
                            </select>
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">Only enable after completing Stripe Connect setup above</p>
                        </div>
                        
                        <!-- Configuration Status -->
                        <?php
                        $test_mode = get_option('ptp_stripe_test_mode', true);
                        $has_test_secret = !empty(get_option('ptp_stripe_test_secret'));
                        $has_test_pub = !empty(get_option('ptp_stripe_test_publishable'));
                        $has_live_secret = !empty(get_option('ptp_stripe_live_secret'));
                        $has_live_pub = !empty(get_option('ptp_stripe_live_publishable'));
                        $has_webhook_secret = !empty(get_option('ptp_stripe_webhook_secret'));
                        $connect_enabled = get_option('ptp_stripe_connect_enabled', false);
                        
                        $current_mode = $test_mode ? 'Test' : 'Live';
                        $has_keys = $test_mode ? ($has_test_secret && $has_test_pub) : ($has_live_secret && $has_live_pub);
                        ?>
                        <div style="background: #F3F4F6; border-radius: 8px; padding: 15px; margin-top: 20px;">
                            <h4 style="margin: 0 0 12px; font-size: 14px; font-weight: 600;">📊 Current Configuration Status</h4>
                            <div style="display: grid; gap: 8px; font-size: 13px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="color: <?php echo $test_mode ? '#059669' : '#DC2626'; ?>;">●</span>
                                    <strong>Mode:</strong> <?php echo $current_mode; ?> Mode
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="color: <?php echo $has_keys ? '#059669' : '#DC2626'; ?>;">●</span>
                                    <strong><?php echo $current_mode; ?> API Keys:</strong> 
                                    <?php echo $has_keys ? '✓ Configured' : '✗ Missing'; ?>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="color: <?php echo $has_webhook_secret ? '#059669' : '#F59E0B'; ?>;">●</span>
                                    <strong>Webhook Secret:</strong> 
                                    <?php echo $has_webhook_secret ? '✓ Configured' : '⚠ Not set (optional but recommended)'; ?>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="color: <?php echo $connect_enabled ? '#059669' : '#F59E0B'; ?>;">●</span>
                                    <strong>Stripe Connect:</strong> 
                                    <?php echo $connect_enabled ? '✓ Enabled' : '⚠ Disabled'; ?>
                                </div>
                            </div>
                            
                            <?php if (!$has_keys || !$connect_enabled): ?>
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #E5E7EB;">
                                <p style="margin: 0; color: #92400E; font-size: 12px; font-weight: 600;">⚠️ Action Required:</p>
                                <ul style="margin: 8px 0 0; padding-left: 16px; color: #78350F; font-size: 12px;">
                                    <?php if (!$has_keys): ?>
                                    <li>Add your Stripe <?php echo $current_mode; ?> API keys above</li>
                                    <?php endif; ?>
                                    <?php if (!$connect_enabled): ?>
                                    <li>Complete Stripe Connect setup in your Stripe Dashboard, then enable it here</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <?php else: ?>
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #E5E7EB;">
                                <p style="margin: 0; color: #059669; font-size: 12px; font-weight: 600;">✓ Stripe is fully configured!</p>
                                <p style="margin: 4px 0 0; color: #6B7280; font-size: 12px;">Trainers can now connect their bank accounts for payouts.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <p><button type="submit" class="ptp-btn ptp-btn-primary ptp-btn-lg">Save Settings</button></p>
            </form>
            
            <?php elseif ($active_tab === 'notifications'): ?>
            <form method="post" action="options.php">
                <?php settings_fields('ptp_notifications'); ?>
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Notification Settings</h3>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>Email Booking Confirmation</label>
                            <select name="ptp_email_booking_confirmation">
                                <option value="1" <?php selected(get_option('ptp_email_booking_confirmation', 1), 1); ?>>Enabled</option>
                                <option value="0" <?php selected(get_option('ptp_email_booking_confirmation', 1), 0); ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="ptp-form-row">
                            <label>Email Session Reminder</label>
                            <select name="ptp_email_session_reminder">
                                <option value="1" <?php selected(get_option('ptp_email_session_reminder', 1), 1); ?>>Enabled</option>
                                <option value="0" <?php selected(get_option('ptp_email_session_reminder', 1), 0); ?>>Disabled</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Push Notifications (Mobile App)</h3>
                        <p style="color: #6B7280; font-size: 13px; margin: 5px 0 0;">Firebase Cloud Messaging for iOS/Android push notifications</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div class="ptp-form-row">
                            <label>FCM Server Key</label>
                            <input type="password" name="ptp_fcm_server_key" value="<?php echo esc_attr(get_option('ptp_fcm_server_key')); ?>" placeholder="Enter Firebase Cloud Messaging server key">
                            <p style="color: #6B7280; font-size: 12px; margin-top: 5px;">Get this from Firebase Console → Project Settings → Cloud Messaging</p>
                        </div>
                    </div>
                </div>
                <p><button type="submit" class="ptp-btn ptp-btn-primary ptp-btn-lg">Save Settings</button></p>
            </form>
            
            <?php elseif ($active_tab === 'sms'): ?>
            <form method="post" action="options.php">
                <?php settings_fields('ptp_openphone'); ?>
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>OpenPhone SMS Settings</h3>
                        <p style="color:#6B7280;font-size:14px;margin:8px 0 0">Send booking confirmations, session reminders, mentorship touchpoints, and chatbot replies via OpenPhone.</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <?php
                        $op_key     = get_option('ptp_openphone_api_key', 'fRwRNgl2ynRQFy5oBUhvRuZtQA2Cz4CS');
                        $op_from    = get_option('ptp_openphone_from', '+16106714778');
                        $op_user    = get_option('ptp_openphone_user_id', '');
                        $op_enabled = get_option('ptp_sms_enabled', '1');
                        $op_ready   = !empty($op_key) && !empty($op_from);
                        ?>
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
                            <span>Status:</span>
                            <?php if ($op_ready && $op_enabled): ?>
                                <span style="background:#D1FAE5;color:#065F46;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600">Active</span>
                                <button type="button" class="button" id="ptp-test-openphone-main">Test Connection</button>
                            <?php elseif ($op_ready): ?>
                                <span style="background:#FEF3C7;color:#92400E;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600">Configured but disabled</span>
                            <?php else: ?>
                                <span style="background:#FEE2E2;color:#991B1B;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600">Not configured</span>
                            <?php endif; ?>
                        </div>
                        <div id="ptp-openphone-main-result" style="display:none;margin:0 0 16px;padding:12px;border-radius:6px;font-size:13px;"></div>

                        <div class="ptp-form-row">
                            <label>Enable SMS</label>
                            <select name="ptp_sms_enabled">
                                <option value="1" <?php selected($op_enabled, '1'); ?>>Enabled</option>
                                <option value="0" <?php selected($op_enabled, '0'); ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="ptp-form-row">
                            <label>API Key</label>
                            <input type="password" name="ptp_openphone_api_key" value="<?php echo esc_attr($op_key); ?>" placeholder="Your OpenPhone API key">
                            <p style="color:#6B7280;font-size:12px;margin-top:5px">From <a href="https://app.openphone.com/settings/api" target="_blank">OpenPhone &rarr; Settings &rarr; API Keys</a></p>
                        </div>
                        <div class="ptp-form-row">
                            <label>From Phone Number</label>
                            <input type="text" name="ptp_openphone_from" value="<?php echo esc_attr($op_from); ?>" placeholder="+16106714778 or PNxxxxxxxx">
                            <p style="color:#6B7280;font-size:12px;margin-top:5px">E.164 format (+1...) or OpenPhone Phone Number ID (starts with PN). Test Connection shows your available numbers.</p>
                        </div>
                        <div class="ptp-form-row">
                            <label>User ID <span style="font-weight:400;color:#9CA3AF">(optional)</span></label>
                            <input type="text" name="ptp_openphone_user_id" value="<?php echo esc_attr($op_user); ?>" placeholder="USxxxxxxxx">
                            <p style="color:#6B7280;font-size:12px;margin-top:5px">Defaults to the phone number owner. Only needed if multiple users share one number.</p>
                        </div>
                    </div>
                </div>

                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>Webhook Setup</h3>
                    </div>
                    <div class="ptp-settings-section-body">
                        <p style="color:#6B7280;font-size:14px;margin:0 0 12px">To receive incoming SMS and delivery confirmations, add these webhooks in OpenPhone:</p>
                        <ol style="margin:0 0 16px;padding-left:20px;color:#374151;line-height:2">
                            <li>Go to <a href="https://app.openphone.com/settings/webhooks" target="_blank">OpenPhone &rarr; Settings &rarr; Webhooks</a></li>
                            <li>Add webhook URL: <code style="background:#F3F4F6;padding:2px 6px;border-radius:4px;font-size:13px"><?php echo esc_html(rest_url('ptp/v1/openphone/webhook')); ?></code></li>
                            <li>Enable events: <strong>message.received</strong>, <strong>message.delivered</strong></li>
                            <li>Save</li>
                        </ol>
                        <p style="color:#6B7280;font-size:13px;margin:0">Chatbot webhook: <code style="background:#F3F4F6;padding:2px 6px;border-radius:4px;font-size:12px"><?php echo esc_html(rest_url('ptp/v1/chatbot/sms-webhook')); ?></code></p>
                    </div>
                </div>

                <p><button type="submit" class="ptp-btn ptp-btn-primary ptp-btn-lg">Save SMS Settings</button></p>
            </form>
            <script>
            jQuery(function($){
                $('#ptp-test-openphone-main').on('click', function(){
                    var btn=$(this), res=$('#ptp-openphone-main-result');
                    btn.prop('disabled',true).text('Testing...');
                    res.hide();
                    $.post(ajaxurl,{action:'ptp_test_openphone_connection',nonce:'<?php echo wp_create_nonce("ptp_admin_nonce"); ?>'},function(r){
                        btn.prop('disabled',false).text('Test Connection');
                        if(r.success){
                            res.html('<strong>Connected!</strong> '+r.data.message).css({background:'#D1FAE5',color:'#065F46',border:'1px solid #A7F3D0'}).show();
                        } else {
                            res.html('<strong>Failed:</strong> '+(r.data||'Unknown error')).css({background:'#FEE2E2',color:'#991B1B',border:'1px solid #FECACA'}).show();
                        }
                    }).fail(function(){
                        btn.prop('disabled',false).text('Test Connection');
                        res.html('<strong>Request failed</strong>').css({background:'#FEE2E2',color:'#991B1B',border:'1px solid #FECACA'}).show();
                    });
                });
            });
            </script>
            <?php elseif ($active_tab === 'pixels'): ?>
            <form method="post" action="options.php">
                <?php settings_fields('ptp_pixels'); ?>
                <div class="ptp-settings-section">
                    <div class="ptp-settings-section-header">
                        <h3>📊 Retargeting & Analytics Pixels</h3>
                        <p style="color:#6B7280;font-size:14px;margin:8px 0 0">Add your tracking pixels to retarget visitors and measure conversions. All events are tracked automatically.</p>
                    </div>
                    <div class="ptp-settings-section-body">
                        <div style="background:#FEF3C7;border-radius:8px;padding:16px;margin-bottom:20px">
                            <p style="margin:0;color:#92400E;font-size:14px"><strong>⚡ Events tracked automatically:</strong> PageView, ViewContent, Search, AddToCart, InitiateCheckout, Purchase, Lead</p>
                        </div>
                        
                        <h4 style="margin:0 0 12px;padding-top:20px;border-top:1px solid #E5E7EB">Facebook / Meta Pixel</h4>
                        <div class="ptp-form-row">
                            <label>Pixel ID</label>
                            <input type="text" name="ptp_fb_pixel_id" value="<?php echo esc_attr(get_option('ptp_fb_pixel_id')); ?>" placeholder="123456789012345">
                            <p style="color:#6B7280;font-size:12px;margin-top:5px">Find in Facebook Events Manager → Data Sources → Your Pixel</p>
                        </div>
                        <div class="ptp-form-row">
                            <label>Conversions API Access Token (Optional)</label>
                            <input type="password" name="ptp_fb_access_token" value="<?php echo esc_attr(get_option('ptp_fb_access_token')); ?>" placeholder="EAABs...">
                            <p style="color:#6B7280;font-size:12px;margin-top:5px">For server-side tracking. Improves accuracy after iOS 14.5</p>
                        </div>
                        
                        <h4 style="margin:24px 0 12px;padding-top:20px;border-top:1px solid #E5E7EB">Google Analytics 4</h4>
                        <div class="ptp-form-row">
                            <label>Measurement ID</label>
                            <input type="text" name="ptp_ga4_measurement_id" value="<?php echo esc_attr(get_option('ptp_ga4_measurement_id')); ?>" placeholder="G-XXXXXXXXXX">
                            <p style="color:#6B7280;font-size:12px;margin-top:5px">Find in GA4 → Admin → Data Streams → Your Stream</p>
                        </div>
                        
                        <h4 style="margin:24px 0 12px;padding-top:20px;border-top:1px solid #E5E7EB">Google Ads</h4>
                        <div class="ptp-form-row">
                            <label>Conversion ID</label>
                            <input type="text" name="ptp_google_ads_id" value="<?php echo esc_attr(get_option('ptp_google_ads_id')); ?>" placeholder="AW-123456789">
                        </div>
                        <div class="ptp-form-row">
                            <label>Conversion Label</label>
                            <input type="text" name="ptp_google_ads_conversion_label" value="<?php echo esc_attr(get_option('ptp_google_ads_conversion_label')); ?>" placeholder="AbCdEfGhIjK">
                            <p style="color:#6B7280;font-size:12px;margin-top:5px">Find in Google Ads → Tools → Conversions → Your Conversion</p>
                        </div>
                        
                        <h4 style="margin:24px 0 12px;padding-top:20px;border-top:1px solid #E5E7EB">TikTok Pixel</h4>
                        <div class="ptp-form-row">
                            <label>Pixel ID</label>
                            <input type="text" name="ptp_tiktok_pixel_id" value="<?php echo esc_attr(get_option('ptp_tiktok_pixel_id')); ?>" placeholder="CXXXXXXXXX">
                            <p style="color:#6B7280;font-size:12px;margin-top:5px">Find in TikTok Ads Manager → Events → Web Events</p>
                        </div>
                    </div>
                </div>
                <p><button type="submit" class="ptp-btn ptp-btn-primary ptp-btn-lg">Save Pixel Settings</button></p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /* =========================================================================
       HANDLE ACTIONS
       ========================================================================= */
    public function handle_actions() {
        // SECURITY: Require admin capability for all actions
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (!isset($_GET['action']) && !isset($_POST['trainer_id'])) return;
        
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        
        global $wpdb;
        
        // Handle trainer profile update (POST)
        if ($action === 'update_trainer' && isset($_POST['trainer_id']) && wp_verify_nonce($_POST['ptp_trainer_nonce'] ?? '', 'ptp_update_trainer')) {
            $trainer_id = absint($_POST['trainer_id']);
            
            // v134: Check if status is changing to active (for approval email)
            $old_trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT status, email, display_name FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $trainer_id
            ));
            $old_status = $old_trainer ? $old_trainer->status : '';
            $new_status = sanitize_text_field($_POST['status'] ?? 'pending');
            
            // v200: Process training_locations from checkboxes into JSON
            $raw_locations = isset($_POST['training_locations']) ? (array) $_POST['training_locations'] : array();
            $clean_locations = array();
            $ptp_location_master = self::get_ptp_training_locations();
            foreach ($raw_locations as $loc_key) {
                $loc_key = sanitize_text_field($loc_key);
                if (isset($ptp_location_master[$loc_key])) {
                    $clean_locations[] = $ptp_location_master[$loc_key];
                }
            }
            // v212: Process custom locations with Google Maps data
            $custom_locs_json = isset($_POST['custom_locations_json']) ? (array) $_POST['custom_locations_json'] : array();
            foreach ($custom_locs_json as $cj) {
                $decoded = json_decode(stripslashes($cj), true);
                if ($decoded && !empty($decoded['name'])) {
                    $clean_locations[] = array(
                        'name' => sanitize_text_field($decoded['name']),
                        'address' => sanitize_text_field($decoded['address'] ?? ''),
                        'lat' => floatval($decoded['lat'] ?? 0),
                        'lng' => floatval($decoded['lng'] ?? 0),
                        'custom' => true,
                    );
                }
            }
            $training_locations_json = !empty($clean_locations) ? wp_json_encode($clean_locations) : '';

            $update_data = array(
                'display_name' => sanitize_text_field($_POST['display_name'] ?? ''),
                'headline' => sanitize_text_field($_POST['headline'] ?? ''),
                'location' => sanitize_text_field($_POST['location'] ?? ''),
                'hourly_rate' => floatval($_POST['hourly_rate'] ?? 75),
                'college' => sanitize_text_field($_POST['college'] ?? ''),
                'playing_level' => sanitize_text_field($_POST['playing_level'] ?? ''),
                'phone' => sanitize_text_field($_POST['phone'] ?? ''),
                'travel_radius' => intval($_POST['travel_radius'] ?? 15),
                'bio' => sanitize_textarea_field($_POST['bio'] ?? ''),
                'training_philosophy' => sanitize_textarea_field($_POST['training_philosophy'] ?? ''),
                'specialties' => sanitize_text_field($_POST['specialties'] ?? ''),
                'status' => $new_status,
                'is_featured' => intval($_POST['is_featured'] ?? 0),
                'sort_order' => intval($_POST['sort_order'] ?? 0),
                'training_locations' => $training_locations_json,
                'photo_url' => esc_url_raw($_POST['photo_url'] ?? ''),
                'cover_photo_url' => esc_url_raw($_POST['cover_photo_url'] ?? ''),
            );
            
            // v200: Update slug if provided
            $slug = sanitize_title($_POST['slug'] ?? '');
            if (!empty($slug)) {
                $update_data['slug'] = $slug;
            } elseif (empty($old_trainer->slug ?? '')) {
                // Auto-generate slug from display_name if none exists
                $update_data['slug'] = sanitize_title($update_data['display_name']);
            }
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                $update_data,
                array('id' => $trainer_id)
            );
            
            // v178: Fire hook which handles both email (send_trainer_approval_email) and SMS (send_trainer_welcome)
            // Removed duplicate direct wp_mail call — the hook's HTML email is better formatted
            if ($old_status !== 'active' && $new_status === 'active' && $old_trainer) {
                ptp_log("PTP: Status changed to active for trainer #{$trainer_id} — firing ptp_trainer_approved hook");
                do_action('ptp_trainer_approved', $trainer_id);
            }
            
            // Clear trainer cache
            wp_cache_delete('ptp_trainer_' . $trainer_id, 'ptp');
            
            wp_safe_redirect(admin_url('admin.php?page=ptp-trainers&action=view&id=' . $trainer_id . '&updated=1'));
            exit;
        }
        
        // Trainer view action (no nonce needed for view)
        if ($action === 'view' && $id && isset($_GET['page']) && $_GET['page'] === 'ptp-trainers') {
            $this->trainer_detail_page($id);
            exit;
        }
        
        // Actions requiring nonce
        if (!isset($_GET['_wpnonce'])) return;
        
        // Trainer actions
        if (in_array($action, array('activate', 'deactivate', 'delete'), true) && wp_verify_nonce($_GET['_wpnonce'], 'ptp_trainer_action')) {
            $table = $wpdb->prefix . 'ptp_trainers';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) return;
            
            if ($action === 'delete') {
                // Get trainer info first
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $id
                ));
                
                if ($trainer) {
                    // Remove trainer role from user (don't delete user account)
                    if ($trainer->user_id) {
                        $user = get_user_by('ID', $trainer->user_id);
                        if ($user) {
                            $user->remove_role('ptp_trainer');
                        }
                    }
                    
                    // Delete related data
                    $wpdb->delete("{$wpdb->prefix}ptp_availability", array('trainer_id' => $id), array('%d'));
                    $wpdb->delete("{$wpdb->prefix}ptp_reviews", array('trainer_id' => $id), array('%d'));
                    
                    // Delete trainer record
                    $wpdb->delete("{$wpdb->prefix}ptp_trainers", array('id' => $id), array('%d'));
                }
                
                wp_safe_redirect(admin_url('admin.php?page=ptp-trainers&trainer_deleted=1'));
                exit;
            }
            
            $new_status = $action === 'activate' ? 'active' : 'inactive';
            $wpdb->update("{$wpdb->prefix}ptp_trainers", array('status' => $new_status), array('id' => $id), array('%s'), array('%d'));
            
            // Fire action for referral tracking when trainer is activated
            if ($action === 'activate') {
                do_action('ptp_trainer_approved', $id);
            }
            
            wp_safe_redirect(admin_url('admin.php?page=ptp-trainers&trainer_updated=1'));
            exit;
        }
        
        // Document verification actions
        if (in_array($action, array('verify_safesport', 'reject_safesport', 'verify_background', 'reject_background'), true) && wp_verify_nonce($_GET['_wpnonce'], 'ptp_verify_doc')) {
            $table = $wpdb->prefix . 'ptp_trainers';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) return;
            
            if ($action === 'verify_safesport') {
                $wpdb->update($table, array('safesport_verified' => 1, 'is_background_checked' => 1), array('id' => $id), array('%d', '%d'), array('%d'));
            } elseif ($action === 'reject_safesport') {
                $wpdb->update($table, array('safesport_verified' => 0, 'safesport_doc_url' => ''), array('id' => $id), array('%d', '%s'), array('%d'));
            } elseif ($action === 'verify_background') {
                $wpdb->update($table, array('background_verified' => 1), array('id' => $id), array('%d'), array('%d'));
            } elseif ($action === 'reject_background') {
                $wpdb->update($table, array('background_verified' => 0, 'background_doc_url' => ''), array('id' => $id), array('%d', '%s'), array('%d'));
            }
            
            wp_safe_redirect(admin_url('admin.php?page=ptp-trainers&action=view&id=' . $id . '&doc_updated=1'));
            exit;
        }
        
        // Application actions (approve/reject/delete) — must run here on admin_init before output
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page === 'ptp-applications' && in_array($action, array('approve', 'reject', 'delete'), true) && $id && wp_verify_nonce($_GET['_wpnonce'], 'ptp_app_action')) {
            if ($action === 'approve') {
                $result = $this->approve_application($id);
                if ($result) {
                    wp_safe_redirect(admin_url('admin.php?page=ptp-applications&message=approved'));
                } else {
                    wp_safe_redirect(admin_url('admin.php?page=ptp-applications&message=error&error_type=approval_failed'));
                }
                exit;
            } elseif ($action === 'reject') {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_applications',
                    array('status' => 'rejected', 'reviewed_at' => current_time('mysql'), 'reviewed_by' => get_current_user_id()),
                    array('id' => $id)
                );
                $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_applications WHERE id = %d", $id));
                if ($app && class_exists('PTP_Email')) {
                    PTP_Email::send_application_rejected($app->email, $app->name);
                }
                wp_safe_redirect(admin_url('admin.php?page=ptp-applications&message=rejected'));
                exit;
            } elseif ($action === 'delete') {
                $wpdb->delete($wpdb->prefix . 'ptp_applications', array('id' => $id));
                wp_safe_redirect(admin_url('admin.php?page=ptp-applications&message=deleted'));
                exit;
            }
        }
    }
    
    /**
     * Trainer Detail / Compliance Page
     */
    private function trainer_detail_page($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare("
            SELECT t.*, u.user_email, u.user_registered 
            FROM {$wpdb->prefix}ptp_trainers t 
            JOIN {$wpdb->users} u ON t.user_id = u.ID 
            WHERE t.id = %d
        ", $trainer_id));
        
        if (!$trainer) {
            wp_die('Trainer not found');
        }
        
        // Get earnings
        $earnings = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ptp_payouts 
            WHERE trainer_id = %d AND status = 'completed'
        ", $trainer_id)) ?: 0;
        
        // Compliance checks
        $has_safesport = !empty($trainer->safesport_doc_url);
        $safesport_verified = !empty($trainer->safesport_verified);
        $has_background = !empty($trainer->background_doc_url);
        $background_verified = !empty($trainer->background_verified);
        $has_w9 = !empty($trainer->w9_submitted);
        $has_stripe = !empty($trainer->stripe_account_id) && !empty($trainer->stripe_charges_enabled);
        $compliance_complete = $safesport_verified && $has_w9;
        
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo admin_url('admin.php?page=ptp-trainers'); ?>">← Trainers</a> / 
                <?php echo esc_html($trainer->display_name); ?>
            </h1>
            
            <?php if (isset($_GET['doc_updated'])): ?>
            <div class="notice notice-success"><p>Document status updated!</p></div>
            <?php endif; ?>
            
            <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success"><p>✓ Trainer profile updated successfully!</p></div>
            <?php endif; ?>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <!-- Left Column: Profile Info -->
                <div>
                    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                        <h2 style="margin-top: 0;">👤 Profile Information</h2>
                        <table class="form-table">
                            <tr><th>Name</th><td><?php echo esc_html($trainer->display_name); ?></td></tr>
                            <tr><th>Legal Name</th><td><?php echo esc_html($trainer->legal_name ?: '-'); ?></td></tr>
                            <tr><th>Email</th><td><?php echo esc_html($trainer->user_email); ?></td></tr>
                            <tr><th>Phone</th><td><?php echo esc_html($trainer->phone ?: '-'); ?></td></tr>
                            <tr><th>Location</th><td><?php echo esc_html($trainer->location ?: '-'); ?></td></tr>
                            <?php 
                            $view_locations = $trainer->training_locations ? json_decode($trainer->training_locations, true) : [];
                            if (!empty($view_locations)): ?>
                            <tr><th>Training Locations</th><td>
                                <?php foreach ($view_locations as $vl): ?>
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; margin: 2px; border-radius: 100px; font-size: 12px; font-weight: 600; background: rgba(252,185,0,0.12); color: #92600A;">📍 <?php echo esc_html($vl['name'] ?? 'Unknown'); ?></span>
                                <?php endforeach; ?>
                            </td></tr>
                            <?php endif; ?>
                            <tr><th>Hourly Rate</th><td>$<?php echo number_format($trainer->hourly_rate, 0); ?></td></tr>
                            <tr><th>College</th><td><?php echo esc_html($trainer->college ?: '-'); ?></td></tr>
                            <tr><th>Status</th><td><span class="ptp-status ptp-status-<?php echo esc_attr($trainer->status); ?>"><?php echo ucfirst($trainer->status); ?></span></td></tr>
                            <tr><th>Joined</th><td><?php echo date('M j, Y', strtotime($trainer->created_at)); ?></td></tr>
                            <tr><th>Total Sessions</th><td><?php echo $trainer->total_sessions; ?></td></tr>
                            <tr><th>Total Earnings</th><td>$<?php echo number_format($earnings, 2); ?></td></tr>
                        </table>
                        
                        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #E5E7EB;">
                            <a href="<?php echo home_url('/trainer/' . $trainer->slug); ?>" class="button" target="_blank">View Public Profile</a>
                            <?php if ($trainer->status === 'active'): ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-trainers&action=deactivate&id=' . $trainer->id), 'ptp_trainer_action'); ?>" class="button">Deactivate</a>
                            <?php elseif ($compliance_complete): ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-trainers&action=activate&id=' . $trainer->id), 'ptp_trainer_action'); ?>" class="button button-primary">Activate Trainer</a>
                            <?php endif; ?>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-trainers&action=delete&id=' . $trainer->id), 'ptp_trainer_action'); ?>" class="button" style="color: #DC2626; border-color: #DC2626;" onclick="return confirm('⚠️ DELETE TRAINER: <?php echo esc_js($trainer->display_name); ?>\n\nThis will permanently delete this trainer record.\n\nAre you sure?');">🗑️ Delete Trainer</a>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Compliance -->
                <div>
                    <!-- Compliance Status -->
                    <div style="background: <?php echo $compliance_complete ? '#D1FAE5' : '#FEF3C7'; ?>; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h3 style="margin-top: 0; color: <?php echo $compliance_complete ? '#065F46' : '#92400E'; ?>;">
                            <?php echo $compliance_complete ? '✅ Compliance Complete' : '⚠️ Compliance Incomplete'; ?>
                        </h3>
                        <p style="margin: 0; color: <?php echo $compliance_complete ? '#065F46' : '#92400E'; ?>;">
                            <?php echo $compliance_complete ? 'This trainer has completed all required compliance steps and can be activated.' : 'This trainer needs to complete the items below before activation.'; ?>
                        </p>
                    </div>
                    
                    <!-- SafeSport Document -->
                    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                        <h3 style="margin-top: 0;">🛡️ SafeSport Certificate</h3>
                        <?php if ($has_safesport): ?>
                            <div style="margin-bottom: 15px;">
                                <a href="<?php echo esc_url($trainer->safesport_doc_url); ?>" target="_blank" class="button">📄 View Document</a>
                            </div>
                            <?php if ($safesport_verified): ?>
                                <div style="background: #D1FAE5; padding: 12px; border-radius: 6px; color: #065F46;">
                                    ✅ <strong>Verified</strong> - Document has been reviewed and approved
                                </div>
                            <?php else: ?>
                                <div style="background: #FEF3C7; padding: 12px; border-radius: 6px; color: #92400E; margin-bottom: 10px;">
                                    ⏳ <strong>Pending Review</strong> - Please review the document
                                </div>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-trainers&action=verify_safesport&id=' . $trainer->id), 'ptp_verify_doc'); ?>" class="button button-primary">✓ Verify & Approve</a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-trainers&action=reject_safesport&id=' . $trainer->id), 'ptp_verify_doc'); ?>" class="button" onclick="return confirm('Reject this document? The trainer will need to upload a new one.');">✗ Reject</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="background: #FEE2E2; padding: 12px; border-radius: 6px; color: #DC2626;">
                                ❌ <strong>Not Submitted</strong> - Trainer has not uploaded a SafeSport certificate
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Background Check -->
                    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                        <h3 style="margin-top: 0;">📋 Background Check</h3>
                        <?php if ($has_background): ?>
                            <div style="margin-bottom: 15px;">
                                <a href="<?php echo esc_url($trainer->background_doc_url); ?>" target="_blank" class="button">📄 View Document</a>
                            </div>
                            <?php if ($background_verified): ?>
                                <div style="background: #D1FAE5; padding: 12px; border-radius: 6px; color: #065F46;">
                                    ✅ <strong>Verified</strong>
                                </div>
                            <?php else: ?>
                                <div style="background: #FEF3C7; padding: 12px; border-radius: 6px; color: #92400E; margin-bottom: 10px;">
                                    ⏳ <strong>Pending Review</strong>
                                </div>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-trainers&action=verify_background&id=' . $trainer->id), 'ptp_verify_doc'); ?>" class="button button-primary">✓ Verify</a>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=ptp-trainers&action=reject_background&id=' . $trainer->id), 'ptp_verify_doc'); ?>" class="button">✗ Reject</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="background: #F3F4F6; padding: 12px; border-radius: 6px; color: #6B7280;">
                                — <strong>Not Submitted</strong> (Optional)
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- W-9 Tax Info -->
                    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                        <h3 style="margin-top: 0;">📝 W-9 / Tax Information</h3>
                        <?php if ($has_w9): ?>
                            <div style="background: #D1FAE5; padding: 12px; border-radius: 6px; color: #065F46; margin-bottom: 15px;">
                                ✅ <strong>W-9 Submitted</strong> on <?php echo date('M j, Y', strtotime($trainer->w9_submitted_at)); ?>
                            </div>
                            <table class="form-table" style="margin: 0;">
                                <tr><th>Legal Name</th><td><?php echo esc_html($trainer->legal_name); ?></td></tr>
                                <tr><th>Tax ID</th><td><?php echo strtoupper($trainer->tax_id_type ?: 'SSN'); ?>: ***-**-<?php echo esc_html($trainer->tax_id_last4); ?></td></tr>
                                <tr><th>Address</th><td>
                                    <?php echo esc_html($trainer->tax_address_line1); ?><br>
                                    <?php if ($trainer->tax_address_line2) echo esc_html($trainer->tax_address_line2) . '<br>'; ?>
                                    <?php echo esc_html($trainer->tax_city . ', ' . $trainer->tax_state . ' ' . $trainer->tax_zip); ?>
                                </td></tr>
                            </table>
                        <?php else: ?>
                            <div style="background: #FEE2E2; padding: 12px; border-radius: 6px; color: #DC2626;">
                                ❌ <strong>Not Submitted</strong> - Required for 1099 reporting
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Contractor Agreement -->
                    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                        <h3 style="margin-top: 0;">📜 Contractor Agreement</h3>
                        <?php if (!empty($trainer->contractor_agreement_signed)): ?>
                            <div style="background: #D1FAE5; padding: 12px; border-radius: 6px; color: #065F46;">
                                ✅ <strong>Signed</strong> on <?php echo date('M j, Y \a\t g:i A', strtotime($trainer->contractor_agreement_signed_at)); ?>
                            </div>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #6B7280;">
                                IP Address: <?php echo esc_html($trainer->contractor_agreement_ip ?: 'N/A'); ?>
                            </p>
                        <?php else: ?>
                            <div style="background: #FEE2E2; padding: 12px; border-radius: 6px; color: #DC2626;">
                                ❌ <strong>Not Signed</strong> - Trainer must agree to terms
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Stripe Payouts -->
                    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3 style="margin-top: 0;">💳 Stripe Payouts</h3>
                        <?php if ($has_stripe): ?>
                            <div style="background: #D1FAE5; padding: 12px; border-radius: 6px; color: #065F46;">
                                ✅ <strong>Connected</strong> - Trainer can receive direct deposits
                            </div>
                            <p style="margin: 10px 0 0; font-size: 13px; color: #6B7280;">
                                Account ID: <?php echo esc_html(substr($trainer->stripe_account_id, 0, 12) . '...'); ?>
                            </p>
                        <?php elseif ($trainer->stripe_account_id): ?>
                            <div style="background: #FEF3C7; padding: 12px; border-radius: 6px; color: #92400E;">
                                ⏳ <strong>Setup Incomplete</strong> - Trainer needs to finish Stripe onboarding
                            </div>
                        <?php else: ?>
                            <div style="background: #F3F4F6; padding: 12px; border-radius: 6px; color: #6B7280;">
                                — <strong>Not Connected</strong> - Trainer will be prompted to connect Stripe
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Full Width Sections -->
            <div style="grid-column: 1 / -1;">
                <!-- Edit Trainer Profile -->
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                    <h2 style="margin-top: 0;">✏️ Edit Profile</h2>
                    <form method="post" action="<?php echo admin_url('admin.php?page=ptp-trainers&action=update_trainer&id=' . $trainer->id); ?>">
                        <?php wp_nonce_field('ptp_update_trainer', 'ptp_trainer_nonce'); ?>
                        <input type="hidden" name="trainer_id" value="<?php echo $trainer->id; ?>">
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <!-- v200: Photo & Cover Photo uploads -->
                            <div style="grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div>
                                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Profile Photo</label>
                                    <div id="ptpPhotoPreview" style="width:120px;height:120px;border-radius:12px;border:2px dashed #d1d5db;overflow:hidden;margin-bottom:8px;display:flex;align-items:center;justify-content:center;background:#f9fafb;cursor:pointer" onclick="ptpSelectMedia('photo_url','ptpPhotoPreview')">
                                        <?php if (!empty($trainer->photo_url)): ?>
                                        <img src="<?php echo esc_url($trainer->photo_url); ?>" style="width:100%;height:100%;object-fit:cover">
                                        <?php else: ?>
                                        <span style="color:#9CA3AF;font-size:12px;text-align:center">Click to<br>upload</span>
                                        <?php endif; ?>
                                    </div>
                                    <input type="hidden" name="photo_url" id="photo_url" value="<?php echo esc_attr($trainer->photo_url ?? ''); ?>">
                                    <button type="button" class="button button-small" onclick="ptpSelectMedia('photo_url','ptpPhotoPreview')">Choose Photo</button>
                                    <?php if (!empty($trainer->photo_url)): ?>
                                    <button type="button" class="button button-small" onclick="document.getElementById('photo_url').value='';document.getElementById('ptpPhotoPreview').innerHTML='<span style=color:#9CA3AF;font-size:12px;text-align:center>Click to<br>upload</span>'" style="color:#DC2626">Remove</button>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cover Photo <span style="font-weight:400;color:#6B7280">(hero banner)</span></label>
                                    <div id="ptpCoverPreview" style="width:240px;height:120px;border-radius:12px;border:2px dashed #d1d5db;overflow:hidden;margin-bottom:8px;display:flex;align-items:center;justify-content:center;background:#f9fafb;cursor:pointer" onclick="ptpSelectMedia('cover_photo_url','ptpCoverPreview')">
                                        <?php if (!empty($trainer->cover_photo_url)): ?>
                                        <img src="<?php echo esc_url($trainer->cover_photo_url); ?>" style="width:100%;height:100%;object-fit:cover">
                                        <?php else: ?>
                                        <span style="color:#9CA3AF;font-size:12px;text-align:center">Click to upload<br>cover image</span>
                                        <?php endif; ?>
                                    </div>
                                    <input type="hidden" name="cover_photo_url" id="cover_photo_url" value="<?php echo esc_attr($trainer->cover_photo_url ?? ''); ?>">
                                    <button type="button" class="button button-small" onclick="ptpSelectMedia('cover_photo_url','ptpCoverPreview')">Choose Cover</button>
                                    <?php if (!empty($trainer->cover_photo_url)): ?>
                                    <button type="button" class="button button-small" onclick="document.getElementById('cover_photo_url').value='';document.getElementById('ptpCoverPreview').innerHTML='<span style=color:#9CA3AF;font-size:12px;text-align:center>Click to upload<br>cover image</span>'" style="color:#DC2626">Remove</button>
                                    <?php endif; ?>
                                    <p style="margin-top:6px;font-size:11px;color:#6B7280">Recommended: 1920x800px or larger. This is the big photo on the trainer profile page.</p>
                                </div>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Display Name</label>
                                <input type="text" name="display_name" value="<?php echo esc_attr($trainer->display_name); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Profile Slug</label>
                                <div style="display:flex;align-items:center;gap:0">
                                    <span style="padding:8px 10px;background:#f3f4f6;border:1px solid #ddd;border-right:0;border-radius:6px 0 0 6px;font-size:12px;color:#6B7280;white-space:nowrap">/trainer/</span>
                                    <input type="text" name="slug" value="<?php echo esc_attr($trainer->slug ?? ''); ?>" placeholder="<?php echo esc_attr(sanitize_title($trainer->display_name)); ?>" style="flex:1;padding:8px 12px;border:1px solid #ddd;border-radius:0 6px 6px 0;">
                                </div>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Headline</label>
                                <input type="text" name="headline" value="<?php echo esc_attr($trainer->headline ?? ''); ?>" placeholder="e.g., NCAA D1 Player at Villanova" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Location</label>
                                <input type="text" name="location" value="<?php echo esc_attr($trainer->location ?? ''); ?>" placeholder="City, State" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Hourly Rate ($)</label>
                                <input type="number" name="hourly_rate" value="<?php echo esc_attr($trainer->hourly_rate ?? 75); ?>" min="25" max="300" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">College/Team</label>
                                <input type="text" name="college" value="<?php echo esc_attr($trainer->college ?? ''); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Playing Level</label>
                                <select name="playing_level" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                                    <option value="">Select Level</option>
                                    <option value="pro" <?php selected($trainer->playing_level ?? '', 'pro'); ?>>Professional</option>
                                    <option value="college_d1" <?php selected($trainer->playing_level ?? '', 'college_d1'); ?>>NCAA D1</option>
                                    <option value="college_d2" <?php selected($trainer->playing_level ?? '', 'college_d2'); ?>>NCAA D2</option>
                                    <option value="college_d3" <?php selected($trainer->playing_level ?? '', 'college_d3'); ?>>NCAA D3</option>
                                    <option value="academy" <?php selected($trainer->playing_level ?? '', 'academy'); ?>>MLS/NWSL Academy</option>
                                    <option value="semi_pro" <?php selected($trainer->playing_level ?? '', 'semi_pro'); ?>>Semi-Pro</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Phone</label>
                                <input type="text" name="phone" value="<?php echo esc_attr($trainer->phone ?? ''); ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Travel Radius (miles)</label>
                                <input type="number" name="travel_radius" value="<?php echo esc_attr($trainer->travel_radius ?? 15); ?>" min="5" max="100" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <label style="display: block; font-weight: 600; margin-bottom: 8px;">Training Locations</label>
                                <p style="color: #6B7280; font-size: 13px; margin-bottom: 10px;">Select all locations where this trainer is available for sessions. Selected locations appear on their public profile.</p>
                                <?php
                                $saved_locations = $trainer->training_locations ? json_decode($trainer->training_locations, true) : [];
                                $saved_keys = array();
                                if (!empty($saved_locations)) {
                                    foreach ($saved_locations as $sl) {
                                        $saved_keys[] = sanitize_title($sl['name'] ?? '');
                                    }
                                }
                                $master_locations = self::get_ptp_training_locations();
                                ?>
                                <!-- v200: Google Maps view -->
                                <div id="ptpLocationsMap" style="width:100%;height:320px;border-radius:12px;border:1px solid #e5e7eb;margin-bottom:16px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;">
                                    <span style="color:#9CA3AF;font-size:13px">Loading map...</span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                    <?php foreach ($master_locations as $key => $loc): 
                                        $checked = in_array($key, $saved_keys) ? 'checked' : '';
                                    ?>
                                    <label class="ptp-loc-label" data-key="<?php echo esc_attr($key); ?>" data-lat="<?php echo esc_attr($loc['lat']); ?>" data-lng="<?php echo esc_attr($loc['lng']); ?>" style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; border: 1px solid <?php echo $checked ? '#FCB900' : '#E5E7EB'; ?>; border-radius: 8px; cursor: pointer; background: <?php echo $checked ? 'rgba(252,185,0,0.06)' : '#fff'; ?>; transition: all 0.2s;">
                                        <input type="checkbox" name="training_locations[]" value="<?php echo esc_attr($key); ?>" <?php echo $checked; ?> style="accent-color: #FCB900;" onchange="ptpToggleLocation(this)">
                                        <span>
                                            <strong style="font-size: 13px;"><?php echo esc_html($loc['name']); ?></strong>
                                            <span style="display: block; font-size: 11px; color: #6B7280;"><?php echo esc_html($loc['address']); ?></span>
                                        </span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- v212: Custom Locations with Google Places Autocomplete -->
                                <div style="margin-top:20px;border-top:1px solid #E5E7EB;padding-top:16px">
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                                        <div>
                                            <strong style="font-size:14px;">Custom Locations</strong>
                                            <p style="color:#6B7280;font-size:12px;margin:2px 0 0">Add real training locations with Google Maps</p>
                                        </div>
                                        <button type="button" onclick="ptpShowCustomLocForm()" id="ptpAddCustomLocBtn" class="button button-small" style="white-space:nowrap">+ Add Location</button>
                                    </div>
                                    
                                    <!-- Add custom location form (hidden by default) -->
                                    <div id="ptpCustomLocForm" style="display:none;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:16px;margin-bottom:12px">
                                        <div style="margin-bottom:10px">
                                            <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px">Location Name</label>
                                            <input type="text" id="ptpCustomLocName" placeholder="e.g. Springfield Soccer Complex" style="width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px">
                                        </div>
                                        <div style="margin-bottom:10px">
                                            <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px">Address (search)</label>
                                            <input type="text" id="ptpCustomLocAddress" placeholder="Start typing an address..." style="width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px" autocomplete="off">
                                            <input type="hidden" id="ptpCustomLocLat" value="">
                                            <input type="hidden" id="ptpCustomLocLng" value="">
                                            <input type="hidden" id="ptpCustomLocFullAddr" value="">
                                        </div>
                                        <div id="ptpCustomLocMapPreview" style="width:100%;height:200px;border-radius:8px;border:1px solid #E5E7EB;margin-bottom:10px;background:#f3f4f6;display:none"></div>
                                        <div style="display:flex;gap:8px">
                                            <button type="button" onclick="ptpSaveCustomLoc()" class="button button-primary button-small">Save Location</button>
                                            <button type="button" onclick="ptpCancelCustomLoc()" class="button button-small">Cancel</button>
                                        </div>
                                    </div>
                                    
                                    <!-- Saved custom locations list -->
                                    <div id="ptpCustomLocsList">
                                        <?php
                                        // Show existing custom locations
                                        if (!empty($saved_locations)) {
                                            foreach ($saved_locations as $sl) {
                                                if (!empty($sl['custom'])) {
                                                    $loc_json = wp_json_encode(array(
                                                        'name' => $sl['name'],
                                                        'address' => $sl['address'] ?? '',
                                                        'lat' => $sl['lat'] ?? 0,
                                                        'lng' => $sl['lng'] ?? 0,
                                                    ));
                                                    echo '<div class="ptp-custom-loc-item" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid #22C55E;border-radius:8px;background:rgba(34,197,94,0.04);margin-bottom:8px" data-lat="' . esc_attr($sl['lat'] ?? 0) . '" data-lng="' . esc_attr($sl['lng'] ?? 0) . '">';
                                                    echo '<span style="display:flex;align-items:center;justify-content:center;width:32px;height:32px;background:#FCB900;border-radius:6px;flex-shrink:0"><svg width="16" height="16" fill="none" stroke="#0A0A0A" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg></span>';
                                                    echo '<span style="flex:1;min-width:0"><strong style="font-size:13px;display:block">' . esc_html($sl['name']) . '</strong><span style="font-size:11px;color:#6B7280;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . esc_html($sl['address'] ?? '') . '</span></span>';
                                                    echo '<input type="hidden" name="custom_locations_json[]" value="' . esc_attr($loc_json) . '">';
                                                    echo '<button type="button" onclick="ptpRemoveCustomLoc(this)" style="background:none;border:none;color:#EF4444;cursor:pointer;font-size:18px;padding:4px;line-height:1" title="Remove">&times;</button>';
                                                    echo '</div>';
                                                }
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Bio</label>
                                <textarea name="bio" rows="4" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;"><?php echo esc_textarea($trainer->bio ?? ''); ?></textarea>
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Training Philosophy</label>
                                <textarea name="training_philosophy" rows="3" placeholder="What makes this trainer's approach unique? Their coaching style, methodology, etc." style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;"><?php echo esc_textarea($trainer->training_philosophy ?? ''); ?></textarea>
                                <p style="margin-top:4px;font-size:11px;color:#6B7280">Shown on the trainer's public profile page.</p>
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Specialties (comma-separated)</label>
                                <input type="text" name="specialties" value="<?php echo esc_attr($trainer->specialties ?? ''); ?>" placeholder="shooting, dribbling, passing, defense" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Status</label>
                                <select name="status" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                                    <option value="active" <?php selected($trainer->status, 'active'); ?>>Active</option>
                                    <option value="pending" <?php selected($trainer->status, 'pending'); ?>>Pending</option>
                                    <option value="suspended" <?php selected($trainer->status, 'suspended'); ?>>Suspended</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Featured Trainer</label>
                                <select name="is_featured" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                                    <option value="0" <?php selected($trainer->is_featured ?? 0, 0); ?>>No</option>
                                    <option value="1" <?php selected($trainer->is_featured ?? 0, 1); ?>>Yes - Featured</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Sort Order</label>
                                <input type="number" name="sort_order" value="<?php echo intval($trainer->sort_order ?? 0); ?>" min="0" max="999" style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px;">
                                <small style="color: #6B7280;">Lower = higher ranking. Manage in <a href="<?php echo admin_url('admin.php?page=ptp-trainer-ranking'); ?>">Ranking</a></small>
                            </div>
                        </div>
                        <p style="margin-top: 20px;">
                            <button type="submit" class="button button-primary button-large">Save Changes</button>
                        </p>
                    </form>
                    
                    <!-- v216: Admin Schedule Management -->
                    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top: 20px;">
                        <h2 style="margin-top: 0;">
                            <span style="margin-right:6px">&#128197;</span> Schedule Management
                        </h2>
                        <p style="color: #6B7280; font-size: 13px; margin-bottom: 16px;">
                            Edit this trainer's weekly availability and blocked dates. Changes take effect immediately on the public profile.
                        </p>
                        
                        <div id="adminScheduleContainer">
                            <div style="text-align:center;padding:20px"><span class="spinner is-active" style="float:none;margin:0 auto"></span></div>
                        </div>
                        
                        <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #E5E7EB;">
                            <h3 style="margin-top:0;font-size:14px">Blocked Dates</h3>
                            <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:end">
                                <div>
                                    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:3px">Date</label>
                                    <input type="date" id="adminBlockDate" min="<?php echo date('Y-m-d'); ?>" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px">
                                </div>
                                <div>
                                    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:3px">Reason</label>
                                    <input type="text" id="adminBlockReason" placeholder="e.g. Vacation" style="padding:6px 10px;border:1px solid #ddd;border-radius:6px;width:200px">
                                </div>
                                <button type="button" class="button" onclick="ptpAdminBlockDate(<?php echo intval($trainer_id); ?>)">Block Date</button>
                            </div>
                            <div id="adminBlockedList" style="max-height:300px;overflow-y:auto"></div>
                        </div>
                    </div>
                    
                    <script>
                    (function() {
                        var trainerId = <?php echo intval($trainer_id); ?>;
                        var adminNonce = '<?php echo wp_create_nonce('ptp_admin_nonce'); ?>';
                        var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                        var dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                        
                        function loadAdminSchedule() {
                            var container = document.getElementById('adminScheduleContainer');
                            
                            jQuery.post(ajaxUrl, {
                                action: 'ptp_admin_get_trainer_schedule',
                                trainer_id: trainerId,
                                nonce: adminNonce
                            }, function(res) {
                                if (!res.success) {
                                    container.innerHTML = '<p style="color:#DC2626">Error loading schedule</p>';
                                    return;
                                }
                                
                                var schedule = res.data.schedule;
                                var html = '<table class="widefat fixed striped" style="margin-bottom:0"><thead><tr><th style="width:100px">Day</th><th style="width:70px">Active</th><th>Start</th><th>End</th><th style="width:80px">Save</th></tr></thead><tbody>';
                                
                                for (var d = 0; d < 7; d++) {
                                    var day = schedule[d] || { enabled: false, start: '09:00', end: '17:00' };
                                    html += '<tr id="adminDay' + d + '">';
                                    html += '<td><strong>' + dayNames[d] + '</strong></td>';
                                    html += '<td><input type="checkbox" id="adminEnabled' + d + '"' + (day.enabled ? ' checked' : '') + '></td>';
                                    html += '<td><input type="time" id="adminStart' + d + '" value="' + day.start + '" style="width:120px;padding:4px 8px;border:1px solid #ddd;border-radius:4px"></td>';
                                    html += '<td><input type="time" id="adminEnd' + d + '" value="' + day.end + '" style="width:120px;padding:4px 8px;border:1px solid #ddd;border-radius:4px"></td>';
                                    html += '<td><button type="button" class="button button-small" onclick="ptpAdminSaveDay(' + d + ')">Save</button></td>';
                                    html += '</tr>';
                                }
                                
                                html += '</tbody></table>';
                                
                                // Google Calendar status
                                if (res.data.gcal_connected) {
                                    html += '<div style="margin-top:12px;padding:10px;background:#ECFDF5;border-radius:6px;font-size:12px">';
                                    html += '<strong style="color:#065F46">Google Calendar Connected:</strong> ' + (res.data.gcal_email || 'Yes');
                                    if (res.data.gcal_last_sync) {
                                        html += ' &middot; Last sync: ' + res.data.gcal_last_sync;
                                    }
                                    html += '</div>';
                                }
                                
                                container.innerHTML = html;
                                loadAdminBlockedDates();
                            });
                        }
                        
                        function loadAdminBlockedDates() {
                            jQuery.post(ajaxUrl, {
                                action: 'ptp_admin_get_blocked_dates',
                                trainer_id: trainerId,
                                nonce: adminNonce
                            }, function(res) {
                                var list = document.getElementById('adminBlockedList');
                                if (!res.success || !res.data.blocked_dates || res.data.blocked_dates.length === 0) {
                                    list.innerHTML = '<p style="color:#9CA3AF;font-size:13px">No blocked dates</p>';
                                    return;
                                }
                                var html = '';
                                res.data.blocked_dates.forEach(function(b) {
                                    var dateObj = new Date(b.date + 'T12:00:00');
                                    var display = dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
                                    html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f3f4f6">';
                                    html += '<span style="font-size:13px"><strong>' + display + '</strong>';
                                    if (b.reason) html += ' &mdash; <span style="color:#6B7280">' + b.reason + '</span>';
                                    html += '</span>';
                                    html += '<button type="button" class="button button-small" style="color:#DC2626;border-color:#DC2626" onclick="ptpAdminUnblockDate(\'' + b.date + '\')">Remove</button>';
                                    html += '</div>';
                                });
                                list.innerHTML = html;
                            });
                        }
                        
                        window.ptpAdminSaveDay = function(day) {
                            var enabled = document.getElementById('adminEnabled' + day).checked ? '1' : '0';
                            var start = document.getElementById('adminStart' + day).value || '09:00';
                            var end = document.getElementById('adminEnd' + day).value || '17:00';
                            
                            var row = document.getElementById('adminDay' + day);
                            var btn = row.querySelector('button');
                            btn.textContent = '...';
                            btn.disabled = true;
                            
                            jQuery.post(ajaxUrl, {
                                action: 'ptp_admin_save_trainer_schedule',
                                trainer_id: trainerId,
                                day: day,
                                enabled: enabled,
                                start: start,
                                end: end,
                                nonce: adminNonce
                            }, function(res) {
                                btn.textContent = res.success ? 'Saved!' : 'Error';
                                btn.disabled = false;
                                if (res.success) {
                                    row.style.background = '#ECFDF5';
                                    setTimeout(function() { row.style.background = ''; btn.textContent = 'Save'; }, 1500);
                                }
                            });
                        };
                        
                        window.ptpAdminBlockDate = function(tid) {
                            var date = document.getElementById('adminBlockDate').value;
                            var reason = document.getElementById('adminBlockReason').value;
                            if (!date) { alert('Select a date'); return; }
                            
                            jQuery.post(ajaxUrl, {
                                action: 'ptp_admin_block_trainer_date',
                                trainer_id: tid,
                                date: date,
                                reason: reason,
                                nonce: adminNonce
                            }, function(res) {
                                if (res.success) {
                                    document.getElementById('adminBlockDate').value = '';
                                    document.getElementById('adminBlockReason').value = '';
                                    loadAdminBlockedDates();
                                } else {
                                    alert(res.data && res.data.message ? res.data.message : 'Error');
                                }
                            });
                        };
                        
                        window.ptpAdminUnblockDate = function(date) {
                            if (!confirm('Remove this blocked date?')) return;
                            jQuery.post(ajaxUrl, {
                                action: 'ptp_admin_unblock_trainer_date',
                                trainer_id: trainerId,
                                date: date,
                                nonce: adminNonce
                            }, function(res) {
                                if (res.success) loadAdminBlockedDates();
                            });
                        };
                        
                        // Load on page load
                        if (document.readyState === 'loading') {
                            document.addEventListener('DOMContentLoaded', loadAdminSchedule);
                        } else {
                            loadAdminSchedule();
                        }
                    })();
                    </script>
                    
                    <!-- v200: WP Media Uploader + Google Maps for Locations -->
                    <script>
                    /* WP Media Picker */
                    function ptpSelectMedia(fieldId, previewId) {
                        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
                            alert('Media library not available. Make sure you are in the WordPress admin.');
                            return;
                        }
                        var frame = wp.media({
                            title: fieldId === 'cover_photo_url' ? 'Select Cover Photo' : 'Select Profile Photo',
                            button: { text: 'Use This Image' },
                            multiple: false,
                            library: { type: 'image' }
                        });
                        frame.on('select', function() {
                            var attachment = frame.state().get('selection').first().toJSON();
                            var url = attachment.url;
                            // For cover, prefer 'full' size; for photo, prefer 'large'
                            if (fieldId === 'cover_photo_url' && attachment.sizes && attachment.sizes.full) {
                                url = attachment.sizes.full.url;
                            } else if (attachment.sizes && attachment.sizes.large) {
                                url = attachment.sizes.large.url;
                            }
                            document.getElementById(fieldId).value = url;
                            document.getElementById(previewId).innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover">';
                        });
                        frame.open();
                    }
                    // Enqueue WP media
                    if (typeof wp !== 'undefined' && typeof wp.media === 'undefined') {
                        var s = document.createElement('script');
                        s.src = '<?php echo includes_url('js/media-editor.min.js'); ?>';
                        document.head.appendChild(s);
                    }

                    /* Google Maps for Training Locations */
                    var ptpMap, ptpMarkers = {};
                    function ptpInitLocationsMap() {
                        var center = {lat: 40.02, lng: -75.35};
                        ptpMap = new google.maps.Map(document.getElementById('ptpLocationsMap'), {
                            center: center,
                            zoom: 10,
                            mapTypeControl: false,
                            streetViewControl: false,
                            fullscreenControl: true,
                            styles: [
                                {featureType:'poi',stylers:[{visibility:'off'}]},
                                {featureType:'transit',stylers:[{visibility:'off'}]}
                            ]
                        });

                        var bounds = new google.maps.LatLngBounds();
                        document.querySelectorAll('.ptp-loc-label').forEach(function(label) {
                            var key = label.dataset.key;
                            var lat = parseFloat(label.dataset.lat);
                            var lng = parseFloat(label.dataset.lng);
                            var isChecked = label.querySelector('input[type=checkbox]').checked;
                            var name = label.querySelector('strong').textContent;

                            var marker = new google.maps.Marker({
                                position: {lat: lat, lng: lng},
                                map: ptpMap,
                                title: name,
                                icon: {
                                    path: google.maps.SymbolPath.CIRCLE,
                                    scale: isChecked ? 12 : 8,
                                    fillColor: isChecked ? '#FCB900' : '#9CA3AF',
                                    fillOpacity: isChecked ? 1 : 0.5,
                                    strokeColor: isChecked ? '#92400E' : '#6B7280',
                                    strokeWeight: 2,
                                },
                                zIndex: isChecked ? 10 : 1
                            });

                            // Info window
                            var info = new google.maps.InfoWindow({
                                content: '<div style="font-family:Inter,system-ui,sans-serif;padding:4px"><strong style="font-size:14px">' + name + '</strong><br><span style="font-size:12px;color:#6B7280">' + label.querySelector('span span').textContent + '</span><br><label style="display:flex;align-items:center;gap:6px;margin-top:8px;cursor:pointer;font-size:13px;font-weight:600"><input type="checkbox" ' + (isChecked ? 'checked' : '') + ' onchange="ptpMapToggle(\'' + key + '\',this.checked)" style="accent-color:#FCB900;width:16px;height:16px">' + (isChecked ? 'Assigned' : 'Not assigned') + '</label></div>'
                            });
                            marker.addListener('click', function() { info.open(ptpMap, marker); });

                            ptpMarkers[key] = marker;
                            bounds.extend({lat: lat, lng: lng});
                        });

                        ptpMap.fitBounds(bounds, {top:30,bottom:30,left:30,right:30});
                    }

                    // Toggle location from map info window
                    function ptpMapToggle(key, checked) {
                        var label = document.querySelector('.ptp-loc-label[data-key="'+key+'"]');
                        if (label) {
                            var cb = label.querySelector('input[type=checkbox]');
                            cb.checked = checked;
                            ptpToggleLocation(cb);
                        }
                    }

                    // Toggle location visual state
                    function ptpToggleLocation(cb) {
                        var label = cb.closest('.ptp-loc-label') || cb.closest('label');
                        var key = label ? label.dataset.key : '';
                        if (cb.checked) {
                            label.style.borderColor = '#FCB900';
                            label.style.background = 'rgba(252,185,0,0.06)';
                        } else {
                            label.style.borderColor = '#E5E7EB';
                            label.style.background = '#fff';
                        }
                        // Update marker on map
                        if (ptpMarkers[key]) {
                            ptpMarkers[key].setIcon({
                                path: google.maps.SymbolPath.CIRCLE,
                                scale: cb.checked ? 12 : 8,
                                fillColor: cb.checked ? '#FCB900' : '#9CA3AF',
                                fillOpacity: cb.checked ? 1 : 0.5,
                                strokeColor: cb.checked ? '#92400E' : '#6B7280',
                                strokeWeight: 2,
                            });
                            ptpMarkers[key].setZIndex(cb.checked ? 10 : 1);
                        }
                    }

                    // Load Google Maps API
                    window.ptpGmapsReady = function() {
                        ptpInitLocationsMap();
                        ptpInitCustomLocAutocomplete();
                        // Add existing custom location markers
                        document.querySelectorAll('.ptp-custom-loc-item').forEach(function(item) {
                            var lat = parseFloat(item.dataset.lat);
                            var lng = parseFloat(item.dataset.lng);
                            if (lat && lng && ptpMap) {
                                new google.maps.Marker({
                                    position: {lat: lat, lng: lng},
                                    map: ptpMap,
                                    title: item.querySelector('strong').textContent,
                                    icon: {
                                        path: google.maps.SymbolPath.CIRCLE,
                                        scale: 14,
                                        fillColor: '#22C55E',
                                        fillOpacity: 1,
                                        strokeColor: '#166534',
                                        strokeWeight: 2,
                                    },
                                    zIndex: 15
                                });
                            }
                        });
                    };

                    // v212: Custom Location Functions
                    var ptpCustomAutocomplete = null, ptpCustomPreviewMap = null, ptpCustomPreviewMarker = null;

                    function ptpInitCustomLocAutocomplete() {
                        var input = document.getElementById('ptpCustomLocAddress');
                        if (!input || !google.maps.places) return;
                        ptpCustomAutocomplete = new google.maps.places.Autocomplete(input, {
                            types: ['establishment', 'geocode'],
                            componentRestrictions: {country: 'us'},
                            fields: ['formatted_address', 'geometry', 'name']
                        });
                        ptpCustomAutocomplete.addListener('place_changed', function() {
                            var place = ptpCustomAutocomplete.getPlace();
                            if (!place.geometry) return;
                            var lat = place.geometry.location.lat();
                            var lng = place.geometry.location.lng();
                            document.getElementById('ptpCustomLocLat').value = lat;
                            document.getElementById('ptpCustomLocLng').value = lng;
                            document.getElementById('ptpCustomLocFullAddr').value = place.formatted_address || input.value;
                            // Auto-fill name if empty
                            var nameField = document.getElementById('ptpCustomLocName');
                            if (!nameField.value && place.name) nameField.value = place.name;
                            // Show map preview
                            var previewEl = document.getElementById('ptpCustomLocMapPreview');
                            previewEl.style.display = 'block';
                            if (!ptpCustomPreviewMap) {
                                ptpCustomPreviewMap = new google.maps.Map(previewEl, {
                                    center: {lat: lat, lng: lng},
                                    zoom: 15,
                                    mapTypeControl: false,
                                    streetViewControl: false,
                                    fullscreenControl: false,
                                    zoomControl: true,
                                    styles: [{featureType:'poi',stylers:[{visibility:'off'}]}]
                                });
                            } else {
                                ptpCustomPreviewMap.setCenter({lat: lat, lng: lng});
                            }
                            if (ptpCustomPreviewMarker) ptpCustomPreviewMarker.setMap(null);
                            ptpCustomPreviewMarker = new google.maps.Marker({
                                position: {lat: lat, lng: lng},
                                map: ptpCustomPreviewMap,
                                icon: {
                                    path: google.maps.SymbolPath.CIRCLE,
                                    scale: 14,
                                    fillColor: '#22C55E',
                                    fillOpacity: 1,
                                    strokeColor: '#166534',
                                    strokeWeight: 2,
                                }
                            });
                        });
                    }

                    function ptpShowCustomLocForm() {
                        document.getElementById('ptpCustomLocForm').style.display = 'block';
                        document.getElementById('ptpAddCustomLocBtn').style.display = 'none';
                    }

                    function ptpCancelCustomLoc() {
                        document.getElementById('ptpCustomLocForm').style.display = 'none';
                        document.getElementById('ptpAddCustomLocBtn').style.display = '';
                        document.getElementById('ptpCustomLocName').value = '';
                        document.getElementById('ptpCustomLocAddress').value = '';
                        document.getElementById('ptpCustomLocLat').value = '';
                        document.getElementById('ptpCustomLocLng').value = '';
                        document.getElementById('ptpCustomLocFullAddr').value = '';
                        document.getElementById('ptpCustomLocMapPreview').style.display = 'none';
                    }

                    function ptpSaveCustomLoc() {
                        var name = document.getElementById('ptpCustomLocName').value.trim();
                        var address = document.getElementById('ptpCustomLocFullAddr').value || document.getElementById('ptpCustomLocAddress').value.trim();
                        var lat = parseFloat(document.getElementById('ptpCustomLocLat').value) || 0;
                        var lng = parseFloat(document.getElementById('ptpCustomLocLng').value) || 0;
                        if (!name) { alert('Please enter a location name.'); return; }
                        if (!address || !lat) { alert('Please search and select an address from the dropdown.'); return; }
                        var locData = JSON.stringify({name: name, address: address, lat: lat, lng: lng});
                        var html = '<div class="ptp-custom-loc-item" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid #22C55E;border-radius:8px;background:rgba(34,197,94,0.04);margin-bottom:8px" data-lat="'+lat+'" data-lng="'+lng+'">';
                        html += '<span style="display:flex;align-items:center;justify-content:center;width:32px;height:32px;background:#FCB900;border-radius:6px;flex-shrink:0"><svg width="16" height="16" fill="none" stroke="#0A0A0A" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg></span>';
                        html += '<span style="flex:1;min-width:0"><strong style="font-size:13px;display:block">'+name+'</strong><span style="font-size:11px;color:#6B7280;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+address+'</span></span>';
                        html += '<input type="hidden" name="custom_locations_json[]" value=\''+locData.replace(/'/g,"&#39;")+'\'/>';
                        html += '<button type="button" onclick="ptpRemoveCustomLoc(this)" style="background:none;border:none;color:#EF4444;cursor:pointer;font-size:18px;padding:4px;line-height:1" title="Remove">&times;</button>';
                        html += '</div>';
                        document.getElementById('ptpCustomLocsList').insertAdjacentHTML('beforeend', html);
                        // Add marker to main map
                        if (ptpMap && lat && lng) {
                            new google.maps.Marker({
                                position: {lat: lat, lng: lng},
                                map: ptpMap,
                                title: name,
                                icon: {
                                    path: google.maps.SymbolPath.CIRCLE,
                                    scale: 14,
                                    fillColor: '#22C55E',
                                    fillOpacity: 1,
                                    strokeColor: '#166534',
                                    strokeWeight: 2,
                                },
                                zIndex: 15
                            });
                        }
                        ptpCancelCustomLoc();
                    }

                    function ptpRemoveCustomLoc(btn) {
                        if (confirm('Remove this custom location?')) {
                            btn.closest('.ptp-custom-loc-item').remove();
                        }
                    }

                    (function(){
                        var gmapsKey = '<?php echo esc_js(get_option("ptp_google_maps_api_key", "") ?: get_option("ptp_google_maps_key", "")); ?>';
                        if (!gmapsKey) {
                            document.getElementById('ptpLocationsMap').innerHTML = '<div style="text-align:center;padding:20px"><p style="color:#6B7280;font-size:13px;margin:0">Google Maps API key not set.</p><p style="margin:4px 0 0;font-size:12px;color:#9CA3AF">Add your API key in PTP Settings to see the map.</p><p style="margin:8px 0 0"><a href="<?php echo admin_url("admin.php?page=ptp-settings"); ?>" class="button button-small">Go to Settings</a></p></div>';
                            return;
                        }
                        if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
                            ptpGmapsReady();
                            return;
                        }
                        var script = document.createElement('script');
                        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + gmapsKey + '&libraries=places&callback=ptpGmapsReady';
                        script.async = true;
                        script.defer = true;
                        document.head.appendChild(script);
                    })();
                    </script>
                    <?php wp_enqueue_media(); ?>
                </div>
                
                <!-- Stripe Transactions -->
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h2 style="margin-top: 0;">💳 Transaction History</h2>
                    <?php
                    // Get bookings with payment info
                    $transactions = $wpdb->get_results($wpdb->prepare("
                        SELECT b.*, p.name as player_name, par.display_name as parent_name
                        FROM {$wpdb->prefix}ptp_bookings b
                        LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
                        LEFT JOIN {$wpdb->prefix}ptp_parents par ON b.parent_id = par.id
                        WHERE b.trainer_id = %d AND b.payment_status IN ('paid', 'refunded')
                        ORDER BY b.created_at DESC
                        LIMIT 20
                    ", $trainer_id));
                    
                    if (!empty($transactions)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Session</th>
                                <th>Amount</th>
                                <th>Trainer Payout</th>
                                <th>Status</th>
                                <th>Stripe ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $txn): 
                                $platform_fee = get_option('ptp_platform_fee', 20);
                                $trainer_payout = $txn->total_amount * (1 - ($platform_fee / 100));
                            ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($txn->created_at)); ?></td>
                                <td><?php echo esc_html($txn->parent_name ?: 'N/A'); ?></td>
                                <td><?php echo date('M j', strtotime($txn->session_date)) . ' @ ' . date('g:i A', strtotime($txn->session_time)); ?></td>
                                <td><strong>$<?php echo number_format($txn->total_amount, 2); ?></strong></td>
                                <td style="color: #059669;">$<?php echo number_format($trainer_payout, 2); ?></td>
                                <td>
                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 100px; font-size: 12px; font-weight: 600; <?php 
                                        echo $txn->payment_status === 'paid' ? 'background: #D1FAE5; color: #065F46;' : 'background: #FEE2E2; color: #DC2626;'; 
                                    ?>">
                                        <?php echo $txn->payment_status === 'paid' ? '✓ Paid' : '↺ Refunded'; ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px; color: #6B7280;">
                                    <?php if (!empty($txn->stripe_payment_intent_id)): ?>
                                        <a href="https://dashboard.stripe.com/payments/<?php echo esc_attr($txn->stripe_payment_intent_id); ?>" target="_blank" style="color: #2563EB;">
                                            <?php echo esc_html(substr($txn->stripe_payment_intent_id, 0, 20)); ?>...
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php
                    // Calculate totals
                    $total_paid = array_sum(array_map(function($t) { return $t->payment_status === 'paid' ? $t->total_amount : 0; }, $transactions));
                    $total_refunded = array_sum(array_map(function($t) { return $t->payment_status === 'refunded' ? $t->total_amount : 0; }, $transactions));
                    $trainer_total = $total_paid * (1 - ($platform_fee / 100));
                    ?>
                    <div style="margin-top: 20px; padding: 20px; background: #F3F4F6; border-radius: 8px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; text-align: center;">
                        <div>
                            <div style="font-size: 24px; font-weight: 800; color: #111;">$<?php echo number_format($total_paid, 2); ?></div>
                            <div style="font-size: 13px; color: #6B7280;">Total Collected</div>
                        </div>
                        <div>
                            <div style="font-size: 24px; font-weight: 800; color: #DC2626;">$<?php echo number_format($total_refunded, 2); ?></div>
                            <div style="font-size: 13px; color: #6B7280;">Refunded</div>
                        </div>
                        <div>
                            <div style="font-size: 24px; font-weight: 800; color: #059669;">$<?php echo number_format($trainer_total, 2); ?></div>
                            <div style="font-size: 13px; color: #6B7280;">Trainer Earnings</div>
                        </div>
                        <div>
                            <div style="font-size: 24px; font-weight: 800; color: #FCB900;">$<?php echo number_format($total_paid - $trainer_total, 2); ?></div>
                            <div style="font-size: 13px; color: #6B7280;">Platform Fee (<?php echo $platform_fee; ?>%)</div>
                        </div>
                    </div>
                    
                    <?php else: ?>
                    <p style="color: #6B7280; text-align: center; padding: 40px;">No transactions yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /* =========================================================================
       TOOLS PAGE
       ========================================================================= */
    public function tools_page() {
        // Define all required pages
        $required_pages = array(
            'training' => array('title' => 'PTP Training', 'content' => '[ptp_home]'),
            'find-trainers' => array('title' => 'Find Trainers', 'content' => '[ptp_trainers_grid]'),
            'trainer' => array('title' => 'Trainer Profile', 'content' => '[ptp_trainer_profile]'),
            'book-session' => array('title' => 'Book Session', 'content' => '[ptp_booking_form]'),
            'booking-confirmation' => array('title' => 'Booking Confirmed', 'content' => '[ptp_booking_confirmation]'),
            'my-training' => array('title' => 'My Training', 'content' => '[ptp_my_training]'),
            'trainer-dashboard' => array('title' => 'Trainer Dashboard', 'content' => '[ptp_trainer_dashboard]'),
            'trainer-onboarding' => array('title' => 'Complete Your Profile', 'content' => '[ptp_trainer_onboarding]'),
            'messages' => array('title' => 'Messages', 'content' => '[ptp_messaging]'),
            'account' => array('title' => 'Account', 'content' => '[ptp_account]'),
            'login' => array('title' => 'Login', 'content' => '[ptp_login]'),
            'register' => array('title' => 'Register', 'content' => '[ptp_register]'),
            'apply' => array('title' => 'Become a Trainer', 'content' => '[ptp_apply]'),
            'parent-dashboard' => array('title' => 'Parent Dashboard', 'content' => '[ptp_parent_dashboard]'),
            'player-progress' => array('title' => 'Player Progress', 'content' => '[ptp_player_progress]'),
            'training-plans' => array('title' => 'Training Plans', 'content' => '[ptp_training_plans]'),
        );
        
        $message = '';
        $message_type = '';
        
        // Handle form submissions
        if (isset($_POST['ptp_tools_action']) && wp_verify_nonce($_POST['ptp_tools_nonce'], 'ptp_tools')) {
            $action = sanitize_text_field($_POST['ptp_tools_action']);
            
            if ($action === 'create_all') {
                $created = 0;
                foreach ($required_pages as $slug => $page) {
                    if (!get_page_by_path($slug)) {
                        wp_insert_post(array(
                            'post_title' => $page['title'],
                            'post_name' => $slug,
                            'post_content' => $page['content'],
                            'post_status' => 'publish',
                            'post_type' => 'page',
                        ));
                        $created++;
                    }
                }
                $message = $created > 0 ? "Created {$created} missing pages." : "All pages already exist.";
                $message_type = 'success';
            }
            
            if ($action === 'update_all') {
                $updated = 0;
                foreach ($required_pages as $slug => $page) {
                    $existing = get_page_by_path($slug);
                    if ($existing) {
                        wp_update_post(array(
                            'ID' => $existing->ID,
                            'post_content' => $page['content'],
                        ));
                        $updated++;
                    }
                }
                $message = "Updated {$updated} pages with correct shortcodes.";
                $message_type = 'success';
            }
            
            if ($action === 'recreate_all') {
                $recreated = 0;
                foreach ($required_pages as $slug => $page) {
                    $existing = get_page_by_path($slug);
                    if ($existing) {
                        wp_delete_post($existing->ID, true);
                    }
                    wp_insert_post(array(
                        'post_title' => $page['title'],
                        'post_name' => $slug,
                        'post_content' => $page['content'],
                        'post_status' => 'publish',
                        'post_type' => 'page',
                    ));
                    $recreated++;
                }
                $message = "Recreated all {$recreated} pages.";
                $message_type = 'success';
            }
            
            if ($action === 'create_single' && !empty($_POST['page_slug'])) {
                $slug = sanitize_text_field($_POST['page_slug']);
                if (isset($required_pages[$slug])) {
                    $existing = get_page_by_path($slug);
                    if ($existing) {
                        wp_delete_post($existing->ID, true);
                    }
                    wp_insert_post(array(
                        'post_title' => $required_pages[$slug]['title'],
                        'post_name' => $slug,
                        'post_content' => $required_pages[$slug]['content'],
                        'post_status' => 'publish',
                        'post_type' => 'page',
                    ));
                    $message = "Page '{$slug}' has been created/recreated.";
                    $message_type = 'success';
                }
            }
            
            if ($action === 'recreate_tables') {
                PTP_Database::create_tables();
                if (class_exists('PTP_SMS')) PTP_SMS::create_table();
                if (class_exists('PTP_Social')) PTP_Social::create_table();
                if (class_exists('PTP_Geocoding')) PTP_Geocoding::create_table();
                if (class_exists('PTP_Recurring')) PTP_Recurring::create_tables();
                if (class_exists('PTP_Groups')) PTP_Groups::create_tables();
                if (class_exists('PTP_Calendar_Sync')) PTP_Calendar_Sync::create_tables();
                if (class_exists('PTP_Training_Plans')) PTP_Training_Plans::create_tables();
                $message = "All database tables have been recreated/updated.";
                $message_type = 'success';
            }
            
            if ($action === 'add_indexes') {
                $count = PTP_Database::add_performance_indexes();
                $message = $count > 0 
                    ? "Added {$count} performance indexes to the database."
                    : "All performance indexes already exist.";
                $message_type = 'success';
            }
            
            if ($action === 'merge_duplicate_players') {
                global $wpdb;
                $total_merged = 0;
                
                // Get all parents
                $parents = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}ptp_parents");
                foreach ($parents as $parent) {
                    if (class_exists('PTP_Player')) {
                        $merged = PTP_Player::merge_duplicates($parent->id);
                        $total_merged += $merged;
                    }
                }
                
                $message = $total_merged > 0 
                    ? "Merged {$total_merged} duplicate player records."
                    : "No duplicate players found.";
                $message_type = 'success';
            }
            
            if ($action === 'reset_trainers') {
                // Delete all trainers from database
                $wpdb->query("DELETE FROM {$wpdb->prefix}ptp_trainers");
                $wpdb->query("DELETE FROM {$wpdb->prefix}ptp_applications");
                $wpdb->query("DELETE FROM {$wpdb->prefix}ptp_availability");
                $wpdb->query("DELETE FROM {$wpdb->prefix}ptp_reviews");
                
                // Remove trainer role from all users
                $trainer_users = get_users(array('role' => 'ptp_trainer'));
                foreach ($trainer_users as $user) {
                    $user->remove_role('ptp_trainer');
                    // If user has no other roles, give them subscriber
                    if (empty($user->roles)) {
                        $user->add_role('subscriber');
                    }
                }
                
                $message = "All trainers and applications have been reset. " . count($trainer_users) . " users updated.";
                $message_type = 'success';
            }
        }
        
        // Build page status
        $page_status = array();
        foreach ($required_pages as $slug => $page) {
            $existing = get_page_by_path($slug);
            $page_status[$slug] = array(
                'title' => $page['title'],
                'shortcode' => $page['content'],
                'exists' => $existing ? true : false,
                'id' => $existing ? $existing->ID : null,
                'current_content' => $existing ? $existing->post_content : null,
                'correct' => $existing && trim($existing->post_content) === trim($page['content']),
                'url' => $existing ? get_permalink($existing->ID) : null,
            );
        }
        
        ?>
        <div class="wrap ptp-admin">
            <h1>PTP Tools</h1>
            
            <?php if ($message): ?>
                <div class="notice notice-<?php echo $message_type; ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="ptp-tools-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                
                <!-- Page Creator -->
                <div class="ptp-card">
                    <div class="ptp-card-header">
                        <h2 style="margin: 0;">Page Manager</h2>
                    </div>
                    <div class="ptp-card-body">
                        <p>Manage all PTP pages and their shortcodes.</p>
                        
                        <form method="post" style="margin-bottom: 20px;">
                            <?php wp_nonce_field('ptp_tools', 'ptp_tools_nonce'); ?>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button type="submit" name="ptp_tools_action" value="create_all" class="button button-primary">
                                    Create Missing Pages
                                </button>
                                <button type="submit" name="ptp_tools_action" value="update_all" class="button">
                                    Update All Shortcodes
                                </button>
                                <button type="submit" name="ptp_tools_action" value="recreate_all" class="button" 
                                        onclick="return confirm('This will delete and recreate ALL PTP pages. Are you sure?');">
                                    Recreate All Pages
                                </button>
                            </div>
                        </form>
                        
                        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                            <thead>
                                <tr>
                                    <th>Page</th>
                                    <th>Slug</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($page_status as $slug => $status): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($status['title']); ?></strong>
                                            <br><code style="font-size: 11px;"><?php echo esc_html($status['shortcode']); ?></code>
                                        </td>
                                        <td><code>/<?php echo esc_html($slug); ?>/</code></td>
                                        <td>
                                            <?php if (!$status['exists']): ?>
                                                <span style="color: #dc3232;">❌ Missing</span>
                                            <?php elseif (!$status['correct']): ?>
                                                <span style="color: #dba617;">⚠️ Wrong Shortcode</span>
                                            <?php else: ?>
                                                <span style="color: #46b450;">✅ OK</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" style="display: inline;">
                                                <?php wp_nonce_field('ptp_tools', 'ptp_tools_nonce'); ?>
                                                <input type="hidden" name="page_slug" value="<?php echo esc_attr($slug); ?>">
                                                <button type="submit" name="ptp_tools_action" value="create_single" class="button button-small">
                                                    <?php echo $status['exists'] ? 'Recreate' : 'Create'; ?>
                                                </button>
                                            </form>
                                            <?php if ($status['exists']): ?>
                                                <a href="<?php echo esc_url($status['url']); ?>" class="button button-small" target="_blank">View</a>
                                                <a href="<?php echo esc_url(admin_url('post.php?post=' . $status['id'] . '&action=edit')); ?>" class="button button-small">Edit</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- System Info -->
                <div class="ptp-card">
                    <div class="ptp-card-header">
                        <h2 style="margin: 0;">System Info</h2>
                    </div>
                    <div class="ptp-card-body">
                        <table class="widefat" style="border: none;">
                            <tr>
                                <td><strong>Plugin Version</strong></td>
                                <td><?php echo PTP_VERSION; ?></td>
                            </tr>
                            <tr>
                                <td><strong>WordPress Version</strong></td>
                                <td><?php echo get_bloginfo('version'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>PHP Version</strong></td>
                                <td><?php echo phpversion(); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Database Prefix</strong></td>
                                <td><?php global $wpdb; echo $wpdb->prefix; ?></td>
                            </tr>
                        </table>
                        
                        <h3 style="margin-top: 20px;">Database Tables</h3>
                        <?php
                        global $wpdb;
                        $tables = array(
                            'ptp_trainers',
                            'ptp_parents', 
                            'ptp_applications',
                            'ptp_bookings',
                            'ptp_availability',
                            'ptp_reviews',
                            'ptp_conversations',
                            'ptp_messages',
                            'ptp_payouts',
                        );
                        ?>
                        <table class="widefat" style="border: none;">
                            <?php foreach ($tables as $table): 
                                $full_table = $wpdb->prefix . $table;
                                $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;
                                $count = $exists ? $wpdb->get_var("SELECT COUNT(*) FROM $full_table") : 0;
                            ?>
                                <tr>
                                    <td><?php echo $table; ?></td>
                                    <td>
                                        <?php if ($exists): ?>
                                            <span style="color: #46b450;">✅ <?php echo $count; ?> rows</span>
                                        <?php else: ?>
                                            <span style="color: #dc3232;">❌ Missing</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                        
                        <h3 style="margin-top: 20px;">User Roles</h3>
                        <?php
                        $trainer_count = count(get_users(array('role' => 'ptp_trainer')));
                        $parent_count = count(get_users(array('role' => 'ptp_parent')));
                        ?>
                        <table class="widefat" style="border: none;">
                            <tr>
                                <td>ptp_trainer</td>
                                <td><?php echo $trainer_count; ?> users</td>
                            </tr>
                            <tr>
                                <td>ptp_parent</td>
                                <td><?php echo $parent_count; ?> users</td>
                            </tr>
                        </table>
                    </div>
                </div>
                
            </div>
            
            <!-- Repair Tools -->
            <div style="margin-top: 20px;">
                <div class="ptp-card">
                    <div class="ptp-card-header">
                        <h2 style="margin: 0;">🔧 Repair Tools</h2>
                    </div>
                    <div class="ptp-card-body">
                        <p>Fix common issues with trainer accounts and applications.</p>
                        
                        <?php
                        // Find broken trainers (have role but no trainer record)
                        $trainer_users = get_users(array('role' => 'ptp_trainer'));
                        $broken_trainers = array();
                        
                        foreach ($trainer_users as $user) {
                            $has_record = $wpdb->get_var($wpdb->prepare(
                                "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
                                $user->ID
                            ));
                            
                            if (!$has_record) {
                                // Check if they have an approved application
                                $app = $wpdb->get_row($wpdb->prepare(
                                    "SELECT * FROM {$wpdb->prefix}ptp_applications WHERE user_id = %d ORDER BY id DESC LIMIT 1",
                                    $user->ID
                                ));
                                
                                $broken_trainers[] = array(
                                    'user' => $user,
                                    'application' => $app,
                                );
                            }
                        }
                        
                        // Handle repair action
                        if (isset($_POST['ptp_repair_action']) && wp_verify_nonce($_POST['ptp_tools_nonce'], 'ptp_tools')) {
                            $repair_action = sanitize_text_field($_POST['ptp_repair_action']);
                            
                            if ($repair_action === 'repair_trainer' && !empty($_POST['repair_user_id'])) {
                                $repair_user_id = intval($_POST['repair_user_id']);
                                $repair_user = get_user_by('ID', $repair_user_id);
                                
                                if ($repair_user) {
                                    // Check for application
                                    $app = $wpdb->get_row($wpdb->prepare(
                                        "SELECT * FROM {$wpdb->prefix}ptp_applications WHERE user_id = %d ORDER BY id DESC LIMIT 1",
                                        $repair_user_id
                                    ));
                                    
                                    // Create trainer record
                                    $slug = sanitize_title($repair_user->display_name) . '-' . $repair_user_id;
                                    
                                    // Make sure slug is unique
                                    $slug_exists = $wpdb->get_var($wpdb->prepare(
                                        "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s", $slug
                                    ));
                                    if ($slug_exists) {
                                        $slug .= '-' . time();
                                    }
                                    
                                    $trainer_data = array(
                                        'user_id' => $repair_user_id,
                                        'display_name' => $app ? $app->name : $repair_user->display_name,
                                        'slug' => $slug,
                                        'email' => $app ? $app->email : $repair_user->user_email,
                                        'phone' => $app ? ($app->phone ?: '') : '',
                                        'location' => $app ? ($app->location ?: '') : '',
                                        'college' => $app ? ($app->college ?: '') : '',
                                        'team' => $app ? ($app->team ?: '') : '',
                                        'playing_level' => $app ? ($app->playing_level ?: '') : '',
                                        'specialties' => $app ? ($app->specialties ?: '') : '',
                                        'instagram' => $app ? ($app->instagram ?: '') : '',
                                        'headline' => $app ? ($app->headline ?: '') : '',
                                        'bio' => $app ? ($app->bio ?: '') : '',
                                        'hourly_rate' => $app ? floatval($app->hourly_rate) : 0,
                                        'travel_radius' => $app ? intval($app->travel_radius) : 15,
                                        'status' => 'active',
                                    );
                                    
                                    $insert_result = $wpdb->insert($wpdb->prefix . 'ptp_trainers', $trainer_data);
                                    
                                    if ($insert_result) {
                                        echo '<div class="notice notice-success"><p>✅ Created trainer record for ' . esc_html($repair_user->display_name) . '</p></div>';
                                        // Refresh the broken trainers list
                                        $broken_trainers = array_filter($broken_trainers, function($bt) use ($repair_user_id) {
                                            return $bt['user']->ID !== $repair_user_id;
                                        });
                                    } else {
                                        echo '<div class="notice notice-error"><p>❌ Failed to create trainer record: ' . esc_html($wpdb->last_error) . '</p></div>';
                                    }
                                }
                            }
                            
                            if ($repair_action === 'repair_all_trainers') {
                                $repaired = 0;
                                foreach ($broken_trainers as $bt) {
                                    $repair_user = $bt['user'];
                                    $app = $bt['application'];
                                    
                                    $slug = sanitize_title($repair_user->display_name) . '-' . $repair_user->ID;
                                    $slug_exists = $wpdb->get_var($wpdb->prepare(
                                        "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s", $slug
                                    ));
                                    if ($slug_exists) {
                                        $slug .= '-' . time() . '-' . $repaired;
                                    }
                                    
                                    $trainer_data = array(
                                        'user_id' => $repair_user->ID,
                                        'display_name' => $app ? $app->name : $repair_user->display_name,
                                        'slug' => $slug,
                                        'email' => $app ? $app->email : $repair_user->user_email,
                                        'phone' => $app ? ($app->phone ?: '') : '',
                                        'location' => $app ? ($app->location ?: '') : '',
                                        'college' => $app ? ($app->college ?: '') : '',
                                        'team' => $app ? ($app->team ?: '') : '',
                                        'playing_level' => $app ? ($app->playing_level ?: '') : '',
                                        'specialties' => $app ? ($app->specialties ?: '') : '',
                                        'instagram' => $app ? ($app->instagram ?: '') : '',
                                        'headline' => $app ? ($app->headline ?: '') : '',
                                        'bio' => $app ? ($app->bio ?: '') : '',
                                        'hourly_rate' => $app ? floatval($app->hourly_rate) : 0,
                                        'travel_radius' => $app ? intval($app->travel_radius) : 15,
                                        'status' => 'active',
                                    );
                                    
                                    if ($wpdb->insert($wpdb->prefix . 'ptp_trainers', $trainer_data)) {
                                        $repaired++;
                                    }
                                }
                                
                                if ($repaired > 0) {
                                    echo '<div class="notice notice-success"><p>✅ Repaired ' . $repaired . ' trainer(s)</p></div>';
                                    $broken_trainers = array(); // Clear the list
                                }
                            }
                        }
                        ?>
                        
                        <?php if (!empty($broken_trainers)): ?>
                            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px 16px; margin: 16px 0; border-radius: 0 4px 4px 0;">
                                <strong>⚠️ Found <?php echo count($broken_trainers); ?> user(s) with trainer role but no trainer record:</strong>
                            </div>
                            
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Email</th>
                                        <th>Application Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($broken_trainers as $bt): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html($bt['user']->display_name); ?></strong>
                                                <br><small>ID: <?php echo $bt['user']->ID; ?></small>
                                            </td>
                                            <td><?php echo esc_html($bt['user']->user_email); ?></td>
                                            <td>
                                                <?php if ($bt['application']): ?>
                                                    <span style="color: <?php echo $bt['application']->status === 'approved' ? '#28a745' : '#dc3545'; ?>">
                                                        <?php echo ucfirst($bt['application']->status); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #6c757d;">No application found</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="post" style="display: inline;">
                                                    <?php wp_nonce_field('ptp_tools', 'ptp_tools_nonce'); ?>
                                                    <input type="hidden" name="repair_user_id" value="<?php echo $bt['user']->ID; ?>">
                                                    <button type="submit" name="ptp_repair_action" value="repair_trainer" class="button button-primary button-small">
                                                        Create Trainer Record
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <form method="post" style="margin-top: 16px;">
                                <?php wp_nonce_field('ptp_tools', 'ptp_tools_nonce'); ?>
                                <button type="submit" name="ptp_repair_action" value="repair_all_trainers" class="button button-primary">
                                    🔧 Repair All (Create <?php echo count($broken_trainers); ?> trainer records)
                                </button>
                            </form>
                        <?php else: ?>
                            <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 12px 16px; margin: 16px 0; border-radius: 0 4px 4px 0;">
                                <strong>✅ All users with trainer role have valid trainer records.</strong>
                            </div>
                        <?php endif; ?>
                        
                        <hr style="margin: 24px 0;">
                        
                        <h4>Database Maintenance</h4>
                        <form method="post" style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <?php wp_nonce_field('ptp_tools', 'ptp_tools_nonce'); ?>
                            <button type="submit" name="ptp_tools_action" value="recreate_tables" class="button"
                                    onclick="return confirm('This will recreate all database tables. Existing data will be preserved. Continue?');">
                                Recreate Database Tables
                            </button>
                            <button type="submit" name="ptp_tools_action" value="add_indexes" class="button button-primary">
                                ⚡ Add Performance Indexes
                            </button>
                            <button type="submit" name="ptp_tools_action" value="merge_duplicate_players" class="button">
                                🔄 Merge Duplicate Players
                            </button>
                        </form>
                        <p style="color: #6B7280; font-size: 12px; margin-top: 8px;">Performance indexes improve query speed. Merge duplicates removes duplicate player records from checkout.</p>
                        
                        <hr style="margin: 24px 0;">
                        
                        <h4>⚠️ Danger Zone</h4>
                        <form method="post" style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <?php wp_nonce_field('ptp_tools', 'ptp_tools_nonce'); ?>
                            <button type="submit" name="ptp_tools_action" value="reset_trainers" class="button" style="background: #DC2626; color: #fff; border-color: #DC2626;"
                                    onclick="return confirm('⚠️ WARNING: This will DELETE ALL trainers, applications, availability, and reviews. This cannot be undone! Are you absolutely sure?');">
                                🗑️ Reset All Trainers
                            </button>
                        </form>
                        <p style="color: #6B7280; font-size: 12px; margin-top: 8px;">This deletes all trainer records, applications, availability, and reviews. Users keep their accounts but lose trainer role.</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * v200: Master list of PTP training locations
     * Each key is a sanitize_title slug, each value has name + address.
     * Used in admin trainer edit, trainer profile, and free session matching.
     */
    public static function get_ptp_training_locations() {
        return array(
            'wilson-farm-park-wayne-pa' => array(
                'name' => 'Wilson Farm Park',
                'address' => 'Wayne, PA',
                'lat' => 40.0439,
                'lng' => -75.3879,
            ),
            'sleighton-park-media-pa' => array(
                'name' => 'Sleighton Park',
                'address' => 'Media, PA',
                'lat' => 39.9168,
                'lng' => -75.3927,
            ),
            'radnor-memorial-park-villanova-pa' => array(
                'name' => 'Radnor Memorial Park',
                'address' => 'Villanova, PA',
                'lat' => 40.0384,
                'lng' => -75.3459,
            ),
            'gable-park-newtown-square-pa' => array(
                'name' => 'Gable Park',
                'address' => 'Newtown Square, PA',
                'lat' => 39.9876,
                'lng' => -75.4129,
            ),
            'decou-soccer-complex-cherry-hill-nj' => array(
                'name' => 'DeCou Soccer Complex',
                'address' => 'Cherry Hill, NJ',
                'lat' => 39.9065,
                'lng' => -74.9947,
            ),
            'ustc-indoor-downingtown-pa' => array(
                'name' => 'USTC Indoor',
                'address' => 'Downingtown, PA',
                'lat' => 40.0065,
                'lng' => -75.7032,
            ),
            'haverford-reserve-havertown-pa' => array(
                'name' => 'Haverford Reserve',
                'address' => 'Havertown, PA',
                'lat' => 39.9801,
                'lng' => -75.3104,
            ),
            'conshohocken-park-conshohocken-pa' => array(
                'name' => 'Sutcliffe Park',
                'address' => 'Conshohocken, PA',
                'lat' => 40.0799,
                'lng' => -75.3007,
            ),
            'springfield-country-club-springfield-pa' => array(
                'name' => 'Springfield Complex',
                'address' => 'Springfield, PA',
                'lat' => 39.9312,
                'lng' => -75.3205,
            ),
            'blue-bell-park-blue-bell-pa' => array(
                'name' => 'Whitpain Park',
                'address' => 'Blue Bell, PA',
                'lat' => 40.1523,
                'lng' => -75.2660,
            ),
        );
    }

    /**
     * =====================================================
     * TRAINER RANKING PAGE (v54)
     * =====================================================
     */
    
    /**
     * AJAX: Save trainer sort order
     */
    public function ajax_save_trainer_order() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $order_data = isset($_POST['order']) ? $_POST['order'] : array();
        
        if (empty($order_data) || !is_array($order_data)) {
            wp_send_json_error('Invalid order data');
        }
        
        // Sanitize and prepare order data
        $clean_data = array();
        foreach ($order_data as $item) {
            $trainer_id = absint($item['id'] ?? 0);
            $position = absint($item['position'] ?? 0);
            if ($trainer_id > 0) {
                $clean_data[$trainer_id] = $position;
            }
        }
        
        $result = PTP_Trainer::bulk_update_sort_order($clean_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(array(
            'message' => 'Order saved successfully',
            'updated' => $result['updated'],
        ));
    }
    
    /**
     * AJAX: Toggle trainer featured status
     */
    public function ajax_toggle_featured() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $trainer_id = absint($_POST['trainer_id'] ?? 0);
        $is_featured = !empty($_POST['is_featured']);
        
        if (!$trainer_id) {
            wp_send_json_error('Invalid trainer ID');
        }
        
        $result = PTP_Trainer::set_featured($trainer_id, $is_featured);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(array(
            'message' => $is_featured ? 'Trainer featured' : 'Trainer unfeatured',
            'is_featured' => $is_featured,
        ));
    }
    
    /**
     * AJAX: Bulk feature/unfeature trainers
     */
    public function ajax_bulk_feature() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $trainer_ids = isset($_POST['trainer_ids']) ? array_map('absint', (array)$_POST['trainer_ids']) : array();
        $is_featured = !empty($_POST['is_featured']);
        
        if (empty($trainer_ids)) {
            wp_send_json_error('No trainers selected');
        }
        
        $result = PTP_Trainer::bulk_set_featured($trainer_ids, $is_featured);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(array(
            'message' => $result['updated'] . ' trainers updated',
            'updated' => $result['updated'],
        ));
    }
    
    /**
     * Trainer Ranking Page
     */
    public function trainer_ranking_page() {
        global $wpdb;
        
        // Handle auto-assign action
        if (isset($_POST['ptp_auto_assign']) && wp_verify_nonce($_POST['ptp_ranking_nonce'], 'ptp_ranking_action')) {
            $count = PTP_Trainer::auto_assign_sort_orders();
            echo '<div class="notice notice-success is-dismissible"><p>✅ Auto-assigned sort orders to ' . $count . ' trainers.</p></div>';
        }
        
        // Get trainers for ranking
        $trainers = PTP_Trainer::get_for_ranking('active');
        $featured_count = PTP_Trainer::get_featured_count();
        $total_count = count($trainers);
        
        ?>
        <div class="ptp-admin-wrap">
            <div class="ptp-admin-header">
                <div class="ptp-admin-header-content">
                    <div class="ptp-admin-logo">
                        <span class="dashicons dashicons-star-filled"></span>
                    </div>
                    <div class="ptp-admin-title-wrap">
                        <h1 class="ptp-admin-title">Trainer <span>Ranking</span></h1>
                        <p class="ptp-admin-subtitle">Feature and rank trainers to control display order</p>
                    </div>
                </div>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <div style="background: #FEF3C7; padding: 8px 16px; border-radius: 8px; font-weight: 600; color: #92400E;">
                        ⭐ <?php echo $featured_count; ?> Featured
                    </div>
                    <div style="background: #E5E7EB; padding: 8px 16px; border-radius: 8px; font-weight: 600; color: #374151;">
                        👥 <?php echo $total_count; ?> Total Active
                    </div>
                </div>
            </div>
            
            <?php $this->render_nav('trainers'); ?>
            
            <!-- Instructions -->
            <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; display: flex; gap: 12px; align-items: flex-start;">
                <span style="font-size: 24px;">💡</span>
                <div>
                    <strong style="color: #1E40AF;">How Ranking Works</strong>
                    <p style="margin: 4px 0 0; color: #3B82F6; font-size: 14px;">
                        <strong>Featured trainers</strong> appear first on the trainers page. Within each group (featured/non-featured), trainers are sorted by their <strong>position number</strong> (lower = higher ranking).
                        Drag and drop to reorder, or use the quick actions to feature/unfeature trainers.
                    </p>
                </div>
            </div>
            
            <!-- Toolbar -->
            <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
                <form method="post" style="margin: 0;">
                    <?php wp_nonce_field('ptp_ranking_action', 'ptp_ranking_nonce'); ?>
                    <button type="submit" name="ptp_auto_assign" class="button button-secondary">
                        🔄 Auto-Assign Positions
                    </button>
                </form>
                
                <button type="button" id="ptp-save-order" class="button button-primary" disabled style="opacity: 0.5;">
                    💾 Save Order
                </button>
                
                <div style="margin-left: auto; display: flex; gap: 8px;">
                    <button type="button" id="ptp-feature-selected" class="button" disabled>
                        ⭐ Feature Selected
                    </button>
                    <button type="button" id="ptp-unfeature-selected" class="button" disabled>
                        ✖️ Unfeature Selected
                    </button>
                </div>
            </div>
            
            <!-- Ranking Table -->
            <div class="ptp-card">
                <div class="ptp-card-body no-padding">
                    <table class="ptp-table" id="ptp-ranking-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="ptp-select-all"></th>
                                <th style="width: 50px;">⋮⋮</th>
                                <th style="width: 60px;">#</th>
                                <th>Trainer</th>
                                <th>Location</th>
                                <th>Rate</th>
                                <th>Rating</th>
                                <th>Sessions</th>
                                <th style="width: 100px;">Featured</th>
                            </tr>
                        </thead>
                        <tbody id="ptp-ranking-body">
                            <?php if ($trainers): ?>
                                <?php foreach ($trainers as $index => $t): ?>
                                <tr class="ptp-ranking-row <?php echo $t->is_featured ? 'is-featured' : ''; ?>" 
                                    data-trainer-id="<?php echo $t->id; ?>" 
                                    data-position="<?php echo $t->sort_order; ?>">
                                    <td>
                                        <input type="checkbox" class="ptp-trainer-checkbox" value="<?php echo $t->id; ?>">
                                    </td>
                                    <td class="ptp-drag-handle" style="cursor: grab; color: #9CA3AF; font-size: 18px;">⋮⋮</td>
                                    <td class="ptp-position-cell" style="font-weight: 700; color: #6B7280;"><?php echo $t->sort_order; ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <img src="<?php echo esc_url($t->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($t->display_name) . '&background=FCB900&color=0A0A0A'); ?>" 
                                                 alt="" style="width: 40px; height: 40px; border-radius: 8px; object-fit: cover;">
                                            <div>
                                                <strong><?php echo esc_html($t->display_name); ?></strong>
                                                <?php if ($t->is_featured): ?>
                                                <span style="background: #FEF3C7; color: #92400E; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">⭐ FEATURED</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($t->location ?: '-'); ?></td>
                                    <td>$<?php echo number_format($t->hourly_rate, 0); ?>/hr</td>
                                    <td>
                                        <?php if ($t->average_rating > 0): ?>
                                        <span style="color: #F59E0B;">★</span> <?php echo number_format($t->average_rating, 1); ?>
                                        <span style="color: #9CA3AF; font-size: 12px;">(<?php echo $t->review_count; ?>)</span>
                                        <?php else: ?>
                                        <span style="color: #9CA3AF;">No reviews</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $t->total_sessions; ?></td>
                                    <td>
                                        <button type="button" class="ptp-feature-toggle button button-small" 
                                                data-trainer-id="<?php echo $t->id; ?>" 
                                                data-is-featured="<?php echo $t->is_featured ? '1' : '0'; ?>"
                                                style="<?php echo $t->is_featured ? 'background: #FCB900; border-color: #FCB900; color: #0A0A0A;' : ''; ?>">
                                            <?php echo $t->is_featured ? '⭐ Featured' : 'Feature'; ?>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 40px; color: #6B7280;">
                                        No active trainers found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <style>
        .ptp-ranking-row { transition: background 0.2s; }
        .ptp-ranking-row:hover { background: #F9FAFB; }
        .ptp-ranking-row.is-featured { background: #FFFBEB; }
        .ptp-ranking-row.is-featured:hover { background: #FEF3C7; }
        .ptp-ranking-row.dragging { opacity: 0.5; background: #FEF3C7; }
        .ptp-ranking-row.drag-over { border-top: 3px solid #FCB900; }
        .ptp-drag-handle:hover { color: #FCB900 !important; }
        #ptp-save-order:not([disabled]) { opacity: 1 !important; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            const nonce = '<?php echo wp_create_nonce('ptp_admin_nonce'); ?>';
            let orderChanged = false;
            
            // Drag and drop functionality
            let draggedRow = null;
            
            $('#ptp-ranking-body').on('mousedown', '.ptp-drag-handle', function(e) {
                draggedRow = $(this).closest('tr');
                draggedRow.addClass('dragging');
            });
            
            $(document).on('mouseup', function() {
                if (draggedRow) {
                    draggedRow.removeClass('dragging');
                    draggedRow = null;
                    $('.ptp-ranking-row').removeClass('drag-over');
                }
            });
            
            $('#ptp-ranking-body').on('mouseover', '.ptp-ranking-row', function() {
                if (draggedRow && draggedRow[0] !== this) {
                    $('.ptp-ranking-row').removeClass('drag-over');
                    $(this).addClass('drag-over');
                }
            });
            
            $('#ptp-ranking-body').on('mouseup', '.ptp-ranking-row', function() {
                if (draggedRow && draggedRow[0] !== this) {
                    $(this).before(draggedRow);
                    updatePositions();
                    orderChanged = true;
                    $('#ptp-save-order').prop('disabled', false);
                }
            });
            
            // Update position numbers after drag
            function updatePositions() {
                $('#ptp-ranking-body .ptp-ranking-row').each(function(index) {
                    $(this).find('.ptp-position-cell').text(index);
                    $(this).attr('data-position', index);
                });
            }
            
            // Save order
            $('#ptp-save-order').on('click', function() {
                const $btn = $(this);
                $btn.text('Saving...').prop('disabled', true);
                
                const order = [];
                $('#ptp-ranking-body .ptp-ranking-row').each(function(index) {
                    order.push({
                        id: $(this).data('trainer-id'),
                        position: index
                    });
                });
                
                $.post(ajaxurl, {
                    action: 'ptp_save_trainer_order',
                    nonce: nonce,
                    order: order
                }, function(response) {
                    if (response.success) {
                        $btn.text('✓ Saved!');
                        orderChanged = false;
                        setTimeout(() => $btn.text('💾 Save Order').prop('disabled', true), 2000);
                    } else {
                        alert('Error: ' + response.data);
                        $btn.text('💾 Save Order').prop('disabled', false);
                    }
                }).fail(function() {
                    alert('Request failed');
                    $btn.text('💾 Save Order').prop('disabled', false);
                });
            });
            
            // Toggle featured
            $('.ptp-feature-toggle').on('click', function() {
                const $btn = $(this);
                const trainerId = $btn.data('trainer-id');
                const currentlyFeatured = $btn.data('is-featured') === 1;
                const newFeatured = !currentlyFeatured;
                
                $btn.prop('disabled', true);
                
                $.post(ajaxurl, {
                    action: 'ptp_toggle_featured',
                    nonce: nonce,
                    trainer_id: trainerId,
                    is_featured: newFeatured ? 1 : 0
                }, function(response) {
                    if (response.success) {
                        $btn.data('is-featured', newFeatured ? 1 : 0);
                        const $row = $btn.closest('tr');
                        
                        if (newFeatured) {
                            $btn.text('⭐ Featured').css({background: '#FCB900', borderColor: '#FCB900', color: '#0A0A0A'});
                            $row.addClass('is-featured');
                            $row.find('.ptp-trainer-checkbox').after('<span style="background: #FEF3C7; color: #92400E; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">⭐ FEATURED</span>');
                        } else {
                            $btn.text('Feature').css({background: '', borderColor: '', color: ''});
                            $row.removeClass('is-featured');
                            $row.find('span:contains("FEATURED")').remove();
                        }
                    } else {
                        alert('Error: ' + response.data);
                    }
                    $btn.prop('disabled', false);
                }).fail(function() {
                    alert('Request failed');
                    $btn.prop('disabled', false);
                });
            });
            
            // Select all checkbox
            $('#ptp-select-all').on('change', function() {
                $('.ptp-trainer-checkbox').prop('checked', this.checked);
                updateBulkButtons();
            });
            
            $('.ptp-trainer-checkbox').on('change', updateBulkButtons);
            
            function updateBulkButtons() {
                const checked = $('.ptp-trainer-checkbox:checked').length;
                $('#ptp-feature-selected, #ptp-unfeature-selected').prop('disabled', checked === 0);
            }
            
            // Bulk feature/unfeature
            $('#ptp-feature-selected, #ptp-unfeature-selected').on('click', function() {
                const $btn = $(this);
                const isFeaturing = $btn.attr('id') === 'ptp-feature-selected';
                const ids = [];
                
                $('.ptp-trainer-checkbox:checked').each(function() {
                    ids.push($(this).val());
                });
                
                if (ids.length === 0) return;
                
                $btn.prop('disabled', true).text(isFeaturing ? 'Featuring...' : 'Unfeaturing...');
                
                $.post(ajaxurl, {
                    action: 'ptp_bulk_feature',
                    nonce: nonce,
                    trainer_ids: ids,
                    is_featured: isFeaturing ? 1 : 0
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                        $btn.text(isFeaturing ? '⭐ Feature Selected' : '✖️ Unfeature Selected').prop('disabled', false);
                    }
                }).fail(function() {
                    alert('Request failed');
                    $btn.text(isFeaturing ? '⭐ Feature Selected' : '✖️ Unfeature Selected').prop('disabled', false);
                });
            });
            
            // Warn before leaving with unsaved changes
            $(window).on('beforeunload', function() {
                if (orderChanged) {
                    return 'You have unsaved changes. Are you sure you want to leave?';
                }
            });
        });
        </script>
        <?php
    }
}

// Initialize admin
if (is_admin()) {
    PTP_Admin::instance();
}
