<?php
/**
 * PTP OpenPhone Bridge — v2.0
 * 
 * Deep integration between Free Session leads and OpenPhone.
 * Pulls real call + message data, syncs contacts, enriches conversion pipeline.
 *
 * OpenPhone API v1: https://www.openphone.com/docs/mdx/api-reference/introduction
 * 
 * USAGE:
 *   // Pull full conversation with a phone number
 *   $timeline = PTP_OpenPhone_Bridge::get_lead_timeline('+16105551234');
 *   
 *   // Sync a lead into OpenPhone contacts
 *   PTP_OpenPhone_Bridge::sync_lead_to_contact($app_id);
 *   
 *   // Get call history with a number
 *   $calls = PTP_OpenPhone_Bridge::get_calls('+16105551234');
 *   
 *   // Get message history
 *   $msgs = PTP_OpenPhone_Bridge::get_messages('+16105551234');
 *   
 *   // Send SMS from admin
 *   PTP_OpenPhone_Bridge::send_sms('+16105551234', 'Hey!');
 *   
 *   // Auto-detect if lead was called and update status
 *   PTP_OpenPhone_Bridge::auto_detect_call_status($app_id);
 */

defined('ABSPATH') || exit;

class PTP_OpenPhone_Bridge {

    private static $api_base = 'https://api.openphone.com/v1';
    private static $api_key  = '';
    private static $from     = '';
    private static $cache_ttl = 120; // seconds

    public static function init() {
        self::$api_key = get_option('ptp_openphone_api_key', 'fRwRNgl2ynRQFy5oBUhvRuZtQA2Cz4CS');
        self::$from    = get_option('ptp_openphone_from', '+16106714778');

        // AJAX endpoints for admin UI
        add_action('wp_ajax_ptp_op_timeline',       [__CLASS__, 'ajax_timeline']);
        add_action('wp_ajax_ptp_op_send_sms',       [__CLASS__, 'ajax_send_sms']);
        add_action('wp_ajax_ptp_op_sync_contact',   [__CLASS__, 'ajax_sync_contact']);
        add_action('wp_ajax_ptp_op_detect_calls',   [__CLASS__, 'ajax_detect_calls']);
        add_action('wp_ajax_ptp_op_bulk_detect',    [__CLASS__, 'ajax_bulk_detect']);

        // Auto-sync new leads to OpenPhone contacts

        // Auto-detect call when call status manually changed to completed

        // ── WEBHOOK EVENT LISTENERS ──
        // When OpenPhone webhook fires events, auto-update matching lead records
        add_action('ptp_sms_received',    [__CLASS__, 'on_webhook_sms_received'], 15, 1);
        add_action('ptp_openphone_call',  [__CLASS__, 'on_webhook_call_event'], 15, 1);

        // DB migration
        add_action('init', [__CLASS__, 'ensure_columns'], 25);
    }

    // ─── DATABASE ────────────────────────────────────────────────

    public static function ensure_columns() {
        if (get_option('ptp_op_bridge_db') === '1.0') return;

        global $wpdb;
        $table = $wpdb->prefix . 'ptp_session_applications';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) return;

        $existing = wp_list_pluck($wpdb->get_results("SHOW COLUMNS FROM {$table}"), 'Field');

        $cols = [
            'openphone_contact_id' => "varchar(100) DEFAULT NULL",
            'last_call_at'         => "datetime DEFAULT NULL",
            'last_call_duration'   => "int(11) DEFAULT 0",
            'last_call_direction'  => "varchar(20) DEFAULT NULL",
            'total_calls'          => "int(11) DEFAULT 0",
            'total_sms_sent'       => "int(11) DEFAULT 0",
            'total_sms_received'   => "int(11) DEFAULT 0",
            'last_sms_received_at' => "datetime DEFAULT NULL",
            'last_parent_reply'    => "text DEFAULT NULL",
            'call_auto_detected'   => "tinyint(1) DEFAULT 0",
            'openphone_synced_at'  => "datetime DEFAULT NULL",
        ];

        foreach ($cols as $col => $def) {
            if (!in_array($col, $existing, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
            }
        }

        update_option('ptp_op_bridge_db', '1.0', false);
    }

    // ─── API CLIENT ─────────────────────────────────────────────

    private static function api_get($endpoint, $params = []) {
        if (empty(self::$api_key)) return new \WP_Error('no_key', 'OpenPhone API key not set');

        $url = self::$api_base . $endpoint;
        if ($params) $url .= '?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => self::$api_key, 'Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return new \WP_Error('api_error', $body['message'] ?? "HTTP {$code}", ['status' => $code]);
        }

        return $body;
    }

    private static function api_post($endpoint, $data = []) {
        if (empty(self::$api_key)) return new \WP_Error('no_key', 'OpenPhone API key not set');

        $response = wp_remote_post(self::$api_base . $endpoint, [
            'headers' => ['Authorization' => self::$api_key, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode($data),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return new \WP_Error('api_error', $body['message'] ?? "HTTP {$code}", ['status' => $code]);
        }

        return $body;
    }

    private static function api_patch($endpoint, $data = []) {
        if (empty(self::$api_key)) return new \WP_Error('no_key', 'OpenPhone API key not set');

        $response = wp_remote_request(self::$api_base . $endpoint, [
            'method'  => 'PATCH',
            'headers' => ['Authorization' => self::$api_key, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode($data),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return new \WP_Error('api_error', $body['message'] ?? "HTTP {$code}", ['status' => $code]);
        }

        return $body;
    }

    // ─── PHONE NUMBER HELPERS ───────────────────────────────────

    public static function normalize_phone($phone) {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digits) === 10) return '+1' . $digits;
        if (strlen($digits) === 11 && $digits[0] === '1') return '+' . $digits;
        if (strlen($digits) > 10) return '+' . $digits;
        return false;
    }

    private static function get_phone_number_id() {
        $cached = get_transient('ptp_op_phone_id');
        if ($cached) return $cached;

        $result = self::api_get('/phone-numbers');
        if (is_wp_error($result)) return null;

        $numbers = $result['data'] ?? [];
        foreach ($numbers as $num) {
            if (($num['number'] ?? '') === self::$from || ($num['formattedNumber'] ?? '') === self::$from) {
                set_transient('ptp_op_phone_id', $num['id'], DAY_IN_SECONDS);
                return $num['id'];
            }
        }

        // Fallback: use first number
        if (!empty($numbers[0]['id'])) {
            set_transient('ptp_op_phone_id', $numbers[0]['id'], DAY_IN_SECONDS);
            return $numbers[0]['id'];
        }

        return null;
    }

    // ─── CORE: GET CALLS ────────────────────────────────────────

    /**
     * Get call history with a specific phone number.
     *
     * @param  string $phone E.164 phone number
     * @param  int    $max_results
     * @return array  List of call objects
     */
    public static function get_calls($phone, $max_results = 20) {
        $phone = self::normalize_phone($phone);
        if (!$phone) return [];

        $phone_id = self::get_phone_number_id();
        if (!$phone_id) return [];

        $cache_key = 'ptp_op_calls_' . md5($phone);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $params = [
            'phoneNumberId'   => $phone_id,
            'participants'    => [$phone],
            'maxResults'      => min($max_results, 50),
        ];

        $result = self::api_get('/calls', $params);
        if (is_wp_error($result)) {
            ptp_log('[PTP OP Bridge] Get calls error: ' . $result->get_error_message());
            return [];
        }

        $calls = [];
        foreach (($result['data'] ?? []) as $call) {
            $calls[] = [
                'id'         => $call['id'] ?? '',
                'direction'  => $call['direction'] ?? 'unknown',
                'status'     => $call['status'] ?? '',
                'duration'   => intval($call['duration'] ?? 0),
                'created_at' => $call['createdAt'] ?? '',
                'answered'   => !empty($call['answeredAt']),
                'answered_at'=> $call['answeredAt'] ?? null,
                'from'       => $call['from'] ?? '',
                'to'         => is_array($call['to'] ?? null) ? ($call['to'][0] ?? '') : ($call['to'] ?? ''),
                'voicemail'  => !empty($call['voicemail']),
            ];
        }

        set_transient($cache_key, $calls, self::$cache_ttl);
        return $calls;
    }

    /**
     * Get call transcript/summary if available.
     */
    public static function get_call_summary($call_id) {
        if (!$call_id) return null;

        $result = self::api_get("/calls/{$call_id}/summary");
        if (is_wp_error($result)) return null;

        return $result['data'] ?? null;
    }

    public static function get_call_transcript($call_id) {
        if (!$call_id) return null;

        $result = self::api_get("/calls/{$call_id}/transcription");
        if (is_wp_error($result)) return null;

        return $result['data'] ?? null;
    }

    // ─── CORE: GET MESSAGES ─────────────────────────────────────

    /**
     * Get SMS conversation with a phone number.
     *
     * @param  string $phone E.164
     * @param  int    $max_results
     * @return array
     */
    public static function get_messages($phone, $max_results = 30) {
        $phone = self::normalize_phone($phone);
        if (!$phone) return [];

        $phone_id = self::get_phone_number_id();
        if (!$phone_id) return [];

        $cache_key = 'ptp_op_msgs_' . md5($phone);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $params = [
            'phoneNumberId' => $phone_id,
            'participants'  => [$phone],
            'maxResults'    => min($max_results, 50),
        ];

        $result = self::api_get('/messages', $params);
        if (is_wp_error($result)) {
            ptp_log('[PTP OP Bridge] Get messages error: ' . $result->get_error_message());
            return [];
        }

        $messages = [];
        foreach (($result['data'] ?? []) as $msg) {
            $direction = ($msg['direction'] ?? '') === 'incoming' ? 'inbound' : 'outbound';
            $messages[] = [
                'id'        => $msg['id'] ?? '',
                'direction' => $direction,
                'body'      => $msg['text'] ?? $msg['body'] ?? $msg['content'] ?? '',
                'status'    => $msg['status'] ?? '',
                'created_at'=> $msg['createdAt'] ?? '',
                'from'      => $msg['from'] ?? '',
                'to'        => is_array($msg['to'] ?? null) ? ($msg['to'][0] ?? '') : ($msg['to'] ?? ''),
            ];
        }

        // Sort chronologically (oldest first)
        usort($messages, function($a, $b) {
            return strtotime($a['created_at']) - strtotime($b['created_at']);
        });

        set_transient($cache_key, $messages, self::$cache_ttl);
        return $messages;
    }

    // ─── CORE: SEND SMS ─────────────────────────────────────────

    /**
     * Send SMS via OpenPhone (wraps PTP_SMS_V71 but also logs to timeline).
     */
    public static function send_sms($phone, $message) {
        $phone = self::normalize_phone($phone);
        if (!$phone) return new \WP_Error('invalid_phone', 'Invalid phone number');

        // Use existing SMS class
        if (class_exists('PTP_SMS_V71')) {
            if (!PTP_SMS_V71::is_enabled()) PTP_SMS_V71::init();
            $result = PTP_SMS_V71::send($phone, $message);

            // Update lead stats if we can find them
            if (!is_wp_error($result)) {
                self::increment_lead_sms_count($phone, 'sent');
                // Clear message cache so timeline refreshes
                delete_transient('ptp_op_msgs_' . md5($phone));
                delete_transient('ptp_op_timeline_' . md5($phone));

                // Fire hook for Command Center / other systems
                do_action('ptp_sms_sent', [
                    'to'      => $phone,
                    'from'    => self::$from,
                    'message' => $message,
                    'source'  => 'openphone_bridge_admin',
                    'result'  => $result,
                ]);
            }

            return $result;
        }

        // Fallback: direct API call
        return self::api_post('/messages', [
            'content' => mb_substr($message, 0, 1600),
            'from'    => self::$from,
            'to'      => [$phone],
        ]);
    }

    // ─── CORE: CONTACTS ─────────────────────────────────────────

    /**
     * Create or update an OpenPhone contact for a lead.
     *
     * @param  int   $app_id  Session application ID
     * @return string|WP_Error  Contact ID
     */
    public static function sync_lead_to_contact($app_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_session_applications';
        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", intval($app_id)));
        if (!$lead) return new \WP_Error('not_found', 'Lead not found');

        $phone = self::normalize_phone($lead->phone);
        if (!$phone) return new \WP_Error('no_phone', 'Lead has no valid phone');

        $name_parts = explode(' ', $lead->parent_name ?? '', 2);

        // Check if contact already exists
        if (!empty($lead->openphone_contact_id)) {
            // Update existing
            $result = self::api_patch('/contacts/' . $lead->openphone_contact_id, [
                'firstName'    => $name_parts[0] ?? '',
                'lastName'     => $name_parts[1] ?? '',
                'emails'       => $lead->email ? [['value' => $lead->email]] : [],
            ]);

            if (!is_wp_error($result)) {
                $wpdb->update($table, ['openphone_synced_at' => current_time('mysql')], ['id' => $app_id]);
                return $lead->openphone_contact_id;
            }
        }

        // Create new contact
        $contact_data = [
            'firstName'    => $name_parts[0] ?? '',
            'lastName'     => $name_parts[1] ?? '',
            'phoneNumbers' => [['value' => $phone]],
            'emails'       => $lead->email ? [['value' => $lead->email]] : [],
            'company'      => 'PTP Lead',
            'role'         => 'Parent — ' . ($lead->child_name ?? 'Unknown'),
            'source'       => 'ptp_free_session',
            'externalId'   => 'ptp_fsa_' . $app_id,
        ];

        $result = self::api_post('/contacts', $contact_data);
        if (is_wp_error($result)) {
            ptp_log('[PTP OP Bridge] Contact create error: ' . $result->get_error_message());
            return $result;
        }

        $contact_id = $result['data']['id'] ?? '';
        if ($contact_id) {
            $wpdb->update($table, [
                'openphone_contact_id' => $contact_id,
                'openphone_synced_at'  => current_time('mysql'),
            ], ['id' => $app_id]);

            // Fire hook for Command Center / other systems
            do_action('ptp_op_contact_synced', $app_id, $contact_id, $lead);
        }

        return $contact_id;
    }

    // ─── CORE: LEAD TIMELINE ────────────────────────────────────

    /**
     * Build a unified timeline of all interactions with a lead.
     * Merges: calls, messages (sent + received), status changes.
     *
     * @param  string $phone
     * @param  int|null $app_id  Optional: include internal status events
     * @return array
     */
    public static function get_lead_timeline($phone, $app_id = null) {
        $phone = self::normalize_phone($phone);
        if (!$phone) return ['events' => [], 'stats' => []];

        $cache_key = 'ptp_op_timeline_' . md5($phone . '_' . $app_id);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $events = [];

        // 1. Calls
        $calls = self::get_calls($phone);
        foreach ($calls as $c) {
            $label = $c['direction'] === 'outgoing' ? 'Outbound Call' : 'Inbound Call';
            if (!$c['answered']) $label .= ' (Missed)';
            if ($c['voicemail']) $label .= ' + Voicemail';

            $events[] = [
                'type'       => 'call',
                'direction'  => $c['direction'],
                'label'      => $label,
                'duration'   => $c['duration'],
                'duration_fmt'=> $c['duration'] > 0 ? gmdate('i:s', $c['duration']) : '0:00',
                'answered'   => $c['answered'],
                'timestamp'  => $c['created_at'],
                'call_id'    => $c['id'],
                'icon'       => $c['direction'] === 'outgoing' ? 'phone-outgoing' : 'phone-incoming',
            ];
        }

        // 2. Messages
        $messages = self::get_messages($phone);
        foreach ($messages as $m) {
            $events[] = [
                'type'      => 'sms',
                'direction' => $m['direction'],
                'label'     => $m['direction'] === 'outbound' ? 'SMS Sent' : 'SMS Received',
                'body'      => $m['body'],
                'body_preview' => mb_strlen($m['body']) > 120 ? mb_substr($m['body'], 0, 120) . '...' : $m['body'],
                'status'    => $m['status'],
                'timestamp' => $m['created_at'],
                'icon'      => $m['direction'] === 'outbound' ? 'message-square' : 'message-circle',
            ];
        }

        // 3. Internal status events (from DB)
        if ($app_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'ptp_session_applications';
            $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $app_id));

            if ($lead) {
                $events[] = [
                    'type'      => 'system',
                    'direction' => 'internal',
                    'label'     => 'Lead Submitted',
                    'body'      => $lead->child_name . ' (age ' . $lead->child_age . ') — ' . ($lead->biggest_challenge ?: $lead->goal ?: 'No notes'),
                    'timestamp' => $lead->created_at,
                    'icon'      => 'user-plus',
                ];

                if ($lead->accepted_at) {
                    $events[] = [
                        'type'      => 'system',
                        'direction' => 'internal',
                        'label'     => 'Accepted' . ($lead->trainer_name ? ' — ' . $lead->trainer_name : ''),
                        'timestamp' => $lead->accepted_at,
                        'icon'      => 'check-circle',
                    ];
                }

                if ($lead->trainer_sent_at) {
                    $events[] = [
                        'type'      => 'system',
                        'direction' => 'internal',
                        'label'     => 'Trainer Sent: ' . ($lead->trainer_name ?: $lead->trainer_slug ?: 'Unknown'),
                        'timestamp' => $lead->trainer_sent_at,
                        'icon'      => 'send',
                    ];
                }
            }
        }

        // Sort by timestamp (newest first for display)
        usort($events, function($a, $b) {
            return strtotime($b['timestamp'] ?? 0) - strtotime($a['timestamp'] ?? 0);
        });

        // Build stats
        $stats = [
            'total_calls'       => 0,
            'answered_calls'    => 0,
            'total_call_time'   => 0,
            'outbound_sms'      => 0,
            'inbound_sms'       => 0,
            'last_contact'      => null,
            'last_parent_reply' => null,
            'response_rate'     => 0,
        ];

        foreach ($events as $e) {
            if ($e['type'] === 'call') {
                $stats['total_calls']++;
                if ($e['answered'] ?? false) {
                    $stats['answered_calls']++;
                    $stats['total_call_time'] += $e['duration'] ?? 0;
                }
            }
            if ($e['type'] === 'sms' && $e['direction'] === 'outbound') $stats['outbound_sms']++;
            if ($e['type'] === 'sms' && $e['direction'] === 'inbound') {
                $stats['inbound_sms']++;
                if (!$stats['last_parent_reply']) {
                    $stats['last_parent_reply'] = $e['body'] ?? '';
                }
            }
            if (!$stats['last_contact'] && in_array($e['type'], ['call', 'sms'])) {
                $stats['last_contact'] = $e['timestamp'];
            }
        }

        $stats['total_call_time_fmt'] = $stats['total_call_time'] > 0 ? gmdate('H:i:s', $stats['total_call_time']) : '0:00';
        $stats['response_rate'] = $stats['outbound_sms'] > 0
            ? round(($stats['inbound_sms'] / $stats['outbound_sms']) * 100)
            : 0;

        $result = ['events' => $events, 'stats' => $stats];
        set_transient($cache_key, $result, self::$cache_ttl);
        return $result;
    }

    // ─── AUTO-DETECT CALL STATUS ────────────────────────────────

    /**
     * Check OpenPhone for actual calls to this lead and update call_status.
     * Returns the detected status.
     */
    public static function auto_detect_call_status($app_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_session_applications';
        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", intval($app_id)));
        if (!$lead || !$lead->phone) return null;

        $calls = self::get_calls($lead->phone);
        $messages = self::get_messages($lead->phone);

        // Count real interactions
        $answered = array_filter($calls, function($c) { return $c['answered']; });
        $outbound_calls = array_filter($calls, function($c) { return $c['direction'] === 'outgoing'; });
        $inbound_sms = array_filter($messages, function($m) { return $m['direction'] === 'inbound'; });

        $update = [
            'total_calls'        => count($calls),
            'total_sms_sent'     => count(array_filter($messages, function($m) { return $m['direction'] === 'outbound'; })),
            'total_sms_received' => count($inbound_sms),
            'call_auto_detected' => 1,
        ];

        // Last call info
        $outbound_answered = array_filter($calls, function($c) { return $c['direction'] === 'outgoing' && $c['answered']; });
        if (!empty($outbound_answered)) {
            $last = reset($outbound_answered); // newest first
            $update['last_call_at'] = date('Y-m-d H:i:s', strtotime($last['created_at']));
            $update['last_call_duration'] = $last['duration'];
            $update['last_call_direction'] = 'outgoing';
        }

        // Last parent reply
        if (!empty($inbound_sms)) {
            $last_reply = reset($inbound_sms);
            $update['last_sms_received_at'] = date('Y-m-d H:i:s', strtotime($last_reply['created_at']));
            $update['last_parent_reply'] = mb_substr($last_reply['body'] ?? '', 0, 500);
        }

        // Auto-detect call_status
        $new_status = $lead->call_status;
        if (!empty($outbound_answered)) {
            $longest = max(array_column($outbound_answered, 'duration'));
            if ($longest >= 60) {
                $new_status = 'completed'; // 1+ min answered call = real conversation
            } elseif ($longest >= 10) {
                $new_status = 'completed'; // brief but connected
            }
        } elseif (!empty($outbound_calls) && empty($outbound_answered)) {
            // Called but no answer
            if ($lead->call_status === 'not_scheduled') {
                $new_status = 'scheduled'; // at least attempted
            }
        }

        // Don't downgrade: if already 'completed', keep it
        $status_rank = ['not_scheduled' => 0, 'scheduled' => 1, 'completed' => 2, 'no_show' => 2, 'not_a_fit' => 2];
        if (($status_rank[$new_status] ?? 0) > ($status_rank[$lead->call_status] ?? 0)) {
            $update['call_status'] = $new_status;
        }

        $wpdb->update($table, $update, ['id' => $app_id]);

        // Fire hooks for Command Center / other systems
        do_action('ptp_op_call_detected', $app_id, $update, $lead);

        // If call status changed, fire specific hook
        if (isset($update['call_status']) && $update['call_status'] !== $lead->call_status) {
            do_action('ptp_free_session_call_status_changed', $app_id, $update['call_status'], $lead->call_status, $lead);
        }

        return $update;
    }

    // ─── AUTO-DETECT: BULK ──────────────────────────────────────

    /**
     * Scan all pending/recent leads and auto-detect call status.
     * Run this on a schedule or button click.
     */
    public static function bulk_detect_calls($limit = 20) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_session_applications';

        // Get leads that haven't been auto-detected recently
        $leads = $wpdb->get_results($wpdb->prepare(
            "SELECT id, phone, parent_name, call_status
             FROM {$table}
             WHERE phone != '' AND phone IS NOT NULL
               AND (call_auto_detected = 0 OR call_auto_detected IS NULL)
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        ));

        $updated = 0;
        foreach ($leads as $lead) {
            $result = self::auto_detect_call_status($lead->id);
            if ($result) $updated++;
            // Rate limit: small delay between API calls
            usleep(200000); // 200ms
        }

        do_action('ptp_op_bulk_detect_complete', $updated, count($leads));

        return $updated;
    }

    // ─── HOOKS ──────────────────────────────────────────────────

    /**
     * When a new lead submits, auto-sync to OpenPhone.
     */
    public static function on_lead_submitted($app_id, $data = []) {
        // Defer to avoid blocking the response
        if (!wp_next_scheduled('ptp_op_sync_lead', [$app_id])) {
            wp_schedule_single_event(time() + 5, 'ptp_op_sync_lead', [$app_id]);
        }
    }

    public static function on_call_marked_completed($app_id, $lead) {
        // Pull real data from OpenPhone to enrich
        self::auto_detect_call_status($app_id);
    }

    /**
     * Real-time: When OpenPhone webhook fires ptp_sms_received,
     * find the matching free session lead and update their record.
     * This keeps lead records fresh without manual "Sync OpenPhone" clicks.
     */
    public static function on_webhook_sms_received($data) {
        if (empty($data['from'])) return;

        global $wpdb;
        $table = $wpdb->prefix . 'ptp_session_applications';
        $phone_clean = preg_replace('/[^0-9]/', '', $data['from']);
        if (strlen($phone_clean) < 10) return;

        // Find matching lead by phone
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT id, phone FROM {$table}
             WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', '') LIKE %s
             ORDER BY created_at DESC LIMIT 1",
            '%' . $wpdb->esc_like(substr($phone_clean, -10)) . '%'
        ));

        if (!$lead) return;

        // Update reply data directly (faster than full API re-scan)
        $text = sanitize_textarea_field($data['text'] ?? '');
        $wpdb->update($table, [
            'total_sms_received'   => intval($wpdb->get_var($wpdb->prepare(
                "SELECT total_sms_received FROM {$table} WHERE id = %d", $lead->id
            ))) + 1,
            'last_sms_received_at' => current_time('mysql'),
            'last_parent_reply'    => mb_substr($text, 0, 500),
        ], ['id' => $lead->id]);

        // Clear timeline cache
        $normalized = self::normalize_phone($lead->phone);
        if ($normalized) {
            delete_transient('ptp_op_timeline_' . md5($normalized . '_' . $lead->id));
            delete_transient('ptp_op_msgs_' . md5($normalized));
        }

        // Fire hook for Command Center
        do_action('ptp_op_lead_sms_received', $lead->id, $text, $data);

        ptp_log("[PTP OP Bridge] Real-time SMS update for lead #{$lead->id} from {$data['from']}");
    }

    /**
     * Real-time: When OpenPhone webhook fires ptp_openphone_call,
     * find the matching free session lead and update call stats.
     */
    public static function on_webhook_call_event($data) {
        if (empty($data['from']) || ($data['type'] ?? '') !== 'call.completed') return;

        global $wpdb;
        $table = $wpdb->prefix . 'ptp_session_applications';

        // Determine the external phone (not our number)
        $direction = $data['direction'] ?? 'unknown';
        $phone = $data['from'] ?? '';

        // For outbound calls, 'from' is us, so we need the 'to' number
        // For inbound calls, 'from' is the parent
        // The webhook raw data has participants array
        $raw = $data['raw'] ?? [];
        $participants = $raw['participants'] ?? [];

        // Try to find the non-PTP phone
        $our_number = preg_replace('/[^0-9]/', '', self::$from);
        $external_phone = '';
        foreach ($participants as $p) {
            $p_clean = preg_replace('/[^0-9]/', '', $p);
            if (substr($p_clean, -10) !== substr($our_number, -10)) {
                $external_phone = $p_clean;
                break;
            }
        }
        if (!$external_phone) $external_phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($external_phone) < 10) return;

        // Find matching lead
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT id, phone, call_status FROM {$table}
             WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', '') LIKE %s
             ORDER BY created_at DESC LIMIT 1",
            '%' . $wpdb->esc_like(substr($external_phone, -10)) . '%'
        ));

        if (!$lead) return;

        $duration = intval($data['duration'] ?? 0);
        $update = [
            'total_calls' => intval($wpdb->get_var($wpdb->prepare(
                "SELECT total_calls FROM {$table} WHERE id = %d", $lead->id
            ))) + 1,
        ];

        // If answered call of significant duration, update call details
        if ($duration >= 10) {
            $update['last_call_at'] = current_time('mysql');
            $update['last_call_duration'] = $duration;
            $update['last_call_direction'] = $direction;

            // Auto-upgrade call status
            $status_rank = ['not_scheduled' => 0, 'scheduled' => 1, 'completed' => 2, 'no_show' => 2, 'not_a_fit' => 2];
            if (($status_rank['completed'] ?? 0) > ($status_rank[$lead->call_status] ?? 0)) {
                $update['call_status'] = 'completed';
                $update['call_auto_detected'] = 1;
            }
        }

        $wpdb->update($table, $update, ['id' => $lead->id]);

        // Clear caches
        $normalized = self::normalize_phone($lead->phone);
        if ($normalized) {
            delete_transient('ptp_op_timeline_' . md5($normalized . '_' . $lead->id));
            delete_transient('ptp_op_calls_' . md5($normalized));
        }

        // Fire hook for Command Center
        do_action('ptp_op_lead_call_completed', $lead->id, $duration, $data);

        ptp_log("[PTP OP Bridge] Real-time call update for lead #{$lead->id} | {$duration}s | status={$data['status']}");
    }

    // ─── HELPERS ────────────────────────────────────────────────

    private static function increment_lead_sms_count($phone, $direction = 'sent') {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_session_applications';
        $phone_clean = preg_replace('/[^0-9]/', '', $phone);

        $col = $direction === 'sent' ? 'total_sms_sent' : 'total_sms_received';

        // Find lead by phone (fuzzy)
        $lead_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', '') LIKE %s
             ORDER BY created_at DESC LIMIT 1",
            '%' . $wpdb->esc_like(substr($phone_clean, -10)) . '%'
        ));

        if ($lead_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET {$col} = COALESCE({$col}, 0) + 1 WHERE id = %d",
                $lead_id
            ));
        }
    }

    // ─── AJAX HANDLERS ──────────────────────────────────────────

    public static function ajax_timeline() {
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        check_ajax_referer('ptp_fsa_match_nonce');

        $phone  = sanitize_text_field($_POST['phone'] ?? '');
        $app_id = intval($_POST['app_id'] ?? 0);

        if (!$phone) wp_send_json_error('No phone number');

        $timeline = self::get_lead_timeline($phone, $app_id ?: null);
        wp_send_json_success($timeline);
    }

    public static function ajax_send_sms() {
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        check_ajax_referer('ptp_fsa_match_nonce');

        $phone   = sanitize_text_field($_POST['phone'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $app_id  = intval($_POST['app_id'] ?? 0);

        if (!$phone || !$message) wp_send_json_error('Phone and message required');

        $result = self::send_sms($phone, $message);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Clear caches
        $normalized = self::normalize_phone($phone);
        delete_transient('ptp_op_msgs_' . md5($normalized));
        delete_transient('ptp_op_timeline_' . md5($normalized . '_' . $app_id));

        wp_send_json_success([
            'message_id' => is_string($result) ? $result : ($result['data']['id'] ?? ''),
            'sent'       => true,
        ]);
    }

    public static function ajax_sync_contact() {
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        check_ajax_referer('ptp_fsa_match_nonce');

        $app_id = intval($_POST['app_id'] ?? 0);
        if (!$app_id) wp_send_json_error('No app ID');

        $result = self::sync_lead_to_contact($app_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['contact_id' => $result]);
    }

    public static function ajax_detect_calls() {
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        check_ajax_referer('ptp_fsa_match_nonce');

        $app_id = intval($_POST['app_id'] ?? 0);
        if (!$app_id) wp_send_json_error('No app ID');

        $result = self::auto_detect_call_status($app_id);
        if (!$result) wp_send_json_error('Detection failed');

        wp_send_json_success($result);
    }

    public static function ajax_bulk_detect() {
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        check_ajax_referer('ptp_fsa_match_nonce');

        $updated = self::bulk_detect_calls(20);
        wp_send_json_success(['updated' => $updated]);
    }
}

// Cron hook for deferred contact sync
add_action('ptp_op_sync_lead', function($app_id) {
    if (class_exists('PTP_OpenPhone_Bridge')) {
        PTP_OpenPhone_Bridge::sync_lead_to_contact($app_id);
    }
});
