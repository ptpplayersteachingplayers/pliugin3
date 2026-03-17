<?php
/**
 * PTP Stripe Smart API v1.0
 * 
 * Smart Stripe integrations for PTP including:
 * - Training sessions API with Stripe checkout
 * - Camps/Programs API with Stripe products
 * - Payment Links generation
 * - Coupons & Promotions
 * - Subscription packages
 * - Revenue analytics
 * - Smart upsells & cross-sells
 * 
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_Stripe_Smart_API {
    
    const NAMESPACE = 'ptp/v1';
    
    private static $instance = null;
    private $secret_key;
    private $publishable_key;
    private $test_mode;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->test_mode = get_option('ptp_stripe_test_mode', true);
        
        if ($this->test_mode) {
            $this->secret_key = get_option('ptp_stripe_test_secret', '');
            $this->publishable_key = get_option('ptp_stripe_test_publishable', '');
        } else {
            $this->secret_key = get_option('ptp_stripe_live_secret', '');
            $this->publishable_key = get_option('ptp_stripe_live_publishable', '');
        }
        
        // Fallbacks
        if (empty($this->secret_key)) {
            $this->secret_key = get_option('ptp_stripe_secret_key', '');
        }
        
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register all API routes
     */
    public function register_routes() {
        
        // ============================================================
        // TRAININGS API - Individual training sessions with Stripe
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/trainings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_trainings'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/trainings/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_training'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/trainings/by-trainer/(?P<trainer_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_trainings_by_trainer'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/trainings/packages', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_training_packages'),
            'permission_callback' => '__return_true',
        ));
        
        // ============================================================
        // CAMPS API - Enhanced with Stripe checkout
        // ============================================================
        
        register_rest_route(self::NAMESPACE, '/camps/stripe-checkout', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_camp_checkout'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/camps/(?P<id>\d+)/stripe-product', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_camp_stripe_product'),
            'permission_callback' => '__return_true',
        ));
        
        // ============================================================
        // STRIPE SMART FEATURES
        // ============================================================
        
        // Payment Links
        register_rest_route(self::NAMESPACE, '/stripe/payment-link', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_payment_link'),
            'permission_callback' => array($this, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/stripe/payment-links', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_payment_links'),
            'permission_callback' => array($this, 'check_trainer_auth'),
        ));
        
        // Coupons & Promotions
        register_rest_route(self::NAMESPACE, '/stripe/coupons', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_coupons'),
            'permission_callback' => array($this, 'check_admin_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/stripe/coupon/validate', array(
            'methods' => 'POST',
            'callback' => array($this, 'validate_coupon'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/stripe/coupon', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_coupon'),
            'permission_callback' => array($this, 'check_admin_auth'),
        ));
        
        // Subscriptions
        register_rest_route(self::NAMESPACE, '/stripe/subscriptions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_subscription_plans'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route(self::NAMESPACE, '/stripe/subscription/create', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_subscription'),
            'permission_callback' => array($this, 'check_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/stripe/subscription/(?P<id>[a-zA-Z0-9_]+)/cancel', array(
            'methods' => 'POST',
            'callback' => array($this, 'cancel_subscription'),
            'permission_callback' => array($this, 'check_auth'),
        ));
        
        // Revenue & Analytics
        register_rest_route(self::NAMESPACE, '/stripe/revenue', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_revenue_stats'),
            'permission_callback' => array($this, 'check_trainer_auth'),
        ));
        
        register_rest_route(self::NAMESPACE, '/stripe/transactions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_transactions'),
            'permission_callback' => array($this, 'check_trainer_auth'),
        ));
        
        // Quick Checkout
        register_rest_route(self::NAMESPACE, '/stripe/quick-checkout', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_quick_checkout'),
            'permission_callback' => '__return_true',
        ));
        
        // Upsells
        register_rest_route(self::NAMESPACE, '/stripe/upsells', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_smart_upsells'),
            'permission_callback' => '__return_true',
        ));
        
        // Customer Portal
        register_rest_route(self::NAMESPACE, '/stripe/customer-portal', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_customer_portal'),
            'permission_callback' => array($this, 'check_auth'),
        ));
        
        // Invoices
        register_rest_route(self::NAMESPACE, '/stripe/invoices', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_invoices'),
            'permission_callback' => array($this, 'check_auth'),
        ));
        
        // Instant Payout (for trainers)
        register_rest_route(self::NAMESPACE, '/stripe/instant-payout', array(
            'methods' => 'POST',
            'callback' => array($this, 'request_instant_payout'),
            'permission_callback' => array($this, 'check_trainer_auth'),
        ));
    }
    
    // ================================================================
    // AUTH HELPERS
    // ================================================================
    
    public function check_auth($request) {
        $auth = $request->get_header('Authorization');
        if (empty($auth)) {
            return is_user_logged_in();
        }
        return true;
    }
    
    public function check_trainer_auth($request) {
        if (!$this->check_auth($request)) {
            return false;
        }
        
        // Check if user is a trainer
        if (is_user_logged_in()) {
            global $wpdb;
            $trainer = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
                get_current_user_id()
            ));
            return !empty($trainer);
        }
        
        return true; // JWT auth handles this
    }
    
    public function check_admin_auth($request) {
        return current_user_can('manage_options');
    }
    
    // ================================================================
    // STRIPE API HELPER
    // ================================================================
    
    private function stripe_request($endpoint, $method = 'GET', $data = array()) {
        if (empty($this->secret_key)) {
            return array('error' => array('message' => 'Stripe not configured'));
        }
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Stripe-Version' => '2023-10-16',
            ),
            'timeout' => 30,
        );
        
        $url = 'https://api.stripe.com/v1' . $endpoint;
        
        if (!empty($data) && in_array($method, array('POST', 'PUT'))) {
            $args['body'] = $data;
        }
        
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return array('error' => array('message' => $response->get_error_message()));
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    // ================================================================
    // TRAININGS API
    // ================================================================
    
    /**
     * Get all available training types/packages
     */
    public function get_trainings($request) {
        global $wpdb;
        
        $page = max(1, intval($request->get_param('page') ?: 1));
        $per_page = min(50, max(1, intval($request->get_param('per_page') ?: 20)));
        $offset = ($page - 1) * $per_page;
        
        $trainer_id = intval($request->get_param('trainer_id'));
        $type = sanitize_text_field($request->get_param('type')); // single, package, subscription
        
        $where = array("t.status = 'active'");
        $params = array();
        
        if ($trainer_id) {
            $where[] = "t.id = %d";
            $params[] = $trainer_id;
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Get trainers with their training options
        $sql = "SELECT t.id, t.slug, t.display_name, t.photo_url, t.hourly_rate, 
                       t.specialties, t.city, t.state, t.average_rating, t.review_count,
                       t.bio, t.training_locations
                FROM {$wpdb->prefix}ptp_trainers t
                WHERE {$where_sql}
                ORDER BY t.average_rating DESC, t.total_sessions DESC
                LIMIT %d OFFSET %d";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $trainers = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        $trainings = array();
        foreach ($trainers as $trainer) {
            $rate = intval($trainer->hourly_rate ?: 60);
            $locations = json_decode($trainer->training_locations, true) ?: array();
            
            // Build training packages for this trainer — from PTP_Packages
            $packages = array();
            if (class_exists('PTP_Packages')) {
                $popular_map = array('pack3' => true);
                foreach (PTP_Packages::all() as $pk => $def) {
                    $pricing = PTP_Packages::calculate_price($rate, $pk);
                    $packages[] = array(
                        'id' => $pk . '_' . $trainer->id,
                        'trainer_id' => $trainer->id,
                        'type' => $pk === 'single' ? 'single' : 'package',
                        'name' => $def['name'],
                        'description' => $pricing['sessions'] === 1
                            ? "1-hour private training session with {$trainer->display_name}"
                            : "{$pricing['sessions']} private sessions - Save {$def['discount']}%",
                        'sessions' => $pricing['sessions'],
                        'price' => intval($pricing['total']),
                        'price_per_session' => intval($pricing['per_session']),
                        'savings' => intval($pricing['save']),
                        'savings_percent' => $def['discount'],
                        'popular' => !empty($popular_map[$pk]),
                    );
                }
            } else {
                $packages = array(
                    array('id' => 'single_' . $trainer->id, 'trainer_id' => $trainer->id, 'type' => 'single', 'name' => 'Single Session', 'description' => "1-hour private training session with {$trainer->display_name}", 'sessions' => 1, 'price' => $rate, 'price_per_session' => $rate, 'savings' => 0, 'savings_percent' => 0, 'popular' => false),
                    array('id' => 'pack3_' . $trainer->id, 'trainer_id' => $trainer->id, 'type' => 'package', 'name' => '3-Session Pack', 'description' => "3 private sessions - Save 10%", 'sessions' => 3, 'price' => intval($rate * 3 * 0.9), 'price_per_session' => intval($rate * 0.9), 'savings' => intval($rate * 3 * 0.1), 'savings_percent' => 10, 'popular' => true),
                    array('id' => 'pack5_' . $trainer->id, 'trainer_id' => $trainer->id, 'type' => 'package', 'name' => '5-Session Pack', 'description' => "5 private sessions - Save 15%", 'sessions' => 5, 'price' => intval($rate * 5 * 0.85), 'price_per_session' => intval($rate * 0.85), 'savings' => intval($rate * 5 * 0.15), 'savings_percent' => 15, 'popular' => false),
                    array('id' => 'pack10_' . $trainer->id, 'trainer_id' => $trainer->id, 'type' => 'package', 'name' => '10-Session Pack', 'description' => "10 private sessions - Save 20%", 'sessions' => 10, 'price' => intval($rate * 10 * 0.8), 'price_per_session' => intval($rate * 0.8), 'savings' => intval($rate * 10 * 0.2), 'savings_percent' => 20, 'popular' => false),
                );
            }
            
            // Filter by type if specified
            if ($type) {
                $packages = array_filter($packages, function($p) use ($type) {
                    return $p['type'] === $type;
                });
            }
            
            $trainings[] = array(
                'trainer' => array(
                    'id' => $trainer->id,
                    'slug' => $trainer->slug,
                    'name' => $trainer->display_name,
                    'photo' => $trainer->photo_url,
                    'rating' => floatval($trainer->average_rating ?: 5),
                    'reviews' => intval($trainer->review_count ?: 0),
                    'specialties' => array_filter(explode(',', $trainer->specialties ?: '')),
                    'location' => trim(($trainer->city ?: '') . ', ' . ($trainer->state ?: ''), ', '),
                    'locations' => $locations,
                ),
                'packages' => array_values($packages),
                'hourly_rate' => $rate,
                'currency' => 'USD',
            );
        }
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'");
        
        return rest_ensure_response(array(
            'success' => true,
            'trainings' => $trainings,
            'pagination' => array(
                'total' => intval($total),
                'per_page' => $per_page,
                'page' => $page,
                'total_pages' => ceil($total / $per_page),
            ),
        ));
    }
    
    /**
     * Get single training package details
     */
    public function get_training($request) {
        $id = sanitize_text_field($request->get_param('id'));
        
        // Parse ID (format: type_trainerId)
        $parts = explode('_', $id);
        if (count($parts) < 2) {
            return new WP_Error('invalid_id', 'Invalid training ID', array('status' => 400));
        }
        
        $type = $parts[0];
        $trainer_id = intval($parts[1]);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
        }
        
        $rate = intval($trainer->hourly_rate ?: 60);
        
        $package_map = array(
            'single' => array('name' => 'Single Session', 'sessions' => 1, 'discount' => 0),
            'pack3' => array('name' => '3-Session Pack', 'sessions' => 3, 'discount' => 0.1),
            'pack5' => array('name' => '5-Session Pack', 'sessions' => 5, 'discount' => 0.15),
            'pack10' => array('name' => '10-Session Pack', 'sessions' => 10, 'discount' => 0.2),
        );
        
        if (!isset($package_map[$type])) {
            return new WP_Error('invalid_type', 'Invalid package type', array('status' => 400));
        }
        
        $pkg = $package_map[$type];
        $price = intval($rate * $pkg['sessions'] * (1 - $pkg['discount']));
        
        return rest_ensure_response(array(
            'success' => true,
            'training' => array(
                'id' => $id,
                'trainer_id' => $trainer_id,
                'type' => $type === 'single' ? 'single' : 'package',
                'name' => $pkg['name'],
                'sessions' => $pkg['sessions'],
                'price' => $price,
                'price_per_session' => intval($price / $pkg['sessions']),
                'savings_percent' => intval($pkg['discount'] * 100),
                'trainer' => array(
                    'id' => $trainer->id,
                    'slug' => $trainer->slug,
                    'name' => $trainer->display_name,
                    'photo' => $trainer->photo_url,
                    'bio' => $trainer->bio,
                    'rating' => floatval($trainer->average_rating ?: 5),
                    'specialties' => array_filter(explode(',', $trainer->specialties ?: '')),
                ),
            ),
        ));
    }
    
    /**
     * Get trainings by specific trainer
     */
    public function get_trainings_by_trainer($request) {
        $request->set_param('trainer_id', $request->get_param('trainer_id'));
        return $this->get_trainings($request);
    }
    
    /**
     * Get all training package types
     */
    public function get_training_packages($request) {
        return rest_ensure_response(array(
            'success' => true,
            'packages' => array(
                array(
                    'type' => 'single',
                    'name' => 'Single Session',
                    'sessions' => 1,
                    'discount_percent' => 0,
                    'description' => 'Perfect for trying out a new trainer',
                ),
                array(
                    'type' => 'pack3',
                    'name' => '3-Session Pack',
                    'sessions' => 3,
                    'discount_percent' => 10,
                    'description' => 'Great for focused skill development',
                    'popular' => true,
                ),
                array(
                    'type' => 'pack5',
                    'name' => '5-Session Pack',
                    'sessions' => 5,
                    'discount_percent' => 15,
                    'description' => 'Best value for consistent training',
                ),
                array(
                    'type' => 'pack10',
                    'name' => '10-Session Pack',
                    'sessions' => 10,
                    'discount_percent' => 20,
                    'description' => 'Maximum savings for committed players',
                ),
            ),
        ));
    }
    
    // ================================================================
    // CAMPS STRIPE INTEGRATION
    // ================================================================
    
    /**
     * Create Stripe Checkout Session for camp registration
     */
    public function create_camp_checkout($request) {
        global $wpdb;
        
        $camp_id = intval($request->get_param('camp_id'));
        $quantity = max(1, intval($request->get_param('quantity') ?: 1));
        $coupon_code = sanitize_text_field($request->get_param('coupon'));
        $success_url = sanitize_url($request->get_param('success_url') ?: home_url('/thank-you/?session_id={CHECKOUT_SESSION_ID}'));
        $cancel_url = sanitize_url($request->get_param('cancel_url') ?: home_url('/camps/'));
        
        // Get camp/product
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE id = %d AND active = 1",
            $camp_id
        ));
        
        if (!$product) {
            return new WP_Error('not_found', 'Camp not found', array('status' => 404));
        }
        
        // Build checkout session
        $checkout_data = array(
            'mode' => 'payment',
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'line_items[0][price]' => $product->stripe_price_id,
            'line_items[0][quantity]' => $quantity,
            'metadata[source]' => 'ptp_camps_api',
            'metadata[camp_id]' => $camp_id,
            'metadata[type]' => 'camp_registration',
        );
        
        // Apply coupon if provided
        if ($coupon_code) {
            $checkout_data['discounts[0][coupon]'] = $coupon_code;
        }
        
        // Add customer email if logged in
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $checkout_data['customer_email'] = $user->user_email;
        }
        
        $result = $this->stripe_request('/checkout/sessions', 'POST', $checkout_data);
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'checkout_url' => $result['url'],
            'session_id' => $result['id'],
        ));
    }
    
    /**
     * Get camp's Stripe product info
     */
    public function get_camp_stripe_product($request) {
        global $wpdb;
        
        $camp_id = intval($request->get_param('id'));
        
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE id = %d",
            $camp_id
        ));
        
        if (!$product) {
            return new WP_Error('not_found', 'Product not found', array('status' => 404));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'product' => array(
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'price' => floatval($product->price_cents) / 100,
                'price_cents' => intval($product->price_cents),
                'currency' => 'USD',
                'stripe_product_id' => $product->stripe_product_id,
                'stripe_price_id' => $product->stripe_price_id,
                'active' => (bool) $product->active,
            ),
        ));
    }
    
    // ================================================================
    // STRIPE SMART FEATURES
    // ================================================================
    
    /**
     * Create Payment Link for easy sharing
     */
    public function create_payment_link($request) {
        $name = sanitize_text_field($request->get_param('name'));
        $price = floatval($request->get_param('price'));
        $description = sanitize_text_field($request->get_param('description'));
        
        if (empty($name) || $price < 0.50) {
            return new WP_Error('invalid_params', 'Name and price (min $0.50) required', array('status' => 400));
        }
        
        // Create product
        $product = $this->stripe_request('/products', 'POST', array(
            'name' => $name,
            'metadata[source]' => 'ptp_payment_link',
        ));
        
        if (isset($product['error'])) {
            return new WP_Error('stripe_error', $product['error']['message'], array('status' => 400));
        }
        
        // Create price
        $stripe_price = $this->stripe_request('/prices', 'POST', array(
            'product' => $product['id'],
            'unit_amount' => intval($price * 100),
            'currency' => 'usd',
        ));
        
        if (isset($stripe_price['error'])) {
            return new WP_Error('stripe_error', $stripe_price['error']['message'], array('status' => 400));
        }
        
        // Create payment link
        $link = $this->stripe_request('/payment_links', 'POST', array(
            'line_items[0][price]' => $stripe_price['id'],
            'line_items[0][quantity]' => 1,
        ));
        
        if (isset($link['error'])) {
            return new WP_Error('stripe_error', $link['error']['message'], array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'payment_link' => array(
                'id' => $link['id'],
                'url' => $link['url'],
                'active' => $link['active'],
            ),
        ));
    }
    
    /**
     * Get all payment links for trainer
     */
    public function get_payment_links($request) {
        $result = $this->stripe_request('/payment_links', 'GET', array('limit' => 100));
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'payment_links' => $result['data'] ?? array(),
        ));
    }
    
    /**
     * Get active coupons
     */
    public function get_coupons($request) {
        $result = $this->stripe_request('/coupons', 'GET', array(
            'limit' => 50,
        ));
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        // Filter to active, non-expired coupons
        $coupons = array_filter($result['data'] ?? array(), function($c) {
            if (!$c['valid']) return false;
            if ($c['redeem_by'] && $c['redeem_by'] < time()) return false;
            return true;
        });
        
        return rest_ensure_response(array(
            'success' => true,
            'coupons' => array_map(function($c) {
                return array(
                    'id' => $c['id'],
                    'name' => $c['name'] ?? $c['id'],
                    'percent_off' => $c['percent_off'],
                    'amount_off' => $c['amount_off'] ? $c['amount_off'] / 100 : null,
                    'currency' => $c['currency'],
                    'duration' => $c['duration'],
                    'max_redemptions' => $c['max_redemptions'],
                    'times_redeemed' => $c['times_redeemed'],
                    'valid' => $c['valid'],
                );
            }, array_values($coupons)),
        ));
    }
    
    /**
     * Validate a coupon code
     */
    public function validate_coupon($request) {
        $code = sanitize_text_field($request->get_param('code'));
        $amount = floatval($request->get_param('amount'));
        
        if (empty($code)) {
            return new WP_Error('missing_code', 'Coupon code required', array('status' => 400));
        }
        
        $result = $this->stripe_request('/coupons/' . $code);
        
        if (isset($result['error'])) {
            return rest_ensure_response(array(
                'success' => false,
                'valid' => false,
                'message' => 'Invalid coupon code',
            ));
        }
        
        if (!$result['valid']) {
            return rest_ensure_response(array(
                'success' => false,
                'valid' => false,
                'message' => 'Coupon has expired or is no longer valid',
            ));
        }
        
        // Calculate discount
        $discount = 0;
        if ($result['percent_off']) {
            $discount = $amount * ($result['percent_off'] / 100);
        } elseif ($result['amount_off']) {
            $discount = $result['amount_off'] / 100;
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'valid' => true,
            'coupon' => array(
                'id' => $result['id'],
                'name' => $result['name'] ?? $result['id'],
                'percent_off' => $result['percent_off'],
                'amount_off' => $result['amount_off'] ? $result['amount_off'] / 100 : null,
            ),
            'original_amount' => $amount,
            'discount' => round($discount, 2),
            'final_amount' => round($amount - $discount, 2),
        ));
    }
    
    /**
     * Create a coupon (admin only)
     */
    public function create_coupon($request) {
        $name = sanitize_text_field($request->get_param('name'));
        $percent_off = floatval($request->get_param('percent_off'));
        $amount_off = floatval($request->get_param('amount_off'));
        $duration = sanitize_text_field($request->get_param('duration') ?: 'once');
        $max_redemptions = intval($request->get_param('max_redemptions'));
        $redeem_by = $request->get_param('redeem_by');
        
        $data = array(
            'duration' => $duration,
        );
        
        if ($name) {
            $data['name'] = $name;
            $data['id'] = sanitize_title($name);
        }
        
        if ($percent_off > 0) {
            $data['percent_off'] = min(100, $percent_off);
        } elseif ($amount_off > 0) {
            $data['amount_off'] = intval($amount_off * 100);
            $data['currency'] = 'usd';
        }
        
        if ($max_redemptions > 0) {
            $data['max_redemptions'] = $max_redemptions;
        }
        
        if ($redeem_by) {
            $data['redeem_by'] = strtotime($redeem_by);
        }
        
        $result = $this->stripe_request('/coupons', 'POST', $data);
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'coupon' => $result,
        ));
    }
    
    /**
     * Get subscription plans
     */
    public function get_subscription_plans($request) {
        // Get recurring prices
        $result = $this->stripe_request('/prices', 'GET', array(
            'type' => 'recurring',
            'active' => 'true',
            'limit' => 50,
            'expand[]' => 'data.product',
        ));
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        $plans = array_map(function($price) {
            return array(
                'id' => $price['id'],
                'product_id' => $price['product']['id'] ?? $price['product'],
                'name' => $price['product']['name'] ?? 'Subscription',
                'description' => $price['product']['description'] ?? '',
                'price' => $price['unit_amount'] / 100,
                'currency' => $price['currency'],
                'interval' => $price['recurring']['interval'],
                'interval_count' => $price['recurring']['interval_count'],
            );
        }, $result['data'] ?? array());
        
        return rest_ensure_response(array(
            'success' => true,
            'plans' => $plans,
        ));
    }
    
    /**
     * Create subscription
     */
    public function create_subscription($request) {
        $price_id = sanitize_text_field($request->get_param('price_id'));
        
        if (empty($price_id)) {
            return new WP_Error('missing_price', 'Price ID required', array('status' => 400));
        }
        
        // Get or create customer
        $user_id = get_current_user_id();
        $customer_id = get_user_meta($user_id, 'ptp_stripe_customer_id', true);
        
        if (empty($customer_id)) {
            $user = wp_get_current_user();
            $customer = $this->stripe_request('/customers', 'POST', array(
                'email' => $user->user_email,
                'name' => $user->display_name,
                'metadata[user_id]' => $user_id,
            ));
            
            if (isset($customer['error'])) {
                return new WP_Error('stripe_error', $customer['error']['message'], array('status' => 400));
            }
            
            $customer_id = $customer['id'];
            update_user_meta($user_id, 'ptp_stripe_customer_id', $customer_id);
        }
        
        // Create checkout session for subscription
        $session = $this->stripe_request('/checkout/sessions', 'POST', array(
            'mode' => 'subscription',
            'customer' => $customer_id,
            'line_items[0][price]' => $price_id,
            'line_items[0][quantity]' => 1,
            'success_url' => home_url('/account/?subscription=success'),
            'cancel_url' => home_url('/account/?subscription=cancelled'),
        ));
        
        if (isset($session['error'])) {
            return new WP_Error('stripe_error', $session['error']['message'], array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'checkout_url' => $session['url'],
            'session_id' => $session['id'],
        ));
    }
    
    /**
     * Cancel subscription
     */
    public function cancel_subscription($request) {
        $subscription_id = sanitize_text_field($request->get_param('id'));
        
        $result = $this->stripe_request('/subscriptions/' . $subscription_id, 'POST', array(
            'cancel_at_period_end' => 'true',
        ));
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Subscription will be cancelled at end of billing period',
            'cancel_at' => date('Y-m-d', $result['current_period_end']),
        ));
    }
    
    /**
     * Get revenue stats for trainer
     */
    public function get_revenue_stats($request) {
        $period = sanitize_text_field($request->get_param('period') ?: 'month');
        
        // Calculate date range
        $end = time();
        switch ($period) {
            case 'week':
                $start = strtotime('-7 days');
                break;
            case 'year':
                $start = strtotime('-1 year');
                break;
            default:
                $start = strtotime('-30 days');
        }
        
        // Get balance transactions
        $result = $this->stripe_request('/balance_transactions', 'GET', array(
            'created[gte]' => $start,
            'created[lte]' => $end,
            'limit' => 100,
            'type' => 'charge',
        ));
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        $total = 0;
        $count = 0;
        foreach ($result['data'] ?? array() as $txn) {
            $total += $txn['net'];
            $count++;
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'stats' => array(
                'period' => $period,
                'total_revenue' => $total / 100,
                'transaction_count' => $count,
                'average_transaction' => $count > 0 ? round(($total / $count) / 100, 2) : 0,
                'currency' => 'USD',
            ),
        ));
    }
    
    /**
     * Get recent transactions
     */
    public function get_transactions($request) {
        $limit = min(100, max(1, intval($request->get_param('limit') ?: 25)));
        
        $result = $this->stripe_request('/charges', 'GET', array(
            'limit' => $limit,
        ));
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        $transactions = array_map(function($charge) {
            return array(
                'id' => $charge['id'],
                'amount' => $charge['amount'] / 100,
                'currency' => $charge['currency'],
                'status' => $charge['status'],
                'description' => $charge['description'],
                'customer_email' => $charge['billing_details']['email'] ?? null,
                'created' => date('Y-m-d H:i:s', $charge['created']),
                'refunded' => $charge['refunded'],
            );
        }, $result['data'] ?? array());
        
        return rest_ensure_response(array(
            'success' => true,
            'transactions' => $transactions,
        ));
    }
    
    /**
     * Create quick checkout for training session
     */
    public function create_quick_checkout($request) {
        $trainer_id = intval($request->get_param('trainer_id'));
        $package_type = sanitize_text_field($request->get_param('package') ?: 'single');
        $session_date = sanitize_text_field($request->get_param('date'));
        $session_time = sanitize_text_field($request->get_param('time'));
        $player_name = sanitize_text_field($request->get_param('player_name'));
        $coupon = sanitize_text_field($request->get_param('coupon'));
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
        }
        
        $rate = intval($trainer->hourly_rate ?: 60);
        
        // Calculate package price
        $packages = array(
            'single' => array('sessions' => 1, 'discount' => 0),
            'pack3' => array('sessions' => 3, 'discount' => 0.1),
            'pack5' => array('sessions' => 5, 'discount' => 0.15),
            'pack10' => array('sessions' => 10, 'discount' => 0.2),
        );
        
        $pkg = $packages[$package_type] ?? $packages['single'];
        $price = intval($rate * $pkg['sessions'] * (1 - $pkg['discount']));
        
        // Create checkout session
        $checkout_data = array(
            'mode' => 'payment',
            'success_url' => home_url("/thank-you/?trainer={$trainer->slug}&session_id={CHECKOUT_SESSION_ID}"),
            'cancel_url' => home_url("/trainer/{$trainer->slug}/"),
            'line_items[0][price_data][currency]' => 'usd',
            'line_items[0][price_data][unit_amount]' => $price * 100,
            'line_items[0][price_data][product_data][name]' => "Training with {$trainer->display_name}",
            'line_items[0][price_data][product_data][description]' => "{$pkg['sessions']} session(s)",
            'line_items[0][quantity]' => 1,
            'metadata[trainer_id]' => $trainer_id,
            'metadata[package]' => $package_type,
            'metadata[sessions]' => $pkg['sessions'],
            'metadata[date]' => $session_date,
            'metadata[time]' => $session_time,
            'metadata[player_name]' => $player_name,
            'metadata[source]' => 'ptp_quick_checkout',
        );
        
        if ($coupon) {
            $checkout_data['discounts[0][coupon]'] = $coupon;
        }
        
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $checkout_data['customer_email'] = $user->user_email;
        }
        
        $result = $this->stripe_request('/checkout/sessions', 'POST', $checkout_data);
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'checkout_url' => $result['url'],
            'session_id' => $result['id'],
            'amount' => $price,
            'sessions' => $pkg['sessions'],
        ));
    }
    
    /**
     * Get smart upsells based on cart/purchase
     */
    public function get_smart_upsells($request) {
        $trainer_id = intval($request->get_param('trainer_id'));
        $current_package = sanitize_text_field($request->get_param('current_package'));
        $purchase_amount = floatval($request->get_param('amount'));
        
        $upsells = array();
        
        // Suggest package upgrade
        if ($current_package === 'single') {
            $upsells[] = array(
                'type' => 'package_upgrade',
                'title' => 'Save 10% with 3-Pack',
                'description' => 'Book 3 sessions and save 10% on each',
                'savings' => '10%',
                'action' => 'upgrade_to_pack3',
            );
        } elseif ($current_package === 'pack3') {
            $upsells[] = array(
                'type' => 'package_upgrade',
                'title' => 'Save 15% with 5-Pack',
                'description' => 'Book 5 sessions and save even more',
                'savings' => '15%',
                'action' => 'upgrade_to_pack5',
            );
        }
        
        // Suggest camps if available
        global $wpdb;
        $camps = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}ptp_stripe_products 
            WHERE active = 1 
            ORDER BY RAND() 
            LIMIT 2
        ");
        
        foreach ($camps as $camp) {
            $upsells[] = array(
                'type' => 'camp',
                'title' => $camp->name,
                'description' => $camp->description,
                'price' => $camp->price_cents / 100,
                'action' => 'add_camp',
                'camp_id' => $camp->id,
            );
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'upsells' => $upsells,
        ));
    }
    
    /**
     * Create customer portal session
     */
    public function create_customer_portal($request) {
        $user_id = get_current_user_id();
        $customer_id = get_user_meta($user_id, 'ptp_stripe_customer_id', true);
        
        if (empty($customer_id)) {
            return new WP_Error('no_customer', 'No billing account found', array('status' => 404));
        }
        
        $result = $this->stripe_request('/billing_portal/sessions', 'POST', array(
            'customer' => $customer_id,
            'return_url' => home_url('/account/'),
        ));
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'portal_url' => $result['url'],
        ));
    }
    
    /**
     * Get customer invoices
     */
    public function get_invoices($request) {
        $user_id = get_current_user_id();
        $customer_id = get_user_meta($user_id, 'ptp_stripe_customer_id', true);
        
        if (empty($customer_id)) {
            return rest_ensure_response(array(
                'success' => true,
                'invoices' => array(),
            ));
        }
        
        $result = $this->stripe_request('/invoices', 'GET', array(
            'customer' => $customer_id,
            'limit' => 25,
        ));
        
        if (isset($result['error'])) {
            return new WP_Error('stripe_error', $result['error']['message'], array('status' => 400));
        }
        
        $invoices = array_map(function($inv) {
            return array(
                'id' => $inv['id'],
                'number' => $inv['number'],
                'amount' => $inv['amount_paid'] / 100,
                'status' => $inv['status'],
                'date' => date('Y-m-d', $inv['created']),
                'pdf_url' => $inv['invoice_pdf'],
                'hosted_url' => $inv['hosted_invoice_url'],
            );
        }, $result['data'] ?? array());
        
        return rest_ensure_response(array(
            'success' => true,
            'invoices' => $invoices,
        ));
    }
    
    /**
     * Request instant payout (for trainers with Connect)
     */
    public function request_instant_payout($request) {
        global $wpdb;
        
        $user_id = get_current_user_id();
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if (!$trainer || empty($trainer->stripe_account_id)) {
            return new WP_Error('no_account', 'Stripe Connect account not set up', array('status' => 400));
        }
        
        // Get available balance
        $balance = $this->stripe_request('/balance', 'GET', array(), array(
            'Stripe-Account' => $trainer->stripe_account_id,
        ));
        
        if (isset($balance['error'])) {
            return new WP_Error('stripe_error', $balance['error']['message'], array('status' => 400));
        }
        
        $available = 0;
        foreach ($balance['available'] ?? array() as $bal) {
            if ($bal['currency'] === 'usd') {
                $available = $bal['amount'];
            }
        }
        
        if ($available < 100) { // $1 minimum
            return new WP_Error('insufficient_funds', 'Minimum $1 required for payout', array('status' => 400));
        }
        
        // Create instant payout
        $payout = $this->stripe_request('/payouts', 'POST', array(
            'amount' => $available,
            'currency' => 'usd',
            'method' => 'instant',
        ), array(
            'Stripe-Account' => $trainer->stripe_account_id,
        ));
        
        if (isset($payout['error'])) {
            return new WP_Error('stripe_error', $payout['error']['message'], array('status' => 400));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'payout' => array(
                'id' => $payout['id'],
                'amount' => $payout['amount'] / 100,
                'status' => $payout['status'],
                'arrival_date' => date('Y-m-d H:i', $payout['arrival_date']),
            ),
        ));
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Stripe_Smart_API::instance();
}, 15);
