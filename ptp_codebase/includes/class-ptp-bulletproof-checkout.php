<?php
/**
 * PTP Bulletproof Checkout Handler v1.0.0
 * 
 * Handles unified checkout for:
 * - Camp registrations
 * - Training sessions
 * - Addons (jerseys, packages)
 * - Mixed carts (camps + trainings)
 * 
 * All payments processed through Stripe Payment Intents
 * 
 * @since v16
 */

defined('ABSPATH') || exit;

class PTP_Bulletproof_Checkout {
    
    private static $instance = null;
    
    // Item type constants
    const TYPE_CAMP = 'camp';
    const TYPE_TRAINING = 'training';
    const TYPE_ADDON = 'addon';
    const TYPE_PACKAGE = 'package';
    
    // Discount constants
    const MULTI_CAMP_2_DISCOUNT = 0.10; // 10% off 2 camps
    const MULTI_CAMP_3_DISCOUNT = 0.20; // 20% off 3+ camps
    const BUNDLE_DISCOUNT = 0.05;       // 5% off camp+training bundle
    const SIBLING_DISCOUNT = 0.10;      // 10% off per sibling
    
    /**
     * v241: Ensure Instagram announcement columns exist on camp_registrations
     */
    public function maybe_add_ig_columns() {
        if (get_option('ptp_ig_cols_v241') === '1') return;
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_camp_registrations';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!in_array('announcement_photo_url', $cols)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN announcement_photo_url varchar(500) DEFAULT NULL");
        }
        if (!in_array('instagram_handle', $cols)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN instagram_handle varchar(100) DEFAULT NULL");
        }
        if (!in_array('photo_consent', $cols)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN photo_consent tinyint(1) DEFAULT 0");
        }
        update_option('ptp_ig_cols_v241', '1');
    }
    
    /**
     * v241: One-time backfill — scan past orders for Instagram data and create
     * announcement rows so they appear in the admin Instagram Announcements queue.
     * 
     * Sources checked:
     *   1. ptp_camp_orders (has announcement_photo_url, instagram_handle, photo_consent)
     *   2. ptp_camp_registrations (same columns, added by maybe_add_ig_columns)
     * 
     * Only runs once (gated by option flag). Safe to re-run by deleting the option.
     */
    public function maybe_backfill_ig_announcements() {
        if (get_option('ptp_ig_backfill_v241') === 'done') return;
        if (!current_user_can('manage_options')) return;
        
        global $wpdb;
        
        $announcements_table = $wpdb->prefix . 'ptp_social_announcements';
        
        // Ensure target table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$announcements_table'") !== $announcements_table) {
            if (class_exists('PTP_Social_Announcement')) {
                PTP_Social_Announcement::instance()->maybe_create_tables();
            }
            if ($wpdb->get_var("SHOW TABLES LIKE '$announcements_table'") !== $announcements_table) {
                ptp_log('[PTP v241 Backfill] Cannot create ptp_social_announcements table — skipping');
                update_option('ptp_ig_backfill_v241', 'done');
                return;
            }
        }
        
        $backfilled = 0;
        
        // ── Source 1: ptp_camp_orders ──
        $orders_table = $wpdb->prefix . 'ptp_camp_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '$orders_table'") === $orders_table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$orders_table}", 0);
            if (in_array('instagram_handle', $cols) && in_array('announcement_photo_url', $cols)) {
                $rows = $wpdb->get_results("
                    SELECT o.id as order_id, o.instagram_handle, o.announcement_photo_url, o.photo_consent,
                           o.camper_first_name, o.camper_last_name, o.parent_email,
                           o.camp_name, o.camp_location, o.camp_date, o.created_at
                    FROM {$orders_table} o
                    WHERE (o.instagram_handle IS NOT NULL AND o.instagram_handle != '')
                       OR (o.announcement_photo_url IS NOT NULL AND o.announcement_photo_url != '')
                    ORDER BY o.id ASC
                ");
                
                foreach ($rows as $row) {
                    // Skip if already in announcements
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$announcements_table} WHERE order_id = %d LIMIT 1",
                        $row->order_id
                    ));
                    if ($exists) continue;
                    
                    $handle = trim($row->instagram_handle ?? '');
                    $photo = trim($row->announcement_photo_url ?? '');
                    if (!$handle && !$photo) continue;
                    
                    // Normalize handle
                    $handle = ltrim($handle, '@');
                    if ($handle) $handle = '@' . preg_replace('/[^a-zA-Z0-9._]/', '', $handle);
                    
                    $camper = trim(($row->camper_first_name ?? '') . ' ' . ($row->camper_last_name ?? ''));
                    if (!$camper) $camper = 'Camper';
                    
                    $wpdb->insert($announcements_table, array(
                        'order_id'         => $row->order_id,
                        'instagram_handle' => $handle,
                        'camper_name'      => $camper,
                        'camp_name'        => $row->camp_name ?? '',
                        'camp_location'    => $row->camp_location ?? '',
                        'camp_dates'       => $row->camp_date ?? '',
                        'parent_email'     => $row->parent_email ?? '',
                        'photo_url'        => esc_url_raw($photo),
                        'status'           => 'pending',
                        'created_at'       => $row->created_at ?? current_time('mysql'),
                    ));
                    $backfilled++;
                }
            }
        }
        
        // ── Source 2: ptp_camp_registrations ──
        $regs_table = $wpdb->prefix . 'ptp_camp_registrations';
        if ($wpdb->get_var("SHOW TABLES LIKE '$regs_table'") === $regs_table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$regs_table}", 0);
            if (in_array('instagram_handle', $cols) && in_array('announcement_photo_url', $cols)) {
                // Join with players/parents for names and emails
                $parents_table = $wpdb->prefix . 'ptp_parents';
                $players_table = $wpdb->prefix . 'ptp_players';
                
                $rows = $wpdb->get_results("
                    SELECT r.id as reg_id, r.instagram_handle, r.announcement_photo_url, r.photo_consent,
                           r.camp_name, r.location as camp_location, r.camp_date, r.created_at,
                           r.stripe_payment_intent_id,
                           CONCAT(pl.first_name, ' ', COALESCE(pl.last_name, '')) as camper_name,
                           pa.email as parent_email
                    FROM {$regs_table} r
                    LEFT JOIN {$players_table} pl ON r.player_id = pl.id
                    LEFT JOIN {$parents_table} pa ON r.parent_id = pa.id
                    WHERE (r.instagram_handle IS NOT NULL AND r.instagram_handle != '')
                       OR (r.announcement_photo_url IS NOT NULL AND r.announcement_photo_url != '')
                    ORDER BY r.id ASC
                ");
                
                foreach ($rows as $row) {
                    $handle = trim($row->instagram_handle ?? '');
                    $photo = trim($row->announcement_photo_url ?? '');
                    if (!$handle && !$photo) continue;
                    
                    // Check for existing by reg ID (use booking_id field since there's no reg_id column)
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$announcements_table} WHERE booking_id = %d LIMIT 1",
                        $row->reg_id
                    ));
                    if ($exists) continue;
                    
                    $handle = ltrim($handle, '@');
                    if ($handle) $handle = '@' . preg_replace('/[^a-zA-Z0-9._]/', '', $handle);
                    
                    $camper = trim($row->camper_name ?? '');
                    if (!$camper) $camper = 'Camper';
                    
                    $wpdb->insert($announcements_table, array(
                        'booking_id'       => $row->reg_id,
                        'instagram_handle' => $handle,
                        'camper_name'      => $camper,
                        'camp_name'        => $row->camp_name ?? '',
                        'camp_location'    => $row->camp_location ?? '',
                        'camp_dates'       => $row->camp_date ?? '',
                        'parent_email'     => $row->parent_email ?? '',
                        'photo_url'        => esc_url_raw($photo),
                        'status'           => 'pending',
                        'created_at'       => $row->created_at ?? current_time('mysql'),
                    ));
                    $backfilled++;
                }
            }
        }
        
        update_option('ptp_ig_backfill_v241', 'done');
        
        if ($backfilled > 0) {
            ptp_log("[PTP v241 Backfill] Created {$backfilled} Instagram announcement(s) from past orders");
            // Notify admin
            $admin_email = get_option('admin_email');
            wp_mail(
                $admin_email,
                "[PTP] Instagram Announcements Backfilled — {$backfilled} found",
                "PTP v241 scanned your past orders and found {$backfilled} Instagram announcement(s) that parents submitted but were never queued.\n\n" .
                "They're now in your Instagram Announcements dashboard (WP Admin > PTP > Instagram Announcements) with status 'pending'.\n\n" .
                "Review them and use the Copy/Posted/Skip buttons to process each one."
            );
        } else {
            ptp_log('[PTP v241 Backfill] No past Instagram submissions found to backfill');
        }
    }
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_ptp_create_payment_intent', array($this, 'ajax_create_payment_intent'));
        add_action('wp_ajax_nopriv_ptp_create_payment_intent', array($this, 'ajax_create_payment_intent'));
        
        add_action('wp_ajax_ptp_confirm_checkout', array($this, 'ajax_confirm_checkout'));
        add_action('wp_ajax_nopriv_ptp_confirm_checkout', array($this, 'ajax_confirm_checkout'));
        
        // v241: Ensure IG columns exist on camp_registrations table
        add_action('init', array($this, 'maybe_add_ig_columns'), 20);
        
        // v241: One-time backfill of Instagram announcements from past orders
        add_action('admin_init', array($this, 'maybe_backfill_ig_announcements'));
        
        // Add to cart handlers
        add_action('wp_ajax_ptp_add_to_unified_cart', array($this, 'ajax_add_to_cart'));
        add_action('wp_ajax_nopriv_ptp_add_to_unified_cart', array($this, 'ajax_add_to_cart'));
        
        // Training-specific add to cart (from trainer profiles)
        add_action('wp_ajax_ptp_add_training_to_cart', array($this, 'ajax_add_training_to_cart'));
        add_action('wp_ajax_nopriv_ptp_add_training_to_cart', array($this, 'ajax_add_training_to_cart'));
        
        // Camp-specific add to cart (alias for compatibility)
        add_action('wp_ajax_ptp_add_camp_to_cart', array($this, 'ajax_add_camp_to_cart'));
        add_action('wp_ajax_nopriv_ptp_add_camp_to_cart', array($this, 'ajax_add_camp_to_cart'));
        
        // Add multiple camp weeks at once (from camp product template)
        add_action('wp_ajax_ptp_camps_add_multiple_weeks', array($this, 'ajax_add_multiple_camps'));
        add_action('wp_ajax_nopriv_ptp_camps_add_multiple_weeks', array($this, 'ajax_add_multiple_camps'));
        
        // REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('ptp/v1', '/checkout/calculate', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_calculate_totals'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route('ptp/v1', '/checkout/create-intent', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_create_payment_intent'),
            'permission_callback' => '__return_true',
        ));
    }
    
    // =========================================================================
    // CART OPERATIONS
    // =========================================================================
    
    /**
     * Add item to unified cart
     */
    public function ajax_add_to_cart() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_pack_nonce') && 
            !wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_cart_action')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $item_type = sanitize_text_field($_POST['item_type'] ?? self::TYPE_CAMP);
        $item_id = absint($_POST['item_id'] ?? $_POST['product_id'] ?? 0);
        $quantity = absint($_POST['quantity'] ?? 1);
        $clear_cart = !empty($_POST['clear_cart']);
        
        if (!$item_id && $item_type !== self::TYPE_ADDON) {
            wp_send_json_error(array('message' => 'Invalid item'));
            return;
        }
        
        if (!function_exists('ptp_cart')) {
            wp_send_json_error(array('message' => 'Cart not available'));
            return;
        }
        
        // Clear cart if requested
        if ($clear_cart) {
            ptp_cart()->empty_cart();
        }
        
        // Get item data based on type
        $item_data = $this->get_item_data($item_type, $item_id);
        
        if (!$item_data) {
            wp_send_json_error(array('message' => 'Item not found'));
            return;
        }
        
        // Build metadata
        $metadata = array(
            'name' => $item_data['name'],
            'stripe_product' => $item_data['stripe_product'] ?? '',
            'stripe_price' => $item_data['stripe_price'] ?? '',
            'source_url' => esc_url_raw($_POST['source_url'] ?? ''),
        );
        
        // Add type-specific metadata
        if ($item_type === self::TYPE_CAMP) {
            $metadata['date'] = $item_data['date'] ?? '';
            $metadata['location'] = $item_data['location'] ?? '';
            $metadata['time'] = $item_data['time'] ?? '9AM - 3PM';
        } elseif ($item_type === self::TYPE_TRAINING) {
            $metadata['trainer_id'] = $item_data['trainer_id'] ?? 0;
            $metadata['trainer_name'] = $item_data['trainer_name'] ?? '';
            $metadata['session_date'] = $item_data['session_date'] ?? '';
            $metadata['session_time'] = $item_data['session_time'] ?? '';
            $metadata['location'] = $item_data['location'] ?? '';
        }
        
        // Add to cart
        $cart_key = ptp_cart()->add_to_cart(
            $item_type,
            $item_id,
            $quantity,
            $item_data['price'],
            $metadata
        );
        
        // Store source URL in session
        if (!empty($metadata['source_url']) && function_exists('ptp_session')) {
            ptp_session()->set('ptp_source_url', $metadata['source_url']);
        }
        
        if ($cart_key) {
            // v175.2: CRITICAL - Must save before wp_send_json exits (shutdown won't run)
            ptp_cart()->save_cart();
            
            // Calculate new totals
            $totals = $this->calculate_cart_totals();
            
            wp_send_json_success(array(
                'cart_key' => $cart_key,
                'cart_count' => ptp_cart()->get_cart_contents_count(),
                'totals' => $totals,
                'redirect' => home_url('/ptp-checkout/'),
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to add to cart'));
        }
    }
    
    /**
     * Get item data by type and ID
     */
    private function get_item_data($type, $id) {
        global $wpdb;
        
        switch ($type) {
            case self::TYPE_CAMP:
                // Try Stripe products table first
                $stripe_product = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE id = %d",
                    $id
                ));
                
                if ($stripe_product) {
                    return array(
                        'id' => $stripe_product->id,
                        'name' => $stripe_product->name,
                        'price' => floatval($stripe_product->price),
                        'stripe_product' => $stripe_product->stripe_product_id,
                        'stripe_price' => $stripe_product->stripe_price_id,
                        'date' => $stripe_product->camp_date ?? '',
                        'location' => $stripe_product->location ?? '',
                        'time' => '9AM - 3PM',
                    );
                }
                
                // Fallback to post meta
                $post = get_post($id);
                if ($post) {
                    return array(
                        'id' => $id,
                        'name' => $post->post_title,
                        'price' => floatval(get_post_meta($id, '_price', true) ?: 421),
                        'stripe_product' => get_post_meta($id, '_stripe_product_id', true),
                        'stripe_price' => get_post_meta($id, '_stripe_price_id', true),
                        'date' => get_post_meta($id, '_camp_date', true),
                        'location' => get_post_meta($id, '_camp_location', true),
                        'time' => get_post_meta($id, '_camp_time', true) ?: '9AM - 3PM',
                    );
                }
                break;
                
            case self::TYPE_TRAINING:
                // Get trainer info
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $id
                ));
                
                if ($trainer) {
                    $rate = intval($trainer->hourly_rate ?: 60);
                    return array(
                        'id' => $id,
                        'name' => '1v1 Training with ' . $trainer->display_name,
                        'price' => floatval($rate),
                        'trainer_id' => $trainer->id,
                        'trainer_name' => $trainer->display_name,
                        'stripe_product' => get_option('ptp_stripe_training_product_id', ''),
                        'stripe_price' => '', // Dynamic pricing
                    );
                }
                break;
                
            case self::TYPE_ADDON:
                // Addon items (jersey, care bundle, etc.)
                $addons = array(
                    'jersey' => array('name' => 'World Cup Jersey', 'price' => 50),
                    'care' => array('name' => 'Before/After Care', 'price' => 60),
                );
                
                $addon_key = sanitize_text_field($_POST['addon_key'] ?? 'jersey');
                if (isset($addons[$addon_key])) {
                    return array(
                        'id' => 0,
                        'name' => $addons[$addon_key]['name'],
                        'price' => floatval($addons[$addon_key]['price']),
                        'stripe_product' => '',
                        'stripe_price' => '',
                    );
                }
                break;
        }
        
        return null;
    }
    
    /**
     * Add training session to cart (from trainer profile)
     */
    public function ajax_add_training_to_cart() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_booking_nonce') && 
            !wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_cart_action') &&
            !wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_pack_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $trainer_id = absint($_POST['trainer_id'] ?? 0);
        $session_date = sanitize_text_field($_POST['session_date'] ?? '');
        $session_time = sanitize_text_field($_POST['session_time'] ?? '');
        $location = sanitize_text_field($_POST['location'] ?? '');
        $duration = absint($_POST['duration'] ?? 60);
        $source_url = esc_url_raw($_POST['source_url'] ?? '');
        $clear_cart = !empty($_POST['clear_cart']);
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer'));
            return;
        }
        
        if (!function_exists('ptp_cart')) {
            wp_send_json_error(array('message' => 'Cart not available'));
            return;
        }
        
        // Get trainer info
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        // Calculate price based on duration
        $hourly_rate = floatval($trainer->hourly_rate ?: 60);
        $price = ($hourly_rate / 60) * $duration;
        
        // Clear cart if requested
        if ($clear_cart) {
            ptp_cart()->empty_cart();
        }
        
        // Build metadata
        $metadata = array(
            'name' => '1v1 Training with ' . $trainer->display_name,
            'trainer_id' => $trainer_id,
            'trainer_name' => $trainer->display_name,
            'trainer_photo' => $trainer->photo_url ?? '',
            'session_date' => $session_date,
            'session_time' => $session_time,
            'duration' => $duration,
            'location' => $location,
            'source_url' => $source_url,
            'stripe_product' => get_option('ptp_stripe_training_product_id', ''),
            'stripe_price' => '', // Dynamic pricing
        );
        
        // Add to cart
        $cart_key = ptp_cart()->add_to_cart(
            self::TYPE_TRAINING,
            $trainer_id,
            1,
            $price,
            $metadata
        );
        
        // Store source URL in session
        if ($source_url && function_exists('ptp_session')) {
            ptp_session()->set('ptp_source_url', $source_url);
        }
        
        if ($cart_key) {
            // v175.2: CRITICAL - Must save before wp_send_json exits (shutdown won't run)
            ptp_cart()->save_cart();
            
            $totals = $this->calculate_cart_totals();
            
            wp_send_json_success(array(
                'cart_key' => $cart_key,
                'cart_count' => ptp_cart()->get_cart_contents_count(),
                'totals' => $totals,
                'redirect' => home_url('/ptp-checkout/'),
                'message' => 'Training session added to cart',
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to add to cart'));
        }
    }
    
    /**
     * Add camp to cart (alias handler)
     */
    public function ajax_add_camp_to_cart() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_pack_nonce') && 
            !wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_cart_action')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $product_id = absint($_POST['product_id'] ?? 0);
        $source_url = esc_url_raw($_POST['source_url'] ?? '');
        $clear_cart = !empty($_POST['clear_cart']) || !empty($_POST['direct_checkout']);
        
        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid product'));
            return;
        }
        
        if (!function_exists('ptp_cart')) {
            wp_send_json_error(array('message' => 'Cart not available'));
            return;
        }
        
        // Clear cart if requested
        if ($clear_cart) {
            ptp_cart()->empty_cart();
        }
        
        // Get product info
        $item_data = $this->get_item_data(self::TYPE_CAMP, $product_id);
        
        if (!$item_data) {
            wp_send_json_error(array('message' => 'Product not found'));
            return;
        }
        
        // Build metadata
        $metadata = array(
            'name' => $item_data['name'],
            'stripe_product' => $item_data['stripe_product'],
            'stripe_price' => $item_data['stripe_price'],
            'date' => $item_data['date'],
            'location' => $item_data['location'],
            'time' => $item_data['time'],
            'source_url' => $source_url,
        );
        
        // Add to cart
        $cart_key = ptp_cart()->add_to_cart(
            self::TYPE_CAMP,
            $product_id,
            1,
            $item_data['price'],
            $metadata
        );
        
        // Store source URL in session
        if ($source_url && function_exists('ptp_session')) {
            ptp_session()->set('ptp_source_url', $source_url);
        }
        
        if ($cart_key) {
            // v175.2: CRITICAL - Must save before wp_send_json exits (shutdown won't run)
            ptp_cart()->save_cart();
            
            $totals = $this->calculate_cart_totals();
            
            wp_send_json_success(array(
                'cart_key' => $cart_key,
                'cart_count' => ptp_cart()->get_cart_contents_count(),
                'totals' => $totals,
                'redirect' => home_url('/ptp-checkout/'),
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to add to cart'));
        }
    }
    
    /**
     * Add multiple camp weeks to cart at once (from camp product template)
     */
    public function ajax_add_multiple_camps() {
        // Verify nonce - accept multiple nonce names for compatibility
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $nonce_valid = wp_verify_nonce($nonce, 'ptp_camps_nonce') || 
                       wp_verify_nonce($nonce, 'ptp_cart_action') ||
                       wp_verify_nonce($nonce, 'ptp_pack_nonce');
        
        if (!$nonce_valid) {
            ptp_log('[PTP Bulletproof] Multiple camps nonce failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Get camp IDs from request
        $camp_ids = array();
        if (!empty($_POST['product_ids'])) {
            $camp_ids = array_map('absint', (array) $_POST['product_ids']);
        } elseif (!empty($_POST['camp_ids'])) {
            $camp_ids = array_map('absint', (array) $_POST['camp_ids']);
        }
        
        if (empty($camp_ids)) {
            wp_send_json_error(array('message' => 'No camps selected'));
            return;
        }
        
        if (!function_exists('ptp_cart')) {
            wp_send_json_error(array('message' => 'Cart not available'));
            return;
        }
        
        // Clear cart and add fresh
        ptp_cart()->empty_cart();
        
        // Parse camps_data JSON if provided
        $camps_data = array();
        if (!empty($_POST['camps_data'])) {
            $decoded = json_decode(stripslashes($_POST['camps_data']), true);
            if (is_array($decoded)) {
                $camps_data = $decoded;
            }
        }
        
        // Early bird handling
        $is_early_bird = false; // v227: Early bird disabled
        $early_bird_amount = 0;
        
        $added_count = 0;
        $added_camps = array();
        
        foreach ($camp_ids as $index => $camp_id) {
            // Get camp data from camps_data array or fetch from database
            $camp_info = isset($camps_data[$index]) ? $camps_data[$index] : array();
            
            // Try to get item data from database
            $item_data = $this->get_item_data(self::TYPE_CAMP, $camp_id);
            
            if (!$item_data) {
                // Try as WordPress post (ptp_camp CPT)
                $post = get_post($camp_id);
                if ($post) {
                    $base_price = floatval(get_post_meta($camp_id, '_camp_price', true) ?: 525);
                    $price = $is_early_bird ? max(0, $base_price - $early_bird_amount) : $base_price;
                    
                    $item_data = array(
                        'id' => $camp_id,
                        'name' => $camp_info['name'] ?? $post->post_title,
                        'price' => $price,
                        'stripe_product' => $camp_info['stripeProduct'] ?? get_post_meta($camp_id, '_camp_stripe_product_id', true),
                        'stripe_price' => $camp_info['stripePrice'] ?? get_post_meta($camp_id, '_camp_stripe_price_id', true),
                        'date' => $camp_info['date'] ?? get_post_meta($camp_id, '_camp_date_short', true),
                        'location' => $camp_info['location'] ?? get_post_meta($camp_id, '_camp_location_short', true),
                        'time' => $camp_info['time'] ?? get_post_meta($camp_id, '_camp_time_short', true) ?: '9AM - 3PM',
                    );
                }
            }
            
            if (!$item_data) {
                ptp_log('[PTP Bulletproof] Camp not found: ' . $camp_id);
                continue;
            }
            
            // Apply early bird discount if not already applied
            if ($is_early_bird && !empty($camps_data[$index]['price'])) {
                $item_data['price'] = floatval($camps_data[$index]['price']);
            }
            
            // Build metadata
            $metadata = array(
                'name' => $item_data['name'],
                'stripe_product' => $item_data['stripe_product'] ?? '',
                'stripe_price' => $item_data['stripe_price'] ?? '',
                'date' => $item_data['date'] ?? '',
                'location' => $item_data['location'] ?? '',
                'time' => $item_data['time'] ?? '9AM - 3PM',
                'early_bird' => $is_early_bird ? 1 : 0,
            );
            
            // Add to cart
            $cart_key = ptp_cart()->add_to_cart(
                self::TYPE_CAMP,
                $camp_id,
                1,
                $item_data['price'],
                $metadata
            );
            
            if ($cart_key) {
                $added_count++;
                $added_camps[] = array(
                    'id' => $camp_id,
                    'name' => $item_data['name'],
                    'price' => $item_data['price'],
                );
            }
        }
        
        if ($added_count === 0) {
            wp_send_json_error(array('message' => 'Failed to add camps to cart'));
            return;
        }
        
        // Store multiweek info in session
        if (function_exists('ptp_session') && $added_count > 1) {
            ptp_session()->set('ptp_multiweek_count', $added_count);
            ptp_session()->set('ptp_early_bird', $is_early_bird);
        }
        
        // Calculate totals
        $totals = $this->calculate_cart_totals();
        
        // v175.2: CRITICAL - Must save before wp_send_json exits (shutdown won't run)
        ptp_cart()->save_cart();
        
        // Build redirect URL with camps
        $camps_param = implode(',', $camp_ids);
        $redirect_url = home_url('/ptp-checkout/') . '?camps=' . $camps_param;
        if ($added_count >= 2) {
            $redirect_url .= '&multiweek=1&weeks=' . $added_count;
        }
        if ($is_early_bird) {
            $redirect_url .= '&early_bird=1';
        }
        
        wp_send_json_success(array(
            'cart_count' => ptp_cart()->get_cart_contents_count(),
            'camps_added' => $added_count,
            'camps' => $added_camps,
            'totals' => $totals,
            'redirect' => $redirect_url,
        ));
    }
    
    // =========================================================================
    // TOTALS CALCULATION
    // =========================================================================
    
    /**
     * Calculate cart totals with all discounts
     */
    public function calculate_cart_totals() {
        if (!function_exists('ptp_cart')) {
            return array(
                'subtotal' => 0,
                'discounts' => array(),
                'total' => 0,
                'items' => array(),
            );
        }
        
        $items = ptp_cart()->get_cart();
        
        $camp_items = array();
        $training_items = array();
        $addon_items = array();
        $subtotal = 0;
        $discounts = array();
        
        // Categorize items
        foreach ($items as $key => $item) {
            $item_type = $item['item_type'] ?? 'product';
            $line_total = floatval($item['line_total']);
            $subtotal += $line_total;
            
            switch ($item_type) {
                case self::TYPE_CAMP:
                    $camp_items[] = $item;
                    break;
                case self::TYPE_TRAINING:
                    $training_items[] = $item;
                    break;
                case self::TYPE_ADDON:
                    $addon_items[] = $item;
                    break;
            }
        }
        
        $camp_subtotal = array_sum(array_map(function($i) { return $i['line_total']; }, $camp_items));
        $training_subtotal = array_sum(array_map(function($i) { return $i['line_total']; }, $training_items));
        
        // Multi-camp discount
        $camp_count = count($camp_items);
        if ($camp_count >= 3) {
            $camp_discount = round($camp_subtotal * self::MULTI_CAMP_3_DISCOUNT, 2);
            $discounts[] = array(
                'type' => 'multi_camp',
                'label' => '3-Camp Pack (20% off)',
                'amount' => $camp_discount,
            );
        } elseif ($camp_count == 2) {
            $camp_discount = round($camp_subtotal * self::MULTI_CAMP_2_DISCOUNT, 2);
            $discounts[] = array(
                'type' => 'multi_camp',
                'label' => '2-Camp Pack (10% off)',
                'amount' => $camp_discount,
            );
        }
        
        // Bundle discount (camp + training)
        if (!empty($camp_items) && !empty($training_items)) {
            $bundle_subtotal = $camp_subtotal + $training_subtotal;
            $bundle_discount = round($bundle_subtotal * self::BUNDLE_DISCOUNT, 2);
            $discounts[] = array(
                'type' => 'bundle',
                'label' => 'Camp + Training Bundle (5% off)',
                'amount' => $bundle_discount,
            );
        }
        
        // Calculate final total
        $total_discounts = array_sum(array_column($discounts, 'amount'));
        $total = max(0, $subtotal - $total_discounts);
        
        return array(
            'subtotal' => $subtotal,
            'camp_subtotal' => $camp_subtotal,
            'training_subtotal' => $training_subtotal,
            'camp_count' => $camp_count,
            'training_count' => count($training_items),
            'discounts' => $discounts,
            'total_discount' => $total_discounts,
            'total' => $total,
            'has_camps' => !empty($camp_items),
            'has_training' => !empty($training_items),
            'has_bundle' => !empty($camp_items) && !empty($training_items),
        );
    }
    
    /**
     * REST: Calculate totals
     */
    public function rest_calculate_totals($request) {
        return rest_ensure_response($this->calculate_cart_totals());
    }
    
    // =========================================================================
    // STRIPE PAYMENT INTENT
    // =========================================================================
    
    /**
     * Create Stripe Payment Intent
     */
    public function ajax_create_payment_intent() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_checkout')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $result = $this->create_payment_intent($_POST);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * REST: Create Payment Intent
     */
    public function rest_create_payment_intent($request) {
        $result = $this->create_payment_intent($request->get_params());
        
        if (is_wp_error($result)) {
            return new WP_Error('payment_error', $result->get_error_message(), array('status' => 400));
        }
        
        return rest_ensure_response($result);
    }
    
    /**
     * Create Stripe Payment Intent
     */
    private function create_payment_intent($data) {
        // Get Stripe keys
        $test_mode = get_option('ptp_stripe_test_mode', true);
        $secret_key = $test_mode 
            ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
            : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
        
        if (empty($secret_key)) {
            return new WP_Error('stripe_not_configured', 'Payment system not configured');
        }
        
        // Calculate totals
        $totals = $this->calculate_cart_totals();
        
        if ($totals['total'] <= 0) {
            return new WP_Error('invalid_total', 'Invalid checkout total');
        }
        
        // Build line items description
        $items = ptp_cart()->get_cart();
        $line_items = array();
        foreach ($items as $item) {
            $name = $item['metadata']['name'] ?? 'Item';
            $line_items[] = $name;
        }
        
        // Generate checkout session ID
        $checkout_session = 'ptp_' . wp_generate_uuid4();
        
        // Customer email
        $email = sanitize_email($data['email'] ?? '');
        $first_name = sanitize_text_field($data['first_name'] ?? '');
        $last_name = sanitize_text_field($data['last_name'] ?? '');
        $phone = sanitize_text_field($data['phone'] ?? '');
        $customer_name = trim($first_name . ' ' . $last_name);
        
        // Find or create Stripe Customer
        $stripe_customer_id = '';
        if (!empty($email) && class_exists('PTP_Stripe') && method_exists('PTP_Stripe', 'find_or_create_customer_by_email')) {
            $stripe_customer_id = PTP_Stripe::find_or_create_customer_by_email($email, $customer_name, $phone);
        }
        
        // Build Payment Intent params
        $amount = intval($totals['total'] * 100); // Convert to cents
        
        $intent_params = array(
            'amount' => $amount,
            'currency' => 'usd',
            'automatic_payment_methods[enabled]' => 'true',
            'description' => 'PTP: ' . implode(', ', array_slice($line_items, 0, 3)),
            'metadata[checkout_session]' => $checkout_session,
            'metadata[camp_count]' => $totals['camp_count'],
            'metadata[training_count]' => $totals['training_count'],
            'metadata[has_bundle]' => $totals['has_bundle'] ? 'yes' : 'no',
            'metadata[items]' => implode(', ', $line_items),
        );
        
        if (!empty($stripe_customer_id)) {
            $intent_params['customer'] = $stripe_customer_id;
        }
        if (!empty($email)) {
            $intent_params['receipt_email'] = $email;
            $intent_params['metadata[customer_email]'] = $email;
        }
        if (!empty($customer_name)) {
            $intent_params['metadata[customer_name]'] = $customer_name;
        }
        
        // ── Attribution tracking: capture UTMs from cookies → PI metadata ──
        $attr = array();
        if (class_exists('PTP_Camps_Attribution')) {
            $attr = PTP_Camps_Attribution::get_attribution_from_cookies();
        } else {
            // Fallback: read attribution cookies directly
            if (!empty($_COOKIE['ptp_lt'])) {
                $attr = json_decode(urldecode($_COOKIE['ptp_lt']), true) ?: array();
            } elseif (!empty($_COOKIE['ptp_ft'])) {
                $attr = json_decode(urldecode($_COOKIE['ptp_ft']), true) ?: array();
            }
        }
        // Also check POST (checkout hidden fields)
        $utm_fields = array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term');
        foreach ($utm_fields as $uf) {
            if (empty($attr[$uf]) && !empty($data[$uf])) $attr[$uf] = sanitize_text_field($data[$uf]);
        }
        if (empty($attr['click_id'] ?? '') && empty($attr['fbclid'] ?? '') && !empty($data['click_id'])) {
            $attr['click_id'] = sanitize_text_field($data['click_id']);
        }
        if (empty($attr['landing_page'] ?? '') && !empty($data['landing_page'])) {
            $attr['landing_page'] = sanitize_text_field($data['landing_page']);
        }
        // Normalize click_id
        $click_id = $attr['click_id'] ?? ($attr['fbclid'] ?? ($attr['gclid'] ?? ($attr['msclkid'] ?? '')));
        $landing_page = $attr['landing_page'] ?? '';
        
        if (!empty($attr['utm_source']))   $intent_params['metadata[utm_source]']   = $attr['utm_source'];
        if (!empty($attr['utm_medium']))   $intent_params['metadata[utm_medium]']   = $attr['utm_medium'];
        if (!empty($attr['utm_campaign'])) $intent_params['metadata[utm_campaign]'] = $attr['utm_campaign'];
        if (!empty($attr['utm_content']))  $intent_params['metadata[utm_content]']  = $attr['utm_content'];
        if (!empty($attr['utm_term']))     $intent_params['metadata[utm_term]']     = $attr['utm_term'];
        if (!empty($click_id))             $intent_params['metadata[click_id]']     = $click_id;
        if (!empty($landing_page))         $intent_params['metadata[landing_page]'] = $landing_page;
        
        // Store attribution in session for confirm_checkout
        if (!session_id() && !headers_sent()) @session_start();
        $_SESSION['ptp_checkout_attribution'] = array(
            'utm_source'   => $attr['utm_source'] ?? '',
            'utm_medium'   => $attr['utm_medium'] ?? '',
            'utm_campaign' => $attr['utm_campaign'] ?? '',
            'utm_content'  => $attr['utm_content'] ?? '',
            'utm_term'     => $attr['utm_term'] ?? '',
            'click_id'     => $click_id,
            'landing_page' => $landing_page,
        );
        
        // Create Payment Intent via Stripe API
        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => $intent_params,
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('[PTP Stripe] API error: ' . $response->get_error_message());
            return new WP_Error('stripe_error', 'Payment service unavailable');
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!empty($body['error'])) {
            ptp_log('[PTP Stripe] Error: ' . ($body['error']['message'] ?? 'Unknown'));
            return new WP_Error('stripe_error', $body['error']['message'] ?? 'Payment failed');
        }
        
        if (empty($body['client_secret'])) {
            return new WP_Error('stripe_error', 'Invalid payment response');
        }
        
        // Store checkout data in transient
        $checkout_data = array(
            'totals' => $totals,
            'items' => $items,
            'payment_intent_id' => $body['id'],
            'created_at' => current_time('mysql'),
        );
        set_transient('ptp_checkout_' . $checkout_session, $checkout_data, 2 * HOUR_IN_SECONDS);
        
        return array(
            'client_secret' => $body['client_secret'],
            'payment_intent_id' => $body['id'],
            'checkout_session' => $checkout_session,
            'amount' => $totals['total'],
            'publishable_key' => $test_mode 
                ? get_option('ptp_stripe_test_publishable', get_option('ptp_stripe_publishable_key', ''))
                : get_option('ptp_stripe_live_publishable', get_option('ptp_stripe_publishable_key', '')),
        );
    }
    
    // =========================================================================
    // CHECKOUT CONFIRMATION
    // =========================================================================
    
    /**
     * Confirm checkout and create orders
     */
    public function ajax_confirm_checkout() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_checkout')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $checkout_session = sanitize_text_field($_POST['checkout_session'] ?? '');
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
        
        if (empty($checkout_session) || empty($payment_intent_id)) {
            wp_send_json_error(array('message' => 'Invalid checkout data'));
            return;
        }
        
        // v241: Atomic DB lock — prevents double order creation from concurrent requests
        // Two tabs, double-click, or slow network can fire ajax_confirm_checkout twice
        // The transient check below has a TOCTOU race; GET_LOCK is atomic
        global $wpdb;
        $lock_name = 'ptp_checkout_' . substr(md5($payment_intent_id), 0, 16);
        $got_lock = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lock_name));
        
        if (!$got_lock) {
            // Another request is processing this payment — wait and return success
            sleep(2);
            if (get_transient('ptp_processed_' . $payment_intent_id)) {
                wp_send_json_success(array('redirect' => home_url('/thank-you/?session=' . $checkout_session)));
            }
            wp_send_json_error(array('message' => 'Payment is being processed. Please wait.'));
            return;
        }
        
        // Check if already processed (now safe under lock)
        if (get_transient('ptp_processed_' . $payment_intent_id)) {
            $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            wp_send_json_success(array(
                'redirect' => home_url('/thank-you/?session=' . $checkout_session),
            ));
            return;
        }
        
        // Get checkout data
        $checkout_data = get_transient('ptp_checkout_' . $checkout_session);
        
        if (!$checkout_data) {
            $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            wp_send_json_error(array('message' => 'Checkout session expired'));
            return;
        }
        
        // Verify payment with Stripe
        $verified = $this->verify_payment($payment_intent_id);
        
        if (!$verified) {
            $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            wp_send_json_error(array('message' => 'Payment verification failed'));
            return;
        }
        
        // Collect customer data
        $customer_data = array(
            'parent_first_name' => sanitize_text_field($_POST['parent_first_name'] ?? ''),
            'parent_last_name' => sanitize_text_field($_POST['parent_last_name'] ?? ''),
            'parent_email' => sanitize_email($_POST['parent_email'] ?? ''),
            'parent_phone' => sanitize_text_field($_POST['parent_phone'] ?? ''),
            'camper_first_name' => sanitize_text_field($_POST['camper_first_name'] ?? ''),
            'camper_last_name' => sanitize_text_field($_POST['camper_last_name'] ?? ''),
            'camper_dob' => sanitize_text_field($_POST['camper_dob'] ?? ''),
            'camper_shirt_size' => sanitize_text_field($_POST['camper_shirt_size'] ?? ''),
            'emergency_name' => sanitize_text_field($_POST['emergency_name'] ?? ''),
            'emergency_phone' => sanitize_text_field($_POST['emergency_phone'] ?? ''),
            'waiver_accepted' => !empty($_POST['waiver_accepted']),
            // v168: Instagram photo announcement
            'announcement_photo_url' => esc_url_raw($_POST['announcement_photo_url'] ?? ''),
            'instagram_handle' => sanitize_text_field($_POST['instagram_handle'] ?? ''),
            'photo_consent' => !empty($_POST['photo_consent']),
        );
        
        // ── Recover attribution: session (set in create_payment_intent) → cookies → POST ──
        if (!session_id() && !headers_sent()) @session_start();
        $attr = $_SESSION['ptp_checkout_attribution'] ?? array();
        if (empty($attr['utm_source'])) {
            // Fallback to cookies
            if (class_exists('PTP_Camps_Attribution')) {
                $attr = PTP_Camps_Attribution::get_attribution_from_cookies();
            } elseif (!empty($_COOKIE['ptp_lt'])) {
                $attr = json_decode(urldecode($_COOKIE['ptp_lt']), true) ?: array();
            }
        }
        // Normalize
        $customer_data['utm_source']   = $attr['utm_source'] ?? '';
        $customer_data['utm_medium']   = $attr['utm_medium'] ?? '';
        $customer_data['utm_campaign'] = $attr['utm_campaign'] ?? '';
        $customer_data['utm_content']  = $attr['utm_content'] ?? '';
        $customer_data['utm_term']     = $attr['utm_term'] ?? '';
        $customer_data['click_id']     = $attr['click_id'] ?? ($attr['fbclid'] ?? ($attr['gclid'] ?? ''));
        $customer_data['landing_page'] = $attr['landing_page'] ?? '';
        
        // Create orders
        $result = $this->create_orders($checkout_data, $customer_data, $payment_intent_id);
        
        if (is_wp_error($result)) {
            $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        // v216.1: Fire proper hooks for all downstream systems
        // Training bookings: escrow, SMS, Google Calendar, Supabase, notifications
        if (!empty($result['booking_ids'])) {
            foreach ($result['booking_ids'] as $bid) {
                try {
                    // Create escrow hold for trainer payout system
                    if (class_exists('PTP_Escrow') && method_exists('PTP_Escrow', 'create_hold')) {
                        global $wpdb;
                        $booking = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d", $bid
                        ));
                        if ($booking && $booking->total_amount > 0) {
                            PTP_Escrow::create_hold($bid, $payment_intent_id, $booking->total_amount);
                        }
                    }
                    
                    // Fire booking confirmed hook — triggers SMS, Calendar, Supabase, cross-sell, etc.
                    do_action('ptp_booking_confirmed', $bid);
                } catch (\Throwable $e) {
                    ptp_log("[PTP Bulletproof] Hook error for booking $bid: " . $e->getMessage());
                }
            }
        }
        
        // Camp registrations: fire order completed for email wiring
        if (!empty($result['registration_ids'])) {
            // v216.1: Build full booking data from checkout so email has camp details
            $items = $checkout_data['items'] ?? array();
            $camp_items = array_filter($items, function($i) { return ($i['item_type'] ?? '') === self::TYPE_CAMP; });
            $camp_items = array_values($camp_items);
            
            foreach ($result['registration_ids'] as $idx => $reg_id) {
                try {
                    $item = $camp_items[$idx] ?? ($camp_items[0] ?? array());
                    $metadata = $item['metadata'] ?? array();
                    
                    do_action('ptp_camp_order_completed', $reg_id, array(
                        'customer_email'    => $customer_data['parent_email'],
                        'customer_name'     => $customer_data['parent_first_name'] . ' ' . $customer_data['parent_last_name'],
                        'customer_phone'    => $customer_data['parent_phone'],
                        'camper_name'       => $customer_data['camper_first_name'] . ' ' . $customer_data['camper_last_name'],
                        'camper_dob'        => $customer_data['camper_dob'] ?? '',
                        'camper_shirt'      => $customer_data['camper_shirt_size'] ?? '',
                        'camp_id'           => intval($item['item_id'] ?? 0),
                        'camp_name'         => $metadata['name'] ?? '',
                        'camp_date'         => $metadata['date'] ?? '',
                        'camp_location'     => $metadata['location'] ?? '',
                        'camp_time'         => $metadata['time'] ?? '9AM - 3PM',
                        'amount_paid'       => floatval($item['line_total'] ?? 0),
                        'base_price'        => floatval($item['price'] ?? $item['line_total'] ?? 0),
                        'stripe_payment_id' => $payment_intent_id,
                        'emergency_contact' => $customer_data['emergency_name'] ?? '',
                        'emergency_phone'   => $customer_data['emergency_phone'] ?? '',
                        'status'            => 'confirmed',
                        // Attribution for CAC tracking
                        'utm_source'        => $customer_data['utm_source'] ?? '',
                        'utm_medium'        => $customer_data['utm_medium'] ?? '',
                        'utm_campaign'      => $customer_data['utm_campaign'] ?? '',
                        'utm_content'       => $customer_data['utm_content'] ?? '',
                        'utm_term'          => $customer_data['utm_term'] ?? '',
                        'click_id'          => $customer_data['click_id'] ?? '',
                        'landing_page'      => $customer_data['landing_page'] ?? '',
                    ));
                } catch (\Throwable $e) {
                    ptp_log("[PTP Bulletproof] Camp hook error for reg $reg_id: " . $e->getMessage());
                }
            }
        }
        
        // Mark as processed
        set_transient('ptp_processed_' . $payment_intent_id, true, DAY_IN_SECONDS);
        
        // v241: Release DB lock
        $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        
        // Clear cart
        ptp_cart()->empty_cart();
        
        // Store order info for thank you page
        set_transient('ptp_order_' . $checkout_session, $result, HOUR_IN_SECONDS);
        
        wp_send_json_success(array(
            'order_id' => $result['order_id'] ?? null,
            'booking_ids' => $result['booking_ids'] ?? array(),
            'redirect' => home_url('/thank-you/?session=' . $checkout_session),
        ));
    }
    
    /**
     * Verify payment with Stripe
     */
    private function verify_payment($payment_intent_id) {
        $test_mode = get_option('ptp_stripe_test_mode', true);
        $secret_key = $test_mode 
            ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
            : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
        
        $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('[PTP Verify] API error: ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return isset($body['status']) && $body['status'] === 'succeeded';
    }
    
    /**
     * Create orders from checkout
     */
    private function create_orders($checkout_data, $customer_data, $payment_intent_id) {
        global $wpdb;
        
        $result = array(
            'order_id' => null,
            'booking_ids' => array(),
            'registration_ids' => array(),
        );
        
        $items = $checkout_data['items'] ?? array();
        $totals = $checkout_data['totals'] ?? array();
        
        // Get or create parent record
        $parent_id = $this->get_or_create_parent($customer_data);
        
        // Get or create player record
        $player_id = $this->get_or_create_player($customer_data, $parent_id);
        
        // Process each item
        foreach ($items as $item) {
            $item_type = $item['item_type'] ?? 'product';
            
            switch ($item_type) {
                case self::TYPE_CAMP:
                    // Create camp registration
                    $reg_id = $this->create_camp_registration($item, $customer_data, $parent_id, $player_id, $payment_intent_id);
                    if ($reg_id) {
                        $result['registration_ids'][] = $reg_id;
                    }
                    break;
                    
                case self::TYPE_TRAINING:
                    // Create training booking
                    $booking_id = $this->create_training_booking($item, $customer_data, $parent_id, $player_id, $payment_intent_id);
                    if ($booking_id) {
                        $result['booking_ids'][] = $booking_id;
                    }
                    break;
            }
        }
        
        // Create unified order record
        $order_id = $this->create_order_record($checkout_data, $customer_data, $payment_intent_id, $result);
        $result['order_id'] = $order_id;
        
        // v241: Create Instagram announcement row if parent uploaded photo + gave consent
        $this->maybe_create_instagram_announcement($customer_data, $checkout_data, $order_id, $result);
        
        // Send confirmation emails
        $this->send_confirmation_emails($result, $customer_data, $checkout_data);
        
        return $result;
    }
    
    /**
     * Get or create parent record
     */
    private function get_or_create_parent($customer_data) {
        global $wpdb;
        
        $email = $customer_data['parent_email'];
        
        // Check existing
        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE email = %s",
            $email
        ));
        
        if ($parent_id) {
            return $parent_id;
        }
        
        // Create new
        $wpdb->insert(
            $wpdb->prefix . 'ptp_parents',
            array(
                'first_name' => $customer_data['parent_first_name'],
                'last_name' => $customer_data['parent_last_name'],
                'email' => $email,
                'phone' => $customer_data['parent_phone'],
                'emergency_contact_name' => $customer_data['emergency_name'],
                'emergency_contact_phone' => $customer_data['emergency_phone'],
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get or create player record
     */
    private function get_or_create_player($customer_data, $parent_id) {
        global $wpdb;
        
        $first_name = $customer_data['camper_first_name'];
        $last_name = $customer_data['camper_last_name'];
        
        // Check existing
        $player_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d AND first_name = %s AND last_name = %s",
            $parent_id, $first_name, $last_name
        ));
        
        if ($player_id) {
            return $player_id;
        }
        
        // Create new
        $wpdb->insert(
            $wpdb->prefix . 'ptp_players',
            array(
                'parent_id' => $parent_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'date_of_birth' => $customer_data['camper_dob'],
                'shirt_size' => $customer_data['camper_shirt_size'],
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Create camp registration
     */
    private function create_camp_registration($item, $customer_data, $parent_id, $player_id, $payment_intent_id) {
        global $wpdb;
        
        // v241: Check if registration already exists for this payment
        $existing_reg = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_camp_registrations WHERE stripe_payment_intent_id = %s AND camp_product_id = %d LIMIT 1",
            $payment_intent_id, intval($item['item_id'])
        ));
        if ($existing_reg) {
            ptp_log('[PTP v241] Camp registration already exists for PI ' . $payment_intent_id . ' — returning #' . $existing_reg);
            return (int) $existing_reg;
        }
        
        $metadata = $item['metadata'] ?? array();
        
        $insert_data = array(
            'camp_product_id' => $item['item_id'],
            'player_id' => $player_id,
            'parent_id' => $parent_id,
            'camp_name' => $metadata['name'] ?? 'Camp',
            'camp_date' => $metadata['date'] ?? '',
            'location' => $metadata['location'] ?? '',
            'price_paid' => $item['line_total'],
            'stripe_payment_intent_id' => $payment_intent_id,
            'status' => 'confirmed',
            'waiver_accepted' => $customer_data['waiver_accepted'] ? 1 : 0,
            'created_at' => current_time('mysql'),
        );
        $insert_formats = array('%d', '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s');
        
        // v241: Save Instagram photo announcement data to camp registration
        $ig_photo = $customer_data['announcement_photo_url'] ?? '';
        $ig_handle = $customer_data['instagram_handle'] ?? '';
        $ig_consent = !empty($customer_data['photo_consent']) && !empty($ig_photo);
        
        // Check if columns exist before inserting (they may not on older installs)
        $table_name = $wpdb->prefix . 'ptp_camp_registrations';
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}", 0);
        
        if (in_array('announcement_photo_url', $cols)) {
            $insert_data['announcement_photo_url'] = esc_url_raw($ig_photo);
            $insert_formats[] = '%s';
        }
        if (in_array('instagram_handle', $cols)) {
            $insert_data['instagram_handle'] = sanitize_text_field($ig_handle);
            $insert_formats[] = '%s';
        }
        if (in_array('photo_consent', $cols)) {
            $insert_data['photo_consent'] = $ig_consent ? 1 : 0;
            $insert_formats[] = '%d';
        }
        
        $wpdb->insert($table_name, $insert_data, $insert_formats);
        
        return $wpdb->insert_id;
    }
    
    /**
     * Create training booking
     */
    private function create_training_booking($item, $customer_data, $parent_id, $player_id, $payment_intent_id) {
        global $wpdb;
        
        // v241: Check if booking already exists for this payment (webhook + client-side race)
        $existing_booking = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s LIMIT 1",
            $payment_intent_id
        ));
        if ($existing_booking) {
            ptp_log('[PTP v241] Booking already exists for PI ' . $payment_intent_id . ' — returning #' . $existing_booking);
            return (int) $existing_booking;
        }
        
        $metadata = $item['metadata'] ?? array();
        $trainer_id = $metadata['trainer_id'] ?? $item['item_id'];
        $total_amount = floatval($item['line_total'] ?? 0);
        
        // v222: Prefer raw date/time over display format, then normalize
        $raw_date = $metadata['date'] ?? $metadata['session_date'] ?? '';
        $raw_time = $metadata['time'] ?? $metadata['session_time'] ?? '';
        if (function_exists('ptp_normalize_session_date')) {
            $raw_date = ptp_normalize_session_date($raw_date) ?: $raw_date;
        }
        if (function_exists('ptp_normalize_session_time')) {
            $raw_time = ptp_normalize_session_time($raw_time) ?: $raw_time;
        }
        
        // v216.1: Calculate proper platform fee and trainer payout
        $platform_fee_rate = function_exists('ptp_get_platform_fee') ? ptp_get_platform_fee() : 0.15;
        $platform_fee = round($total_amount * $platform_fee_rate, 2);
        $trainer_payout = $total_amount - $platform_fee;
        
        // Get trainer hourly rate for reference
        $hourly_rate = 70;
        if (class_exists('PTP_Trainer')) {
            $trainer = PTP_Trainer::get($trainer_id);
            if ($trainer) {
                $hourly_rate = floatval($trainer->hourly_rate ?: 70);
            }
        }
        
        // v231: Double-booking prevention
        if (!empty($raw_date) && $raw_date !== '0000-00-00' && !empty($raw_time) && $raw_time !== '00:00:00') {
            $conflict = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d AND session_date = %s AND start_time = %s 
                 AND status NOT IN ('cancelled', 'refunded') LIMIT 1",
                $trainer_id, $raw_date, $raw_time
            ));
            if ($conflict) {
                ptp_log(sprintf('[PTP v231 Bulletproof] DOUBLE BOOKING BLOCKED: trainer=%d date=%s time=%s conflicts with #%d',
                    $trainer_id, $raw_date, $raw_time, $conflict));
                do_action('ptp_double_booking_prevented', array('trainer_id' => $trainer_id, 'date' => $raw_date, 'time' => $raw_time), $conflict);
                return null;
            }
        }
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'booking_number' => 'PTP-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8)),
                'trainer_id' => $trainer_id,
                'player_id' => $player_id,
                'parent_id' => $parent_id,
                'session_date' => $raw_date,
                'start_time' => $raw_time,
                'location' => $metadata['location'] ?? '',
                'total_amount' => $total_amount,
                'hourly_rate' => $hourly_rate,
                'platform_fee' => $platform_fee,
                'trainer_payout' => $trainer_payout,
                'payment_intent_id' => $payment_intent_id,
                'payment_status' => 'paid',
                'status' => 'confirmed',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s')
        );
        
        $booking_id = $wpdb->insert_id;
        
        // Notify trainer
        $this->notify_trainer($trainer_id, $booking_id, $customer_data);
        
        return $booking_id;
    }
    
    /**
     * v241: Create Instagram announcement if parent uploaded photo + gave consent
     * Writes to ptp_social_announcements (the table with admin UI in PTP_Social_Announcement)
     */
    private function maybe_create_instagram_announcement($customer_data, $checkout_data, $order_id, $result) {
        $photo_url = $customer_data['announcement_photo_url'] ?? '';
        $ig_handle = $customer_data['instagram_handle'] ?? '';
        $consent = !empty($customer_data['photo_consent']);
        
        // Need at minimum: consent + either photo or IG handle
        if (!$consent || (empty($photo_url) && empty($ig_handle))) {
            return;
        }
        
        // Clean handle
        $ig_handle = ltrim(trim($ig_handle), '@');
        if ($ig_handle) $ig_handle = '@' . preg_replace('/[^a-zA-Z0-9._]/', '', $ig_handle);
        
        // Build camper name
        $camper_name = trim(($customer_data['camper_first_name'] ?? '') . ' ' . ($customer_data['camper_last_name'] ?? ''));
        if (!$camper_name) $camper_name = 'Camper';
        
        // Get camp info from checkout items
        $camp_name = '';
        $camp_location = '';
        $camp_dates = '';
        foreach (($checkout_data['items'] ?? array()) as $item) {
            if (($item['item_type'] ?? '') === 'camp') {
                $meta = $item['metadata'] ?? array();
                $camp_name = $meta['name'] ?? ($meta['camp_name'] ?? '');
                $camp_location = $meta['location'] ?? ($meta['camp_location'] ?? '');
                $camp_dates = $meta['date'] ?? ($meta['camp_dates'] ?? '');
                break; // Use first camp
            }
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_social_announcements';
        
        // Make sure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            // Try to create it via the Social Announcement class
            if (class_exists('PTP_Social_Announcement')) {
                PTP_Social_Announcement::instance()->maybe_create_tables();
            }
            // If still doesn't exist, bail
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                ptp_log('[PTP v241] ptp_social_announcements table does not exist, skipping announcement creation');
                return;
            }
        }
        
        // Check for duplicate (same order)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE order_id = %d LIMIT 1",
            $order_id
        ));
        if ($existing) {
            ptp_log('[PTP v241] Announcement already exists for order #' . $order_id);
            return;
        }
        
        $inserted = $wpdb->insert($table, array(
            'order_id'         => $order_id,
            'booking_id'       => !empty($result['booking_ids']) ? $result['booking_ids'][0] : null,
            'instagram_handle' => $ig_handle,
            'camper_name'      => $camper_name,
            'camp_name'        => $camp_name,
            'camp_location'    => $camp_location,
            'camp_dates'       => $camp_dates,
            'parent_email'     => $customer_data['parent_email'] ?? '',
            'photo_url'        => esc_url_raw($photo_url),
            'status'           => 'pending',
            'created_at'       => current_time('mysql'),
        ), array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
        
        if ($inserted) {
            $announcement_id = $wpdb->insert_id;
            ptp_log(sprintf(
                '[PTP v241] Instagram announcement #%d created — Camper: %s, IG: %s, Photo: %s, Order: #%d',
                $announcement_id, $camper_name, $ig_handle, $photo_url ? 'yes' : 'no', $order_id
            ));
            
            // Send admin notification
            $admin_email = get_option('admin_email');
            $subject = '[PTP] New Instagram Feature Request — ' . $camper_name;
            $message = "New Instagram announcement from checkout:\n\n";
            $message .= "Camper: {$camper_name}\n";
            $message .= "Camp: " . ($camp_name ?: 'N/A') . "\n";
            $message .= "Location: " . ($camp_location ?: 'N/A') . "\n";
            $message .= "Dates: " . ($camp_dates ?: 'N/A') . "\n";
            $message .= "Instagram: {$ig_handle}\n";
            $message .= "Photo: " . ($photo_url ?: 'None uploaded') . "\n";
            $message .= "Order: #{$order_id}\n\n";
            $message .= "View all pending announcements in WP Admin > PTP > Instagram Announcements";
            wp_mail($admin_email, $subject, $message);
        } else {
            ptp_log('[PTP v241] Failed to insert announcement for order #' . $order_id . ': ' . $wpdb->last_error);
        }
    }
    
    /**
     * Create order record
     */
    private function create_order_record($checkout_data, $customer_data, $payment_intent_id, $result) {
        global $wpdb;
        
        $totals = $checkout_data['totals'] ?? array();
        
        $order_data = array(
            'customer_email' => $customer_data['parent_email'],
            'customer_name' => $customer_data['parent_first_name'] . ' ' . $customer_data['parent_last_name'],
            'subtotal' => $totals['subtotal'] ?? 0,
            'discount' => $totals['total_discount'] ?? 0,
            'total' => $totals['total'] ?? 0,
            'payment_method' => 'stripe',
            'payment_intent_id' => $payment_intent_id,
            'status' => 'completed',
            'camp_registration_ids' => implode(',', $result['registration_ids'] ?? array()),
            'booking_ids' => implode(',', $result['booking_ids'] ?? array()),
            'created_at' => current_time('mysql'),
        );
        $formats = array('%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s');
        
        // Add UTM columns if table supports them
        $table = $wpdb->prefix . 'ptp_orders';
        $cols = $wpdb->get_col("DESCRIBE {$table}", 0);
        if (is_array($cols) && in_array('utm_source', $cols)) {
            $order_data['utm_source']   = $customer_data['utm_source'] ?? '';
            $order_data['utm_medium']   = $customer_data['utm_medium'] ?? '';
            $order_data['utm_campaign'] = $customer_data['utm_campaign'] ?? '';
            $order_data['utm_content']  = $customer_data['utm_content'] ?? '';
            $order_data['click_id']     = $customer_data['click_id'] ?? '';
            $order_data['landing_page'] = $customer_data['landing_page'] ?? '';
            $formats = array_merge($formats, array('%s', '%s', '%s', '%s', '%s', '%s'));
        }
        
        $wpdb->insert($table, $order_data, $formats);
        
        return $wpdb->insert_id;
    }
    
    /**
     * Send confirmation emails
     */
    private function send_confirmation_emails($result, $customer_data, $checkout_data) {
        $to = $customer_data['parent_email'];
        
        // v216.1: Delegate to proper email system instead of plain-text
        // Training bookings → PTP_Notifications for in-app only; emails handled by hook
        if (!empty($result['booking_ids'])) {
            foreach ($result['booking_ids'] as $bid) {
                try {
                    // v136: Removed PTP_Email fallback - emails now handled exclusively via hooks
                    if (class_exists('PTP_Notifications') && method_exists('PTP_Notifications', 'booking_created')) {
                        PTP_Notifications::booking_created($bid);
                    }
                } catch (\Throwable $e) {
                    ptp_log("[PTP Bulletproof] Notification error for booking $bid: " . $e->getMessage());
                }
            }
        }
        
        // Camp registrations → handled via ptp_camp_order_completed hook in ajax_confirm_checkout
        // v216.1: Removed plain-text fallback here. The hook fires after create_orders() returns
        // and triggers send_camp_booking_confirmation_direct() with full camp details.
        // Sending here too would cause parents to receive 2 emails (plain-text + branded HTML).
    }
    
    /**
     * Notify trainer of new booking
     */
    private function notify_trainer($trainer_id, $booking_id, $customer_data) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer || empty($trainer->email)) {
            return;
        }
        
        // v228: Default to skip — ptp_booking_confirmed hook sends branded HTML emails.
        // Plain-text here was causing trainers to receive 2 emails.
        if (apply_filters('ptp_bulletproof_skip_plain_notify', true)) {
            ptp_log('[PTP Bulletproof v228] Skipping plain-text trainer notify — hook chain handles emails');
            return;
        }
        
        $subject = 'New Training Session Booked!';
        $message = "You have a new training session!\n\n";
        $message .= "Player: " . $customer_data['camper_first_name'] . " " . $customer_data['camper_last_name'] . "\n";
        $message .= "Parent: " . $customer_data['parent_first_name'] . " " . $customer_data['parent_last_name'] . "\n";
        $message .= "Phone: " . $customer_data['parent_phone'] . "\n\n";
        $message .= "Log in to your dashboard to see details and confirm the session.";
        
        wp_mail($trainer->email, $subject, $message, array(
            'From: ' . ptp_email_brand('from_training'),
        ));
    }
}

// Initialize
PTP_Bulletproof_Checkout::instance();

/**
 * Helper function to get checkout instance
 */
function ptp_checkout() {
    return PTP_Bulletproof_Checkout::instance();
}
