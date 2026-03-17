<?php
/**
 * PTP Trainer Profile Fixes v142
 * 
 * Fixes:
 * - Trainer profiles not showing (status check, query optimization)
 * - Mobile/Desktop responsive optimization
 * - Performance improvements
 * - SEO enhancements
 * 
 * @version 142.0.0
 */

defined('ABSPATH') || exit;

class PTP_Trainer_Profile_Fixes {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Fix trainer visibility
        add_action('init', array($this, 'fix_trainer_visibility'), 5);
        
        // Optimize queries
        add_filter('ptp_trainer_query', array($this, 'optimize_trainer_query'), 10, 2);
        
        // Mobile/Desktop CSS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_responsive_styles'), 100);
        add_action('wp_head', array($this, 'output_critical_css'), 1);
        
        // Fix scroll issues
        add_action('wp_footer', array($this, 'fix_scroll_js'), 999);
        
        // REST API for trainer diagnostics
        add_action('rest_api_init', array($this, 'register_diagnostic_routes'));
        
        // Admin notice for trainer issues
        add_action('admin_notices', array($this, 'trainer_status_notice'));
        
        // Auto-fix trainer status on save
        add_action('ptp_trainer_saved', array($this, 'auto_fix_trainer_status'), 10, 2);
    }
    
    /**
     * Fix trainer visibility issues
     * Common causes: status not 'active', missing slug, missing required fields
     */
    public function fix_trainer_visibility() {
        if (!is_admin() && !wp_doing_ajax()) {
            return;
        }
        
        // Only run periodically
        $last_check = get_transient('ptp_trainer_visibility_check');
        if ($last_check) {
            return;
        }
        
        global $wpdb;
        
        // Find trainers that should be visible but aren't
        $problematic = $wpdb->get_results("
            SELECT t.*, u.user_email, u.display_name as wp_name
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
            WHERE (t.status IS NULL OR t.status = '' OR t.status NOT IN ('active', 'pending', 'inactive'))
               OR t.slug IS NULL 
               OR t.slug = ''
               OR t.display_name IS NULL
               OR t.display_name = ''
        ");
        
        foreach ($problematic as $trainer) {
            $updates = array();
            
            // Fix missing status - default to 'pending' for safety
            if (empty($trainer->status) || !in_array($trainer->status, array('active', 'pending', 'inactive'))) {
                // If trainer has completed profile, set to active
                if (!empty($trainer->hourly_rate) && !empty($trainer->bio)) {
                    $updates['status'] = 'active';
                } else {
                    $updates['status'] = 'pending';
                }
            }
            
            // Fix missing slug
            if (empty($trainer->slug)) {
                $name = $trainer->display_name ?: $trainer->wp_name ?: 'trainer-' . $trainer->id;
                $slug = sanitize_title($name);
                
                // Ensure unique
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s AND id != %d",
                    $slug, $trainer->id
                ));
                
                if ($exists) {
                    $slug .= '-' . $trainer->id;
                }
                
                $updates['slug'] = $slug;
            }
            
            // Fix missing display name
            if (empty($trainer->display_name)) {
                $updates['display_name'] = $trainer->wp_name ?: 'Trainer ' . $trainer->id;
            }
            
            if (!empty($updates)) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    $updates,
                    array('id' => $trainer->id)
                );
                
                ptp_log("[PTP Fix] Auto-fixed trainer #{$trainer->id}: " . json_encode($updates));
            }
        }
        
        set_transient('ptp_trainer_visibility_check', time(), HOUR_IN_SECONDS);
    }
    
    /**
     * Optimize trainer queries for better performance
     */
    public function optimize_trainer_query($query, $args = array()) {
        global $wpdb;
        
        // Add index hints if tables are large
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers");
        
        if ($count > 100) {
            // Use covering index
            $query = str_replace(
                "SELECT *",
                "SELECT t.id, t.slug, t.display_name, t.photo_url, t.cover_photo_url, t.hourly_rate, 
                        t.specialties, t.city, t.state, t.bio, t.average_rating, t.review_count, 
                        t.total_sessions, t.playing_level, t.is_featured, t.training_locations",
                $query
            );
        }
        
        return $query;
    }
    
    /**
     * Enqueue responsive styles
     */
    public function enqueue_responsive_styles() {
        if (!$this->is_trainer_page()) {
            return;
        }
        
        wp_enqueue_style(
            'ptp-responsive-v142',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/ptp-responsive-v142.css',
            array(),
            '142.0.0'
        );
    }
    
    /**
     * Output critical CSS inline
     */
    public function output_critical_css() {
        if (!$this->is_trainer_page()) {
            return;
        }
        ?>
<style id="ptp-critical-v142">
/* =============================================
   PTP Critical CSS v142 - Mobile + Desktop Fix
   ============================================= */

/* Base Reset */
*,*::before,*::after{box-sizing:border-box}

/* Force scroll to work everywhere — v223-fix: skip on tp210 pages (own CSS handles this) */
html:not(:has(body.tp210)),body:not(.tp210){
    overflow-x:hidden !important;
    overflow-y:auto !important;
    height:auto !important;
    min-height:100% !important;
    position:static !important;
    -webkit-overflow-scrolling:touch;
}

/* Hide scrollbar but keep functionality */
html:not(:has(body.tp210)),body:not(.tp210){scrollbar-width:none;-ms-overflow-style:none}
html:not(:has(body.tp210))::-webkit-scrollbar,body:not(.tp210)::-webkit-scrollbar{display:none;width:0}

/* Prevent modal classes from breaking scroll — v223-fix: skip on tp210 */
html:not(:has(body.tp210)).modal-open,html:not(:has(body.tp210)).menu-open,html:not(:has(body.tp210)).ptp-drawer-open,
body:not(.tp210).modal-open,body:not(.tp210).menu-open,body:not(.tp210).ptp-drawer-open{
    overflow-y:auto !important;
    position:static !important;
}

/* Only block scroll when explicitly needed */
body.ptp-modal-active{
    overflow:hidden !important;
    position:fixed !important;
    width:100% !important;
}

/* =============================================
   MOBILE FIRST - Base styles (< 768px)
   ============================================= */

.ptp-trainer-profile{
    --safe-top:env(safe-area-inset-top,0px);
    --safe-bottom:env(safe-area-inset-bottom,0px);
    font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
    background:#fff;
    min-height:100vh;
    min-height:100dvh;
}

/* Profile Header */
.ptp-profile-header{
    position:relative;
    padding:0;
    background:#0A0A0A;
}

.ptp-cover-photo{
    width:100%;
    height:200px;
    object-fit:cover;
    display:block;
}

.ptp-profile-avatar{
    width:100px;
    height:100px;
    border-radius:50%;
    border:4px solid #fff;
    box-shadow:0 4px 20px rgba(0,0,0,0.2);
    margin:-50px auto 0;
    display:block;
    position:relative;
    z-index:10;
    background:#fff;
}

.ptp-profile-info{
    text-align:center;
    padding:16px 20px 24px;
}

.ptp-profile-name{
    font-size:24px;
    font-weight:700;
    color:#0A0A0A;
    margin:0 0 4px;
}

.ptp-profile-tagline{
    font-size:14px;
    color:#6B7280;
    margin:0 0 12px;
}

.ptp-profile-stats{
    display:flex;
    justify-content:center;
    gap:24px;
    padding:12px 0;
}

.ptp-stat{
    text-align:center;
}

.ptp-stat-value{
    font-size:20px;
    font-weight:700;
    color:#0A0A0A;
}

.ptp-stat-label{
    font-size:12px;
    color:#9CA3AF;
    text-transform:uppercase;
    letter-spacing:0.5px;
}

/* Booking CTA */
.ptp-book-cta{
    position:fixed;
    bottom:0;
    left:0;
    right:0;
    padding:16px 20px calc(16px + var(--safe-bottom));
    background:#fff;
    border-top:1px solid #E5E7EB;
    z-index:100;
    display:flex;
    align-items:center;
    gap:16px;
}

.ptp-book-price{
    flex-shrink:0;
}

.ptp-book-price-value{
    font-size:24px;
    font-weight:700;
    color:#0A0A0A;
}

.ptp-book-price-label{
    font-size:12px;
    color:#6B7280;
}

.ptp-book-btn{
    flex:1;
    background:#FCB900;
    color:#0A0A0A;
    font-size:16px;
    font-weight:600;
    padding:14px 24px;
    border:none;
    border-radius:12px;
    cursor:pointer;
    transition:all 0.2s;
    -webkit-tap-highlight-color:transparent;
}

.ptp-book-btn:active{
    transform:scale(0.98);
    background:#E5A800;
}

/* Content sections */
.ptp-section{
    padding:24px 20px;
    border-bottom:8px solid #F3F4F6;
}

.ptp-section-title{
    font-size:18px;
    font-weight:700;
    color:#0A0A0A;
    margin:0 0 16px;
}

/* Specialty tags */
.ptp-tags{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}

.ptp-tag{
    background:#F3F4F6;
    color:#374151;
    font-size:13px;
    font-weight:500;
    padding:8px 14px;
    border-radius:20px;
}

/* Reviews */
.ptp-review{
    padding:16px 0;
    border-bottom:1px solid #F3F4F6;
}

.ptp-review:last-child{
    border-bottom:none;
}

.ptp-review-header{
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:8px;
}

.ptp-review-avatar{
    width:40px;
    height:40px;
    border-radius:50%;
    background:#E5E7EB;
}

.ptp-review-name{
    font-weight:600;
    color:#0A0A0A;
}

.ptp-review-stars{
    color:#FCB900;
    font-size:14px;
}

.ptp-review-text{
    font-size:14px;
    color:#4B5563;
    line-height:1.6;
}

/* Add bottom padding for fixed CTA */
.ptp-trainer-profile .ptp-content{
    padding-bottom:100px;
}

/* =============================================
   TABLET (768px - 1023px)
   ============================================= */

@media (min-width:768px) {
    .ptp-cover-photo{
        height:280px;
    }
    
    .ptp-profile-avatar{
        width:120px;
        height:120px;
        margin-top:-60px;
    }
    
    .ptp-profile-name{
        font-size:28px;
    }
    
    .ptp-profile-stats{
        gap:40px;
    }
    
    .ptp-stat-value{
        font-size:24px;
    }
    
    .ptp-section{
        padding:32px 40px;
    }
    
    .ptp-book-cta{
        padding:20px 40px calc(20px + var(--safe-bottom));
    }
}

/* =============================================
   DESKTOP (1024px+)
   ============================================= */

@media (min-width:1024px) {
    /* Two-column layout */
    .ptp-trainer-profile{
        display:grid;
        grid-template-columns:1fr 380px;
        grid-template-rows:auto 1fr;
        gap:0;
        max-width:1400px;
        margin:0 auto;
        min-height:100vh;
    }
    
    .ptp-profile-header{
        grid-column:1 / -1;
    }
    
    .ptp-content{
        padding:40px;
        padding-bottom:40px !important;
        overflow-y:auto;
    }
    
    .ptp-sidebar{
        padding:40px;
        border-left:1px solid #E5E7EB;
        position:sticky;
        top:0;
        height:100vh;
        overflow-y:auto;
    }
    
    /* Remove fixed CTA on desktop */
    .ptp-book-cta{
        position:static;
        flex-direction:column;
        align-items:stretch;
        padding:0;
        border-top:none;
        background:transparent;
    }
    
    .ptp-book-price{
        text-align:center;
        margin-bottom:16px;
    }
    
    .ptp-book-price-value{
        font-size:32px;
    }
    
    .ptp-book-btn{
        padding:16px 32px;
        font-size:17px;
    }
    
    .ptp-cover-photo{
        height:320px;
    }
    
    .ptp-profile-avatar{
        width:140px;
        height:140px;
        margin-top:-70px;
    }
    
    .ptp-profile-name{
        font-size:32px;
    }
    
    .ptp-section{
        padding:40px 0;
        border-bottom:1px solid #E5E7EB;
    }
    
    .ptp-section-title{
        font-size:20px;
    }
}

/* =============================================
   LARGE DESKTOP (1440px+)
   ============================================= */

@media (min-width:1440px) {
    .ptp-trainer-profile{
        grid-template-columns:1fr 420px;
    }
    
    .ptp-content{
        padding:48px 64px;
    }
    
    .ptp-sidebar{
        padding:48px;
    }
}

/* =============================================
   TRAINER GRID - Find Trainers Page
   ============================================= */

.ptp-trainers-grid{
    display:grid;
    gap:16px;
    padding:16px;
}

.ptp-trainer-card{
    background:#fff;
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 2px 8px rgba(0,0,0,0.08);
    transition:all 0.3s cubic-bezier(0.34,1.56,0.64,1);
}

.ptp-trainer-card:active{
    transform:scale(0.98);
}

.ptp-trainer-card-image{
    width:100%;
    height:200px;
    object-fit:cover;
}

.ptp-trainer-card-content{
    padding:16px;
}

.ptp-trainer-card-name{
    font-size:18px;
    font-weight:700;
    color:#0A0A0A;
    margin:0 0 4px;
}

.ptp-trainer-card-meta{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:14px;
    color:#6B7280;
    margin-bottom:12px;
}

.ptp-trainer-card-rate{
    font-size:16px;
    font-weight:600;
    color:#0A0A0A;
}

/* Tablet Grid */
@media (min-width:768px) {
    .ptp-trainers-grid{
        grid-template-columns:repeat(2,1fr);
        gap:24px;
        padding:24px;
    }
}

/* Desktop Grid */
@media (min-width:1024px) {
    .ptp-trainers-grid{
        grid-template-columns:repeat(3,1fr);
        gap:24px;
        padding:32px;
    }
    
    .ptp-trainer-card{
        cursor:pointer;
    }
    
    .ptp-trainer-card:hover{
        transform:translateY(-4px);
        box-shadow:0 12px 40px rgba(0,0,0,0.15);
    }
}

/* Large Desktop Grid */
@media (min-width:1440px) {
    .ptp-trainers-grid{
        grid-template-columns:repeat(4,1fr);
        max-width:1600px;
        margin:0 auto;
    }
}

/* =============================================
   UTILITY CLASSES
   ============================================= */

.ptp-show-mobile{display:block}
.ptp-show-tablet{display:none}
.ptp-show-desktop{display:none}

@media (min-width:768px) {
    .ptp-show-mobile{display:none}
    .ptp-show-tablet{display:block}
}

@media (min-width:1024px) {
    .ptp-show-tablet{display:none}
    .ptp-show-desktop{display:block}
}

/* Touch-friendly targets */
@media (hover:none) and (pointer:coarse) {
    .ptp-book-btn,
    .ptp-trainer-card,
    button,
    a{
        min-height:44px;
    }
}

/* Reduced motion */
@media (prefers-reduced-motion:reduce) {
    *,*::before,*::after{
        animation-duration:0.01ms !important;
        transition-duration:0.01ms !important;
    }
}
</style>
        <?php
    }
    
    /**
     * Fix scroll issues with JavaScript
     */
    public function fix_scroll_js() {
        if (!$this->is_trainer_page()) {
            return;
        }
        ?>
<script id="ptp-scroll-fix-v142">
(function() {
    'use strict';
    
    // v242: tp210 profile pages handle their own scroll/modal state — skip entirely
    if (document.body.classList.contains('tp210') || document.querySelector('.tp210-wrap')) {
        console.log('[PTP v142] Skipped — tp210 handles own scroll');
        return;
    }
    
    // Remove any classes that might block scrolling
    function enableScroll() {
        document.documentElement.classList.remove('no-scroll', 'modal-open', 'menu-open', 'ptp-drawer-open');
        document.body.classList.remove('no-scroll', 'modal-open', 'menu-open', 'ptp-drawer-open');
        document.documentElement.style.overflow = '';
        document.documentElement.style.position = '';
        document.documentElement.style.height = '';
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.height = '';
    }
    
    // Run on load + DOM ready only — no more setInterval
    enableScroll();
    document.addEventListener('DOMContentLoaded', enableScroll);
    
    // Handle modal state properly
    window.ptpEnableScroll = enableScroll;
    window.ptpDisableScroll = function() {
        document.body.classList.add('ptp-modal-active');
    };
    
    // Ensure content is scrollable
    document.querySelectorAll('.ptp-trainers-grid, .ft-main, .trainer-grid-main').forEach(function(el) {
        el.style.overflowY = 'visible';
        el.style.height = 'auto';
    });
    
    console.log('[PTP v142] Scroll fix applied');
})();
</script>
        <?php
    }
    
    /**
     * Check if current page is a trainer-related page
     */
    private function is_trainer_page() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        return (
            strpos($uri, '/trainer/') !== false ||
            strpos($uri, '/find-trainers') !== false ||
            strpos($uri, '/trainers') !== false ||
            is_page('find-trainers') ||
            is_page('trainers')
        );
    }
    
    /**
     * Register diagnostic REST routes
     */
    public function register_diagnostic_routes() {
        register_rest_route('ptp/v1', '/diagnostics/trainers', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_trainer_diagnostics'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
        
        register_rest_route('ptp/v1', '/diagnostics/fix-trainer/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'fix_trainer'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
    }
    
    /**
     * Get trainer diagnostics
     */
    public function get_trainer_diagnostics($request) {
        global $wpdb;
        
        $trainers = $wpdb->get_results("
            SELECT t.*, 
                   u.user_email,
                   u.display_name as wp_display_name,
                   CASE 
                       WHEN t.status != 'active' THEN 'inactive_status'
                       WHEN t.slug IS NULL OR t.slug = '' THEN 'missing_slug'
                       WHEN t.display_name IS NULL OR t.display_name = '' THEN 'missing_name'
                       WHEN t.hourly_rate IS NULL OR t.hourly_rate = 0 THEN 'no_rate'
                       ELSE 'ok'
                   END as issue
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
            ORDER BY t.id DESC
        ");
        
        $summary = array(
            'total' => count($trainers),
            'active' => 0,
            'inactive' => 0,
            'issues' => array(),
        );
        
        foreach ($trainers as $t) {
            if ($t->status === 'active') {
                $summary['active']++;
            } else {
                $summary['inactive']++;
            }
            
            if ($t->issue !== 'ok') {
                $summary['issues'][$t->issue] = ($summary['issues'][$t->issue] ?? 0) + 1;
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'summary' => $summary,
            'trainers' => $trainers,
        ));
    }
    
    /**
     * Fix individual trainer
     */
    public function fix_trainer($request) {
        global $wpdb;
        
        $trainer_id = intval($request->get_param('id'));
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.display_name as wp_name, u.user_email
             FROM {$wpdb->prefix}ptp_trainers t
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
             WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
        }
        
        $updates = array();
        $fixes = array();
        
        // Fix status
        if (empty($trainer->status) || !in_array($trainer->status, array('active', 'pending', 'inactive'))) {
            $updates['status'] = 'active';
            $fixes[] = 'Set status to active';
        }
        
        // Fix slug
        if (empty($trainer->slug)) {
            $name = $trainer->display_name ?: $trainer->wp_name ?: 'trainer-' . $trainer->id;
            $updates['slug'] = sanitize_title($name);
            $fixes[] = 'Generated slug';
        }
        
        // Fix display name
        if (empty($trainer->display_name)) {
            $updates['display_name'] = $trainer->wp_name ?: 'Trainer ' . $trainer->id;
            $fixes[] = 'Set display name';
        }
        
        // Fix hourly rate
        if (empty($trainer->hourly_rate)) {
            $updates['hourly_rate'] = 60;
            $fixes[] = 'Set default hourly rate';
        }
        
        if (!empty($updates)) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                $updates,
                array('id' => $trainer_id)
            );
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'trainer_id' => $trainer_id,
            'fixes_applied' => $fixes,
            'updates' => $updates,
        ));
    }
    
    /**
     * Admin notice for trainer issues
     */
    public function trainer_status_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'ptp') === false) {
            return;
        }
        
        global $wpdb;
        
        $issues = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers
            WHERE status != 'active' 
               OR status IS NULL 
               OR slug IS NULL 
               OR slug = ''
        ");
        
        if ($issues > 0) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong>PTP Notice:</strong> 
                    <?php echo $issues; ?> trainer(s) may not be visible due to missing data. 
                    <a href="<?php echo admin_url('admin.php?page=ptp-trainers&fix=all'); ?>">Auto-fix now</a> or 
                    <a href="<?php echo rest_url('ptp/v1/diagnostics/trainers'); ?>" target="_blank">View diagnostics</a>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Auto-fix trainer status on save
     */
    public function auto_fix_trainer_status($trainer_id, $data) {
        global $wpdb;
        
        $updates = array();
        
        // Ensure slug exists
        if (empty($data['slug'])) {
            $updates['slug'] = sanitize_title($data['display_name'] ?? 'trainer-' . $trainer_id);
        }
        
        // Set to active if profile is complete
        if (empty($data['status']) || $data['status'] === 'pending') {
            if (!empty($data['hourly_rate']) && !empty($data['bio'])) {
                $updates['status'] = 'active';
            }
        }
        
        if (!empty($updates)) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                $updates,
                array('id' => $trainer_id)
            );
        }
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Trainer_Profile_Fixes::instance();
}, 5);
