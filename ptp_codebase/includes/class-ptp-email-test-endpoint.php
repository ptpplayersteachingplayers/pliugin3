<?php
/**
 * PTP Email Test Endpoint
 * 
 * REST API endpoint for testing and debugging email sends.
 * Admin-only access for manual email testing.
 * 
 * Endpoints:
 * - GET  /wp-json/ptp/v1/email-test/status - Check email system status
 * - POST /wp-json/ptp/v1/email-test/camp/{order_id} - Send camp confirmation
 * - POST /wp-json/ptp/v1/email-test/training/{booking_id} - Send training confirmation
 * - GET  /wp-json/ptp/v1/email-test/log - View recent email logs
 * 
 * @version 152.7.5
 * @since 152.7.5
 */

defined('ABSPATH') || exit;

class PTP_Email_Test_Endpoint {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Email system status
        register_rest_route('ptp/v1', '/email-test/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
        
        // Send camp confirmation
        register_rest_route('ptp/v1', '/email-test/camp/(?P<order_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_camp_confirmation'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
        
        // Send training confirmation
        register_rest_route('ptp/v1', '/email-test/training/(?P<booking_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_training_confirmation'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
        
        // View email log
        register_rest_route('ptp/v1', '/email-test/log', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_email_log'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
        
        // Test email to specific address
        register_rest_route('ptp/v1', '/email-test/send', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_test_email'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
        
        // Verify webhook wiring
        register_rest_route('ptp/v1', '/email-test/verify-wiring', array(
            'methods' => 'GET',
            'callback' => array($this, 'verify_wiring'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
    }
    
    /**
     * Check admin permission
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }
    
    /**
     * Get email system status
     */
    public function get_status($request) {
        global $wpdb;
        
        $status = array(
            'timestamp' => current_time('mysql'),
            'email_classes' => array(
                'PTP_Email' => class_exists('PTP_Email'),
                'PTP_Email_Templates' => class_exists('PTP_Email_Templates'),
                'PTP_Camp_Emails' => class_exists('PTP_Camp_Emails'),
                'PTP_Camp_Orders' => class_exists('PTP_Camp_Orders'),
                'PTP_Order_Email_Wiring' => class_exists('PTP_Order_Email_Wiring'),
                'PTP_Unified_Order_Email' => class_exists('PTP_Unified_Order_Email'),
                'PTP_Notifications' => class_exists('PTP_Notifications'),
            ),
            'stripe_classes' => array(
                'PTP_Stripe' => class_exists('PTP_Stripe'),
                'PTP_Stripe_Connect' => class_exists('PTP_Stripe_Connect'),
            ),
            'sms_classes' => array(
                'PTP_SMS' => class_exists('PTP_SMS'),
                'PTP_SMS_enabled' => class_exists('PTP_SMS') && method_exists('PTP_SMS', 'is_enabled') ? PTP_SMS::is_enabled() : false,
            ),
            'tables' => array(
                'ptp_bookings' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_bookings'") !== null,
                'ptp_unified_camp_orders' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_unified_camp_orders'") !== null,
                'ptp_camp_order_items' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_camp_order_items'") !== null,
            ),
            'wp_mail' => array(
                'function_exists' => function_exists('wp_mail'),
                'admin_email' => get_option('admin_email'),
            ),
            'recent_bookings' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'recent_camp_orders' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        );
        
        return rest_ensure_response($status);
    }
    
    /**
     * Send camp confirmation email
     */
    public function send_camp_confirmation($request) {
        $order_id = absint($request['order_id']);
        
        if (!$order_id) {
            return new WP_Error('invalid_order', 'Invalid order ID', array('status' => 400));
        }
        
        $results = array(
            'order_id' => $order_id,
            'timestamp' => current_time('mysql'),
            'methods_tried' => array(),
            'success' => false,
        );
        
        // Get order details
        if (class_exists('PTP_Camp_Orders')) {
            $order = PTP_Camp_Orders::get_order($order_id);
            if ($order) {
                $results['order'] = array(
                    'order_number' => $order->order_number,
                    'billing_email' => $order->billing_email,
                    'status' => $order->status,
                    'total' => $order->total_amount,
                    'items_count' => count($order->items ?? array()),
                    'confirmation_sent' => $order->confirmation_sent ?? null,
                );
            } else {
                return new WP_Error('order_not_found', 'Order not found', array('status' => 404));
            }
        }
        
        // Try PTP_Camp_Emails
        if (class_exists('PTP_Camp_Emails') && method_exists('PTP_Camp_Emails', 'send_order_confirmation')) {
            ptp_log("[PTP Email Test] Trying PTP_Camp_Emails::send_order_confirmation({$order_id})");
            $sent = PTP_Camp_Emails::send_order_confirmation($order_id);
            $results['methods_tried']['PTP_Camp_Emails::send_order_confirmation'] = $sent;
            if ($sent) $results['success'] = true;
        }
        
        // Try PTP_Order_Email_Wiring
        if (class_exists('PTP_Order_Email_Wiring')) {
            ptp_log("[PTP Email Test] Dispatching ptp_camp_order_completed action for order {$order_id}");
            do_action('ptp_camp_order_completed', $order_id, $order ?? null);
            $results['methods_tried']['ptp_camp_order_completed_action'] = 'dispatched';
        }
        
        return rest_ensure_response($results);
    }
    
    /**
     * Send training confirmation email
     */
    public function send_training_confirmation($request) {
        global $wpdb;
        
        $booking_id = absint($request['booking_id']);
        
        if (!$booking_id) {
            return new WP_Error('invalid_booking', 'Invalid booking ID', array('status' => 400));
        }
        
        $results = array(
            'booking_id' => $booking_id,
            'timestamp' => current_time('mysql'),
            'methods_tried' => array(),
            'success' => false,
        );
        
        // Get booking details
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.email as trainer_email
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if ($booking) {
            $results['booking'] = array(
                'customer_name' => $booking->customer_name,
                'customer_email' => $booking->customer_email,
                'trainer_name' => $booking->trainer_name,
                'session_date' => $booking->session_date,
                'start_time' => $booking->start_time,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'total_amount' => $booking->total_amount,
                'confirmation_sent' => $booking->confirmation_sent ?? null,
            );
        } else {
            return new WP_Error('booking_not_found', 'Booking not found', array('status' => 404));
        }
        
        // Try PTP_Email
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_booking_confirmation')) {
            ptp_log("[PTP Email Test] Trying PTP_Email::send_booking_confirmation({$booking_id})");
            $sent = PTP_Email::send_booking_confirmation($booking_id);
            $results['methods_tried']['PTP_Email::send_booking_confirmation'] = $sent ? true : false;
            if ($sent) $results['success'] = true;
        }
        
        // Try trainer notification
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_trainer_new_booking')) {
            ptp_log("[PTP Email Test] Trying PTP_Email::send_trainer_new_booking({$booking_id})");
            $sent = PTP_Email::send_trainer_new_booking($booking_id);
            $results['methods_tried']['PTP_Email::send_trainer_new_booking'] = $sent ? true : false;
        }
        
        // Dispatch actions
        ptp_log("[PTP Email Test] Dispatching ptp_training_booking_completed action for booking {$booking_id}");
        do_action('ptp_training_booking_completed', $booking_id);
        $results['methods_tried']['ptp_training_booking_completed_action'] = 'dispatched';
        
        return rest_ensure_response($results);
    }
    
    /**
     * Get email log (from debug.log)
     */
    public function get_email_log($request) {
        $log_file = WP_CONTENT_DIR . '/debug.log';
        $lines = array();
        
        if (file_exists($log_file) && is_readable($log_file)) {
            // Get last 200 lines
            $all_lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $all_lines = array_slice($all_lines, -500);
            
            // Filter for PTP email related entries
            foreach ($all_lines as $line) {
                if (
                    stripos($line, 'PTP Email') !== false ||
                    stripos($line, 'ptp_') !== false ||
                    stripos($line, 'Stripe Webhook') !== false ||
                    stripos($line, 'booking_confirmation') !== false ||
                    stripos($line, 'camp_order') !== false ||
                    stripos($line, 'wp_mail') !== false
                ) {
                    $lines[] = $line;
                }
            }
            
            // Keep only last 100 relevant lines
            $lines = array_slice($lines, -100);
        }
        
        return rest_ensure_response(array(
            'log_file' => $log_file,
            'log_exists' => file_exists($log_file),
            'entries_count' => count($lines),
            'entries' => $lines,
        ));
    }
    
    /**
     * Send test email to specific address
     */
    public function send_test_email($request) {
        $to = sanitize_email($request->get_param('to'));
        $type = sanitize_text_field($request->get_param('type') ?: 'basic');
        
        if (!$to) {
            $to = get_option('admin_email');
        }
        
        $results = array(
            'to' => $to,
            'type' => $type,
            'timestamp' => current_time('mysql'),
            'success' => false,
        );
        
        $subject = 'PTP Email Test - ' . date('Y-m-d H:i:s');
        $message = '<html><body>';
        $message .= '<h1 style="color:#FCB900;">PTP Email Test</h1>';
        $message .= '<p>This is a test email from the PTP Training Platform.</p>';
        $message .= '<p><strong>Type:</strong> ' . esc_html($type) . '</p>';
        $message .= '<p><strong>Timestamp:</strong> ' . current_time('mysql') . '</p>';
        $message .= '<p><strong>Site:</strong> ' . home_url() . '</p>';
        $message .= '<hr>';
        $message .= '<p style="color:#666;font-size:12px;">If you received this email, your email system is working correctly.</p>';
        $message .= '</body></html>';
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_training'),
        );
        
        ptp_log("[PTP Email Test] Sending test email to {$to}");
        
        $sent = wp_mail($to, $subject, $message, $headers);
        
        $results['success'] = $sent;
        $results['wp_mail_result'] = $sent;
        
        if (!$sent) {
            global $phpmailer;
            if (isset($phpmailer) && isset($phpmailer->ErrorInfo)) {
                $results['error'] = $phpmailer->ErrorInfo;
            }
        }
        
        ptp_log("[PTP Email Test] Test email to {$to}: " . ($sent ? 'SUCCESS' : 'FAILED'));
        
        return rest_ensure_response($results);
    }
    
    /**
     * Verify webhook wiring is correct
     */
    public function verify_wiring($request) {
        global $wp_filter;
        
        $hooks_to_check = array(
            'ptp_stripe_webhook_checkout.session.completed',
            'ptp_stripe_webhook_payment_intent.succeeded',
            'ptp_camp_order_completed',
            'ptp_camp_order_status_changed',
            'ptp_training_booking_completed',
            'ptp_booking_confirmed',
            'ptp_bundle_order_completed',
            'ptp_thankyou_page_loaded',
            'ptp_camp_confirmation_sent',
            'ptp_training_confirmation_sent',
            'ptp_stripe_webhook_received',
        );
        
        $wiring = array();
        
        foreach ($hooks_to_check as $hook) {
            $wiring[$hook] = array(
                'has_callbacks' => has_action($hook),
                'callbacks' => array(),
            );
            
            if (isset($wp_filter[$hook])) {
                foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $id => $callback) {
                        $callback_name = '';
                        if (is_array($callback['function'])) {
                            if (is_object($callback['function'][0])) {
                                $callback_name = get_class($callback['function'][0]) . '::' . $callback['function'][1];
                            } else {
                                $callback_name = $callback['function'][0] . '::' . $callback['function'][1];
                            }
                        } elseif (is_string($callback['function'])) {
                            $callback_name = $callback['function'];
                        }
                        
                        $wiring[$hook]['callbacks'][] = array(
                            'priority' => $priority,
                            'function' => $callback_name,
                        );
                    }
                }
            }
        }
        
        // Check REST routes
        $rest_routes = array(
            '/ptp/v1/stripe-webhook' => rest_url('ptp/v1/stripe-webhook'),
        );
        
        return rest_ensure_response(array(
            'timestamp' => current_time('mysql'),
            'hooks' => $wiring,
            'rest_routes' => $rest_routes,
            'stripe_enabled' => class_exists('PTP_Stripe') && PTP_Stripe::is_enabled(),
        ));
    }
}

// Initialize
PTP_Email_Test_Endpoint::instance();
