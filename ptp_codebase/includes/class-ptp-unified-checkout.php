<?php
/**
 * PTP Unified Checkout Handler v85.6
 * 
 * Handles:
 * - Camper info (DOB, shirt size, team, skill level)
 * - Parent/Guardian info
 * - Emergency contact & medical info
 * - Insurance information
 * - Waiver acceptance
 * - PTP Native orders (camps/clinics)
 * - Training bookings
 * - Bundle discounts
 * - Stripe Payment Intents
 */

defined('ABSPATH') || exit;

class PTP_Unified_Checkout {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('wp_ajax_ptp_unified_checkout', array($this, 'process_checkout'));
        add_action('wp_ajax_nopriv_ptp_unified_checkout', array($this, 'process_checkout'));
        
        // AJAX handler for saving checkout data (new flow)
        add_action('wp_ajax_ptp_save_checkout', array($this, 'save_checkout_data'));
        add_action('wp_ajax_nopriv_ptp_save_checkout', array($this, 'save_checkout_data'));
        
        // v115.5.1: AJAX handler for creating order after successful payment
        add_action('wp_ajax_ptp_create_order_after_payment', array($this, 'ajax_create_order_after_payment'));
        add_action('wp_ajax_nopriv_ptp_create_order_after_payment', array($this, 'ajax_create_order_after_payment'));
        
        // v240: AJAX handler for completing free checkout (FREETRAINING, PTPFREE, etc.)
        add_action('wp_ajax_ptp_complete_free_checkout', array($this, 'ajax_complete_free_checkout'));
        add_action('wp_ajax_nopriv_ptp_complete_free_checkout', array($this, 'ajax_complete_free_checkout'));
        
        // Handle payment return URL (for Affirm and other redirect methods)
        add_action('template_redirect', array($this, 'handle_payment_return'));
        
        // Auto-create thank-you page if missing
        add_action('init', array($this, 'ensure_thank_you_page'), 20);
        
        // Ensure tables exist
        add_action('init', array($this, 'maybe_create_tables'));
        
        // v117.2.18: Fallback email trigger for training bookings
        add_action('ptp_training_booked_fallback', array($this, 'handle_training_email_fallback'), 10, 3);
    }
    
    /**
     * Handle fallback email sending for training bookings
     * Called from thank-you page if email wasn't sent initially
     */
    public function handle_training_email_fallback($booking_id, $trainer_id, $booking) {
        global $wpdb;
        
        // Check if already processed
        $already_sent = get_transient('ptp_training_email_' . $booking_id);
        if ($already_sent) {
            ptp_log('[PTP Email Fallback v117.2.18] Already processed booking #' . $booking_id);
            return;
        }
        
        // Get player name
        $player = null;
        if (!empty($booking->player_id)) {
            $player = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}ptp_players WHERE id = %d",
                $booking->player_id
            ));
        }
        $camper_name = $player ? trim($player->first_name . ' ' . $player->last_name) : 'Player';
        
        // Call notify_trainer
        $this->notify_trainer($trainer_id, $booking_id, $camper_name);
        
        // Mark as processed
        set_transient('ptp_training_email_' . $booking_id, 1, 24 * HOUR_IN_SECONDS);
        
        ptp_log('[PTP Email Fallback v117.2.18] Processed fallback email for booking #' . $booking_id);
    }
    
    /**
     * Save checkout data for later order creation (called before payment confirmation)
     * v114: Now stores cart items for reliable order creation after redirect
     */
    public function save_checkout_data() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['ptp_checkout_nonce'] ?? '', 'ptp_checkout')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }
        
        $checkout_session = sanitize_text_field($_POST['checkout_session'] ?? '');
        if (empty($checkout_session)) {
            wp_send_json_error(array('message' => 'Invalid checkout session.'));
            return;
        }
        
        ptp_log('[PTP Checkout v114] save_checkout_data starting for session: ' . $checkout_session);
        
        // Collect form data
        $parent_data = array(
            'first_name' => sanitize_text_field($_POST['parent_first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['parent_last_name'] ?? ''),
            'email' => sanitize_email($_POST['parent_email'] ?? ''),
            'phone' => sanitize_text_field($_POST['parent_phone'] ?? ''),
        );
        
        $player_id = intval($_POST['player_id'] ?? 0);
        
        // v131: Handle multi-player checkout for group sessions
        $group_player_count = intval($_POST['group_player_count'] ?? 0);
        $group_session_id = intval($_POST['group_session_id'] ?? 0);
        $group_size = intval($_POST['group_size'] ?? 1);
        $players_data = array();
        
        if ($group_player_count > 0 && !empty($_POST['players'])) {
            // Multi-player checkout
            foreach ($_POST['players'] as $idx => $player_input) {
                $players_data[] = array(
                    'player_id' => intval($player_input['player_id'] ?? 0),
                    'first_name' => sanitize_text_field($player_input['first_name'] ?? ''),
                    'last_name' => sanitize_text_field($player_input['last_name'] ?? ''),
                    'dob' => sanitize_text_field($player_input['dob'] ?? ''),
                    'shirt_size' => sanitize_text_field($player_input['shirt_size'] ?? ''),
                    'team' => sanitize_text_field($player_input['team'] ?? ''),
                    'skill_level' => sanitize_text_field($player_input['skill'] ?? ''),
                );
            }
            
            // Use first player as primary camper_data for compatibility
            if (!empty($players_data)) {
                $camper_data = $players_data[0];
            } else {
                $camper_data = array(
                    'first_name' => '',
                    'last_name' => '',
                    'dob' => '',
                    'shirt_size' => '',
                    'team' => '',
                    'skill_level' => '',
                );
            }
            
            ptp_log('[PTP Checkout v131] Multi-player checkout with ' . count($players_data) . ' players');
        } else {
            // Single player checkout (original)
            // v177: Read both camper_* and player_* field names for compatibility
            $camper_data = array(
                'first_name' => sanitize_text_field($_POST['camper_first_name'] ?? $_POST['player_first_name'] ?? ''),
                'last_name' => sanitize_text_field($_POST['camper_last_name'] ?? $_POST['player_last_name'] ?? ''),
                'dob' => sanitize_text_field($_POST['camper_dob'] ?? $_POST['player_dob'] ?? ''),
                'shirt_size' => sanitize_text_field($_POST['camper_shirt_size'] ?? $_POST['player_shirt'] ?? ''),
                'team' => sanitize_text_field($_POST['camper_team'] ?? $_POST['player_team'] ?? ''),
                'skill_level' => sanitize_text_field($_POST['camper_skill'] ?? ''),
            );
        }
        
        $emergency_data = array(
            'name' => sanitize_text_field($_POST['emergency_name'] ?? ''),
            'phone' => sanitize_text_field($_POST['emergency_phone'] ?? ''),
            'relation' => sanitize_text_field($_POST['emergency_relation'] ?? ''),
        );
        
        // Validation - allow existing player selection
        if (empty($parent_data['first_name']) || empty($parent_data['email'])) {
            wp_send_json_error(array('message' => 'Please fill in all required parent/guardian fields.'));
            return;
        }
        
        // If no existing player selected, new camper data required
        if (!$player_id && empty($camper_data['first_name'])) {
            wp_send_json_error(array('message' => 'Please provide camper information.'));
            return;
        }
        
        if (empty($emergency_data['name']) || empty($emergency_data['phone'])) {
            wp_send_json_error(array('message' => 'Emergency contact information is required.'));
            return;
        }
        
        $waiver_accepted = !empty($_POST['waiver']) || !empty($_POST['waiver_accepted']);
        if (!$waiver_accepted) {
            wp_send_json_error(array('message' => 'You must accept the waiver to continue.'));
            return;
        }
        
        // v10.3.6: Capture native cart items BEFORE the redirect
        $cart_items_data = array();
        $has_native_cart = false;
        
        if (function_exists('ptp_cart') && !ptp_cart()->is_empty()) {
            $has_native_cart = true;
            foreach (ptp_cart()->get_cart() as $cart_key => $cart_item) {
                $item_type = $cart_item['item_type'] ?? 'product';
                $metadata = $cart_item['metadata'] ?? array();
                
                // v16: Support both key formats for stripe IDs
                $stripe_product = $metadata['stripe_product'] ?? $metadata['stripe_product_id'] ?? '';
                $stripe_price = $metadata['stripe_price'] ?? $metadata['stripe_price_id'] ?? '';
                $item_name = $metadata['name'] ?? $metadata['camp_name'] ?? 'Item';
                $item_date = $metadata['date'] ?? $metadata['camp_date'] ?? $metadata['camp_dates'] ?? '';
                $item_location = $metadata['location'] ?? $metadata['camp_location'] ?? '';
                $item_time = $metadata['time'] ?? $metadata['camp_time'] ?? '9AM - 3PM';
                
                $cart_items_data[] = array(
                    'item_type' => $item_type,
                    'product_id' => $cart_item['item_id'],
                    'quantity' => $cart_item['quantity'],
                    'price' => $cart_item['price'],
                    'line_total' => $cart_item['line_total'],
                    'name' => $item_name,
                    'stripe_product' => $stripe_product,
                    'stripe_price' => $stripe_price,
                    'source_url' => $metadata['source_url'] ?? '',
                    'date' => $item_date,
                    'location' => $item_location,
                    'time' => $item_time,
                );
            }
            ptp_log('[PTP Checkout v16] Captured ' . count($cart_items_data) . ' native cart items');
        }
        
        // Sibling info
        $sibling_data = null;
        if (!empty($_POST['sibling_first_name'])) {
            $sibling_data = array(
                'first_name' => sanitize_text_field($_POST['sibling_first_name'] ?? ''),
                'last_name' => sanitize_text_field($_POST['sibling_last_name'] ?? ''),
                'dob' => sanitize_text_field($_POST['sibling_dob'] ?? ''),
                'shirt_size' => sanitize_text_field($_POST['sibling_shirt'] ?? $_POST['sibling_shirt_size'] ?? ''),
            );
        }
        
        // Before/After Care
        $before_after_care = !empty($_POST['before_after_care']);
        $care_amount = floatval($_POST['before_after_care_amount'] ?? 0);
        
        // Upgrade pack
        $upgrade_selected = sanitize_text_field($_POST['upgrade_selected'] ?? '');
        $upgrade_amount = floatval($_POST['upgrade_amount'] ?? 0);
        $upgrade_camps = sanitize_text_field($_POST['upgrade_camps'] ?? '');
        
        // Referral - v175: Check both field names for compatibility
        $referral_code = sanitize_text_field($_POST['referral_code'] ?? $_POST['referral_validated'] ?? '');
        $referral_discount = floatval($_POST['referral_discount'] ?? 0);
        $referral_id = intval($_POST['referral_id'] ?? 0);
        
        // v175: Coupon/Promo code
        $coupon_code = strtoupper(sanitize_text_field($_POST['coupon_code'] ?? ''));
        $coupon_discount = floatval($_POST['coupon_discount'] ?? 0);
        $coupon_id = intval($_POST['coupon_id'] ?? 0);
        $free_code_id = intval($_POST['free_code_id'] ?? 0);
        
        // Jersey upsell
        $jersey_added = !empty($_POST['jersey_upsell']) || !empty($_POST['jersey_added']);
        $jersey_amount = floatval($_POST['jersey_amount'] ?? ($_POST['jersey_upsell'] ? 50 : 0));
        
        // Final total
        $final_total = floatval($_POST['final_total'] ?? $_POST['cart_total'] ?? 0);
        
        // Store checkout data with cart items
        $checkout_data = array(
            'parent_data' => $parent_data,
            'player_id' => $player_id,
            'camper_data' => $camper_data,
            'sibling_data' => $sibling_data,
            'emergency_data' => $emergency_data,
            'medical_info' => sanitize_textarea_field($_POST['medical_info'] ?? $_POST['player_medical'] ?? ''),
            'insurance_data' => array(
                'provider' => sanitize_text_field($_POST['insurance_provider'] ?? ''),
                'policy' => sanitize_text_field($_POST['insurance_policy'] ?? ''),
                'group' => sanitize_text_field($_POST['insurance_group'] ?? ''),
            ),
            'waiver_accepted' => true,
            'photo_consent' => !empty($_POST['photo_consent']),
            // v168: Instagram announcement photo
            'announcement_photo_url' => esc_url_raw($_POST['announcement_photo_url'] ?? ''),
            'instagram_handle' => sanitize_text_field($_POST['instagram_handle'] ?? ''),
            'instagram_photo_consent' => !empty($_POST['photo_consent']) && !empty($_POST['announcement_photo_url']),
            // v193: How did you find us
            'how_found_us' => sanitize_text_field($_POST['how_found_us'] ?? ''),
            'how_found_us_other' => sanitize_text_field($_POST['how_found_us_other'] ?? ''),
            'trainer_id' => intval($_POST['trainer_id'] ?? 0),
            'training_package' => sanitize_text_field($_POST['training_package'] ?? 'single'),
            'training_total' => floatval($_POST['training_total'] ?? $_POST['training_price'] ?? 0),
            'session_date' => function_exists('ptp_normalize_session_date')
                ? ptp_normalize_session_date($_POST['session_date'] ?? '')
                : sanitize_text_field($_POST['session_date'] ?? ''),
            'session_time' => function_exists('ptp_normalize_session_time') 
                ? ptp_normalize_session_time($_POST['session_time'] ?? '') 
                : sanitize_text_field($_POST['session_time'] ?? ''),
            'session_location' => sanitize_text_field($_POST['session_location'] ?? ''),
            'session_location_address' => sanitize_text_field($_POST['session_location_address'] ?? ''),
            'session_location_lat' => sanitize_text_field($_POST['session_location_lat'] ?? ''),
            'session_location_lng' => sanitize_text_field($_POST['session_location_lng'] ?? ''),
            'cart_total' => floatval($_POST['cart_total'] ?? 0),
            'final_total' => $final_total,
            'user_id' => get_current_user_id(),
            // v114: Include cart items for order creation
            'has_native_cart' => $has_native_cart,
            'cart_items' => $cart_items_data,
            // Add-ons and discounts
            'before_after_care' => $before_after_care,
            'care_amount' => $care_amount,
            'upgrade_selected' => $upgrade_selected,
            'upgrade_amount' => $upgrade_amount,
            'upgrade_camps' => $upgrade_camps,
            'referral_code' => $referral_code,
            'referral_discount' => $referral_discount,
            'referral_id' => $referral_id,
            // v175: Coupon data
            'coupon_code' => $coupon_code,
            'coupon_discount' => $coupon_discount,
            'coupon_id' => $coupon_id,
            'free_code_id' => $free_code_id,
            'jersey_added' => $jersey_added,
            'jersey_amount' => $jersey_amount,
            // v193: Parent's own referral code for sharing
            'generated_referral_code' => sanitize_text_field($_POST['generated_referral_code'] ?? ''),
            'created_at' => current_time('mysql'),
            // v131: Multi-player / group session support
            'players_data' => $players_data,
            'group_player_count' => $group_player_count,
            'group_session_id' => $group_session_id,
            'group_size' => $group_size,
        );
        
        // v236: Increased from 2 hours to 24 hours — parents who get distracted lose their checkout data
        $saved = set_transient('ptp_checkout_' . $checkout_session, $checkout_data, DAY_IN_SECONDS);
        ptp_log('[PTP Checkout v114] Transient saved: ' . ($saved ? 'YES' : 'NO') . ' for session ' . $checkout_session);
        ptp_log('[PTP Checkout v114] Data includes ' . count($cart_items_data) . ' cart items, total: $' . $final_total);
        // v117.2.24: Enhanced training data logging
        ptp_log('[PTP Checkout v117.2.24] ===== TRAINING DATA CAPTURED =====');
        ptp_log('[PTP Checkout v117.2.24] trainer_id from POST: ' . ($_POST['trainer_id'] ?? 'NOT IN POST'));
        ptp_log('[PTP Checkout v117.2.24] training_total from POST: ' . ($_POST['training_total'] ?? 'NOT IN POST'));
        ptp_log('[PTP Checkout v117.2.24] training_package from POST: ' . ($_POST['training_package'] ?? 'NOT IN POST'));
        ptp_log('[PTP Checkout v117.2.24] Stored trainer_id: ' . ($checkout_data['trainer_id'] ?? 'NOT SET'));
        ptp_log('[PTP Checkout v117.2.24] Stored training_total: ' . ($checkout_data['training_total'] ?? 'NOT SET'));
        ptp_log('[PTP Checkout v117.2.24] Stored training_package: ' . ($checkout_data['training_package'] ?? 'NOT SET'));
        ptp_log('[PTP Checkout v117.2.24] parent_email: ' . ($checkout_data['parent_data']['email'] ?? 'NOT SET'));
        
        wp_send_json_success(array('message' => 'Checkout data saved', 'session' => $checkout_session));
    }
    
    /**
     * v115.5.1: Create PTP Native order after successful Stripe payment
     * Called from JS after stripe.confirmPayment() succeeds
     */
    public function ajax_create_order_after_payment() {
        ptp_log('[PTP Order v115.5.1] ========== CREATING ORDER AFTER PAYMENT ==========');
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['ptp_checkout_nonce'] ?? '', 'ptp_checkout')) {
            ptp_log('[PTP Order v115.5.1] Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $checkout_session = sanitize_text_field($_POST['checkout_session'] ?? '');
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
        
        ptp_log('[PTP Order v115.5.1] Session: ' . $checkout_session . ', PI: ' . $payment_intent_id);
        
        if (empty($checkout_session)) {
            wp_send_json_error(array('message' => 'Missing checkout session'));
            return;
        }
        
        // Get checkout data from transient
        $checkout_data = get_transient('ptp_checkout_' . $checkout_session);
        
        if (empty($checkout_data)) {
            ptp_log('[PTP Order v115.5.1] No checkout data found for session: ' . $checkout_session);
            wp_send_json_error(array('message' => 'Checkout data not found'));
            return;
        }
        
        // Verify payment with Stripe if we have PI ID
        $intent = null;
        if (!empty($payment_intent_id)) {
            $secret_key = get_option('ptp_stripe_test_mode', true) 
                ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
                : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
            
            if (!empty($secret_key)) {
                $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
                    'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                    'timeout' => 30,
                ));
                
                if (!is_wp_error($response)) {
                    $intent = json_decode(wp_remote_retrieve_body($response), true);
                    ptp_log('[PTP Order v115.5.1] Payment status: ' . ($intent['status'] ?? 'unknown'));
                    
                    if (($intent['status'] ?? '') !== 'succeeded') {
                        wp_send_json_error(array('message' => 'Payment not completed'));
                        return;
                    }
                }
            }
        }
        
        // Create the order
        $result = $this->create_orders_from_session($checkout_data, $payment_intent_id, $intent ?? array());
        
        // v117.2.21: Success if we have EITHER order_id OR booking_id
        if (!empty($result['order_id']) || !empty($result['booking_id'])) {
            // Delete the transient
            delete_transient('ptp_checkout_' . $checkout_session);
            
            // Mark as processed
            set_transient('ptp_processed_' . ($payment_intent_id ?: $checkout_session), true, DAY_IN_SECONDS);
            
            // Set cookie for thank-you page
            if (!headers_sent()) {
                if (!empty($result['order_id'])) {
                    setcookie('ptp_last_order', $result['order_id'], time() + 3600, '/');
                }
                if (!empty($result['booking_id'])) {
                    setcookie('ptp_last_booking', $result['booking_id'], time() + 3600, '/');
                }
            }
            
            ptp_log('[PTP Order v117.2.21] Success - order_id: ' . ($result['order_id'] ?? 'none') . ', booking_id: ' . ($result['booking_id'] ?? 'none'));
            wp_send_json_success(array(
                'order_id' => $result['order_id'] ?? null,
                'booking_id' => $result['booking_id'] ?? null,
                'message' => 'Order/booking created'
            ));
        } else {
            ptp_log('[PTP Order v117.2.21] Failed to create order or booking');
            wp_send_json_error(array('message' => 'Failed to create order'));
        }
    }
    
    /**
     * v240: Complete a free checkout (FREETRAINING, PTPFREE, FIRSTFREE, PTP-app codes)
     * Called from JS when coupon makes total $0 — skips Stripe entirely.
     */
    public function ajax_complete_free_checkout() {
        // v236: Wrap ENTIRE handler in try-catch — any uncaught PHP error/warning
        // outputs non-JSON text which breaks the JS fetch().json() call
        try {
            ptp_log('[PTP Free Checkout v236] ========== FREE CHECKOUT START ==========');
            
            // Verify nonce — accept either free or regular checkout nonce
            $nonce_val = $_POST['nonce'] ?? $_POST['ptp_checkout_nonce'] ?? '';
            if (!wp_verify_nonce($nonce_val, 'ptp_free_checkout') && !wp_verify_nonce($nonce_val, 'ptp_checkout')) {
                ptp_log('[PTP Free Checkout v236] Nonce verification failed');
                wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
                return;
            }
            
            $checkout_session = sanitize_text_field($_POST['checkout_session'] ?? '');
            if (empty($checkout_session)) {
                wp_send_json_error(array('message' => 'Missing checkout session.'));
                return;
            }
            
            // Get saved checkout data from transient
            $checkout_data = get_transient('ptp_checkout_' . $checkout_session);
            if (empty($checkout_data)) {
                ptp_log('[PTP Free Checkout v236] No checkout data for session: ' . $checkout_session);
                wp_send_json_error(array('message' => 'Checkout session expired. Please try again.'));
                return;
            }
            
            $app_code = strtoupper(sanitize_text_field($_POST['app_code'] ?? ''));
            $free_code_id = intval($_POST['free_code_id'] ?? $checkout_data['free_code_id'] ?? 0);
            $coupon_id = intval($_POST['coupon_id'] ?? $checkout_data['coupon_id'] ?? 0);
            $coupon_code = '';
            if (!empty($checkout_data['coupon_code'])) {
                $coupon_code = strtoupper(sanitize_text_field($checkout_data['coupon_code']));
            } elseif (!empty($app_code)) {
                $coupon_code = $app_code;
            }
            
            $training_total = floatval($checkout_data['training_total'] ?? 0);
            $item_type = !empty($checkout_data['trainer_id']) ? 'training' : 'all';
            
            // v236: Re-validate coupon only if PTP_Coupon_Tracker is available
            // If validation fails or class is missing, still allow the booking (coupon was already validated client-side)
            $code_to_validate = $coupon_code ?: $app_code;
            if (!empty($code_to_validate) && class_exists('PTP_Coupon_Tracker') && method_exists('PTP_Coupon_Tracker', 'validate_coupon')) {
                try {
                    $validation = PTP_Coupon_Tracker::validate_coupon($code_to_validate, $training_total, $item_type);
                    if (!empty($validation['valid'])) {
                        if (!empty($validation['coupon_id'])) $coupon_id = intval($validation['coupon_id']);
                        if (!empty($validation['free_code_id'])) $free_code_id = intval($validation['free_code_id']);
                        if (!empty($validation['app_id'])) $checkout_data['free_app_id'] = intval($validation['app_id']);
                        if (!empty($validation['app_code'])) $checkout_data['free_app_code'] = $validation['app_code'];
                        ptp_log('[PTP Free Checkout v236] Coupon re-validated: ' . $code_to_validate);
                    } else {
                        // v236: Log but DON'T block — the coupon was already validated when applied
                        ptp_log('[PTP Free Checkout v236] Coupon re-validation returned invalid for: ' . $code_to_validate . ' — proceeding anyway');
                    }
                } catch (\Throwable $ve) {
                    ptp_log('[PTP Free Checkout v236] Coupon validation threw: ' . $ve->getMessage() . ' — proceeding anyway');
                }
            } else {
                ptp_log('[PTP Free Checkout v236] Skipping coupon re-validation (class not available or no code)');
            }
            
            // Mark as free session
            $checkout_data['is_free_session'] = true;
            $checkout_data['coupon_code'] = $coupon_code;
            $checkout_data['coupon_id'] = $coupon_id;
            $checkout_data['free_code_id'] = $free_code_id;
            $checkout_data['coupon_discount'] = $training_total;
            $checkout_data['final_total'] = 0;
            
            ptp_log(sprintf('[PTP Free Checkout v236] Processing — trainer=%d, code=%s, coupon_id=%d, free_code_id=%d, original_price=$%.2f',
                $checkout_data['trainer_id'] ?? 0, $coupon_code, $coupon_id, $free_code_id, $training_total));
            
            // Create the booking
            $free_pi_id = 'free_session_' . $checkout_session;
            $result = $this->create_orders_from_session($checkout_data, $free_pi_id, array());
            
            if (!empty($result['booking_id'])) {
                delete_transient('ptp_checkout_' . $checkout_session);
                set_transient('ptp_processed_' . $checkout_session, true, DAY_IN_SECONDS);
                
                // Track coupon usage (non-fatal)
                try {
                    if ($coupon_id > 0 && class_exists('PTP_Coupon_Tracker')) {
                        PTP_Coupon_Tracker::instance()->increment_coupon_usage($coupon_id);
                    }
                    if ($free_code_id > 0 && class_exists('PTP_Coupon_Tracker')) {
                        PTP_Coupon_Tracker::instance()->mark_free_code_used($free_code_id, $result['booking_id'], null);
                    }
                } catch (\Throwable $te) {
                    ptp_log('[PTP Free Checkout v236] Coupon tracking failed (non-fatal): ' . $te->getMessage());
                }
                
                if (!headers_sent()) {
                    setcookie('ptp_last_booking', $result['booking_id'], time() + 3600, '/');
                }
                
                ptp_log('[PTP Free Checkout v236] SUCCESS — booking_id=' . $result['booking_id']);
                
                // Fire hooks
                do_action('ptp_booking_completed', $result['booking_id'], $checkout_data);
                do_action('ptp_training_booking_completed', $result['booking_id']);
                
                wp_send_json_success(array(
                    'booking_id' => $result['booking_id'],
                    'order_id' => $result['order_id'] ?? null,
                    'message' => 'Free session booked!'
                ));
            } else {
                $error_msg = $result['error'] ?? 'Failed to create booking';
                ptp_log('[PTP Free Checkout v236] FAILED — ' . $error_msg);
                wp_send_json_error(array('message' => $error_msg));
            }
        } catch (\Throwable $e) {
            // v236: Catch ALL errors — guarantees JSON response even on PHP fatal
            ptp_log('[PTP Free Checkout v236] FATAL ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            ptp_log('[PTP Free Checkout v236] Stack: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => 'Booking failed: ' . $e->getMessage()));
        }
    }
    
    /**
     * Ensure thank-you page exists
     */
    public function ensure_thank_you_page() {
        $page = get_page_by_path('thank-you');
        
        if (!$page) {
            // Create the page immediately
            $page_id = wp_insert_post(array(
                'post_title' => 'Thank You',
                'post_name' => 'thank-you',
                'post_content' => '[ptp_thank_you]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => 1,
            ));
            
            if ($page_id && !is_wp_error($page_id)) {
                ptp_log('[PTP] Auto-created thank-you page: ' . $page_id);
                // Flush rewrite rules so the page works immediately
                flush_rewrite_rules();
            }
        }
    }
    
    /**
     * Handle return from Stripe redirect payment (Affirm, etc.)
     */
    public function handle_payment_return() {
        // Only handle on thank-you page
        if (strpos($_SERVER['REQUEST_URI'], '/thank-you/') === false && 
            strpos($_SERVER['REQUEST_URI'], '/order-received/') === false) {
            return;
        }
        
        $payment_intent_id = sanitize_text_field($_GET['payment_intent'] ?? '');
        $session_id = sanitize_text_field($_GET['session'] ?? '');
        
        // Need either payment_intent or session
        if (empty($payment_intent_id) && empty($session_id)) {
            return;
        }
        
        // Check if we already processed this
        $process_key = $payment_intent_id ?: $session_id;
        $already_processed = get_transient('ptp_processed_' . $process_key);
        if ($already_processed) {
            return; // Let normal page load happen
        }
        
        ptp_log('[PTP Return] Processing return - PI: ' . $payment_intent_id . ', Session: ' . $session_id);
        
        // Get Stripe secret key
        $secret_key = get_option('ptp_stripe_test_mode', true) 
            ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
            : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
        
        if (empty($secret_key)) {
            return; // Can't verify, let page load normally
        }
        
        // Get checkout data first to find payment intent if we only have session
        $checkout_data = get_transient('ptp_checkout_' . $session_id);
        
        // If we have session but no payment_intent, we need to find the PI from the session's metadata
        if (empty($payment_intent_id) && !empty($session_id)) {
            // Search for PaymentIntent with this checkout_session in metadata
            $search_response = wp_remote_get('https://api.stripe.com/v1/payment_intents?limit=5', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                ),
                'timeout' => 30,
            ));
            
            if (!is_wp_error($search_response)) {
                $search_body = json_decode(wp_remote_retrieve_body($search_response), true);
                foreach (($search_body['data'] ?? array()) as $pi) {
                    if (($pi['metadata']['checkout_session'] ?? '') === $session_id) {
                        $payment_intent_id = $pi['id'];
                        break;
                    }
                }
            }
        }
        
        if (empty($payment_intent_id)) {
            ptp_log('[PTP Return] Could not find PaymentIntent for session: ' . $session_id);
            return; // Can't find PI, let page load normally
        }
        
        // Retrieve PaymentIntent from Stripe
        $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('[PTP Return] Stripe API error: ' . $response->get_error_message());
            return;
        }
        
        $intent = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($intent) || isset($intent['error'])) {
            ptp_log('[PTP Return] Invalid PaymentIntent: ' . json_encode($intent));
            return;
        }
        
        $status = $intent['status'] ?? '';
        ptp_log('[PTP Return] PaymentIntent status: ' . $status);
        
        // Check if payment succeeded
        if ($status !== 'succeeded') {
            // Payment not complete - redirect back to checkout with error
            $error_msg = 'Payment was not completed. Status: ' . $status;
            if ($status === 'requires_payment_method') {
                $error_msg = 'Payment failed. Please try again with a different payment method.';
            }
            
            // v117.2.8: Preserve checkout params for retry
            $redirect_url = home_url('/ptp-checkout/');
            $redirect_params = array('payment_error' => $error_msg);
            
            // Try to get original params from checkout data
            if ($checkout_data) {
                if (!empty($checkout_data['trainer_id'])) {
                    $redirect_params['trainer_id'] = $checkout_data['trainer_id'];
                }
                if (!empty($checkout_data['training_package'])) {
                    $redirect_params['package'] = $checkout_data['training_package'];
                }
                if (!empty($checkout_data['session_date'])) {
                    $redirect_params['date'] = $checkout_data['session_date'];
                }
                if (!empty($checkout_data['session_time'])) {
                    $redirect_params['time'] = $checkout_data['session_time'];
                }
                if (!empty($checkout_data['session_location'])) {
                    $redirect_params['location'] = $checkout_data['session_location'];
                }
            }
            
            wp_redirect(add_query_arg($redirect_params, $redirect_url));
            exit;
        }
        
        // Get checkout data from transient
        $checkout_session = $intent['metadata']['checkout_session'] ?? $session_id;
        if (empty($checkout_data)) {
            $checkout_data = get_transient('ptp_checkout_' . $checkout_session);
        }
        
        // v168.1: FALLBACK - If transient expired but payment succeeded, use Stripe metadata
        if (empty($checkout_data)) {
            $intent_metadata = $intent['metadata'] ?? array();
            $camp_count = intval($intent_metadata['camp_count'] ?? 0);
            $training_count = intval($intent_metadata['training_count'] ?? 0);
            
            ptp_log('[PTP Return v168.1] No transient, checking Stripe metadata. camp_count=' . $camp_count . ', training_count=' . $training_count);
            
            // If this is a camp payment (has camps, no training), create order from Stripe metadata
            if ($camp_count > 0 && $training_count == 0 && class_exists('PTP_Camp_Orders')) {
                global $wpdb;
                
                // Check if order already exists
                $existing_order = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE stripe_payment_intent_id = %s",
                    $payment_intent_id
                ));
                
                if (!$existing_order) {
                    ptp_log('[PTP Return v168.1] Creating camp order from Stripe metadata...');
                    
                    // Build order data from Stripe metadata
                    $order_number = 'PTP-' . strtoupper(substr(md5($payment_intent_id), 0, 8));
                    $amount_paid = floatval($intent['amount_received'] ?? $intent['amount'] ?? 0) / 100; // Stripe uses cents
                    
                    // Get customer details from Stripe
                    $items_str = $intent_metadata['items'] ?? '';
                    $customer_email = $intent['receipt_email'] ?? '';
                    
                    // Try to get customer email from Stripe Customer if available
                    $customer_name = '';
                    if (!empty($intent['customer'])) {
                        $customer_response = wp_remote_get('https://api.stripe.com/v1/customers/' . $intent['customer'], array(
                            'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                            'timeout' => 10,
                        ));
                        if (!is_wp_error($customer_response)) {
                            $customer_data = json_decode(wp_remote_retrieve_body($customer_response), true);
                            if (empty($customer_email)) {
                                $customer_email = $customer_data['email'] ?? '';
                            }
                            $customer_name = $customer_data['name'] ?? '';
                        }
                    }
                    
                    // Split name
                    $name_parts = explode(' ', $customer_name, 2);
                    $first_name = $name_parts[0] ?? '';
                    $last_name = $name_parts[1] ?? '';
                    
                    // Create order
                    $wpdb->insert($wpdb->prefix . 'ptp_unified_camp_orders', array(
                        'order_number' => $order_number,
                        'status' => 'completed',
                        'payment_status' => 'paid',
                        'billing_first_name' => $first_name,
                        'billing_last_name' => $last_name,
                        'billing_email' => $customer_email,
                        'subtotal' => $amount_paid,
                        'total_amount' => $amount_paid,
                        'stripe_payment_intent_id' => $payment_intent_id,
                        'stripe_checkout_session_id' => $checkout_session,
                        'paid_at' => current_time('mysql'),
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ));
                    $order_id = $wpdb->insert_id;
                    
                    if ($order_id) {
                        // Create order item from metadata - parse items string
                        // Items string format: "Camp Name ($XXX)"
                        $camp_name = 'Camp Registration';
                        if (preg_match('/^(.+?)\s*\(\$[\d.]+\)/', $items_str, $matches)) {
                            $camp_name = trim($matches[1]);
                        } elseif (!empty($items_str)) {
                            $camp_name = $items_str;
                        }
                        
                        $wpdb->insert($wpdb->prefix . 'ptp_camp_order_items', array(
                            'order_id' => $order_id,
                            'camp_name' => $camp_name,
                            'camp_dates' => $intent_metadata['date'] ?? '',
                            'camp_location' => $intent_metadata['location'] ?? '',
                            'camp_time' => $intent_metadata['time'] ?? '9AM - 3PM',
                            'camper_first_name' => $first_name,
                            'camper_last_name' => $last_name,
                            'base_price' => $amount_paid,
                            'final_price' => $amount_paid,
                        ));
                        
                        ptp_log('[PTP Return v168.1] Camp order created from Stripe metadata: #' . $order_id . ' (' . $order_number . ')');
                        
                        // Fire hook for Command Center contact sync
                        do_action('ptp_camp_order_created', $order_id, array(
                            'email' => $customer_email,
                            'first_name' => $first_name,
                            'last_name' => $last_name,
                            'amount' => $amount_paid,
                        ));
                        // Send confirmation email
                        if (!empty($customer_email) && class_exists('PTP_Camp_Emails')) {
                            PTP_Camp_Emails::send_order_confirmation($order_id);
                            ptp_log('[PTP Return v168.1] Sent camp confirmation email');
                        }
                        
                        // Redirect to thank you with order
                        set_transient('ptp_processed_' . $process_key, true, DAY_IN_SECONDS);
                        wp_redirect(home_url('/thank-you/?order=' . $order_id));
                        exit;
                    }
                } else {
                    // Order exists, redirect to it
                    ptp_log('[PTP Return v168.1] Camp order already exists: #' . $existing_order);
                    set_transient('ptp_processed_' . $process_key, true, DAY_IN_SECONDS);
                    wp_redirect(home_url('/thank-you/?order=' . $existing_order));
                    exit;
                }
            }
            
            // Not a camp or couldn't create - log and return
            ptp_log('[PTP Return] No checkout data found for session: ' . $checkout_session);
            // Mark as processed anyway to prevent loop
            set_transient('ptp_processed_' . $process_key, true, DAY_IN_SECONDS);
            return;
        }
        
        // Create orders from saved checkout data
        $result = $this->create_orders_from_session($checkout_data, $payment_intent_id, $intent);
        
        // Clear cart after successful order creation
        if (function_exists('ptp_cart') && ptp_cart()) {
            ptp_cart()->empty_cart();
        }
        
        // Delete the transient
        delete_transient('ptp_checkout_' . $checkout_session);
        
        // Mark as processed to prevent duplicate processing on refresh
        set_transient('ptp_processed_' . $process_key, true, DAY_IN_SECONDS);
        
        // Redirect to clean thank you page
        $redirect_url = home_url('/thank-you/');
        if (!empty($result['order_id'])) {
            $redirect_url = add_query_arg('order', $result['order_id'], $redirect_url);
        }
        if (!empty($result['booking_id'])) {
            $redirect_url = add_query_arg('booking', $result['booking_id'], $redirect_url);
        }
        
        ptp_log('[PTP Return] Redirecting to: ' . $redirect_url);
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Create orders from saved checkout session data
     * v114: Uses stored cart items instead of relying on ptp_cart()
     */
    /**
     * v211: Public wrapper for create_orders_from_session
     * Called from thank-you page after Stripe payment redirect
     */
    public function create_orders_from_session_public($data, $payment_intent_id, $intent = array()) {
        return $this->create_orders_from_session($data, $payment_intent_id, $intent);
    }
    
    private function create_orders_from_session($data, $payment_intent_id, $intent) {
        global $wpdb;
        
        $result = array('order_id' => null, 'booking_id' => null);
        
        $parent_data = $data['parent_data'] ?? array();
        $camper_data = $data['camper_data'] ?? array();
        $player_id = $data['player_id'] ?? 0;
        $trainer_id = $data['trainer_id'] ?? 0;
        $total = $data['final_total'] ?? $data['total'] ?? 0;
        
        // v10.3.6: Get cart items from saved data, not ptp_cart()
        $has_native_cart = $data['has_native_cart'] ?? $data['has_woo'] ?? false;
        $cart_items_data = $data['cart_items'] ?? array();
        
        ptp_log('[PTP Return v10.3.6] Creating orders - Total: $' . $total . ', Trainer: ' . $trainer_id . ', Cart items: ' . count($cart_items_data));
        
        // Get or create user
        $user_id = $data['user_id'] ?? get_current_user_id();
        if (!$user_id && !empty($parent_data['email'])) {
            $user = get_user_by('email', $parent_data['email']);
            if ($user) {
                $user_id = $user->ID;
            } else {
                // Create new user
                $username = $this->generate_unique_username($parent_data['email']);
                $password = wp_generate_password();
                $user_id = wp_create_user($username, $password, $parent_data['email']);
                
                if (!is_wp_error($user_id)) {
                    wp_update_user(array(
                        'ID' => $user_id,
                        'first_name' => $parent_data['first_name'],
                        'last_name' => $parent_data['last_name'],
                        'display_name' => $parent_data['first_name'] . ' ' . $parent_data['last_name'],
                    ));
                    update_user_meta($user_id, 'billing_phone', $parent_data['phone']);
                    update_user_meta($user_id, 'billing_first_name', $parent_data['first_name']);
                    update_user_meta($user_id, 'billing_last_name', $parent_data['last_name']);
                    update_user_meta($user_id, 'billing_email', $parent_data['email']);
                    ptp_log('[PTP Return v114] Created new user: ' . $user_id);
                }
            }
        }
        
        // Get or create parent record
        $parent_id = null;
        $emergency_data = $data['emergency_data'] ?? array();
        $medical_info = sanitize_textarea_field($data['medical_info'] ?? '');
        
        if (!empty($parent_data['email'])) {
            // First try to find by user_id if logged in
            if ($user_id > 0) {
                $parent_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                    $user_id
                ));
            }
            
            // If not found, try by email
            if (!$parent_id) {
                $parent_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE email = %s",
                    $parent_data['email']
                ));
            }
            
            if (!$parent_id) {
                // Create new parent record
                $insurance_data = $data['insurance_data'] ?? array();
                $parent_insert = array(
                    'user_id' => $user_id,
                    'first_name' => $parent_data['first_name'],
                    'last_name' => $parent_data['last_name'],
                    'display_name' => $parent_data['first_name'] . ' ' . $parent_data['last_name'],
                    'email' => $parent_data['email'],
                    'phone' => $parent_data['phone'],
                    'emergency_name' => $emergency_data['name'] ?? '',
                    'emergency_phone' => $emergency_data['phone'] ?? '',
                    'emergency_relation' => $emergency_data['relation'] ?? '',
                    'medical_info' => $medical_info,
                    'created_at' => current_time('mysql'),
                );
                // v213: Persist insurance data
                if (!empty($insurance_data['provider'])) {
                    $parent_insert['insurance_provider'] = $insurance_data['provider'];
                    $parent_insert['insurance_policy'] = $insurance_data['policy'] ?? '';
                    $parent_insert['insurance_group'] = $insurance_data['group'] ?? '';
                }
                $wpdb->insert($wpdb->prefix . 'ptp_parents', $parent_insert);
                $parent_id = $wpdb->insert_id;
                ptp_log('[PTP Return v117.2.18] Created parent record: ' . $parent_id . ' for email: ' . $parent_data['email']);
            } else {
                // Update existing parent with latest info (including email if missing)
                $update_data = array(
                    'first_name' => $parent_data['first_name'],
                    'last_name' => $parent_data['last_name'],
                    'display_name' => $parent_data['first_name'] . ' ' . $parent_data['last_name'],
                    'email' => $parent_data['email'],  // Always update email
                    'phone' => $parent_data['phone'],
                );
                
                // Only update emergency contact if provided
                if (!empty($emergency_data['name'])) {
                    $update_data['emergency_name'] = $emergency_data['name'];
                    $update_data['emergency_phone'] = $emergency_data['phone'] ?? '';
                    $update_data['emergency_relation'] = $emergency_data['relation'] ?? '';
                }
                if (!empty($medical_info)) {
                    $update_data['medical_info'] = $medical_info;
                }
                // v213: Persist insurance data
                $insurance_data = $data['insurance_data'] ?? array();
                if (!empty($insurance_data['provider'])) {
                    $update_data['insurance_provider'] = $insurance_data['provider'];
                    $update_data['insurance_policy'] = $insurance_data['policy'] ?? '';
                    $update_data['insurance_group'] = $insurance_data['group'] ?? '';
                }
                
                $wpdb->update(
                    $wpdb->prefix . 'ptp_parents',
                    $update_data,
                    array('id' => $parent_id)
                );
                ptp_log('[PTP Return v117.2.18] Updated parent record: ' . $parent_id . ' with email: ' . $parent_data['email']);
            }
        }
        
        // v193: Save attribution source (only set once — don't overwrite on returning families)
        if ($user_id > 0 && !empty($data['how_found_us']) && !get_user_meta($user_id, 'ptp_how_found_us', true)) {
            $source = $data['how_found_us'];
            if ($source === 'other' && !empty($data['how_found_us_other'])) {
                $source = 'other: ' . $data['how_found_us_other'];
            }
            update_user_meta($user_id, 'ptp_how_found_us', sanitize_text_field($source));
            ptp_log('[PTP Checkout v193] Attribution saved for user ' . $user_id . ': ' . $source);
        }
        
        // v193: Create/activate referral code for this parent (for sharing with friends)
        if ($user_id > 0 && !empty($data['generated_referral_code'])) {
            $gen_code = strtoupper(sanitize_text_field($data['generated_referral_code']));
            $ref_table = $wpdb->prefix . 'ptp_referral_codes';
            $ref_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$ref_table'") === $ref_table;
            
            if ($ref_table_exists) {
                // Check if user already has a referral code
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT code FROM $ref_table WHERE user_id = %d LIMIT 1", $user_id
                ));
                
                if (!$existing) {
                    // Check for code collision
                    $collision = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $ref_table WHERE code = %s", $gen_code
                    ));
                    if ($collision) {
                        $gen_code = $gen_code . substr(md5(uniqid()), 0, 2);
                    }
                    
                    $wpdb->insert($ref_table, array(
                        'user_id'    => $user_id,
                        'code'       => $gen_code,
                        'discount'   => 15.00,
                        'type'       => 'referral',
                        'times_used' => 0,
                        'max_uses'   => 0,
                        'status'     => 'active',
                        'created_at' => current_time('mysql'),
                    ));
                    ptp_log('[PTP Checkout v193] Referral code created for user ' . $user_id . ': ' . $gen_code);
                    
                    // Also store on user meta for easy lookup
                    update_user_meta($user_id, 'ptp_referral_code', $gen_code);
                } else {
                    ptp_log('[PTP Checkout v193] User ' . $user_id . ' already has referral code: ' . $existing);
                }
            } else {
                // Table doesn't exist — create it
                $charset = $wpdb->get_charset_collate();
                $wpdb->query("CREATE TABLE IF NOT EXISTS $ref_table (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    user_id bigint(20) unsigned NOT NULL,
                    code varchar(50) NOT NULL,
                    discount decimal(10,2) DEFAULT 15.00,
                    type varchar(20) DEFAULT 'referral',
                    times_used int DEFAULT 0,
                    max_uses int DEFAULT 0,
                    status varchar(20) DEFAULT 'active',
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY code (code),
                    KEY user_id (user_id)
                ) $charset;");
                
                $wpdb->insert($ref_table, array(
                    'user_id'    => $user_id,
                    'code'       => $gen_code,
                    'discount'   => 15.00,
                    'type'       => 'referral',
                    'times_used' => 0,
                    'max_uses'   => 0,
                    'status'     => 'active',
                    'created_at' => current_time('mysql'),
                ));
                update_user_meta($user_id, 'ptp_referral_code', $gen_code);
                ptp_log('[PTP Checkout v193] Created referral_codes table and code for user ' . $user_id . ': ' . $gen_code);
            }
        }
        
        // Get camper name
        $camper_name = trim(($camper_data['first_name'] ?? '') . ' ' . ($camper_data['last_name'] ?? ''));
        if (empty($camper_name) && $player_id && $parent_id) {
            $player = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}ptp_players WHERE id = %d AND parent_id = %d",
                $player_id, $parent_id
            ));
            if ($player) {
                $camper_name = trim($player->first_name . ' ' . $player->last_name);
            }
        }
        // v114.1: Fallback to Stripe metadata
        if (empty($camper_name) && !empty($intent['metadata']['camper_name'])) {
            $camper_name = $intent['metadata']['camper_name'];
        }
        if (empty($camper_name)) {
            $camper_name = $parent_data['first_name'] ?? 'Camper';
        }
        
        // =========================================================
        // v164: UNIFIED CHECKOUT - CREATE CAMP ORDER FOR ALL CAMP ITEMS
        // All checkouts now use /ptp-checkout/ (camps, training, or mixed)
        // =========================================================
        $camp_items_for_order = array();
        $medical_info = sanitize_textarea_field($data['medical_info'] ?? '');
        $sibling_data = $data['sibling_data'] ?? null;
        
        if (!empty($cart_items_data)) {
            foreach ($cart_items_data as $item) {
                if (($item['item_type'] ?? '') === 'camp') {
                    // Primary camper item
                    $camp_items_for_order[] = array(
                        'product_id' => $item['product_id'],
                        'stripe_product_id' => $item['stripe_product'] ?? '',
                        'stripe_price_id' => $item['stripe_price'] ?? '',
                        'camp_name' => $item['name'],
                        'camp_dates' => $item['date'] ?? '',
                        'camp_location' => $item['location'] ?? '',
                        'camp_time' => $item['time'] ?? '9AM - 3PM',
                        'base_price' => floatval($item['price']),
                        'camper_first_name' => $camper_data['first_name'] ?? '',
                        'camper_last_name' => $camper_data['last_name'] ?? '',
                        'camper_dob' => $camper_data['dob'] ?? null,
                        'camper_shirt_size' => $camper_data['shirt_size'] ?? '',
                        'camper_team' => $camper_data['team'] ?? '',
                        'camper_skill_level' => $camper_data['skill_level'] ?? '',
                        'medical_conditions' => $medical_info,
                        'waiver_signed' => 1,
                        // v168: Instagram announcement photo
                        'announcement_photo_url' => $data['announcement_photo_url'] ?? '',
                        'instagram_handle' => $data['instagram_handle'] ?? '',
                        'photo_consent' => !empty($data['instagram_photo_consent']) ? 1 : 0,
                    );
                    
                    // v226: Sibling gets their own camp order item for each camp week
                    if (!empty($sibling_data) && !empty($sibling_data['first_name'])) {
                        $camp_items_for_order[] = array(
                            'product_id' => $item['product_id'],
                            'stripe_product_id' => $item['stripe_product'] ?? '',
                            'stripe_price_id' => $item['stripe_price'] ?? '',
                            'camp_name' => $item['name'],
                            'camp_dates' => $item['date'] ?? '',
                            'camp_location' => $item['location'] ?? '',
                            'camp_time' => $item['time'] ?? '9AM - 3PM',
                            'base_price' => floatval($item['price']),
                            'camper_first_name' => $sibling_data['first_name'],
                            'camper_last_name' => $sibling_data['last_name'] ?? ($camper_data['last_name'] ?? ''),
                            'camper_dob' => $sibling_data['dob'] ?? null,
                            'camper_shirt_size' => $sibling_data['shirt_size'] ?? '',
                            'camper_team' => $camper_data['team'] ?? '',
                            'camper_skill_level' => $camper_data['skill_level'] ?? '',
                            'medical_conditions' => $medical_info,
                            'waiver_signed' => 1,
                            'is_sibling' => true,
                            'announcement_photo_url' => $data['announcement_photo_url'] ?? '',
                            'instagram_handle' => $data['instagram_handle'] ?? '',
                            'photo_consent' => !empty($data['instagram_photo_consent']) ? 1 : 0,
                        );
                    }
                }
            }
        }
        
        // v164: Unified checkout handles all orders (camps, training, or mixed)
        if (!empty($camp_items_for_order) && class_exists('PTP_Camp_Orders')) {
            ptp_log('[PTP Return v157.3] Creating camp order with ' . count($camp_items_for_order) . ' camp items');
            
            $camp_order_result = PTP_Camp_Orders::create_order(array(
                'user_id' => $user_id,
                'parent_id' => $parent_id,
                'billing_first_name' => $parent_data['first_name'] ?? '',
                'billing_last_name' => $parent_data['last_name'] ?? '',
                'billing_email' => $parent_data['email'] ?? '',
                'billing_phone' => $parent_data['phone'] ?? '',
                'emergency_name' => $emergency_data['name'] ?? '',
                'emergency_phone' => $emergency_data['phone'] ?? '',
                'emergency_relation' => $emergency_data['relation'] ?? '',
                'items' => $camp_items_for_order,
                'referral_code' => $data['referral_code'] ?? '',
                'coupon_code' => $data['coupon_code'] ?? '',
                'coupon_discount' => $data['coupon_discount'] ?? 0,
                'coupon_id' => $data['coupon_id'] ?? 0,
                'notes' => 'Created via unified checkout. PaymentIntent: ' . $payment_intent_id . (!empty($data['how_found_us']) ? '. Found via: ' . ($data['how_found_us'] === 'other' ? ($data['how_found_us_other'] ?: 'Other') : $data['how_found_us']) : ''),
                'how_found_us' => $data['how_found_us'] ?? '',
                'how_found_us_other' => $data['how_found_us_other'] ?? '',
            ));
            
            if (!is_wp_error($camp_order_result) && !empty($camp_order_result['order_id'])) {
                $camp_order_id = $camp_order_result['order_id'];
                $result['order_id'] = $camp_order_id;
                
                // Mark order as paid
                PTP_Camp_Orders::update_order($camp_order_id, array(
                    'status' => 'completed',
                    'payment_status' => 'paid',
                    'payment_method' => 'stripe',
                    'stripe_payment_intent' => $payment_intent_id,
                    'paid_at' => current_time('mysql'),
                ));
                
                ptp_log('[PTP Return v157.3] Camp order created: #' . $camp_order_id . ' (Order Number: ' . $camp_order_result['order_number'] . ')');
                
                // Send confirmation email
                if (class_exists('PTP_Camp_Emails') && method_exists('PTP_Camp_Emails', 'send_order_confirmation')) {
                    PTP_Camp_Emails::send_order_confirmation($camp_order_id);
                    ptp_log('[PTP Return v157.3] Camp confirmation email sent for order #' . $camp_order_id);
                }
                
                // v168: Create social announcement entry for Instagram opt-ins
                if (!empty($data['announcement_photo_url']) && !empty($data['instagram_photo_consent'])) {
                    global $wpdb;
                    $announcements_table = $wpdb->prefix . 'ptp_social_announcements';
                    
                    // Check if announcements table exists
                    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$announcements_table'") === $announcements_table;
                    
                    if ($table_exists) {
                        foreach ($camp_items_for_order as $camp_item) {
                            $camper_name = trim(($camp_item['camper_first_name'] ?? '') . ' ' . ($camp_item['camper_last_name'] ?? ''));
                            
                            $wpdb->insert($announcements_table, array(
                                'order_id' => $camp_order_id,
                                'instagram_handle' => $data['instagram_handle'] ?? '',
                                'camper_name' => $camper_name,
                                'camp_name' => $camp_item['camp_name'] ?? '',
                                'camp_location' => $camp_item['camp_location'] ?? '',
                                'camp_dates' => $camp_item['camp_dates'] ?? '',
                                'parent_email' => $parent_data['email'] ?? '',
                                'photo_url' => $data['announcement_photo_url'],
                                'status' => 'pending',
                                'created_at' => current_time('mysql'),
                            ));
                            
                            ptp_log('[PTP Return v168] Social announcement created for camper: ' . $camper_name . ' (Order #' . $camp_order_id . ')');
                        }
                    }
                }
                
                // v235: Save instagram/photo back to player record for next checkout
                if ($player_id && (!empty($data['instagram_handle']) || !empty($data['announcement_photo_url']))) {
                    $ig_update = array();
                    if (!empty($data['instagram_handle'])) $ig_update['instagram_handle'] = sanitize_text_field($data['instagram_handle']);
                    if (!empty($data['announcement_photo_url'])) $ig_update['player_photo_url'] = esc_url_raw($data['announcement_photo_url']);
                    if (!empty($ig_update)) {
                        $wpdb->update($wpdb->prefix . 'ptp_players', $ig_update, array('id' => $player_id));
                        ptp_log('[PTP Checkout v235] Saved instagram data to player #' . $player_id);
                    }
                }
            } else {
                $error_msg = is_wp_error($camp_order_result) ? $camp_order_result->get_error_message() : 'Unknown error';
                ptp_log('[PTP Return v157.3] Failed to create camp order: ' . $error_msg);
            }
            
            // Clear native cart
            if (function_exists('ptp_cart')) {
                ptp_cart()->empty_cart();
            }
        }
        
        // Create PTP Native order from stored cart items (legacy WooCommerce-style - DISABLED)
        // v117.2.12: Check if this is a training-only checkout (no WC products needed)
        $is_training_only = ($trainer_id > 0) && ((($data['training_total'] ?? 0) > 0) || !empty($data['is_free_session'])) && empty($cart_items_data);
        
        // Get total from multiple sources as fallback
        if ($total <= 0 && !empty($intent['amount'])) {
            $total = floatval($intent['amount']) / 100; // Stripe amount is in cents
            ptp_log('[PTP Return v114.1] Using Stripe amount as total: $' . $total);
        }
        
        // v117.2.12: Only create WC order for camp/product purchases, NOT for training-only
        if (false && $total > 0 && !$is_training_only) {
            ptp_log('[PTP Return v10.3.6] Creating PTP Native order - has_native_cart=' . ($has_native_cart ? 'true' : 'false') . ', items=' . count($cart_items_data) . ', total=$' . $total);
            
            $order = PTP_Native_Order_Manager::create_order(array(
                'customer_id' => $user_id,
                'status' => 'pending',
            ));
            
            if (!is_wp_error($order)) {
                // Add items from saved cart data if available
                if (!empty($cart_items_data)) {
                    foreach ($cart_items_data as $item_data) {
                        $product_id = $item_data['product_id'];
                        $product = ptp_get_camp_product($product_id);
                        
                        if ($product) {
                            $item_id = $order->add_product($product, $item_data['quantity'], array(
                                'subtotal' => $item_data['line_subtotal'],
                                'total' => $item_data['line_total'],
                            ));
                            
                            if ($item_id) {
                                ptp_add_order_item_meta($item_id, 'Player Name', $camper_name);
                                if (!empty($camper_data['dob'])) {
                                    $age = (new DateTime($camper_data['dob']))->diff(new DateTime())->y;
                                    ptp_add_order_item_meta($item_id, 'Player Age', $age);
                                }
                                ptp_add_order_item_meta($item_id, 'T-Shirt Size', $camper_data['shirt_size'] ?? '');
                                ptp_add_order_item_meta($item_id, '_player_id', $player_id);
                            }
                        }
                    }
                } else {
                    // v114.1: No cart items - add a line item for the payment
                    ptp_log('[PTP Return v114.1] No cart items - creating order with fee line item');
                    $fee = ptp_create_order_fee();
                    $fee->set_name('PTP Camp Registration - ' . $camper_name);
                    $fee->set_total($total);
                    $order->add_item($fee);
                }
                
                // Set billing details
                $order->set_billing_first_name($parent_data['first_name'] ?? '');
                $order->set_billing_last_name($parent_data['last_name'] ?? '');
                $order->set_billing_email($parent_data['email'] ?? '');
                $order->set_billing_phone($parent_data['phone'] ?? '');
                
                // Add-ons: Before/After Care
                if (!empty($data['before_after_care']) && ($data['care_amount'] ?? 0) > 0) {
                    $care_fee = ptp_create_order_fee();
                    $care_fee->set_name('Before & After Care');
                    $care_fee->set_total($data['care_amount']);
                    $order->add_item($care_fee);
                    $order->update_meta_data('_before_after_care', 'yes');
                    $order->update_meta_data('_before_after_care_amount', $data['care_amount']);
                }
                
                // Add-ons: Upgrade Pack
                if (!empty($data['upgrade_selected']) && ($data['upgrade_amount'] ?? 0) > 0) {
                    $upgrade_labels = array(
                        '2pack' => '2-Camp Pack (+1 Camp)',
                        '3pack' => '3-Camp Pack (+2 Camps)',
                        'allaccess' => 'All-Access Pass',
                    );
                    $upgrade_label = $upgrade_labels[$data['upgrade_selected']] ?? 'Camp Pack Upgrade';
                    
                    $upgrade_fee = ptp_create_order_fee();
                    $upgrade_fee->set_name($upgrade_label);
                    $upgrade_fee->set_total($data['upgrade_amount']);
                    $order->add_item($upgrade_fee);
                    $order->update_meta_data('_upgrade_pack', $data['upgrade_selected']);
                    $order->update_meta_data('_upgrade_amount', $data['upgrade_amount']);
                    
                    if (!empty($data['upgrade_camps'])) {
                        $order->update_meta_data('_upgrade_camp_ids', $data['upgrade_camps']);
                    }
                }
                
                // Add-ons: Referral discount
                if (!empty($data['referral_code']) && ($data['referral_discount'] ?? 0) > 0) {
                    $referral_fee = ptp_create_order_fee();
                    $referral_fee->set_name('Referral Discount (' . $data['referral_code'] . ')');
                    $referral_fee->set_total(-$data['referral_discount']);
                    $order->add_item($referral_fee);
                    $order->update_meta_data('_referral_code', $data['referral_code']);
                    $order->update_meta_data('_referral_discount', $data['referral_discount']);
                    if (!empty($data['referral_id'])) {
                        $order->update_meta_data('_referral_id', $data['referral_id']);
                    }
                }
                
                // v175: Coupon/Promo code discount
                if (!empty($data['coupon_code']) && ($data['coupon_discount'] ?? 0) > 0) {
                    $coupon_fee = ptp_create_order_fee();
                    $coupon_fee->set_name('Promo Code (' . $data['coupon_code'] . ')');
                    $coupon_fee->set_total(-$data['coupon_discount']);
                    $order->add_item($coupon_fee);
                    $order->update_meta_data('_coupon_code', $data['coupon_code']);
                    $order->update_meta_data('_coupon_discount', $data['coupon_discount']);
                    if (!empty($data['coupon_id'])) {
                        $order->update_meta_data('_coupon_id', $data['coupon_id']);
                    }
                    if (!empty($data['free_code_id'])) {
                        $order->update_meta_data('_free_code_id', $data['free_code_id']);
                    }
                }
                
                // Add-ons: World Cup Jersey (optional)
                if (!empty($data['jersey_added'])) {
                    $jersey_fee = ptp_create_order_fee();
                    $jersey_fee->set_name('World Cup 2026 x PTP Jersey');
                    $jersey_fee->set_total(50);
                    $order->add_item($jersey_fee);
                }
                
                // Set payment info
                $order->set_payment_method('stripe');
                $order->set_payment_method_title('Credit Card');
                $order->set_transaction_id($payment_intent_id);
                
                // Order meta
                $order->update_meta_data('_stripe_payment_intent', $payment_intent_id);
                $order->update_meta_data('_player_id', $player_id);
                $order->update_meta_data('_player_name', $camper_name);
                $order->update_meta_data('_ptp_waiver_accepted', 'yes');
                $order->update_meta_data('_ptp_waiver_date', current_time('mysql'));
                
                if (!empty($data['emergency_data'])) {
                    $order->update_meta_data('_ptp_emergency_name', $data['emergency_data']['name'] ?? '');
                    $order->update_meta_data('_ptp_emergency_phone', $data['emergency_data']['phone'] ?? '');
                    $order->update_meta_data('_ptp_emergency_relationship', $data['emergency_data']['relation'] ?? '');
                }
                
                if (!empty($data['medical_info'])) {
                    $order->update_meta_data('_ptp_medical_info', $data['medical_info']);
                }
                
                // Camper data as array for compatibility
                $campers = array(array(
                    'full_name' => $camper_name,
                    'first_name' => $camper_data['first_name'] ?? '',
                    'last_name' => $camper_data['last_name'] ?? '',
                    'age' => !empty($camper_data['dob']) ? (new DateTime($camper_data['dob']))->diff(new DateTime())->y : '',
                    'shirt_size' => $camper_data['shirt_size'] ?? '',
                    'team' => $camper_data['team'] ?? '',
                    'skill_level' => $camper_data['skill_level'] ?? '',
                ));
                $order->update_meta_data('_ptp_campers', $campers);
                
                $order->calculate_totals();
                $order->payment_complete($payment_intent_id);
                $order->add_order_note('Paid via PTP Checkout (redirect return). PaymentIntent: ' . $payment_intent_id);
                $order->save();
                
                $result['order_id'] = $order->get_id();
                
                // v175: Fire action for coupon/referral tracking
                do_action('ptp_checkout_completed', $data, array(
                    'order_id' => $order->get_id(),
                    'payment_intent_id' => $payment_intent_id,
                ));
                
                // Clear cart if it exists
                if (false && ptp_cart()) {
                    ptp_cart()->clear();
                }
                
                ptp_log('[PTP Return v114.1] PTP Native order created: ' . $order->get_id());
                
                // v114.1: FORCE send confirmation email directly via wp_mail
                $customer_email = $order->get_billing_email();
                if (!empty($customer_email)) {
                    $site_name = ptp_email_brand('company');
                    $order_total = $order->get_total();
                    $order_number = $order->get_order_number();
                    $logo_url = ptp_email_brand('logo_url');
                    
                    // Get order items for email
                    $items_html = '';
                    foreach ($order->get_items() as $item) {
                        $items_html .= '<tr>
                            <td style="padding: 12px; border-bottom: 1px solid #eee; font-family: Inter, Arial, sans-serif;">' . esc_html($item->get_name()) . '</td>
                            <td style="padding: 12px; border-bottom: 1px solid #eee; text-align: right; font-family: Inter, Arial, sans-serif; font-weight: 600;">$' . number_format($item->get_total(), 2) . '</td>
                        </tr>';
                    }
                    foreach ($order->get_items('fee') as $fee) {
                        $items_html .= '<tr>
                            <td style="padding: 12px; border-bottom: 1px solid #eee; font-family: Inter, Arial, sans-serif;">' . esc_html($fee->get_name()) . '</td>
                            <td style="padding: 12px; border-bottom: 1px solid #eee; text-align: right; font-family: Inter, Arial, sans-serif; font-weight: 600;">$' . number_format($fee->get_total(), 2) . '</td>
                        </tr>';
                    }
                    
                    $headers = array(
                        'Content-Type: text/html; charset=UTF-8',
                        'From: ' . ptp_email_brand('from_training'),
                        'Reply-To: ' . ptp_email_brand('from_email'),
                    );
                    
                    $subject = '⚽ You\'re In! Order #' . $order_number . ' Confirmed';
                    $message = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; background: #ffffff; border: 2px solid #0A0A0A;">
                    <!-- Header -->
                    <tr>
                        <td style="background: #0A0A0A; padding: 25px; text-align: center;">
                            <img src="' . $logo_url . '" alt="PTP Soccer Camps" style="max-width: 180px; height: auto;">
                        </td>
                    </tr>
                    
                    <!-- Gold Banner -->
                    <tr>
                        <td style="background: #FCB900; padding: 20px; text-align: center;">
                            <h1 style="margin: 0; font-family: Oswald, Arial, sans-serif; font-size: 28px; font-weight: 700; color: #0A0A0A; text-transform: uppercase; letter-spacing: 1px;">YOU\'RE IN! ⚽</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 35px 30px;">
                            <p style="font-family: Inter, Arial, sans-serif; font-size: 16px; color: #333; margin: 0 0 20px;">
                                Hi <strong>' . esc_html($order->get_billing_first_name()) . '</strong>,
                            </p>
                            <p style="font-family: Inter, Arial, sans-serif; font-size: 16px; color: #333; margin: 0 0 25px;">
                                Thanks for signing up! <strong>' . esc_html($camper_name) . '</strong> is registered and ready to train with the pros.
                            </p>
                            
                            <!-- Order Box -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9f9f9; border: 2px solid #0A0A0A; margin-bottom: 25px;">
                                <tr>
                                    <td style="background: #0A0A0A; padding: 12px 15px;">
                                        <span style="font-family: Oswald, Arial, sans-serif; font-size: 14px; font-weight: 600; color: #FCB900; text-transform: uppercase; letter-spacing: 1px;">ORDER #' . $order_number . '</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 0;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            ' . $items_html . '
                                            <tr style="background: #FCB900;">
                                                <td style="padding: 15px; font-family: Oswald, Arial, sans-serif; font-size: 16px; font-weight: 700; text-transform: uppercase;">TOTAL</td>
                                                <td style="padding: 15px; text-align: right; font-family: Oswald, Arial, sans-serif; font-size: 20px; font-weight: 700;">$' . number_format($order_total, 2) . '</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Player Info -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                                <tr>
                                    <td style="font-family: Oswald, Arial, sans-serif; font-size: 12px; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: 1px; padding-bottom: 8px;">PLAYER</td>
                                </tr>
                                <tr>
                                    <td style="font-family: Inter, Arial, sans-serif; font-size: 18px; font-weight: 600; color: #0A0A0A;">' . esc_html($camper_name) . '</td>
                                </tr>
                            </table>
                            
                            <!-- What\'s Next -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background: #0A0A0A; padding: 20px; margin-bottom: 25px;">
                                <tr>
                                    <td>
                                        <p style="font-family: Oswald, Arial, sans-serif; font-size: 14px; font-weight: 600; color: #FCB900; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 10px;">WHAT\'S NEXT?</p>
                                        <p style="font-family: Inter, Arial, sans-serif; font-size: 14px; color: #ffffff; margin: 0;">
                                            We\'ll send camp details and check-in instructions before your session. Questions? Just reply to this email.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="font-family: Inter, Arial, sans-serif; font-size: 14px; color: #666; margin: 0;">
                                See you on the field! 🏆
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background: #0A0A0A; padding: 25px; text-align: center;">
                            <p style="font-family: Inter, Arial, sans-serif; font-size: 12px; color: #888; margin: 0 0 10px;">
                                <a href="' . esc_url(home_url()) . '" style="color: #FCB900; text-decoration: none;">' . esc_html(str_replace(array('https://', 'http://'), '', home_url())) . '</a>
                            </p>
                            <p style="font-family: Inter, Arial, sans-serif; font-size: 11px; color: #666; margin: 0;">
                                Players Teaching Players | ' . date('Y') . '
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
                    
                    $sent = wp_mail($customer_email, $subject, $message, $headers);
                    ptp_log('[PTP Return v114.1] Direct confirmation email to ' . $customer_email . ': ' . ($sent ? 'SENT' : 'FAILED'));
                    
                    // Also notify admin
                    $admin_email = get_option('admin_email');
                    $admin_subject = '🎯 New Order #' . $order_number . ' - $' . number_format($order_total, 2) . ' - ' . esc_html($camper_name);
                    $admin_message = '
                    <div style="font-family: Inter, Arial, sans-serif; max-width: 500px;">
                        <div style="background: #FCB900; padding: 15px; border: 2px solid #0A0A0A;">
                            <h2 style="margin: 0; font-family: Oswald, Arial, sans-serif; text-transform: uppercase;">New Order #' . $order_number . '</h2>
                        </div>
                        <div style="padding: 20px; background: #f9f9f9; border: 2px solid #0A0A0A; border-top: none;">
                            <p><strong>Customer:</strong> ' . esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . '</p>
                            <p><strong>Email:</strong> ' . esc_html($customer_email) . '</p>
                            <p><strong>Phone:</strong> ' . esc_html($order->get_billing_phone()) . '</p>
                            <p><strong>Player:</strong> ' . esc_html($camper_name) . '</p>
                            <p style="font-size: 24px; font-weight: bold; color: #0A0A0A;">Total: $' . number_format($order_total, 2) . '</p>
                            <p><a href="' . admin_url('post.php?post=' . $order->get_id() . '&action=edit') . '" style="display: inline-block; background: #0A0A0A; color: #FCB900; padding: 12px 25px; text-decoration: none; font-weight: bold; text-transform: uppercase;">View Order</a></p>
                        </div>
                    </div>';
                    
                    wp_mail($admin_email, $admin_subject, $admin_message, $headers);
                    ptp_log('[PTP Return v114.1] Admin notification sent to ' . $admin_email);
                } else {
                    ptp_log('[PTP Return v114.1] WARNING: No billing email on order - cannot send confirmation');
                }
                
                // Also try PTP Native native emails as backup
                try {
                    if (false) {
                        // WC emails removed;
                        $emails = $ptp_emails->get_emails();
                        if (isset($emails['PTP_Customer_Email'])) {
                            $emails['PTP_Customer_Email']->trigger($order->get_id(), $order);
                            ptp_log('[PTP Return v114.1] WC Processing email triggered');
                        }
                        if (isset($emails['PTP_Admin_Email'])) {
                            $emails['PTP_Admin_Email']->trigger($order->get_id(), $order);
                            ptp_log('[PTP Return v114.1] WC New Order email triggered');
                        }
                    }
                } catch (Exception $e) {
                    ptp_log('[PTP Return v114.1] WC Email error: ' . $e->getMessage());
                }
            } else {
                ptp_log('[PTP Return v114.1] Failed to create WC order: ' . $order->get_error_message());
                
                // v114.1: Fallback email when WC order creation fails
                if (!empty($parent_data['email'])) {
                    // Send custom confirmation email
                    $admin_email = get_option('admin_email');
                    $headers = array('Content-Type: text/html; charset=UTF-8');
                    
                    // Customer email
                    $customer_subject = 'Your PTP Soccer Camp Order Confirmation';
                    $customer_message = sprintf(
                        "<p>Hi %s,</p>
                        <p>Thank you for your order! Your camp reservation for %s has been confirmed.</p>
                        <p><strong>Order Total: $%.2f</strong></p>
                        <p>You will receive further instructions soon.</p>
                        <p>- PTP Soccer Camps Team</p>",
                        esc_html($parent_data['first_name']),
                        esc_html($camper_name),
                        floatval($total)
                    );
                    
                    wp_mail($parent_data['email'], $customer_subject, $customer_message, $headers);
                    ptp_log('[PTP Return v114.1] Fallback confirmation email sent to: ' . $parent_data['email']);
                    
                    // Admin notification
                    $admin_subject = '[PTP] Order Failed - Manual Review Needed (' . $camper_name . ')';
                    $admin_message = sprintf(
                        "<p>An order failed to create in PTP Native but was logged in PTP system.</p>
                        <p><strong>Customer:</strong> %s (%s)</p>
                        <p><strong>Camper:</strong> %s</p>
                        <p><strong>Amount:</strong> $%.2f</p>
                        <p>Please manually create the order in PTP Native.</p>",
                        esc_html($parent_data['first_name'] . ' ' . $parent_data['last_name']),
                        esc_html($parent_data['email']),
                        esc_html($camper_name),
                        floatval($total)
                    );
                    
                    wp_mail($admin_email, $admin_subject, $admin_message, $headers);
                    ptp_log('[PTP Return v114.1] Admin alert sent to: ' . $admin_email);
                }
            }
        } else {
            ptp_log('[PTP Return v117.2.12] Skipped PTP Native order creation - total=$' . $total . 
                ', ptp_create_order=' . (false ? 'true' : 'false') .
                ', is_training_only=' . ($is_training_only ? 'true' : 'false'));
        }
        
        // Create training booking if trainer selected
        // v117.2.24: Enhanced logging for debugging
        ptp_log('[PTP Return v117.2.24] ===== TRAINING BOOKING CHECK =====');
        ptp_log('[PTP Return v117.2.24] trainer_id: ' . $trainer_id);
        ptp_log('[PTP Return v117.2.24] training_total: ' . ($data['training_total'] ?? 'NOT SET'));
        ptp_log('[PTP Return v117.2.24] training_package: ' . ($data['training_package'] ?? 'NOT SET'));
        ptp_log('[PTP Return v117.2.24] parent_id: ' . ($parent_id ?? 'NOT SET'));
        ptp_log('[PTP Return v117.2.24] parent_email: ' . ($parent_data['email'] ?? 'NOT SET'));
        
        if ($trainer_id > 0 && (($data['training_total'] ?? 0) > 0 || !empty($data['is_free_session']))) {
            // Get or create player record
            $player_id_for_booking = $player_id;
            if (!$player_id_for_booking && $parent_id && !empty($camper_data['first_name'])) {
                $player_name = trim(($camper_data['first_name'] ?? '') . ' ' . ($camper_data['last_name'] ?? ''));
                $wpdb->insert($wpdb->prefix . 'ptp_players', array(
                    'parent_id' => $parent_id,
                    'name' => $player_name,  // Required for SMS queries
                    'first_name' => $camper_data['first_name'],
                    'last_name' => $camper_data['last_name'] ?? '',
                    'dob' => $camper_data['dob'] ?? null,
                    'age' => !empty($camper_data['dob']) ? (int)date_diff(date_create($camper_data['dob']), date_create('today'))->y : null,
                    'shirt_size' => $camper_data['shirt_size'] ?? null,
                    'instagram_handle' => sanitize_text_field($data['instagram_handle'] ?? ''),
                    'player_photo_url' => esc_url_raw($data['announcement_photo_url'] ?? ''),
                    'created_at' => current_time('mysql'),
                ));
                $player_id_for_booking = $wpdb->insert_id;
                ptp_log('[PTP Return v117.2.25] Created player: ' . $player_id_for_booking . ' - ' . $player_name);
            }
            
            // Create booking
            $training_package = $data['training_package'] ?? 'single';
            if (class_exists('PTP_Packages')) {
                $training_package = PTP_Packages::resolve_key($training_package);
                $pkg_def = PTP_Packages::get($training_package);
                $num_sessions = $pkg_def['sessions'];
            } else {
                $sessions = array('single' => 1, 'pack3' => 3, 'pack5' => 5, 'pack10' => 10, '10pack' => 10);
                $num_sessions = $sessions[$training_package] ?? 1;
            }
            // v194: Graduated fee based on parent-trainer session history
            $fee_calc = self::calculate_graduated_fee($trainer_id, $parent_id, ($data['training_total'] ?? 0), $num_sessions);
            $trainer_payout = $fee_calc['trainer_payout'];
            $platform_fee_amount = $fee_calc['platform_fee'];
            
            // Generate booking number
            $booking_number = 'PTP-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            
            // v211: Use the SELECTED training location from checkout (specific park/field)
            // Fall back to trainer's city/state only if no specific location was selected
            $booking_location = '';
            $booking_location_notes = '';
            
            // Priority 1: The specific location selected during booking (e.g. "Villanova Stadium")
            if (!empty($data['session_location'])) {
                $booking_location = $data['session_location'];
                // Build location notes with full address and coordinates
                $notes_parts = array();
                if (!empty($data['session_location_address']) && $data['session_location_address'] !== $data['session_location']) {
                    $notes_parts[] = $data['session_location_address'];
                }
                if (!empty($data['session_location_lat']) && !empty($data['session_location_lng'])) {
                    $notes_parts[] = 'GPS: ' . $data['session_location_lat'] . ', ' . $data['session_location_lng'];
                }
                if ($notes_parts) {
                    $booking_location_notes = implode(' | ', $notes_parts);
                }
            }
            
            // Priority 2: Trainer's generic location from DB
            if (empty($booking_location)) {
                $trainer_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT location, city, state, training_locations FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
                ));
                if ($trainer_record) {
                    if (!empty($trainer_record->location)) {
                        $booking_location = $trainer_record->location;
                    } elseif (!empty($trainer_record->city)) {
                        $booking_location = $trainer_record->city . (!empty($trainer_record->state) ? ', ' . $trainer_record->state : '');
                    }
                    // v230: Fallback to first training_locations entry
                    if (empty($booking_location) && !empty($trainer_record->training_locations)) {
                        $tl = json_decode($trainer_record->training_locations, true);
                        if (!is_array($tl)) $tl = json_decode(wp_unslash($trainer_record->training_locations), true);
                        if (is_array($tl)) {
                            foreach ($tl as $loc) {
                                if (is_array($loc) && !empty($loc['name'])) {
                                    $booking_location = $loc['name'];
                                    if (!empty($loc['address']) && $loc['address'] !== $loc['name']) {
                                        $booking_location_notes = $loc['address'];
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            
            $booking_insert = array(
                'booking_number' => $booking_number,
                'trainer_id' => $trainer_id,
                'parent_id' => $parent_id,
                'player_id' => $player_id_for_booking ?: 0,
                'session_date' => !empty($data['session_date']) 
                    ? (function_exists('ptp_normalize_session_date') ? ptp_normalize_session_date($data['session_date']) : $data['session_date']) 
                    : null,
                'start_time' => !empty($data['session_time']) 
                    ? (function_exists('ptp_normalize_session_time') ? ptp_normalize_session_time($data['session_time']) : $data['session_time']) 
                    : null,
                'location' => $booking_location,
                'location_notes' => $booking_location_notes,
                'status' => 'confirmed',
                'payment_status' => !empty($data['is_free_session']) ? 'free_session' : 'paid',
                'payment_intent_id' => $payment_intent_id,
                'created_at' => current_time('mysql'),
            );
            
            // v122: Check which columns exist and add appropriate data
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings");
            
            // New schema columns
            if (in_array('package_type', $columns)) {
                $booking_insert['package_type'] = $training_package;
            }
            if (in_array('total_sessions', $columns)) {
                $booking_insert['total_sessions'] = $num_sessions;
            }
            if (in_array('sessions_remaining', $columns)) {
                $booking_insert['sessions_remaining'] = $num_sessions;
            }
            if (in_array('total_amount', $columns)) {
                $booking_insert['total_amount'] = $data['training_total'];
            }
            if (in_array('amount_paid', $columns)) {
                $booking_insert['amount_paid'] = $data['training_total'];
            }
            if (in_array('platform_fee', $columns)) {
                $booking_insert['platform_fee'] = $platform_fee_amount;
            }
            if (in_array('trainer_payout', $columns)) {
                $booking_insert['trainer_payout'] = $trainer_payout;
            }
            
            // v225: Override amounts for free sessions
            // Parent pays $0 but trainer still earns — PTP absorbs cost as acquisition spend
            if (!empty($data['is_free_session'])) {
                // Get trainer's rate to calculate proper payout
                $trainer_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT hourly_rate FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
                ));
                $trainer_rate = floatval($trainer_record->hourly_rate ?? 60);
                
                // Calculate trainer earnings using graduated commission schedule
                $free_fee = self::calculate_graduated_fee($trainer_id, $parent_id, $trainer_rate, 1);
                
                $booking_insert['total_amount'] = 0;         // Parent charged $0
                $booking_insert['amount_paid'] = 0;           // Parent paid $0
                $booking_insert['platform_fee'] = 0;          // PTP absorbs full cost
                $booking_insert['trainer_payout'] = $free_fee['trainer_payout']; // Trainer still earns
                $booking_insert['payment_status'] = 'free_session';
                if (in_array('hourly_rate', $columns)) {
                    $booking_insert['hourly_rate'] = $trainer_rate;
                }
                if (in_array('notes', $columns)) {
                    $booking_insert['notes'] = 'Free session via code: ' . ($data['free_app_code'] ?? $data['coupon_code'] ?? '') 
                        . ' | Trainer earns $' . number_format($free_fee['trainer_payout'], 2) 
                        . ' (' . $free_fee['fee_info'] . ')';
                }
                ptp_log(sprintf('[PTP Free Session v225] Trainer #%d earns $%.2f on free session (rate=$%.2f, %s)', 
                    $trainer_id, $free_fee['trainer_payout'], $trainer_rate, $free_fee['fee_info']));
            }
            
            // Old schema columns (for backward compat)
            if (in_array('session_type', $columns)) {
                $booking_insert['session_type'] = $training_package;
            }
            if (in_array('session_count', $columns)) {
                $booking_insert['session_count'] = $num_sessions;
            }
            if (in_array('hourly_rate', $columns)) {
                $booking_insert['hourly_rate'] = round(($data['training_total'] ?? 0) / $num_sessions, 2);
            }
            if (in_array('end_time', $columns) && !empty($data['session_time'])) {
                // Calculate end time (1 hour after start)
                $start = strtotime($data['session_time']);
                if ($start) {
                    $booking_insert['end_time'] = date('H:i:s', $start + 3600);
                }
            }
            
            // Store parent email in guest_email as backup
            if (in_array('guest_email', $columns) && !empty($parent_data['email'])) {
                $booking_insert['guest_email'] = $parent_data['email'];
            }
            
            // v132: Store multi-player data in group_players column
            if (in_array('group_players', $columns) && !empty($data['players_data'])) {
                $booking_insert['group_players'] = json_encode($data['players_data']);
                ptp_log('[PTP Checkout v132] Storing ' . count($data['players_data']) . ' players in group_players');
            }
            
            // v235.5: Store group size
            if (in_array('group_size', $columns)) {
                $booking_insert['group_size'] = intval($data['group_size'] ?? 1);
            }
            
            // v175: Store coupon/referral data for tracking
            if (in_array('coupon_code', $columns) && !empty($data['coupon_code'])) {
                $booking_insert['coupon_code'] = $data['coupon_code'];
                $booking_insert['coupon_discount'] = floatval($data['coupon_discount'] ?? 0);
                if (!empty($data['coupon_id'])) {
                    $booking_insert['coupon_id'] = intval($data['coupon_id']);
                }
                if (!empty($data['free_code_id'])) {
                    $booking_insert['free_code_id'] = intval($data['free_code_id']);
                }
            }
            
            if (in_array('referral_code', $columns) && !empty($data['referral_code'])) {
                $booking_insert['referral_code'] = $data['referral_code'];
                $booking_insert['referral_discount'] = floatval($data['referral_discount'] ?? 0);
                if (!empty($data['referral_id'])) {
                    $booking_insert['referral_id'] = intval($data['referral_id']);
                }
            }
            
            ptp_log('[PTP Return v122] Attempting booking insert with columns: ' . implode(', ', array_keys($booking_insert)));
            
            // v231: Double-booking prevention — check if trainer already has a booking at this date+time
            if (!empty($booking_insert['session_date']) && $booking_insert['session_date'] !== '0000-00-00'
                && !empty($booking_insert['start_time']) && $booking_insert['start_time'] !== '00:00:00') {
                
                $conflict = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_bookings 
                     WHERE trainer_id = %d 
                     AND session_date = %s 
                     AND start_time = %s 
                     AND status NOT IN ('cancelled', 'refunded')
                     LIMIT 1",
                    $booking_insert['trainer_id'],
                    $booking_insert['session_date'],
                    $booking_insert['start_time']
                ));
                
                if ($conflict) {
                    ptp_log(sprintf('[PTP v231] DOUBLE BOOKING BLOCKED: trainer=%d date=%s time=%s conflicts with booking #%d',
                        $booking_insert['trainer_id'], $booking_insert['session_date'], $booking_insert['start_time'], $conflict));
                    
                    $result['error'] = 'This time slot was just booked by another parent. Please select a different time.';
                    $result['conflict_booking_id'] = $conflict;
                    
                    // Still let the payment go through — we'll handle refund/reschedule
                    // But don't create a duplicate booking
                    do_action('ptp_double_booking_prevented', $booking_insert, $conflict);
                    
                    // Return early with error — payment refund handled separately
                    return $result;
                }
            }
            
            $insert_result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $booking_insert);
            
            if ($insert_result === false) {
                ptp_log('[PTP Return v122] BOOKING INSERT FAILED!');
                ptp_log('[PTP Return v122] MySQL Error: ' . $wpdb->last_error);
                ptp_log('[PTP Return v122] Last Query: ' . $wpdb->last_query);
                ptp_log('[PTP Return v122] Insert Data: ' . json_encode($booking_insert));
                
                // Fire hook for debugging
                do_action('ptp_booking_insert_failed', $booking_insert, $wpdb->last_error);
            } else {
                $result['booking_id'] = $wpdb->insert_id;
                ptp_log('[PTP Return v122] Booking created successfully: ' . $result['booking_id']);
            }
            
            ptp_log('[PTP Return v114] Booking created: ' . $result['booking_id']);
            
            // ========================================
            // CREATE ESCROW HOLD - Funds held until session complete
            // v240: Skip escrow for free sessions — no funds to hold
            // ========================================
            if ($result['booking_id'] && class_exists('PTP_Escrow') && empty($data['is_free_session'])) {
                $escrow_id = PTP_Escrow::create_hold(
                    $result['booking_id'],
                    $payment_intent_id,
                    $data['training_total']
                );
                
                if (is_wp_error($escrow_id)) {
                    ptp_log('[PTP Return v117.2.25] Escrow creation failed: ' . $escrow_id->get_error_message());
                } else {
                    ptp_log('[PTP Return v117.2.25] Escrow created: ' . $escrow_id);
                    
                    // Update booking with escrow info
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        array(
                            'escrow_id' => $escrow_id,
                            'funds_held' => 1,
                            'payment_status' => 'paid',
                        ),
                        array('id' => $result['booking_id'])
                    );
                }
            }
            
            // ========================================
            // PACKAGE CREDITS - For multi-session packs
            // ========================================
            if ($num_sessions > 1 && $result['booking_id'] && $parent_id) {
                // Create package credit record (sessions remaining after first one)
                $remaining_sessions = $num_sessions - 1; // First session is booked now
                $price_per_session = round(($data['training_total'] ?? 0) / $num_sessions, 2);
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 year'));
                
                $credit_result = $wpdb->insert(
                    $wpdb->prefix . 'ptp_package_credits',
                    array(
                        'parent_id' => $parent_id,
                        'trainer_id' => $trainer_id,
                        'package_type' => $training_package,
                        'total_credits' => $num_sessions,
                        'remaining' => $remaining_sessions,
                        'price_per_session' => $price_per_session,
                        'total_paid' => $data['training_total'] ?? 0,
                        'payment_intent_id' => $payment_intent_id,
                        'any_trainer' => 1, // v182: credits usable with any active trainer
                        'expires_at' => $expires_at,
                        'status' => 'active',
                        'created_at' => current_time('mysql'),
                    ),
                    array('%d', '%d', '%s', '%d', '%d', '%f', '%f', '%s', '%d', '%s', '%s', '%s')
                );
                
                if ($credit_result) {
                    $credit_id = $wpdb->insert_id;
                    $result['package_credit_id'] = $credit_id;
                    $result['sessions_remaining'] = $remaining_sessions;
                    
                    // Link booking to credit
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        array('package_credit_id' => $credit_id),
                        array('id' => $result['booking_id'])
                    );
                    
                    ptp_log('[PTP Return v117.2.26] Package credit created: ' . $credit_id . ' with ' . $remaining_sessions . ' remaining sessions for trainer ' . $trainer_id);
                } else {
                    ptp_log('[PTP Return v117.2.26] Failed to create package credit: ' . $wpdb->last_error);
                }
            }
            
            // ========================================
            // NOTIFICATIONS - Email & SMS
            // ========================================
            // Notify trainer (handles both email + SMS for trainer and parent)
            $this->notify_trainer($trainer_id, $result['booking_id'], $camper_name);
            
            // Fire hooks for additional integrations (SMS class listens to these)
            do_action('ptp_booking_confirmed', $result['booking_id']);
            do_action('ptp_training_booking_created', $result['booking_id'], $trainer_id, $data);
            do_action('ptp_package_credits_created', $result['package_credit_id'] ?? null, $parent_id, $trainer_id, $num_sessions);
            
            // Check if this is trainer's first booking and fire celebration hook
            $trainer_booking_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d AND status NOT IN ('cancelled', 'rejected')",
                $trainer_id
            ));
            if ($trainer_booking_count == 1) {
                do_action('ptp_trainer_first_booking', $trainer_id, $result['booking_id']);
                ptp_log("[PTP] Trainer #{$trainer_id} first booking! Hook fired.");
            }
            
            // Schedule session reminder (24 hours before)
            if (!empty($data['session_date']) && !empty($data['session_time'])) {
                $session_datetime = strtotime($data['session_date'] . ' ' . $data['session_time']);
                $reminder_time = $session_datetime - (24 * 60 * 60); // 24 hours before
                
                if ($reminder_time > time()) {
                    wp_schedule_single_event($reminder_time, 'ptp_session_reminder', array($result['booking_id']));
                    ptp_log('[PTP Return v117.2.25] Session reminder scheduled for: ' . date('Y-m-d H:i', $reminder_time));
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Create required tables
     */
    public function maybe_create_tables() {
        if (get_option('ptp_checkout_tables_v86') === '1') return;
        
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        // Parents table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_parents (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            address text DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            state varchar(50) DEFAULT NULL,
            zip varchar(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY email (email)
        ) $charset");
        
        // Players/Campers table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_players (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) UNSIGNED NOT NULL,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) DEFAULT NULL,
            dob date DEFAULT NULL,
            age int DEFAULT NULL,
            shirt_size varchar(10) DEFAULT NULL,
            team varchar(255) DEFAULT NULL,
            skill_level varchar(50) DEFAULT NULL,
            position varchar(50) DEFAULT NULL,
            emergency_name varchar(255) DEFAULT NULL,
            emergency_phone varchar(50) DEFAULT NULL,
            emergency_relation varchar(100) DEFAULT NULL,
            medical_info text DEFAULT NULL,
            insurance_provider varchar(255) DEFAULT NULL,
            insurance_policy varchar(100) DEFAULT NULL,
            insurance_group varchar(100) DEFAULT NULL,
            waiver_accepted tinyint(1) DEFAULT 0,
            waiver_accepted_at datetime DEFAULT NULL,
            waiver_ip varchar(45) DEFAULT NULL,
            photo_consent tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY parent_id (parent_id)
        ) $charset");
        
        // Add photo_consent column if it doesn't exist (for existing databases)
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ptp_players LIKE 'photo_consent'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}ptp_players ADD COLUMN photo_consent tinyint(1) DEFAULT 1 AFTER waiver_ip");
        }
        
        // Bookings table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_bookings (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            parent_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED DEFAULT NULL,
            session_date date DEFAULT NULL,
            session_time time DEFAULT NULL,
            location varchar(255) DEFAULT NULL,
            package_type varchar(50) DEFAULT 'single',
            total_sessions int DEFAULT 1,
            sessions_remaining int DEFAULT 1,
            sessions_completed int DEFAULT 0,
            amount_paid decimal(10,2) DEFAULT 0,
            trainer_payout decimal(10,2) DEFAULT 0,
            payment_intent_id varchar(255) DEFAULT NULL,
            status varchar(50) DEFAULT 'pending',
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY parent_id (parent_id),
            KEY status (status)
        ) $charset");
        
        update_option('ptp_checkout_tables_v86', '1');
    }
    
    /**
     * Process checkout
     */
    public function process_checkout() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['ptp_checkout_nonce'] ?? '', 'ptp_checkout')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }
        
        global $wpdb;
        
        // ========================================
        // COLLECT ALL FORM DATA
        // ========================================
        
        $payment_method_id = sanitize_text_field($_POST['payment_method_id'] ?? '');
        $cart_total = floatval($_POST['cart_total'] ?? 0);
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $training_package = sanitize_text_field($_POST['training_package'] ?? 'single');
        $bundle_code = sanitize_text_field($_POST['bundle_code'] ?? '');
        
        // Session booking data
        $session_date = sanitize_text_field($_POST['session_date'] ?? '');
        $session_time = sanitize_text_field($_POST['session_time'] ?? '');
        // v220: Normalize time
        if (!empty($session_time) && function_exists('ptp_normalize_session_time')) {
            $session_time = ptp_normalize_session_time($session_time) ?: $session_time;
        }
        $session_location = sanitize_text_field($_POST['session_location'] ?? '');
        
        // Parent/Guardian info
        $parent_data = array(
            'first_name' => sanitize_text_field($_POST['parent_first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['parent_last_name'] ?? ''),
            'email' => sanitize_email($_POST['parent_email'] ?? ''),
            'phone' => sanitize_text_field($_POST['parent_phone'] ?? ''),
        );
        
        // Camper info
        $player_id = intval($_POST['player_id'] ?? 0);
        $camper_data = array(
            'first_name' => sanitize_text_field($_POST['camper_first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['camper_last_name'] ?? ''),
            'dob' => sanitize_text_field($_POST['camper_dob'] ?? ''),
            'shirt_size' => sanitize_text_field($_POST['camper_shirt_size'] ?? ''),
            'team' => sanitize_text_field($_POST['camper_team'] ?? ''),
            'skill_level' => sanitize_text_field($_POST['camper_skill'] ?? ''),
        );
        
        // Emergency & Medical
        $emergency_data = array(
            'name' => sanitize_text_field($_POST['emergency_name'] ?? ''),
            'phone' => sanitize_text_field($_POST['emergency_phone'] ?? ''),
            'relation' => sanitize_text_field($_POST['emergency_relation'] ?? ''),
        );
        
        $medical_info = sanitize_textarea_field($_POST['medical_info'] ?? '');
        
        // Insurance
        $insurance_data = array(
            'provider' => sanitize_text_field($_POST['insurance_provider'] ?? ''),
            'policy' => sanitize_text_field($_POST['insurance_policy'] ?? ''),
            'group' => sanitize_text_field($_POST['insurance_group'] ?? ''),
        );
        
        // Waiver
        $waiver_accepted = isset($_POST['waiver_accepted']) && $_POST['waiver_accepted'];
        
        // Photo/Media Consent
        $photo_consent = isset($_POST['photo_consent']) && $_POST['photo_consent'];
        
        // v235: Instagram data — save to player record for future checkouts
        $instagram_handle = sanitize_text_field($_POST['instagram_handle'] ?? '');
        $announcement_photo_url = esc_url_raw($_POST['announcement_photo_url'] ?? '');
        
        // Before/After Care
        $before_after_care = isset($_POST['before_after_care']) && $_POST['before_after_care'];
        $care_amount = floatval($_POST['before_after_care_amount'] ?? 0);
        
        // Upgrade pack
        $upgrade_selected = sanitize_text_field($_POST['upgrade_selected'] ?? '');
        $upgrade_amount = floatval($_POST['upgrade_amount'] ?? 0);
        $upgrade_camps = sanitize_text_field($_POST['upgrade_camps'] ?? '');
        $upgrade_camp_ids = array_filter(array_map('intval', explode(',', $upgrade_camps)));
        
        // v175: Coupon/Referral data
        $coupon_code = strtoupper(sanitize_text_field($_POST['coupon_code'] ?? ''));
        $coupon_discount = floatval($_POST['coupon_discount'] ?? 0);
        $coupon_id = intval($_POST['coupon_id'] ?? 0);
        $free_code_id = intval($_POST['free_code_id'] ?? 0);
        $referral_code = sanitize_text_field($_POST['referral_code'] ?? '');
        $referral_discount = floatval($_POST['referral_discount'] ?? 0);
        $referral_id = intval($_POST['referral_id'] ?? 0);
        
        // ========================================
        // VALIDATION
        // ========================================
        
        if (empty($parent_data['first_name']) || empty($parent_data['email'])) {
            wp_send_json_error(array('message' => 'Please fill in all required parent/guardian fields.'));
            return;
        }
        
        if (!$player_id && empty($camper_data['first_name'])) {
            wp_send_json_error(array('message' => 'Please provide camper information.'));
            return;
        }
        
        if (empty($emergency_data['name']) || empty($emergency_data['phone'])) {
            wp_send_json_error(array('message' => 'Emergency contact information is required.'));
            return;
        }
        
        if (!$waiver_accepted) {
            wp_send_json_error(array('message' => 'You must accept the waiver and agreement to continue.'));
            return;
        }
        
        // Check for deferred payment flow (Elements will handle payment method)
        $create_intent_only = isset($_POST['create_intent_only']) && $_POST['create_intent_only'];
        
        if (!$create_intent_only && empty($payment_method_id)) {
            wp_send_json_error(array('message' => 'Payment information is required.'));
            return;
        }
        
        // ========================================
        // USER & PARENT CREATION
        // ========================================
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            $existing_user = get_user_by('email', $parent_data['email']);
            if ($existing_user) {
                $user_id = $existing_user->ID;
            } else {
                $username = $this->generate_unique_username($parent_data['email']);
                $password = wp_generate_password();
                
                $user_id = wp_create_user($username, $password, $parent_data['email']);
                if (is_wp_error($user_id)) {
                    wp_send_json_error(array('message' => 'Could not create account.'));
                    return;
                }
                
                wp_update_user(array(
                    'ID' => $user_id,
                    'first_name' => $parent_data['first_name'],
                    'last_name' => $parent_data['last_name'],
                    'display_name' => $parent_data['first_name'] . ' ' . $parent_data['last_name'],
                ));
                
                update_user_meta($user_id, 'billing_phone', $parent_data['phone']);
                update_user_meta($user_id, 'billing_first_name', $parent_data['first_name']);
                update_user_meta($user_id, 'billing_last_name', $parent_data['last_name']);
                update_user_meta($user_id, 'billing_email', $parent_data['email']);
            }
        }
        
        // Get or create parent record
        $parent_id = $this->get_or_create_parent($user_id, $parent_data);
        
        // ========================================
        // PLAYER/CAMPER CREATION
        // ========================================
        
        if ($player_id) {
            // Verify player belongs to parent & update with new data
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d AND parent_id = %d",
                $player_id, $parent_id
            ));
            
            if ($existing) {
                // Update emergency & medical for this registration
                $wpdb->update(
                    $wpdb->prefix . 'ptp_players',
                    array(
                        'emergency_name' => $emergency_data['name'],
                        'emergency_phone' => $emergency_data['phone'],
                        'emergency_relation' => $emergency_data['relation'],
                        'medical_info' => $medical_info,
                        'insurance_provider' => $insurance_data['provider'],
                        'insurance_policy' => $insurance_data['policy'],
                        'insurance_group' => $insurance_data['group'],
                        'waiver_accepted' => 1,
                        'waiver_accepted_at' => current_time('mysql'),
                        'waiver_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ),
                    array('id' => $player_id)
                );
                
                // v235: Save instagram/photo to player for next checkout
                if (!empty($instagram_handle) || !empty($announcement_photo_url)) {
                    $ig_update = array();
                    if (!empty($instagram_handle)) $ig_update['instagram_handle'] = $instagram_handle;
                    if (!empty($announcement_photo_url)) $ig_update['player_photo_url'] = $announcement_photo_url;
                    $wpdb->update($wpdb->prefix . 'ptp_players', $ig_update, array('id' => $player_id));
                }
                
                $camper_data['first_name'] = $existing->first_name;
                $camper_data['last_name'] = $existing->last_name;
            } else {
                $player_id = 0; // Invalid, create new
            }
        }
        
        if (!$player_id && !empty($camper_data['first_name'])) {
            // Calculate age from DOB
            $age = null;
            if (!empty($camper_data['dob'])) {
                $dob = new DateTime($camper_data['dob']);
                $now = new DateTime();
                $age = $dob->diff($now)->y;
            }
            
            $wpdb->insert(
                $wpdb->prefix . 'ptp_players',
                array(
                    'parent_id' => $parent_id,
                    'first_name' => $camper_data['first_name'],
                    'last_name' => $camper_data['last_name'],
                    'dob' => $camper_data['dob'] ?: null,
                    'age' => $age,
                    'shirt_size' => $camper_data['shirt_size'],
                    'team' => $camper_data['team'],
                    'skill_level' => $camper_data['skill_level'],
                    'emergency_name' => $emergency_data['name'],
                    'emergency_phone' => $emergency_data['phone'],
                    'emergency_relation' => $emergency_data['relation'],
                    'medical_info' => $medical_info,
                    'insurance_provider' => $insurance_data['provider'],
                    'insurance_policy' => $insurance_data['policy'],
                    'insurance_group' => $insurance_data['group'],
                    'waiver_accepted' => 1,
                    'waiver_accepted_at' => current_time('mysql'),
                    'waiver_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'photo_consent' => $photo_consent ? 1 : 0,
                    'instagram_handle' => $instagram_handle,
                    'player_photo_url' => $announcement_photo_url,
                    'created_at' => current_time('mysql'),
                )
            );
            $player_id = $wpdb->insert_id;
        }
        
        // ========================================
        // CALCULATE TOTALS
        // ========================================
        
        $woo_total = 0;
        $training_total = 0;
        $has_native_cart = function_exists('ptp_cart') && !ptp_cart()->is_empty();
        
        if ($has_native_cart) {
            ptp_cart()->calculate_totals();
            $woo_total = floatval(ptp_cart()->get_subtotal());
        }
        
        // Training pricing
        $trainer = null;
        if ($trainer_id) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $trainer_id
            ));
            
            if ($trainer) {
                $rate = intval($trainer->hourly_rate ?: 60);
                if (class_exists('PTP_Packages')) {
                    $pricing = PTP_Packages::calculate_price($rate, $training_package);
                    $training_total = intval($pricing['total']);
                } else {
                    $packages = array(
                        'single' => $rate,
                        'pack3' => intval($rate * 3 * 0.9),
                        'pack5' => intval($rate * 5 * 0.85),
                        'pack10' => intval($rate * 10 * 0.8),
                        '10pack' => intval($rate * 10 * 0.8),
                    );
                    $training_total = $packages[$training_package] ?? $rate;
                }
            }
        }
        
        $subtotal = $woo_total + $training_total;
        
        // Bundle discount
        $bundle_discount = 0;
        $bundle = null;
        if ($bundle_code && $trainer_id && $has_native_cart) {
            $bundle = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_bundles WHERE bundle_code = %s",
                $bundle_code
            ));
            if ($bundle) {
                $discount_pct = floatval($bundle->discount_percent ?: 15);
                $bundle_discount = round($subtotal * ($discount_pct / 100), 2);
            }
        }
        
        $total = $subtotal - $bundle_discount;
        
        // ========================================
        // STRIPE PAYMENT
        // ========================================
        
        $secret_key = get_option('ptp_stripe_test_mode', true) 
            ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
            : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
        
        if (empty($secret_key)) {
            wp_send_json_error(array('message' => 'Payment system not configured. Please contact support.'));
            return;
        }
        
        $amount_cents = intval(round($total * 100));
        
        if ($amount_cents < 50) {
            wp_send_json_error(array('message' => 'Order total must be at least $0.50'));
            return;
        }
        
        $camper_name = trim($camper_data['first_name'] . ' ' . $camper_data['last_name']);
        $create_intent_only = isset($_POST['create_intent_only']) && $_POST['create_intent_only'];
        
        // Build description from cart items
        $cart_items_str = sanitize_text_field($_POST['cart_items'] ?? '');
        $description_parts = array();
        if (!empty($cart_items_str)) {
            $cart_item_names = array_map('trim', explode(',', $cart_items_str));
            $description_parts = array_slice($cart_item_names, 0, 3);
        }
        if ($trainer) {
            $description_parts[] = 'Training with ' . $trainer->display_name;
        }
        $stripe_description = !empty($description_parts) 
            ? implode(', ', $description_parts) . ' - ' . $camper_name
            : 'PTP Registration - ' . $camper_name;
        
        // Build PaymentIntent data
        $intent_data = array(
            'amount' => $amount_cents,
            'currency' => 'usd',
            'automatic_payment_methods[enabled]' => 'true',
            'description' => $stripe_description,
            // v114.1: Removed receipt_email - PTP Native handles confirmation emails
            'metadata[parent_name]' => $parent_data['first_name'] . ' ' . $parent_data['last_name'],
            'metadata[parent_email]' => $parent_data['email'],
            'metadata[camper_name]' => $camper_name,
            'metadata[player_id]' => $player_id,
            'metadata[checkout_session]' => wp_generate_uuid4(),
            'metadata[cart_items]' => $cart_items_str,
        );
        
        // Stripe Connect for trainer — v194: graduated fee
        if ($trainer && !empty($trainer->stripe_account_id) && $training_total > 0) {
            $n_sessions = class_exists('PTP_Packages') ? PTP_Packages::get(PTP_Packages::resolve_key($training_package))['sessions'] : (array('single' => 1, 'pack3' => 3, 'pack5' => 5, 'pack10' => 10, '10pack' => 10)[$training_package] ?? 1);
            $intent_fee = self::calculate_graduated_fee($trainer_id, $parent_id, $training_total, $n_sessions);
            $trainer_amount = intval(round($intent_fee['trainer_payout'] * 100));
            
            $intent_data['transfer_data[destination]'] = $trainer->stripe_account_id;
            $intent_data['transfer_data[amount]'] = $trainer_amount;
            $intent_data['metadata[trainer_id]'] = $trainer_id;
            $intent_data['metadata[fee_info]'] = $intent_fee['fee_info'];
        }
        
        // Store form data in session for webhook/return handling
        $checkout_data = array(
            'parent_data' => $parent_data,
            'camper_data' => $camper_data,
            'emergency_data' => $emergency_data,
            'medical_info' => $medical_info,
            'insurance_data' => $insurance_data,
            'waiver_accepted' => $waiver_accepted,
            'photo_consent' => $photo_consent,
            'cart_items' => $_POST['cart_items'] ?? '',
            'trainer_id' => $trainer_id,
            'training_package' => $training_package,
            'training_total' => $training_total,
            'camp_total' => $camp_total,
            'bundle_discount' => $bundle_discount,
            'total' => $total,
        );
        set_transient('ptp_checkout_' . $intent_data['metadata[checkout_session]'], $checkout_data, HOUR_IN_SECONDS);
        
        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => $intent_data,
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('[PTP Checkout] Stripe error: ' . $response->get_error_message());
            wp_send_json_error(array('message' => 'Payment failed. Please try again.'));
            return;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            ptp_log('[PTP Checkout] Stripe error: ' . json_encode($body['error']));
            $msg = $body['error']['message'] ?? 'Payment failed.';
            if (strpos($msg, 'declined') !== false) {
                $msg = 'Your card was declined. Please try a different card.';
            }
            wp_send_json_error(array('message' => $msg));
            return;
        }
        
        $payment_intent_id = $body['id'] ?? '';
        $client_secret = $body['client_secret'] ?? '';
        $status = $body['status'] ?? '';
        
        // For deferred confirmation flow (Affirm support), return client_secret
        // The frontend will call stripe.confirmPayment() with this
        if ($status === 'requires_payment_method' || $status === 'requires_confirmation') {
            wp_send_json_success(array(
                'client_secret' => $client_secret,
                'payment_intent_id' => $payment_intent_id,
                'redirect_url' => home_url('/thank-you/?payment_intent=' . $payment_intent_id . '&session=' . $intent_data['metadata[checkout_session]']),
            ));
            return;
        }
        
        // Handle 3D Secure or other action required
        if ($status === 'requires_action' || $status === 'requires_source_action') {
            wp_send_json_success(array(
                'requires_action' => true,
                'client_secret' => $client_secret,
                'payment_intent_id' => $payment_intent_id,
                'redirect_url' => home_url('/thank-you/?payment_intent=' . $payment_intent_id . '&session=' . $intent_data['metadata[checkout_session]']),
            ));
            return;
        }
        
        // ========================================
        // PAYMENT SUCCEEDED - CREATE ORDERS
        // ========================================
        
        if ($status === 'succeeded') {
            $order_id = null;
            $booking_id = null;
            
            // Native cart order
            if ($has_native_cart) {
                $order = PTP_Native_Order_Manager::create_order(array(
                    'customer_id' => $user_id,
                    'status' => 'pending', // Start as pending, payment_complete will change to processing and trigger emails
                ));
                
                if (!is_wp_error($order)) {
                    // Add items - v10.3.6: Updated for native cart structure
                    foreach (ptp_cart()->get_items() as $cart_item) {
                        $item_id = $cart_item['item_id'];
                        $item_type = $cart_item['item_type'] ?? 'product';
                        $metadata = $cart_item['metadata'] ?? array();
                        
                        // Add order line item
                        $order_item_id = $order->add_item(array(
                            'name' => $metadata['name'] ?? 'Item',
                            'item_type' => $item_type,
                            'product_id' => $item_id,
                            'quantity' => $cart_item['quantity'],
                            'subtotal' => $cart_item['line_total'],
                            'total' => $cart_item['line_total'],
                        ));
                        
                        if ($order_item_id) {
                            ptp_add_order_item_meta($order_item_id, 'Player Name', $camper_name);
                            ptp_add_order_item_meta($order_item_id, 'Player Age', $camper_data['dob'] ? (new DateTime($camper_data['dob']))->diff(new DateTime())->y : '');
                            ptp_add_order_item_meta($order_item_id, 'T-Shirt Size', $camper_data['shirt_size']);
                            ptp_add_order_item_meta($order_item_id, '_player_id', $player_id);
                            
                            // Store source URL for navigation
                            if (!empty($metadata['source_url'])) {
                                ptp_add_order_item_meta($order_item_id, '_source_url', $metadata['source_url']);
                            }
                        }
                    }
                    
                    // Billing
                    $order->set_billing_first_name($parent_data['first_name']);
                    $order->set_billing_last_name($parent_data['last_name']);
                    $order->set_billing_email($parent_data['email']);
                    $order->set_billing_phone($parent_data['phone']);
                    
                    // Bundle discount
                    if ($bundle_discount > 0) {
                        $fee = ptp_create_order_fee();
                        $fee->set_name('Bundle Discount');
                        $fee->set_total(-$bundle_discount);
                        $order->add_item($fee);
                    }
                    
                    $order->calculate_totals();
                    $order->set_payment_method('stripe');
                    $order->set_payment_method_title('Credit Card');
                    $order->set_transaction_id($payment_intent_id);
                    
                    // Meta
                    $order->update_meta_data('_stripe_payment_intent', $payment_intent_id);
                    $order->update_meta_data('_player_id', $player_id);
                    $order->update_meta_data('_player_name', $camper_name);
                    $order->update_meta_data('_emergency_contact', $emergency_data['name'] . ' - ' . $emergency_data['phone']);
                    $order->update_meta_data('_medical_info', $medical_info);
                    $order->update_meta_data('_waiver_accepted', 'yes');
                    $order->update_meta_data('_waiver_accepted_at', current_time('mysql'));
                    $order->update_meta_data('_photo_consent', $photo_consent ? 'yes' : 'no');
                    
                    if ($bundle_code) {
                        $order->update_meta_data('_bundle_code', $bundle_code);
                        $order->update_meta_data('_bundle_discount', $bundle_discount);
                    }
                    
                    // Before/After Care
                    if ($before_after_care) {
                        $order->update_meta_data('_before_after_care', 'yes');
                        $order->update_meta_data('_before_after_care_amount', $care_amount);
                        
                        // Add as fee
                        $care_fee = ptp_create_order_fee();
                        $care_fee->set_name('Before & After Care');
                        $care_fee->set_total($care_amount);
                        $order->add_item($care_fee);
                    }
                    
                    // Upgrade Pack
                    if ($upgrade_selected) {
                        $order->update_meta_data('_upgrade_pack', $upgrade_selected);
                        $order->update_meta_data('_upgrade_amount', $upgrade_amount);
                        
                        $upgrade_labels = array(
                            '2pack' => '2-Camp Pack (+1 Camp)',
                            '3pack' => '3-Camp Pack (+2 Camps)',
                            'allaccess' => 'All-Access Pass',
                        );
                        $upgrade_label = $upgrade_labels[$upgrade_selected] ?? 'Camp Pack Upgrade';
                        
                        // Add as fee
                        $upgrade_fee = ptp_create_order_fee();
                        $upgrade_fee->set_name($upgrade_label);
                        $upgrade_fee->set_total($upgrade_amount);
                        $order->add_item($upgrade_fee);
                        
                        // Save selected additional camps
                        if (!empty($upgrade_camp_ids)) {
                            $order->update_meta_data('_upgrade_camp_ids', $upgrade_camp_ids);
                            
                            // Get camp names for display
                            $camp_names = array();
                            foreach ($upgrade_camp_ids as $camp_id) {
                                $camp_product = ptp_get_camp_product($camp_id);
                                if ($camp_product) {
                                    $camp_names[] = $camp_product->get_name();
                                }
                            }
                            $order->update_meta_data('_upgrade_camp_names', implode(', ', $camp_names));
                        }
                    }
                    
                    $order->payment_complete($payment_intent_id);
                    $order->add_order_note('Paid via PTP Checkout. Payment Intent: ' . $payment_intent_id);
                    $order->save();
                    
                    $order_id = $order->get_id();
                    
                    ptp_cart()->clear();
                    
                    // Trigger PTP Native emails properly
                    ptp_log('[PTP Checkout] Triggering emails for order #' . $order_id);
                    
                    // Method 1: Trigger status change hook (most reliable)
                    // REMOVED: do_action('ptp-native_order_status_pending_to_processing_notification', $order_id, $order);
                    
                    // Method 2: Trigger new order actions
                    // REMOVED: do_action('ptp-native_new_order', $order_id, $order);
                    // REMOVED: do_action('ptp-native_checkout_order_processed', $order_id, array(), $order);
                    
                    // Method 3: Trigger customer email directly as fallback
                    if (false) {
                        // WC emails removed;
                        $emails = $ptp_emails->get_emails();
                        
                        // Customer processing order email
                        if (isset($emails['PTP_Customer_Email'])) {
                            $emails['PTP_Customer_Email']->trigger($order_id, $order);
                            ptp_log('[PTP Checkout] Customer processing email triggered');
                        }
                        
                        // Admin new order email
                        if (isset($emails['PTP_Admin_Email'])) {
                            $emails['PTP_Admin_Email']->trigger($order_id, $order);
                            ptp_log('[PTP Checkout] Admin new order email triggered');
                        }
                    }
                    
                    ptp_log('[PTP Checkout] Order #' . $order_id . ' created successfully, emails triggered');
                }
            }
            
            // Training booking
            if ($trainer_id && $training_total > 0) {
                $num_sessions = class_exists('PTP_Packages') ? PTP_Packages::get(PTP_Packages::resolve_key($training_package))['sessions'] : (array('single' => 1, 'pack3' => 3, 'pack5' => 5, 'pack10' => 10, '10pack' => 10)[$training_package] ?? 1);
                // v194: Graduated fee
                $fee_calc_3 = self::calculate_graduated_fee($trainer_id, $parent_id, $training_total, $num_sessions);
                $trainer_payout = $fee_calc_3['trainer_payout'];
                
                // Determine status based on whether date was selected
                $booking_status = !empty($session_date) ? 'confirmed' : 'pending_schedule';
                
                // v211: Always use trainer's location from DB
                $trainer_loc_rec = $wpdb->get_row($wpdb->prepare(
                    "SELECT location, city, state FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
                ));
                $trainer_loc = '';
                if ($trainer_loc_rec) {
                    if (!empty($trainer_loc_rec->location)) {
                        $trainer_loc = $trainer_loc_rec->location;
                    } elseif (!empty($trainer_loc_rec->city)) {
                        $trainer_loc = $trainer_loc_rec->city . (!empty($trainer_loc_rec->state) ? ', ' . $trainer_loc_rec->state : '');
                    }
                }
                if (empty($trainer_loc) && !empty($session_location)) {
                    $trainer_loc = $session_location; // Last resort fallback
                }
                
                $wpdb->insert(
                    $wpdb->prefix . 'ptp_bookings',
                    array(
                        'trainer_id' => $trainer_id,
                        'parent_id' => $parent_id,
                        'player_id' => $player_id,
                        'session_date' => !empty($session_date) ? $session_date : null,
                        'start_time' => !empty($session_time) ? $session_time : null,
                        'location' => !empty($trainer_loc) ? $trainer_loc : null,
                        'package_type' => $training_package,
                        'total_sessions' => $num_sessions,
                        'sessions_remaining' => $num_sessions,
                        'amount_paid' => $training_total,
                        'trainer_payout' => $trainer_payout,
                        'payment_intent_id' => $payment_intent_id,
                        'status' => $booking_status,
                        // v175: Coupon/Referral tracking
                        'coupon_code' => $coupon_code ?: null,
                        'coupon_discount' => $coupon_discount,
                        'coupon_id' => $coupon_id ?: null,
                        'free_code_id' => $free_code_id ?: null,
                        'referral_code' => $referral_code ?: null,
                        'referral_discount' => $referral_discount,
                        'referral_id' => $referral_id ?: null,
                        'created_at' => current_time('mysql'),
                    )
                );
                $booking_id = $wpdb->insert_id;
                
                // Update bundle
                if ($bundle_code) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bundles',
                        array(
                            'training_booking_id' => $booking_id,
                            'training_status' => 'completed',
                            'camp_order_id' => $order_id,
                            'camp_status' => $order_id ? 'completed' : 'pending',
                            'payment_intent_id' => $payment_intent_id,
                            'payment_status' => 'completed',
                            'completed_at' => current_time('mysql'),
                            'status' => 'completed',
                        ),
                        array('bundle_code' => $bundle_code)
                    );
                }
                
                // Notify trainer
                $this->notify_trainer($trainer_id, $booking_id, $camper_name);
            }
            
            // Redirect URL
            $redirect_url = home_url('/thank-you/');
            if ($order_id) {
                $order = PTP_Native_Order_Manager::get_order($order_id);
                if ($order) {
                    $redirect_url = $order->get_checkout_order_received_url();
                }
            } elseif ($booking_id) {
                $redirect_url = home_url('/my-account/?booking=' . $booking_id . '&success=1');
            }
            
            wp_send_json_success(array(
                'order_id' => $order_id,
                'booking_id' => $booking_id,
                'redirect_url' => $redirect_url,
            ));
            return;
        }
        
        wp_send_json_error(array('message' => 'Payment could not be processed. Status: ' . $status));
    }
    
    /**
     * Generate unique username
     */
    private function generate_unique_username($email) {
        $base = sanitize_user(current(explode('@', $email)));
        $base = preg_replace('/[^a-zA-Z0-9]/', '', $base);
        if (empty($base)) $base = 'user';
        
        $username = $base;
        $i = 1;
        while (username_exists($username)) {
            $username = $base . $i;
            $i++;
        }
        return $username;
    }
    
    /**
     * Get or create parent
     */
    private function get_or_create_parent($user_id, $data) {
        global $wpdb;
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if ($parent) {
            // Update
            $wpdb->update(
                $wpdb->prefix . 'ptp_parents',
                array(
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                ),
                array('id' => $parent->id)
            );
            return $parent->id;
        }
        
        // Create
        $wpdb->insert(
            $wpdb->prefix . 'ptp_parents',
            array(
                'user_id' => $user_id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'created_at' => current_time('mysql'),
            )
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * v194: Calculate graduated platform fee based on parent-trainer session history
     * 
     * Fee schedule (per trainer agreement):
     *   Sessions 1-2: 50% PTP / 50% trainer
     *   Sessions 3-4: 25% PTP / 75% trainer
     *   Session 5+:   15% PTP / 85% trainer
     * 
     * Session count is per parent-trainer pair and resets for new families.
     * For multi-session packages, each session advances the counter.
     * 
     * @param int   $trainer_id    Trainer ID
     * @param int   $parent_id     Parent ID (0 if unknown - falls back to flat rate)
     * @param float $total_amount  Total amount charged for training
     * @param int   $num_sessions  Number of sessions in this purchase (1, 3, or 5)
     * @return array ['platform_fee' => float, 'trainer_payout' => float, 'fee_info' => string]
     */
    public static function calculate_graduated_fee($trainer_id, $parent_id, $total_amount, $num_sessions = 1) {
        // Fee schedule: session_number => PTP's cut
        $fee_schedule = array(1 => 0.50, 2 => 0.50, 3 => 0.25, 4 => 0.25);
        $fee_ongoing = 0.15;
        
        // If we can't identify the parent, use PTP_Escrow if available, else flat rate
        if (!$parent_id || !$trainer_id) {
            $fallback_pct = floatval(get_option('ptp_platform_fee_percent', 25));
            $platform_fee = round($total_amount * ($fallback_pct / 100), 2);
            return array(
                'platform_fee'   => $platform_fee,
                'trainer_payout' => round($total_amount - $platform_fee, 2),
                'fee_info'       => "Flat {$fallback_pct}% (no parent-trainer pair)",
            );
        }
        
        global $wpdb;
        
        // Count previous PAID sessions between this parent and trainer
        // Check bookings table (primary)
        $previous_sessions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
             WHERE parent_id = %d AND trainer_id = %d 
             AND status IN ('completed', 'confirmed', 'pending')
             AND payment_status = 'paid'
             AND total_amount > 0",
            $parent_id, $trainer_id
        ));
        
        // Also check escrow table if it exists (may have records not in bookings)
        $escrow_table = $wpdb->prefix . 'ptp_escrow';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$escrow_table}'")) {
            $escrow_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$escrow_table}
                 WHERE trainer_id = %d AND parent_id = %d 
                 AND status IN ('holding', 'session_complete', 'confirmed', 'released')
                 AND total_amount > 0",
                $trainer_id, $parent_id
            ));
            // Use the higher count (avoid double-counting)
            $previous_sessions = max($previous_sessions, $escrow_count);
        }
        
        // Calculate per-session rate for multi-session packages
        $per_session_amount = $total_amount / max($num_sessions, 1);
        $total_platform_fee = 0;
        
        for ($s = 1; $s <= $num_sessions; $s++) {
            $session_num = $previous_sessions + $s;
            $fee_rate = isset($fee_schedule[$session_num]) ? $fee_schedule[$session_num] : $fee_ongoing;
            $total_platform_fee += round($per_session_amount * $fee_rate, 2);
        }
        
        $trainer_payout = round($total_amount - $total_platform_fee, 2);
        
        // Build info string for logging/notes
        $first_session = $previous_sessions + 1;
        $last_session = $previous_sessions + $num_sessions;
        $first_rate = isset($fee_schedule[$first_session]) ? ($fee_schedule[$first_session] * 100) : ($fee_ongoing * 100);
        $last_rate = isset($fee_schedule[$last_session]) ? ($fee_schedule[$last_session] * 100) : ($fee_ongoing * 100);
        
        if ($num_sessions === 1) {
            $fee_info = sprintf("Session #%d: %.0f%% PTP fee", $first_session, $first_rate);
        } else {
            $fee_info = sprintf("Sessions #%d-%d: %.0f%%->%.0f%% PTP fee", $first_session, $last_session, $first_rate, $last_rate);
        }
        
        ptp_log(sprintf('[PTP Graduated Fee] trainer=%d parent=%d prev=%d buying=%d fee=$%.2f payout=$%.2f (%s)',
            $trainer_id, $parent_id, $previous_sessions, $num_sessions, $total_platform_fee, $trainer_payout, $fee_info));
        
        return array(
            'platform_fee'      => $total_platform_fee,
            'trainer_payout'    => $trainer_payout,
            'fee_info'          => $fee_info,
            'previous_sessions' => $previous_sessions,
            'first_session_num' => $first_session,
            'last_session_num'  => $last_session,
        );
    }
    
    /**
     * Notify trainer of booking
     */
    public function notify_trainer($trainer_id, $booking_id, $camper_name) {
        global $wpdb;
        
        ptp_log('[PTP Notify v128.2.7] Starting notify_trainer for booking ' . $booking_id . ', trainer ' . $trainer_id);
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID 
             WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            ptp_log('[PTP Notify v128.2.7] Trainer not found: ' . $trainer_id);
            return;
        }
        
        // Get booking with parent info from multiple sources
        // v213: Added player DOB/age, medical, emergency for email completeness
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, 
                    p.first_name as parent_first, p.last_name as parent_last, p.email as parent_email, p.phone as parent_phone,
                    p.medical_info as parent_medical_info, p.emergency_name, p.emergency_phone, p.emergency_relation,
                    pl.first_name as player_first, pl.last_name as player_last, pl.dob as player_dob, pl.age as player_age,
                    u.user_email as wp_user_email
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            ptp_log('[PTP Notify v128.2.7] Booking not found: ' . $booking_id);
            return;
        }
        
        // v128.2.7: Get parent email with enhanced fallbacks
        $parent_email = $booking->parent_email;
        if (empty($parent_email) && !empty($booking->wp_user_email)) {
            $parent_email = $booking->wp_user_email;
            ptp_log('[PTP Notify v128.2.7] Using WP user email as fallback: ' . $parent_email);
        }
        
        // v128.2.7: Check guest_email column (used for guest checkout)
        if (empty($parent_email) && !empty($booking->guest_email)) {
            $parent_email = $booking->guest_email;
            ptp_log('[PTP Notify v128.2.7] Using guest_email as fallback: ' . $parent_email);
        }
        
        // v128.2.7: Try to get from recent checkout session transient
        if (empty($parent_email) && !empty($booking->payment_intent_id)) {
            // Search for transient with this payment intent
            $all_transients = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} 
                 WHERE option_name LIKE '_transient_ptp_checkout_%' 
                 AND option_value LIKE '%" . esc_sql($booking->payment_intent_id) . "%'
                 LIMIT 1"
            );
            if (!empty($all_transients)) {
                $checkout_data = maybe_unserialize($all_transients[0]->option_value);
                if (is_array($checkout_data) && !empty($checkout_data['parent_data']['email'])) {
                    $parent_email = $checkout_data['parent_data']['email'];
                    ptp_log('[PTP Notify v128.2.7] Found email from checkout transient: ' . $parent_email);
                    
                    // Also update the parent record with this email if missing
                    if ($booking->parent_id && empty($booking->parent_email)) {
                        $wpdb->update(
                            $wpdb->prefix . 'ptp_parents',
                            array('email' => $parent_email),
                            array('id' => $booking->parent_id)
                        );
                        ptp_log('[PTP Notify v128.2.7] Updated parent #' . $booking->parent_id . ' with email: ' . $parent_email);
                    }
                }
            }
        }
        
        ptp_log('[PTP Notify v128.2.7] Final parent_email: ' . ($parent_email ?: 'NONE'));
        ptp_log('[PTP Notify v128.2.7] Parent phone: ' . ($booking->parent_phone ?: 'NONE'));
        
        // Format session details
        // v215: Robust null/zero date handling — '0000-00-00' passes !empty() but is invalid
        $raw_date = $booking->session_date ?? '';
        $has_valid_date = (!empty($raw_date) && $raw_date !== '0000-00-00' && strtotime($raw_date) > 0);
        $date_display = $has_valid_date ? date('l, F j, Y', strtotime($raw_date)) : 'TBD - Trainer will confirm';
        
        $time_display = 'TBD';
        
        // v123: Check both start_time (db column) and session_time (legacy)
        $session_time = !empty($booking->start_time) ? $booking->start_time : (!empty($booking->session_time) ? $booking->session_time : '');
        // v220: Normalize time first (fixes "1:00" being treated as 1 AM)
        if (!empty($session_time) && function_exists('ptp_normalize_session_time')) {
            $session_time = ptp_normalize_session_time($session_time) ?: $session_time;
        }
        if (!empty($session_time) && $session_time !== '00:00:00') {
            $time_parts = explode(':', $session_time);
            $hour = intval($time_parts[0]);
            $minute = isset($time_parts[1]) ? intval($time_parts[1]) : 0;
            $ampm = $hour >= 12 ? 'PM' : 'AM';
            $displayHour = $hour > 12 ? $hour - 12 : ($hour == 0 ? 12 : $hour);
            $time_display = $minute > 0 ? "{$displayHour}:" . str_pad($minute, 2, '0', STR_PAD_LEFT) . " {$ampm}" : "{$displayHour}:00 {$ampm}";
            ptp_log('[PTP Notify v220] Time extracted: ' . $session_time . ' -> ' . $time_display);
        }
        
        // v215: Location fallback chain — booking → trainer.location → trainer.city,state → training_locations → TBD
        $location_display = '';
        if (!empty($booking->location)) {
            $location_display = $booking->location;
        }
        if (empty($location_display) && $trainer) {
            if (!empty($trainer->location)) {
                $location_display = $trainer->location;
            } elseif (!empty($trainer->city)) {
                $location_display = $trainer->city . (!empty($trainer->state) ? ', ' . $trainer->state : '');
            }
        }
        // v230: Fallback to trainer's training_locations JSON
        if (empty($location_display) && $trainer && !empty($trainer->training_locations)) {
            $tl = json_decode($trainer->training_locations, true);
            if (!is_array($tl)) $tl = json_decode(wp_unslash($trainer->training_locations), true);
            if (is_array($tl)) {
                foreach ($tl as $loc) {
                    if (is_array($loc) && !empty($loc['name'])) {
                        $location_display = $loc['name'];
                        break;
                    }
                }
            }
        }
        if (empty($location_display)) {
            $location_display = 'TBD - Trainer will confirm';
        }
        if (class_exists('PTP_Packages')) {
            $pkg_def = PTP_Packages::get($booking->package_type ?? 'single');
            $package_display = $pkg_def['name'] ?? ucfirst($booking->package_type);
        } else {
            $package_labels = array('single' => 'Single Session', 'pack3' => '3-Session Pack', 'pack5' => '5-Session Pack', 'pack10' => '10-Session Pack');
            $package_display = $package_labels[$booking->package_type] ?? ucfirst($booking->package_type);
        }
        $player_name = trim(($booking->player_first ?? '') . ' ' . ($booking->player_last ?? ''));
        if (empty($player_name)) $player_name = $camper_name;
        
        // Email headers - BRANDED FROM ADDRESS
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_training'),
            'Reply-To: ' . ptp_email_brand('from_email')
        );
        
        // ========================================
        // 1. TRAINER EMAIL — v228: REMOVED
        // Emails handled by ptp_booking_confirmed hook chain:
        //   → PTP_Order_Email_Wiring::on_training_booking_completed()
        //   → PTP_Email::send_trainer_new_booking() + send_booking_confirmation()
        // Sending here caused duplicate emails.
        // ========================================
        ptp_log('[PTP Notify v228] Emails delegated to ptp_booking_confirmed hook chain');
        
        // ========================================
        // 2. PARENT EMAIL — v228: REMOVED (same reason)
        // ========================================
        
        // ========================================
        // 3. SMS NOTIFICATIONS
        // ========================================
        if (class_exists('PTP_SMS_V71') && PTP_SMS_V71::is_enabled()) {
            // Trainer SMS
            if (!empty($trainer->phone)) {
                $trainer_sms = "New booking! {$player_name} booked a {$package_display}. ";
                if ($has_valid_date) {
                    $trainer_sms .= date('M j', strtotime($booking->session_date)) . " at {$time_display}. ";
                }
                $trainer_sms .= "Check your dashboard for details.";
                
                PTP_SMS_V71::send($trainer->phone, $trainer_sms);
                ptp_log('[PTP Training v117.2.13] Trainer SMS sent to: ' . $trainer->phone);
            }
            
            // Parent SMS
            if (!empty($booking->parent_phone)) {
                $parent_sms = ptp_email_brand('company') . " Confirmed! {$player_name}'s training with {$trainer->display_name} is confirmed! ";
                if ($has_valid_date) {
                    $parent_sms .= date('M j', strtotime($booking->session_date)) . " at {$time_display}";
                    if ($location_display && $location_display !== 'TBD - Trainer will confirm') {
                        $parent_sms .= " @ " . substr($location_display, 0, 30);
                    }
                    $parent_sms .= ". ";
                } else {
                    $parent_sms .= "Your trainer will reach out to confirm date/time. ";
                }
                $parent_sms .= "Questions? Reply to this text.";
                
                PTP_SMS_V71::send($booking->parent_phone, $parent_sms);
                ptp_log('[PTP Training v117.2.13] Parent SMS sent to: ' . $booking->parent_phone);
            }
        }
        
        // ========================================
        // 4. ADMIN EMAIL (v220)
        // ========================================
        $admin_email = get_option('admin_email');
        if (!empty($admin_email)) {
            $parent_name_display = trim(($booking->parent_first ?? '') . ' ' . ($booking->parent_last ?? ''));
            $total_amount = floatval($booking->total_amount ?? 0);
            
            // v220: Dynamically calculate payout/fee if DB values are missing or don't add up
            $db_payout = floatval($booking->trainer_payout ?? 0);
            $db_fee = floatval($booking->platform_fee ?? 0);
            
            if ($total_amount > 0 && (abs(($db_payout + $db_fee) - $total_amount) > 0.02 || ($db_payout == 0 && $db_fee == 0))) {
                // DB values don't add up or are both zero — recalculate
                $fee_calc = self::calculate_graduated_fee(
                    $trainer_id,
                    $booking->parent_id ?? 0,
                    $total_amount,
                    intval($booking->total_sessions ?? 1)
                );
                $db_fee = $fee_calc['platform_fee'];
                $db_payout = $fee_calc['trainer_payout'];
                $fee_info = $fee_calc['fee_info'] ?? '';
            } else {
                // DB values are correct — figure out rate for display
                $fee_pct = $total_amount > 0 ? round(($db_fee / $total_amount) * 100) : 0;
                $fee_info = $fee_pct . '% PTP fee';
            }
            
            // Build fee rate badge for admin visibility
            $fee_badge = !empty($fee_info) ? '<p style="margin:8px 0 0;font-size:11px;color:#6B7280;text-align:center;">' . esc_html($fee_info) . '</p>' : '';
            
            // v220: Detect free session
            $is_free = ($total_amount <= 0) || ($booking->payment_status === 'free_session');
            
            // v220: Build financial section — different for free vs paid
            $financial_html = '';
            if ($is_free) {
                $financial_html = '<div style="background:#ECFDF5;border:2px solid #A7F3D0;border-radius:8px;padding:20px;text-align:center;margin-bottom:16px;">
                    <p style="margin:0;font-size:10px;color:#047857;text-transform:uppercase;letter-spacing:1px;font-weight:600;">Session Type</p>
                    <p style="margin:4px 0 0;font-size:28px;font-weight:700;color:#047857;">FREE SESSION</p>
                    <p style="margin:4px 0 0;font-size:12px;color:#6B7280;">' . esc_html($booking->coupon_code ?? $booking->app_code ?? 'Promotional') . '</p>
                </div>';
            } else {
                $financial_html = '<div style="display:flex;gap:12px;margin-bottom:16px;">
            <div style="flex:1;background:#ECFDF5;border:1px solid #A7F3D0;border-radius:8px;padding:16px;text-align:center;">
                <p style="margin:0;font-size:10px;color:#047857;text-transform:uppercase;letter-spacing:1px;font-weight:600;">Total Paid</p>
                <p style="margin:4px 0 0;font-size:24px;font-weight:700;color:#047857;">$' . number_format($total_amount, 2) . '</p>
            </div>
            <div style="flex:1;background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:16px;text-align:center;">
                <p style="margin:0;font-size:10px;color:#92400E;text-transform:uppercase;letter-spacing:1px;font-weight:600;">Trainer Payout</p>
                <p style="margin:4px 0 0;font-size:24px;font-weight:700;color:#92400E;">$' . number_format($db_payout, 2) . '</p>
            </div>
            <div style="flex:1;background:#EFF6FF;border:1px solid #93C5FD;border-radius:8px;padding:16px;text-align:center;">
                <p style="margin:0;font-size:10px;color:#1E40AF;text-transform:uppercase;letter-spacing:1px;font-weight:600;">Platform Fee</p>
                <p style="margin:4px 0 0;font-size:24px;font-weight:700;color:#1E40AF;">$' . number_format($db_fee, 2) . '</p>
            </div>
        </div>
        ' . $fee_badge;
            }
            
            $admin_subject = '[' . ptp_email_brand('company') . '] New Booking ' . ($booking->booking_number ?? '#' . $booking_id) . ' - ' . $player_name . ' w/ ' . $trainer->display_name . ($is_free ? ' - FREE SESSION' : ' - $' . number_format($total_amount, 2));
            
            $admin_body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f9fafb;font-family:Inter,-apple-system,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;padding:32px 16px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
    <tr><td style="background:#0A0A0A;padding:20px 24px;text-align:center;">
        <span style="font-size:24px;font-weight:700;color:#FCB900;letter-spacing:3px;">PTP</span>
        <span style="font-size:11px;color:rgba(255,255,255,0.5);letter-spacing:2px;text-transform:uppercase;margin-left:8px;">ADMIN</span>
    </td></tr>
    
    <tr><td style="background:#fff;padding:24px;border:1px solid #e5e7eb;border-top:none;">
        <h1 style="margin:0 0 4px;font-size:20px;color:#0A0A0A;">New Training Booking</h1>
        <p style="margin:0 0 20px;font-size:13px;color:#6B7280;">' . esc_html($booking->booking_number ?? '') . ' | ' . current_time('M j, Y g:i A') . '</p>
        
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:16px;">
            <p style="margin:0 0 4px;font-size:10px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:1px;">Session Details</p>
            <p style="margin:4px 0;color:#111;"><strong>Trainer:</strong> ' . esc_html($trainer->display_name) . '</p>
            <p style="margin:4px 0;color:#111;"><strong>Player:</strong> ' . esc_html($player_name) . ($player_age ? ' (age ' . esc_html($player_age) . ')' : '') . '</p>
            <p style="margin:4px 0;color:#111;"><strong>Package:</strong> ' . esc_html($package_display) . '</p>
            <p style="margin:4px 0;color:#111;"><strong>Date:</strong> ' . esc_html($date_display) . '</p>
            <p style="margin:4px 0;color:#111;"><strong>Time:</strong> ' . esc_html($time_display) . '</p>
            <p style="margin:4px 0;color:#111;"><strong>Location:</strong> ' . esc_html($location_display) . '</p>
        </div>
        
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:16px;">
            <p style="margin:0 0 4px;font-size:10px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:1px;">Parent Contact</p>
            <p style="margin:4px 0;color:#111;"><strong>Name:</strong> ' . esc_html($parent_name_display) . '</p>
            <p style="margin:4px 0;color:#111;"><strong>Email:</strong> <a href="mailto:' . esc_attr($parent_email) . '">' . esc_html($parent_email) . '</a></p>
            <p style="margin:4px 0;color:#111;"><strong>Phone:</strong> ' . esc_html($booking->parent_phone ?: '-') . '</p>
        </div>
        
        ' . $financial_html . '
        
        ' . ($medical_info ? '<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:16px;margin-bottom:16px;">
            <p style="margin:0 0 4px;font-size:10px;font-weight:600;color:#DC2626;text-transform:uppercase;letter-spacing:1px;">Medical / Health Info</p>
            <p style="margin:4px 0;color:#7F1D1D;">' . esc_html($medical_info) . '</p>
        </div>' : '') . '
        
        <a href="' . esc_url(admin_url('admin.php?page=ptp-bookings')) . '" style="display:block;background:#0A0A0A;color:#FCB900;text-decoration:none;padding:14px;text-align:center;font-weight:700;font-size:14px;text-transform:uppercase;letter-spacing:1px;border-radius:6px;margin-bottom:12px;">View Booking</a>
        
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
        <tr>
            <td width="50%" style="padding-right:6px;">
                <a href="' . esc_url(admin_url('admin.php?page=ptp-parents' . ($booking->parent_id ? '&action=view&id=' . $booking->parent_id : ''))) . '" style="display:block;background:#f9fafb;border:1px solid #e5e7eb;color:#111;text-decoration:none;padding:10px;text-align:center;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;border-radius:6px;">View Parent</a>
            </td>
            <td width="50%" style="padding-left:6px;">
                <a href="' . esc_url(admin_url('admin.php?page=ptp-trainers&action=view&id=' . $trainer_id)) . '" style="display:block;background:#f9fafb;border:1px solid #e5e7eb;color:#111;text-decoration:none;padding:10px;text-align:center;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;border-radius:6px;">View Trainer</a>
            </td>
        </tr>
        </table>
        
        ' . (!empty($booking->parent_phone) ? '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
        <tr>
            <td width="50%" style="padding-right:6px;">
                <a href="sms:' . esc_attr($booking->parent_phone) . '" style="display:block;background:#ECFDF5;border:1px solid #A7F3D0;color:#047857;text-decoration:none;padding:10px;text-align:center;font-weight:600;font-size:12px;border-radius:6px;">Text Parent</a>
            </td>
            <td width="50%" style="padding-left:6px;">
                <a href="sms:' . esc_attr($trainer->phone ?? $trainer->user_email ?? '') . '" style="display:block;background:#EFF6FF;border:1px solid #93C5FD;color:#1E40AF;text-decoration:none;padding:10px;text-align:center;font-weight:600;font-size:12px;border-radius:6px;">Text Trainer</a>
            </td>
        </tr>
        </table>' : '') . '
    </td></tr>
</table>
</td></tr></table></body></html>';
            
            $sent = wp_mail($admin_email, $admin_subject, $admin_body, $headers);
            ptp_log('[PTP Notify v213] Admin email ' . ($sent ? 'SENT' : 'FAILED') . ' to: ' . $admin_email);
        }
        
        // Trigger action for other integrations
        do_action('ptp_training_booked', $booking_id, $trainer_id, $booking);
    }
    
    /**
     * Generate trainer booking email HTML
     */
    private function get_trainer_booking_email($trainer, $booking, $player_name, $date_display, $time_display, $location_display, $package_display) {
        $dashboard_url = home_url('/trainer-dashboard/');
        // v220: Dynamic earnings calculation
        $payout = floatval($booking->trainer_payout ?? 0);
        if ($payout <= 0 && floatval($booking->total_amount ?? 0) > 0) {
            $fee_calc = self::calculate_graduated_fee(
                $booking->trainer_id ?? 0,
                $booking->parent_id ?? 0,
                floatval($booking->total_amount),
                intval($booking->total_sessions ?? 1)
            );
            $payout = $fee_calc['trainer_payout'];
        }
        $earnings = number_format($payout, 2);
        $needs_confirmation = empty($booking->session_date) || $booking->session_date === '0000-00-00';
        
        // v213: Parent contact info
        $parent_name = trim(($booking->parent_first ?? '') . ' ' . ($booking->parent_last ?? ''));
        $parent_phone = $booking->parent_phone ?? '';
        $parent_email_addr = $booking->parent_email ?? $booking->wp_user_email ?? $booking->guest_email ?? '';
        
        // v213: Player age
        $player_age = '';
        if (!empty($booking->player_dob)) {
            $player_age = (string) date_diff(date_create($booking->player_dob), date_create('today'))->y;
        } elseif (!empty($booking->player_age)) {
            $player_age = (string) $booking->player_age;
        }
        
        // v213: Medical info
        $medical_info = trim($booking->parent_medical_info ?? '');
        
        // v213: Location address
        $location_address = trim($booking->location_notes ?? '');
        // Strip GPS coords for display
        $location_address_clean = $location_address ? preg_replace('/\s*\|\s*GPS:.*$/', '', $location_address) : '';
        
        // v213: Group players
        $group_players_html = '';
        if (!empty($booking->group_players)) {
            $gp = json_decode($booking->group_players, true);
            if (is_array($gp) && count($gp) > 0) {
                $names = array();
                foreach ($gp as $p) {
                    $names[] = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                }
                $group_players_html = implode(', ', array_filter($names));
            }
        }
        
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0A0A0A;font-family:Inter,-apple-system,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0A0A0A;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
                    <!-- Header -->
                    <tr>
                        <td style="text-align:center;padding-bottom:32px;">
                            <div style="font-family:Oswald,sans-serif;font-size:32px;font-weight:700;color:#FCB900;letter-spacing:3px;">PTP</div>
                            <div style="font-size:11px;color:rgba(255,255,255,0.5);letter-spacing:2px;text-transform:uppercase;margin-top:4px;">TRAINING</div>
                        </td>
                    </tr>
                    
                    <!-- Main Card -->
                    <tr>
                        <td style="background:#1a1a1a;border:2px solid #FCB900;padding:32px;">
                            <h1 style="font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:#FCB900;margin:0 0 8px;text-transform:uppercase;">New Training Booked! 🎉</h1>
                            <p style="color:rgba(255,255,255,0.7);font-size:16px;margin:0 0 24px;">Great news, ' . esc_html($trainer->display_name) . '! A new session has been booked.</p>
                            
                            <!-- Session Details -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(252,185,0,0.1);border:1px solid rgba(252,185,0,0.3);margin-bottom:24px;">
                                <tr>
                                    <td style="padding:20px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Player</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($player_name) . '</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Package</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($package_display) . '</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Date</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($date_display) . '</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Time</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($time_display) . '</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 0;">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Location</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($location_display) . '</span>
                                                    ' . ($location_address_clean ? '<br><span style="font-size:13px;color:rgba(255,255,255,0.5);">' . esc_html($location_address_clean) . '</span>' : '') . '
                                                </td>
                                            </tr>
                                            ' . ($player_age ? '
                                            <tr>
                                                <td style="padding:8px 0;border-top:1px solid rgba(255,255,255,0.1);">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Player Age</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($player_age) . ' years old</span>
                                                </td>
                                            </tr>' : '') . '
                                            ' . ($group_players_html ? '
                                            <tr>
                                                <td style="padding:8px 0;border-top:1px solid rgba(255,255,255,0.1);">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Group Players</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($group_players_html) . '</span>
                                                </td>
                                            </tr>' : '') . '
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- v213: Parent Contact Info -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);margin-bottom:24px;">
                                <tr>
                                    <td style="padding:20px;">
                                        <p style="font-size:10px;color:#22C55E;text-transform:uppercase;letter-spacing:1px;font-weight:600;margin:0 0 12px;">Parent / Guardian Contact</p>
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            ' . ($parent_name ? '
                                            <tr>
                                                <td style="padding:4px 0;">
                                                    <span style="font-size:13px;color:rgba(255,255,255,0.5);">Name:</span>
                                                    <span style="font-size:15px;color:#fff;font-weight:600;margin-left:8px;">' . esc_html($parent_name) . '</span>
                                                </td>
                                            </tr>' : '') . '
                                            ' . ($parent_phone ? '
                                            <tr>
                                                <td style="padding:4px 0;">
                                                    <span style="font-size:13px;color:rgba(255,255,255,0.5);">Phone:</span>
                                                    <a href="tel:' . esc_attr($parent_phone) . '" style="font-size:15px;color:#22C55E;font-weight:600;margin-left:8px;text-decoration:none;">' . esc_html($parent_phone) . '</a>
                                                </td>
                                            </tr>' : '') . '
                                            ' . ($parent_email_addr ? '
                                            <tr>
                                                <td style="padding:4px 0;">
                                                    <span style="font-size:13px;color:rgba(255,255,255,0.5);">Email:</span>
                                                    <a href="mailto:' . esc_attr($parent_email_addr) . '" style="font-size:15px;color:#22C55E;font-weight:600;margin-left:8px;text-decoration:none;">' . esc_html($parent_email_addr) . '</a>
                                                </td>
                                            </tr>' : '') . '
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            ' . ($medical_info ? '
                            <!-- v213: Medical Alert -->
                            <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);padding:16px;margin-bottom:24px;">
                                <p style="color:#EF4444;font-weight:600;margin:0 0 8px;">⚠ Medical / Health Info</p>
                                <p style="color:rgba(255,255,255,0.7);font-size:14px;margin:0;">' . esc_html($medical_info) . '</p>
                            </div>
                            ' : '') . '
                            
                            <!-- Earnings -->
                            <div style="background:#FCB900;padding:16px 20px;margin-bottom:24px;text-align:center;">
                                <span style="font-size:11px;color:#0A0A0A;text-transform:uppercase;letter-spacing:1px;font-weight:600;">Your Earnings</span><br>
                                <span style="font-family:Oswald,sans-serif;font-size:32px;font-weight:700;color:#0A0A0A;">$' . $earnings . '</span>
                            </div>
                            
                            ' . ($needs_confirmation ? '
                            <!-- Action Required -->
                            <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);padding:16px;margin-bottom:24px;">
                                <p style="color:#EF4444;font-weight:600;margin:0 0 8px;">⚠️ Action Required</p>
                                <p style="color:rgba(255,255,255,0.7);font-size:14px;margin:0;">Contact the parent to confirm the session date, time, and location.</p>
                            </div>
                            ' : '
                            <!-- Confirmed -->
                            <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);padding:16px;margin-bottom:24px;">
                                <p style="color:#22C55E;font-weight:600;margin:0 0 8px;">✓ Session Confirmed</p>
                                <p style="color:rgba(255,255,255,0.7);font-size:14px;margin:0;">Make sure to reach out to the parent before the session to introduce yourself.</p>
                            </div>
                            ') . '
                            
                            <!-- CTA -->
                            <a href="' . esc_url($dashboard_url) . '" style="display:block;background:#FCB900;color:#0A0A0A;text-decoration:none;padding:16px 24px;text-align:center;font-family:Oswald,sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">View Full Details →</a>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="text-align:center;padding-top:32px;">
                            <p style="color:rgba(255,255,255,0.4);font-size:12px;margin:0;">Players Teaching Players</p>
                            <p style="color:rgba(255,255,255,0.3);font-size:11px;margin:8px 0 0;">Questions? Reply to this email or text us.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Generate parent booking confirmation email HTML
     */
    private function get_parent_booking_email($trainer, $booking, $player_name, $date_display, $time_display, $location_display, $package_display) {
        $level_labels = array('pro'=>'Professional','college_d1'=>'NCAA Division 1','college_d2'=>'NCAA Division 2','college_d3'=>'NCAA Division 3','academy'=>'Academy/ECNL','semi_pro'=>'Semi-Professional');
        $trainer_level = $level_labels[$trainer->playing_level] ?? 'Pro Trainer';
        $trainer_photo = $trainer->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($trainer->display_name) . '&size=120&background=FCB900&color=0A0A0A&bold=true';
        $needs_confirmation = empty($booking->session_date) || $booking->session_date === '0000-00-00';
        $amount_paid = number_format($booking->amount_paid, 2);
        // v213: Booking number and location address for parent email
        $booking_number = $booking->booking_number ?? '';
        $location_notes = trim($booking->location_notes ?? '');
        $location_address_clean = $location_notes ? preg_replace('/\s*\|\s*GPS:.*$/', '', $location_notes) : '';
        
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0A0A0A;font-family:Inter,-apple-system,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0A0A0A;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
                    <!-- Header -->
                    <tr>
                        <td style="text-align:center;padding-bottom:32px;">
                            <div style="font-family:Oswald,sans-serif;font-size:32px;font-weight:700;color:#FCB900;letter-spacing:3px;">PTP</div>
                            <div style="font-size:11px;color:rgba(255,255,255,0.5);letter-spacing:2px;text-transform:uppercase;margin-top:4px;">PRIVATE TRAINING</div>
                        </td>
                    </tr>
                    
                    <!-- Success Badge -->
                    <tr>
                        <td style="text-align:center;padding-bottom:24px;">
                            <span style="display:inline-block;background:#22C55E;color:#fff;font-size:12px;font-weight:600;padding:8px 20px;text-transform:uppercase;letter-spacing:1px;">✓ Booking Confirmed</span>
                        </td>
                    </tr>
                    
                    <!-- Main Card -->
                    <tr>
                        <td style="background:#1a1a1a;border:2px solid rgba(255,255,255,0.1);padding:32px;">
                            <h1 style="font-family:Oswald,sans-serif;font-size:24px;font-weight:700;color:#fff;margin:0 0 8px;text-transform:uppercase;">' . esc_html($player_name) . ' IS LOCKED IN! 🔥</h1>
                            ' . ($booking_number ? '<p style="color:rgba(255,255,255,0.4);font-size:12px;margin:0 0 4px;letter-spacing:1px;">BOOKING ' . esc_html($booking_number) . '</p>' : '') . '
                            <p style="color:rgba(255,255,255,0.6);font-size:15px;margin:0 0 28px;">Training session booked successfully. Here are your details:</p>
                            
                            <!-- Trainer Card -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(252,185,0,0.08);border:2px solid #FCB900;margin-bottom:24px;">
                                <tr>
                                    <td style="padding:20px;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="width:80px;vertical-align:top;">
                                                    <img src="' . esc_url($trainer_photo) . '" width="70" height="70" style="border-radius:50%;border:3px solid #FCB900;" alt="">
                                                </td>
                                                <td style="vertical-align:top;padding-left:16px;">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Your Trainer</span><br>
                                                    <span style="font-family:Oswald,sans-serif;font-size:20px;font-weight:700;color:#fff;">' . esc_html($trainer->display_name) . '</span><br>
                                                    <span style="display:inline-block;background:#FCB900;color:#0A0A0A;font-size:10px;font-weight:600;padding:4px 10px;margin-top:6px;text-transform:uppercase;letter-spacing:1px;">' . esc_html($trainer_level) . '</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Session Details -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                                <tr>
                                    <td style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                        <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">📅 Date</span><br>
                                        <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($date_display) . '</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                        <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">🕐 Time</span><br>
                                        <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($time_display) . '</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                        <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">📍 Location</span><br>
                                        <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($location_display) . '</span>
                                        ' . ($location_address_clean ? '<br><span style="font-size:13px;color:rgba(255,255,255,0.5);">' . esc_html($location_address_clean) . '</span>' : '') . '
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                        <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">📦 Package</span><br>
                                        <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($package_display) . '</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 0;">
                                        <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">💳 Amount Paid</span><br>
                                        <span style="font-size:16px;color:#FCB900;font-weight:700;">$' . $amount_paid . '</span>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- What\'s Next -->
                            <div style="background:rgba(255,255,255,0.05);padding:20px;margin-bottom:24px;">
                                <p style="font-family:Oswald,sans-serif;font-size:14px;font-weight:600;color:#FCB900;margin:0 0 12px;text-transform:uppercase;letter-spacing:1px;">What\'s Next</p>
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding:8px 0;">
                                            <span style="display:inline-block;width:24px;height:24px;background:#FCB900;color:#0A0A0A;font-size:12px;font-weight:700;text-align:center;line-height:24px;margin-right:12px;">1</span>
                                            <span style="color:#fff;font-size:14px;">' . esc_html($trainer->display_name) . ' will reach out to confirm details</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;">
                                            <span style="display:inline-block;width:24px;height:24px;background:#FCB900;color:#0A0A0A;font-size:12px;font-weight:700;text-align:center;line-height:24px;margin-right:12px;">2</span>
                                            <span style="color:#fff;font-size:14px;">Bring water, cleats, and a ball if you have one</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;">
                                            <span style="display:inline-block;width:24px;height:24px;background:#FCB900;color:#0A0A0A;font-size:12px;font-weight:700;text-align:center;line-height:24px;margin-right:12px;">3</span>
                                            <span style="color:#fff;font-size:14px;">Show up ready to train and have fun!</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <p style="color:rgba(255,255,255,0.5);font-size:13px;text-align:center;margin:0;">Questions? Just reply to this email or text us anytime.</p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="text-align:center;padding-top:32px;">
                            <p style="color:rgba(255,255,255,0.4);font-size:12px;margin:0;">Players Teaching Players</p>
                            <p style="color:rgba(255,255,255,0.3);font-size:11px;margin:8px 0 0;">Teaching what team coaches don\'t.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
}

// Initialize
PTP_Unified_Checkout::instance();
