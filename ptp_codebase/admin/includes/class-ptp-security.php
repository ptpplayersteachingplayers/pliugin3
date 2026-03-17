
<?php
if (!defined('ABSPATH')) exit;

class PTP_Security {
    public static function admin_only() {
        return is_user_logged_in() && current_user_can('manage_options');
    }

    public static function require_admin() {
        if (!self::admin_only()) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
            exit;
        }
    }

    public static function fail_closed($message = 'Forbidden') {
        wp_send_json_error(['message' => $message], 403);
        exit;
    }

    public static function require_nonce($action, $field = '_wpnonce') {
        if (!isset($_REQUEST[$field]) || !wp_verify_nonce($_REQUEST[$field], $action)) {
            self::fail_closed('Invalid nonce');
        }
    }

    public static function rate_limit($key, $limit = 20, $window = 60) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $k = 'ptp_rl_' . md5($key . '|' . $ip);
        $data = get_transient($k);
        if (!$data) {
            set_transient($k, ['c' => 1], $window);
            return;
        }
        if ($data['c'] >= $limit) {
            self::fail_closed('Rate limit exceeded');
        }
        $data['c']++;
        set_transient($k, $data, $window);
    }

    /**
     * Global rate limiting for all unauthenticated PTP AJAX endpoints.
     * Call this once from the main plugin bootstrap after loading this class.
     * 
     * - General PTP nopriv actions: 30 requests/minute per IP
     * - Payment/checkout actions: 10 requests/minute per IP
     * - Email capture/lead actions: 15 requests/minute per IP
     */
    public static function init_nopriv_rate_limits() {
        if (!defined('DOING_AJAX') || !DOING_AJAX) return;
        if (is_user_logged_in()) return;

        $action = $_REQUEST['action'] ?? '';
        if (strpos($action, 'ptp_') !== 0) return;

        // Tighter limits for sensitive endpoints
        $tight_limit_actions = array(
            'ptp_process_booking_payment', 'ptp_complete_payment',
            'ptp_camp_checkout', 'ptp_wizard_process_booking',
            'ptp_thankyou_add_training', 'ptp_thankyou_add_camp',
        );
        $medium_limit_actions = array(
            'ptp_camps_capture_email', 'ptp_wizard_submit_lead',
            'ptp_send_public_message', 'ptp_add_to_waitlist',
            'ptp_submit_announcement', 'ptp_save_social_announcement',
            'ptp_generate_share_card',
        );

        if (in_array($action, $tight_limit_actions)) {
            self::rate_limit('nopriv_tight_' . $action, 10, 60);
        } elseif (in_array($action, $medium_limit_actions)) {
            self::rate_limit('nopriv_med_' . $action, 15, 60);
        } else {
            self::rate_limit('nopriv_general', 30, 60);
        }
    }
}
