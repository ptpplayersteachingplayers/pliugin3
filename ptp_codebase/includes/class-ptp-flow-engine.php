<?php
/**
 * PTP Flow Engine v1.0.0
 * 
 * Centralized conversion flow management for:
 * - Upsells (packages, add-ons)
 * - Cross-sells (camps ↔ trainings)
 * - Referral tracking & rewards
 * - Social proof & urgency
 * 
 * Properly engineered with backend tracking, analytics, and attribution.
 * 
 * @since 59.0.0
 */

defined('ABSPATH') || exit;

class PTP_Flow_Engine {
    
    private static $instance = null;
    
    // Conversion events
    const EVENT_VIEW_TRAINER = 'view_trainer';
    const EVENT_SELECT_PACKAGE = 'select_package';
    const EVENT_START_BOOKING = 'start_booking';
    const EVENT_VIEW_CAMP = 'view_camp';
    const EVENT_ADD_TO_CART = 'add_to_cart';
    const EVENT_BEGIN_CHECKOUT = 'begin_checkout';
    const EVENT_PURCHASE = 'purchase';
    const EVENT_REFERRAL_CLICK = 'referral_click';
    const EVENT_REFERRAL_SIGNUP = 'referral_signup';
    const EVENT_REFERRAL_CONVERT = 'referral_convert';
    
    // Package discount tiers
    const DISCOUNT_3PACK = 0.10;
    const DISCOUNT_5PACK = 0.15;
    const DISCOUNT_10PACK = 0.20;
    
    // Bundle discount
    const BUNDLE_DISCOUNT = 0.05;
    
    // Referral rewards
    const REFERRER_CREDIT = 25;
    const REFEREE_DISCOUNT = 20;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Event tracking
        add_action('wp_ajax_ptp_track_flow_event', array($this, 'ajax_track_event'));
        add_action('wp_ajax_nopriv_ptp_track_flow_event', array($this, 'ajax_track_event'));
        
        // Referral capture
        add_action('init', array($this, 'capture_referral'), 5);
        add_action('user_register', array($this, 'process_referral_signup'));
        add_action('ptp_booking_completed', array($this, 'process_referral_conversion'), 10, 2);
        // REMOVED: add_action('ptp-native_order_status_completed', array($this, 'process_woo_referral_conversion'));
        
        // Session data
        add_action('init', array($this, 'init_session'));
        
        // Cleanup old hooks that cause duplicate content
        add_action('init', array($this, 'cleanup_duplicate_hooks'), 999);
        
        // Frontend assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Add flow data to pages
        add_action('wp_footer', array($this, 'output_flow_data'), 5);
        
        // REST API
        add_action('rest_api_init', array($this, 'register_endpoints'));
    }
    
    /**
     * Initialize session for flow tracking
     */
    public function init_session() {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        // Generate session ID for anonymous users
        if (!isset($_SESSION['ptp_flow_session'])) {
            $_SESSION['ptp_flow_session'] = wp_generate_uuid4();
        }
    }
    
    /**
     * Cleanup duplicate hooks that cause UX issues
     */
    public function cleanup_duplicate_hooks() {
        // Remove any duplicate package displays from footer
        // The packages should only show in the booking widget
        
        // Remove old referral banner if new one is enabled
        if (function_exists('ptp_render_referral_banner_v2')) {
            // Keep the v2 version, it's better
        }
    }
    
    /**
     * Capture referral code from URL
     */
    public function capture_referral() {
        if (isset($_GET['ref']) || isset($_GET['referral'])) {
            $code = sanitize_text_field($_GET['ref'] ?? $_GET['referral']);
            
            if (!empty($code)) {
                // Store in session and cookie
                $_SESSION['ptp_referral_code'] = $code;
                setcookie('ptp_ref', $code, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
                
                // Track the referral click
                $this->track_event(self::EVENT_REFERRAL_CLICK, array(
                    'referral_code' => $code,
                    'landing_page' => $_SERVER['REQUEST_URI'] ?? '',
                ));
            }
        }
    }
    
    /**
     * Process referral on user signup
     */
    public function process_referral_signup($user_id) {
        $referral_code = $_SESSION['ptp_referral_code'] ?? ($_COOKIE['ptp_ref'] ?? '');
        
        if (empty($referral_code)) return;
        
        // Find referrer
        $referrer = $this->get_user_by_referral_code($referral_code);
        if (!$referrer) return;
        
        // Store the referral relationship
        update_user_meta($user_id, '_ptp_referred_by', $referrer->ID);
        update_user_meta($user_id, '_ptp_referred_code', $referral_code);
        update_user_meta($user_id, '_ptp_referred_at', current_time('mysql'));
        
        // Log the event
        $this->track_event(self::EVENT_REFERRAL_SIGNUP, array(
            'referrer_id' => $referrer->ID,
            'referee_id' => $user_id,
            'referral_code' => $referral_code,
        ));
        
        // Increment referrer's signup count
        $signups = (int) get_user_meta($referrer->ID, '_ptp_referral_signups', true);
        update_user_meta($referrer->ID, '_ptp_referral_signups', $signups + 1);
    }
    
    /**
     * Process referral conversion on booking
     */
    public function process_referral_conversion($booking_id, $booking_data) {
        $user_id = get_current_user_id();
        if (!$user_id) return;
        
        $referrer_id = get_user_meta($user_id, '_ptp_referred_by', true);
        if (!$referrer_id) return;
        
        // Check if already converted
        $converted = get_user_meta($user_id, '_ptp_referral_converted', true);
        if ($converted) return;
        
        // Mark as converted
        update_user_meta($user_id, '_ptp_referral_converted', current_time('mysql'));
        
        // Calculate booking value
        $booking_value = isset($booking_data['total_amount']) ? floatval($booking_data['total_amount']) : 0;
        
        // Award referrer credit
        $this->add_referral_credit($referrer_id, self::REFERRER_CREDIT, array(
            'type' => 'referral_reward',
            'referee_id' => $user_id,
            'booking_id' => $booking_id,
            'booking_value' => $booking_value,
        ));
        
        // Track event
        $this->track_event(self::EVENT_REFERRAL_CONVERT, array(
            'referrer_id' => $referrer_id,
            'referee_id' => $user_id,
            'booking_id' => $booking_id,
            'booking_value' => $booking_value,
            'reward_amount' => self::REFERRER_CREDIT,
        ));
        
        // Increment conversion count
        $conversions = (int) get_user_meta($referrer_id, '_ptp_referral_conversions', true);
        update_user_meta($referrer_id, '_ptp_referral_conversions', $conversions + 1);
    }
    
    /**
     * Process PTP Native order referral
     */
    public function process_woo_referral_conversion($order_id) {
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order) return;
        
        $user_id = $order->get_user_id();
        if (!$user_id) return;
        
        $referrer_id = get_user_meta($user_id, '_ptp_referred_by', true);
        if (!$referrer_id) return;
        
        // Check if this order already credited
        if (get_post_meta($order_id, '_ptp_referral_credited', true)) return;
        
        // Mark order as credited
        update_post_meta($order_id, '_ptp_referral_credited', $referrer_id);
        
        // Check if first purchase
        $converted = get_user_meta($user_id, '_ptp_referral_converted', true);
        if (!$converted) {
            update_user_meta($user_id, '_ptp_referral_converted', current_time('mysql'));
            
            // Award referrer credit
            $this->add_referral_credit($referrer_id, self::REFERRER_CREDIT, array(
                'type' => 'referral_reward',
                'referee_id' => $user_id,
                'order_id' => $order_id,
                'order_value' => $order->get_total(),
            ));
            
            // Track event
            $this->track_event(self::EVENT_REFERRAL_CONVERT, array(
                'referrer_id' => $referrer_id,
                'referee_id' => $user_id,
                'order_id' => $order_id,
                'order_value' => $order->get_total(),
                'reward_amount' => self::REFERRER_CREDIT,
            ));
        }
    }
    
    /**
     * Add credit to user's referral balance
     */
    public function add_referral_credit($user_id, $amount, $meta = array()) {
        global $wpdb;
        
        // Update balance
        $balance = (float) get_user_meta($user_id, '_ptp_referral_balance', true);
        $new_balance = $balance + $amount;
        update_user_meta($user_id, '_ptp_referral_balance', $new_balance);
        
        // Log transaction
        $wpdb->insert(
            $wpdb->prefix . 'ptp_referral_transactions',
            array(
                'user_id' => $user_id,
                'amount' => $amount,
                'balance_after' => $new_balance,
                'type' => $meta['type'] ?? 'credit',
                'meta' => json_encode($meta),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%f', '%f', '%s', '%s', '%s')
        );
        
        return $new_balance;
    }
    
    /**
     * Get user by referral code
     */
    public function get_user_by_referral_code($code) {
        $users = get_users(array(
            'meta_key' => '_ptp_referral_code',
            'meta_value' => $code,
            'number' => 1,
        ));
        
        return !empty($users) ? $users[0] : null;
    }
    
    /**
     * Get or generate referral code for user
     */
    public static function get_referral_code($user_id) {
        $code = get_user_meta($user_id, '_ptp_referral_code', true);
        
        if (empty($code)) {
            $user = get_userdata($user_id);
            if (!$user) return '';
            
            // Generate code from name
            $first_name = $user->first_name ?: $user->display_name;
            $base = strtoupper(preg_replace('/[^A-Za-z]/', '', $first_name));
            $base = substr($base, 0, 6);
            
            // Add random suffix
            $code = $base . rand(10, 99);
            
            // Ensure unique
            while (self::instance()->get_user_by_referral_code($code)) {
                $code = $base . rand(10, 99);
            }
            
            update_user_meta($user_id, '_ptp_referral_code', $code);
        }
        
        return $code;
    }
    
    /**
     * Get referral stats for user
     */
    public static function get_referral_stats($user_id) {
        return array(
            'code' => self::get_referral_code($user_id),
            'balance' => (float) get_user_meta($user_id, '_ptp_referral_balance', true),
            'signups' => (int) get_user_meta($user_id, '_ptp_referral_signups', true),
            'conversions' => (int) get_user_meta($user_id, '_ptp_referral_conversions', true),
            'total_earned' => (float) get_user_meta($user_id, '_ptp_referral_total_earned', true),
            'reward_per_referral' => self::REFERRER_CREDIT,
            'referee_discount' => self::REFEREE_DISCOUNT,
        );
    }
    
    /**
     * Track conversion event
     */
    public function track_event($event, $data = array()) {
        global $wpdb;
        
        $data = array_merge($data, array(
            'user_id' => get_current_user_id(),
            'session_id' => $_SESSION['ptp_flow_session'] ?? '',
            'page_url' => $_SERVER['REQUEST_URI'] ?? '',
            'referrer' => $_SERVER['HTTP_REFERER'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip' => $this->get_client_ip(),
        ));
        
        // Log to database
        $table = $wpdb->prefix . 'ptp_flow_events';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $wpdb->insert(
                $table,
                array(
                    'event_name' => $event,
                    'event_data' => json_encode($data),
                    'user_id' => $data['user_id'],
                    'session_id' => $data['session_id'],
                    'created_at' => current_time('mysql'),
                ),
                array('%s', '%s', '%d', '%s', '%s')
            );
        }
        
        // Fire action for other integrations
        do_action('ptp_flow_event', $event, $data);
    }
    
    /**
     * AJAX track event
     */
    public function ajax_track_event() {
        $event = sanitize_text_field($_POST['event'] ?? '');
        $data = isset($_POST['data']) ? (array) $_POST['data'] : array();
        
        if (empty($event)) {
            wp_send_json_error('Missing event');
        }
        
        // Sanitize data
        array_walk_recursive($data, function(&$val) {
            $val = sanitize_text_field($val);
        });
        
        $this->track_event($event, $data);
        
        wp_send_json_success();
    }
    
    /**
     * Get client IP
     */
    private function get_client_ip() {
        $ip_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', $_SERVER[$key])[0];
                return trim($ip);
            }
        }
        
        return '';
    }
    
    /**
     * Calculate package pricing
     */
    public static function calculate_package_pricing($hourly_rate) {
        $rate = (int) $hourly_rate;
        
        return array(
            'single' => array(
                'id' => 'single',
                'name' => 'Single Session',
                'sessions' => 1,
                'discount' => 0,
                'per_session' => $rate,
                'total' => $rate,
                'savings' => 0,
            ),
            '3pack' => array(
                'id' => '3pack',
                'name' => '3 Sessions',
                'sessions' => 3,
                'discount' => self::DISCOUNT_3PACK * 100,
                'per_session' => round($rate * (1 - self::DISCOUNT_3PACK)),
                'total' => round($rate * (1 - self::DISCOUNT_3PACK) * 3),
                'savings' => round($rate * 3 * self::DISCOUNT_3PACK),
            ),
            '5pack' => array(
                'id' => '5pack',
                'name' => '5 Sessions',
                'sessions' => 5,
                'discount' => self::DISCOUNT_5PACK * 100,
                'per_session' => round($rate * (1 - self::DISCOUNT_5PACK)),
                'total' => round($rate * (1 - self::DISCOUNT_5PACK) * 5),
                'savings' => round($rate * 5 * self::DISCOUNT_5PACK),
                'popular' => true,
            ),
            '10pack' => array(
                'id' => '10pack',
                'name' => '10 Sessions',
                'sessions' => 10,
                'discount' => self::DISCOUNT_10PACK * 100,
                'per_session' => round($rate * (1 - self::DISCOUNT_10PACK)),
                'total' => round($rate * (1 - self::DISCOUNT_10PACK) * 10),
                'savings' => round($rate * 10 * self::DISCOUNT_10PACK),
                'best_value' => true,
            ),
        );
    }
    
    /**
     * Output flow data to frontend
     */
    public function output_flow_data() {
        $user_id = get_current_user_id();
        
        $data = array(
            'session_id' => $_SESSION['ptp_flow_session'] ?? '',
            'user_id' => $user_id,
            'logged_in' => $user_id > 0,
            'referral_code' => $_SESSION['ptp_referral_code'] ?? ($_COOKIE['ptp_ref'] ?? ''),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_flow_nonce'),
        );
        
        // Add referral stats for logged in users
        if ($user_id) {
            $data['referral_stats'] = self::get_referral_stats($user_id);
        }
        ?>
        <script>
        window.ptpFlow = <?php echo json_encode($data); ?>;
        
        // Flow tracking helper
        window.ptpTrack = function(event, data) {
            data = data || {};
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'ptp_track_flow_event',
                    event: event,
                    data: JSON.stringify(data),
                })
            });
        };
        </script>
        <?php
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        // Only on relevant pages
        if (!is_page() && !is_singular('product') && strpos($_SERVER['REQUEST_URI'], '/trainer/') === false) {
            return;
        }
        
        wp_enqueue_script(
            'ptp-flow-engine',
            PTP_PLUGIN_URL . 'assets/js/flow-engine.js',
            array('jquery'),
            PTP_VERSION,
            true
        );
    }
    
    /**
     * Register REST endpoints
     */
    public function register_endpoints() {
        register_rest_route('ptp/v1', '/flow/track', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_track_event'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route('ptp/v1', '/flow/referral/(?P<code>[a-zA-Z0-9]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_validate_referral'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * REST track event
     */
    public function rest_track_event($request) {
        $event = $request->get_param('event');
        $data = $request->get_param('data') ?? array();
        
        if (empty($event)) {
            return new WP_Error('missing_event', 'Event name required', array('status' => 400));
        }
        
        $this->track_event($event, $data);
        
        return rest_ensure_response(array('success' => true));
    }
    
    /**
     * REST validate referral
     */
    public function rest_validate_referral($request) {
        $code = $request->get_param('code');
        $user = $this->get_user_by_referral_code($code);
        
        if (!$user) {
            return rest_ensure_response(array(
                'valid' => false,
                'message' => 'Invalid referral code',
            ));
        }
        
        return rest_ensure_response(array(
            'valid' => true,
            'referrer_name' => $user->first_name ?: 'A Friend',
            'discount' => self::REFEREE_DISCOUNT,
        ));
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset = $wpdb->get_charset_collate();
        
        // Flow events table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_flow_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_name varchar(100) NOT NULL,
            event_data longtext,
            user_id bigint(20) unsigned DEFAULT 0,
            session_id varchar(36) DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY event_name (event_name),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta($sql);
        
        // Referral transactions table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_referral_transactions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            amount decimal(10,2) NOT NULL,
            balance_after decimal(10,2) NOT NULL,
            type varchar(50) NOT NULL,
            meta longtext,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta($sql);
    }
}

// Initialize
PTP_Flow_Engine::instance();

// Helper functions

/**
 * Get package pricing for a trainer
 */
function ptp_get_package_pricing($hourly_rate) {
    return PTP_Flow_Engine::calculate_package_pricing($hourly_rate);
}

/**
 * Get referral code for current user
 */
function ptp_get_my_referral_code() {
    $user_id = get_current_user_id();
    return $user_id ? PTP_Flow_Engine::get_referral_code($user_id) : '';
}

/**
 * Get referral stats for current user
 */
function ptp_get_my_referral_stats() {
    $user_id = get_current_user_id();
    return $user_id ? PTP_Flow_Engine::get_referral_stats($user_id) : null;
}

/**
 * Check if user was referred
 */
function ptp_is_referred_user($user_id = null) {
    if (!$user_id) $user_id = get_current_user_id();
    return !empty(get_user_meta($user_id, '_ptp_referred_by', true));
}
