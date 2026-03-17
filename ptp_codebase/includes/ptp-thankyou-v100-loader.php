<?php
/**
 * PTP Thank You Page v100 - Viral Machine Loader
 * 
 * This file loads all the thank you page enhancement features.
 * Add this to your main plugin's includes() method:
 * 
 * require_once PTP_PLUGIN_DIR . 'includes/ptp-thankyou-v100-loader.php';
 * 
 * Features included:
 * - Enhanced thank you page template with social announcement opt-in
 * - Referral program integration (uses existing PTP_Referral_System)
 * - One-click upsell for private training
 * - Admin dashboard for managing announcements
 * - Admin settings page for configuration
 * 
 * @since 100.0.0
 */

defined('ABSPATH') || exit;

/**
 * Load Thank You Page v100 Components
 */
class PTP_ThankYou_V100_Loader {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        $plugin_dir = PTP_PLUGIN_DIR;
        
        // Social announcement system
        if (file_exists($plugin_dir . 'includes/class-ptp-social-announcement.php')) {
            require_once $plugin_dir . 'includes/class-ptp-social-announcement.php';
        }
        
        // Thank you page AJAX handlers
        if (file_exists($plugin_dir . 'includes/class-ptp-thankyou-ajax.php')) {
            require_once $plugin_dir . 'includes/class-ptp-thankyou-ajax.php';
        }
        
        // Admin settings (only in admin)
        if (is_admin() && file_exists($plugin_dir . 'admin/class-ptp-thankyou-admin.php')) {
            require_once $plugin_dir . 'admin/class-ptp-thankyou-admin.php';
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Override template for thank you page
        add_filter('ptp_thankyou_template', array($this, 'get_template_path'));
        
        // Set default options on first run
        add_action('admin_init', array($this, 'set_default_options'));
        
        // Add pending count to admin menu
        add_action('admin_menu', array($this, 'add_menu_badges'), 999);
    }
    
    /**
     * Get template path for thank you page
     */
    public function get_template_path($template) {
        $v100_template = PTP_PLUGIN_DIR . 'templates/thank-you-v100.php';
        
        if (file_exists($v100_template)) {
            return $v100_template;
        }
        
        return $template;
    }
    
    /**
     * Set default options
     */
    public function set_default_options() {
        if (get_option('ptp_thankyou_v100_defaults_set')) {
            return;
        }
        
        // Training CTA defaults (replaces upsell)
        if (false === get_option('ptp_thankyou_training_cta_enabled')) {
            add_option('ptp_thankyou_training_cta_enabled', 'yes');
        }
        if (false === get_option('ptp_thankyou_training_cta_url')) {
            add_option('ptp_thankyou_training_cta_url', '/find-trainers/');
        }
        
        // Announcement defaults
        if (false === get_option('ptp_thankyou_announcement_enabled')) {
            add_option('ptp_thankyou_announcement_enabled', 'yes');
        }
        if (false === get_option('ptp_instagram_handle')) {
            add_option('ptp_instagram_handle', '@ptpsoccercamps');
        }
        
        // Referral defaults
        if (false === get_option('ptp_thankyou_referral_enabled')) {
            add_option('ptp_thankyou_referral_enabled', 'yes');
        }
        if (false === get_option('ptp_referral_amount')) {
            add_option('ptp_referral_amount', 25);
        }
        
        update_option('ptp_thankyou_v100_defaults_set', true);
    }
    
    /**
     * Add badges to admin menu items
     */
    public function add_menu_badges() {
        global $submenu;
        
        if (!isset($submenu['ptp-admin'])) {
            return;
        }
        
        // Get pending announcement count
        $pending_count = 0;
        if (class_exists('PTP_Social_Announcement')) {
            $pending_count = PTP_Social_Announcement::get_pending_count();
        }
        
        if ($pending_count > 0) {
            foreach ($submenu['ptp-admin'] as $key => $item) {
                if ($item[2] === 'ptp-announcements') {
                    $submenu['ptp-admin'][$key][0] .= sprintf(
                        ' <span class="awaiting-mod count-%d"><span class="pending-count">%d</span></span>',
                        $pending_count,
                        $pending_count
                    );
                    break;
                }
            }
        }
    }
}

// Initialize loader
add_action('plugins_loaded', function() {
    PTP_ThankYou_V100_Loader::instance();
}, 20);

/**
 * Helper function to redirect to new thank you page template
 * 
 * Call this in your templates.php or shortcode handler
 */
function ptp_load_thankyou_v100() {
    $template = PTP_PLUGIN_DIR . 'templates/thank-you-v100.php';
    
    if (file_exists($template)) {
        include $template;
        return true;
    }
    
    return false;
}
