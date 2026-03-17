<?php
/**
 * PTP Meta Conversions API v2.0
 * 
 * Server-side conversion tracking via Stripe webhooks.
 * Sends Purchase events to Meta when checkout.session.completed fires.
 * 
 * Why this matters:
 * - Browser pixel only captures ~43% of conversions (ad blockers, iOS privacy)
 * - Server-side captures 90%+ with full customer data
 * - Better match quality = better ad optimization
 * 
 * @since 2.0.0
 */

defined('ABSPATH') || exit;

class PTP_Meta_Conversions_API {
    
    private static $instance = null;
    private $pixel_id;
    private $access_token;
    private $test_mode;
    private $test_event_code;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->pixel_id = get_option('ptp_fb_pixel_id', '');
        $this->access_token = get_option('ptp_fb_access_token', '');
        $this->test_mode = get_option('ptp_fb_test_mode', false);
        $this->test_event_code = get_option('ptp_fb_test_event_code', '');
        
        if (!$this->is_configured()) {
            return;
        }
        
        // Hook into Stripe webhooks
        add_action('ptp_stripe_webhook_checkout.session.completed', array($this, 'handle_checkout_completed'), 10, 1);
        add_action('ptp_stripe_webhook_received', array($this, 'handle_generic_webhook'), 10, 1);
        
        // Hook into PTP booking events (training platform)
        add_action('ptp_booking_completed', array($this, 'handle_booking_completed'), 10, 2);
        
        // Add FB data capture script
        add_action('wp_footer', array($this, 'output_fb_capture_script'), 5);
        
        // AJAX endpoint to store FB cookies in session
        add_action('wp_ajax_ptp_store_fb_data', array($this, 'ajax_store_fb_data'));
        add_action('wp_ajax_nopriv_ptp_store_fb_data', array($this, 'ajax_store_fb_data'));
    }
    
    /**
     * Check if Conversions API is configured
     */
    public function is_configured() {
        return !empty($this->pixel_id) && !empty($this->access_token);
    }
    
    /**
     * Handle checkout.session.completed webhook from Stripe
     */
    public function handle_checkout_completed($event) {
        $session = $event['data']['object'];
        
        // Only process camp registrations (for now)
        $type = $session['metadata']['type'] ?? '';
        if ($type !== 'camp_registration') {
            return;
        }
        
        ptp_log('[PTP Meta CAPI] Processing checkout.session.completed for session: ' . $session['id']);
        
        // Build event data
        $event_data = $this->build_purchase_event($session);
        
        // Send to Meta
        $result = $this->send_event($event_data);
        
        if (is_wp_error($result)) {
            ptp_log('[PTP Meta CAPI] Error sending event: ' . $result->get_error_message());
        } else {
            ptp_log('[PTP Meta CAPI] Event sent successfully. Events received: ' . ($result['events_received'] ?? 'unknown'));
        }
    }
    
    /**
     * Handle generic webhook for payment_intent.succeeded (training bookings)
     */
    public function handle_generic_webhook($event) {
        if ($event['type'] !== 'payment_intent.succeeded') {
            return;
        }
        
        $payment_intent = $event['data']['object'];
        $metadata = $payment_intent['metadata'] ?? array();
        
        // Check if this is a training booking (not camp)
        if (empty($metadata['booking_id']) || !empty($metadata['camp_order_id'])) {
            return;
        }
        
        ptp_log('[PTP Meta CAPI] Processing payment_intent.succeeded for training booking: ' . $metadata['booking_id']);
        
        $event_data = $this->build_training_purchase_event($payment_intent);
        $result = $this->send_event($event_data);
        
        if (is_wp_error($result)) {
            ptp_log('[PTP Meta CAPI] Error: ' . $result->get_error_message());
        }
    }
    
    /**
     * Handle booking completed event (fallback)
     */
    public function handle_booking_completed($booking_id, $booking) {
        // This is a fallback if webhook doesn't fire
        ptp_log('[PTP Meta CAPI] Booking completed event for booking: ' . $booking_id);
        
        $event_data = $this->build_booking_event($booking_id, $booking);
        $this->send_event($event_data);
    }
    
    /**
     * Build Purchase event from Stripe checkout session
     */
    private function build_purchase_event($session) {
        $customer_details = $session['customer_details'] ?? array();
        $metadata = $session['metadata'] ?? array();
        
        // Get user data from customer details
        $user_data = $this->extract_user_data($customer_details, $metadata);
        
        // Get order details
        $order_number = $metadata['order_number'] ?? $session['client_reference_id'] ?? '';
        $camper_count = intval($metadata['camper_count'] ?? 1);
        
        return array(
            'event_name' => 'Purchase',
            'event_time' => time(),
            'event_id' => $session['id'], // Deduplication key
            'event_source_url' => home_url('/ptp-checkout/'),
            'action_source' => 'website',
            'user_data' => $user_data,
            'custom_data' => array(
                'currency' => strtoupper($session['currency'] ?? 'usd'),
                'value' => ($session['amount_total'] ?? 0) / 100,
                'content_type' => 'product',
                'content_name' => 'PTP Camp Registration',
                'content_category' => 'Soccer Camp',
                'content_ids' => array($order_number),
                'num_items' => $camper_count,
                'order_id' => $order_number,
            ),
        );
    }
    
    /**
     * Build Purchase event from training payment intent
     */
    private function build_training_purchase_event($payment_intent) {
        $metadata = $payment_intent['metadata'] ?? array();
        $charges = $payment_intent['charges']['data'] ?? array();
        $charge = $charges[0] ?? array();
        $billing = $charge['billing_details'] ?? array();
        
        $user_data = array();
        
        // Extract from billing details
        if (!empty($billing['email'])) {
            $user_data['em'] = $this->hash($billing['email']);
        }
        if (!empty($billing['name'])) {
            $parts = explode(' ', $billing['name'], 2);
            $user_data['fn'] = $this->hash($parts[0] ?? '');
            $user_data['ln'] = $this->hash($parts[1] ?? '');
        }
        if (!empty($billing['phone'])) {
            $user_data['ph'] = $this->hash(preg_replace('/\D/', '', $billing['phone']));
        }
        if (!empty($billing['address'])) {
            $addr = $billing['address'];
            if (!empty($addr['city'])) $user_data['ct'] = $this->hash($addr['city']);
            if (!empty($addr['state'])) $user_data['st'] = $this->hash($addr['state']);
            if (!empty($addr['postal_code'])) $user_data['zp'] = $this->hash($addr['postal_code']);
            if (!empty($addr['country'])) $user_data['country'] = $this->hash($addr['country']);
        }
        
        // FB cookies from metadata
        if (!empty($metadata['fbp'])) $user_data['fbp'] = $metadata['fbp'];
        if (!empty($metadata['fbc'])) $user_data['fbc'] = $metadata['fbc'];
        if (!empty($metadata['client_user_agent'])) $user_data['client_user_agent'] = $metadata['client_user_agent'];
        if (!empty($metadata['client_ip_address'])) $user_data['client_ip_address'] = $metadata['client_ip_address'];
        
        return array(
            'event_name' => 'Purchase',
            'event_time' => time(),
            'event_id' => $payment_intent['id'],
            'event_source_url' => home_url('/book-session/'),
            'action_source' => 'website',
            'user_data' => array_filter($user_data),
            'custom_data' => array(
                'currency' => 'USD',
                'value' => ($payment_intent['amount'] ?? 0) / 100,
                'content_type' => 'product',
                'content_name' => 'Training Session',
                'content_category' => 'Private Training',
                'content_ids' => array($metadata['booking_id'] ?? ''),
                'num_items' => 1,
            ),
        );
    }
    
    /**
     * Build event from PTP booking object
     */
    private function build_booking_event($booking_id, $booking) {
        $user_data = array();
        
        if (!empty($booking->parent_id)) {
            $user = get_user_by('ID', $booking->parent_id);
            if ($user) {
                $user_data['em'] = $this->hash($user->user_email);
                $user_data['fn'] = $this->hash($user->first_name ?: '');
                $user_data['ln'] = $this->hash($user->last_name ?: '');
            }
        }
        
        // Get FB cookies from session/transient
        $fb_data = get_transient('ptp_fb_data_' . ($booking->parent_id ?: session_id()));
        if ($fb_data) {
            if (!empty($fb_data['fbp'])) $user_data['fbp'] = $fb_data['fbp'];
            if (!empty($fb_data['fbc'])) $user_data['fbc'] = $fb_data['fbc'];
            if (!empty($fb_data['client_user_agent'])) $user_data['client_user_agent'] = $fb_data['client_user_agent'];
        }
        
        $user_data['client_ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_data['client_user_agent'] = $user_data['client_user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        return array(
            'event_name' => 'Purchase',
            'event_time' => time(),
            'event_id' => 'booking_' . $booking_id,
            'event_source_url' => home_url($_SERVER['REQUEST_URI'] ?? ''),
            'action_source' => 'website',
            'user_data' => array_filter($user_data),
            'custom_data' => array(
                'currency' => 'USD',
                'value' => floatval($booking->amount ?? 80),
                'content_type' => 'product',
                'content_name' => 'Training Session',
                'content_category' => 'Private Training',
                'content_ids' => array('trainer_' . ($booking->trainer_id ?? '')),
                'num_items' => 1,
            ),
        );
    }
    
    /**
     * Extract user data from Stripe customer details
     */
    private function extract_user_data($customer_details, $metadata = array()) {
        $user_data = array();
        
        // Email (most important)
        if (!empty($customer_details['email'])) {
            $user_data['em'] = $this->hash($customer_details['email']);
        }
        
        // Name
        if (!empty($customer_details['name'])) {
            $parts = explode(' ', $customer_details['name'], 2);
            $user_data['fn'] = $this->hash($parts[0] ?? '');
            $user_data['ln'] = $this->hash($parts[1] ?? '');
        }
        
        // Phone
        if (!empty($customer_details['phone'])) {
            $user_data['ph'] = $this->hash(preg_replace('/\D/', '', $customer_details['phone']));
        }
        
        // Address
        $address = $customer_details['address'] ?? array();
        if (!empty($address['city'])) $user_data['ct'] = $this->hash($address['city']);
        if (!empty($address['state'])) $user_data['st'] = $this->hash($address['state']);
        if (!empty($address['postal_code'])) $user_data['zp'] = $this->hash($address['postal_code']);
        if (!empty($address['country'])) $user_data['country'] = $this->hash($address['country']);
        
        // FB browser data from metadata (passed from frontend)
        if (!empty($metadata['fbp'])) $user_data['fbp'] = $metadata['fbp'];
        if (!empty($metadata['fbc'])) $user_data['fbc'] = $metadata['fbc'];
        if (!empty($metadata['client_user_agent'])) $user_data['client_user_agent'] = $metadata['client_user_agent'];
        if (!empty($metadata['client_ip_address'])) $user_data['client_ip_address'] = $metadata['client_ip_address'];
        
        // Filter out empty values
        return array_filter($user_data);
    }
    
    /**
     * Hash value for Meta (SHA256, lowercase)
     */
    private function hash($value) {
        if (empty($value)) return null;
        $normalized = strtolower(trim($value));
        return hash('sha256', $normalized);
    }
    
    /**
     * Send event to Meta Conversions API
     */
    public function send_event($event_data) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'Meta Conversions API not configured');
        }
        
        $url = sprintf(
            'https://graph.facebook.com/v18.0/%s/events',
            $this->pixel_id
        );
        
        $payload = array(
            'data' => json_encode(array($event_data)),
            'access_token' => $this->access_token,
        );
        
        // Add test event code if in test mode
        if ($this->test_mode && !empty($this->test_event_code)) {
            $payload['test_event_code'] = $this->test_event_code;
        }
        
        $response = wp_remote_post($url, array(
            'body' => $payload,
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 200 || isset($body['error'])) {
            $error_msg = $body['error']['message'] ?? 'Unknown error';
            ptp_log('[PTP Meta CAPI] API Error: ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }
        
        return $body;
    }
    
    /**
     * Output FB capture script in footer
     * Captures _fbp and _fbc cookies and stores them for checkout
     */
    public function output_fb_capture_script() {
        // Run on ALL frontend pages to capture fbp/fbc cookies for CAPI
        // Previously limited to checkout only — this caused missed attribution on camp/cart pages
        if (is_admin()) {
            return;
        }
        ?>
        <script>
        (function() {
            function getCookie(name) {
                var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
                return match ? match[2] : null;
            }
            
            function getFbClickId() {
                var urlParams = new URLSearchParams(window.location.search);
                var fbclid = urlParams.get('fbclid');
                var fbc = getCookie('_fbc');
                if (!fbc && fbclid) {
                    fbc = 'fb.1.' + Date.now() + '.' + fbclid;
                }
                return fbc;
            }
            
            // Store FB data for server-side tracking
            window.ptpFbData = {
                fbp: getCookie('_fbp'),
                fbc: getFbClickId(),
                client_user_agent: navigator.userAgent,
                source_url: window.location.href
            };
            
            // Send to server to store in session
            if (window.ptpFbData.fbp || window.ptpFbData.fbc) {
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=ptp_store_fb_data&nonce=<?php echo wp_create_nonce('ptp_fb_nonce'); ?>&fbp=' + encodeURIComponent(window.ptpFbData.fbp || '') +
                          '&fbc=' + encodeURIComponent(window.ptpFbData.fbc || '') +
                          '&ua=' + encodeURIComponent(window.ptpFbData.client_user_agent)
                });
            }
            
            // Expose for checkout forms to use
            window.getPtpFbData = function() {
                return window.ptpFbData;
            };
        })();
        </script>
        <?php
    }
    
    /**
     * AJAX handler to store FB data in transient
     */
    public function ajax_store_fb_data() {
        check_ajax_referer('ptp_fb_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        $key = 'ptp_fb_data_' . ($user_id ?: session_id());
        
        $data = array(
            'fbp' => sanitize_text_field($_POST['fbp'] ?? ''),
            'fbc' => sanitize_text_field($_POST['fbc'] ?? ''),
            'client_user_agent' => sanitize_text_field($_POST['ua'] ?? ''),
            'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        );
        
        set_transient($key, $data, HOUR_IN_SECONDS);
        
        wp_send_json_success();
    }
    
    /**
     * Get stored FB data for current user
     */
    public static function get_stored_fb_data() {
        $user_id = get_current_user_id();
        $key = 'ptp_fb_data_' . ($user_id ?: session_id());
        return get_transient($key) ?: array();
    }
    
    /**
     * Register settings
     */
    public static function register_settings() {
        register_setting('ptp_pixels', 'ptp_fb_pixel_id');
        register_setting('ptp_pixels', 'ptp_fb_access_token');
        register_setting('ptp_pixels', 'ptp_fb_test_mode');
        register_setting('ptp_pixels', 'ptp_fb_test_event_code');
    }
}

// Initialize
add_action('init', function() {
    PTP_Meta_Conversions_API::instance();
});
add_action('admin_init', array('PTP_Meta_Conversions_API', 'register_settings'));
