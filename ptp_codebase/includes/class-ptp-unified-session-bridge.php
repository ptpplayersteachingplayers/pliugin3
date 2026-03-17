<?php
/**
 * PTP Unified Session Bridge v1.0.0
 * 
 * ISSUE #1 FIX: Dual Session Systems
 * 
 * This class bridges the camps plugin session system (ptp_camp_sessions) 
 * with the training platform's native session system (ptp_native_sessions).
 * 
 * Provides a single session interface for both plugins to prevent cart state
 * desync during unified checkout when mixing camps + training.
 * 
 * @since 160.0.0
 */

defined('ABSPATH') || exit;

class PTP_Unified_Session_Bridge {
    
    private static $instance = null;
    private $session_key = null;
    private $session_data = array();
    private $dirty = false;
    private $loaded = false;
    
    const COOKIE_NAME = 'ptp_unified_session';
    const COOKIE_EXPIRY = 7 * DAY_IN_SECONDS;
    const TABLE_NAME = 'ptp_native_sessions';
    
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
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init_session'), 1);
        add_action('shutdown', array($this, 'save_session'), 20);
        
        // Provide backward compatibility for camps plugin
        add_filter('ptp_camps_get_session', array($this, 'get_session_data'), 10, 2);
        add_action('ptp_camps_set_session', array($this, 'set_session_data'), 10, 3);
        add_action('ptp_camps_delete_session', array($this, 'delete_session_key'), 10, 1);
    }
    
    /**
     * Initialize session
     */
    public function init_session() {
        $this->session_key = $this->get_or_create_session_key();
        $this->load_session();
    }
    
    /**
     * Get or create session key from cookie
     */
    private function get_or_create_session_key() {
        // Check for existing cookie
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            return sanitize_text_field($_COOKIE[self::COOKIE_NAME]);
        }
        
        // Check for PTP session cookie (compatibility)
        if (!empty($_COOKIE['ptp_session'])) {
            $key = sanitize_text_field($_COOKIE['ptp_session']);
            $this->set_cookie($key);
            return $key;
        }
        
        // Check for camps cart cookie (compatibility)
        if (!empty($_COOKIE['ptp_cart_id'])) {
            $key = sanitize_text_field($_COOKIE['ptp_cart_id']);
            $this->set_cookie($key);
            return $key;
        }
        
        // Generate new session key
        $key = wp_generate_uuid4();
        $this->set_cookie($key);
        
        return $key;
    }
    
    /**
     * Set session cookie
     */
    private function set_cookie($key) {
        if (!headers_sent()) {
            setcookie(
                self::COOKIE_NAME,
                $key,
                time() + self::COOKIE_EXPIRY,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
        }
        $_COOKIE[self::COOKIE_NAME] = $key;
    }
    
    /**
     * Load session data from database
     */
    private function load_session() {
        if ($this->loaded) {
            return;
        }
        
        $this->loaded = true;
        
        if (empty($this->session_key)) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $this->create_table();
            return;
        }
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT session_data, expires_at FROM {$table} 
             WHERE session_key = %s AND (expires_at IS NULL OR expires_at > NOW())",
            $this->session_key
        ));
        
        if ($row && !empty($row->session_data)) {
            $this->session_data = maybe_unserialize($row->session_data);
            if (!is_array($this->session_data)) {
                $this->session_data = array();
            }
        }
        
        // Also load from legacy camps session table if exists and merge
        $this->merge_legacy_camps_session();
    }
    
    /**
     * Merge data from legacy camps session table (one-time migration)
     */
    private function merge_legacy_camps_session() {
        global $wpdb;
        
        $camps_table = $wpdb->prefix . 'ptp_camp_sessions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$camps_table'") !== $camps_table) {
            return;
        }
        
        // Check for legacy cart session
        $legacy_cart_key = 'cart_' . $this->session_key;
        $legacy = $wpdb->get_row($wpdb->prepare(
            "SELECT session_value FROM {$camps_table} WHERE session_key = %s AND session_expiry > %d",
            $legacy_cart_key,
            time()
        ));
        
        if ($legacy && !empty($legacy->session_value)) {
            $legacy_data = maybe_unserialize($legacy->session_value);
            if (is_array($legacy_data) && !empty($legacy_data)) {
                // Only merge if we don't already have cart data
                if (empty($this->session_data['camps_cart'])) {
                    $this->session_data['camps_cart'] = $legacy_data;
                    $this->dirty = true;
                    
                    // Delete legacy entry after migration
                    $wpdb->delete($camps_table, array('session_key' => $legacy_cart_key));
                    ptp_log('[PTP Session Bridge] Migrated legacy camps cart session');
                }
            }
        }
        
        // Check for legacy coupon session
        $legacy_coupon_key = 'cart_coupon_' . $this->session_key;
        $legacy_coupon = $wpdb->get_row($wpdb->prepare(
            "SELECT session_value FROM {$camps_table} WHERE session_key = %s AND session_expiry > %d",
            $legacy_coupon_key,
            time()
        ));
        
        if ($legacy_coupon && !empty($legacy_coupon->session_value)) {
            $coupon_data = maybe_unserialize($legacy_coupon->session_value);
            if ($coupon_data && empty($this->session_data['camps_coupon'])) {
                $this->session_data['camps_coupon'] = $coupon_data;
                $this->dirty = true;
                
                $wpdb->delete($camps_table, array('session_key' => $legacy_coupon_key));
                ptp_log('[PTP Session Bridge] Migrated legacy camps coupon session');
            }
        }
    }
    
    /**
     * Save session to database
     */
    public function save_session() {
        if (!$this->dirty || empty($this->session_key)) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $this->create_table();
        }
        
        $user_id = get_current_user_id();
        $expires_at = date('Y-m-d H:i:s', time() + self::COOKIE_EXPIRY);
        
        // Check if session exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT session_id FROM {$table} WHERE session_key = %s",
            $this->session_key
        ));
        
        if ($exists) {
            $wpdb->update(
                $table,
                array(
                    'session_data' => maybe_serialize($this->session_data),
                    'expires_at' => $expires_at,
                    'user_id' => $user_id ?: 0,
                ),
                array('session_key' => $this->session_key),
                array('%s', '%s', '%d'),
                array('%s')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'session_key' => $this->session_key,
                    'user_id' => $user_id ?: 0,
                    'session_data' => maybe_serialize($this->session_data),
                    'expires_at' => $expires_at,
                    'created_at' => current_time('mysql'),
                ),
                array('%s', '%d', '%s', '%s', '%s')
            );
        }
        
        $this->dirty = false;
    }
    
    /**
     * Create session table if it doesn't exist
     */
    private function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            session_data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            UNIQUE KEY session_key (session_key),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Get session key
     */
    public function get_session_key() {
        return $this->session_key;
    }
    
    /**
     * Check if session exists
     */
    public function has_session() {
        return !empty($this->session_key);
    }
    
    /**
     * Get session value
     */
    public function get($key, $default = null) {
        if (!$this->loaded) {
            $this->load_session();
        }
        return isset($this->session_data[$key]) ? $this->session_data[$key] : $default;
    }
    
    /**
     * Set session value
     */
    public function set($key, $value) {
        if (!$this->loaded) {
            $this->load_session();
        }
        $this->session_data[$key] = $value;
        $this->dirty = true;
    }
    
    /**
     * Delete session value
     */
    public function delete($key) {
        if (!$this->loaded) {
            $this->load_session();
        }
        if (isset($this->session_data[$key])) {
            unset($this->session_data[$key]);
            $this->dirty = true;
        }
    }
    
    /**
     * Clear all session data
     */
    public function clear() {
        $this->session_data = array();
        $this->dirty = true;
    }
    
    // =========================================================================
    // CAMPS PLUGIN COMPATIBILITY METHODS
    // These methods provide backward compatibility with the camps plugin
    // =========================================================================
    
    /**
     * Filter: Get session data for camps plugin
     * Replaces PTP_Camps_Database::get_session()
     */
    public function get_session_data($value, $key) {
        // Strip 'cart_' prefix if present (legacy format)
        $clean_key = preg_replace('/^cart_/', 'camps_cart_', $key);
        $clean_key = preg_replace('/^cart_coupon_/', 'camps_coupon_', $clean_key);
        
        return $this->get($clean_key, $value);
    }
    
    /**
     * Action: Set session data for camps plugin
     * Replaces PTP_Camps_Database::set_session()
     */
    public function set_session_data($key, $data, $expiry = null) {
        // Strip 'cart_' prefix if present (legacy format)
        $clean_key = preg_replace('/^cart_/', 'camps_cart_', $key);
        $clean_key = preg_replace('/^cart_coupon_/', 'camps_coupon_', $clean_key);
        
        $this->set($clean_key, $data);
    }
    
    /**
     * Action: Delete session key for camps plugin
     * Replaces PTP_Camps_Database::delete_session()
     */
    public function delete_session_key($key) {
        $clean_key = preg_replace('/^cart_/', 'camps_cart_', $key);
        $clean_key = preg_replace('/^cart_coupon_/', 'camps_coupon_', $clean_key);
        
        $this->delete($clean_key);
    }
    
    /**
     * Cleanup expired sessions (called by cron)
     */
    public static function cleanup_expired_sessions() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $deleted = $wpdb->query(
                "DELETE FROM {$table} WHERE expires_at < NOW()"
            );
            
            if ($deleted > 0) {
                ptp_log("[PTP Session Bridge] Cleaned up {$deleted} expired sessions");
            }
        }
    }
}

/**
 * Global helper function
 */
function ptp_unified_session() {
    return PTP_Unified_Session_Bridge::instance();
}

// Initialize on plugins_loaded
add_action('plugins_loaded', function() {
    PTP_Unified_Session_Bridge::instance();
}, 3); // Before other PTP plugins (usually 5+)

// Schedule cleanup cron
add_action('wp_loaded', function() {
    if (!wp_next_scheduled('ptp_cleanup_sessions')) {
        wp_schedule_event(time(), 'daily', 'ptp_cleanup_sessions');
    }
}, 20);

add_action('ptp_cleanup_sessions', array('PTP_Unified_Session_Bridge', 'cleanup_expired_sessions'));
