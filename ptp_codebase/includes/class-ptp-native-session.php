<?php
/**
 * PTP Native Session Manager
 *
 * Replaces PTP Native session with native PHP sessions + database persistence.
 * Provides the same API as Native Session for easy migration.
 *
 * @version 1.0.0
 * @since 148.0.0
 */

defined('ABSPATH') || exit;

class PTP_Native_Session {

    /** @var PTP_Native_Session */
    private static $instance = null;

    /** @var string */
    private $session_key = '';

    /** @var array */
    private $data = array();

    /** @var bool */
    private $dirty = false;

    /** @var bool */
    private $has_session = false;

    /** @var string */
    private $table_name;

    /** @var bool */
    private $initialized = false;

    /** Cookie name */
    const COOKIE_NAME = 'ptp_session';

    /** Session expiry (7 days) */
    const SESSION_EXPIRY = 604800;

    /**
     * Get instance (singleton)
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the session system
     */
    public static function init() {
        self::instance();
    }

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ptp_native_sessions';

        // Initialize session on init (priority 1 = very early)
        add_action('init', array($this, 'init_session'), 1);

        // Save session on shutdown
        add_action('shutdown', array($this, 'save_session'), 20);

        // Cleanup expired sessions daily
        add_action('ptp_cleanup_native_sessions', array($this, 'cleanup_expired_sessions'));
        if (!wp_next_scheduled('ptp_cleanup_native_sessions')) {
            wp_schedule_event(time(), 'daily', 'ptp_cleanup_native_sessions');
        }
    }

    /**
     * Initialize session
     */
    public function init_session() {
        if ($this->initialized) {
            return;
        }

        // Get or create session key
        $this->session_key = $this->get_or_create_session_key();

        // Load session data
        $this->load_session();

        // Set cookie if needed
        $this->set_session_cookie();

        $this->initialized = true;
    }

    /**
     * Get session key from cookie or generate new one
     */
    private function get_or_create_session_key() {
        // Check for existing cookie
        if (isset($_COOKIE[self::COOKIE_NAME]) && !empty($_COOKIE[self::COOKIE_NAME])) {
            return sanitize_text_field($_COOKIE[self::COOKIE_NAME]);
        }

        // Check for logged-in user
        if (is_user_logged_in()) {
            return 'user_' . get_current_user_id();
        }

        // Generate new guest session key
        return $this->generate_session_key();
    }

    /**
     * Generate unique session key
     */
    private function generate_session_key() {
        return 'ptp_' . bin2hex(random_bytes(16));
    }

    /**
     * Load session data from database
     */
    private function load_session() {
        global $wpdb;

        // Check if table exists first
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if (!$table_exists) {
            $this->data = array();
            $this->has_session = false;
            return;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT session_data, expires_at FROM {$this->table_name}
             WHERE session_key = %s AND expires_at > NOW()",
            $this->session_key
        ));

        if ($row) {
            $this->data = maybe_unserialize($row->session_data);
            $this->has_session = true;

            if (!is_array($this->data)) {
                $this->data = array();
            }
        } else {
            $this->data = array();
            $this->has_session = false;
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

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if (!$table_exists) {
            return;
        }

        $expires = date('Y-m-d H:i:s', time() + self::SESSION_EXPIRY);
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;

        $wpdb->replace(
            $this->table_name,
            array(
                'session_key' => $this->session_key,
                'user_id' => $user_id,
                'session_data' => maybe_serialize($this->data),
                'expires_at' => $expires,
            ),
            array('%s', '%d', '%s', '%s')
        );

        $this->dirty = false;
        $this->has_session = true;
    }

    /**
     * Set session cookie
     */
    private function set_session_cookie() {
        if (headers_sent() || empty($this->session_key)) {
            return;
        }

        // Don't set cookie for logged-in users (they use user ID)
        if (is_user_logged_in()) {
            return;
        }

        // Check if cookie already set
        if (isset($_COOKIE[self::COOKIE_NAME]) && $_COOKIE[self::COOKIE_NAME] === $this->session_key) {
            return;
        }

        // Set cookie
        $secure = is_ssl();
        $expiry = time() + self::SESSION_EXPIRY;

        setcookie(
            self::COOKIE_NAME,
            $this->session_key,
            $expiry,
            COOKIEPATH ?: '/',
            COOKIE_DOMAIN ?: '',
            $secure,
            true // httponly
        );

        $_COOKIE[self::COOKIE_NAME] = $this->session_key;
    }

    /**
     * Set session cookie (public method for compatibility)
     * Matches ptp_session()->set_customer_session_cookie()
     */
    public function set_customer_session_cookie($set = true) {
        if ($set) {
            $this->set_session_cookie();
            $this->has_session = true;
        }
    }

    /**
     * Check if session exists
     * Matches ptp_session()->has_session()
     */
    public function has_session() {
        return $this->has_session || !empty($this->data);
    }

    /**
     * Get session value
     * Matches ptp_session()->get()
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null) {
        // Initialize if not done yet
        if (!$this->initialized) {
            $this->init_session();
        }
        return isset($this->data[$key]) ? $this->data[$key] : $default;
    }

    /**
     * Set session value
     * Matches ptp_session()->set()
     *
     * @param string $key
     * @param mixed $value
     */
    public function set($key, $value) {
        // Initialize if not done yet
        if (!$this->initialized) {
            $this->init_session();
        }

        if (!isset($this->data[$key]) || $this->data[$key] !== $value) {
            $this->data[$key] = $value;
            $this->dirty = true;
        }
    }

    /**
     * Delete session value
     *
     * @param string $key
     */
    public function delete($key) {
        if (isset($this->data[$key])) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /**
     * Get all session data
     *
     * @return array
     */
    public function get_all() {
        return $this->data;
    }

    /**
     * Clear all session data
     */
    public function clear() {
        $this->data = array();
        $this->dirty = true;
    }

    /**
     * Destroy session completely
     */
    public function destroy() {
        global $wpdb;

        // Delete from database
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if ($table_exists) {
            $wpdb->delete(
                $this->table_name,
                array('session_key' => $this->session_key),
                array('%s')
            );
        }

        // Clear cookie
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '');
        }

        // Reset state
        $this->data = array();
        $this->has_session = false;
        $this->dirty = false;
    }

    /**
     * Get session key
     */
    public function get_session_key() {
        if (!$this->initialized) {
            $this->init_session();
        }
        return $this->session_key;
    }

    /**
     * Cleanup expired sessions (cron job)
     */
    public function cleanup_expired_sessions() {
        global $wpdb;

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if ($table_exists) {
            $wpdb->query(
                "DELETE FROM {$this->table_name} WHERE expires_at < NOW()"
            );
        }
    }

    /**
     * Create database table
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ptp_native_sessions';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            session_data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            UNIQUE KEY session_key (session_key),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Migrate PTP Native session to PTP session
     * Call this during plugin activation if WC was previously used
     */
    public function migrate_legacy_session() {
        if (!function_exists('ptp_session') || !ptp_session()) {
            return false;
        }

        // Get common session keys used by PTP
        $keys_to_migrate = array(
            'ptp_training_items',
            'ptp_current_training',
            'ptp_active_bundle',
            'ptp_bundle_code',
            'ptp_selected_trainer',
            'ptp_booking_data',
            'ptp_jersey_upsell',
            'ptp_referral_code',
            'ptp_cart_fees',
            'ptp_cart_discounts',
        );

        foreach ($keys_to_migrate as $key) {
            $value = ptp_session()->get($key);
            if ($value !== null) {
                $this->set($key, $value);
            }
        }

        $this->save_session();

        return true;
    }
}

/**
 * Global helper function
 */
if (!function_exists('ptp_session')) {
    function ptp_session() {
        return PTP_Native_Session::instance();
    }
}

// Initialize on plugins_loaded
add_action('plugins_loaded', function() {
    PTP_Native_Session::instance();
}, 5);
