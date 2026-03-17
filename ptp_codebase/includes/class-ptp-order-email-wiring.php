<?php
/**
 * PTP Order Email Wiring v150.5
 * 
 * COMPREHENSIVE email wiring for all order types:
 * - Camp registrations (via Stripe webhook or direct completion)
 * - Training bookings
 * - Bundle orders (camps + training combined)
 * 
 * This file hooks into various completion points to guarantee
 * emails are sent even if the primary trigger fails.
 * 
 * Also handles:
 * - Reminder email scheduling (3-day, 1-day, morning-of)
 * - Duplicate prevention
 * - Logging for debugging
 * 
 * @version 150.5.0
 * @since 115.0.0
 */

defined('ABSPATH') || exit;

class PTP_Order_Email_Wiring {
    
    private static $instance = null;
    private static $emails_sent = array(); // Track sent emails to prevent duplicates
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Initialize email settings
        add_action('init', array($this, 'init'), 5);
        
        // =========================================================
        // CAMP ORDER EMAIL TRIGGERS (v150.5 - ENABLED)
        // =========================================================
        
        // Primary: Stripe webhook - checkout.session.completed
        add_action('ptp_stripe_webhook_checkout.session.completed', array($this, 'on_stripe_checkout_complete'), 10, 1);
        
        // Primary: Custom camp order completion hook
        add_action('ptp_camp_order_completed', array($this, 'on_camp_order_completed'), 10, 2);
        
        // Secondary: Direct order status change
        add_action('ptp_camp_order_status_changed', array($this, 'on_order_status_changed'), 10, 3);
        
        // Tertiary: Thank-you page backup trigger
        add_action('ptp_thankyou_page_loaded', array($this, 'on_thankyou_page'), 10, 2);
        add_action('wp', array($this, 'check_thankyou_page_order'), 20);
        
        // =========================================================
        // TRAINING BOOKING EMAIL TRIGGERS (v150.5 - ENABLED)
        // =========================================================
        
        // Primary: Stripe payment_intent.succeeded
        add_action('ptp_stripe_webhook_payment_intent.succeeded', array($this, 'on_training_payment_success'), 10, 1);
        
        // Secondary: Direct booking completion
        add_action('ptp_training_booking_completed', array($this, 'on_training_booking_completed'), 10, 1);
        add_action('ptp_booking_confirmed', array($this, 'on_training_booking_completed'), 10, 1);
        
        // =========================================================
        // BUNDLE ORDER EMAIL TRIGGERS (v150.5 - ENABLED)
        // =========================================================
        
        // Bundle completion (both parts done)
        add_action('ptp_bundle_order_completed', array($this, 'on_bundle_completed'), 10, 1);
        
        // =========================================================
        // REMINDER EMAIL SCHEDULING (v150.5 - ENABLED)
        // =========================================================
        
        // Schedule reminders after confirmation
        add_action('ptp_camp_confirmation_sent', array($this, 'schedule_camp_reminders'), 10, 2);
        add_action('ptp_training_confirmation_sent', array($this, 'schedule_training_reminders'), 10, 2);
        
        // Cron hooks for sending reminders
        add_action('ptp_send_camp_reminder', array($this, 'send_camp_reminder'), 10, 2);
        add_action('ptp_send_training_reminder', array($this, 'send_training_reminder'), 10, 2);
        
        // =========================================================
        // EMAIL DEBUGGING & ADMIN
        // =========================================================
        
        // Log email failures
        add_action('wp_mail_failed', array($this, 'log_mail_failure'));
        
        // Admin AJAX for manual email send
        add_action('wp_ajax_ptp_send_order_email', array($this, 'ajax_send_order_email'));
        add_action('wp_ajax_ptp_resend_confirmation', array($this, 'ajax_resend_confirmation'));
    }
    
    /**
     * Initialize email settings
     */
    public function init() {
        // Ensure email templates class is loaded
        if (!class_exists('PTP_Email_Templates')) {
            $path = defined('PTP_PLUGIN_DIR') ? PTP_PLUGIN_DIR . 'includes/class-ptp-email-templates.php' : '';
            if ($path && file_exists($path)) {
                require_once $path;
            }
        }
        
        // Set default options if not set
        if (false === get_option('ptp_email_enabled')) {
            update_option('ptp_email_enabled', 'yes');
        }
        
        if (false === get_option('ptp_email_logo_url')) {
            update_option('ptp_email_logo_url', 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png');
        }
        
        if (false === get_option('ptp_email_support_phone')) {
            update_option('ptp_email_support_phone', '(610) 761-5230');
        }
    }
    
    // =========================================================
    // STRIPE WEBHOOK HANDLERS
    // =========================================================
    
    /**
     * Handle Stripe checkout.session.completed webhook
     */
    public function on_stripe_checkout_complete($event) {
        $session = $event['data']['object'] ?? array();
        $metadata = $session['metadata'] ?? array();
        
        ptp_log('[PTP Email Wiring] Stripe checkout.session.completed. Session ID: ' . ($session['id'] ?? 'unknown'));
        ptp_log('[PTP Email Wiring] Metadata keys: ' . implode(',', array_keys($metadata)));
        
        // Determine order type
        $type = $metadata['type'] ?? '';
        $order_id = $metadata['order_id'] ?? null;
        $order_number = $metadata['order_number'] ?? null;
        $customer_email = $session['customer_details']['email'] ?? ($metadata['customer_email'] ?? '');
        
        // Camp registration
        if ($type === 'camp_registration' || !empty($metadata['camper_count'])) {
            $this->send_camp_confirmation($order_id, $customer_email);
            return;
        }
        
        // Training booking
        if ($type === 'training' || !empty($metadata['booking_id'])) {
            $booking_id = $metadata['booking_id'] ?? $order_id;
            $this->send_training_confirmation($booking_id, $customer_email);
            return;
        }
        
        // Bundle (camps + training)
        if ($type === 'bundle' || (!empty($metadata['camp_order_id']) && !empty($metadata['training_booking_id']))) {
            $this->send_bundle_confirmation($metadata, $customer_email);
            return;
        }
        
        // Generic order - try camp confirmation
        if ($order_id) {
            $this->send_camp_confirmation($order_id, $customer_email);
        }
    }
    
    /**
     * Handle Stripe payment_intent.succeeded webhook
     */
    public function on_training_payment_success($event) {
        $payment_intent = $event['data']['object'] ?? array();
        $metadata = $payment_intent['metadata'] ?? array();
        $pi_id = $payment_intent['id'] ?? 'unknown';
        
        ptp_log('[PTP Email Wiring] payment_intent.succeeded received. PI ID: ' . $pi_id);
        ptp_log('[PTP Email Wiring] Payment metadata keys: ' . implode(',', array_keys($metadata)));
        
        // Check for booking ID
        $booking_id = $metadata['booking_id'] ?? null;
        $customer_email = $metadata['customer_email'] ?? '';
        
        if ($booking_id) {
            ptp_log("[PTP Email Wiring] Processing training confirmation for booking {$booking_id}");
            $this->send_training_confirmation($booking_id, $customer_email);
        } else {
            ptp_log("[PTP Email Wiring] No booking_id in payment_intent metadata");
        }
        
        // Also check for camp booking IDs (from camps plugin)
        $booking_ids = !empty($metadata['booking_ids']) ? explode(',', $metadata['booking_ids']) : array();
        if (!empty($booking_ids) && !empty($metadata['customer_email'])) {
            ptp_log("[PTP Email Wiring] Found camp booking_ids in payment: " . implode(',', $booking_ids));
            
            // This is a camps plugin payment - trigger camps email
            if (class_exists('PTP_Camps_Emails')) {
                PTP_Camps_Emails::send_confirmation($metadata['customer_email'], $booking_ids);
                ptp_log("[PTP Email Wiring] Called PTP_Camps_Emails::send_confirmation");
                
                // Schedule reminders for each booking
                foreach ($booking_ids as $bid) {
                    if (method_exists('PTP_Camps_Emails', 'schedule_reminders')) {
                        PTP_Camps_Emails::schedule_reminders($bid);
                    }
                }
            }
        }
    }
    
    // =========================================================
    // DIRECT ORDER COMPLETION HANDLERS
    // =========================================================
    
    /**
     * Handle direct camp order completion
     */
    public function on_camp_order_completed($order_id, $order = null) {
        $email = '';
        
        // v216.1: Handle both data shapes:
        // 1. Unified checkout: ($order_id from ptp_unified_camp_orders, $order object)
        // 2. Camps plugin: ($booking_id from ptp_camp_bookings, $row array)
        
        if (is_object($order)) {
            $email = $order->billing_email ?? ($order->customer_email ?? '');
        } elseif (is_array($order)) {
            // Camps plugin passes the raw booking row as an array
            $email = $order['customer_email'] ?? '';
            
            // For camps plugin bookings, send directly since no unified order exists
            if ($email && $order_id) {
                $this->send_camp_booking_confirmation_direct($order_id, $order);
                return;
            }
        }
        
        // Fallback: try to find in unified orders (works for unified checkout path)
        if (empty($email) && class_exists('PTP_Camp_Orders')) {
            $fetched = PTP_Camp_Orders::get_order($order_id);
            if ($fetched) {
                $email = $fetched->billing_email ?? '';
            }
        }
        
        if ($order_id && $email) {
            $this->send_camp_confirmation($order_id, $email);
        }
    }
    
    /**
     * Handle order status change
     */
    public function on_order_status_changed($order_id, $old_status, $new_status) {
        if (in_array($new_status, array('completed', 'paid', 'confirmed', 'processing'))) {
            // Get order to find email
            if (class_exists('PTP_Camp_Orders')) {
                $order = PTP_Camp_Orders::get_order($order_id);
                if ($order) {
                    $email = $order->billing_email ?? '';
                    $this->send_camp_confirmation($order_id, $email);
                }
            }
        }
    }
    
    /**
     * Check thank-you page for order to confirm
     */
    public function check_thankyou_page_order() {
        if (!is_page(array('camp-thank-you', 'thank-you', 'ptp-thank-you', 'booking-confirmation'))) {
            return;
        }
        
        $order_id = isset($_GET['order']) ? absint($_GET['order']) : 0;
        $order_number = isset($_GET['order_number']) ? sanitize_text_field($_GET['order_number']) : '';
        
        if ($order_id || $order_number) {
            // Try to find the order
            if (class_exists('PTP_Camp_Orders')) {
                if ($order_number) {
                    global $wpdb;
                    $order_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE order_number = %s",
                        $order_number
                    ));
                }
                
                if ($order_id) {
                    $order = PTP_Camp_Orders::get_order($order_id);
                    if ($order && in_array($order->status, array('completed', 'paid', 'processing'))) {
                        $this->send_camp_confirmation($order_id, $order->billing_email);
                    }
                }
            }
        }
    }
    
    /**
     * Thank-you page backup trigger
     */
    public function on_thankyou_page($order_id, $order_type) {
        if ($order_type === 'camp' && $order_id) {
            if (class_exists('PTP_Camp_Orders')) {
                $order = PTP_Camp_Orders::get_order($order_id);
                if ($order && in_array($order->status, array('completed', 'paid', 'processing'))) {
                    $this->send_camp_confirmation($order_id, $order->billing_email);
                }
            }
        } elseif ($order_type === 'training' && $order_id) {
            global $wpdb;
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                $order_id
            ));
            if ($booking) {
                $this->send_training_confirmation($order_id, $booking->customer_email);
            }
        }
    }
    
    /**
     * Handle direct training booking completion
     */
    public function on_training_booking_completed($booking_id) {
        global $wpdb;
        
        // v225: Unified dedup — check transient first
        $already_sent = get_transient('ptp_training_email_sent_' . $booking_id);
        if ($already_sent) {
            ptp_log("[PTP Email Wiring v225] Skipping booking $booking_id — already sent at " . ($already_sent['time'] ?? 'unknown'));
            return;
        }
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if ($booking) {
            $this->send_training_confirmation($booking_id, $booking->customer_email);
        }
    }
    
    /**
     * Handle bundle completion
     */
    public function on_bundle_completed($bundle_code) {
        global $wpdb;
        
        $bundle = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bundles WHERE bundle_code = %s",
            $bundle_code
        ));
        
        if ($bundle) {
            $this->send_bundle_confirmation(array(
                'camp_order_id' => $bundle->camp_order_id,
                'training_booking_id' => $bundle->training_booking_id,
                'bundle_code' => $bundle_code,
            ), $bundle->customer_email);
        }
    }
    
    // =========================================================
    // EMAIL SENDING METHODS
    // =========================================================
    
    /**
     * Send camp confirmation email
     */
    private function send_camp_confirmation($order_id, $email) {
        if (!$order_id) {
            return false;
        }
        
        // Prevent duplicate sends (in-memory check)
        $key = 'camp_' . $order_id;
        if (isset(self::$emails_sent[$key])) {
            ptp_log("[PTP Email Wiring] Skipping duplicate camp confirmation for order $order_id (in-memory)");
            return true;
        }
        
        // Check if already sent (database check)
        global $wpdb;
        $already_sent = $wpdb->get_var($wpdb->prepare(
            "SELECT confirmation_sent FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE id = %d",
            $order_id
        ));
        
        if ($already_sent && $already_sent !== '0000-00-00 00:00:00') {
            ptp_log("[PTP Email Wiring] Camp confirmation already sent for order $order_id at $already_sent");
            self::$emails_sent[$key] = true;
            return true;
        }
        
        ptp_log("[PTP Email Wiring] Sending camp confirmation for order $order_id to $email");
        
        $sent = false;
        
        // Try PTP_Camp_Orders::send_confirmation_email first (Training Platform)
        if (class_exists('PTP_Camp_Orders') && method_exists('PTP_Camp_Orders', 'send_confirmation_email')) {
            $sent = PTP_Camp_Orders::send_confirmation_email($order_id);
            ptp_log("[PTP Email Wiring] PTP_Camp_Orders::send_confirmation_email returned: " . ($sent ? 'true' : 'false'));
        }
        
        // Fallback to PTP_Camps_Emails (Camps Plugin)
        if (!$sent && class_exists('PTP_Camps_Emails')) {
            // Get order items to find booking IDs
            $order = null;
            if (class_exists('PTP_Camp_Orders')) {
                $order = PTP_Camp_Orders::get_order($order_id);
            }
            
            if ($order && !empty($order->items)) {
                $booking_ids = array();
                foreach ($order->items as $item) {
                    if (!empty($item->id)) {
                        $booking_ids[] = $item->id;
                    }
                }
                
                if (!empty($booking_ids)) {
                    $target_email = $email ?: $order->billing_email;
                    $sent = PTP_Camps_Emails::send_confirmation($target_email, $booking_ids);
                    ptp_log("[PTP Email Wiring] PTP_Camps_Emails::send_confirmation returned: " . ($sent ? 'true' : 'false'));
                }
            }
        }
        
        if ($sent) {
            self::$emails_sent[$key] = true;
            
            // Mark as sent in database
            $wpdb->update(
                $wpdb->prefix . 'ptp_unified_camp_orders',
                array('confirmation_sent' => current_time('mysql')),
                array('id' => $order_id)
            );
            
            // Trigger reminder scheduling
            do_action('ptp_camp_confirmation_sent', $order_id, $email);
            
            ptp_log("[PTP Email Wiring] ✓ Camp confirmation sent successfully for order $order_id");
            return true;
        } else {
            ptp_log("[PTP Email Wiring] ✗ Failed to send camp confirmation for order $order_id");
            return false;
        }
    }
    
    /**
     * v216.1: Direct confirmation email for camps plugin checkout path.
     * 
     * The camps plugin fires ptp_camp_order_completed with a booking_id from
     * ptp_camp_bookings (not ptp_unified_camp_orders). This method handles
     * that path by building and sending the email from booking row data directly.
     */
    private function send_camp_booking_confirmation_direct($booking_id, $booking_data) {
        if (!$booking_id) return false;
        
        $key = 'camp_booking_' . $booking_id;
        if (isset(self::$emails_sent[$key])) {
            ptp_log("[PTP Email Wiring] Skipping duplicate camp booking confirmation for booking $booking_id (in-memory)");
            return true;
        }
        
        $email = $booking_data['customer_email'] ?? '';
        if (!$email || !is_email($email)) {
            ptp_log("[PTP Email Wiring] No valid email for camp booking $booking_id");
            return false;
        }
        
        // Check if already sent (look in ptp_camp_bookings for a confirmation_sent column)
        global $wpdb;
        $bookings_table = $wpdb->prefix . 'ptp_camp_bookings';
        $has_conf_col = $wpdb->get_var("SHOW COLUMNS FROM `{$bookings_table}` LIKE 'confirmation_sent'");
        if ($has_conf_col) {
            $already = $wpdb->get_var($wpdb->prepare(
                "SELECT confirmation_sent FROM {$bookings_table} WHERE id = %d", $booking_id
            ));
            if ($already && $already !== '0000-00-00 00:00:00') {
                self::$emails_sent[$key] = true;
                return true;
            }
        }
        
        ptp_log("[PTP Email Wiring] Sending direct camp booking confirmation for booking $booking_id to $email");
        
        // Get camp details from CPT or Stripe products
        $camp_id = intval($booking_data['camp_id'] ?? 0);
        $camp_name = '';
        $camp_date = '';
        $camp_location = '';
        $camp_time = '9AM - 3PM';
        
        if ($camp_id) {
            $post = get_post($camp_id);
            if ($post && $post->post_type === 'ptp_camp') {
                $camp_name = $post->post_title;
                $camp_date = get_post_meta($camp_id, '_camp_date_short', true) ?: get_post_meta($camp_id, '_camp_date', true);
                $camp_location = get_post_meta($camp_id, '_camp_location_short', true) ?: get_post_meta($camp_id, '_camp_location', true);
                $camp_time = get_post_meta($camp_id, '_camp_time_short', true) ?: get_post_meta($camp_id, '_camp_time', true) ?: '9AM - 3PM';
            } else {
                // Try Stripe products table
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE id = %d AND product_type = 'camp'",
                    $camp_id
                ));
                if ($product) {
                    $camp_name = $product->name ?? '';
                    $camp_date = $product->camp_dates ?? '';
                    $camp_location = $product->camp_location ?? '';
                    $camp_time = $product->camp_time ?? '9AM - 3PM';
                }
            }
        }
        
        // v216.1: Use directly-passed data as fallback when CPT/Stripe lookup returned empty
        // Bulletproof checkout and camps plugin may pass these directly in booking_data
        if (empty($camp_name))     $camp_name     = $booking_data['camp_name'] ?? '';
        if (empty($camp_date))     $camp_date     = $booking_data['camp_date'] ?? '';
        if (empty($camp_location)) $camp_location = $booking_data['camp_location'] ?? '';
        if (empty($camp_time) || $camp_time === '9AM - 3PM') {
            $camp_time = $booking_data['camp_time'] ?? $camp_time;
        }
        
        // Build data for the email
        $customer_name = $booking_data['customer_name'] ?? '';
        $customer_phone = $booking_data['customer_phone'] ?? '';
        $camper_name = $booking_data['camper_name'] ?? '';
        $camper_dob = $booking_data['camper_dob'] ?? '';
        $camper_shirt = $booking_data['camper_shirt'] ?? '';
        $emergency_contact = $booking_data['emergency_contact'] ?? '';
        $emergency_phone = $booking_data['emergency_phone'] ?? '';
        $coupon_code = $booking_data['coupon_code'] ?? '';
        $discount_amount = floatval($booking_data['discount_amount'] ?? 0);
        $camper_first = explode(' ', trim($camper_name))[0] ?: explode(' ', trim($customer_name))[0] ?: 'Camper';
        $amount_paid = floatval($booking_data['amount_paid'] ?? 0);
        
        // Build branded confirmation email (PTP design system)
        $subject = strtoupper($camper_first) . " is Locked In! - " . ptp_email_brand('company') . " Camp Confirmation";
        
        $html = '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#0A0A0A;font-family:Helvetica,Arial,sans-serif;color:#FFFFFF;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#0A0A0A;">
<tr><td align="center" style="padding:16px;">
<table role="presentation" width="480" cellspacing="0" cellpadding="0" border="0" style="max-width:480px;width:100%;">
    <tr><td style="padding:20px 0;text-align:center;border-bottom:1px solid #222;">
        <span style="font-size:32px;font-weight:700;color:#FFFFFF;letter-spacing:3px;">' . esc_html(ptp_email_brand('company')) . '</span>
    </td></tr>
    <tr><td style="padding:40px 16px 32px;text-align:center;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center">
            <tr><td style="background-color:#FCB900;padding:8px 20px;">
                <span style="font-size:12px;font-weight:700;color:#0A0A0A;letter-spacing:2px;">&#10003; CONFIRMED</span>
            </td></tr>
        </table>
        <h1 style="margin:20px 0 16px;font-size:36px;font-weight:700;color:#FFFFFF;line-height:1;">
            <span style="color:#FCB900;">' . esc_html(strtoupper($camper_first)) . '</span><br>IS LOCKED IN
        </h1>
        <p style="margin:0;font-size:14px;color:#888888;">Confirmation for <strong style="color:#FFFFFF;">' . esc_html($email) . '</strong></p>
    </td></tr>';
        
        // Camp details card — 2-column grid matching unified checkout email
        $html .= '
    <tr><td style="padding:0 16px 16px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#1A1A1A;border:1px solid #333;">
            <tr><td style="background-color:#FCB900;padding:14px 16px;">
                <span style="font-size:11px;font-weight:700;color:#0A0A0A;letter-spacing:2px;">CAMP DETAILS</span>
                <span style="font-size:11px;color:#0A0A0A;opacity:0.7;margin-left:8px;">Booking #' . esc_html($booking_id) . '</span>
            </td></tr>
            <tr><td style="padding:16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td style="padding:8px 0;" width="50%" valign="top">
                            <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">CAMP</p>
                            <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($camp_name ?: ptp_email_brand('company') . ' Camp') . '</p>
                        </td>
                        <td style="padding:8px 0;" width="50%" valign="top">
                            <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">LOCATION</p>
                            <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($camp_location ?: 'See details below') . '</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;" width="50%" valign="top">
                            <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">DATES</p>
                            <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($camp_date ?: 'See confirmation') . '</p>
                        </td>
                        <td style="padding:8px 0;" width="50%" valign="top">
                            <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">TIME</p>
                            <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($camp_time) . '</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0;" width="50%" valign="top">
                            <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">CAMPER</p>
                            <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($camper_name ?: $camper_first) . '</p>
                        </td>
                        <td style="padding:8px 0;" width="50%" valign="top">
                            <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">TOTAL PAID</p>
                            <p style="margin:0;font-size:18px;font-weight:700;color:#FCB900;">' . ($amount_paid > 0 ? '$' . number_format($amount_paid, 2) : 'Free') . '</p>
                        </td>
                    </tr>';
        
        // Optional row: shirt size + DOB
        if ($camper_shirt || $camper_dob) {
            $html .= '
                    <tr>';
            if ($camper_shirt) {
                $html .= '
                        <td style="padding:8px 0;" width="50%" valign="top">
                            <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">SHIRT SIZE</p>
                            <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($camper_shirt) . '</p>
                        </td>';
            }
            if ($camper_dob) {
                $html .= '
                        <td style="padding:8px 0;" width="50%" valign="top">
                            <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">DATE OF BIRTH</p>
                            <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($camper_dob) . '</p>
                        </td>';
            }
            $html .= '
                    </tr>';
        }
        
        // Discount row
        if ($coupon_code && $discount_amount > 0) {
            $html .= '
                    <tr>
                        <td colspan="2" style="padding:8px 0;">
                            <p style="margin:0;font-size:13px;color:#888888;">Discount: <span style="color:#FCB900;">' . esc_html($coupon_code) . '</span> (-$' . number_format($discount_amount, 2) . ')</p>
                        </td>
                    </tr>';
        }
        
        $html .= '
                </table>
            </td></tr>
        </table>
    </td></tr>';
        
        // Emergency contact card
        if ($emergency_contact || $emergency_phone) {
            $html .= '
    <tr><td style="padding:0 16px 16px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#1A1A1A;border:1px solid #333;">
            <tr><td style="background-color:#222;padding:14px 16px;">
                <span style="font-size:11px;font-weight:700;color:#FCB900;letter-spacing:2px;">EMERGENCY CONTACT</span>
            </td></tr>
            <tr><td style="padding:16px;font-size:14px;color:#CCCCCC;">
                ' . ($emergency_contact ? esc_html($emergency_contact) : '') . ($emergency_phone ? ' &mdash; <a href="tel:' . esc_attr($emergency_phone) . '" style="color:#FCB900;text-decoration:none;">' . esc_html($emergency_phone) . '</a>' : '') . '
            </td></tr>
        </table>
    </td></tr>';
        }
        
        // What to bring section
        $html .= '
    <tr><td style="padding:0 16px 16px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#1A1A1A;border:1px solid #333;">
            <tr><td style="background-color:#222;padding:14px 16px;">
                <span style="font-size:11px;font-weight:700;color:#FCB900;letter-spacing:2px;">WHAT TO BRING</span>
            </td></tr>
            <tr><td style="padding:16px;font-size:14px;color:#CCCCCC;line-height:1.6;">
                Soccer cleats, shin guards, water bottle, sunscreen, and a great attitude.
            </td></tr>
        </table>
    </td></tr>
    <tr><td style="padding:20px 16px;text-align:center;border-top:1px solid #222;">
        <p style="margin:0;font-size:12px;color:#666;">Questions? Reply to this email or text us.</p>
        <p style="margin:8px 0 0;font-size:12px;color:#666;">' . esc_html(ptp_email_brand('company')) . ' &bull; ' . esc_html(str_replace(array('https://', 'http://'), '', ptp_email_brand('site_url'))) . '</p>
    </td></tr>
</table>
</td></tr></table></body></html>';
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_camps'),
            'Reply-To: ' . ptp_email_brand('from_email'),
        );
        
        $sent = wp_mail($email, $subject, $html, $headers);
        
        if ($sent) {
            self::$emails_sent[$key] = true;
            
            // Mark as sent if column exists
            if ($has_conf_col) {
                $wpdb->update(
                    $bookings_table,
                    array('confirmation_sent' => current_time('mysql')),
                    array('id' => $booking_id)
                );
            }
            
            // Send admin notification
            if (class_exists('PTP_Camp_Emails') && method_exists('PTP_Camp_Emails', 'send_admin_notification')) {
                PTP_Camp_Emails::send_admin_notification((object) $booking_data);
            }
            
            ptp_log("[PTP Email Wiring] ✓ Direct camp booking confirmation sent for booking $booking_id to $email");
        } else {
            ptp_log("[PTP Email Wiring] ✗ Failed to send direct camp booking confirmation for booking $booking_id");
        }
        
        return $sent;
    }

    /**
     * Send training confirmation email
     */
    private function send_training_confirmation($booking_id, $email) {
        if (!$booking_id) {
            return false;
        }
        
        $key = 'training_' . $booking_id;
        if (isset(self::$emails_sent[$key])) {
            ptp_log("[PTP Email Wiring] Skipping duplicate training confirmation for booking $booking_id");
            return true;
        }
        
        // v225: Check unified transient (PTP_Email sets this when it actually sends)
        $already_sent = get_transient('ptp_training_email_sent_' . $booking_id);
        if ($already_sent) {
            self::$emails_sent[$key] = true;
            ptp_log("[PTP Email Wiring v225] Skipping booking $booking_id — transient already set by " . ($already_sent['source'] ?? 'unknown'));
            return true;
        }
        
        // Check if already sent via DB column
        global $wpdb;
        $already_sent_db = $wpdb->get_var($wpdb->prepare(
            "SELECT confirmation_sent FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if ($already_sent_db && $already_sent_db !== '0000-00-00 00:00:00') {
            self::$emails_sent[$key] = true;
            return true;
        }
        
        ptp_log("[PTP Email Wiring v225] Sending training emails for booking $booking_id");
        
        $sent_parent = false;
        $sent_trainer = false;
        
        // Send parent confirmation (PTP_Email sets its own transient)
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_booking_confirmation')) {
            $sent_parent = PTP_Email::send_booking_confirmation($booking_id);
        }
        if (!$sent_parent && class_exists('PTP_Notifications') && method_exists('PTP_Notifications', 'send_booking_confirmation')) {
            $sent_parent = PTP_Notifications::send_booking_confirmation($booking_id);
        }
        
        // v225: Also send trainer new booking notification
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_trainer_new_booking')) {
            $sent_trainer = PTP_Email::send_trainer_new_booking($booking_id);
        }
        
        if ($sent_parent || $sent_trainer) {
            self::$emails_sent[$key] = true;
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('confirmation_sent' => current_time('mysql')),
                array('id' => $booking_id)
            );
            
            do_action('ptp_training_confirmation_sent', $booking_id, $email);
            
            ptp_log("[PTP Email Wiring v225] ✓ Training emails sent for booking $booking_id (parent=" . ($sent_parent ? 'yes' : 'no') . ", trainer=" . ($sent_trainer ? 'yes' : 'no') . ")");
            return true;
        }
        
        return false;
    }
    
    /**
     * Send bundle confirmation email
     */
    private function send_bundle_confirmation($metadata, $email) {
        $camp_order_id = $metadata['camp_order_id'] ?? null;
        $training_booking_id = $metadata['training_booking_id'] ?? null;
        
        ptp_log("[PTP Email Wiring] Sending bundle confirmation - camp: $camp_order_id, training: $training_booking_id");
        
        // Send individual confirmations
        if ($camp_order_id) {
            $this->send_camp_confirmation($camp_order_id, $email);
        }
        
        if ($training_booking_id) {
            $this->send_training_confirmation($training_booking_id, $email);
        }
    }
    
    // =========================================================
    // REMINDER EMAIL SCHEDULING
    // =========================================================
    
    /**
     * Schedule camp reminders after confirmation
     */
    public function schedule_camp_reminders($order_id, $email) {
        if (!class_exists('PTP_Camp_Orders')) {
            return;
        }
        
        $order = PTP_Camp_Orders::get_order($order_id);
        if (!$order || empty($order->items)) {
            return;
        }
        
        foreach ($order->items as $item) {
            $camp_id = $item->camp_id ?? null;
            if (!$camp_id) continue;
            
            $start_date = get_post_meta($camp_id, '_camp_start_date', true);
            if (!$start_date) {
                $start_date = get_post_meta($camp_id, '_camp_date', true);
                if ($start_date) {
                    $start_date = date('Y-m-d', strtotime($start_date));
                }
            }
            
            if (!$start_date) continue;
            
            $camp_time = strtotime($start_date . ' 09:00:00');
            $now = time();
            $item_id = $item->id ?? 0;
            
            if (!$item_id) continue;
            
            // 3 days before at 9am
            $three_days = strtotime('-3 days 09:00:00', $camp_time);
            if ($three_days > $now && !wp_next_scheduled('ptp_send_camp_reminder', array($item_id, 3))) {
                wp_schedule_single_event($three_days, 'ptp_send_camp_reminder', array($item_id, 3));
                ptp_log("[PTP Email Wiring] Scheduled 3-day camp reminder for item $item_id at " . date('Y-m-d H:i:s', $three_days));
            }
            
            // 1 day before at 9am
            $one_day = strtotime('-1 day 09:00:00', $camp_time);
            if ($one_day > $now && !wp_next_scheduled('ptp_send_camp_reminder', array($item_id, 1))) {
                wp_schedule_single_event($one_day, 'ptp_send_camp_reminder', array($item_id, 1));
                ptp_log("[PTP Email Wiring] Scheduled 1-day camp reminder for item $item_id at " . date('Y-m-d H:i:s', $one_day));
            }
            
            // Morning of at 7am
            $morning_of = strtotime(date('Y-m-d 07:00:00', $camp_time));
            if ($morning_of > $now && !wp_next_scheduled('ptp_send_camp_reminder', array($item_id, 0))) {
                wp_schedule_single_event($morning_of, 'ptp_send_camp_reminder', array($item_id, 0));
                ptp_log("[PTP Email Wiring] Scheduled morning-of camp reminder for item $item_id at " . date('Y-m-d H:i:s', $morning_of));
            }
        }
    }
    
    /**
     * Schedule training reminders
     */
    public function schedule_training_reminders($booking_id, $email) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking || !$booking->session_date) {
            return;
        }
        
        $session_time = strtotime($booking->session_date . ' ' . ($booking->session_time ?? '09:00:00'));
        $now = time();
        
        // 1 day before at 9am
        $one_day = strtotime('-1 day 09:00:00', $session_time);
        if ($one_day > $now && !wp_next_scheduled('ptp_send_training_reminder', array($booking_id, 1))) {
            wp_schedule_single_event($one_day, 'ptp_send_training_reminder', array($booking_id, 1));
        }
        
        // 2 hours before
        $two_hours = strtotime('-2 hours', $session_time);
        if ($two_hours > $now && !wp_next_scheduled('ptp_send_training_reminder', array($booking_id, 0))) {
            wp_schedule_single_event($two_hours, 'ptp_send_training_reminder', array($booking_id, 0));
        }
    }
    
    /**
     * Send camp reminder email (called by cron)
     */
    public function send_camp_reminder($booking_id, $days_before) {
        ptp_log("[PTP Email Wiring] Sending camp reminder: booking $booking_id, $days_before days before");
        
        // Try PTP_Camps_Emails first (camps plugin)
        if (class_exists('PTP_Camps_Emails') && method_exists('PTP_Camps_Emails', 'send_reminder')) {
            PTP_Camps_Emails::send_reminder($booking_id, $days_before);
            return;
        }
        
        // Try PTP_Camp_Emails (training platform)
        if (class_exists('PTP_Camp_Emails') && method_exists('PTP_Camp_Emails', 'send_reminder')) {
            PTP_Camp_Emails::send_reminder($booking_id, $days_before);
            return;
        }
        
        ptp_log("[PTP Email Wiring] No reminder email class available for booking $booking_id");
    }
    
    /**
     * Send training reminder email (called by cron)
     */
    public function send_training_reminder($booking_id, $days_before) {
        ptp_log("[PTP Email Wiring] Sending training reminder: booking $booking_id, $days_before days before");
        
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_session_reminder')) {
            PTP_Email::send_session_reminder($booking_id, $days_before);
        } elseif (class_exists('PTP_Notifications') && method_exists('PTP_Notifications', 'send_reminder')) {
            PTP_Notifications::send_reminder($booking_id, $days_before);
        }
    }
    
    // =========================================================
    // ADMIN & DEBUGGING
    // =========================================================
    
    /**
     * Log mail failures
     */
    public function log_mail_failure($error) {
        ptp_log('[PTP Email Wiring] wp_mail failed: ' . implode(', ', array_keys($error->errors)));
    }
    
    /**
     * AJAX: Manually send order email
     */
    public function ajax_send_order_email() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $type = sanitize_text_field($_POST['type'] ?? 'camp');
        
        if (!$order_id) {
            wp_send_json_error('No order ID provided');
        }
        
        // Clear the sent flag to allow resend
        $key = $type . '_' . $order_id;
        unset(self::$emails_sent[$key]);
        
        if ($type === 'camp') {
            // Clear database flag
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'ptp_unified_camp_orders',
                array('confirmation_sent' => null),
                array('id' => $order_id)
            );
            
            $order = class_exists('PTP_Camp_Orders') ? PTP_Camp_Orders::get_order($order_id) : null;
            $email = $order ? $order->billing_email : '';
            $sent = $this->send_camp_confirmation($order_id, $email);
        } else {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('confirmation_sent' => null),
                array('id' => $order_id)
            );
            
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                $order_id
            ));
            $email = $booking ? $booking->customer_email : '';
            $sent = $this->send_training_confirmation($order_id, $email);
        }
        
        if ($sent) {
            wp_send_json_success('Email sent successfully');
        } else {
            wp_send_json_error('Failed to send email');
        }
    }
    
    /**
     * AJAX: Resend confirmation email
     */
    public function ajax_resend_confirmation() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        
        if (!$order_id) {
            wp_send_json_error('No order ID');
        }
        
        // Clear sent flag and resend
        $key = 'camp_' . $order_id;
        unset(self::$emails_sent[$key]);
        
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_unified_camp_orders',
            array('confirmation_sent' => null),
            array('id' => $order_id)
        );
        
        $order = class_exists('PTP_Camp_Orders') ? PTP_Camp_Orders::get_order($order_id) : null;
        $email = $order ? $order->billing_email : '';
        $sent = $this->send_camp_confirmation($order_id, $email);
        
        wp_send_json_success(array(
            'sent' => $sent,
            'message' => $sent ? 'Confirmation email resent' : 'Failed to send'
        ));
    }
}

// Initialize
function ptp_order_email_wiring() {
    return PTP_Order_Email_Wiring::instance();
}

add_action('plugins_loaded', 'ptp_order_email_wiring', 15);
