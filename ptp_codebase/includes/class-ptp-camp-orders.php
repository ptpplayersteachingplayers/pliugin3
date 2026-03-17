<?php
/**
 * PTP Camp Orders - PTP Native-Free Camp Order Management
 * 
 * Handles camp registrations, orders, and payment tracking without PTP Native.
 * Uses Stripe Checkout Sessions for payment processing.
 * 
 * @version 146.0.0
 * @since 146.0.0
 */

defined('ABSPATH') || exit;

class PTP_Camp_Orders {
    
    private static $instance = null;
    
    // Discount constants
    const SIBLING_DISCOUNT_PCT = 10;
    const REFERRAL_DISCOUNT = 25;
    const CARE_BUNDLE_PRICE = 60;
    const JERSEY_PRICE = 50;
    const PROCESSING_RATE = 0.03;
    const PROCESSING_FLAT = 0.30;
    
    const TEAM_DISCOUNTS = [
        5 => 10,   // 5+ campers = 10% off
        10 => 15,  // 10+ campers = 15% off
        15 => 20,  // 15+ campers = 20% off
    ];
    
    const MULTIWEEK_DISCOUNTS = [
        2 => 10,   // 2 weeks = 10% off
        3 => 15,   // 3 weeks = 15% off
        4 => 20,   // 4+ weeks = 20% off
    ];
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Create tables on init
        add_action('init', array($this, 'maybe_create_tables'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_camp_checkout', array($this, 'ajax_create_checkout'));
        add_action('wp_ajax_nopriv_ptp_camp_checkout', array($this, 'ajax_create_checkout'));
        add_action('wp_ajax_ptp_apply_camp_referral', array($this, 'ajax_apply_referral'));
        add_action('wp_ajax_nopriv_ptp_apply_camp_referral', array($this, 'ajax_apply_referral'));
        
        // Stripe webhook handlers
        add_action('ptp_stripe_webhook_checkout.session.completed', array($this, 'handle_checkout_completed'));
        add_action('ptp_stripe_webhook_checkout.session.expired', array($this, 'handle_checkout_expired'));
        
        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Camp Orders table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_unified_camp_orders (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number varchar(20) NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            
            -- Contact info
            billing_first_name varchar(100) DEFAULT '',
            billing_last_name varchar(100) DEFAULT '',
            billing_email varchar(255) NOT NULL,
            billing_phone varchar(20) DEFAULT '',
            billing_address varchar(255) DEFAULT '',
            billing_city varchar(100) DEFAULT '',
            billing_state varchar(50) DEFAULT '',
            billing_zip varchar(20) DEFAULT '',
            
            -- Emergency contact
            emergency_name varchar(100) DEFAULT '',
            emergency_phone varchar(20) DEFAULT '',
            emergency_relation varchar(50) DEFAULT '',
            
            -- Pricing
            subtotal decimal(10,2) NOT NULL DEFAULT 0,
            discount_amount decimal(10,2) DEFAULT 0,
            discount_code varchar(50) DEFAULT '',
            discount_type varchar(50) DEFAULT '',
            care_bundle_total decimal(10,2) DEFAULT 0,
            jersey_total decimal(10,2) DEFAULT 0,
            processing_fee decimal(10,2) DEFAULT 0,
            total_amount decimal(10,2) NOT NULL DEFAULT 0,
            
            -- Payment
            payment_status enum('pending','processing','completed','failed','refunded','partially_refunded') DEFAULT 'pending',
            stripe_checkout_session_id varchar(255) DEFAULT '',
            stripe_payment_intent_id varchar(255) DEFAULT '',
            stripe_customer_id varchar(255) DEFAULT '',
            paid_at datetime DEFAULT NULL,
            
            -- Refunds
            refund_amount decimal(10,2) DEFAULT 0,
            refund_reason text DEFAULT NULL,
            refunded_at datetime DEFAULT NULL,
            
            -- Referral
            referral_code_used varchar(50) DEFAULT '',
            referral_code_generated varchar(50) DEFAULT '',
            referred_by_order_id bigint(20) UNSIGNED DEFAULT NULL,
            
            -- Team registration
            is_team_registration tinyint(1) DEFAULT 0,
            team_name varchar(100) DEFAULT '',
            team_coach_name varchar(100) DEFAULT '',
            team_coach_email varchar(255) DEFAULT '',
            team_coach_phone varchar(20) DEFAULT '',
            
            -- Meta
            status enum('pending','processing','completed','cancelled','refunded') DEFAULT 'pending',
            ip_address varchar(45) DEFAULT '',
            user_agent text DEFAULT NULL,
            notes text DEFAULT NULL,
            admin_notes text DEFAULT NULL,
            
            -- Attribution / UTM tracking
            utm_source varchar(100) DEFAULT '',
            utm_medium varchar(100) DEFAULT '',
            utm_campaign varchar(255) DEFAULT '',
            utm_content varchar(255) DEFAULT '',
            utm_term varchar(255) DEFAULT '',
            click_id varchar(255) DEFAULT '',
            landing_page varchar(500) DEFAULT '',
            
            -- Timestamps
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            
            PRIMARY KEY (id),
            UNIQUE KEY order_number (order_number),
            KEY user_id (user_id),
            KEY billing_email (billing_email),
            KEY status (status),
            KEY payment_status (payment_status),
            KEY stripe_checkout_session_id (stripe_checkout_session_id),
            KEY referral_code_used (referral_code_used),
            KEY referral_code_generated (referral_code_generated),
            KEY created_at (created_at)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Upgrade path: add UTM columns to existing unified_camp_orders table
        $uco_table = $wpdb->prefix . 'ptp_unified_camp_orders';
        $uco_cols = $wpdb->get_col("DESCRIBE {$uco_table}", 0);
        if (is_array($uco_cols) && !in_array('utm_source', $uco_cols)) {
            $wpdb->query("ALTER TABLE {$uco_table} ADD COLUMN utm_source VARCHAR(100) DEFAULT '' AFTER admin_notes");
            $wpdb->query("ALTER TABLE {$uco_table} ADD COLUMN utm_medium VARCHAR(100) DEFAULT '' AFTER utm_source");
            $wpdb->query("ALTER TABLE {$uco_table} ADD COLUMN utm_campaign VARCHAR(255) DEFAULT '' AFTER utm_medium");
            $wpdb->query("ALTER TABLE {$uco_table} ADD COLUMN utm_content VARCHAR(255) DEFAULT '' AFTER utm_campaign");
            $wpdb->query("ALTER TABLE {$uco_table} ADD COLUMN utm_term VARCHAR(255) DEFAULT '' AFTER utm_content");
            $wpdb->query("ALTER TABLE {$uco_table} ADD COLUMN click_id VARCHAR(255) DEFAULT '' AFTER utm_term");
            $wpdb->query("ALTER TABLE {$uco_table} ADD COLUMN landing_page VARCHAR(500) DEFAULT '' AFTER click_id");
            $wpdb->query("ALTER TABLE {$uco_table} ADD INDEX idx_utm_source (utm_source)");
        }
        
        // Camp Order Items table (campers/registrations)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_camp_order_items (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            stripe_product_id varchar(255) DEFAULT '',
            stripe_price_id varchar(255) DEFAULT '',
            
            -- Camp info
            camp_name varchar(255) NOT NULL,
            camp_week varchar(100) DEFAULT '',
            camp_dates varchar(100) DEFAULT '',
            camp_location varchar(255) DEFAULT '',
            camp_time varchar(100) DEFAULT '',
            
            -- Camper info
            camper_first_name varchar(100) NOT NULL,
            camper_last_name varchar(100) NOT NULL,
            camper_dob date DEFAULT NULL,
            camper_age int(11) DEFAULT NULL,
            camper_gender varchar(20) DEFAULT '',
            camper_shirt_size varchar(10) DEFAULT '',
            camper_team varchar(100) DEFAULT '',
            camper_skill_level varchar(50) DEFAULT '',
            camper_position varchar(50) DEFAULT '',
            
            -- Medical/Special needs
            medical_conditions text DEFAULT NULL,
            allergies text DEFAULT NULL,
            medications text DEFAULT NULL,
            special_needs text DEFAULT NULL,
            
            -- Pricing
            base_price decimal(10,2) NOT NULL DEFAULT 0,
            discount_amount decimal(10,2) DEFAULT 0,
            final_price decimal(10,2) NOT NULL DEFAULT 0,
            is_sibling tinyint(1) DEFAULT 0,
            
            -- Add-ons
            before_care tinyint(1) DEFAULT 0,
            after_care tinyint(1) DEFAULT 0,
            care_bundle tinyint(1) DEFAULT 0,
            jersey tinyint(1) DEFAULT 0,
            jersey_number varchar(10) DEFAULT '',
            jersey_name varchar(50) DEFAULT '',
            
            -- Waiver
            waiver_signed tinyint(1) DEFAULT 0,
            waiver_signed_by varchar(100) DEFAULT '',
            waiver_signed_at datetime DEFAULT NULL,
            waiver_ip varchar(45) DEFAULT '',
            photo_release tinyint(1) DEFAULT 1,
            
            -- Instagram announcement (v168)
            announcement_photo_url varchar(255) DEFAULT NULL,
            instagram_handle varchar(100) DEFAULT NULL,
            photo_consent tinyint(1) DEFAULT 0,
            
            -- Status
            status enum('registered','checked_in','completed','cancelled','no_show') DEFAULT 'registered',
            checked_in_at datetime DEFAULT NULL,
            checked_in_by bigint(20) UNSIGNED DEFAULT NULL,
            
            -- Timestamps
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY stripe_product_id (stripe_product_id),
            KEY camper_name (camper_first_name, camper_last_name),
            KEY status (status),
            KEY camp_dates (camp_dates)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Camp Referral Codes table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_camp_referrals (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code varchar(50) NOT NULL,
            order_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            email varchar(255) DEFAULT '',
            
            -- Stats
            times_used int(11) DEFAULT 0,
            total_discount_given decimal(10,2) DEFAULT 0,
            
            -- Rewards
            reward_type enum('credit','discount','none') DEFAULT 'credit',
            reward_amount decimal(10,2) DEFAULT 25,
            reward_claimed tinyint(1) DEFAULT 0,
            reward_claimed_at datetime DEFAULT NULL,
            
            -- Status
            is_active tinyint(1) DEFAULT 1,
            expires_at datetime DEFAULT NULL,
            max_uses int(11) DEFAULT NULL,
            
            -- Timestamps
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY email (email),
            KEY is_active (is_active)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Stripe Products table (camps synced from Stripe)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_stripe_products (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stripe_product_id varchar(255) NOT NULL,
            stripe_price_id varchar(255) DEFAULT '',
            
            -- Product info
            name varchar(255) NOT NULL,
            description text DEFAULT NULL,
            price_cents int(11) DEFAULT 0,
            
            -- Camp-specific metadata
            product_type enum('camp','clinic','training','membership','other') DEFAULT 'camp',
            trainer_id bigint(20) UNSIGNED DEFAULT NULL,
            camp_dates varchar(100) DEFAULT '',
            camp_location varchar(255) DEFAULT '',
            camp_time varchar(100) DEFAULT '',
            camp_age_min int(11) DEFAULT NULL,
            camp_age_max int(11) DEFAULT NULL,
            camp_capacity int(11) DEFAULT NULL,
            camp_registered int(11) DEFAULT 0,
            
            -- Display
            image_url varchar(500) DEFAULT '',
            sort_order int(11) DEFAULT 0,
            is_featured tinyint(1) DEFAULT 0,
            
            -- Status
            active tinyint(1) DEFAULT 1,
            
            -- Timestamps
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            UNIQUE KEY stripe_product_id (stripe_product_id),
            KEY product_type (product_type),
            KEY trainer_id (trainer_id),
            KEY active (active),
            KEY sort_order (sort_order)
        ) $charset_collate;";
        dbDelta($sql);
        
        // v168: Add Instagram announcement columns if they don't exist
        $table_name = $wpdb->prefix . 'ptp_camp_order_items';
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        $column_names = array_map(function($col) { return $col->Field; }, $columns);
        
        if (!in_array('announcement_photo_url', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN announcement_photo_url varchar(255) DEFAULT NULL AFTER photo_release");
        }
        if (!in_array('instagram_handle', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN instagram_handle varchar(100) DEFAULT NULL AFTER announcement_photo_url");
        }
        if (!in_array('photo_consent', $column_names)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN photo_consent tinyint(1) DEFAULT 0 AFTER instagram_handle");
        }
        
        update_option('ptp_unified_camp_orders_db_version', '168.0.0');
    }
    
    /**
     * Maybe create tables on init
     */
    public function maybe_create_tables() {
        $db_version = get_option('ptp_unified_camp_orders_db_version', '0');
        if (version_compare($db_version, '168.0.0', '<')) {
            self::create_tables();
        }
    }
    
    /**
     * Generate unique order number
     */
    public static function generate_order_number() {
        $prefix = 'PTP';
        $timestamp = date('ymd');
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
        return $prefix . $timestamp . $random;
    }
    
    /**
     * Generate referral code
     */
    public static function generate_referral_code($order_id, $email) {
        $base = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', explode('@', $email)[0]), 0, 4));
        if (strlen($base) < 4) {
            $base = str_pad($base, 4, 'X');
        }
        $suffix = strtoupper(substr(md5($order_id . $email . time()), 0, 4));
        return $base . $suffix;
    }
    
    /**
     * Calculate order totals with all discounts
     */
    public static function calculate_totals($items, $options = array()) {
        $subtotal = 0;
        $discount_amount = 0;
        $care_bundle_total = 0;
        $jersey_total = 0;
        $discount_breakdown = array();
        
        // Sort items to identify siblings
        $camper_count = count($items);
        
        foreach ($items as $index => $item) {
            $base_price = floatval($item['base_price'] ?? 0);
            $item_discount = 0;
            
            // v226: Respect item's own is_sibling flag; fall back to index for legacy
            $is_sib = !empty($item['is_sibling']);
            if ($is_sib) {
                $sibling_discount = $base_price * (self::SIBLING_DISCOUNT_PCT / 100);
                $item_discount += $sibling_discount;
                $discount_breakdown['sibling'][] = array(
                    'camper' => $item['camper_first_name'] ?? 'Camper ' . ($index + 1),
                    'amount' => $sibling_discount,
                );
            }
            
            $subtotal += $base_price;
            $discount_amount += $item_discount;
            
            // Add-ons
            if (!empty($item['care_bundle'])) {
                $care_bundle_total += self::CARE_BUNDLE_PRICE;
            }
            if (!empty($item['jersey'])) {
                $jersey_total += self::JERSEY_PRICE;
            }
        }
        
        // Team discount
        $team_discount_pct = 0;
        foreach (self::TEAM_DISCOUNTS as $min_campers => $discount_pct) {
            if ($camper_count >= $min_campers) {
                $team_discount_pct = $discount_pct;
            }
        }
        if ($team_discount_pct > 0) {
            $team_discount = $subtotal * ($team_discount_pct / 100);
            $discount_amount += $team_discount;
            $discount_breakdown['team'] = array(
                'percent' => $team_discount_pct,
                'amount' => $team_discount,
            );
        }
        
        // Multiweek discount (if same camper, multiple weeks)
        $weeks_per_camper = array();
        foreach ($items as $item) {
            $camper_key = ($item['camper_first_name'] ?? '') . '|' . ($item['camper_last_name'] ?? '');
            if (!isset($weeks_per_camper[$camper_key])) {
                $weeks_per_camper[$camper_key] = 0;
            }
            $weeks_per_camper[$camper_key]++;
        }
        foreach ($weeks_per_camper as $camper_key => $weeks) {
            if ($weeks >= 2) {
                $multiweek_pct = 0;
                foreach (self::MULTIWEEK_DISCOUNTS as $min_weeks => $discount_pct) {
                    if ($weeks >= $min_weeks) {
                        $multiweek_pct = $discount_pct;
                    }
                }
                if ($multiweek_pct > 0) {
                    // Find items for this camper
                    $camper_subtotal = 0;
                    foreach ($items as $item) {
                        $key = ($item['camper_first_name'] ?? '') . '|' . ($item['camper_last_name'] ?? '');
                        if ($key === $camper_key) {
                            $camper_subtotal += floatval($item['base_price'] ?? 0);
                        }
                    }
                    $multiweek_discount = $camper_subtotal * ($multiweek_pct / 100);
                    $discount_amount += $multiweek_discount;
                    $discount_breakdown['multiweek'][] = array(
                        'camper' => explode('|', $camper_key)[0],
                        'weeks' => $weeks,
                        'percent' => $multiweek_pct,
                        'amount' => $multiweek_discount,
                    );
                }
            }
        }
        
        // Referral code discount
        if (!empty($options['referral_code'])) {
            $referral = self::validate_referral_code($options['referral_code']);
            if ($referral && $referral['valid']) {
                $discount_amount += self::REFERRAL_DISCOUNT;
                $discount_breakdown['referral'] = array(
                    'code' => $options['referral_code'],
                    'amount' => self::REFERRAL_DISCOUNT,
                );
            }
        }
        
        // Calculate totals
        $discounted_subtotal = max(0, $subtotal - $discount_amount);
        $total_before_fees = $discounted_subtotal + $care_bundle_total + $jersey_total;
        
        // Processing fee
        $processing_fee = ($total_before_fees * self::PROCESSING_RATE) + self::PROCESSING_FLAT;
        $processing_fee = round($processing_fee, 2);
        
        $total = $total_before_fees + $processing_fee;
        
        return array(
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discount_amount, 2),
            'discount_breakdown' => $discount_breakdown,
            'care_bundle_total' => round($care_bundle_total, 2),
            'jersey_total' => round($jersey_total, 2),
            'processing_fee' => $processing_fee,
            'total' => round($total, 2),
            'camper_count' => $camper_count,
        );
    }
    
    /**
     * Validate referral code
     */
    public static function validate_referral_code($code) {
        global $wpdb;
        
        $code = strtoupper(trim($code));
        if (empty($code)) {
            return array('valid' => false, 'message' => 'No code provided');
        }
        
        $referral = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_camp_referrals
            WHERE code = %s AND is_active = 1
        ", $code));
        
        if (!$referral) {
            return array('valid' => false, 'message' => 'Invalid referral code');
        }
        
        // Check expiry
        if ($referral->expires_at && strtotime($referral->expires_at) < time()) {
            return array('valid' => false, 'message' => 'This code has expired');
        }
        
        // Check max uses
        if ($referral->max_uses && $referral->times_used >= $referral->max_uses) {
            return array('valid' => false, 'message' => 'This code has reached its maximum uses');
        }
        
        return array(
            'valid' => true,
            'code' => $code,
            'discount' => self::REFERRAL_DISCOUNT,
            'referral_id' => $referral->id,
            'referrer_order_id' => $referral->order_id,
        );
    }
    
    /**
     * Create a camp order
     */
    public static function create_order($data) {
        global $wpdb;
        
        $order_number = self::generate_order_number();
        
        // Calculate totals
        $totals = self::calculate_totals($data['items'] ?? array(), array(
            'referral_code' => $data['referral_code'] ?? '',
        ));
        
        // v226: Apply coupon discount from unified checkout
        $coupon_code = sanitize_text_field($data['coupon_code'] ?? '');
        $coupon_discount = floatval($data['coupon_discount'] ?? 0);
        if ($coupon_discount > 0 && !empty($coupon_code)) {
            $totals['discount_amount'] = round($totals['discount_amount'] + $coupon_discount, 2);
            $totals['discount_breakdown']['coupon'] = array(
                'code' => $coupon_code,
                'amount' => $coupon_discount,
            );
            // Recalculate total with coupon applied
            $discounted_sub = max(0, $totals['subtotal'] - $totals['discount_amount']);
            $total_before_fees = $discounted_sub + $totals['care_bundle_total'] + $totals['jersey_total'];
            $totals['processing_fee'] = round(($total_before_fees * self::PROCESSING_RATE) + self::PROCESSING_FLAT, 2);
            $totals['total'] = round($total_before_fees + $totals['processing_fee'], 2);
        }
        
        // Determine discount type
        $discount_type = '';
        if (!empty($totals['discount_breakdown'])) {
            $discount_type = implode(',', array_keys($totals['discount_breakdown']));
        }
        
        // Insert order
        $order_data = array(
            'order_number' => $order_number,
            'user_id' => $data['user_id'] ?? get_current_user_id(),
            'parent_id' => $data['parent_id'] ?? null,
            'billing_first_name' => sanitize_text_field($data['billing_first_name'] ?? ''),
            'billing_last_name' => sanitize_text_field($data['billing_last_name'] ?? ''),
            'billing_email' => sanitize_email($data['billing_email'] ?? ''),
            'billing_phone' => sanitize_text_field($data['billing_phone'] ?? ''),
            'billing_address' => sanitize_text_field($data['billing_address'] ?? ''),
            'billing_city' => sanitize_text_field($data['billing_city'] ?? ''),
            'billing_state' => sanitize_text_field($data['billing_state'] ?? ''),
            'billing_zip' => sanitize_text_field($data['billing_zip'] ?? ''),
            'emergency_name' => sanitize_text_field($data['emergency_name'] ?? ''),
            'emergency_phone' => sanitize_text_field($data['emergency_phone'] ?? ''),
            'emergency_relation' => sanitize_text_field($data['emergency_relation'] ?? ''),
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'discount_code' => sanitize_text_field($coupon_code ?: ($data['referral_code'] ?? '')),
            'discount_type' => $discount_type,
            'care_bundle_total' => $totals['care_bundle_total'],
            'jersey_total' => $totals['jersey_total'],
            'processing_fee' => $totals['processing_fee'],
            'total_amount' => $totals['total'],
            'referral_code_used' => sanitize_text_field($data['referral_code'] ?? ''),
            'is_team_registration' => !empty($data['is_team_registration']) ? 1 : 0,
            'team_name' => sanitize_text_field($data['team_name'] ?? ''),
            'team_coach_name' => sanitize_text_field($data['team_coach_name'] ?? ''),
            'team_coach_email' => sanitize_email($data['team_coach_email'] ?? ''),
            'team_coach_phone' => sanitize_text_field($data['team_coach_phone'] ?? ''),
            'status' => 'pending',
            'payment_status' => 'pending',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
        );
        
        // Add UTM attribution if columns exist
        $cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}ptp_unified_camp_orders", 0);
        if (is_array($cols) && in_array('utm_source', $cols)) {
            $attr = array();
            if (class_exists('PTP_Camps_Attribution')) {
                $attr = PTP_Camps_Attribution::get_attribution_from_cookies();
            } elseif (!empty($_COOKIE['ptp_lt'])) {
                $attr = json_decode(urldecode($_COOKIE['ptp_lt']), true) ?: array();
            }
            $order_data['utm_source']   = sanitize_text_field($data['utm_source'] ?? ($attr['utm_source'] ?? ''));
            $order_data['utm_medium']   = sanitize_text_field($data['utm_medium'] ?? ($attr['utm_medium'] ?? ''));
            $order_data['utm_campaign'] = sanitize_text_field($data['utm_campaign'] ?? ($attr['utm_campaign'] ?? ''));
            $order_data['utm_content']  = sanitize_text_field($data['utm_content'] ?? ($attr['utm_content'] ?? ''));
            $order_data['click_id']     = sanitize_text_field($data['click_id'] ?? ($attr['click_id'] ?? ($attr['fbclid'] ?? '')));
            $order_data['landing_page'] = sanitize_text_field($data['landing_page'] ?? ($attr['landing_page'] ?? ''));
        }
        
        $wpdb->insert($wpdb->prefix . 'ptp_unified_camp_orders', $order_data);
        $order_id = $wpdb->insert_id;
        
        if (!$order_id) {
            return new WP_Error('order_creation_failed', 'Failed to create order');
        }
        
        // Insert order items
        foreach ($data['items'] as $index => $item) {
            // v226: Respect item's own is_sibling flag (from unified checkout sibling flow)
            $is_sibling = !empty($item['is_sibling']) ? 1 : 0;
            $base_price = floatval($item['base_price'] ?? 0);
            $item_discount = 0;
            
            if ($is_sibling) {
                $item_discount = $base_price * (self::SIBLING_DISCOUNT_PCT / 100);
            }
            
            $item_data = array(
                'order_id' => $order_id,
                'stripe_product_id' => sanitize_text_field($item['stripe_product_id'] ?? ''),
                'stripe_price_id' => sanitize_text_field($item['stripe_price_id'] ?? ''),
                'camp_name' => sanitize_text_field($item['camp_name'] ?? ''),
                'camp_week' => sanitize_text_field($item['camp_week'] ?? ''),
                'camp_dates' => sanitize_text_field($item['camp_dates'] ?? ''),
                'camp_location' => sanitize_text_field($item['camp_location'] ?? ''),
                'camp_time' => sanitize_text_field($item['camp_time'] ?? ''),
                'camper_first_name' => sanitize_text_field($item['camper_first_name'] ?? ''),
                'camper_last_name' => sanitize_text_field($item['camper_last_name'] ?? ''),
                'camper_dob' => $item['camper_dob'] ?? null,
                'camper_age' => intval($item['camper_age'] ?? 0),
                'camper_gender' => sanitize_text_field($item['camper_gender'] ?? ''),
                'camper_shirt_size' => sanitize_text_field($item['camper_shirt_size'] ?? ''),
                'camper_team' => sanitize_text_field($item['camper_team'] ?? ''),
                'camper_skill_level' => sanitize_text_field($item['camper_skill_level'] ?? ''),
                'camper_position' => sanitize_text_field($item['camper_position'] ?? ''),
                'medical_conditions' => sanitize_textarea_field($item['medical_conditions'] ?? ''),
                'allergies' => sanitize_textarea_field($item['allergies'] ?? ''),
                'medications' => sanitize_textarea_field($item['medications'] ?? ''),
                'special_needs' => sanitize_textarea_field($item['special_needs'] ?? ''),
                'base_price' => $base_price,
                'discount_amount' => $item_discount,
                'final_price' => $base_price - $item_discount,
                'is_sibling' => $is_sibling,
                'before_care' => !empty($item['before_care']) ? 1 : 0,
                'after_care' => !empty($item['after_care']) ? 1 : 0,
                'care_bundle' => !empty($item['care_bundle']) ? 1 : 0,
                'jersey' => !empty($item['jersey']) ? 1 : 0,
                'jersey_number' => sanitize_text_field($item['jersey_number'] ?? ''),
                'jersey_name' => sanitize_text_field($item['jersey_name'] ?? ''),
                'waiver_signed' => !empty($item['waiver_signed']) ? 1 : 0,
                'waiver_signed_by' => sanitize_text_field($data['billing_first_name'] . ' ' . $data['billing_last_name']),
                'waiver_signed_at' => current_time('mysql'),
                'waiver_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'photo_release' => isset($item['photo_release']) ? intval($item['photo_release']) : 1,
                // v168: Instagram announcement photo
                'announcement_photo_url' => esc_url_raw($item['announcement_photo_url'] ?? ''),
                'instagram_handle' => sanitize_text_field($item['instagram_handle'] ?? ''),
                'photo_consent' => !empty($item['photo_consent']) && !empty($item['announcement_photo_url']) ? 1 : 0,
            );
            
            $wpdb->insert($wpdb->prefix . 'ptp_camp_order_items', $item_data);
        }
        
        return array(
            'order_id' => $order_id,
            'order_number' => $order_number,
            'totals' => $totals,
        );
    }
    
    /**
     * Get order by ID
     */
    public static function get_order($order_id) {
        global $wpdb;
        
        $order = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE id = %d
        ", $order_id));
        
        if (!$order) {
            return null;
        }
        
        // Get order items
        $order->items = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = %d
        ", $order_id));
        
        return $order;
    }
    
    /**
     * Get order by order number
     */
    public static function get_order_by_number($order_number) {
        global $wpdb;
        
        $order = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE order_number = %s
        ", $order_number));
        
        if (!$order) {
            return null;
        }
        
        $order->items = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = %d
        ", $order->id));
        
        return $order;
    }
    
    /**
     * Get order by Stripe checkout session
     */
    public static function get_order_by_checkout_session($session_id) {
        global $wpdb;
        
        $order = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders 
            WHERE stripe_checkout_session_id = %s
        ", $session_id));
        
        if (!$order) {
            return null;
        }
        
        $order->items = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = %d
        ", $order->id));
        
        return $order;
    }
    
    /**
     * Update order status
     */
    public static function update_order_status($order_id, $status, $payment_status = null) {
        global $wpdb;
        
        $data = array(
            'status' => $status,
            'updated_at' => current_time('mysql'),
        );
        
        if ($payment_status) {
            $data['payment_status'] = $payment_status;
        }
        
        if ($status === 'completed' || $payment_status === 'completed') {
            $data['completed_at'] = current_time('mysql');
            $data['paid_at'] = current_time('mysql');
        }
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_unified_camp_orders',
            $data,
            array('id' => $order_id)
        );
    }
    
    /**
     * v168.1: General order update function
     * Maps common field names to actual database columns
     */
    public static function update_order($order_id, $data) {
        global $wpdb;
        
        // Field mapping - common names to actual DB columns
        $field_map = array(
            'stripe_payment_intent' => 'stripe_payment_intent_id',
            'payment_intent' => 'stripe_payment_intent_id',
            'payment_intent_id' => 'stripe_payment_intent_id',
            'checkout_session' => 'stripe_checkout_session_id',
            'session_id' => 'stripe_checkout_session_id',
            'customer_id' => 'stripe_customer_id',
            'total' => 'total_amount',
        );
        
        $update_data = array(
            'updated_at' => current_time('mysql'),
        );
        
        foreach ($data as $key => $value) {
            // Map field name if needed
            $db_column = isset($field_map[$key]) ? $field_map[$key] : $key;
            $update_data[$db_column] = $value;
        }
        
        // Handle completed/paid timestamps
        if (isset($update_data['status']) && $update_data['status'] === 'completed') {
            $update_data['completed_at'] = current_time('mysql');
        }
        if (isset($update_data['payment_status']) && $update_data['payment_status'] === 'paid') {
            $update_data['paid_at'] = current_time('mysql');
        }
        
        ptp_log('[PTP_Camp_Orders v168.1] Updating order #' . $order_id . ' with: ' . json_encode($update_data));
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_unified_camp_orders',
            $update_data,
            array('id' => $order_id)
        );
    }
    
    /**
     * Update order Stripe details
     */
    public static function update_stripe_details($order_id, $session_id, $payment_intent_id = '', $customer_id = '') {
        global $wpdb;
        
        $data = array(
            'stripe_checkout_session_id' => $session_id,
        );
        
        if ($payment_intent_id) {
            $data['stripe_payment_intent_id'] = $payment_intent_id;
        }
        if ($customer_id) {
            $data['stripe_customer_id'] = $customer_id;
        }
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_unified_camp_orders',
            $data,
            array('id' => $order_id)
        );
    }
    
    /**
     * Complete order after successful payment
     */
    public static function complete_order($order_id) {
        global $wpdb;
        
        $order = self::get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found');
        }
        
        // Update order status
        self::update_order_status($order_id, 'completed', 'completed');
        
        // Generate referral code for this order
        $referral_code = self::generate_referral_code($order_id, $order->billing_email);
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_unified_camp_orders',
            array('referral_code_generated' => $referral_code),
            array('id' => $order_id)
        );
        
        // Create referral record
        $wpdb->insert(
            $wpdb->prefix . 'ptp_camp_referrals',
            array(
                'code' => $referral_code,
                'order_id' => $order_id,
                'user_id' => $order->user_id,
                'email' => $order->billing_email,
                'is_active' => 1,
            )
        );
        
        // If order used a referral code, credit the referrer
        if (!empty($order->referral_code_used)) {
            $wpdb->query($wpdb->prepare("
                UPDATE {$wpdb->prefix}ptp_camp_referrals
                SET times_used = times_used + 1,
                    total_discount_given = total_discount_given + %f
                WHERE code = %s
            ", self::REFERRAL_DISCOUNT, $order->referral_code_used));
        }
        
        // Update camp registration counts
        foreach ($order->items as $item) {
            if (!empty($item->stripe_product_id)) {
                $wpdb->query($wpdb->prepare("
                    UPDATE {$wpdb->prefix}ptp_stripe_products
                    SET camp_registered = camp_registered + 1
                    WHERE stripe_product_id = %s
                ", $item->stripe_product_id));
            }
        }
        
        // Send confirmation email
        if (class_exists('PTP_Camp_Emails')) {
            PTP_Camp_Emails::send_order_confirmation($order_id);
        }
        
        // Send SMS notification
        if (class_exists('PTP_SMS') && !empty($order->billing_phone)) {
            PTP_SMS::send(
                $order->billing_phone,
                "PTP: Your camp registration is confirmed! Order #{$order->order_number}. Check your email for details."
            );
        }
        
        return array(
            'success' => true,
            'order_id' => $order_id,
            'referral_code' => $referral_code,
        );
    }
    
    /**
     * Get orders for a user
     */
    public static function get_user_orders($user_id, $limit = 20) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT o.*, 
                   (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = o.id) as item_count
            FROM {$wpdb->prefix}ptp_unified_camp_orders o
            WHERE o.user_id = %d
            ORDER BY o.created_at DESC
            LIMIT %d
        ", $user_id, $limit));
    }
    
    /**
     * Get orders by email
     */
    public static function get_orders_by_email($email, $limit = 20) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT o.*, 
                   (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = o.id) as item_count
            FROM {$wpdb->prefix}ptp_unified_camp_orders o
            WHERE o.billing_email = %s
            ORDER BY o.created_at DESC
            LIMIT %d
        ", $email, $limit));
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('ptp/v1', '/camp-orders', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_orders'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
        
        register_rest_route('ptp/v1', '/camp-orders/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_order'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
    }
    
    /**
     * REST: Get orders
     */
    public function rest_get_orders($request) {
        global $wpdb;
        
        $page = absint($request->get_param('page') ?: 1);
        $per_page = absint($request->get_param('per_page') ?: 20);
        $status = $request->get_param('status');
        $offset = ($page - 1) * $per_page;
        
        $where = "1=1";
        if ($status) {
            $where .= $wpdb->prepare(" AND status = %s", $status);
        }
        
        $orders = $wpdb->get_results($wpdb->prepare("
            SELECT o.*, 
                   (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = o.id) as item_count
            FROM {$wpdb->prefix}ptp_unified_camp_orders o
            WHERE {$where}
            ORDER BY o.created_at DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE $where");
        
        return array(
            'orders' => $orders,
            'total' => intval($total),
            'pages' => ceil($total / $per_page),
        );
    }
    
    /**
     * REST: Get single order
     */
    public function rest_get_order($request) {
        $order_id = $request->get_param('id');
        $order = self::get_order($order_id);
        
        if (!$order) {
            return new WP_Error('not_found', 'Order not found', array('status' => 404));
        }
        
        return $order;
    }
    
    /**
     * AJAX: Create checkout session
     */
    public function ajax_create_checkout() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $data = json_decode(stripslashes($_POST['data'] ?? '{}'), true);
        
        if (empty($data['items'])) {
            wp_send_json_error(array('message' => 'No items in cart'));
        }
        
        // Create order first
        $order_result = self::create_order($data);
        
        if (is_wp_error($order_result)) {
            wp_send_json_error(array('message' => $order_result->get_error_message()));
        }
        
        // Create Stripe Checkout Session
        $checkout = PTP_Camp_Checkout::create_checkout_session(
            $order_result['order_id'],
            $order_result['totals']
        );
        
        if (is_wp_error($checkout)) {
            wp_send_json_error(array('message' => $checkout->get_error_message()));
        }
        
        // Update order with checkout session ID
        self::update_stripe_details($order_result['order_id'], $checkout['id']);
        
        wp_send_json_success(array(
            'checkout_url' => $checkout['url'],
            'session_id' => $checkout['id'],
            'order_number' => $order_result['order_number'],
        ));
    }
    
    /**
     * AJAX: Apply referral code
     */
    public function ajax_apply_referral() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $code = sanitize_text_field($_POST['code'] ?? '');
        $result = self::validate_referral_code($code);
        
        if ($result['valid']) {
            wp_send_json_success(array(
                'valid' => true,
                'discount' => $result['discount'],
                'message' => "Referral code applied! $" . $result['discount'] . " off your order.",
            ));
        } else {
            wp_send_json_error(array(
                'valid' => false,
                'message' => $result['message'],
            ));
        }
    }
    
    /**
     * Handle Stripe webhook: checkout.session.completed
     */
    public function handle_checkout_completed($event) {
        $session = $event['data']['object'];
        $order = self::get_order_by_checkout_session($session['id']);
        
        if (!$order) {
            ptp_log("[PTP Camp Orders] Checkout completed but no order found for session: " . $session['id']);
            return;
        }
        
        // Update Stripe details
        self::update_stripe_details(
            $order->id,
            $session['id'],
            $session['payment_intent'] ?? '',
            $session['customer'] ?? ''
        );
        
        // Complete the order
        self::complete_order($order->id);
        
        ptp_log("[PTP Camp Orders] Order #{$order->order_number} completed via Stripe webhook");
    }
    
    /**
     * Handle Stripe webhook: checkout.session.expired
     */
    public function handle_checkout_expired($event) {
        $session = $event['data']['object'];
        $order = self::get_order_by_checkout_session($session['id']);
        
        if (!$order) {
            return;
        }
        
        self::update_order_status($order->id, 'cancelled', 'failed');
        
        ptp_log("[PTP Camp Orders] Order #{$order->order_number} expired - checkout session timeout");
    }
}

// Initialize
PTP_Camp_Orders::instance();
