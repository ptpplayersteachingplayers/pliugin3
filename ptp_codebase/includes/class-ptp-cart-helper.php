<?php
/**
 * PTP Cart Helper v1.2.0 - FIXED
 * 
 * Centralized helper class for cart and checkout operations.
 * Provides single source of truth for:
 * - Cart state management
 * - Discount calculations
 * - Nonce handling
 * - Payment verification
 * 
 * FIXES in v1.1.0:
 * - Enabled cart count filter (was disabled with `if (false)`)
 * - Added native cart integration
 * - Fixed bundle discount calculation flow
 * - Added get_unified_totals() for Stripe checkout
 * 
 * V1.2.0 (V160):
 * - Added integration with PTP_Stripe_ID_Helper
 * - Added ptp_find_payment_records() global helper
 * - Added ptp_get_order_stripe_data() global helper
 * 
 * @since 60.3.0
 * @updated 160.0.0
 */

defined('ABSPATH') || exit;

class PTP_Cart_Helper {
    
    private static $instance = null;
    
    // Standardized nonce actions
    const NONCE_CART = 'ptp_cart_action';
    const NONCE_CHECKOUT = 'ptp_checkout_action';
    const NONCE_BUNDLE = 'ptp_bundle_checkout';
    
    // Bundle discount - default values (can be overridden in admin)
    const BUNDLE_DISCOUNT_PERCENT_DEFAULT = 5;
    
    // Processing fee defaults (can be overridden in admin)
    const PROCESSING_FEE_PERCENT_DEFAULT = 3.0;
    const PROCESSING_FEE_FIXED_DEFAULT = 0.30;
    
    /**
     * Get bundle discount percentage from settings
     */
    public static function get_bundle_discount_percent() {
        return floatval(get_option('ptp_bundle_discount_percent', self::BUNDLE_DISCOUNT_PERCENT_DEFAULT));
    }
    
    /**
     * Get processing fee settings
     */
    public static function get_processing_fee_settings() {
        return array(
            'enabled' => (bool) get_option('ptp_processing_fee_enabled', 1),
            'percent' => floatval(get_option('ptp_processing_fee_percent', self::PROCESSING_FEE_PERCENT_DEFAULT)),
            'fixed' => floatval(get_option('ptp_processing_fee_fixed', self::PROCESSING_FEE_FIXED_DEFAULT)),
        );
    }
    
    // Session keys - single source of truth
    const SESSION_CART_KEY = 'ptp_cart_state';
    const COOKIE_SESSION_ID = 'ptp_session'; // Must match Bundle Checkout cookie name
    
    // Cache keys
    const CACHE_PREFIX = 'ptp_cart_';
    const CACHE_EXPIRY = 300; // 5 minutes
    
    // Rate limiting
    const RATE_LIMIT_CHECKOUT_MAX = 10; // Max checkout attempts per window
    const RATE_LIMIT_CHECKOUT_WINDOW = 300; // 5 minute window
    const RATE_LIMIT_CART_MAX = 30; // Max cart operations per window
    const RATE_LIMIT_CART_WINDOW = 60; // 1 minute window
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Ensure session is started - both PHP and PTP Native
        add_action('init', array($this, 'ensure_session'), 1);
        // Use wp_loaded instead of ptp-native_init to ensure cart is fully ready
        add_action('wp_loaded', array($this, 'ensure_native_session'), 10);

        // v150.5: ENABLED - Cart count filter for unified cart badge
        // This ensures the header cart badge shows training + camps combined
        add_filter('ptp_cart_contents_count', array($this, 'adjust_cart_count'), 99);
        add_filter('ptp_native_cart_count', array($this, 'adjust_cart_count'), 99);
        
        // Also hook into native cart for real-time updates
        add_action('ptp_cart_item_added', array($this, 'on_cart_change'), 10);
        add_action('ptp_cart_item_removed', array($this, 'on_cart_change'), 10);
        add_action('ptp_cart_emptied', array($this, 'on_cart_change'), 10);

        // Cleanup cron - defer to avoid early scheduling issues
        add_action('wp_loaded', array($this, 'schedule_cleanup_cron'), 20);
    }
    
    /**
     * Handle cart change - invalidate cache
     */
    public function on_cart_change() {
        self::invalidate_cart_cache();
    }
    
    /**
     * Schedule cleanup cron (deferred to avoid Action Scheduler issues)
     */
    public function schedule_cleanup_cron() {
        add_action('ptp_cleanup_abandoned_carts', array($this, 'cleanup_abandoned_bundles'));
        
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled('ptp_cleanup_abandoned_carts')) {
            wp_schedule_event(time(), 'daily', 'ptp_cleanup_abandoned_carts');
        }
    }
    
    /**
     * Adjust cart count to include training items
     * This fixes the cart badge in the header
     * v150.5: ENABLED and working with native cart
     */
    public function adjust_cart_count($count) {
        // Get cart data which includes both camps and training
        $cart_data = self::get_cart_data();
        
        // Return unified count
        return intval($cart_data['item_count'] ?? $count);
    }
    
    /**
     * Get unified item count (camps + training)
     * Use this for cart badge display
     */
    public static function get_unified_item_count() {
        $cart_data = self::get_cart_data();
        return intval($cart_data['item_count'] ?? 0);
    }
    
    /**
     * Ensure PHP session is available
     */
    public function ensure_session() {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
    }
    
    /**
     * Ensure PTP Native session is initialized
     * This is critical for cart persistence
     */
    public function ensure_native_session() {
        // If using native session, use ptp_session()
        if (function_exists('ptp_is_native_mode') && ptp_is_native_mode()) {
            if (function_exists('ptp_session') && !ptp_session()->has_session()) {
                ptp_session()->set_customer_session_cookie(true);
            }
            return;
        }

        // For non-native mode, just ensure PHP session
        if (!session_id() && !headers_sent()) {
            session_start();
        }
    }
    
    // =========================================================================
    // NONCE HELPERS - Standardized nonce creation and verification
    // =========================================================================
    
    /**
     * Create cart action nonce
     */
    public static function create_cart_nonce() {
        return wp_create_nonce(self::NONCE_CART);
    }
    
    /**
     * Create checkout action nonce
     */
    public static function create_checkout_nonce() {
        return wp_create_nonce(self::NONCE_CHECKOUT);
    }
    
    /**
     * Create bundle action nonce
     */
    public static function create_bundle_nonce() {
        return wp_create_nonce(self::NONCE_BUNDLE);
    }
    
    /**
     * Verify cart action nonce
     */
    public static function verify_cart_nonce($nonce = null) {
        $nonce = $nonce ?? ($_POST['nonce'] ?? $_GET['nonce'] ?? '');
        return wp_verify_nonce($nonce, self::NONCE_CART);
    }
    
    /**
     * Verify checkout action nonce
     * Accepts both new standardized nonce and legacy nonce for backwards compatibility
     */
    public static function verify_checkout_nonce($nonce = null) {
        $nonce = $nonce ?? ($_POST['nonce'] ?? $_GET['nonce'] ?? '');
        // Try new standardized nonce first
        if (wp_verify_nonce($nonce, self::NONCE_CHECKOUT)) {
            return true;
        }
        // Fall back to legacy nonce
        return wp_verify_nonce($nonce, 'ptp_checkout');
    }
    
    /**
     * Verify bundle action nonce
     */
    public static function verify_bundle_nonce($nonce = null) {
        $nonce = $nonce ?? ($_POST['nonce'] ?? $_GET['nonce'] ?? '');
        return wp_verify_nonce($nonce, self::NONCE_BUNDLE);
    }
    
    /**
     * Send nonce error response
     */
    public static function send_nonce_error() {
        wp_send_json_error(array(
            'message' => 'Security check failed. Please refresh the page and try again.',
            'code' => 'nonce_failed'
        ));
    }
    
    // =========================================================================
    // SESSION ID - Unique identifier for anonymous users
    // =========================================================================
    
    /**
     * Get or create session ID for current user/visitor
     * MUST match the session ID used by PTP_Bundle_Checkout
     */
    public static function get_session_id() {
        // Check cookie first (works for both logged-in and anonymous)
        if (!empty($_COOKIE[self::COOKIE_SESSION_ID])) {
            return sanitize_text_field($_COOKIE[self::COOKIE_SESSION_ID]);
        }
        
        // Generate new session ID
        $session_id = wp_generate_uuid4();
        
        if (!headers_sent()) {
            setcookie(
                self::COOKIE_SESSION_ID,
                $session_id,
                time() + (30 * DAY_IN_SECONDS),
                '/',
                '',
                is_ssl(),
                true
            );
        }
        
        $_COOKIE[self::COOKIE_SESSION_ID] = $session_id;
        
        return $session_id;
    }
    
    // =========================================================================
    // CART STATE - Single source of truth from database
    // =========================================================================
    
    /**
     * Get unified cart data from database (single source of truth)
     * All other storage mechanisms (session, cookie) are deprecated
     */
    public static function get_cart_data($force_refresh = false) {
        // Check for native mode
        $is_native = function_exists('ptp_is_native_mode') && ptp_is_native_mode();

        if ($is_native) {
            if (function_exists('ptp_session') && !ptp_session()->has_session()) {
                ptp_session()->set_customer_session_cookie(true);
            }
        }

        $session_id = self::get_session_id();
        $cache_key = self::CACHE_PREFIX . md5($session_id);

        // Check cache unless force refresh
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Build cart data
        $data = array(
            'woo_items' => array(),
            'training_items' => array(),
            'cart_subtotal' => 0,
            'training_subtotal' => 0,
            'subtotal' => 0,
            'bundle_discount' => 0,
            'bundle_discount_percent' => self::get_bundle_discount_percent(),
            'processing_fee' => 0,
            'total' => 0,
            'item_count' => 0,
            'has_bundle' => false,
            'has_camps' => false,
            'has_training' => false,
            'checkout_url' => '',
            'checkout_label' => 'Checkout',
            'bundle_code' => null,
        );

        // Get cart items from native cart
        if ($is_native && function_exists('ptp_cart')) {
            $native_cart = ptp_cart();
            $native_cart->calculate_totals();

            foreach ($native_cart->get_cart() as $cart_key => $cart_item) {
                $item_type = $cart_item['item_type'] ?? 'product';
                $is_camp = ($item_type === 'camp');
                $is_training = ($item_type === 'training');

                if ($is_camp) {
                    $data['has_camps'] = true;
                }
                if ($is_training) {
                    $data['has_training'] = true;
                }

                $item = array(
                    'key' => $cart_key,
                    'product_id' => $cart_item['item_id'],
                    'name' => $cart_item['metadata']['name'] ?? 'Item',
                    'quantity' => $cart_item['quantity'],
                    'price' => floatval($cart_item['price']),
                    'subtotal' => $cart_item['line_total'],
                    'line_total' => $cart_item['line_total'],
                    'image' => '',
                    'permalink' => '',
                    'is_camp' => $is_camp,
                    'type' => $item_type,
                    'metadata' => $cart_item['metadata'] ?? array(),
                    'dates' => $cart_item['metadata']['camp_dates'] ?? ($cart_item['metadata']['camp_date'] ?? ''),
                    'location' => $cart_item['metadata']['camp_location'] ?? '',
                    'time' => $cart_item['metadata']['camp_time'] ?? '9AM - 3PM',
                );

                if ($is_training) {
                    $data['training_items'][] = $item;
                    $data['training_subtotal'] += $item['subtotal'];
                } else {
                    $data['woo_items'][] = $item;
                    $data['cart_subtotal'] += $item['subtotal'];
                }
            }
        }
        
        // Get pending training from database (single source of truth)
        $training_items = self::get_pending_training_from_db($session_id);
        
        // ALSO check session for training items (for direct trainer profile → checkout flow)
        if (empty($training_items)) {
            $session_training = self::get_training_from_session();
            if (!empty($session_training)) {
                $training_items = $session_training;
            }
        }
        
        foreach ($training_items as $item) {
            $data['training_items'][] = $item;
            $data['training_subtotal'] += floatval($item['price']);
            $data['has_training'] = true;
            
            if (!empty($item['bundle_code'])) {
                $data['bundle_code'] = $item['bundle_code'];
            }
        }
        
        // Calculate totals using centralized function
        $totals = self::calculate_totals(
            $data['cart_subtotal'],
            $data['training_subtotal'],
            $data['has_camps'],
            $data['has_training']
        );
        
        $data['subtotal'] = $totals['subtotal'];
        $data['bundle_discount'] = $totals['bundle_discount'];
        $data['processing_fee'] = $totals['processing_fee'];
        $data['total'] = $totals['total'];
        $data['has_bundle'] = $totals['has_bundle'];
        $data['item_count'] = count($data['woo_items']) + count($data['training_items']);
        
        // Determine checkout URL
        $data['checkout_url'] = self::get_checkout_url($data);
        $data['checkout_label'] = self::get_checkout_label($data);
        
        // Cache the result
        set_transient($cache_key, $data, self::CACHE_EXPIRY);
        
        return $data;
    }
    
    /**
     * Get pending training items from database only
     * Updated to properly match bundles by session_id or user_id
     */
    public static function get_pending_training_from_db($session_id) {
        global $wpdb;
        
        $items = array();
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $table = $wpdb->prefix . 'ptp_bundles';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if (!$table_exists) {
            return $items;
        }
        
        // Get active bundles for this session or user
        $query = "SELECT * FROM {$table} 
                  WHERE status IN ('active', 'partial', 'pending')
                  AND (expires_at IS NULL OR expires_at > NOW())";
        
        if ($user_id > 0) {
            $query .= $wpdb->prepare(" AND (session_id = %s OR user_id = %d)", $session_id, $user_id);
        } else {
            $query .= $wpdb->prepare(" AND session_id = %s", $session_id);
        }
        
        $query .= " ORDER BY created_at DESC LIMIT 5";
        
        $bundles = $wpdb->get_results($query);
        
        if (empty($bundles)) {
            return $items;
        }
        
        foreach ($bundles as $bundle) {
            if (empty($bundle->trainer_id) || empty($bundle->training_amount)) {
                continue;
            }
            
            // Get trainer info
            $trainer_name = 'Trainer';
            $trainer_image = '';
            if (class_exists('PTP_Trainer')) {
                $trainer = PTP_Trainer::get($bundle->trainer_id);
                if ($trainer) {
                    $trainer_name = $trainer->display_name;
                    $trainer_image = $trainer->photo_url ?? '';
                }
            }
            
            $package_names = array(
                'single' => 'Single Session',
                '5pack' => '5-Session Pack',
                '10pack' => '10-Session Pack',
            );
            
            $items[] = array(
                'type' => 'training',
                'bundle_code' => $bundle->bundle_code,
                'trainer_id' => $bundle->trainer_id,
                'trainer_name' => $trainer_name,
                'trainer_image' => $trainer_image,
                'package' => $bundle->training_package ?? 'single',
                'package_name' => $package_names[$bundle->training_package] ?? 'Training Session',
                'sessions' => intval($bundle->training_sessions ?? 1),
                'date' => $bundle->training_date ?? '',
                'time' => $bundle->training_time ?? '',
                'location' => $bundle->training_location ?? '',
                'price' => floatval($bundle->training_amount),
                'removable' => true,
            );
        }
        
        return $items;
    }
    
    /**
     * Get training from PHP session (fallback)
     */
    public static function get_training_from_session() {
        $items = array();
        
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        // Check PHP session for pending training
        $session_keys = array('ptp_training_items', 'ptp_training_cart', 'ptp_pending_training');
        
        foreach ($session_keys as $key) {
            if (!empty($_SESSION[$key]) && is_array($_SESSION[$key])) {
                foreach ($_SESSION[$key] as $training) {
                    if (!empty($training['trainer_id']) && !empty($training['price'])) {
                        // Get trainer info
                        $trainer_name = 'Trainer';
                        $trainer_image = '';
                        if (class_exists('PTP_Trainer')) {
                            $trainer = PTP_Trainer::get($training['trainer_id']);
                            if ($trainer) {
                                $trainer_name = $trainer->display_name;
                                $trainer_image = $trainer->photo_url ?? '';
                            }
                        }
                        
                        $items[] = array(
                            'type' => 'training',
                            'bundle_code' => $training['bundle_code'] ?? null,
                            'trainer_id' => $training['trainer_id'],
                            'trainer_name' => $trainer_name,
                            'trainer_image' => $trainer_image,
                            'package' => $training['package'] ?? 'single',
                            'package_name' => $training['package_name'] ?? 'Training Session',
                            'sessions' => intval($training['sessions'] ?? 1),
                            'date' => $training['date'] ?? '',
                            'time' => $training['time'] ?? '',
                            'location' => $training['location'] ?? '',
                            'price' => floatval($training['price']),
                            'removable' => true,
                        );
                    }
                }
                break; // Only use first non-empty source
            }
        }
        
        return $items;
    }
    
    /**
     * Invalidate cart cache
     */
    public static function invalidate_cart_cache() {
        $session_id = self::get_session_id();
        $cache_key = self::CACHE_PREFIX . md5($session_id);
        delete_transient($cache_key);
    }
    
    /**
     * Clear all training items from cart (bundles table and session)
     */
    public static function clear_training_items() {
        global $wpdb;
        
        ptp_log('[PTP Cart Helper] clear_training_items called');
        
        // Start session if needed
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        $session_id = self::get_session_id();
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $table = $wpdb->prefix . 'ptp_bundles';
        
        ptp_log('[PTP Cart Helper] Session ID: ' . $session_id . ', User ID: ' . $user_id);
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        
        if ($table_exists) {
            // Delete active bundles for this session/user
            if ($user_id > 0) {
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table} 
                     WHERE status IN ('active', 'partial', 'pending')
                     AND (session_id = %s OR user_id = %d)",
                    $session_id, $user_id
                ));
            } else {
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table} 
                     WHERE status IN ('active', 'partial', 'pending')
                     AND session_id = %s",
                    $session_id
                ));
            }
            ptp_log('[PTP Cart Helper] Deleted ' . $deleted . ' bundles from database');
        }
        
        // Clear PHP session variables
        $session_keys = array(
            'ptp_training_items',
            'ptp_bundle_code', 
            'ptp_training_cart',
            'ptp_active_bundle',
            'ptp_selected_trainer',
            'ptp_booking_data',
            'ptp_pending_training'
        );
        foreach ($session_keys as $key) {
            if (isset($_SESSION[$key])) {
                unset($_SESSION[$key]);
            }
        }
        ptp_log('[PTP Cart Helper] PHP session data cleared');
        
        // Invalidate cart cache
        self::invalidate_cart_cache();
        
        return true;
    }
    
    // =========================================================================
    // DISCOUNT CALCULATION - Single point of calculation
    // =========================================================================
    
    /**
     * Calculate all cart totals including bundle discount and processing fee
     * THIS IS THE ONLY PLACE TOTALS SHOULD BE CALCULATED
     * Settings are configurable in WP Admin → PTP Training → Settings → Checkout
     */
    public static function calculate_totals($cart_subtotal, $training_subtotal, $has_camps, $has_training) {
        $subtotal = floatval($cart_subtotal) + floatval($training_subtotal);
        $has_bundle = $has_camps && $has_training;
        $bundle_discount = 0;
        
        // Get bundle discount from settings
        $bundle_discount_percent = self::get_bundle_discount_percent();
        
        if ($has_bundle && $subtotal > 0) {
            $bundle_discount = round($subtotal * ($bundle_discount_percent / 100), 2);
        }
        
        $discounted_subtotal = $subtotal - $bundle_discount;
        
        // Get processing fee settings
        $fee_settings = self::get_processing_fee_settings();
        $processing_fee = 0;
        
        if ($fee_settings['enabled'] && $discounted_subtotal > 0) {
            $processing_fee = round(($discounted_subtotal * ($fee_settings['percent'] / 100)) + $fee_settings['fixed'], 2);
        }
        
        $total = $discounted_subtotal + $processing_fee;
        
        return array(
            'subtotal' => $subtotal,
            'bundle_discount' => $bundle_discount,
            'discounted_subtotal' => $discounted_subtotal,
            'processing_fee' => $processing_fee,
            'total' => $total,
            'has_bundle' => $has_bundle,
            'discount_percent' => $bundle_discount_percent,
            'processing_fee_enabled' => $fee_settings['enabled'],
            'processing_fee_percent' => $fee_settings['percent'],
            'processing_fee_fixed' => $fee_settings['fixed'],
        );
    }
    
    /**
     * Get unified totals for Stripe Checkout
     * Returns all pricing info needed to build Stripe line items
     * v150.5: NEW METHOD for camp checkout integration
     */
    public static function get_unified_totals() {
        $cart_data = self::get_cart_data(true); // Force refresh
        
        return array(
            'subtotal' => $cart_data['subtotal'],
            'cart_subtotal' => $cart_data['cart_subtotal'],
            'training_subtotal' => $cart_data['training_subtotal'],
            'bundle_discount' => $cart_data['bundle_discount'],
            'bundle_discount_percent' => $cart_data['bundle_discount_percent'],
            'processing_fee' => $cart_data['processing_fee'],
            'total' => $cart_data['total'],
            'has_bundle' => $cart_data['has_bundle'],
            'has_camps' => $cart_data['has_camps'],
            'has_training' => $cart_data['has_training'],
            'item_count' => $cart_data['item_count'],
            'camp_items' => $cart_data['woo_items'],
            'training_items' => $cart_data['training_items'],
        );
    }
    
    /**
     * Calculate discount for a specific amount (when camps present)
     */
    public static function calculate_bundle_discount($amount, $has_camps = null) {
        // If has_camps not specified, check cart
        if ($has_camps === null) {
            $cart_data = self::get_cart_data();
            $has_camps = $cart_data['has_camps'];
        }
        
        if (!$has_camps || $amount <= 0) {
            return 0;
        }
        
        $bundle_discount_percent = self::get_bundle_discount_percent();
        return round($amount * ($bundle_discount_percent / 100), 2);
    }
    
    /**
     * Get checkout URL based on cart contents
     * v164: UNIFIED CHECKOUT - Always use /ptp-checkout/ for everything
     */
    public static function get_checkout_url($cart_data = null) {
        if (!$cart_data) {
            $cart_data = self::get_cart_data();
        }
        
        $has_camps = $cart_data['has_camps'] ?? false;
        $has_training = $cart_data['has_training'] ?? false;
        $bundle_code = $cart_data['bundle_code'] ?? '';
        
        // v164: Single unified checkout for all items (camps, trainings, or both)
        if ($has_camps || $has_training) {
            if ($bundle_code) {
                return home_url('/ptp-checkout/?bundle=' . $bundle_code);
            }
            return home_url('/ptp-checkout/');
        }
        
        // Empty cart
        return home_url('/camps/');
    }
    
    /**
     * Get checkout button label based on cart contents
     */
    public static function get_checkout_label($cart_data = null) {
        if (!$cart_data) {
            $cart_data = self::get_cart_data();
        }
        
        $has_camps = $cart_data['has_camps'] ?? false;
        $has_training = $cart_data['has_training'] ?? false;
        $discount_percent = self::get_bundle_discount_percent();
        
        if ($has_camps && $has_training) {
            return 'Bundle Checkout - Save ' . intval($discount_percent) . '%';
        }
        
        if ($has_training) {
            return 'Complete Training Booking';
        }
        
        if ($has_camps) {
            return 'Checkout';
        }
        
        return 'Browse Camps';
    }
    
    /**
     * Check if a product is a camp/clinic
     */
    public static function is_camp_product($product) {
        if (!$product || !is_object($product)) {
            return false;
        }
        
        $product_id = method_exists($product, 'get_id') ? $product->get_id() : 0;
        if (!$product_id) {
            return false;
        }
        
        // Check product type meta
        $product_type = get_post_meta($product_id, '_ptp_product_type', true);
        if (in_array($product_type, array('camp', 'clinic'))) {
            return true;
        }
        
        // Check if linked to camp
        $camp_id = get_post_meta($product_id, '_ptp_camp_id', true);
        if (!empty($camp_id)) {
            return true;
        }
        
        // Check product name
        $name = method_exists($product, 'get_name') ? strtolower($product->get_name()) : '';
        if (strpos($name, 'camp') !== false || strpos($name, 'clinic') !== false) {
            return true;
        }
        
        // Check categories
        $terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'slugs'));
        if (!is_wp_error($terms)) {
            foreach ($terms as $slug) {
                if (strpos($slug, 'camp') !== false || strpos($slug, 'clinic') !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    // =========================================================================
    // STRIPE PAYMENT VERIFICATION
    // =========================================================================
    
    /**
     * Verify Stripe payment intent status
     */
    public static function verify_stripe_payment($payment_intent_id) {
        if (!class_exists('PTP_Stripe')) {
            return new WP_Error('stripe_not_available', 'Stripe is not configured');
        }
        
        $payment_intent = PTP_Stripe::retrieve_payment_intent($payment_intent_id);
        
        if (is_wp_error($payment_intent)) {
            return $payment_intent;
        }
        
        return array(
            'verified' => in_array($payment_intent['status'], array('succeeded', 'processing')),
            'status' => $payment_intent['status'],
            'amount' => $payment_intent['amount'] / 100,
            'metadata' => $payment_intent['metadata'] ?? array(),
        );
    }
    
    // =========================================================================
    // DATABASE TRANSACTIONS
    // =========================================================================
    
    /**
     * Start database transaction
     */
    public static function start_transaction() {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
    }
    
    /**
     * Commit database transaction
     */
    public static function commit_transaction() {
        global $wpdb;
        $wpdb->query('COMMIT');
    }
    
    /**
     * Rollback database transaction
     */
    public static function rollback_transaction() {
        global $wpdb;
        $wpdb->query('ROLLBACK');
    }
    
    // =========================================================================
    // RATE LIMITING
    // =========================================================================
    
    /**
     * Check if an action is rate limited
     */
    public static function check_rate_limit($action, $limit = 10, $window = 60) {
        $session_id = self::get_session_id();
        $key = 'ptp_rate_' . $action . '_' . md5($session_id);
        
        $current = get_transient($key);
        
        if ($current === false) {
            set_transient($key, 1, $window);
            return true;
        }
        
        if ($current >= $limit) {
            ptp_log("PTP Rate Limit: $action blocked for session $session_id ($current/$limit in {$window}s)");
            return new WP_Error(
                'rate_limited',
                'Too many requests. Please wait a moment and try again.',
                array('retry_after' => $window)
            );
        }
        
        set_transient($key, $current + 1, $window);
        return true;
    }
    
    /**
     * Check checkout rate limit
     */
    public static function check_checkout_rate_limit() {
        return self::check_rate_limit('checkout', 5, 60);
    }
    
    /**
     * Check payment confirmation rate limit
     */
    public static function check_payment_rate_limit() {
        return self::check_rate_limit('payment_confirm', 3, 60);
    }
    
    /**
     * Check cart action rate limit
     */
    public static function check_cart_rate_limit() {
        return self::check_rate_limit('cart_action', 30, 60);
    }
    
    /**
     * Send rate limit error response
     */
    public static function send_rate_limit_error($error = null) {
        if (!$error || !is_wp_error($error)) {
            $error = new WP_Error('rate_limited', 'Too many requests. Please wait a moment and try again.');
        }
        
        wp_send_json_error(array(
            'message' => $error->get_error_message(),
            'code' => 'rate_limited',
            'retry_after' => $error->get_error_data()['retry_after'] ?? 60
        ), 429);
    }
    
    // =========================================================================
    // BUNDLE MANAGEMENT
    // =========================================================================
    
    /**
     * Generate unique bundle code
     */
    public static function generate_bundle_code() {
        return 'BND-' . strtoupper(wp_generate_password(8, false, false));
    }
    
    /**
     * Get active bundle for current session
     */
    public static function get_active_bundle() {
        $cart_data = self::get_cart_data();
        
        if (empty($cart_data['bundle_code'])) {
            return null;
        }
        
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bundles 
             WHERE bundle_code = %s 
             AND status IN ('active', 'partial')
             AND (expires_at IS NULL OR expires_at > NOW())",
            $cart_data['bundle_code']
        ));
    }
    
    /**
     * Clear active bundle
     */
    public static function clear_bundle($bundle_code = null) {
        global $wpdb;
        
        if (!$bundle_code) {
            $bundle = self::get_active_bundle();
            $bundle_code = $bundle ? $bundle->bundle_code : null;
        }
        
        if ($bundle_code) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bundles',
                array('status' => 'cancelled'),
                array('bundle_code' => $bundle_code)
            );
        }
        
        // Invalidate cache
        self::invalidate_cart_cache();
    }
    
    /**
     * Cleanup abandoned bundles (called by cron)
     */
    public function cleanup_abandoned_bundles() {
        global $wpdb;
        
        // Mark expired bundles as abandoned
        $wpdb->query(
            "UPDATE {$wpdb->prefix}ptp_bundles 
             SET status = 'abandoned' 
             WHERE status IN ('active', 'partial') 
             AND expires_at < NOW()"
        );
        
        // Delete old abandoned bundles (older than 30 days)
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ptp_bundles 
             WHERE status = 'abandoned' 
             AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        ptp_log('PTP Cart Helper: Cleaned up abandoned bundles');
    }
    
    // =========================================================================
    // SANITIZATION HELPERS
    // =========================================================================
    
    /**
     * Sanitize customer data from POST
     */
    public static function sanitize_customer_data($data = null) {
        if ($data === null) {
            $data = $_POST;
        }
        
        return array(
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($data['last_name'] ?? ''),
            'email' => sanitize_email($data['email'] ?? ''),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
        );
    }
    
    /**
     * Validate required customer fields
     */
    public static function validate_customer_data($customer) {
        $errors = array();
        
        if (empty($customer['first_name'])) {
            $errors[] = 'First name is required';
        }
        
        if (empty($customer['email']) || !is_email($customer['email'])) {
            $errors[] = 'Valid email is required';
        }
        
        return $errors;
    }
}

// Initialize
function ptp_cart_helper() {
    return PTP_Cart_Helper::instance();
}

add_action('plugins_loaded', 'ptp_cart_helper', 5);

// =========================================================================
// GLOBAL HELPER FUNCTIONS
// =========================================================================

/**
 * Get unified cart data
 */
function ptp_get_cart_data($force_refresh = false) {
    return PTP_Cart_Helper::get_cart_data($force_refresh);
}

/**
 * Calculate cart totals
 */
function ptp_calculate_cart_totals($cart_subtotal, $training_subtotal, $has_camps, $has_training) {
    return PTP_Cart_Helper::calculate_totals($cart_subtotal, $training_subtotal, $has_camps, $has_training);
}

/**
 * Verify Stripe payment
 */
function ptp_verify_stripe_payment($payment_intent_id) {
    return PTP_Cart_Helper::verify_stripe_payment($payment_intent_id);
}

/**
 * Get checkout URL
 */
function ptp_get_checkout_url() {
    return PTP_Cart_Helper::get_checkout_url();
}

/**
 * Check if cart has bundle discount
 */
function ptp_cart_has_bundle() {
    $cart = PTP_Cart_Helper::get_cart_data();
    return $cart['has_bundle'];
}

/**
 * Get bundle discount amount for cart
 */
function ptp_get_cart_bundle_discount() {
    $cart = PTP_Cart_Helper::get_cart_data();
    return $cart['bundle_discount'];
}

/**
 * Invalidate cart cache
 */
function ptp_invalidate_cart_cache() {
    PTP_Cart_Helper::invalidate_cart_cache();
}

/**
 * Get unified totals for Stripe checkout
 */
function ptp_get_unified_totals() {
    return PTP_Cart_Helper::get_unified_totals();
}

// =========================================================================
// V160: STRIPE ID HELPER INTEGRATION
// =========================================================================

/**
 * Find all records related to a payment intent across all order tables
 * 
 * @param string $payment_intent_id Stripe payment intent ID (pi_xxx)
 * @return array Array of records keyed by table name
 */
function ptp_find_payment_records($payment_intent_id) {
    if (class_exists('PTP_Stripe_ID_Helper')) {
        return PTP_Stripe_ID_Helper::find_all_by_payment_intent($payment_intent_id);
    }
    return array();
}

/**
 * Get standardized Stripe data from any order/booking record
 * 
 * @param object $record Database record
 * @param string $table Table name (optional)
 * @return array Standardized Stripe data
 */
function ptp_get_order_stripe_data($record, $table = '') {
    if (class_exists('PTP_Stripe_ID_Helper')) {
        return PTP_Stripe_ID_Helper::get_standardized_stripe_data($record, $table);
    }
    return array();
}
