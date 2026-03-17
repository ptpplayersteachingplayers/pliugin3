<?php
/**
 * PTP Coupon Tracker v175
 * 
 * Handles coupon usage tracking, free code redemption,
 * and referral credit awarding after successful purchases.
 */

if (!defined('ABSPATH')) exit;

class PTP_Coupon_Tracker {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hook into camp order completion
        add_action('ptp_camp_order_completed', array($this, 'process_camp_order_coupon'), 10, 2);
        
        // Hook into training booking completion
        add_action('ptp_booking_completed', array($this, 'process_training_coupon'), 10, 2);
        
        // Hook into unified checkout completion
        add_action('ptp_checkout_completed', array($this, 'process_checkout_coupon'), 10, 2);
        
        // AJAX coupon validation (used by checkout)
        add_action('wp_ajax_ptp_validate_coupon', array(__CLASS__, 'ajax_validate_coupon'));
        add_action('wp_ajax_nopriv_ptp_validate_coupon', array(__CLASS__, 'ajax_validate_coupon'));

        // Seed built-in coupons
        add_action('admin_init', array(__CLASS__, 'maybe_seed_coupons'), 999);
    }
    
    /**
     * AJAX handler for coupon validation at checkout
     */
    public static function ajax_validate_coupon() {
        $code = sanitize_text_field($_POST['code'] ?? $_GET['code'] ?? '');
        $amount = floatval($_POST['amount'] ?? $_GET['amount'] ?? 0);
        $item_type = sanitize_text_field($_POST['item_type'] ?? $_GET['item_type'] ?? 'all');
        
        if (empty($code)) {
            wp_send_json_success(array('valid' => false, 'message' => 'Please enter a code'));
            return;
        }
        
        $result = self::validate_coupon($code, $amount, $item_type);
        wp_send_json_success($result);
    }
    
    /**
     * Process coupon after camp order
     */
    public function process_camp_order_coupon($order_id, $order = null) {
        if (!$order_id) return;
        
        global $wpdb;
        
        // v216.1: Handle both data shapes from unified checkout and camps plugin
        if (is_array($order)) {
            // Camps plugin path — coupon usage already incremented in handle_confirm_booking().
            // Extract coupon_code from the booking row for logging/tracking only.
            $coupon_code = $order['coupon_code'] ?? '';
            if (!empty($coupon_code)) {
                ptp_log("[PTP Coupon Tracker] Camp booking $order_id used coupon: $coupon_code (already incremented by camps plugin)");
            }
            return;
        }
        
        // Get order if not provided (unified checkout path)
        if (!$order) {
            $order = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE id = %d",
                $order_id
            ));
        }
        
        if (!$order) return;
        
        // Check for coupon in order meta or payment intent metadata
        $coupon_code = '';
        $coupon_id = 0;
        $free_code_id = 0;
        
        // Try to get from Stripe payment intent metadata
        if (!empty($order->stripe_payment_intent_id)) {
            $metadata = $this->get_payment_intent_metadata($order->stripe_payment_intent_id);
            $coupon_code = $metadata['coupon_code'] ?? '';
            $coupon_id = intval($metadata['coupon_id'] ?? 0);
            $free_code_id = intval($metadata['free_code_id'] ?? 0);
        }
        
        // Process coupon usage
        if ($coupon_id > 0) {
            $this->increment_coupon_usage($coupon_id);
            ptp_log("[PTP Coupon Tracker] Incremented usage for coupon ID {$coupon_id} on order {$order_id}");
        }
        
        // Process free code redemption
        if ($free_code_id > 0) {
            $this->mark_free_code_used($free_code_id, null, $order_id);
            ptp_log("[PTP Coupon Tracker] Marked free code ID {$free_code_id} as used on order {$order_id}");
        }
        
        // Process referral code
        $referral_code = $metadata['referral_code'] ?? '';
        if (!empty($referral_code)) {
            $this->process_referral_conversion($referral_code, $order->user_id ?? 0, $order_id, 'camp');
        }
    }
    
    /**
     * Process coupon after training booking
     */
    public function process_training_coupon($booking_id, $booking = null) {
        if (!$booking_id) return;
        
        global $wpdb;
        
        // Get booking if not provided
        if (!$booking) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                $booking_id
            ));
        }
        
        if (!$booking) return;
        
        // Check for coupon in booking meta
        $coupon_code = get_post_meta($booking_id, '_coupon_code', true) ?: 
                       ($booking->coupon_code ?? '');
        $coupon_id = intval(get_post_meta($booking_id, '_coupon_id', true) ?: 0);
        $free_code_id = intval(get_post_meta($booking_id, '_free_code_id', true) ?: 0);
        
        // Try payment intent metadata
        if (empty($coupon_code) && !empty($booking->stripe_payment_intent_id)) {
            $metadata = $this->get_payment_intent_metadata($booking->stripe_payment_intent_id);
            $coupon_code = $metadata['coupon_code'] ?? '';
            $coupon_id = intval($metadata['coupon_id'] ?? 0);
            $free_code_id = intval($metadata['free_code_id'] ?? 0);
        }
        
        // Process coupon usage
        if ($coupon_id > 0) {
            $this->increment_coupon_usage($coupon_id);
            ptp_log("[PTP Coupon Tracker] Incremented usage for coupon ID {$coupon_id} on booking {$booking_id}");
        }
        
        // Process free code redemption
        if ($free_code_id > 0) {
            $this->mark_free_code_used($free_code_id, $booking_id, null);
            ptp_log("[PTP Coupon Tracker] Marked free code ID {$free_code_id} as used on booking {$booking_id}");
        }
        
        // Process referral
        $referral_code = get_post_meta($booking_id, '_referral_code', true) ?: 
                        ($metadata['referral_code'] ?? '');
        if (!empty($referral_code)) {
            $parent_id = $booking->parent_id ?? 0;
            $user_id = 0;
            if ($parent_id) {
                $parent = $wpdb->get_row($wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->prefix}ptp_parents WHERE id = %d",
                    $parent_id
                ));
                $user_id = $parent->user_id ?? 0;
            }
            $this->process_referral_conversion($referral_code, $user_id, $booking_id, 'training');
        }
    }
    
    /**
     * Process coupon from checkout data
     */
    public function process_checkout_coupon($checkout_data, $result) {
        if (empty($checkout_data)) return;
        
        $coupon_id = intval($checkout_data['coupon_id'] ?? 0);
        $free_code_id = intval($checkout_data['free_code_id'] ?? 0);
        $referral_code = $checkout_data['referral_code'] ?? '';
        $order_id = $result['order_id'] ?? 0;
        $booking_id = $result['booking_id'] ?? 0;
        $user_id = $checkout_data['user_id'] ?? get_current_user_id();
        
        if ($coupon_id > 0) {
            $this->increment_coupon_usage($coupon_id);
        }
        
        if ($free_code_id > 0) {
            $this->mark_free_code_used($free_code_id, $booking_id, $order_id);
        }
        
        if (!empty($referral_code)) {
            $type = $order_id ? 'camp' : 'training';
            $this->process_referral_conversion($referral_code, $user_id, $order_id ?: $booking_id, $type);
        }
    }
    
    /**
     * Increment coupon usage count
     */
    public function increment_coupon_usage($coupon_id) {
        if (!$coupon_id) return false;
        
        global $wpdb;
        
        // Try ptp_coupons table first
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_coupons SET usage_count = usage_count + 1 WHERE id = %d",
            $coupon_id
        ));
        
        if ($result === false || $wpdb->rows_affected === 0) {
            // Try legacy table
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_camp_coupons SET usage_count = usage_count + 1 WHERE id = %d",
                $coupon_id
            ));
        }
        
        return true;
    }
    
    /**
     * Mark free session code as used
     */
    public function mark_free_code_used($free_code_id, $booking_id = null, $order_id = null) {
        if (!$free_code_id) return false;
        
        global $wpdb;
        $free_table = $wpdb->prefix . 'ptp_free_session_codes';
        
        // v197.1: Guard against missing table
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $free_table)) !== $free_table) {
            ptp_log('[PTP Coupon Tracker] ptp_free_session_codes table missing — cannot mark code used');
            return false;
        }
        
        $update_data = array(
            'used' => 1,
            'used_at' => current_time('mysql')
        );
        $format = array('%d', '%s');
        
        if ($booking_id) {
            $update_data['booking_id'] = $booking_id;
            $format[] = '%d';
        }
        
        return $wpdb->update(
            $free_table,
            $update_data,
            array('id' => $free_code_id),
            $format,
            array('%d')
        );
    }
    
    /**
     * Process referral code conversion — v193: Tiered Rewards
     * 
     * Tier 1 (1 referral):  $25 store credit
     * Tier 2 (2 referrals): Free 1-on-1 session (store as $85 credit + flag)
     * Tier 3 (3 referrals): Free session + priority WC booking flag
     * Tier 5 (5 referrals): Free week of summer camp (store as $170 credit + flag)
     */
    public function process_referral_conversion($referral_code, $referred_user_id, $conversion_id, $type = 'camp') {
        if (empty($referral_code)) return false;
        
        global $wpdb;
        
        // Find the referrer
        $referral = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_referral_codes WHERE code = %s",
            $referral_code
        ));
        
        if (!$referral) {
            // Try referrals table
            $referral = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_referrals WHERE referral_code = %s",
                $referral_code
            ));
        }
        
        if (!$referral) return false;
        
        $referrer_id = $referral->user_id ?? $referral->referrer_id ?? 0;
        if (!$referrer_id) return false;
        
        // v193: Count total conversions for this referrer (including this one)
        // Try ptp_referral_codes.times_used first, then count from ptp_referrals table
        $times_used = 0;
        $ref_codes_table = $wpdb->prefix . 'ptp_referral_codes';
        if ($wpdb->get_var("SHOW TABLES LIKE '$ref_codes_table'") === $ref_codes_table) {
            $times_used = intval($wpdb->get_var($wpdb->prepare(
                "SELECT times_used FROM $ref_codes_table WHERE code = %s",
                $referral_code
            )));
        }
        if ($times_used === 0) {
            // Fallback: count converted referrals from ptp_referrals table
            $ref_table = $wpdb->prefix . 'ptp_referrals';
            if ($wpdb->get_var("SHOW TABLES LIKE '$ref_table'") === $ref_table) {
                $times_used = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $ref_table WHERE referrer_id = %d AND status = 'converted'",
                    $referrer_id
                )));
            }
        }
        $total_referrals = $times_used + 1; // +1 for current conversion
        
        // v193: Determine tiered reward
        $credit_amount = 0;
        $reward_type = '';
        $reward_description = '';
        
        // Check which tier milestone this referral hits
        if ($total_referrals == 1) {
            // Tier 1: $25 credit
            $credit_amount = 25.00;
            $reward_type = 'credit';
            $reward_description = 'Referral Tier 1: $25 credit for 1st referral';
        } elseif ($total_referrals == 2) {
            // Tier 2: Free 1-on-1 session ($85 value)
            $credit_amount = 85.00;
            $reward_type = 'free_session';
            $reward_description = 'Referral Tier 2: Free 1-on-1 session for 2nd referral';
            update_user_meta($referrer_id, 'ptp_free_session_earned', current_time('mysql'));
            update_user_meta($referrer_id, 'ptp_free_session_redeemed', '0');
        } elseif ($total_referrals == 3) {
            // Tier 3: Another free session + priority WC booking
            $credit_amount = 85.00;
            $reward_type = 'free_session_priority';
            $reward_description = 'Referral Tier 3: Free session + priority World Cup camp booking for 3rd referral';
            update_user_meta($referrer_id, 'ptp_wc_priority_booking', '1');
            update_user_meta($referrer_id, 'ptp_free_session_earned_tier3', current_time('mysql'));
        } elseif ($total_referrals == 5) {
            // Tier 5: Free week of summer camp ($170 value)
            $credit_amount = 170.00;
            $reward_type = 'free_camp_week';
            $reward_description = 'Referral Tier 5: Free week of summer camp for 5th referral';
            update_user_meta($referrer_id, 'ptp_free_camp_week_earned', current_time('mysql'));
            update_user_meta($referrer_id, 'ptp_free_camp_week_redeemed', '0');
        } else {
            // Non-milestone referrals: $25 credit each
            $credit_amount = 25.00;
            $reward_type = 'credit';
            $reward_description = 'Referral bonus for new ' . ($type === 'camp' ? 'camp registration' : 'training booking') . ' (referral #' . $total_referrals . ')';
        }
        
        // Award credit
        $credits_table = $wpdb->prefix . 'ptp_referral_credits';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$credits_table'") === $credits_table;
        
        // v193: Create table if missing
        if (!$table_exists) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE IF NOT EXISTS $credits_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id bigint(20) UNSIGNED NOT NULL,
                amount decimal(10,2) NOT NULL,
                type varchar(32) NOT NULL DEFAULT 'earned',
                reference_id bigint(20) UNSIGNED DEFAULT NULL,
                description varchar(255) DEFAULT NULL,
                expires_at datetime DEFAULT NULL,
                redeemed_at datetime DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY type (type)
            ) $charset;");
            $table_exists = true;
            ptp_log("[PTP Referral v193] Created ptp_referral_credits table");
        }
        
        if ($table_exists) {
            $wpdb->insert(
                $credits_table,
                array(
                    'user_id'      => $referrer_id,
                    'amount'       => $credit_amount,
                    'type'         => 'earned',
                    'reference_id' => $referral->id ?? 0,
                    'description'  => $reward_description,
                    'created_at'   => current_time('mysql'),
                    'expires_at'   => date('Y-m-d H:i:s', strtotime('+1 year'))
                ),
                array('%d', '%f', '%s', '%d', '%s', '%s', '%s')
            );
            
            if ($wpdb->last_error) {
                ptp_log("[PTP Referral v193] DB error inserting credit: " . $wpdb->last_error);
            } else {
                ptp_log("[PTP Referral v193] Tier reward: {$reward_type} (\${$credit_amount}) to user {$referrer_id} for referral #{$total_referrals}");
            }
        }
        
        // v193: Store referral tier milestone on user meta for dashboard display
        update_user_meta($referrer_id, 'ptp_referral_count', $total_referrals);
        update_user_meta($referrer_id, 'ptp_referral_tier', $this->get_tier_name($total_referrals));
        
        // Update referral status
        if (!empty($referral->id)) {
            if (isset($referral->referrer_id)) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_referrals',
                    array(
                        'referred_id' => $referred_user_id,
                        'status' => 'converted',
                        'conversion_' . ($type === 'camp' ? 'order_id' : 'booking_id') => $conversion_id,
                        'converted_at' => current_time('mysql')
                    ),
                    array('id' => $referral->id)
                );
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ptp_referral_codes SET times_used = times_used + 1 WHERE id = %d",
                    $referral->id
                ));
            }
        }
        
        return true;
    }
    
    /**
     * v193: Get tier name from referral count
     */
    private function get_tier_name($count) {
        if ($count >= 5) return 'legend';
        if ($count >= 3) return 'vip';
        if ($count >= 2) return 'gold';
        if ($count >= 1) return 'bronze';
        return 'none';
    }
    
    /**
     * Get Stripe PaymentIntent metadata
     */
    private function get_payment_intent_metadata($payment_intent_id) {
        if (empty($payment_intent_id)) return array();
        
        $secret_key = get_option('ptp_stripe_test_mode', true)
            ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
            : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
        
        if (empty($secret_key)) return array();
        
        $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
            'headers' => array('Authorization' => 'Bearer ' . $secret_key),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) return array();
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['metadata'] ?? array();
    }
    
    /**
     * Validate and apply coupon at checkout (static helper)
     */
    public static function validate_coupon($code, $amount = 0, $item_type = 'all') {
        global $wpdb;
        
        $code = strtoupper(trim($code));
        
        // v134: Check PTP- application codes (from free session applications)
        if (strpos($code, 'PTP-') === 0) {
            $app_table = $wpdb->prefix . 'ptp_session_applications';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $app_table)) === $app_table) {
                $app = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$app_table} WHERE app_code = %s AND status = 'accepted' LIMIT 1",
                    $code
                ));
                
                if ($app) {
                    // Check if already redeemed (has a booking linked)
                    $already_used = false;
                    if (class_exists('PTP_Free_Session_Conversion')) {
                        $already_used = !empty($app->first_booking_id);
                    }
                    if ($already_used) {
                        return array('valid' => false, 'message' => 'This free session code has already been used');
                    }
                    
                    $discount = $amount > 0 ? $amount : 85.00;
                    return array(
                        'valid' => true,
                        'type' => 'free_training',
                        'discount' => $discount,
                        'free_code_id' => 0,
                        'app_code' => $code,
                        'app_id' => intval($app->id),
                        'message' => 'Free training session applied! (' . esc_html($app->child_name) . ')'
                    );
                }
                
                // Also check pending apps — nudge them
                $pending = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$app_table} WHERE app_code = %s AND status = 'pending' LIMIT 1",
                    $code
                ));
                if ($pending) {
                    return array('valid' => false, 'message' => 'Your application is still being reviewed. We\'ll call within 24 hours!');
                }
            }
        }
        
        // Check FREE codes first (from ptp_free_session_codes table)
        if (strpos($code, 'FREE') === 0) {
            $free_table = $wpdb->prefix . 'ptp_free_session_codes';
            // v197.1: Guard against missing table
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $free_table)) === $free_table) {
                $free_code = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$free_table} 
                     WHERE code = %s AND used = 0 AND (expires_at IS NULL OR expires_at > NOW())",
                    $code
                ));
                
                if ($free_code) {
                    // 100% discount — covers the full session price
                    $discount = $amount > 0 ? $amount : 75.00;
                    return array(
                        'valid' => true,
                        'type' => 'free_training',
                        'discount' => $discount,
                        'free_code_id' => $free_code->id,
                        'message' => 'Free training session applied!'
                    );
                }
            }
        }
        
        // Check ptp_coupons
        $table = $wpdb->prefix . 'ptp_coupons';
        $has_table = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        $coupon = null;
        
        if ($has_table) {
            $coupon = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} 
                 WHERE code = %s AND status = 'active'
                 AND (starts_at IS NULL OR starts_at <= NOW())
                 AND (expires_at IS NULL OR expires_at > NOW())
                 AND (usage_limit = 0 OR usage_count < usage_limit)",
                $code
            ));
        }
        
        // v220: Hardcoded fallback for built-in free training codes
        // Works even if ptp_coupons table is missing or row was deleted
        if (!$coupon) {
            $builtin_free = array('FREETRAINING', 'PTPFREE', 'FIRSTFREE');
            if (in_array($code, $builtin_free, true)) {
                // Auto-seed the missing row so future lookups hit the DB
                if ($has_table) {
                    $descriptions = array(
                        'FREETRAINING' => 'Free 1-on-1 training session!',
                        'PTPFREE'      => 'Free 1-on-1 training session!',
                        'FIRSTFREE'    => 'Your first training session is on us!',
                    );
                    $wpdb->insert($table, array(
                        'code'           => $code,
                        'description'    => $descriptions[$code] ?? 'Free training session',
                        'discount_type'  => 'free_training',
                        'discount_value' => 0,
                        'discount_amount'=> 0,
                        'applies_to'     => 'training',
                        'usage_limit'    => 0,
                        'usage_count'    => 0,
                        'status'         => 'active',
                        'created_at'     => current_time('mysql'),
                    ));
                    $coupon_id = $wpdb->insert_id;
                }
                
                $discount = $amount > 0 ? $amount : 85.00;
                return array(
                    'valid'     => true,
                    'type'      => 'free_training',
                    'discount'  => $discount,
                    'coupon_id' => $coupon_id ?? 0,
                    'message'   => 'Free training session applied!',
                );
            }

            // v226: Hardcoded fallback for 50OFF promo
            if ($code === '50OFF') {
                if ($has_table) {
                    $wpdb->insert($table, array(
                        'code'           => '50OFF',
                        'description'    => '$50 off — early bird discount!',
                        'discount_type'  => 'fixed',
                        'discount_value' => 50,
                        'discount_amount'=> 50,
                        'applies_to'     => 'all',
                        'usage_limit'    => 0,
                        'usage_count'    => 0,
                        'status'         => 'active',
                        'created_at'     => current_time('mysql'),
                    ));
                    $coupon_id = $wpdb->insert_id;
                }
                $discount = min(50, $amount);
                return array(
                    'valid'     => true,
                    'type'      => 'fixed',
                    'discount'  => $discount,
                    'coupon_id' => $coupon_id ?? 0,
                    'message'   => '$50 off applied!',
                );
            }

            // v228: Hardcoded fallback for FREECAMP promo — 100% off any camp
            if ($code === 'FREECAMP') {
                if ($has_table) {
                    $wpdb->insert($table, array(
                        'code'           => 'FREECAMP',
                        'description'    => 'Free camp registration!',
                        'discount_type'  => 'free_camp',
                        'discount_value' => 0,
                        'discount_amount'=> 0,
                        'applies_to'     => 'all',
                        'usage_limit'    => 0,
                        'usage_count'    => 0,
                        'status'         => 'active',
                        'created_at'     => current_time('mysql'),
                    ));
                    $coupon_id = $wpdb->insert_id;
                }
                $discount = $amount > 0 ? $amount : 299.00;
                return array(
                    'valid'     => true,
                    'type'      => 'free_camp',
                    'discount'  => $discount,
                    'coupon_id' => $coupon_id ?? 0,
                    'message'   => 'Free camp — 100% off applied!',
                );
            }
        }
        
        if ($coupon) {
            // Check applies_to
            if ($item_type !== 'all' && $coupon->applies_to !== 'all' && $coupon->applies_to !== $item_type) {
                return array('valid' => false, 'message' => 'This code cannot be used for this item type');
            }
            
            // Check minimum spend
            if ($coupon->minimum_spend > 0 && $amount < $coupon->minimum_spend) {
                return array('valid' => false, 'message' => 'Minimum spend of $' . number_format($coupon->minimum_spend, 2) . ' required');
            }
            
            // Resolve discount amount — DB column may be discount_value or discount_amount
            $disc_amt = 0;
            if (isset($coupon->discount_amount) && floatval($coupon->discount_amount) > 0) {
                $disc_amt = floatval($coupon->discount_amount);
            } elseif (isset($coupon->discount_value) && floatval($coupon->discount_value) > 0) {
                $disc_amt = floatval($coupon->discount_value);
            }
            
            $max_disc = isset($coupon->maximum_discount) ? floatval($coupon->maximum_discount) : 0;
            
            // Calculate discount
            $discount = 0;
            if ($coupon->discount_type === 'free_training') {
                // 100% off — covers entire training price
                $discount = $amount > 0 ? $amount : 85.00;
                $msg = 'Free training session applied!';
            } elseif ($coupon->discount_type === 'free_camp') {
                // v228: 100% off — covers entire camp price
                $discount = $amount > 0 ? $amount : 299.00;
                $msg = 'Free camp — 100% off applied!';
            } elseif ($coupon->discount_type === 'percent') {
                $discount = round($amount * ($disc_amt / 100), 2);
                if ($max_disc > 0 && $discount > $max_disc) {
                    $discount = $max_disc;
                }
                $msg = intval($disc_amt) . '% off applied!';
            } else {
                // Fixed dollar amount
                $discount = $disc_amt;
                $msg = '$' . number_format($disc_amt, 2) . ' off applied!';
            }
            
            return array(
                'valid' => true,
                'type' => $coupon->discount_type,
                'discount' => $discount,
                'coupon_id' => $coupon->id,
                'message' => !empty($coupon->description) ? $coupon->description : $msg,
            );
        }
        
        return array('valid' => false, 'message' => 'Invalid promo code');
    }

    /**
     * Ensure ptp_coupons table has all needed columns and seed built-in codes
     */
    public static function maybe_seed_coupons() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_coupons';

        // Ensure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            if (class_exists('PTP_Database') && method_exists('PTP_Database', 'create_tables')) {
                PTP_Database::create_tables();
            }
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;
        }

        // Add missing columns if needed (only once)
        if (!get_option('ptp_coupons_seeded_v2')) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
            if (!in_array('discount_amount', $cols) && in_array('discount_value', $cols)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN discount_amount decimal(10,2) DEFAULT 0 AFTER discount_value");
                $wpdb->query("UPDATE {$table} SET discount_amount = discount_value WHERE discount_amount = 0 AND discount_value > 0");
            }
            if (!in_array('maximum_discount', $cols)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN maximum_discount decimal(10,2) DEFAULT 0 AFTER discount_amount");
            }
            update_option('ptp_coupons_seeded_v2', true);
        }

        // v220: Always verify built-in codes exist (self-healing)
        // Runs every admin_init but is just 3 fast SELECT queries
        $coupons = array(
            array(
                'code'           => 'FREETRAINING',
                'description'    => 'Free 1-on-1 training session!',
                'discount_type'  => 'free_training',
                'discount_value' => 0,
                'applies_to'     => 'training',
                'usage_limit'    => 0,
                'status'         => 'active',
            ),
            array(
                'code'           => 'PTPFREE',
                'description'    => 'Free 1-on-1 training session!',
                'discount_type'  => 'free_training',
                'discount_value' => 0,
                'applies_to'     => 'training',
                'usage_limit'    => 0,
                'status'         => 'active',
            ),
            array(
                'code'           => 'FIRSTFREE',
                'description'    => 'Your first training session is on us!',
                'discount_type'  => 'free_training',
                'discount_value' => 0,
                'applies_to'     => 'training',
                'usage_limit'    => 0,
                'status'         => 'active',
            ),
            array(
                'code'           => '50OFF',
                'description'    => '$50 off — early bird discount!',
                'discount_type'  => 'fixed',
                'discount_value' => 50,
                'applies_to'     => 'all',
                'usage_limit'    => 0,
                'status'         => 'active',
            ),
            array(
                'code'           => 'FREECAMP',
                'description'    => 'Free camp registration!',
                'discount_type'  => 'free_camp',
                'discount_value' => 0,
                'applies_to'     => 'all',
                'usage_limit'    => 0,
                'status'         => 'active',
            ),
        );

        foreach ($coupons as $c) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE code = %s", $c['code']));
            if (!$exists) {
                $wpdb->insert($table, array(
                    'code'           => $c['code'],
                    'description'    => $c['description'],
                    'discount_type'  => $c['discount_type'],
                    'discount_value' => $c['discount_value'],
                    'discount_amount'=> $c['discount_value'],
                    'applies_to'     => $c['applies_to'],
                    'usage_limit'    => $c['usage_limit'],
                    'usage_count'    => 0,
                    'status'         => $c['status'],
                    'created_at'     => current_time('mysql'),
                ));
            }
        }
    }
}

// Initialize
PTP_Coupon_Tracker::instance();
