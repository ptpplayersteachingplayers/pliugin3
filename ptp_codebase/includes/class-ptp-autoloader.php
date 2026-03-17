<?php
/**
 * PTP Class Autoloader
 * 
 * Implements PSR-4 style autoloading for PTP classes
 * Addresses issue 10.5: Large class files impacting load time
 * 
 * Benefits:
 * - Classes loaded only when needed
 * - Reduces initial memory footprint
 * - Works well with OPcache
 * - Supports lazy loading of heavy classes
 * 
 * @version 72.0.0
 */

defined('ABSPATH') || exit;

class PTP_Autoloader {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Base path for includes
     */
    private $include_path;
    
    /**
     * Class map for direct file lookups
     */
    private $class_map = array();
    
    /**
     * Loaded classes tracking
     */
    private $loaded_classes = array();
    
    /**
     * Get singleton instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - register autoloader
     */
    private function __construct() {
        $this->include_path = PTP_PLUGIN_DIR . 'includes/';
        
        // Build class map
        $this->build_class_map();
        
        // Register autoloader
        spl_autoload_register(array($this, 'autoload'));
    }
    
    /**
     * Build the class map for fast lookups
     */
    private function build_class_map() {
        // Core classes - always needed
        $this->class_map = array(
            // Database
            'PTP_Database' => 'class-ptp-database.php',
            
            // Models
            'PTP_Trainer' => 'class-ptp-trainer.php',
            'PTP_Parent' => 'class-ptp-parent.php',
            'PTP_Player' => 'class-ptp-player.php',
            'PTP_Booking' => 'class-ptp-booking.php',
            
            // V71 Classes (preferred versions)
            'PTP_Ajax_V71' => 'class-ptp-ajax-v71.php',
            'PTP_Availability_V71' => 'class-ptp-availability-v71.php',
            'PTP_Messaging_V71' => 'class-ptp-messaging.php',
            'PTP_Cart_Checkout_V71' => 'class-ptp-cart-checkout-v71.php',
            'PTP_Stripe_Connect_V71' => 'class-ptp-stripe-connect-v71.php',
            'PTP_SMS_V71' => 'class-ptp-sms.php',
            'PTP_Google_Calendar_V71' => 'class-ptp-google-calendar-v71.php',
            
            // Fixes and enhancements
            'PTP_Fixes_V72' => 'class-ptp-fixes-v72.php',
            
            // Admin classes (loaded only in admin)
            'PTP_Admin' => 'class-ptp-admin.php',
            'PTP_Admin_Ajax' => 'class-ptp-admin-ajax.php',
            // PTP_Admin_Payouts v1 removed — superseded by V3
            'PTP_Admin_Applications' => 'class-ptp-admin-applications.php',
            'PTP_Admin_Settings' => 'class-ptp-admin-settings.php',
            
            // Legacy AJAX (fallback)
            'PTP_Ajax' => 'class-ptp-ajax.php',
            
            // Integration classes
            'PTP_Unified_Checkout' => 'class-ptp-unified-checkout.php',
            'PTP_Camp_Crosssell_Everywhere' => 'class-ptp-camp-crosssell-everywhere.php',
            'PTP_Bundle_Checkout' => 'class-ptp-bundle-checkout.php',
            
            // Utility classes
            'PTP_Photo_Upload' => 'class-ptp-photo-upload.php',
            'PTP_Email' => 'class-ptp-email.php',
            'PTP_Analytics' => 'class-ptp-analytics.php',
            'PTP_Escrow' => 'class-ptp-escrow.php',
            'PTP_Payouts' => 'class-ptp-payouts.php',
            
            // Mobile/API
            'PTP_Supabase_Bridge' => 'class-ptp-supabase-bridge.php',
            'PTP_Push_Notifications' => 'class-ptp-push-notifications.php',
            'PTP_FCM' => 'class-ptp-fcm.php',
        );
    }
    
    /**
     * Autoload a class
     */
    public function autoload($class) {
        // Only handle PTP classes
        if (strpos($class, 'PTP_') !== 0) {
            return;
        }
        
        // Check if already loaded
        if (isset($this->loaded_classes[$class])) {
            return;
        }
        
        // Try class map first (fastest)
        if (isset($this->class_map[$class])) {
            $file = $this->include_path . $this->class_map[$class];
            if (file_exists($file)) {
                require_once $file;
                $this->loaded_classes[$class] = true;
                return;
            }
        }
        
        // Try to find file by class name convention
        $file = $this->find_class_file($class);
        if ($file && file_exists($file)) {
            require_once $file;
            $this->loaded_classes[$class] = true;
        }
    }
    
    /**
     * Find class file by naming convention
     */
    private function find_class_file($class) {
        // Convert class name to file name
        // PTP_My_Class -> class-ptp-my-class.php
        $file_name = 'class-' . str_replace('_', '-', strtolower($class)) . '.php';
        $file_path = $this->include_path . $file_name;
        
        if (file_exists($file_path)) {
            return $file_path;
        }
        
        // Try without 'ptp-' prefix for subclasses
        // PTP_Trainer_Profile -> class-ptp-trainer-profile.php (already handled above)
        
        // Try admin directory
        $admin_path = $this->include_path . 'admin/' . $file_name;
        if (file_exists($admin_path)) {
            return $admin_path;
        }
        
        // Try public directory
        $public_path = $this->include_path . 'public/' . $file_name;
        if (file_exists($public_path)) {
            return $public_path;
        }
        
        return false;
    }
    
    /**
     * Check if a class is loaded
     */
    public function is_loaded($class) {
        return isset($this->loaded_classes[$class]);
    }
    
    /**
     * Get list of loaded classes
     */
    public function get_loaded_classes() {
        return array_keys($this->loaded_classes);
    }
    
    /**
     * Preload essential classes for a context
     */
    public function preload($context = 'frontend') {
        $preload_map = array(
            'frontend' => array(
                'PTP_Database',
                'PTP_Trainer',
                'PTP_Booking',
                'PTP_Ajax_V71',
            ),
            'admin' => array(
                'PTP_Database',
                'PTP_Admin',
                'PTP_Trainer',
                'PTP_Booking',
            ),
            'ajax' => array(
                'PTP_Database',
                'PTP_Ajax_V71',
                'PTP_Trainer',
                'PTP_Booking',
                'PTP_Messaging_V71',
            ),
            'checkout' => array(
                'PTP_Database',
                'PTP_Cart_Checkout_V71',
                'PTP_Stripe_Connect_V71',
                'PTP_Trainer',
                'PTP_Booking',
            ),
        );
        
        if (isset($preload_map[$context])) {
            foreach ($preload_map[$context] as $class) {
                if (isset($this->class_map[$class])) {
                    $file = $this->include_path . $this->class_map[$class];
                    if (file_exists($file) && !isset($this->loaded_classes[$class])) {
                        require_once $file;
                        $this->loaded_classes[$class] = true;
                    }
                }
            }
        }
    }
    
    /**
     * Add a class to the map (for extensions)
     */
    public function add_class($class, $file) {
        $this->class_map[$class] = $file;
    }
    
    /**
     * Get memory usage stats
     */
    public function get_stats() {
        return array(
            'loaded_count' => count($this->loaded_classes),
            'loaded_classes' => $this->get_loaded_classes(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        );
    }
}

/**
 * Initialize autoloader
 * Must be called before any PTP class is used
 */
function ptp_init_autoloader() {
    return PTP_Autoloader::instance();
}

// Initialize immediately when this file is included
ptp_init_autoloader();
