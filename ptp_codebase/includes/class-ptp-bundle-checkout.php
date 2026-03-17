<?php
/**
 * PTP Bundle Checkout v1.0.0
 * 
 * Unified checkout system for Training + Camps bundles.
 * Consolidates all bundle logic in ONE place:
 * - Bundle creation and state management
 * - Combined checkout for training + camp
 * - Stripe payment processing
 * - Discount calculations
 * - Session tracking
 * 
 * @since 59.4.0
 */

defined('ABSPATH') || exit;

class PTP_Bundle_Checkout {
    
    private static $instance = null;
    
    // Discount settings
    const BUNDLE_DISCOUNT_PERCENT = 5;  // 5% off when booking training + camp together
    const BUNDLE_EXPIRY_HOURS = 48;      // Bundle expires after 48 hours
    
    // Session keys
    const SESSION_KEY_BUNDLE = 'ptp_bundle_data';
    const SESSION_KEY_PENDING = 'ptp_pending_bundle';
    const COOKIE_BUNDLE = 'ptp_bundle';
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Initialize on WordPress init
        add_action('init', array($this, 'init'), 5);
        
        // Register shortcodes
        add_shortcode('ptp_bundle_checkout', array($this, 'render_bundle_checkout_shortcode'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_create_bundle', array($this, 'ajax_create_bundle'));
        add_action('wp_ajax_nopriv_ptp_create_bundle', array($this, 'ajax_create_bundle'));
        add_action('wp_ajax_ptp_add_camp_to_bundle', array($this, 'ajax_add_camp_to_bundle'));
        add_action('wp_ajax_nopriv_ptp_add_camp_to_bundle', array($this, 'ajax_add_camp_to_bundle'));
        add_action('wp_ajax_ptp_process_bundle_checkout', array($this, 'ajax_process_bundle_checkout'));
        add_action('wp_ajax_nopriv_ptp_process_bundle_checkout', array($this, 'ajax_process_bundle_checkout'));
        add_action('wp_ajax_ptp_confirm_bundle_payment', array($this, 'ajax_confirm_bundle_payment'));
        add_action('wp_ajax_nopriv_ptp_confirm_bundle_payment', array($this, 'ajax_confirm_bundle_payment'));
        add_action('wp_ajax_ptp_get_bundle_status', array($this, 'ajax_get_bundle_status'));
        add_action('wp_ajax_nopriv_ptp_get_bundle_status', array($this, 'ajax_get_bundle_status'));
        add_action('wp_ajax_ptp_clear_bundle', array($this, 'ajax_clear_bundle'));
        add_action('wp_ajax_nopriv_ptp_clear_bundle', array($this, 'ajax_clear_bundle'));
        
        // Inject bundle UI on relevant pages
        add_action('wp_footer', array($this, 'render_bundle_modal'));
        // Disabled: Sticky bundle banner was interfering with mobile UX
        // add_action('wp_footer', array($this, 'render_bundle_banner'));
        
        // Hook into training checkout to check for bundles
        add_filter('ptp_checkout_total', array($this, 'apply_bundle_discount_to_training'), 10, 2);
        
        // Hook into PTP Native to apply discounts
        // REMOVED: add_action('ptp-native_cart_calculate_fees', array($this, 'apply_bundle_discount_to_woo_cart'));
        
        // After PTP Native order, show training completion CTA
        // REMOVED: add_action('ptp-native_thankyou', array($this, 'show_complete_bundle_cta'), 5);
        
        // REST API endpoint
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Initialize
     */
    public function init() {
        // Start session if needed
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        // Check for bundle param in URL
        $this->capture_bundle_from_url();
        
        // Ensure tables exist
        $this->maybe_create_tables();
    }
    
    /**
     * Create database table for bundles
     */
    private function maybe_create_tables() {
        if (get_option('ptp_bundle_checkout_table_version') === '1.0') {
            return;
        }
        
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_bundles (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            bundle_code varchar(32) NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            session_id varchar(64) DEFAULT NULL,
            
            -- Training details
            trainer_id bigint(20) UNSIGNED DEFAULT NULL,
            training_date date DEFAULT NULL,
            training_time time DEFAULT NULL,
            training_location varchar(255) DEFAULT NULL,
            training_package varchar(20) DEFAULT 'single',
            training_sessions int DEFAULT 1,
            training_amount decimal(10,2) DEFAULT 0,
            training_booking_id bigint(20) UNSIGNED DEFAULT NULL,
            training_status varchar(20) DEFAULT 'pending',
            
            -- Camp details
            camp_product_id bigint(20) UNSIGNED DEFAULT NULL,
            camp_quantity int DEFAULT 1,
            camp_amount decimal(10,2) DEFAULT 0,
            camp_order_id bigint(20) UNSIGNED DEFAULT NULL,
            camp_status varchar(20) DEFAULT 'pending',
            
            -- Bundle totals
            subtotal decimal(10,2) DEFAULT 0,
            discount_percent decimal(5,2) DEFAULT 15,
            discount_amount decimal(10,2) DEFAULT 0,
            total_amount decimal(10,2) DEFAULT 0,
            
            -- Payment
            payment_intent_id varchar(255) DEFAULT NULL,
            payment_status varchar(20) DEFAULT 'pending',
            
            -- Meta
            status varchar(20) DEFAULT 'active',
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            
            PRIMARY KEY (id),
            UNIQUE KEY bundle_code (bundle_code),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY trainer_id (trainer_id),
            KEY status (status)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        update_option('ptp_bundle_checkout_table_version', '1.0');
    }
    
    /**
     * Capture bundle code from URL
     */
    private function capture_bundle_from_url() {
        if (isset($_GET['bundle'])) {
            $bundle_code = sanitize_text_field($_GET['bundle']);
            if ($bundle_code) {
                $this->set_active_bundle($bundle_code);
            }
        }
    }
    
    /**
     * Generate unique bundle code
     */
    public static function generate_bundle_code() {
        // Use secure UUID generation instead of predictable md5(uniqid())
        return 'BND-' . strtoupper(wp_generate_password(8, false, false));
    }
    
    /**
     * Get session ID for anonymous users
     */
    private function get_session_id() {
        if (!isset($_COOKIE['ptp_session'])) {
            $session_id = wp_generate_uuid4();
            setcookie('ptp_session', $session_id, time() + 30 * DAY_IN_SECONDS, '/');
            $_COOKIE['ptp_session'] = $session_id;
        }
        return sanitize_text_field($_COOKIE['ptp_session']);
    }
    
    /**
     * Set active bundle in session/cookie
     */
    public function set_active_bundle($bundle_code) {
        $_SESSION[self::SESSION_KEY_BUNDLE] = $bundle_code;
        setcookie(self::COOKIE_BUNDLE, $bundle_code, time() + self::BUNDLE_EXPIRY_HOURS * HOUR_IN_SECONDS, '/');
        $_COOKIE[self::COOKIE_BUNDLE] = $bundle_code;
        
        // Also set in PTP Native session if available
        if (false && ptp_session()) {
            ptp_session()->set('ptp_active_bundle', $bundle_code);
            ptp_session()->set('ptp_bundle_discount', true);
        }
    }
    
    /**
     * Get active bundle code
     */
    public function get_active_bundle_code() {
        // Check session first
        if (!empty($_SESSION[self::SESSION_KEY_BUNDLE])) {
            return sanitize_text_field($_SESSION[self::SESSION_KEY_BUNDLE]);
        }
        
        // Check cookie
        if (!empty($_COOKIE[self::COOKIE_BUNDLE])) {
            return sanitize_text_field($_COOKIE[self::COOKIE_BUNDLE]);
        }
        
        // Check PTP Native session
        if (false && ptp_session()) {
            $bundle = ptp_session()->get('ptp_active_bundle');
            if ($bundle) {
                return sanitize_text_field($bundle);
            }
        }
        
        return null;
    }
    
    /**
     * Get active bundle data
     */
    public function get_active_bundle() {
        $bundle_code = $this->get_active_bundle_code();
        if (!$bundle_code) {
            return null;
        }
        
        return $this->get_bundle_by_code($bundle_code);
    }
    
    /**
     * Get bundle by code
     */
    public function get_bundle_by_code($bundle_code) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bundles 
             WHERE bundle_code = %s 
             AND status IN ('active', 'partial')
             AND (expires_at IS NULL OR expires_at > NOW())",
            $bundle_code
        ));
    }
    
    /**
     * Clear active bundle
     */
    public function clear_bundle() {
        unset($_SESSION[self::SESSION_KEY_BUNDLE]);
        setcookie(self::COOKIE_BUNDLE, '', time() - 3600, '/');
        unset($_COOKIE[self::COOKIE_BUNDLE]);
        
        if (false && ptp_session()) {
            ptp_session()->set('ptp_active_bundle', null);
            ptp_session()->set('ptp_bundle_discount', false);
        }
    }
    
    /**
     * Create or update bundle with training details
     */
    public function create_bundle_with_training($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_bundles';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            // Try to create the table
            if (class_exists('PTP_Database')) {
                PTP_Database::create_tables();
            }
            // Check again
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                return new WP_Error('table_missing', 'Bundles table does not exist. Please deactivate and reactivate the plugin.');
            }
        }
        
        // Get existing columns to avoid inserting into non-existent columns
        $columns_result = $wpdb->get_results("SHOW COLUMNS FROM $table");
        $existing_columns = array();
        foreach ($columns_result as $col) {
            $existing_columns[] = $col->Field;
        }
        
        $bundle_code = self::generate_bundle_code();
        $user_id = get_current_user_id();
        $session_id = $this->get_session_id();
        
        // Get trainer rate
        $trainer = PTP_Trainer::get($data['trainer_id']);
        if (!$trainer) {
            return new WP_Error('invalid_trainer', 'Trainer not found');
        }
        
        $rate = floatval($trainer->hourly_rate ?: 80);
        $sessions = intval($data['sessions'] ?? 1);
        
        // Use provided amount if available (from checkout form), otherwise calculate
        if (!empty($data['amount']) && floatval($data['amount']) > 0) {
            $training_amount = floatval($data['amount']);
        } else {
            // Calculate training amount with package discount
            $training_amount = $rate * $sessions;
            if ($sessions >= 10) {
                $training_amount = $training_amount * 0.85; // 15% off
            } elseif ($sessions >= 5) {
                $training_amount = $training_amount * 0.90; // 10% off
            }
        }
        
        // Build insert data - only include columns that exist in the table
        $insert_data = array(
            'bundle_code' => $bundle_code,
            'user_id' => $user_id ?: null,
            'trainer_id' => $data['trainer_id'],
            'training_date' => $data['date'] ?? null,
            'training_time' => $data['time'] ?? null,
            'training_location' => $data['location'] ?? '',
            'training_package' => $data['package'] ?? 'single',
            'training_sessions' => $sessions,
            'training_amount' => $training_amount,
            'status' => 'active',
            'discount_percent' => self::BUNDLE_DISCOUNT_PERCENT,
            'discount_amount' => 0,
            'total_amount' => $training_amount,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::BUNDLE_EXPIRY_HOURS . ' hours')),
            'created_at' => current_time('mysql'),
        );
        
        // Add session_id only if column exists
        if (in_array('session_id', $existing_columns)) {
            $insert_data['session_id'] = $session_id;
        }
        
        // Filter to only include existing columns
        $insert_data = array_intersect_key($insert_data, array_flip($existing_columns));
        
        // Insert bundle
        $result = $wpdb->insert($table, $insert_data);
        
        if (!$result) {
            ptp_log('PTP Bundle Creation Failed: ' . $wpdb->last_error);
            return new WP_Error('db_error', 'Could not create bundle: ' . $wpdb->last_error);
        }
        
        $this->set_active_bundle($bundle_code);
        
        return array(
            'bundle_code' => $bundle_code,
            'bundle_id' => $wpdb->insert_id,
            'training_amount' => $training_amount,
            'discount_available' => self::BUNDLE_DISCOUNT_PERCENT,
        );
    }
    
    /**
     * Add camp to existing bundle
     */
    public function add_camp_to_bundle($bundle_code, $camp_product_id, $quantity = 1) {
        global $wpdb;
        
        $bundle = $this->get_bundle_by_code($bundle_code);
        if (!$bundle) {
            return new WP_Error('invalid_bundle', 'Bundle not found or expired');
        }
        
        // Get camp product
        $camp = ptp_get_camp_product($camp_product_id);
        if (!$camp) {
            return new WP_Error('invalid_product', 'Camp not found');
        }
        
        $camp_amount = floatval($camp->get_price()) * $quantity;
        
        // Calculate new totals
        $subtotal = floatval($bundle->training_amount) + $camp_amount;
        $discount_amount = round($subtotal * (self::BUNDLE_DISCOUNT_PERCENT / 100), 2);
        $total_amount = $subtotal - $discount_amount;
        
        // Update bundle
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_bundles',
            array(
                'camp_product_id' => $camp_product_id,
                'camp_quantity' => $quantity,
                'camp_amount' => $camp_amount,
                'camp_status' => 'pending',
                'subtotal' => $subtotal,
                'discount_amount' => $discount_amount,
                'total_amount' => $total_amount,
            ),
            array('bundle_code' => $bundle_code)
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Could not update bundle');
        }
        
        return array(
            'bundle_code' => $bundle_code,
            'camp_name' => $camp->get_name(),
            'camp_amount' => $camp_amount,
            'subtotal' => $subtotal,
            'discount_amount' => $discount_amount,
            'total_amount' => $total_amount,
            'savings' => $discount_amount,
        );
    }
    
    /**
     * Calculate bundle discount for a training checkout
     */
    public function calculate_bundle_discount($training_amount) {
        $bundle = $this->get_active_bundle();
        
        // Check if there's an active bundle with a camp
        if ($bundle && $bundle->camp_product_id) {
            return round($training_amount * (self::BUNDLE_DISCOUNT_PERCENT / 100), 2);
        }
        
        // Also check PTP Native cart for camps
        if (false && ptp_cart()) {
            foreach (ptp_cart()->get_items() as $item) {
                $product = $item['data'];
                if ($this->is_camp_product($product)) {
                    return round($training_amount * (self::BUNDLE_DISCOUNT_PERCENT / 100), 2);
                }
            }
        }
        
        return 0;
    }
    
    /**
     * Check if product is a camp/clinic
     */
    public function is_camp_product($product) {
        $title = strtolower($product->get_name());
        $cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'slugs'));
        
        return strpos($title, 'camp') !== false 
            || strpos($title, 'clinic') !== false
            || array_intersect($cats, array('camps', 'clinics', 'camp', 'clinic', 'winter-clinics', 'summer-camps'));
    }
    
    /**
     * Apply bundle discount filter for training checkout
     */
    public function apply_bundle_discount_to_training($total, $trainer_id) {
        $discount = $this->calculate_bundle_discount($total);
        return $total - $discount;
    }
    
    /**
     * Apply bundle discount to PTP Native cart
     */
    public function apply_bundle_discount_to_woo_cart($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        $bundle = $this->get_active_bundle();
        if (!$bundle || !$bundle->training_status === 'completed') {
            return;
        }
        
        // Only apply if training is completed
        if ($bundle->discount_amount > 0) {
            $cart->add_fee(
                sprintf('Bundle Discount (%d%% off)', self::BUNDLE_DISCOUNT_PERCENT),
                -$bundle->discount_amount,
                false
            );
        }
    }
    
    // =====================================================
    // AJAX HANDLERS
    // =====================================================
    
    /**
     * AJAX: Create bundle
     * Updated to use standardized nonces from cart helper
     */
    public function ajax_create_bundle() {
        // Use cart helper for nonce verification if available
        if (class_exists('PTP_Cart_Helper')) {
            if (!PTP_Cart_Helper::verify_bundle_nonce()) {
                PTP_Cart_Helper::send_nonce_error();
                return;
            }
        } elseif (!check_ajax_referer('ptp_bundle', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $camp_id = intval($_POST['camp_id'] ?? 0);
        $date = sanitize_text_field($_POST['date'] ?? '');
        $time = sanitize_text_field($_POST['time'] ?? '');
        $package = sanitize_text_field($_POST['package'] ?? 'single');
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Trainer ID required'));
            return;
        }
        
        // Create bundle with training
        $sessions = 1;
        if ($package === '5pack') $sessions = 5;
        if ($package === '10pack') $sessions = 10;
        
        $result = $this->create_bundle_with_training(array(
            'trainer_id' => $trainer_id,
            'date' => $date,
            'time' => $time,
            'package' => $package,
            'sessions' => $sessions,
        ));
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        // Invalidate cart cache
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::invalidate_cart_cache();
        }
        
        // If camp provided, add it
        if ($camp_id) {
            $camp_result = $this->add_camp_to_bundle($result['bundle_code'], $camp_id);
            if (!is_wp_error($camp_result)) {
                $result = array_merge($result, $camp_result);
            }
        }
        
        // Build redirect URL
        $trainer = PTP_Trainer::get($trainer_id);
        $redirect = home_url('/ptp-checkout/');
        if ($trainer) {
            $redirect = add_query_arg(array(
                'trainer_id' => $trainer_id,
                'date' => $date,
                'time' => $time,
                'package' => $package,
                'bundle' => $result['bundle_code'],
            ), home_url('/ptp-checkout/'));
        }
        
        $result['redirect'] = $redirect;
        $result['message'] = 'Bundle created! ' . self::BUNDLE_DISCOUNT_PERCENT . '% discount applied.';
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Add camp to bundle
     * Updated to use standardized nonces from cart helper
     */
    public function ajax_add_camp_to_bundle() {
        if (class_exists('PTP_Cart_Helper')) {
            if (!PTP_Cart_Helper::verify_bundle_nonce()) {
                PTP_Cart_Helper::send_nonce_error();
                return;
            }
        } elseif (!check_ajax_referer('ptp_bundle', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $bundle_code = $this->get_active_bundle_code();
        if (!$bundle_code) {
            // No active bundle - create one
            $bundle_code = self::generate_bundle_code();
            $this->set_active_bundle($bundle_code);
        }
        
        $camp_id = intval($_POST['camp_id'] ?? 0);
        if (!$camp_id) {
            wp_send_json_error(array('message' => 'Camp ID required'));
            return;
        }
        
        $result = $this->add_camp_to_bundle($bundle_code, $camp_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        // Invalidate cart cache
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::invalidate_cart_cache();
        }
        
        $result['message'] = 'Camp added to bundle! You\'ll save $' . number_format($result['savings'], 2);
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Process bundle checkout (combined training + camp)
     * Updated to use standardized nonces and transaction handling
     */
    public function ajax_process_bundle_checkout() {
        if (class_exists('PTP_Cart_Helper')) {
            if (!PTP_Cart_Helper::verify_checkout_nonce()) {
                PTP_Cart_Helper::send_nonce_error();
                return;
            }
            // Rate limiting
            $rate_check = PTP_Cart_Helper::check_checkout_rate_limit();
            if (is_wp_error($rate_check)) {
                PTP_Cart_Helper::send_rate_limit_error($rate_check);
                return;
            }
        } elseif (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_checkout_action')) {
            // Also try legacy bundle nonce
            if (!check_ajax_referer('ptp_bundle_checkout', 'nonce', false)) {
                wp_send_json_error(array('message' => 'Security check failed'));
                return;
            }
        }
        
        global $wpdb;
        
        $bundle_code = sanitize_text_field($_POST['bundle_code'] ?? '');
        $bundle = $this->get_bundle_by_code($bundle_code);
        
        if (!$bundle) {
            wp_send_json_error(array('message' => 'Bundle not found or expired'));
            return;
        }
        
        // Get form data
        $email = sanitize_email($_POST['email'] ?? '');
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $player_first = sanitize_text_field($_POST['player_first_name'] ?? '');
        $player_age = intval($_POST['player_age'] ?? 0);
        
        if (!$email || !$first_name) {
            wp_send_json_error(array('message' => 'Please provide your name and email'));
            return;
        }
        
        // Create or get user
        $user_id = get_current_user_id();
        $parent_id = 0;
        
        if (!$user_id) {
            $existing_user = get_user_by('email', $email);
            if ($existing_user) {
                $user_id = $existing_user->ID;
            }
        }
        
        if ($user_id) {
            $parent = PTP_Parent::get_by_user_id($user_id);
            if ($parent) {
                $parent_id = $parent->id;
            }
        }
        
        // Calculate final amount
        $total = floatval($bundle->total_amount);
        
        // Create Stripe Payment Intent
        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            wp_send_json_error(array('message' => 'Payment processing not configured'));
            return;
        }
        
        try {
            $metadata = array(
                'bundle_code' => $bundle_code,
                'bundle_id' => $bundle->id,
                'trainer_id' => $bundle->trainer_id,
                'camp_product_id' => $bundle->camp_product_id,
                'email' => $email,
                // v216.1: Add customer details for Stripe Customer attachment
                'customer_email' => $email,
                'customer_name' => trim($first_name . ' ' . $last_name),
                'customer_phone' => $phone,
            );
            
            $payment_intent = PTP_Stripe::create_payment_intent($total, $metadata);
            
            if (is_wp_error($payment_intent)) {
                wp_send_json_error(array('message' => 'Payment setup failed: ' . $payment_intent->get_error_message()));
                return;
            }
            
            // Update bundle with payment intent
            $wpdb->update(
                $wpdb->prefix . 'ptp_bundles',
                array(
                    'payment_intent_id' => $payment_intent['id'],
                    'user_id' => $user_id ?: null,
                ),
                array('id' => $bundle->id)
            );
            
            wp_send_json_success(array(
                'bundle_id' => $bundle->id,
                'bundle_code' => $bundle_code,
                'client_secret' => $payment_intent['client_secret'],
                'total' => $total,
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Payment error: ' . $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Confirm bundle payment after Stripe
     * Updated with payment verification and transaction handling
     */
    public function ajax_confirm_bundle_payment() {
        if (class_exists('PTP_Cart_Helper')) {
            if (!PTP_Cart_Helper::verify_checkout_nonce()) {
                PTP_Cart_Helper::send_nonce_error();
                return;
            }
        } elseif (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_checkout_action')) {
            // Also try legacy bundle nonce
            if (!check_ajax_referer('ptp_bundle_checkout', 'nonce', false)) {
                wp_send_json_error(array('message' => 'Security check failed'));
                return;
            }
        }
        
        global $wpdb;
        
        $bundle_code = sanitize_text_field($_POST['bundle_code'] ?? '');
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
        
        if (!$bundle_code || !$payment_intent_id) {
            wp_send_json_error(array('message' => 'Missing payment information'));
            return;
        }
        
        $bundle = $this->get_bundle_by_code($bundle_code);
        if (!$bundle) {
            wp_send_json_error(array('message' => 'Bundle not found'));
            return;
        }
        
        // Verify payment intent matches
        if ($bundle->payment_intent_id !== $payment_intent_id) {
            wp_send_json_error(array('message' => 'Payment verification failed - intent mismatch'));
            return;
        }
        
        // CRITICAL: Verify payment with Stripe before proceeding
        if (class_exists('PTP_Cart_Helper')) {
            $verification = PTP_Cart_Helper::verify_stripe_payment($payment_intent_id);
            
            if (is_wp_error($verification)) {
                ptp_log('PTP Bundle Checkout: Payment verification failed - ' . $verification->get_error_message());
                wp_send_json_error(array(
                    'message' => $verification->get_error_message(),
                    'code' => $verification->get_error_code()
                ));
                return;
            }
            
            if ($verification !== true) {
                wp_send_json_error(array('message' => 'Payment could not be verified'));
                return;
            }
        } else {
            // Fallback: Direct Stripe check
            if (class_exists('PTP_Stripe')) {
                $intent = PTP_Stripe::get_payment_intent($payment_intent_id);
                if (is_wp_error($intent) || ($intent['status'] ?? '') !== 'succeeded') {
                    wp_send_json_error(array('message' => 'Payment verification failed'));
                    return;
                }
            }
        }
        
        // Start transaction for atomic operations
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::start_transaction();
        }
        
        try {
            // Create training booking if trainer exists
            $booking_id = null;
            if ($bundle->trainer_id) {
                $trainer = PTP_Trainer::get($bundle->trainer_id);
                
                if ($trainer) {
                    $parent_id = $bundle->user_id ? (PTP_Parent::get_by_user_id($bundle->user_id)->id ?? 0) : 0;
                    $bundle_amount = floatval($bundle->training_amount);
                    $bundle_sessions = intval($bundle->training_sessions) ?: 1;
                    // v194: Graduated fee across all sessions in package
                    $bundle_fee = PTP_Unified_Checkout::calculate_graduated_fee($bundle->trainer_id, $parent_id, $bundle_amount, $bundle_sessions);
                    
                    // v236: Priority 1 — selected location from checkout, Priority 2 — trainer profile
                    $t_loc = '';
                    if (!empty($bundle->training_location)) {
                        $t_loc = $bundle->training_location;
                    } elseif (!empty($trainer->location)) {
                        $t_loc = $trainer->location;
                    } elseif (!empty($trainer->city)) {
                        $t_loc = $trainer->city . (!empty($trainer->state) ? ', ' . $trainer->state : '');
                    }
                    // v236: Resolve address from trainer's training_locations
                    $t_loc_notes = '';
                    if (!empty($t_loc) && !empty($trainer->training_locations)) {
                        $tl = json_decode($trainer->training_locations, true);
                        if (!is_array($tl)) $tl = json_decode(wp_unslash($trainer->training_locations), true);
                        if (is_array($tl)) {
                            foreach ($tl as $_tl) {
                                if (is_array($_tl) && !empty($_tl['name']) && $_tl['name'] === $t_loc && !empty($_tl['address'])) {
                                    $t_loc_notes = $_tl['address'];
                                    break;
                                }
                            }
                        }
                    }
                    
                    $booking_data = array(
                        'trainer_id' => $bundle->trainer_id,
                        'parent_id' => $parent_id,
                        'player_id' => 0,
                        'session_date' => $bundle->training_date,
                        'start_time' => $bundle->training_time,
                        'end_time' => date('H:i:s', strtotime($bundle->training_time . ' +1 hour')),
                        'duration_minutes' => 60,
                        'location' => $t_loc,
                        'location_notes' => $t_loc_notes,
                        'hourly_rate' => floatval($trainer->hourly_rate ?: 80),
                        'total_amount' => $bundle_amount,
                        'trainer_payout' => $bundle_fee['trainer_payout'],
                        'platform_fee' => $bundle_fee['platform_fee'],
                        'notes' => 'Bundle booking - Code: ' . $bundle_code,
                        'status' => 'confirmed',
                        'payment_status' => 'paid',
                        'booking_number' => 'PTP-' . strtoupper(wp_generate_password(8, false, false)),
                        'session_type' => $bundle->training_package,
                        'session_count' => $bundle->training_sessions,
                        'sessions_remaining' => $bundle->training_sessions,
                        'payment_intent_id' => $payment_intent_id,
                        'created_at' => current_time('mysql'),
                        'paid_at' => current_time('mysql'),
                    );
                    
                    $result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $booking_data);
                    if (!$result) {
                        throw new Exception('Could not create booking: ' . $wpdb->last_error);
                    }
                    $booking_id = $wpdb->insert_id;
                }
            }
            
            // Create PTP Native order for camp if exists
            $order_id = null;
            if ($bundle->camp_product_id && false) {
                $order = PTP_Native_Order_Manager::create_order(array(
                    'customer_id' => $bundle->user_id ?: 0,
                    'status' => 'completed',
                ));
                
                if (is_wp_error($order)) {
                    throw new Exception('Could not create order: ' . $order->get_error_message());
                }
                
                $camp = ptp_get_camp_product($bundle->camp_product_id);
                if ($camp) {
                    $order->add_product($camp, $bundle->camp_quantity);
                    
                    // Apply bundle discount as fee
                    if ($bundle->discount_amount > 0) {
                        $fee = ptp_create_order_fee();
                        $fee->set_name('Bundle Discount');
                        $fee->set_amount(-$bundle->discount_amount);
                        $fee->set_total(-$bundle->discount_amount);
                        $order->add_item($fee);
                    }
                    
                    $order->calculate_totals();
                    $order->set_payment_method('stripe');
                    $order->set_payment_method_title('Credit Card (Bundle)');
                    $order->add_order_note('Bundle order - Code: ' . $bundle_code);
                    $order->save();
                    
                    $order_id = $order->get_id();
                }
            }
            
            // Update bundle as completed
            $result = $wpdb->update(
                $wpdb->prefix . 'ptp_bundles',
                array(
                    'training_booking_id' => $booking_id,
                    'training_status' => $booking_id ? 'completed' : $bundle->training_status,
                    'camp_order_id' => $order_id,
                    'camp_status' => $order_id ? 'completed' : $bundle->camp_status,
                    'payment_status' => 'paid',
                    'status' => 'completed',
                    'completed_at' => current_time('mysql'),
                ),
                array('id' => $bundle->id)
            );
            
            if ($result === false) {
                throw new Exception('Could not update bundle status');
            }
            
            // Commit transaction
            if (class_exists('PTP_Cart_Helper')) {
                PTP_Cart_Helper::commit_transaction();
                PTP_Cart_Helper::invalidate_cart_cache();
            }
            
            // Clear bundle from session
            $this->clear_bundle();
            
            // Send confirmation emails
            // v235.9: Fixed typo (was send_booking_confirmed which doesn't exist)
            // + fire hook chain so SMS, Google Calendar, Supabase, etc. all trigger
            if ($booking_id) {
                do_action('ptp_booking_confirmed', $booking_id);
                
                // Fallback: direct email if hook chain didn't send
                if (!get_transient('ptp_training_email_sent_' . $booking_id)) {
                    if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_booking_confirmation')) {
                        PTP_Email::send_booking_confirmation($booking_id);
                    }
                }
            }
            
            wp_send_json_success(array(
                'message' => 'Bundle checkout completed!',
                'bundle_code' => $bundle_code,
                'booking_id' => $booking_id,
                'order_id' => $order_id,
                'redirect' => home_url('/booking-confirmation/?bundle=' . $bundle_code),
            ));
            
        } catch (Exception $e) {
            // Rollback transaction
            if (class_exists('PTP_Cart_Helper')) {
                PTP_Cart_Helper::rollback_transaction();
            }
            
            ptp_log('PTP Bundle Checkout Error: ' . $e->getMessage());
            ptp_log('CRITICAL: Payment ' . $payment_intent_id . ' succeeded but order creation failed');
            
            wp_send_json_error(array(
                'message' => 'Order creation failed. Your payment was received. Please contact support.',
                'payment_intent_id' => $payment_intent_id,
            ));
        }
    }
    
    /**
     * AJAX: Get bundle status
     */
    public function ajax_get_bundle_status() {
        $bundle_code = $this->get_active_bundle_code();
        
        if (!$bundle_code) {
            wp_send_json_success(array(
                'has_bundle' => false,
                'discount_available' => self::BUNDLE_DISCOUNT_PERCENT,
            ));
            return;
        }
        
        $bundle = $this->get_bundle_by_code($bundle_code);
        
        if (!$bundle) {
            $this->clear_bundle();
            wp_send_json_success(array(
                'has_bundle' => false,
                'discount_available' => self::BUNDLE_DISCOUNT_PERCENT,
            ));
            return;
        }
        
        wp_send_json_success(array(
            'has_bundle' => true,
            'bundle_code' => $bundle_code,
            'has_training' => !empty($bundle->trainer_id),
            'has_camp' => !empty($bundle->camp_product_id),
            'training_amount' => floatval($bundle->training_amount),
            'camp_amount' => floatval($bundle->camp_amount),
            'subtotal' => floatval($bundle->subtotal),
            'discount_percent' => floatval($bundle->discount_percent),
            'discount_amount' => floatval($bundle->discount_amount),
            'total' => floatval($bundle->total_amount),
            'expires_at' => $bundle->expires_at,
        ));
    }
    
    /**
     * AJAX: Clear bundle
     */
    public function ajax_clear_bundle() {
        $this->clear_bundle();
        wp_send_json_success(array('message' => 'Bundle cleared'));
    }
    
    // =====================================================
    // UI RENDERING
    // =====================================================
    
    /**
     * Render bundle checkout shortcode
     */
    public function render_bundle_checkout_shortcode($atts) {
        $bundle_code = $this->get_active_bundle_code();
        $bundle = $bundle_code ? $this->get_bundle_by_code($bundle_code) : null;
        
        if (!$bundle || (!$bundle->trainer_id && !$bundle->camp_product_id)) {
            return '<div class="ptp-bundle-empty"><p>No active bundle. <a href="' . esc_url(home_url('/find-trainers/')) . '">Start by finding a trainer</a>.</p></div>';
        }
        
        ob_start();
        $this->render_bundle_checkout_page($bundle);
        return ob_get_clean();
    }
    
    /**
     * Render full bundle checkout page
     */
    private function render_bundle_checkout_page($bundle) {
        // Get trainer details
        $trainer = null;
        $camp = null;
        
        if ($bundle->trainer_id) {
            $trainer = PTP_Trainer::get($bundle->trainer_id);
        }
        if ($bundle->camp_product_id && false) {
            $camp = ptp_get_camp_product($bundle->camp_product_id);
        }
        
        $stripe_key = class_exists('PTP_Stripe') ? PTP_Stripe::get_publishable_key() : '';
        ?>
        <div class="ptp-bundle-checkout">
            <style>
            .ptp-bundle-checkout { font-family: 'Inter', -apple-system, sans-serif; }
            .ptp-bc-header { background: linear-gradient(135deg, #0A0A0A 0%, #1F2937 100%); padding: 32px 24px; color: #fff; border-radius: 16px 16px 0 0; }
            .ptp-bc-header h2 { font-family: 'Oswald', sans-serif; font-size: 24px; margin: 0 0 8px; text-transform: uppercase; }
            .ptp-bc-savings { background: #FCB900; color: #0A0A0A; display: inline-block; padding: 6px 16px; border-radius: 20px; font-weight: 700; font-size: 14px; }
            .ptp-bc-items { background: #fff; border: 2px solid #E5E7EB; border-top: none; padding: 24px; }
            .ptp-bc-item { display: flex; gap: 16px; padding: 16px 0; border-bottom: 1px solid #E5E7EB; }
            .ptp-bc-item:last-child { border-bottom: none; }
            .ptp-bc-item-img { width: 80px; height: 80px; border-radius: 12px; overflow: hidden; flex-shrink: 0; }
            .ptp-bc-item-img img { width: 100%; height: 100%; object-fit: cover; }
            .ptp-bc-item-info { flex: 1; }
            .ptp-bc-item-title { font-family: 'Oswald', sans-serif; font-size: 16px; font-weight: 600; margin: 0 0 4px; }
            .ptp-bc-item-meta { font-size: 13px; color: #6B7280; }
            .ptp-bc-item-price { font-weight: 700; font-size: 18px; }
            .ptp-bc-totals { background: #F9FAFB; padding: 20px 24px; border: 2px solid #E5E7EB; border-top: none; }
            .ptp-bc-total-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 15px; }
            .ptp-bc-total-row.discount { color: #059669; font-weight: 600; }
            .ptp-bc-total-row.final { font-size: 20px; font-weight: 700; border-top: 2px solid #0A0A0A; padding-top: 16px; margin-top: 8px; }
            .ptp-bc-form { background: #fff; border: 2px solid #E5E7EB; border-top: none; padding: 24px; border-radius: 0 0 16px 16px; }
            .ptp-bc-field { margin-bottom: 16px; }
            .ptp-bc-field label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #374151; margin-bottom: 6px; }
            .ptp-bc-field input { width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 8px; font-size: 15px; transition: border-color 0.2s; }
            .ptp-bc-field input:focus { outline: none; border-color: #FCB900; }
            .ptp-bc-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
            .ptp-bc-card-element { padding: 16px; border: 2px solid #E5E7EB; border-radius: 8px; background: #fff; }
            .ptp-bc-submit { width: 100%; background: #FCB900; color: #0A0A0A; border: none; padding: 18px 32px; border-radius: 10px; font-family: 'Oswald', sans-serif; font-size: 18px; font-weight: 700; text-transform: uppercase; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 24px; }
            .ptp-bc-submit:hover { background: #0A0A0A; color: #FCB900; }
            .ptp-bc-submit:disabled { opacity: 0.6; cursor: not-allowed; }
            .ptp-bc-error { background: #FEE2E2; color: #DC2626; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: none; }
            @media (max-width: 640px) {
                .ptp-bc-row { grid-template-columns: 1fr; }
                .ptp-bc-item { flex-direction: column; text-align: center; }
                .ptp-bc-item-img { margin: 0 auto; }
            }
            </style>
            
            <!-- Header -->
            <div class="ptp-bc-header">
                <h2>Complete Your Bundle</h2>
                <p style="opacity:0.8;margin:0 0 12px;">Training + Camp = Maximum Results</p>
                <span class="ptp-bc-savings">SAVE <?php echo intval($bundle->discount_percent); ?>% ($<?php echo number_format($bundle->discount_amount, 2); ?>)</span>
            </div>
            
            <!-- Bundle Items -->
            <div class="ptp-bc-items">
                <?php if ($trainer): ?>
                <div class="ptp-bc-item">
                    <div class="ptp-bc-item-img">
                        <img src="<?php echo esc_url($trainer->photo_url ?: PTP_Images::avatar($trainer->display_name, 80)); ?>" alt="">
                    </div>
                    <div class="ptp-bc-item-info">
                        <h3 class="ptp-bc-item-title">Training with <?php echo esc_html($trainer->display_name); ?></h3>
                        <div class="ptp-bc-item-meta">
                            <?php if ($bundle->training_date): ?>
                            📅 <?php echo date('M j, Y', strtotime($bundle->training_date)); ?>
                            <?php if ($bundle->training_time): ?>
                            at <?php echo date('g:i A', strtotime($bundle->training_time)); ?>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($bundle->training_sessions > 1): ?>
                            <br>📦 <?php echo $bundle->training_sessions; ?>-session package
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ptp-bc-item-price">$<?php echo number_format($bundle->training_amount, 0); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($camp): ?>
                <div class="ptp-bc-item">
                    <div class="ptp-bc-item-img">
                        <?php echo $camp->get_image('thumbnail'); ?>
                    </div>
                    <div class="ptp-bc-item-info">
                        <h3 class="ptp-bc-item-title"><?php echo esc_html($camp->get_name()); ?></h3>
                        <div class="ptp-bc-item-meta">
                            ⚽ Camp Registration
                        </div>
                    </div>
                    <div class="ptp-bc-item-price">$<?php echo number_format($bundle->camp_amount, 0); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Totals -->
            <div class="ptp-bc-totals">
                <div class="ptp-bc-total-row">
                    <span>Subtotal</span>
                    <span>$<?php echo number_format($bundle->subtotal, 2); ?></span>
                </div>
                <div class="ptp-bc-total-row discount">
                    <span>Bundle Discount (<?php echo intval($bundle->discount_percent); ?>%)</span>
                    <span>-$<?php echo number_format($bundle->discount_amount, 2); ?></span>
                </div>
                <div class="ptp-bc-total-row final">
                    <span>Total</span>
                    <span>$<?php echo number_format($bundle->total_amount, 2); ?></span>
                </div>
            </div>
            
            <!-- Checkout Form -->
            <form id="bundle-checkout-form" class="ptp-bc-form">
                <input type="hidden" name="bundle_code" value="<?php echo esc_attr($bundle->bundle_code); ?>">
                
                <div class="ptp-bc-error" id="bundle-error"></div>
                
                <div class="ptp-bc-row">
                    <div class="ptp-bc-field">
                        <label>First Name</label>
                        <input type="text" name="first_name" required>
                    </div>
                    <div class="ptp-bc-field">
                        <label>Last Name</label>
                        <input type="text" name="last_name" required>
                    </div>
                </div>
                
                <div class="ptp-bc-row">
                    <div class="ptp-bc-field">
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="ptp-bc-field">
                        <label>Phone</label>
                        <input type="tel" name="phone" required>
                    </div>
                </div>
                
                <div class="ptp-bc-row">
                    <div class="ptp-bc-field">
                        <label>Player First Name</label>
                        <input type="text" name="player_first_name" required>
                    </div>
                    <div class="ptp-bc-field">
                        <label>Player Age</label>
                        <input type="number" name="player_age" min="5" max="18" required>
                    </div>
                </div>
                
                <div class="ptp-bc-field">
                    <label>Card Details</label>
                    <div class="ptp-bc-card-element" id="bundle-card-element"></div>
                    <div id="bundle-card-errors" style="color:#DC2626;font-size:13px;margin-top:8px"></div>
                </div>
                
                <button type="submit" class="ptp-bc-submit" id="bundle-submit-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Pay Securely - $<?php echo number_format($bundle->total_amount, 0); ?>
                </button>
            </form>
            
            <script src="https://js.stripe.com/v3/"></script>
            <script>
            (function() {
                const stripe = Stripe('<?php echo esc_js($stripe_key); ?>');
                const elements = stripe.elements();
                const cardElement = elements.create('card', {
                    style: {
                        base: { fontSize: '16px', color: '#111827', fontFamily: 'Inter, sans-serif' },
                        invalid: { color: '#DC2626' }
                    }
                });
                cardElement.mount('#bundle-card-element');
                
                cardElement.on('change', function(e) {
                    document.getElementById('bundle-card-errors').textContent = e.error ? e.error.message : '';
                });
                
                document.getElementById('bundle-checkout-form').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const btn = document.getElementById('bundle-submit-btn');
                    const errorEl = document.getElementById('bundle-error');
                    
                    btn.disabled = true;
                    btn.textContent = 'Processing...';
                    errorEl.style.display = 'none';
                    
                    try {
                        const formData = new FormData(this);
                        formData.append('action', 'ptp_process_bundle_checkout');
                        formData.append('nonce', '<?php echo wp_create_nonce('ptp_bundle_checkout'); ?>');
                        
                        const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();
                        
                        if (!data.success) {
                            throw new Error(data.data?.message || 'Checkout failed');
                        }
                        
                        const { error, paymentIntent } = await stripe.confirmCardPayment(data.data.client_secret, {
                            payment_method: { card: cardElement },
                            return_url: '<?php echo home_url('/booking-confirmation/'); ?>'
                        });
                        
                        if (error) {
                            throw new Error(error.message);
                        }
                        
                        // Success - redirect to confirmation
                        window.location.href = '<?php echo home_url('/booking-confirmation/'); ?>?bundle=' + data.data.bundle_code;
                        
                    } catch (err) {
                        errorEl.textContent = err.message;
                        errorEl.style.display = 'block';
                        btn.disabled = false;
                        btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Pay Securely - $<?php echo number_format($bundle->total_amount, 0); ?>';
                    }
                });
            })();
            </script>
        </div>
        <?php
    }
    
    /**
     * Render bundle awareness modal (shows when user has partial bundle)
     */
    public function render_bundle_modal() {
        $url = $_SERVER['REQUEST_URI'] ?? '';
        
        // Only on relevant pages
        if (strpos($url, '/trainer/') === false && 
            strpos($url, '/checkout') === false &&
            strpos($url, '/cart') === false &&
            strpos($url, '/training') === false) {
            return;
        }
        
        $bundle = $this->get_active_bundle();
        $has_pending_training = $bundle && $bundle->trainer_id && !$bundle->camp_product_id;
        $has_pending_camp = $bundle && $bundle->camp_product_id && !$bundle->trainer_id;
        
        if (!$has_pending_training && !$has_pending_camp) {
            return;
        }
        
        ?>
        <div id="ptp-bundle-modal" class="ptp-bundle-modal" style="display:none">
            <style>
            .ptp-bundle-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 99999; display: flex !important; align-items: center; justify-content: center; padding: 20px; }
            .ptp-bundle-modal[style*="none"] { display: none !important; }
            .ptp-bm-content { background: #fff; border-radius: 20px; max-width: 420px; width: 100%; overflow: hidden; }
            .ptp-bm-header { background: linear-gradient(135deg, #059669 0%, #047857 100%); padding: 24px; text-align: center; color: #fff; }
            .ptp-bm-header h3 { font-family: 'Oswald', sans-serif; font-size: 22px; margin: 0; text-transform: uppercase; }
            .ptp-bm-body { padding: 24px; text-align: center; }
            .ptp-bm-discount { font-size: 48px; font-family: 'Oswald', sans-serif; font-weight: 700; color: #059669; }
            .ptp-bm-cta { background: #FCB900; color: #0A0A0A; border: none; padding: 16px 32px; border-radius: 10px; font-family: 'Oswald', sans-serif; font-size: 16px; font-weight: 700; text-transform: uppercase; cursor: pointer; width: 100%; margin-top: 16px; }
            .ptp-bm-skip { background: none; border: none; color: #6B7280; font-size: 14px; cursor: pointer; margin-top: 12px; }
            </style>
            
            <div class="ptp-bm-content">
                <div class="ptp-bm-header">
                    <h3>🎁 Complete Your Bundle!</h3>
                </div>
                <div class="ptp-bm-body">
                    <div class="ptp-bm-discount"><?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>% OFF</div>
                    <p style="color:#4B5563;margin:12px 0 0">
                        <?php if ($has_pending_training): ?>
                        Add a camp to your training and save <?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>%!
                        <?php else: ?>
                        Add training to your camp and save <?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>%!
                        <?php endif; ?>
                    </p>
                    
                    <?php if ($has_pending_training): ?>
                    <a href="<?php echo esc_url(home_url('/summer-camps/')); ?>" class="ptp-bm-cta" style="display:block;text-decoration:none;text-align:center">
                        Browse Camps
                    </a>
                    <?php else: ?>
                    <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="ptp-bm-cta" style="display:block;text-decoration:none;text-align:center">
                        Find a Trainer
                    </a>
                    <?php endif; ?>
                    
                    <button class="ptp-bm-skip" onclick="closeBundleModal()">Skip for now</button>
                </div>
            </div>
        </div>
        
        <script>
        (function() {
            var modal = document.getElementById('ptp-bundle-modal');
            if (!modal) return;
            
            // Show after 5 seconds if not dismissed
            if (!sessionStorage.getItem('ptp_bundle_modal_shown')) {
                setTimeout(function() {
                    modal.style.display = 'flex';
                    sessionStorage.setItem('ptp_bundle_modal_shown', '1');
                }, 5000);
            }
            
            window.closeBundleModal = function() {
                modal.style.display = 'none';
            };
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeBundleModal();
            });
        })();
        </script>
        <?php
    }
    
    /**
     * Render persistent bundle banner
     */
    public function render_bundle_banner() {
        $bundle = $this->get_active_bundle();
        if (!$bundle) return;
        
        // Only show if bundle is incomplete
        $is_complete = ($bundle->training_status === 'completed' && $bundle->camp_status === 'completed');
        if ($is_complete) return;
        
        ?>
        <div id="ptp-bundle-banner" style="position:fixed;bottom:0;left:0;right:0;background:#0A0A0A;color:#fff;padding:12px 20px;z-index:9990;display:none">
            <div style="max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
                <div style="display:flex;align-items:center;gap:12px">
                    <span style="font-size:24px">🎁</span>
                    <span>
                        <strong style="color:#FCB900">Bundle Active!</strong>
                        Save <?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>% when you complete your bundle
                    </span>
                </div>
                <div style="display:flex;align-items:center;gap:12px">
                    <a href="<?php echo esc_url(home_url('/bundle-checkout/?bundle=' . $bundle->bundle_code)); ?>" 
                       style="background:#FCB900;color:#0A0A0A;padding:10px 24px;border-radius:8px;font-weight:700;text-decoration:none">
                        Complete Bundle
                    </a>
                    <button onclick="document.getElementById('ptp-bundle-banner').style.display='none'" 
                            style="background:none;border:none;color:#fff;opacity:0.5;font-size:20px;cursor:pointer">&times;</button>
                </div>
            </div>
        </div>
        <script>
        setTimeout(function() {
            var banner = document.getElementById('ptp-bundle-banner');
            if (banner && !sessionStorage.getItem('ptp_bundle_banner_hidden')) {
                banner.style.display = 'block';
            }
        }, 3000);
        </script>
        <?php
    }
    
    /**
     * Show CTA to complete bundle after PTP Native order
     */
    public function show_complete_bundle_cta($order_id) {
        $bundle = $this->get_active_bundle();
        if (!$bundle || !$bundle->trainer_id) return;
        
        // Mark camp as completed in bundle
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_bundles',
            array(
                'camp_order_id' => $order_id,
                'camp_status' => 'completed',
                'status' => $bundle->training_status === 'completed' ? 'completed' : 'partial',
            ),
            array('id' => $bundle->id)
        );
        
        // If training not completed, show CTA
        if ($bundle->training_status !== 'completed') {
            $trainer = PTP_Trainer::get($bundle->trainer_id);
            ?>
            <div style="background:linear-gradient(135deg,#0A0A0A 0%,#1F2937 100%);border-radius:20px;padding:40px;margin:32px 0;text-align:center;border:3px solid #FCB900">
                <div style="font-size:60px;margin-bottom:16px">✅</div>
                <h2 style="font-family:'Oswald',sans-serif;font-size:28px;color:#fff;margin:0 0 8px;text-transform:uppercase">
                    Camp Registration Complete!
                </h2>
                <p style="color:#FCB900;font-size:20px;font-weight:700;margin:0 0 16px">
                    Now complete your training to save <?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>%
                </p>
                <p style="color:rgba(255,255,255,0.7);margin:0 0 24px">
                    Your bundle discount is waiting! Book your training session with <?php echo esc_html($trainer->display_name ?? 'your trainer'); ?>.
                </p>
                <a href="<?php echo esc_url(home_url('/bundle-checkout/?bundle=' . $bundle->bundle_code)); ?>"
                   style="display:inline-flex;align-items:center;gap:10px;background:#FCB900;color:#0A0A0A;padding:18px 40px;
                          border-radius:10px;font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;text-transform:uppercase;
                          text-decoration:none;box-shadow:0 4px 20px rgba(252,185,0,0.4)">
                    Complete Bundle Checkout
                </a>
            </div>
            <?php
        }
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('ptp/v1', '/bundle/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_bundle_status'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route('ptp/v1', '/bundle/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_create_bundle'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * REST: Get bundle status
     */
    public function rest_get_bundle_status($request) {
        $bundle_code = $this->get_active_bundle_code();
        $bundle = $bundle_code ? $this->get_bundle_by_code($bundle_code) : null;
        
        return new WP_REST_Response(array(
            'has_bundle' => !empty($bundle),
            'bundle' => $bundle ? array(
                'code' => $bundle->bundle_code,
                'has_training' => !empty($bundle->trainer_id),
                'has_camp' => !empty($bundle->camp_product_id),
                'discount_percent' => floatval($bundle->discount_percent),
                'total' => floatval($bundle->total_amount),
            ) : null,
            'discount_available' => self::BUNDLE_DISCOUNT_PERCENT,
        ));
    }
    
    /**
     * REST: Create bundle
     */
    public function rest_create_bundle($request) {
        $params = $request->get_json_params();
        
        $result = $this->create_bundle_with_training(array(
            'trainer_id' => intval($params['trainer_id'] ?? 0),
            'date' => sanitize_text_field($params['date'] ?? ''),
            'time' => sanitize_text_field($params['time'] ?? ''),
            'package' => sanitize_text_field($params['package'] ?? 'single'),
            'sessions' => intval($params['sessions'] ?? 1),
        ));
        
        if (is_wp_error($result)) {
            return new WP_REST_Response(array('error' => $result->get_error_message()), 400);
        }
        
        return new WP_REST_Response($result);
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Bundle_Checkout::instance();
}, 12);

// Helper function
function ptp_get_bundle_checkout() {
    return PTP_Bundle_Checkout::instance();
}

// Check if bundle discount applies
function ptp_has_bundle_discount() {
    $checkout = PTP_Bundle_Checkout::instance();
    $bundle = $checkout->get_active_bundle();
    return $bundle && ($bundle->camp_product_id || $bundle->trainer_id);
}

// Get bundle discount amount
function ptp_get_bundle_discount($amount) {
    $checkout = PTP_Bundle_Checkout::instance();
    return $checkout->calculate_bundle_discount($amount);
}
