<?php
/**
 * PTP Thank You Upsell Handler v175
 * 
 * Handles one-click upsell purchases from the thank you page:
 * - Training session upsell
 * - Additional camp upsell (with multi-camp discount)
 * 
 * Uses saved Stripe payment methods for frictionless checkout.
 */

if (!defined('ABSPATH')) exit;

class PTP_Thankyou_Upsell_Handler {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Training upsell
        add_action('wp_ajax_ptp_thankyou_add_training', array($this, 'add_training'));
        add_action('wp_ajax_nopriv_ptp_thankyou_add_training', array($this, 'add_training'));
        
        // Camp upsell
        add_action('wp_ajax_ptp_thankyou_add_camp', array($this, 'add_camp'));
        add_action('wp_ajax_nopriv_ptp_thankyou_add_camp', array($this, 'add_camp'));
    }
    
    /**
     * Get Stripe client
     */
    private function get_stripe() {
        if (!class_exists('PTP_Stripe')) {
            require_once PTP_PLUGIN_PATH . 'includes/class-ptp-stripe.php';
        }
        return PTP_Stripe::instance()->get_client();
    }
    
    /**
     * Get customer's saved payment method from original order
     */
    private function get_saved_payment_method($order) {
        $stripe = $this->get_stripe();
        
        // Try customer ID first
        if (!empty($order->stripe_customer_id)) {
            try {
                $customer = $stripe->customers->retrieve($order->stripe_customer_id);
                return [
                    'customer_id' => $order->stripe_customer_id,
                    'payment_method' => $customer->invoice_settings->default_payment_method ?? $customer->default_source ?? null
                ];
            } catch (\Exception $e) {
                ptp_log('[PTP Upsell] Error getting customer: ' . $e->getMessage());
            }
        }
        
        // Try payment intent
        if (!empty($order->stripe_payment_intent_id)) {
            try {
                $pi = $stripe->paymentIntents->retrieve($order->stripe_payment_intent_id);
                if ($pi->customer) {
                    $customer = $stripe->customers->retrieve($pi->customer);
                    return [
                        'customer_id' => $pi->customer,
                        'payment_method' => $pi->payment_method ?? $customer->invoice_settings->default_payment_method ?? null
                    ];
                }
            } catch (\Exception $e) {
                ptp_log('[PTP Upsell] Error getting payment intent: ' . $e->getMessage());
            }
        }
        
        return null;
    }
    
    /**
     * Charge saved payment method
     */
    private function charge_saved_payment($customer_id, $payment_method, $amount, $description, $metadata = []) {
        $stripe = $this->get_stripe();
        
        try {
            $payment_intent = $stripe->paymentIntents->create([
                'amount' => intval($amount * 100), // Convert to cents
                'currency' => 'usd',
                'customer' => $customer_id,
                'payment_method' => $payment_method,
                'off_session' => true,
                'confirm' => true,
                'description' => $description,
                'metadata' => array_merge([
                    'source' => 'thankyou_upsell',
                    'timestamp' => current_time('mysql'),
                ], $metadata)
            ]);
            
            if ($payment_intent->status === 'succeeded') {
                return [
                    'success' => true,
                    'payment_intent_id' => $payment_intent->id,
                    'amount' => $amount
                ];
            }
            
            return [
                'success' => false,
                'error' => 'Payment not completed: ' . $payment_intent->status
            ];
            
        } catch (\Stripe\Exception\CardException $e) {
            return [
                'success' => false,
                'error' => 'Card declined: ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Payment error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * ========================================
     * ADD TRAINING SESSION UPSELL
     * ========================================
     */
    public function add_training() {
        global $wpdb;
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_thankyou_upsell')) {
            wp_send_json_error('Invalid security token');
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 45);
        $camper_name = sanitize_text_field($_POST['camper_name'] ?? '');
        
        if (!$order_id || !$trainer_id) {
            wp_send_json_error('Missing required data');
        }
        
        // Check if already added
        if (get_transient('ptp_upsell_training_' . $order_id)) {
            wp_send_json_error('Training session already added to this order');
        }
        
        // Get original order
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE id = %d",
            $order_id
        ));
        
        if (!$order) {
            wp_send_json_error('Order not found');
        }
        
        // Get trainer
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            wp_send_json_error('Trainer not found');
        }
        
        // Get saved payment method
        $payment_info = $this->get_saved_payment_method($order);
        
        if (!$payment_info || !$payment_info['payment_method']) {
            // No saved payment - redirect to checkout
            wp_send_json_error([
                'redirect' => home_url('/training/checkout/?trainer=' . $trainer->slug . '&upsell=1'),
                'message' => 'Please complete checkout'
            ]);
        }
        
        // Charge the card
        $charge_result = $this->charge_saved_payment(
            $payment_info['customer_id'],
            $payment_info['payment_method'],
            $amount,
            'PTP Training Session - Upsell from Order #' . $order->order_number,
            [
                'order_id' => $order_id,
                'trainer_id' => $trainer_id,
                'trainer_name' => $trainer->display_name,
                'type' => 'training_upsell'
            ]
        );
        
        if (!$charge_result['success']) {
            ptp_log('[PTP Upsell] Training charge failed: ' . $charge_result['error']);
            wp_send_json_error($charge_result['error']);
        }
        
        // Create booking record
        $booking_number = 'PTP-U-' . strtoupper(substr(md5(uniqid()), 0, 6));
        
        // Get parent ID
        $parent_id = 0;
        if (!empty($order->user_id)) {
            $parent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $order->user_id
            ));
        }
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_bookings',
            [
                'booking_number' => $booking_number,
                'trainer_id' => $trainer_id,
                'parent_id' => $parent_id,
                'player_name' => $camper_name,
                'total_sessions' => 1,
                'sessions_used' => 0,
                'total_amount' => $amount,
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'payment_intent_id' => $charge_result['payment_intent_id'],
                'source' => 'thankyou_upsell',
                'notes' => 'Camp prep session - Upsell from order #' . $order->order_number,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        $booking_id = $wpdb->insert_id;
        
        // Mark as added
        set_transient('ptp_upsell_training_' . $order_id, $booking_id, DAY_IN_SECONDS);
        
        // Send confirmation email
        $this->send_training_confirmation($order, $trainer, $booking_id, $amount, $camper_name);
        
        // Track conversion
        $this->track_upsell_conversion('training', $order_id, $amount);
        
        ptp_log('[PTP Upsell] Training session added! Booking #' . $booking_number . ' for $' . $amount);
        
        wp_send_json_success([
            'booking_id' => $booking_id,
            'booking_number' => $booking_number,
            'message' => 'Training session added!'
        ]);
    }
    
    /**
     * ========================================
     * ADD ANOTHER CAMP UPSELL
     * ========================================
     */
    public function add_camp() {
        global $wpdb;
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_thankyou_upsell')) {
            wp_send_json_error('Invalid security token');
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $camp_id = intval($_POST['camp_id'] ?? 0);
        $stripe_product_id = sanitize_text_field($_POST['stripe_product_id'] ?? '');
        $stripe_price_id = sanitize_text_field($_POST['stripe_price_id'] ?? '');
        $camp_name = sanitize_text_field($_POST['camp_name'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $original_amount = floatval($_POST['original_amount'] ?? 0);
        $camper_name = sanitize_text_field($_POST['camper_name'] ?? '');
        
        if (!$order_id || !$camp_id || !$amount) {
            wp_send_json_error('Missing required data');
        }
        
        // Check if already added
        if (get_transient('ptp_upsell_camp_' . $order_id)) {
            wp_send_json_error('Camp already added to this order');
        }
        
        // Get original order
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE id = %d",
            $order_id
        ));
        
        if (!$order) {
            wp_send_json_error('Order not found');
        }
        
        // Get camp from ptp_stripe_products table
        $camp = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE id = %d AND active = 1",
            $camp_id
        ));
        
        if (!$camp) {
            wp_send_json_error('Camp not found');
        }
        
        // Check spots available
        if ($camp->camp_capacity && $camp->camp_registered >= $camp->camp_capacity) {
            wp_send_json_error('Sorry, this camp is now full');
        }
        
        // Get saved payment method
        $payment_info = $this->get_saved_payment_method($order);
        
        if (!$payment_info || !$payment_info['payment_method']) {
            // No saved payment - redirect to checkout with discount
            wp_send_json_error([
                'redirect' => home_url('/camp-checkout/?camp_id=' . $camp_id . '&discount=MULTICAMP15'),
                'message' => 'Please complete checkout'
            ]);
        }
        
        // Charge the card
        $discount_amount = $original_amount - $amount;
        $charge_result = $this->charge_saved_payment(
            $payment_info['customer_id'],
            $payment_info['payment_method'],
            $amount,
            'PTP Camp Registration - ' . $camp_name . ' (Multi-camp discount)',
            [
                'original_order_id' => $order_id,
                'camp_id' => $camp_id,
                'stripe_product_id' => $stripe_product_id,
                'camp_name' => $camp_name,
                'discount_amount' => $discount_amount,
                'type' => 'camp_upsell'
            ]
        );
        
        if (!$charge_result['success']) {
            ptp_log('[PTP Upsell] Camp charge failed: ' . $charge_result['error']);
            wp_send_json_error($charge_result['error']);
        }
        
        // Get camper info from original order
        $original_item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = %d LIMIT 1",
            $order_id
        ));
        
        // Create new order
        $new_order_number = 'PTP-' . strtoupper(substr(md5(uniqid()), 0, 6));
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_unified_camp_orders',
            [
                'order_number' => $new_order_number,
                'user_id' => $order->user_id,
                'billing_email' => $order->billing_email,
                'billing_phone' => $order->billing_phone,
                'billing_first_name' => $order->billing_first_name,
                'billing_last_name' => $order->billing_last_name,
                'subtotal' => $original_amount,
                'discount_amount' => $discount_amount,
                'discount_code' => 'MULTICAMP15',
                'total_amount' => $amount,
                'payment_status' => 'completed',
                'stripe_payment_intent_id' => $charge_result['payment_intent_id'],
                'stripe_customer_id' => $payment_info['customer_id'],
                'status' => 'completed',
                'notes' => 'Upsell from thank you page - Order #' . $order->order_number,
                'created_at' => current_time('mysql'),
                'completed_at' => current_time('mysql'),
                'paid_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        $new_order_id = $wpdb->insert_id;
        
        // Create order item
        $wpdb->insert(
            $wpdb->prefix . 'ptp_camp_order_items',
            [
                'order_id' => $new_order_id,
                'stripe_product_id' => $stripe_product_id,
                'stripe_price_id' => $stripe_price_id,
                'camp_name' => $camp_name,
                'camp_dates' => $camp->camp_dates ?? '',
                'camp_location' => $camp->camp_location ?? '',
                'camp_time' => $camp->camp_time ?? '9AM - 3PM',
                'camper_first_name' => $original_item->camper_first_name ?? '',
                'camper_last_name' => $original_item->camper_last_name ?? '',
                'camper_age' => $original_item->camper_age ?? null,
                'camper_gender' => $original_item->camper_gender ?? '',
                'camper_shirt_size' => $original_item->camper_shirt_size ?? '',
                'base_price' => $original_amount,
                'discount_amount' => $discount_amount,
                'final_price' => $amount,
                'waiver_signed' => 1,
                'waiver_signed_by' => $original_item->waiver_signed_by ?? '',
                'waiver_signed_at' => current_time('mysql'),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%s', '%s']
        );
        
        // Update camp registration count
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_stripe_products SET camp_registered = camp_registered + 1 WHERE id = %d",
            $camp_id
        ));
        
        // Mark as added
        set_transient('ptp_upsell_camp_' . $order_id, $new_order_id, DAY_IN_SECONDS);
        
        // Send confirmation email
        $this->send_camp_confirmation($order, $camp, $new_order_id, $amount, $discount_amount);
        
        // Track conversion
        $this->track_upsell_conversion('camp', $order_id, $amount);
        
        ptp_log('[PTP Upsell] Camp added! Order #' . $new_order_number . ' for $' . $amount);
        
        wp_send_json_success([
            'order_id' => $new_order_id,
            'order_number' => $new_order_number,
            'message' => 'Camp added!'
        ]);
    }
    
    /**
     * Send training confirmation email
     */
    private function send_training_confirmation($order, $trainer, $booking_id, $amount, $camper_name) {
        $to = $order->billing_email;
        $subject = '⚡ Training Session Added - ' . $trainer->display_name;
        
        $message = "Great news! A 1-on-1 training session has been added to your order.\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "TRAINING SESSION DETAILS\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "Trainer: " . $trainer->display_name . "\n";
        $message .= "Player: " . $camper_name . "\n";
        $message .= "Amount: $" . number_format($amount, 2) . "\n";
        $message .= "Type: 30-minute camp prep session\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "WHAT'S NEXT\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $message .= $trainer->display_name . " will reach out within 24 hours to schedule your session.\n\n";
        $message .= "Questions? Reply to this email or text " . ptp_email_brand('support_phone') . ".\n\n";
        $message .= "- The " . ptp_email_brand('company') . " Team\n";
        $message .= ptp_email_brand('tagline') . "\n";
        
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        
        wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Send camp confirmation email
     */
    private function send_camp_confirmation($order, $camp, $new_order_id, $amount, $discount) {
        $to = $order->billing_email;
        $subject = 'Additional Camp Registration Confirmed!';
        
        $camp_name = $camp->name ?? $camp->camp_name ?? 'PTP Soccer Camp';
        
        $message = "Great news! Your additional camp registration is confirmed.\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "CAMP DETAILS\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "Camp: " . $camp_name . "\n";
        $message .= "Dates: " . ($camp->camp_dates ?? 'TBD') . "\n";
        $message .= "Time: " . ($camp->camp_time ?? '9AM - 3PM') . "\n";
        $message .= "Location: " . ($camp->camp_location ?? 'TBD') . "\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "ORDER SUMMARY\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $message .= "Regular Price: $" . number_format($amount + $discount, 2) . "\n";
        $message .= "Multi-Camp Discount: -$" . number_format($discount, 2) . "\n";
        $message .= "Total Charged: $" . number_format($amount, 2) . "\n\n";
        $message .= "You'll receive your camp packet 1 week before camp starts.\n\n";
        $message .= "Questions? Reply to this email or text " . ptp_email_brand('support_phone') . ".\n\n";
        $message .= "- The " . ptp_email_brand('company') . " Team\n";
        $message .= ptp_email_brand('tagline') . "\n";
        
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        
        wp_mail($to, $subject, $message, $headers);
    }
    
    /**
     * Track upsell conversion for analytics
     */
    private function track_upsell_conversion($type, $order_id, $amount) {
        global $wpdb;
        
        // Log to analytics table if exists
        $table = $wpdb->prefix . 'ptp_analytics';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $wpdb->insert($table, [
                'event_type' => 'thankyou_upsell_' . $type,
                'event_data' => json_encode([
                    'order_id' => $order_id,
                    'amount' => $amount,
                    'type' => $type
                ]),
                'created_at' => current_time('mysql'),
            ]);
        }
        
        // Fire Meta Conversions API if available
        if (class_exists('PTP_Meta_Conversions_API')) {
            try {
                PTP_Meta_Conversions_API::instance()->track_purchase([
                    'value' => $amount,
                    'currency' => 'USD',
                    'content_type' => 'product',
                    'content_name' => 'Upsell: ' . $type,
                ]);
            } catch (\Exception $e) {
                ptp_log('[PTP Upsell] Meta tracking error: ' . $e->getMessage());
            }
        }
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Thankyou_Upsell_Handler::instance();
}, 20);
