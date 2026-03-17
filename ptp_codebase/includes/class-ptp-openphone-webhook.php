<?php
/**
 * PTP OpenPhone Webhook Handler - v228.0
 *
 * Receives real-time events from OpenPhone via webhook:
 *   - message.received   (incoming SMS)
 *   - message.delivered   (outbound delivery confirmation)
 *   - call.ringing        (incoming call)
 *   - call.completed      (call ended)
 *
 * Register endpoint: POST /wp-json/ptp/v1/openphone/webhook
 *
 * Setup (one-time):
 *   1. Go to OpenPhone Settings > Webhooks
 *   2. URL:  https://ptpsummercamps.com/wp-json/ptp/v1/openphone/webhook
 *   3. Events: message.received, message.delivered
 *   4. Copy the signing secret into WP Admin > PTP > SMS > Webhook Secret
 *
 * @since 228.0.0
 */

defined('ABSPATH') || exit;

class PTP_OpenPhone_Webhook {

    /** Register REST route */
    public static function register() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('ptp/v1', '/openphone/webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_webhook'],
            'permission_callback' => '__return_true', // auth is via webhook secret
        ]);
    }

    /**
     * Main webhook handler
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_webhook($request) {
        $payload = $request->get_json_params();

        // Basic structure validation
        if (empty($payload['type']) || empty($payload['data']['object'])) {
            ptp_log('[PTP OpenPhone Webhook] Invalid payload — missing type or data.object');
            return new WP_REST_Response(['status' => 'invalid_payload'], 400);
        }

        $type   = sanitize_text_field($payload['type']);
        $object = $payload['data']['object'];

        ptp_log("[PTP OpenPhone Webhook] Event: {$type} | ID: " . ($object['id'] ?? 'n/a'));

        switch ($type) {
            case 'message.received':
                self::handle_incoming_message($object);
                break;

            case 'message.delivered':
                self::handle_delivery_status($object);
                break;

            case 'call.ringing':
            case 'call.completed':
                self::handle_call_event($type, $object);
                break;

            default:
                ptp_log("[PTP OpenPhone Webhook] Unhandled event type: {$type}");
        }

        // Always return 200 quickly so OpenPhone doesn't retry
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    // =========================================================================
    //  INCOMING MESSAGE
    // =========================================================================

    /**
     * Process an incoming SMS received on your OpenPhone number.
     *
     * @param array $msg OpenPhone message object
     */
    private static function handle_incoming_message($msg) {
        $from = sanitize_text_field($msg['from'] ?? '');
        $to   = is_array($msg['to'] ?? null) ? sanitize_text_field($msg['to'][0] ?? '') : sanitize_text_field($msg['to'] ?? '');
        $text = sanitize_textarea_field($msg['text'] ?? $msg['body'] ?? '');

        if (empty($from) || empty($text)) {
            ptp_log('[PTP OpenPhone Webhook] Incoming message missing from or text');
            return;
        }

        ptp_log("[PTP OpenPhone Webhook] Incoming from {$from}: " . substr($text, 0, 80));

        // 1. Log to ptp_sms_log
        self::log_incoming($from, $to, $text, $msg['id'] ?? '');

        // 2. Try to match sender to a known parent or trainer
        global $wpdb;

        $normalized = self::normalize_phone($from);

        // Check parents
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, display_name FROM {$wpdb->prefix}ptp_parents WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), '(', ''), ')', '') LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like($normalized) . '%'
        ));

        // Check trainers
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, display_name FROM {$wpdb->prefix}ptp_trainers WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), '(', ''), ')', '') LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like($normalized) . '%'
        ));

        // 3. Fire action so other PTP systems can react
        do_action('ptp_sms_received', [
            'from'         => $from,
            'to'           => $to,
            'text'         => $text,
            'openphone_id' => $msg['id'] ?? '',
            'parent'       => $parent,
            'trainer'      => $trainer,
            'raw'          => $msg,
        ]);

        // 4. Auto-reply for common keywords (STOP is handled by OpenPhone/carrier)
        $lower = strtolower(trim($text));

        if (in_array($lower, ['help', 'info'], true)) {
            if (class_exists('PTP_SMS_V71') && PTP_SMS_V71::is_enabled()) {
                PTP_SMS_V71::send($from, ptp_email_brand('company') . "\nVisit " . str_replace(array('https://', 'http://'), '', ptp_email_brand('site_url')) . " or reply with your question and we'll get back to you.");
            }
        }

        // 5. Notify admin of incoming message (optional Slack/email hook)
        do_action('ptp_openphone_incoming_notify', $from, $text, $parent, $trainer);
    }

    // =========================================================================
    //  DELIVERY STATUS
    // =========================================================================

    /**
     * Update SMS log when a message is delivered.
     */
    private static function handle_delivery_status($msg) {
        global $wpdb;

        $sid    = sanitize_text_field($msg['id'] ?? '');
        $status = sanitize_text_field($msg['status'] ?? 'delivered');

        if (empty($sid)) return;

        $table = $wpdb->prefix . 'ptp_sms_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $wpdb->update(
                $table,
                ['status' => $status],
                ['provider_sid' => $sid]
            );
        }

        ptp_log("[PTP OpenPhone Webhook] Delivery status for {$sid}: {$status}");
    }

    // =========================================================================
    //  CALL EVENTS
    // =========================================================================

    /**
     * Handle incoming call events — log and fire actions.
     */
    private static function handle_call_event($type, $call) {
        $from      = sanitize_text_field($call['from'] ?? ($call['participants'][0] ?? ''));
        $direction = sanitize_text_field($call['direction'] ?? 'unknown');
        $status    = sanitize_text_field($call['status'] ?? '');
        $duration  = intval($call['duration'] ?? 0);

        ptp_log("[PTP OpenPhone Webhook] Call {$type} | {$direction} from {$from} | status={$status} duration={$duration}s");

        // Fire action for other PTP systems
        do_action('ptp_openphone_call', [
            'type'      => $type,
            'from'      => $from,
            'direction' => $direction,
            'status'    => $status,
            'duration'  => $duration,
            'raw'       => $call,
        ]);
    }

    // =========================================================================
    //  HELPERS
    // =========================================================================

    /**
     * Strip non-digits from phone for DB matching.
     */
    private static function normalize_phone($phone) {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        // Remove leading '1' country code for 11-digit US numbers
        if (strlen($digits) === 11 && substr($digits, 0, 1) === '1') {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    /**
     * Log incoming message to ptp_sms_log.
     */
    private static function log_incoming($from, $to, $text, $sid) {
        global $wpdb;

        $table = $wpdb->prefix . 'ptp_sms_log';

        // Auto-create if missing
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            if (class_exists('PTP_SMS_V71')) {
                PTP_SMS_V71::create_table();
            }
        }

        $wpdb->insert($table, [
            'phone_to'     => $to,       // Our number (the recipient of inbound)
            'message'      => "[INBOUND from {$from}] {$text}",
            'provider'     => 'openphone',
            'provider_sid' => $sid,
            'status'       => 'received',
            'created_at'   => current_time('mysql'),
        ]);
    }
}

// Bootstrap
PTP_OpenPhone_Webhook::register();
