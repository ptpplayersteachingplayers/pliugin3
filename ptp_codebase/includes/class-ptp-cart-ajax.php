<?php
/**
 * PTP Cart AJAX Handlers
 * 
 * Handles all cart operations for both camps and trainings:
 * - Remove items from cart
 * - Update quantities
 * - Clear cart
 * - Add camps from URL parameters
 * - Add training from URL parameters
 * - Processing fees
 * - Early bird discounts
 * 
 * @package PTP_Training_Platform
 * @version 158.2.0 - Improved PTP_Camps_Cart sync
 */

if (!defined('ABSPATH')) exit;

class PTP_Cart_Ajax {
    
    private static $instance = null;
    
    // Processing fee percentage (3% + $0.30)
    private $processing_fee_percent = 3.0;
    private $processing_fee_fixed = 0.30;
    
    // Early bird discount settings
    private $early_bird_per_camp = 50;
    private $early_bird_enabled = false;
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Get settings (3% + $0.30 by default)
        $this->processing_fee_percent = apply_filters('ptp_processing_fee_percent', 3.0);
        $this->processing_fee_fixed = apply_filters('ptp_processing_fee_fixed', 0.30);
        $this->early_bird_per_camp = apply_filters('ptp_early_bird_per_camp', 50);
        $this->early_bird_enabled = apply_filters('ptp_early_bird_enabled', false);
        
        // Cart item operations - use priority 5 to run before other handlers
        add_action('wp_ajax_ptp_remove_cart_item', array($this, 'remove_cart_item'), 5);
        add_action('wp_ajax_nopriv_ptp_remove_cart_item', array($this, 'remove_cart_item'), 5);
        
        add_action('wp_ajax_ptp_update_cart_quantity', array($this, 'update_cart_quantity'));
        add_action('wp_ajax_nopriv_ptp_update_cart_quantity', array($this, 'update_cart_quantity'));
        
        add_action('wp_ajax_ptp_clear_cart', array($this, 'clear_cart'));
        add_action('wp_ajax_nopriv_ptp_clear_cart', array($this, 'clear_cart'));
        
        // v136: Deferred PaymentIntent creation (called on checkout click, not page load)
        add_action('wp_ajax_ptp_cart_create_pi', array($this, 'ajax_cart_create_pi'));
        add_action('wp_ajax_nopriv_ptp_cart_create_pi', array($this, 'ajax_cart_create_pi'));
        
        // Legacy handlers (redirect to unified handler)
        add_action('wp_ajax_ptp_camps_remove_from_cart', array($this, 'legacy_camps_remove'));
        add_action('wp_ajax_nopriv_ptp_camps_remove_from_cart', array($this, 'legacy_camps_remove'));
        
        add_action('wp_ajax_ptp_remove_training_from_cart', array($this, 'legacy_training_remove'));
        add_action('wp_ajax_nopriv_ptp_remove_training_from_cart', array($this, 'legacy_training_remove'));
        
        // URL parameter handling - run on cart AND checkout pages
        add_action('template_redirect', array($this, 'handle_url_params'), 5);
        
        // Multi-week discount calculation
        add_filter('ptp_cart_calculate_fees', array($this, 'apply_multiweek_discount'));
        
        // Processing fee calculation
        add_filter('ptp_cart_calculate_fees', array($this, 'apply_processing_fee'), 20);
        
        // Early bird discount
        add_filter('ptp_cart_calculate_fees', array($this, 'apply_early_bird_discount'), 5);
    }
    
    /**
     * Remove cart item (works for both camps and trainings)
     * v156: Enhanced with multiple fallback strategies and immediate DB sync
     */
    public function remove_cart_item() {
        try {
            // Verify nonce - accept multiple nonce types for compatibility
            $nonce = sanitize_text_field($_POST['nonce'] ?? '');
            
            // Debug logging
            ptp_log('[PTP Cart Ajax v156] remove_cart_item called');
            ptp_log('[PTP Cart Ajax v156] nonce: ' . substr($nonce, 0, 10) . '...');
            ptp_log('[PTP Cart Ajax v156] cart_key: ' . ($_POST['cart_key'] ?? $_POST['item_key'] ?? 'empty'));
            
            $nonce_valid = wp_verify_nonce($nonce, 'ptp_cart_action') || 
                           wp_verify_nonce($nonce, 'ptp_camps_nonce') ||
                           wp_verify_nonce($nonce, 'ptp_nonce') ||
                           wp_verify_nonce($nonce, 'ptp_pack_nonce') ||
                           wp_verify_nonce($nonce, 'ptp_ajax_nonce') ||
                           wp_verify_nonce($nonce, 'ptp_cart') ||
                           wp_verify_nonce($nonce, 'ptp_camps_cart');
            
            if (!$nonce_valid) {
                ptp_log('[PTP Cart Ajax v156] Nonce verification failed');
                wp_send_json_error(array('message' => 'Security check failed - please refresh the page'));
                return;
            }
            
            $cart_key = sanitize_text_field($_POST['cart_key'] ?? $_POST['item_key'] ?? '');
            
            if (!$cart_key) {
                ptp_log('[PTP Cart Ajax v156] No cart key provided');
                wp_send_json_error(array('message' => 'No item key provided'));
                return;
            }
            
            // Get native cart
            if (!function_exists('ptp_cart') || !ptp_cart()) {
                ptp_log('[PTP Cart Ajax v156] ptp_cart() not available');
                wp_send_json_error(array('message' => 'Cart not available'));
                return;
            }
            
            $cart = ptp_cart();
            
            // Force load cart (important for AJAX context)
            $cart->load_cart();
            
            $current_items = $cart->get_cart();
            ptp_log('[PTP Cart Ajax v156] Current cart keys: ' . implode(', ', array_keys($current_items)));
            ptp_log('[PTP Cart Ajax v156] Attempting to remove: ' . $cart_key);
            
            // Try native cart removal (now has multiple fallback strategies)
            // Get item info BEFORE removal for legacy cart matching
            $removed_item = null;
            $native_items = $cart->get_cart();
            if (isset($native_items[$cart_key])) {
                $removed_item = $native_items[$cart_key];
            } else {
                // Try fuzzy match for item info
                foreach ($native_items as $nk => $ni) {
                    if (strpos($nk, $cart_key) === 0 || strpos($cart_key, $nk) === 0) {
                        $removed_item = $ni;
                        break;
                    }
                }
            }

            $result = $cart->remove_cart_item($cart_key);
            $removed_from_legacy = false;
            
            // Also try to remove from PTP_Camps_Cart (legacy cart system)
            if (class_exists('PTP_Camps_Cart')) {
                try {
                    // Try direct key match first
                    if (method_exists('PTP_Camps_Cart', 'remove')) {
                        $legacy_removed = PTP_Camps_Cart::remove($cart_key);
                        if ($legacy_removed) {
                            $removed_from_legacy = true;
                            ptp_log('[PTP Cart Ajax v158.2] Removed from PTP_Camps_Cart by key');
                        }
                    }
                    
                    // If not found by key, try matching by camp_id
                    if (!$removed_from_legacy && $removed_item && ($removed_item['item_type'] ?? '') === 'camp') {
                        $camp_id = $removed_item['item_id'] ?? ($removed_item['metadata']['camp_id'] ?? 0);
                        if ($camp_id && method_exists('PTP_Camps_Cart', 'get')) {
                            $camps_cart = PTP_Camps_Cart::get();
                            foreach ($camps_cart as $camps_key => $camps_item) {
                                if (($camps_item['camp_id'] ?? 0) == $camp_id) {
                                    PTP_Camps_Cart::remove($camps_key);
                                    $removed_from_legacy = true;
                                    ptp_log('[PTP Cart Ajax v158.2] Removed from PTP_Camps_Cart by camp_id match');
                                    break;
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    ptp_log('[PTP Cart Ajax v158.2] Legacy cart error: ' . $e->getMessage());
                }
            }
            
            // Try removing from session-based training cart
            if (function_exists('ptp_session')) {
                try {
                    $session = ptp_session();
                    if ($session) {
                        $training_cart = $session->get('ptp_training_cart', array());
                        $session_changed = false;
                        
                        // Try exact key match
                        if (isset($training_cart[$cart_key])) {
                            unset($training_cart[$cart_key]);
                            $session_changed = true;
                            ptp_log('[PTP Cart Ajax v175.2] Removed from session training cart by key');
                        }
                        
                        // v175.2: Also try matching by trainer_id for training items
                        if (!$session_changed && strpos($cart_key, 'training_') === 0) {
                            $trainer_id_from_key = intval(str_replace('training_', '', explode('_', $cart_key)[1] ?? $cart_key));
                            if ($trainer_id_from_key) {
                                foreach ($training_cart as $sess_key => $sess_item) {
                                    if (($sess_item['trainer_id'] ?? 0) == $trainer_id_from_key) {
                                        unset($training_cart[$sess_key]);
                                        $session_changed = true;
                                        ptp_log('[PTP Cart Ajax v175.2] Removed from session training cart by trainer_id match');
                                        break;
                                    }
                                }
                            }
                        }
                        
                        if ($session_changed) {
                            $session->set('ptp_training_cart', $training_cart);
                            $removed_from_legacy = true;
                        }
                        
                        // Also clean up ptp99_camps_in_cart session
                        if ($removed_item && ($removed_item['item_type'] ?? '') === 'camp') {
                            $camp_id = $removed_item['item_id'] ?? 0;
                            $camps_in_cart = $session->get('ptp99_camps_in_cart', array());
                            $camps_in_cart = array_filter($camps_in_cart, function($id) use ($camp_id) {
                                return $id != $camp_id;
                            });
                            $session->set('ptp99_camps_in_cart', array_values($camps_in_cart));
                        }
                    }
                } catch (\Throwable $e) {
                    ptp_log('[PTP Cart Ajax v175.2] Session cart error: ' . $e->getMessage());
                }
            }
            
            // Invalidate cart helper cache
            if (class_exists('PTP_Cart_Helper') && method_exists('PTP_Cart_Helper', 'invalidate_cart_cache')) {
                PTP_Cart_Helper::invalidate_cart_cache();
            }
            
            if ($result || $removed_from_legacy) {
                ptp_log('[PTP Cart Ajax v158.2] Item removed successfully');
                
                // CRITICAL: Force save cart immediately (shutdown hook won't run after wp_send_json)
                $cart->save_cart();
                
                // Get cart count and total safely
                $cart_count = 0;
                $cart_total_formatted = '$0.00';
                try {
                    $cart_count = $cart->get_cart_contents_count();
                    $raw_total = $cart->get_total('edit');
                    $cart_total_formatted = '$' . number_format(floatval($raw_total), 2);
                } catch (\Throwable $e) {
                    ptp_log('[PTP Cart Ajax v156] Error getting cart count/total: ' . $e->getMessage());
                }
                
                wp_send_json_success(array(
                    'message' => 'Item removed',
                    'cart_count' => $cart_count,
                    'cart_total' => $cart_total_formatted
                ));
            } else {
                ptp_log('[PTP Cart Ajax v156] Item not found in cart - key: ' . $cart_key);
                
                // Check current cart state
                $current_count = 0;
                try {
                    $current_count = $cart->get_cart_contents_count();
                } catch (\Throwable $e) {
                    // ignore
                }
                
                // If the cart key looks valid (starts with known prefix), 
                // return success anyway - item might have already been removed
                if (strpos($cart_key, 'camp_') === 0 || 
                    strpos($cart_key, 'training_') === 0 ||
                    strpos($cart_key, 'package_') === 0 ||
                    strpos($cart_key, 'addon_') === 0) {
                    
                    $fallback_total = '$0.00';
                    try {
                        $raw = $cart->get_total('edit');
                        $fallback_total = '$' . number_format(floatval($raw), 2);
                    } catch (\Throwable $e) { /* ignore */ }
                    
                    // Return success to allow UI to refresh
                    $cart->save_cart(); // Force save in case something changed
                    wp_send_json_success(array(
                        'message' => 'Item removed',
                        'cart_count' => $current_count,
                        'cart_total' => $fallback_total,
                        'note' => 'Item may have been previously removed'
                    ));
                } else {
                    wp_send_json_error(array('message' => 'Item not found in cart'));
                }
            }
        } catch (\Throwable $e) {
            ptp_log('[PTP Cart Ajax] FATAL in remove_cart_item: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            ptp_log('[PTP Cart Ajax] Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(array(
                'message' => 'An error occurred while removing the item. Please refresh the page and try again.',
                'debug' => defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : null
            ));
        }
    }
    
    /**
     * Legacy handler for camps (redirects to unified handler)
     */
    public function legacy_camps_remove() {
        if (isset($_POST['item_key']) && !isset($_POST['cart_key'])) {
            $_POST['cart_key'] = $_POST['item_key'];
        }
        $this->remove_cart_item();
    }
    
    /**
     * Legacy handler for training (redirects to unified handler)
     */
    public function legacy_training_remove() {
        if (isset($_POST['training_key']) && !isset($_POST['cart_key'])) {
            $_POST['cart_key'] = $_POST['training_key'];
        }
        $this->remove_cart_item();
    }
    
    /**
     * Update cart item quantity
     */
    public function update_cart_quantity() {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'ptp_cart_action') && !wp_verify_nonce($nonce, 'ptp_camps_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');
        $quantity = intval($_POST['quantity'] ?? 1);
        
        if (!$cart_key) {
            wp_send_json_error(array('message' => 'No item key provided'));
            return;
        }
        
        if (!function_exists('ptp_cart') || !ptp_cart()) {
            wp_send_json_error(array('message' => 'Cart not available'));
            return;
        }
        
        if ($quantity <= 0) {
            $result = ptp_cart()->remove_cart_item($cart_key);
        } else {
            $result = ptp_cart()->set_quantity($cart_key, $quantity);
        }
        
        if ($result) {
            wp_send_json_success(array(
                'message' => 'Quantity updated',
                'cart_count' => ptp_cart()->get_cart_contents_count(),
                'cart_total' => ptp_cart()->get_total()
            ));
        } else {
            wp_send_json_error(array('message' => 'Could not update quantity'));
        }
    }
    
    /**
     * Clear entire cart
     */
    public function clear_cart() {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'ptp_cart_action') && !wp_verify_nonce($nonce, 'ptp_camps_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!function_exists('ptp_cart') || !ptp_cart()) {
            wp_send_json_error(array('message' => 'Cart not available'));
            return;
        }
        
        ptp_cart()->empty_cart(true);
        
        // Also clear legacy carts
        if (class_exists('PTP_Camps_Cart')) {
            PTP_Camps_Cart::clear_cart();
        }
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::invalidate_cart_cache();
        }
        
        wp_send_json_success(array('message' => 'Cart cleared'));
    }
    
    /**
     * Handle URL parameters - add camps/training to cart
     * Runs on cart AND checkout pages
     */
    public function handle_url_params() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Run on cart or checkout pages
        $is_cart_page = strpos($uri, 'ptp-cart') !== false || strpos($uri, '/cart') !== false;
        $is_checkout_page = strpos($uri, 'checkout') !== false || strpos($uri, 'ptp-checkout') !== false;
        
        if (!$is_cart_page && !$is_checkout_page) {
            return;
        }
        
        ptp_log('[PTP Cart Ajax v157] handle_url_params running on: ' . $uri);
        
        // Ensure cart is available
        if (!function_exists('ptp_cart') || !ptp_cart()) {
            ptp_log('[PTP Cart Ajax v157] ptp_cart not available');
            return;
        }
        
        // Start session if needed
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        // Handle camps parameter
        $this->handle_camps_param();
        
        // Handle training parameters
        $this->handle_training_params();
    }
    
    /**
     * Handle camps URL parameter: ?camps=1,2,3
     */
    private function handle_camps_param() {
        $camps_param = isset($_GET['camps']) ? sanitize_text_field($_GET['camps']) : '';
        if (empty($camps_param)) {
            return;
        }
        
        // v168.1: Check if early_bird is in URL (set by multiweek handler)
        $early_bird_from_url = !empty($_GET['early_bird']);
        
        // Parse camp IDs
        $camp_ids = array_filter(array_map('intval', explode(',', $camps_param)));
        if (empty($camp_ids)) {
            return;
        }
        
        // Check if we already processed this (avoid duplicate adds on refresh)
        $processed_key = 'ptp_camps_processed_' . md5($camps_param);
        if (isset($_SESSION[$processed_key])) {
            return;
        }
        
        // v168.1: Check if cart already has these camps with early_bird metadata
        // This happens when coming from AJAX handler redirect - don't clear and re-add
        $cart = ptp_cart();
        $existing_items = $cart->get_cart();
        $existing_camp_ids = array();
        $has_early_bird_camps = false;
        
        foreach ($existing_items as $item) {
            if (($item['item_type'] ?? '') === 'camp') {
                $existing_camp_ids[] = (int) $item['item_id'];
                if (!empty($item['metadata']['early_bird'])) {
                    $has_early_bird_camps = true;
                }
            }
        }
        
        // If cart already has these exact camps with early_bird flag, skip re-adding
        sort($camp_ids);
        sort($existing_camp_ids);
        if ($camp_ids === $existing_camp_ids && $has_early_bird_camps) {
            ptp_log('[PTP Cart Ajax v168.1] Skipping re-add - camps already in cart from AJAX handler');
            $_SESSION[$processed_key] = true;
            return;
        }
        
        // Clear existing camp items for fresh selection
        foreach ($existing_items as $key => $item) {
            if (($item['item_type'] ?? '') === 'camp') {
                $cart->remove_cart_item($key);
            }
        }
        
        // Add each camp to cart
        global $wpdb;
        $camp_count = 0;
        
        foreach ($camp_ids as $camp_id) {
            // Try to get camp from ptp_camp post type first
            $camp_post = get_post($camp_id);
            
            if ($camp_post && $camp_post->post_type === 'ptp_camp') {
                $price = floatval(get_post_meta($camp_id, '_camp_price', true)) ?: 525;
                $sale_price = floatval(get_post_meta($camp_id, '_camp_sale_price', true));
                if ($sale_price > 0) {
                    $price = $sale_price;
                }
                
                $metadata = array(
                    'name' => $camp_post->post_title,
                    'camp_name' => $camp_post->post_title,
                    'date' => get_post_meta($camp_id, '_camp_date', true) ?: '',
                    'camp_dates' => get_post_meta($camp_id, '_camp_date', true) ?: '',
                    'location' => get_post_meta($camp_id, '_camp_location', true) ?: '',
                    'camp_location' => get_post_meta($camp_id, '_camp_location', true) ?: '',
                    'time' => get_post_meta($camp_id, '_camp_time', true) ?: '9:00 AM - 3:00 PM',
                    'camp_time' => get_post_meta($camp_id, '_camp_time', true) ?: '9:00 AM - 3:00 PM',
                    'stripe_product_id' => get_post_meta($camp_id, '_stripe_product_id', true) ?: '',
                    'stripe_price_id' => get_post_meta($camp_id, '_stripe_price_id', true) ?: '',
                    'early_bird' => $early_bird_from_url ? 1 : 0, // v168.1: Preserve early bird flag from URL
                );
                
                ptp_cart()->add_to_cart('camp', $camp_id, 1, $price, $metadata);
                $camp_count++;
                
            } else {
                // Fallback: try ptp_stripe_products table
                $stripe_table = $wpdb->prefix . 'ptp_stripe_products';
                $camp_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $stripe_table WHERE id = %d AND product_type = 'camp'",
                    $camp_id
                ));
                
                if ($camp_data) {
                    $price = $camp_data->price_cents ? ($camp_data->price_cents / 100) : 525;
                    
                    $metadata = array(
                        'name' => $camp_data->name,
                        'camp_name' => $camp_data->name,
                        'date' => $camp_data->camp_dates ?: '',
                        'camp_dates' => $camp_data->camp_dates ?: '',
                        'location' => $camp_data->camp_location ?: '',
                        'camp_location' => $camp_data->camp_location ?: '',
                        'time' => $camp_data->camp_time ?: '9:00 AM - 3:00 PM',
                        'camp_time' => $camp_data->camp_time ?: '9:00 AM - 3:00 PM',
                        'stripe_product_id' => $camp_data->stripe_product_id ?: '',
                        'stripe_price_id' => $camp_data->stripe_price_id ?: '',
                        'early_bird' => $early_bird_from_url ? 1 : 0, // v168.1: Preserve early bird flag from URL
                    );
                    
                    ptp_cart()->add_to_cart('camp', $camp_id, 1, $price, $metadata);
                    $camp_count++;
                }
            }
        }
        
        // Set multi-week discount flag in session
        if ($camp_count >= 2) {
            $_SESSION['ptp99_multiweek_discount'] = $camp_count >= 3 ? 20 : 10;
        }
        
        // v157: Save immediately to ensure persistence
        if ($camp_count > 0) {
            ptp_cart()->save_cart();
            ptp_log('[PTP Cart Ajax v157] Camps added to cart: ' . $camp_count);
        }
        
        // Mark as processed
        $_SESSION[$processed_key] = true;
    }
    
    /**
     * Handle training URL parameters:
     * ?trainer_id=31&package=single&date=2026-02-19&time=19:00:00&location=undefined&group_size=1
     */
    private function handle_training_params() {
        $trainer_id = isset($_GET['trainer_id']) ? intval($_GET['trainer_id']) : 0;
        if (!$trainer_id) {
            return;
        }
        
        $package = sanitize_text_field($_GET['package'] ?? 'single');
        $date = sanitize_text_field($_GET['date'] ?? '');
        $time = sanitize_text_field($_GET['time'] ?? '');
        $location = sanitize_text_field($_GET['location'] ?? ''); // v236: Accept from URL first
        $location_address = sanitize_text_field($_GET['location_address'] ?? '');
        $group_size = intval($_GET['group_size'] ?? 1);
        
        // Create a unique key for this training session
        $training_key = md5($trainer_id . $package . $date . $time);
        $processed_key = 'ptp_training_processed_' . $training_key;
        
        // Check if already processed
        if (isset($_SESSION[$processed_key])) {
            return;
        }
        
        // Get trainer info from ptp_trainers table (PRIMARY source)
        global $wpdb;
        $trainer_name = 'Trainer';
        $trainer_photo = '';
        $price = 70; // Default
        
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$trainers_table} WHERE id = %d",
            $trainer_id
        ));
        
        if ($trainer) {
            $trainer_name = $trainer->display_name ?: 'Trainer';
            $trainer_photo = $trainer->photo_url ?: '';
            $price = floatval($trainer->hourly_rate ?: 70);
            
            // v236: Use URL-provided location first, trainer DB as fallback
            if (empty($location)) {
                if (!empty($trainer->location)) {
                    $location = $trainer->location;
                } elseif (!empty($trainer->city)) {
                    $location = $trainer->city . (!empty($trainer->state) ? ', ' . $trainer->state : '');
                } else {
                    $location = $trainer->default_location ?: 'TBD';
                }
            }
        } else {
            // Fallback: try WordPress user
            $user = get_user_by('ID', $trainer_id);
            if ($user) {
                $trainer_name = $user->display_name ?: $user->user_login;
                $trainer_photo = get_user_meta($trainer_id, 'ptp_profile_photo', true) ?: '';
                if (!$trainer_photo) {
                    $trainer_photo = get_user_meta($trainer_id, 'profile_photo', true) ?: '';
                }
                $price = floatval(get_user_meta($trainer_id, 'ptp_hourly_rate', true) ?: 70);
            }
        }
        
        // Package pricing — use PTP_Packages when available
        $package_name = 'Single Session';
        $sessions = 1;
        if (class_exists('PTP_Packages')) {
            $resolved = PTP_Packages::resolve_key($package);
            $pkg_def = PTP_Packages::get($resolved);
            $pricing = PTP_Packages::calculate_price($price, $resolved);
            $package_name = $pkg_def['name'];
            $sessions = $pkg_def['sessions'];
            $price = $pricing['total'];
        } else {
            switch ($package) {
                case 'single':
                default:
                    $package_name = 'Single Session';
                    $sessions = 1;
                    break;
                case 'pack3':
                case '4pack':
                case '4-pack':
                    $package_name = '3-Pack';
                    $sessions = 3;
                    $price = $price * 3 * 0.9;
                    break;
                case 'pack5':
                case '5pack':
                case '8pack':
                case '8-pack':
                    $package_name = '5-Pack';
                    $sessions = 5;
                    $price = $price * 5 * 0.85;
                    break;
                case 'pack10':
                case '10pack':
                    $package_name = '10-Pack';
                    $sessions = 10;
                    $price = $price * 10 * 0.8;
                    break;
            }
        }
        
        // Adjust for group size
        if ($group_size > 1) {
            $price = $price * (1 + (($group_size - 1) * 0.5)); // 50% more per additional person
            $package_name .= " (Group of {$group_size})";
        }
        
        // Format date/time for display
        $display_date = $date;
        $display_time = $time;
        if ($date) {
            $date_obj = DateTime::createFromFormat('Y-m-d', $date);
            if ($date_obj) {
                $display_date = $date_obj->format('F j, Y');
            }
        }
        if ($time) {
            $time_obj = DateTime::createFromFormat('H:i:s', $time);
            if ($time_obj) {
                $display_time = $time_obj->format('g:i A');
            }
        }
        
        // v211: Location already set from trainer record above — no fallback needed
        if (empty($location)) {
            $location = 'TBD';
        }
        
        $metadata = array(
            'trainer_id' => $trainer_id,
            'trainer_name' => $trainer_name,
            'trainer_photo' => $trainer_photo,
            'package' => $package,
            'package_name' => $package_name,
            'name' => $trainer_name . ' - ' . $package_name,
            'sessions' => $sessions,
            'date' => $date,
            'session_date' => $display_date,
            'time' => $time,
            'session_time' => $display_time,
            'location' => $location,
            'location_address' => $location_address,
            'group_size' => $group_size,
        );
        
        // Clear existing training items (user is selecting new training)
        $cart = ptp_cart();
        $existing_items = $cart->get_cart();
        foreach ($existing_items as $key => $item) {
            if (($item['item_type'] ?? '') === 'training') {
                $cart->remove_cart_item($key);
            }
        }
        
        // Add to cart
        $added_key = ptp_cart()->add_to_cart('training', $trainer_id, 1, $price, $metadata);
        
        // v157: Save immediately to ensure persistence
        ptp_cart()->save_cart();
        
        ptp_log('[PTP Cart Ajax v157] Training added to cart: ' . $added_key . ', trainer_id: ' . $trainer_id);
        
        // Mark as processed
        $_SESSION[$processed_key] = true;
    }
    
    /**
     * Apply multi-week discount to cart
     */
    public function apply_multiweek_discount($fees) {
        if (!function_exists('ptp_cart')) {
            return $fees;
        }
        
        $cart = ptp_cart();
        $camp_items = $cart->get_items_by_type('camp');
        $camp_count = count($camp_items);
        
        if ($camp_count < 2) {
            return $fees;
        }
        
        // Calculate camp subtotal
        $camp_subtotal = 0;
        foreach ($camp_items as $item) {
            $camp_subtotal += floatval($item['line_total'] ?? $item['price'] ?? 0);
        }
        
        // Determine discount percentage
        $discount_percent = $camp_count >= 3 ? 20 : 10;
        $discount_amount = round($camp_subtotal * ($discount_percent / 100), 2);
        
        if ($discount_amount > 0) {
            $fees[] = array(
                'name' => "Multi-Week Discount ({$discount_percent}%)",
                'amount' => -$discount_amount,
                'taxable' => false,
                'type' => 'discount',
            );
        }
        
        return $fees;
    }
    
    /**
     * Apply early bird discount - $50 off per camp registration
     */
    public function apply_early_bird_discount($fees) {
        if (!$this->early_bird_enabled || !function_exists('ptp_cart')) {
            return $fees;
        }
        
        // Check if early bird is active (can be controlled by date or option)
        $early_bird_active = apply_filters('ptp_early_bird_active', $this->is_early_bird_period());
        if (!$early_bird_active) {
            return $fees;
        }
        
        $cart = ptp_cart();
        $camp_items = $cart->get_items_by_type('camp');
        
        // Early bird only applies to camps
        if (empty($camp_items)) {
            return $fees;
        }
        
        // $50 off per camp registration (configurable via filter)
        $early_bird_per_camp = $this->early_bird_per_camp;
        $camp_count = count($camp_items);
        $discount_amount = $camp_count * $early_bird_per_camp;
        
        if ($discount_amount > 0) {
            $fees[] = array(
                'name' => 'Early Bird Discount ($50/camp)',
                'amount' => -$discount_amount,
                'taxable' => false,
                'type' => 'discount',
            );
        }
        
        return $fees;
    }
    
    /**
     * Check if currently in early bird period
     */
    private function is_early_bird_period() {
        // Can be controlled by option or filter
        $early_bird_end = get_option('ptp_early_bird_end_date', '2026-02-16');
        $now = current_time('Y-m-d');
        return $now <= $early_bird_end;
    }
    
    /**
     * Apply processing fee
     */
    public function apply_processing_fee($fees) {
        if (!function_exists('ptp_cart')) {
            return $fees;
        }
        
        $cart = ptp_cart();
        $subtotal = $cart->get_subtotal();
        
        // Calculate discounts from existing fees
        $discount_total = 0;
        foreach ($fees as $fee) {
            if (isset($fee['amount']) && $fee['amount'] < 0) {
                $discount_total += abs($fee['amount']);
            }
        }
        
        // Calculate processing fee on net amount
        $net_amount = $subtotal - $discount_total;
        if ($net_amount <= 0) {
            return $fees;
        }
        
        $processing_fee = round(($net_amount * ($this->processing_fee_percent / 100)) + $this->processing_fee_fixed, 2);
        
        if ($processing_fee > 0) {
            $fees[] = array(
                'name' => 'Processing Fee',
                'amount' => $processing_fee,
                'taxable' => false,
                'type' => 'fee',
            );
        }
        
        return $fees;
    }
    
    /**
     * Get cart data for display (used by cart template)
     */
    public static function get_cart_display_data() {
        $data = array(
            'subtotal' => 0,
            'early_bird_discount' => 0,
            'multi_week_discount' => 0,
            'bundle_discount' => 0,
            'processing_fee' => 0,
            'total' => 0,
            'fees' => array(),
        );
        
        if (!function_exists('ptp_cart')) {
            return $data;
        }
        
        $cart = ptp_cart();
        $data['subtotal'] = $cart->get_subtotal();
        
        // Get fees
        $fees = apply_filters('ptp_cart_calculate_fees', array());
        $data['fees'] = $fees;
        
        // Categorize fees
        foreach ($fees as $fee) {
            $amount = $fee['amount'] ?? 0;
            $name = strtolower($fee['name'] ?? '');
            
            if (strpos($name, 'early bird') !== false) {
                $data['early_bird_discount'] = abs($amount);
            } elseif (strpos($name, 'multi-week') !== false) {
                $data['multi_week_discount'] = abs($amount);
            } elseif (strpos($name, 'bundle') !== false) {
                $data['bundle_discount'] = abs($amount);
            } elseif (strpos($name, 'processing') !== false) {
                $data['processing_fee'] = $amount;
            }
        }
        
        // Calculate total
        $data['total'] = $data['subtotal'] - $data['early_bird_discount'] - $data['multi_week_discount'] - $data['bundle_discount'] + $data['processing_fee'];
        
        return $data;
    }
    
    /**
     * v136: Create PaymentIntent on demand (deferred from page load)
     * Recalculates totals server-side — never trusts client amount
     */
    public function ajax_cart_create_pi() {
        check_ajax_referer('ptp_cart_action', 'nonce');
        
        // Load cart and recalculate totals server-side
        if (!function_exists('ptp_cart')) {
            wp_send_json_error(array('message' => 'Cart not available'));
            return;
        }
        
        ptp_cart()->load_cart();
        $cart_items = ptp_cart()->get_cart();
        
        if (empty($cart_items)) {
            wp_send_json_error(array('message' => 'Cart is empty'));
            return;
        }
        
        // Recalculate totals from actual cart data
        $subtotal = 0;
        $camp_count = 0;
        $training_count = 0;
        $names = array();
        $camp_ids = array();
        $stripe_products = array();
        $trainer_data = null;
        
        foreach ($cart_items as $item) {
            $type = $item['item_type'] ?? 'product';
            $price = floatval($item['line_total'] ?? ($item['price'] ?? 0));
            $metadata = $item['metadata'] ?? array();
            $subtotal += $price;
            $names[] = $metadata['name'] ?? $metadata['trainer_name'] ?? 'Item';
            
            if ($type === 'camp') {
                $camp_count++;
                if (!empty($metadata['stripe_product'])) $stripe_products[] = $metadata['stripe_product'];
                if (!empty($item['item_id'])) $camp_ids[] = $item['item_id'];
            } elseif ($type === 'training') {
                $training_count++;
                if (!$trainer_data) {
                    $trainer_data = array(
                        'trainer_id' => $metadata['trainer_id'] ?? $item['item_id'] ?? '',
                        'trainer_name' => $metadata['trainer_name'] ?? '',
                        'package' => $metadata['package'] ?? 'single',
                        'session_date' => $metadata['date'] ?? $metadata['session_date'] ?? '',
                        'session_time' => $metadata['time'] ?? $metadata['session_time'] ?? '',
                        'session_location' => $metadata['location'] ?? '',
                        'session_location_address' => $metadata['location_address'] ?? '',
                        'group_size' => $metadata['group_size'] ?? 1,
                    );
                }
            }
        }
        
        // Calculate discounts
        $early_bird_discount = 0;
        if ($this->is_early_bird_period() && $camp_count > 0) {
            $eb_camps = 0;
            foreach ($cart_items as $item) {
                $md = $item['metadata'] ?? array();
                if (($item['item_type'] ?? '') === 'camp' && empty($md['early_bird']) && empty($md['early_bird_applied'])) {
                    $eb_camps++;
                }
            }
            $early_bird_discount = $eb_camps * $this->early_bird_per_camp;
        }
        
        $multi_week_discount = 0;
        if ($camp_count >= 2) {
            $camp_sub = 0;
            foreach ($cart_items as $item) {
                if (($item['item_type'] ?? '') === 'camp') {
                    $camp_sub += floatval($item['line_total'] ?? ($item['price'] ?? 0));
                }
            }
            $mw_pct = $camp_count >= 3 ? 20 : 10;
            $multi_week_discount = round($camp_sub * $mw_pct / 100, 2);
        }
        
        $bundle_discount = 0;
        if ($camp_count > 0 && $training_count > 0) {
            $bundle_discount = round($subtotal * 0.05, 2);
        }
        
        // Before/After care
        $before_care = intval($_POST['before_care'] ?? 0);
        $after_care = intval($_POST['after_care'] ?? 0);
        $care_options = $before_care + $after_care;
        $care_total = 0;
        if ($camp_count > 0 && $care_options > 0) {
            $care_per_week = $care_options === 2 ? 100 : 50;
            $care_total = $care_per_week * $camp_count;
        }
        
        $net = max(0, $subtotal + $care_total - $early_bird_discount - $multi_week_discount - $bundle_discount);
        $processing_fee = ($net > 0) ? round(($net * ($this->processing_fee_percent / 100)) + $this->processing_fee_fixed, 2) : 0;
        $total = max(0, $net + $processing_fee);
        $cents = intval(round($total * 100));
        
        if ($cents < 50) {
            wp_send_json_success(array('free' => true, 'total' => $total));
            return;
        }
        
        // Get Stripe secret key
        $stripe_test_mode = get_option('ptp_stripe_test_mode', false);
        $mode = $stripe_test_mode ? 'test' : 'live';
        $stripe_sk = get_option('ptp_stripe_' . $mode . '_secret', '');
        if (!$stripe_sk) {
            $settings = get_option('ptp_settings', array());
            $legacy_mode = ($settings['stripe_mode'] ?? 'test') === 'live' ? 'live' : 'test';
            $stripe_sk = $settings['stripe_' . $legacy_mode . '_secret_key'] ?? '';
        }
        if (!$stripe_sk) $stripe_sk = get_option('ptp_stripe_secret_key', '');
        
        if (!$stripe_sk) {
            wp_send_json_error(array('message' => 'Payment not configured'));
            return;
        }
        
        // Build PI metadata
        $checkout_session_id = sanitize_text_field($_POST['checkout_session'] ?? wp_generate_uuid4());
        $desc = implode(', ', array_slice($names, 0, 3));
        if (count($names) > 3) $desc .= ' +' . (count($names) - 3) . ' more';
        
        $pi_body = array(
            'amount' => $cents,
            'currency' => 'usd',
            'payment_method_types[]' => 'card',
            'description' => $desc,
            'metadata[checkout_session]' => $checkout_session_id,
            'metadata[source]' => 'ptp_cart_checkout_v136',
            'metadata[subtotal]' => number_format($subtotal, 2),
            'metadata[processing_fee]' => number_format($processing_fee, 2),
            'metadata[total]' => number_format($total, 2),
            'metadata[item_count]' => $camp_count + $training_count,
            'metadata[camp_count]' => $camp_count,
            'metadata[training_count]' => $training_count,
            'metadata[items]' => $desc,
        );
        
        if ($trainer_data) {
            foreach ($trainer_data as $k => $v) {
                $pi_body['metadata[' . $k . ']'] = $v;
            }
        }
        if ($camp_ids) $pi_body['metadata[camp_ids]'] = implode(',', $camp_ids);
        if ($stripe_products) $pi_body['metadata[stripe_products]'] = implode(',', $stripe_products);
        if ($care_total > 0) {
            $pi_body['metadata[before_care]'] = $before_care;
            $pi_body['metadata[after_care]'] = $after_care;
            $pi_body['metadata[extra_care_total]'] = number_format($care_total, 2);
        }
        
        $resp = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
            'headers' => array('Authorization' => 'Bearer ' . $stripe_sk, 'Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => $pi_body,
            'timeout' => 30,
        ));
        
        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => 'Payment service unavailable'));
            return;
        }
        
        $pi = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($pi['client_secret'])) {
            wp_send_json_success(array(
                'client_secret' => $pi['client_secret'],
                'total' => $total,
                'cents' => $cents,
            ));
        } else {
            wp_send_json_error(array('message' => $pi['error']['message'] ?? 'Payment initialization failed'));
        }
    }
}

// Initialize
PTP_Cart_Ajax::instance();

/**
 * Helper function to get cart display data
 */
function ptp_get_cart_display_data() {
    return PTP_Cart_Ajax::get_cart_display_data();
}
