<?php
/**
 * PTP Camp Checkout - Stripe Checkout Sessions
 * 
 * Handles Stripe Checkout Session creation for camp registrations.
 * This replaces PTP Native checkout with direct Stripe integration.
 * 
 * @version 146.0.0
 * @since 146.0.0
 */

defined('ABSPATH') || exit;

class PTP_Camp_Checkout {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Register shortcode for checkout page
        add_shortcode('ptp_camp_checkout', array($this, 'render_checkout_shortcode'));
        
        // Thank you page shortcode
        add_shortcode('ptp_camp_thank_you', array($this, 'render_thank_you_shortcode'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_get_camp_products', array($this, 'ajax_get_products'));
        add_action('wp_ajax_nopriv_ptp_get_camp_products', array($this, 'ajax_get_products'));
        add_action('wp_ajax_ptp_calculate_camp_totals', array($this, 'ajax_calculate_totals'));
        add_action('wp_ajax_nopriv_ptp_calculate_camp_totals', array($this, 'ajax_calculate_totals'));
    }
    
    /**
     * Enqueue checkout scripts
     */
    public function enqueue_scripts() {
        if (!is_page(array('camp-checkout', 'camps', 'register-camp', 'ptp-checkout', 'checkout'))) {
            return;
        }
        
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
        
        wp_enqueue_script(
            'ptp-camp-checkout',
            PTP_PLUGIN_URL . 'assets/js/camp-checkout.js',
            array('jquery', 'stripe-js'),
            PTP_VERSION,
            true
        );
        
        $stripe_key = '';
        if (class_exists('PTP_Stripe')) {
            PTP_Stripe::init();
            $stripe_key = PTP_Stripe::get_publishable_key();
        }
        
        // Get pricing constants (with fallbacks if class not loaded)
        $sibling_discount = 10;
        $referral_discount = 25;
        $care_bundle = 60;
        $jersey_price = 50;
        $processing_rate = 0.03;
        $processing_flat = 0.30;
        $multiweek_discounts = array(2 => 10, 3 => 15, 4 => 20);
        
        if (class_exists('PTP_Camp_Orders')) {
            $sibling_discount = PTP_Camp_Orders::SIBLING_DISCOUNT_PCT;
            $referral_discount = PTP_Camp_Orders::REFERRAL_DISCOUNT;
            $care_bundle = PTP_Camp_Orders::CARE_BUNDLE_PRICE;
            $jersey_price = PTP_Camp_Orders::JERSEY_PRICE;
            $processing_rate = PTP_Camp_Orders::PROCESSING_RATE;
            $processing_flat = PTP_Camp_Orders::PROCESSING_FLAT;
            $multiweek_discounts = PTP_Camp_Orders::MULTIWEEK_DISCOUNTS;
        }
        
        wp_localize_script('ptp-camp-checkout', 'ptpCampCheckout', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_nonce'),
            'stripeKey' => $stripe_key,
            'currency' => 'usd',
            'thankYouUrl' => home_url('/camp-thank-you/'),
            'siblingDiscount' => $sibling_discount,
            'referralDiscount' => $referral_discount,
            'careBundle' => $care_bundle,
            'jerseyPrice' => $jersey_price,
            'processingRate' => $processing_rate,
            'processingFlat' => $processing_flat,
            'multiweekDiscounts' => $multiweek_discounts,
        ));
        
        wp_enqueue_style(
            'ptp-camp-checkout',
            PTP_PLUGIN_URL . 'assets/css/camp-checkout.css',
            array(),
            PTP_VERSION
        );
    }
    
    /**
     * Create Stripe Checkout Session
     */
    public static function create_checkout_session($order_id, $totals) {
        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            return new WP_Error('stripe_not_configured', 'Stripe is not configured');
        }
        
        $order = PTP_Camp_Orders::get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found');
        }
        
        // Ensure Stripe products exist for all camps
        self::ensure_stripe_products_exist($order->items);
        
        // Build line items for Stripe
        $line_items = array();
        
        // Add each camp as a separate line item with real Stripe price if available
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        foreach ($order->items as $item) {
            // Try to find Stripe price ID
            $stripe_price_id = null;
            if (!empty($item->stripe_product_id)) {
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT stripe_price_id FROM $table WHERE stripe_product_id = %s AND active = 1",
                    $item->stripe_product_id
                ));
                if ($product && !empty($product->stripe_price_id) && strpos($product->stripe_price_id, 'price_') === 0) {
                    $stripe_price_id = $product->stripe_price_id;
                }
            }
            
            // Also try by DB ID
            if (!$stripe_price_id && !empty($item->camp_id)) {
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT stripe_price_id FROM $table WHERE id = %d AND active = 1",
                    $item->camp_id
                ));
                if ($product && !empty($product->stripe_price_id) && strpos($product->stripe_price_id, 'price_') === 0) {
                    $stripe_price_id = $product->stripe_price_id;
                }
            }
            
            if ($stripe_price_id) {
                // Use actual Stripe price
                $line_items[] = array(
                    'price' => $stripe_price_id,
                    'quantity' => 1,
                );
            } else {
                // Fall back to inline price_data
                $line_items[] = array(
                    'price_data' => array(
                        'currency' => 'usd',
                        'unit_amount' => round($item->base_price * 100),
                        'product_data' => array(
                            'name' => $item->camp_name,
                            'description' => sprintf('%s at %s', $item->camp_dates, $item->camp_location),
                        ),
                    ),
                    'quantity' => 1,
                );
            }
        }
        
        // Apply discount if any
        $discount_amount = isset($totals['discount_amount']) ? $totals['discount_amount'] : 0;
        
        // Care bundle line item
        if ($totals['care_bundle_total'] > 0) {
            $line_items[] = array(
                'price_data' => array(
                    'currency' => 'usd',
                    'unit_amount' => round($totals['care_bundle_total'] * 100),
                    'product_data' => array(
                        'name' => 'Before + After Care Bundle',
                        'description' => '8:00 AM - 4:30 PM extended care',
                    ),
                ),
                'quantity' => 1,
            );
        }
        
        // Jersey line item
        if ($totals['jersey_total'] > 0) {
            $jersey_count = intval($totals['jersey_total'] / PTP_Camp_Orders::JERSEY_PRICE);
            $line_items[] = array(
                'price_data' => array(
                    'currency' => 'usd',
                    'unit_amount' => round(PTP_Camp_Orders::JERSEY_PRICE * 100),
                    'product_data' => array(
                        'name' => 'PTP Camp Jersey',
                        'description' => 'Custom camp jersey with name and number',
                    ),
                ),
                'quantity' => $jersey_count,
            );
        }
        
        // Processing fee line item
        if ($totals['processing_fee'] > 0) {
            $line_items[] = array(
                'price_data' => array(
                    'currency' => 'usd',
                    'unit_amount' => round($totals['processing_fee'] * 100),
                    'product_data' => array(
                        'name' => 'Payment Processing Fee',
                        'description' => '3% + $0.30 card processing',
                    ),
                ),
                'quantity' => 1,
            );
        }
        
        // Build checkout session data
        $checkout_data = array(
            'payment_method_types[0]' => 'card',
            'mode' => 'payment',
            'success_url' => add_query_arg(array(
                'order' => $order->order_number,
                'session_id' => '{CHECKOUT_SESSION_ID}',
            ), home_url('/camp-thank-you/')),
            'cancel_url' => add_query_arg('cancelled', '1', home_url('/camps/')),
            'customer_email' => $order->billing_email,
            'client_reference_id' => $order->order_number,
            'metadata[order_id]' => $order_id,
            'metadata[order_number]' => $order->order_number,
            'metadata[type]' => 'camp_registration',
            'metadata[camper_count]' => count($order->items),
            'expires_at' => time() + (30 * 60), // 30 minutes
            'phone_number_collection[enabled]' => 'true', // Collect phone for better Meta match rate
        );
        
        // Add FB data for Meta Conversions API (improves match rate from ~43% to 90%+)
        if (class_exists('PTP_Meta_Conversions_API')) {
            $fb_data = PTP_Meta_Conversions_API::get_stored_fb_data();
            if (!empty($fb_data['fbp'])) {
                $checkout_data['metadata[fbp]'] = $fb_data['fbp'];
            }
            if (!empty($fb_data['fbc'])) {
                $checkout_data['metadata[fbc]'] = $fb_data['fbc'];
            }
            if (!empty($fb_data['client_user_agent'])) {
                $checkout_data['metadata[client_user_agent]'] = substr($fb_data['client_user_agent'], 0, 500);
            }
            if (!empty($fb_data['client_ip_address'])) {
                $checkout_data['metadata[client_ip_address]'] = $fb_data['client_ip_address'];
            }
        }
        
        // Add discount if any
        if ($discount_amount > 0) {
            // Create a coupon on the fly for the discount
            $coupon = self::stripe_request('coupons', array(
                'amount_off' => round($discount_amount * 100),
                'currency' => 'usd',
                'duration' => 'once',
                'name' => 'Referral Discount',
            ), 'POST');
            
            if (!is_wp_error($coupon) && !empty($coupon['id'])) {
                $checkout_data['discounts[0][coupon]'] = $coupon['id'];
            }
        }
        
        // Add line items
        foreach ($line_items as $index => $item) {
            if (isset($item['price'])) {
                // Using existing Stripe price
                $checkout_data["line_items[$index][price]"] = $item['price'];
                $checkout_data["line_items[$index][quantity]"] = $item['quantity'];
            } else {
                // Using inline price_data
                $checkout_data["line_items[$index][price_data][currency]"] = $item['price_data']['currency'];
                $checkout_data["line_items[$index][price_data][unit_amount]"] = $item['price_data']['unit_amount'];
                $checkout_data["line_items[$index][price_data][product_data][name]"] = $item['price_data']['product_data']['name'];
                if (!empty($item['price_data']['product_data']['description'])) {
                    $checkout_data["line_items[$index][price_data][product_data][description]"] = $item['price_data']['product_data']['description'];
                }
                $checkout_data["line_items[$index][quantity]"] = $item['quantity'];
            }
        }
        
        // Make API request
        $response = self::stripe_request('checkout/sessions', $checkout_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        if (empty($response['id']) || empty($response['url'])) {
            return new WP_Error('checkout_creation_failed', 'Failed to create checkout session');
        }
        
        return array(
            'id' => $response['id'],
            'url' => $response['url'],
        );
    }
    
    /**
     * Ensure Stripe products exist for order items
     */
    private static function ensure_stripe_products_exist($items) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        foreach ($items as $item) {
            $camp_id = $item->camp_id ?? null;
            if (!$camp_id) continue;
            
            $product = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE id = %d",
                $camp_id
            ));
            
            if (!$product) continue;
            
            // Check if needs Stripe sync
            $needs_sync = empty($product->stripe_product_id) || 
                          strpos($product->stripe_product_id, 'prod_') !== 0 ||
                          empty($product->stripe_price_id) ||
                          strpos($product->stripe_price_id, 'price_') !== 0;
            
            if ($needs_sync) {
                self::create_single_stripe_product($product);
            }
        }
    }
    
    /**
     * Create a single Stripe product and price
     */
    private static function create_single_stripe_product($product) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        // Create product in Stripe
        $stripe_product = self::stripe_request('products', array(
            'name' => $product->name,
            'description' => sprintf('%s at %s (%s)', 
                $product->camp_dates, 
                $product->camp_location,
                $product->camp_time
            ),
            'metadata[type]' => 'camp',
            'metadata[camp_dates]' => $product->camp_dates,
            'metadata[camp_location]' => $product->camp_location,
            'metadata[ptp_db_id]' => $product->id,
            'metadata[sku]' => $product->sku,
        ), 'POST');
        
        if (is_wp_error($stripe_product)) {
            ptp_log('[PTP Camp Checkout] Failed to create Stripe product: ' . $stripe_product->get_error_message());
            return false;
        }
        
        $stripe_product_id = $stripe_product['id'];
        
        // Create price
        $stripe_price = self::stripe_request('prices', array(
            'product' => $stripe_product_id,
            'unit_amount' => $product->price_cents,
            'currency' => 'usd',
            'metadata[ptp_db_id]' => $product->id,
        ), 'POST');
        
        if (is_wp_error($stripe_price)) {
            ptp_log('[PTP Camp Checkout] Failed to create Stripe price: ' . $stripe_price->get_error_message());
            return false;
        }
        
        $stripe_price_id = $stripe_price['id'];
        
        // Update local database
        $wpdb->update($table, array(
            'stripe_product_id' => $stripe_product_id,
            'stripe_price_id' => $stripe_price_id,
        ), array('id' => $product->id));
        
        ptp_log('[PTP Camp Checkout] Created Stripe product ' . $stripe_product_id . ' with price ' . $stripe_price_id);
        return true;
    }
    
    /**
     * Build registration description
     */
    private static function build_registration_description($order) {
        $campers = array();
        foreach ($order->items as $item) {
            $campers[] = $item->camper_first_name . ' ' . $item->camper_last_name . ' - ' . $item->camp_name;
        }
        
        $desc = implode('; ', array_slice($campers, 0, 3));
        if (count($campers) > 3) {
            $desc .= ' (+' . (count($campers) - 3) . ' more)';
        }
        
        return $desc;
    }
    
    /**
     * Build line items using real Stripe prices when available
     * Falls back to price_data for products without Stripe sync
     */
    public static function build_line_items_with_stripe_prices($order, $totals) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        $line_items = array();
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        
        // Process each camp item
        foreach ($order->items as $item) {
            $stripe_price_id = null;
            
            // Try to find real Stripe price
            if ($table_exists && !empty($item->camp_id)) {
                // Check by stripe_product_id from camp
                if (!empty($item->stripe_product_id)) {
                    $product = $wpdb->get_row($wpdb->prepare(
                        "SELECT stripe_price_id FROM $table WHERE stripe_product_id = %s AND active = 1",
                        $item->stripe_product_id
                    ));
                    if ($product && !empty($product->stripe_price_id) && strpos($product->stripe_price_id, 'price_') === 0) {
                        $stripe_price_id = $product->stripe_price_id;
                    }
                }
                
                // Also check by WooCommerce product ID or local ID
                if (!$stripe_price_id) {
                    $product = $wpdb->get_row($wpdb->prepare(
                        "SELECT stripe_price_id FROM $table WHERE (woo_product_id = %d OR id = %d) AND active = 1",
                        $item->camp_id, $item->camp_id
                    ));
                    if ($product && !empty($product->stripe_price_id) && strpos($product->stripe_price_id, 'price_') === 0) {
                        $stripe_price_id = $product->stripe_price_id;
                    }
                }
            }
            
            if ($stripe_price_id) {
                // Use real Stripe price
                $line_items[] = array(
                    'price' => $stripe_price_id,
                    'quantity' => 1,
                );
            } else {
                // Fallback to price_data
                $line_items[] = array(
                    'price_data' => array(
                        'currency' => 'usd',
                        'unit_amount' => round(floatval($item->price) * 100),
                        'product_data' => array(
                            'name' => $item->camp_name ?: 'PTP Camp Registration',
                            'description' => sprintf(
                                '%s %s - %s',
                                $item->camper_first_name,
                                $item->camper_last_name,
                                $item->camp_dates ?: ''
                            ),
                        ),
                    ),
                    'quantity' => 1,
                );
            }
        }
        
        // Add care bundle if applicable
        if ($totals['care_bundle_total'] > 0) {
            $care_bundle_price = self::get_stripe_price_for_addon('care_bundle');
            if ($care_bundle_price) {
                $bundle_qty = intval($totals['care_bundle_total'] / 199); // Assuming $199 per bundle
                $line_items[] = array(
                    'price' => $care_bundle_price,
                    'quantity' => max(1, $bundle_qty),
                );
            } else {
                $line_items[] = array(
                    'price_data' => array(
                        'currency' => 'usd',
                        'unit_amount' => round($totals['care_bundle_total'] * 100),
                        'product_data' => array(
                            'name' => 'Before + After Care Bundle',
                            'description' => '8:00 AM - 4:30 PM extended care',
                        ),
                    ),
                    'quantity' => 1,
                );
            }
        }
        
        // Add jersey if applicable
        if ($totals['jersey_total'] > 0) {
            $jersey_price = self::get_stripe_price_for_addon('jersey');
            $jersey_count = intval($totals['jersey_total'] / PTP_Camp_Orders::JERSEY_PRICE);
            
            if ($jersey_price) {
                $line_items[] = array(
                    'price' => $jersey_price,
                    'quantity' => max(1, $jersey_count),
                );
            } else {
                $line_items[] = array(
                    'price_data' => array(
                        'currency' => 'usd',
                        'unit_amount' => round(PTP_Camp_Orders::JERSEY_PRICE * 100),
                        'product_data' => array(
                            'name' => 'PTP Camp Jersey',
                            'description' => 'Custom camp jersey with name and number',
                        ),
                    ),
                    'quantity' => $jersey_count,
                );
            }
        }
        
        // Add processing fee if applicable
        if ($totals['processing_fee'] > 0) {
            $line_items[] = array(
                'price_data' => array(
                    'currency' => 'usd',
                    'unit_amount' => round($totals['processing_fee'] * 100),
                    'product_data' => array(
                        'name' => 'Payment Processing Fee',
                        'description' => '3% + $0.30 card processing',
                    ),
                ),
                'quantity' => 1,
            );
        }
        
        return $line_items;
    }
    
    /**
     * Get Stripe price ID for addon products
     */
    private static function get_stripe_price_for_addon($addon_type) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return null;
        }
        
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT stripe_price_id FROM $table WHERE product_type = %s AND active = 1 ORDER BY id DESC LIMIT 1",
            $addon_type
        ));
        
        if ($product && !empty($product->stripe_price_id) && strpos($product->stripe_price_id, 'price_') === 0) {
            return $product->stripe_price_id;
        }
        
        return null;
    }
    
    /**
     * Make Stripe API request
     */
    private static function stripe_request($endpoint, $data = array(), $method = 'POST') {
        $test_mode = get_option('ptp_stripe_test_mode', true);
        
        if ($test_mode) {
            $secret_key = get_option('ptp_stripe_test_secret', '');
        } else {
            $secret_key = get_option('ptp_stripe_live_secret', '');
        }
        
        if (empty($secret_key)) {
            $secret_key = get_option('ptp_stripe_secret_key', '');
        }
        
        if (empty($secret_key)) {
            return new WP_Error('no_api_key', 'Stripe API key not configured');
        }
        
        $url = 'https://api.stripe.com/v1/' . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Stripe-Version' => '2023-10-16',
            ),
            'timeout' => 60,
        );
        
        if ($method === 'POST' && !empty($data)) {
            $args['body'] = $data;
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            ptp_log('PTP Camp Checkout: Stripe API error - ' . $response->get_error_message());
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            $error_message = $body['error']['message'] ?? 'Unknown Stripe error';
            ptp_log('PTP Camp Checkout: Stripe error - ' . $error_message);
            return new WP_Error('stripe_error', $error_message);
        }
        
        return $body;
    }
    
    /**
     * Get camp products from Stripe/database
     */
    public static function get_camp_products($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'active' => true,
            'type' => 'camp',
            'orderby' => 'sort_order',
            'order' => 'ASC',
            'limit' => 50,
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Ensure table exists
        $table = $wpdb->prefix . 'ptp_stripe_products';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        
        if (!$table_exists) {
            // Create table
            self::create_stripe_products_table();
        }
        
        $where = array('1=1');
        
        if ($args['active']) {
            $where[] = 'active = 1';
        }
        
        if ($args['type']) {
            $where[] = $wpdb->prepare("product_type = %s", $args['type']);
        }
        
        $where_sql = implode(' AND ', $where);
        $order_sql = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        $limit = absint($args['limit']);
        
        $products = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_stripe_products
            WHERE {$where_sql}
            ORDER BY {$order_sql}
            LIMIT %d
        ", $limit));
        
        // v150.3: Auto-seed camps if table is empty
        if (empty($products)) {
            self::seed_default_camps();
            
            // Re-query
            $products = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}ptp_stripe_products
                WHERE {$where_sql}
                ORDER BY {$order_sql}
                LIMIT %d
            ", $limit));
        }
        
        // Format prices
        foreach ($products as &$product) {
            $product->price = $product->price_cents ? ($product->price_cents / 100) : 0;
            $product->price_formatted = '$' . number_format($product->price, 0);
            $product->spots_remaining = $product->camp_capacity 
                ? max(0, $product->camp_capacity - $product->camp_registered)
                : null;
        }
        
        return $products;
    }
    
    /**
     * Seed default PTP camps directly into database
     */
    private static function seed_default_camps() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        // PTP Summer Camps 2026
        $camps = array(
            array(
                'name' => 'Soccer Camp Wayne PA – Wilson Farm Park – June 15-19, 2026',
                'camp_dates' => 'June 15-19',
                'camp_location' => 'Wilson Farm Park',
                'camp_time' => '9AM - 3PM',
                'price_cents' => 42000,
                'camp_capacity' => 56,
                'sku' => 'PTP-SC-WAYNE-JUN15-2026',
            ),
            array(
                'name' => 'Soccer Camp Wayne PA – Wilson Farm Park – July 6-10, 2026',
                'camp_dates' => 'July 6-10',
                'camp_location' => 'Wilson Farm Park',
                'camp_time' => '9AM - 3PM',
                'price_cents' => 42000,
                'camp_capacity' => 54,
                'sku' => 'PTP-SC-WAYNE-JUL6-2026',
            ),
            array(
                'name' => 'Soccer Camp Wayne PA – Wilson Farm Park – July 27-31, 2026',
                'camp_dates' => 'July 27-31',
                'camp_location' => 'Wilson Farm Park',
                'camp_time' => '9AM - 3PM',
                'price_cents' => 42000,
                'camp_capacity' => 58,
                'sku' => 'PTP-SC-WAYNE-JUL27-2026',
            ),
            array(
                'name' => 'Soccer Camp Media PA – Sleighton Park – June 29 - July 3, 2026',
                'camp_dates' => 'June 29 - July 3',
                'camp_location' => 'Sleighton Park',
                'camp_time' => '9AM - 3PM',
                'price_cents' => 42000,
                'camp_capacity' => 60,
                'sku' => 'PTP-SC-MEDIA-JUN29-2026',
            ),
            array(
                'name' => 'Soccer Camp Media PA – Sleighton Park – July 20-24, 2026',
                'camp_dates' => 'July 20-24',
                'camp_location' => 'Sleighton Park',
                'camp_time' => '9AM - 3PM',
                'price_cents' => 42000,
                'camp_capacity' => 60,
                'sku' => 'PTP-SC-MEDIA-JUL20-2026',
            ),
            array(
                'name' => 'Soccer Camp Downingtown PA – USTC – July 13-17, 2026 - Half Day',
                'camp_dates' => 'July 13-17',
                'camp_location' => 'USTC Indoor',
                'camp_time' => '9AM - 12PM',
                'price_cents' => 32000,
                'camp_capacity' => 57,
                'sku' => 'PTP-SC-DOWNINGTOWN-JUL13-2026',
            ),
            array(
                'name' => 'Soccer Camp Newtown Square PA – Gable Park – July 6-10, 2026',
                'camp_dates' => 'July 6-10',
                'camp_location' => 'Gable Park',
                'camp_time' => '9AM - 3PM',
                'price_cents' => 42000,
                'camp_capacity' => 60,
                'sku' => 'PTP-SC-NEWTOWNSQ-JUL6-2026',
            ),
            array(
                'name' => 'Soccer Camp Cherry Hill NJ – DeCou Soccer Complex – June 22-26, 2026',
                'camp_dates' => 'June 22-26',
                'camp_location' => 'DeCou Soccer Complex',
                'camp_time' => '9AM - 3PM',
                'price_cents' => 42000,
                'camp_capacity' => 60,
                'sku' => 'PTP-SC-CHERRYHILL-JUN22-2026',
            ),
            array(
                'name' => 'Soccer Camp Cherry Hill NJ – DeCou Soccer Complex – July 20-24, 2026',
                'camp_dates' => 'July 20-24',
                'camp_location' => 'DeCou Soccer Complex',
                'camp_time' => '9AM - 3PM',
                'price_cents' => 42000,
                'camp_capacity' => 59,
                'sku' => 'PTP-SC-CHERRYHILL-JUL20-2026',
            ),
            array(
                'name' => 'Soccer Camp Villanova PA – Radnor Memorial Park – July 20-24, 2026',
                'camp_dates' => 'July 20-24',
                'camp_location' => 'Radnor Memorial Park',
                'camp_time' => '9AM - 3PM',
                'price_cents' => 45000,
                'camp_capacity' => 59,
                'sku' => 'PTP-SC-VILLANOVA-JUL20-2026',
            ),
        );
        
        $image_url = 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg';
        
        foreach ($camps as $i => $camp) {
            // Check if already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE sku = %s",
                $camp['sku']
            ));
            
            if ($exists) continue;
            
            $wpdb->insert($table, array(
                'stripe_product_id' => 'ptp_' . sanitize_title($camp['sku']),
                'stripe_price_id' => '',
                'name' => $camp['name'],
                'product_type' => 'camp',
                'price_cents' => $camp['price_cents'],
                'camp_dates' => $camp['camp_dates'],
                'camp_location' => $camp['camp_location'],
                'camp_time' => $camp['camp_time'],
                'camp_capacity' => $camp['camp_capacity'],
                'camp_registered' => 0,
                'image_url' => $image_url,
                'sort_order' => $i,
                'active' => 1,
                'sku' => $camp['sku'],
            ));
        }
        
        ptp_log('[PTP Camp Checkout] Seeded ' . count($camps) . ' default camps');
    }
    
    /**
     * Create stripe products table
     */
    private static function create_stripe_products_table() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_stripe_products';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stripe_product_id varchar(255) NOT NULL,
            stripe_price_id varchar(255) DEFAULT NULL,
            trainer_id bigint(20) UNSIGNED DEFAULT 0,
            name varchar(255) NOT NULL,
            description text,
            price_cents int(11) DEFAULT 0,
            product_type varchar(50) DEFAULT 'camp',
            camp_dates varchar(100) DEFAULT NULL,
            camp_location varchar(255) DEFAULT NULL,
            camp_time varchar(100) DEFAULT NULL,
            camp_age_min int(11) DEFAULT NULL,
            camp_age_max int(11) DEFAULT NULL,
            camp_capacity int(11) DEFAULT NULL,
            camp_registered int(11) DEFAULT 0,
            image_url varchar(500) DEFAULT NULL,
            sort_order int(11) DEFAULT 0,
            is_featured tinyint(1) DEFAULT 0,
            active tinyint(1) DEFAULT 1,
            woo_product_id bigint(20) UNSIGNED DEFAULT NULL,
            sku varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY stripe_product_id (stripe_product_id),
            KEY product_type (product_type),
            KEY active (active),
            KEY woo_product_id (woo_product_id)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Sync products from Stripe
     */
    public static function sync_products_from_stripe() {
        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            return new WP_Error('stripe_not_configured', 'Stripe not configured');
        }
        
        // Fetch products from Stripe
        $response = self::stripe_request('products', array(
            'active' => 'true',
            'limit' => 100,
        ), 'GET');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        global $wpdb;
        $synced = 0;
        
        foreach ($response['data'] ?? array() as $product) {
            // Check if it's a camp product (via metadata)
            $is_camp = isset($product['metadata']['type']) && 
                       in_array($product['metadata']['type'], array('camp', 'clinic'));
            
            if (!$is_camp && stripos($product['name'], 'camp') === false && 
                stripos($product['name'], 'clinic') === false) {
                continue;
            }
            
            // Get default price
            $price_cents = 0;
            $price_id = '';
            if (!empty($product['default_price'])) {
                $price_response = self::stripe_request('prices/' . $product['default_price'], array(), 'GET');
                if (!is_wp_error($price_response) && isset($price_response['unit_amount'])) {
                    $price_cents = $price_response['unit_amount'];
                    $price_id = $product['default_price'];
                }
            }
            
            // Upsert product
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_stripe_products WHERE stripe_product_id = %s",
                $product['id']
            ));
            
            $data = array(
                'stripe_product_id' => $product['id'],
                'stripe_price_id' => $price_id,
                'name' => $product['name'],
                'description' => $product['description'] ?? '',
                'price_cents' => $price_cents,
                'product_type' => $product['metadata']['type'] ?? 'camp',
                'camp_dates' => $product['metadata']['dates'] ?? '',
                'camp_location' => $product['metadata']['location'] ?? '',
                'camp_time' => $product['metadata']['time'] ?? '',
                'camp_age_min' => $product['metadata']['age_min'] ?? null,
                'camp_age_max' => $product['metadata']['age_max'] ?? null,
                'camp_capacity' => $product['metadata']['capacity'] ?? null,
                'image_url' => $product['images'][0] ?? '',
                'active' => $product['active'] ? 1 : 0,
                'updated_at' => current_time('mysql'),
            );
            
            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_stripe_products',
                    $data,
                    array('id' => $existing)
                );
            } else {
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($wpdb->prefix . 'ptp_stripe_products', $data);
            }
            
            $synced++;
        }
        
        return array(
            'success' => true,
            'synced' => $synced,
        );
    }
    
    /**
     * AJAX: Get camp products
     */
    public function ajax_get_products() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $products = self::get_camp_products(array(
            'type' => sanitize_text_field($_POST['type'] ?? 'camp'),
        ));
        
        wp_send_json_success(array('products' => $products));
    }
    
    /**
     * AJAX: Calculate totals
     */
    public function ajax_calculate_totals() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);
        $referral_code = sanitize_text_field($_POST['referral_code'] ?? '');
        
        $totals = PTP_Camp_Orders::calculate_totals($items, array(
            'referral_code' => $referral_code,
        ));
        
        wp_send_json_success($totals);
    }
    
    /**
     * Create Stripe products from local database
     * This pushes camp products TO Stripe
     */
    public static function create_stripe_products() {
        global $wpdb;
        
        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            return new WP_Error('stripe_not_configured', 'Stripe not configured. Please add your Stripe API keys in PTP Settings.');
        }
        
        $table = $wpdb->prefix . 'ptp_stripe_products';
        $products = $wpdb->get_results("SELECT * FROM $table WHERE product_type = 'camp' AND active = 1");
        
        if (empty($products)) {
            return new WP_Error('no_products', 'No camp products found in database');
        }
        
        $created = 0;
        $errors = array();
        
        foreach ($products as $product) {
            // Skip if already has valid Stripe IDs
            if (!empty($product->stripe_product_id) && 
                strpos($product->stripe_product_id, 'prod_') === 0 &&
                !empty($product->stripe_price_id) && 
                strpos($product->stripe_price_id, 'price_') === 0) {
                continue;
            }
            
            // Create product in Stripe (flat metadata format)
            $stripe_product = self::stripe_request('products', array(
                'name' => $product->name,
                'description' => sprintf('%s at %s (%s)', 
                    $product->camp_dates, 
                    $product->camp_location,
                    $product->camp_time
                ),
                'metadata[type]' => 'camp',
                'metadata[camp_dates]' => $product->camp_dates,
                'metadata[camp_location]' => $product->camp_location,
                'metadata[sku]' => $product->sku,
                'metadata[ptp_db_id]' => $product->id,
            ), 'POST');
            
            if (is_wp_error($stripe_product)) {
                $errors[] = $product->name . ': ' . $stripe_product->get_error_message();
                continue;
            }
            
            $stripe_product_id = $stripe_product['id'];
            
            // Create price for the product
            $stripe_price = self::stripe_request('prices', array(
                'product' => $stripe_product_id,
                'unit_amount' => $product->price_cents,
                'currency' => 'usd',
                'metadata[ptp_db_id]' => $product->id,
            ), 'POST');
            
            if (is_wp_error($stripe_price)) {
                $errors[] = $product->name . ' (price): ' . $stripe_price->get_error_message();
                continue;
            }
            
            $stripe_price_id = $stripe_price['id'];
            
            // Update local database with Stripe IDs
            $wpdb->update($table, array(
                'stripe_product_id' => $stripe_product_id,
                'stripe_price_id' => $stripe_price_id,
            ), array('id' => $product->id));
            
            $created++;
        }
        
        return array(
            'created' => $created,
            'errors' => $errors,
            'message' => sprintf('Created %d Stripe products', $created),
        );
    }
    
    /**
     * Render checkout shortcode
     */
    public function render_checkout_shortcode($atts) {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/camp/camp-checkout.php';
        return ob_get_clean();
    }
    
    /**
     * Render thank you shortcode
     */
    public function render_thank_you_shortcode($atts) {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/camp/camp-thank-you.php';
        return ob_get_clean();
    }
}

// Initialize
PTP_Camp_Checkout::instance();
