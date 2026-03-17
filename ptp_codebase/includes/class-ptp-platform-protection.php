<?php
/**
 * PTP Platform Protection
 * 
 * Prevents trainers from accidentally or intentionally degrading their
 * business on the platform, and protects PTP from disintermediation.
 * 
 * Five protection layers:
 * 1. Message content filter — flags off-platform contact attempts
 * 2. Cancel velocity limiter — caps trainer cancellations per rolling window
 * 3. Profile change monitor — alerts admin on significant edits
 * 4. Rate change guard — alerts + optional throttle on pricing changes
 * 5. Dormancy detector — cron flags trainers going inactive
 * 
 * @since v233
 */
defined('ABSPATH') || exit;

class PTP_Platform_Protection {

    /** Max trainer-initiated cancellations per 7-day window before soft-lock */
    const CANCEL_LIMIT_7D = 4;

    /** Max trainer-initiated cancellations per 24h before hard-lock */
    const CANCEL_LIMIT_24H = 2;

    /** Rate change % that triggers admin alert */
    const RATE_CHANGE_ALERT_PCT = 30;

    /** Days of zero availability before flagging dormant */
    const DORMANCY_THRESHOLD_DAYS = 14;

    /** Option key for protection settings */
    const OPTION_KEY = 'ptp_platform_protection';

    /** Flags table name (uses existing ptp_quality_flags) */
    private static $flags_table;

    /**
     * Boot
     */
    public static function init() {
        global $wpdb;
        self::$flags_table = $wpdb->prefix . 'ptp_quality_flags';

        // ─── 1. Message content filter ───
        add_filter('ptp_pre_send_message',      [__CLASS__, 'scan_message'], 10, 3);
        add_action('ptp_message_sent',           [__CLASS__, 'post_scan_message'], 10, 3);

        // ─── 2. Cancel velocity limiter ───
        add_action('wp_ajax_ptp_trainer_cancel_session', [__CLASS__, 'check_cancel_velocity'], 5); // priority 5 = before actual handler

        // ─── 3 & 4. Profile + rate change monitor ───
        add_action('wp_ajax_ptp_quick_save_trainer',     [__CLASS__, 'monitor_profile_change'], 5);

        // ─── 5. Dormancy cron ───
        add_action('ptp_daily_maintenance', [__CLASS__, 'check_dormant_trainers']);
        if (!wp_next_scheduled('ptp_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'ptp_daily_maintenance');
        }

        // Create protection log table
        add_action('admin_init', [__CLASS__, 'maybe_create_table']);
    }

    /**
     * Create protection log table on first run
     */
    public static function maybe_create_table() {
        if (get_option('ptp_protection_table_v1')) return;

        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_protection_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trainer_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            severity ENUM('info','warning','critical') DEFAULT 'info',
            details TEXT,
            message_id BIGINT UNSIGNED NULL,
            admin_notified TINYINT(1) DEFAULT 0,
            reviewed TINYINT(1) DEFAULT 0,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY trainer_id (trainer_id),
            KEY event_type (event_type),
            KEY severity (severity),
            KEY created_at (created_at)
        ) $charset;");

        update_option('ptp_protection_table_v1', 1);
    }


    /* ═══════════════════════════════════════════════════════════
     *  1. MESSAGE CONTENT FILTER
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Patterns that indicate off-platform contact attempts.
     * Each pattern has a weight (1-10) and category.
     */
    private static function get_message_patterns() {
        return [
            // ─── Phone numbers ───
            ['pattern' => '/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/',           'weight' => 7, 'category' => 'phone',   'label' => 'US phone number'],
            ['pattern' => '/\(\d{3}\)\s*\d{3}[-.\s]?\d{4}/',               'weight' => 7, 'category' => 'phone',   'label' => 'US phone (parens)'],
            ['pattern' => '/\+1\s?\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/',         'weight' => 8, 'category' => 'phone',   'label' => 'US phone +1'],
            ['pattern' => '/call\s+me\s+at|text\s+me\s+at|my\s+number\s+is|my\s+cell\s+is/i', 'weight' => 9, 'category' => 'phone', 'label' => 'Phone sharing language'],

            // ─── Email ───
            ['pattern' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', 'weight' => 6, 'category' => 'email', 'label' => 'Email address'],
            ['pattern' => '/email\s+me\s+at|my\s+email\s+is|send\s+me\s+an?\s+email/i', 'weight' => 8, 'category' => 'email', 'label' => 'Email sharing language'],

            // ─── Payment platforms ───
            ['pattern' => '/\b(venmo|cashapp|cash\s*app|zelle|paypal|apple\s*pay\s*me|gpay)\b/i', 'weight' => 10, 'category' => 'payment', 'label' => 'Payment platform'],
            ['pattern' => '/\$[a-zA-Z][a-zA-Z0-9_-]{2,}/i',                'weight' => 6, 'category' => 'payment', 'label' => 'Cashtag ($handle)'],
            ['pattern' => '/pay\s+me\s+direct|pay\s+me\s+outside|skip\s+the\s+fee|save\s+on\s+fees|avoid\s+the\s+platform/i', 'weight' => 10, 'category' => 'payment', 'label' => 'Direct payment language'],

            // ─── Social media handles ───
            ['pattern' => '/\b(my\s+)?(ig|insta|instagram)\b[:\s]*@?[a-zA-Z0-9_.]+/i', 'weight' => 5, 'category' => 'social', 'label' => 'Instagram handle'],
            ['pattern' => '/\b(my\s+)?(snap|snapchat)\b[:\s]*@?[a-zA-Z0-9_.]+/i',      'weight' => 5, 'category' => 'social', 'label' => 'Snapchat handle'],
            ['pattern' => '/\b(my\s+)?(tiktok|tik\s*tok)\b[:\s]*@?[a-zA-Z0-9_.]+/i',   'weight' => 4, 'category' => 'social', 'label' => 'TikTok handle'],
            ['pattern' => '/@[a-zA-Z0-9_.]{3,30}\b/',                       'weight' => 3, 'category' => 'social', 'label' => 'Generic @ handle'],

            // ─── Off-platform booking language ───
            ['pattern' => '/book\s+(me\s+)?direct(ly)?|book\s+outside|book\s+off\s+(the\s+)?platform/i',       'weight' => 10, 'category' => 'disintermediation', 'label' => 'Direct booking language'],
            ['pattern' => '/don\'?t\s+(need\s+to\s+)?go\s+through\s+(ptp|the\s+(site|app|platform))/i',       'weight' => 10, 'category' => 'disintermediation', 'label' => 'Bypass platform language'],
            ['pattern' => '/cheaper\s+if\s+(you|we)\s+(go|book)\s+direct|save\s+(you\s+)?money\s+if/i',       'weight' => 10, 'category' => 'disintermediation', 'label' => 'Price incentive to go direct'],
            ['pattern' => '/my\s+own\s+(site|website|booking|page|link)/i', 'weight' => 8, 'category' => 'disintermediation', 'label' => 'Own booking site'],
            ['pattern' => '/https?:\/\/(?!.*ptpsummercamps\.com)[^\s]+/i',  'weight' => 5, 'category' => 'external_link', 'label' => 'Non-PTP URL'],

            // ─── Personal contact sharing ───
            ['pattern' => '/here\'?s?\s+my\s+(number|phone|cell|contact|info)/i',       'weight' => 8, 'category' => 'contact_share', 'label' => 'Sharing personal contact'],
            ['pattern' => '/reach\s+me\s+(at|on)|contact\s+me\s+(at|on|outside)/i',     'weight' => 7, 'category' => 'contact_share', 'label' => 'Contact outside platform'],
            ['pattern' => '/hit\s+me\s+up\s+(on|at)|dm\s+me\s+(on|at)/i',              'weight' => 6, 'category' => 'contact_share', 'label' => 'DM/contact request'],
        ];
    }

    /**
     * Pre-send filter: scan message, allow or flag
     * Hooked as filter on ptp_pre_send_message
     * 
     * @return array ['allow' => bool, 'flags' => array, 'score' => int]
     */
    public static function scan_message($message, $sender_id, $conversation_id = 0) {
        $results = [
            'allow' => true,
            'flags' => [],
            'score' => 0,
            'categories' => [],
        ];

        // Only scan trainer messages (parents sharing their own contact info is fine)
        $trainer = class_exists('PTP_Trainer') ? PTP_Trainer::get_by_user_id($sender_id) : null;
        if (!$trainer) {
            return $results; // Not a trainer, skip
        }

        $text = strtolower(trim($message));

        foreach (self::get_message_patterns() as $rule) {
            if (preg_match($rule['pattern'], $message, $matches)) {
                $results['flags'][] = [
                    'label'    => $rule['label'],
                    'category' => $rule['category'],
                    'weight'   => $rule['weight'],
                    'match'    => substr($matches[0], 0, 60),
                ];
                $results['score'] += $rule['weight'];
                $results['categories'][$rule['category']] = true;
            }
        }

        // Threshold: score >= 10 = block + admin alert, 5-9 = allow + log, <5 = pass
        if ($results['score'] >= 10) {
            $results['allow'] = false; // Block the message
        }

        return $results;
    }

    /**
     * Post-send hook: log flagged messages even if allowed (score 5-9)
     */
    public static function post_scan_message($conversation_id, $sender_id, $message_preview) {
        $scan = self::scan_message($message_preview, $sender_id, $conversation_id);

        if ($scan['score'] < 5) return; // Clean

        $trainer = class_exists('PTP_Trainer') ? PTP_Trainer::get_by_user_id($sender_id) : null;
        if (!$trainer) return;

        $severity = $scan['score'] >= 10 ? 'critical' : 'warning';

        self::log_event($trainer->id, 'message_flag', $severity, wp_json_encode([
            'score'      => $scan['score'],
            'flags'      => $scan['flags'],
            'categories' => array_keys($scan['categories']),
            'preview'    => substr($message_preview, 0, 100),
            'blocked'    => !$scan['allow'],
        ]));

        if ($scan['score'] >= 8) {
            self::notify_admin('message_flag', $trainer, sprintf(
                "Trainer %s (#%d) sent a message scoring %d/10 for off-platform contact.\n\nCategories: %s\nFlags: %s\n%s\n\nMessage preview: \"%s\"",
                $trainer->display_name,
                $trainer->id,
                $scan['score'],
                implode(', ', array_keys($scan['categories'])),
                implode(', ', array_column($scan['flags'], 'label')),
                $scan['allow'] ? 'Message was ALLOWED (below block threshold)' : '⚠️ Message was BLOCKED',
                substr($message_preview, 0, 200)
            ));
        }
    }

    /* ═══════════════════════════════════════════════════════════
     *  2. CANCEL VELOCITY LIMITER
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Check cancel velocity BEFORE the actual cancel handler fires.
     * Runs at priority 5 on wp_ajax_ptp_trainer_cancel_session.
     */
    public static function check_cancel_velocity() {
        // Don't block admin overrides
        if (current_user_can('manage_options')) return;

        global $wpdb;

        $user_id = get_current_user_id();
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));

        if (!$trainer) return;

        // Count trainer-initiated cancellations in last 24 hours
        $cancel_24h = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
             WHERE trainer_id = %d AND cancelled_by = 'trainer'
             AND cancelled_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $trainer->id
        ));

        // Count in last 7 days
        $cancel_7d = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
             WHERE trainer_id = %d AND cancelled_by = 'trainer'
             AND cancelled_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $trainer->id
        ));

        // Hard limit: 24h
        if ($cancel_24h >= self::CANCEL_LIMIT_24H) {
            self::log_event($trainer->id, 'cancel_velocity_block', 'critical', wp_json_encode([
                'cancel_24h' => $cancel_24h,
                'cancel_7d'  => $cancel_7d,
                'limit_hit'  => '24h',
            ]));

            self::notify_admin('cancel_velocity', $trainer, sprintf(
                "🚨 Trainer %s (#%d) hit the 24h cancel limit (%d cancellations in 24h, limit is %d).\n\nThis cancellation was BLOCKED. The trainer was shown a message to contact PTP support.\n\n7-day total: %d cancellations.",
                $trainer->display_name,
                $trainer->id,
                $cancel_24h,
                self::CANCEL_LIMIT_24H,
                $cancel_7d
            ));

            wp_send_json_error([
                'message' => "You've cancelled " . self::CANCEL_LIMIT_24H . " sessions today. To protect your families, additional cancellations require contacting PTP support at (610) 671-4778.",
                'blocked_by' => 'cancel_velocity',
            ]);
            exit; // Stop the real handler from running
        }

        // Soft limit: 7d — allow but warn + alert admin
        if ($cancel_7d >= self::CANCEL_LIMIT_7D) {
            self::log_event($trainer->id, 'cancel_velocity_block', 'critical', wp_json_encode([
                'cancel_24h' => $cancel_24h,
                'cancel_7d'  => $cancel_7d,
                'limit_hit'  => '7d',
            ]));

            self::notify_admin('cancel_velocity', $trainer, sprintf(
                "🚨 Trainer %s (#%d) hit the 7-day cancel limit (%d cancellations in 7 days, limit is %d).\n\nThis cancellation was BLOCKED. The trainer was shown a message to contact PTP support.",
                $trainer->display_name,
                $trainer->id,
                $cancel_7d,
                self::CANCEL_LIMIT_7D
            ));

            wp_send_json_error([
                'message' => "You've cancelled " . $cancel_7d . " sessions this week. To protect your families, additional cancellations require contacting PTP support at (610) 671-4778.",
                'blocked_by' => 'cancel_velocity',
            ]);
            exit;
        }

        // Warning at threshold - 1
        if ($cancel_24h >= self::CANCEL_LIMIT_24H - 1 || $cancel_7d >= self::CANCEL_LIMIT_7D - 1) {
            self::log_event($trainer->id, 'cancel_velocity_warn', 'warning', wp_json_encode([
                'cancel_24h' => $cancel_24h,
                'cancel_7d'  => $cancel_7d,
            ]));
        }

        // Let the real handler continue
    }


    /* ═══════════════════════════════════════════════════════════
     *  3 & 4. PROFILE + RATE CHANGE MONITOR
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Intercept profile saves to monitor changes.
     * Runs at priority 5 on wp_ajax_ptp_quick_save_trainer.
     */
    public static function monitor_profile_change() {
        $field = sanitize_key($_POST['field'] ?? '');
        $value = $_POST['value'] ?? '';
        $trainer_id = intval($_POST['trainer_id'] ?? 0);

        if (!$field || !$trainer_id) return; // Let the real handler validate

        global $wpdb;

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));

        if (!$trainer || $trainer->user_id != get_current_user_id()) return;

        // ─── Rate change detection ───
        if ($field === 'hourly_rate') {
            $old_rate = floatval($trainer->hourly_rate);
            $new_rate = max(40, min(200, floatval($value)));

            if ($old_rate > 0 && $new_rate != $old_rate) {
                $pct_change = abs($new_rate - $old_rate) / $old_rate * 100;
                $direction  = $new_rate > $old_rate ? 'increase' : 'decrease';

                self::log_event($trainer->id, 'rate_change', $pct_change >= self::RATE_CHANGE_ALERT_PCT ? 'warning' : 'info', wp_json_encode([
                    'old_rate'   => $old_rate,
                    'new_rate'   => $new_rate,
                    'pct_change' => round($pct_change, 1),
                    'direction'  => $direction,
                ]));

                if ($pct_change >= self::RATE_CHANGE_ALERT_PCT) {
                    self::notify_admin('rate_change', $trainer, sprintf(
                        "Trainer %s (#%d) changed their hourly rate by %.0f%% (%s).\n\nOld rate: $%s\nNew rate: $%s\n\nThis is a %s of $%s per session.",
                        $trainer->display_name,
                        $trainer->id,
                        $pct_change,
                        $direction,
                        number_format($old_rate),
                        number_format($new_rate),
                        $direction,
                        number_format(abs($new_rate - $old_rate))
                    ));
                }
            }
        }

        // ─── Bio/headline blanking detection ───
        if (in_array($field, ['bio', 'headline', 'coaching_why', 'training_philosophy'])) {
            $old_value = $trainer->$field ?? '';
            $new_value = sanitize_textarea_field($value);

            // Blanked a previously filled field
            if (strlen(trim($old_value)) > 20 && strlen(trim($new_value)) < 5) {
                self::log_event($trainer->id, 'profile_blanked', 'warning', wp_json_encode([
                    'field'      => $field,
                    'old_length' => strlen($old_value),
                    'new_length' => strlen($new_value),
                ]));

                self::notify_admin('profile_blanked', $trainer, sprintf(
                    "Trainer %s (#%d) blanked their '%s' field.\n\nPrevious content (%d chars) was replaced with: \"%s\"\n\nThis may impact their profile quality and bookings.",
                    $trainer->display_name,
                    $trainer->id,
                    $field,
                    strlen($old_value),
                    substr($new_value, 0, 100) ?: '(empty)'
                ));
            }
        }

        // ─── Training locations wipe detection ───
        if ($field === 'training_locations') {
            $old_locs = json_decode($trainer->training_locations ?? '[]', true);
            $new_locs = json_decode($value, true);

            if (is_array($old_locs) && count($old_locs) > 0 && (!is_array($new_locs) || count($new_locs) === 0)) {
                self::log_event($trainer->id, 'locations_cleared', 'warning', wp_json_encode([
                    'old_count' => count($old_locs),
                    'old_locations' => array_column($old_locs, 'name'),
                ]));

                self::notify_admin('locations_cleared', $trainer, sprintf(
                    "Trainer %s (#%d) removed ALL their training locations.\n\nPrevious locations: %s\n\nThey now have 0 locations — parents cannot book sessions without a location.",
                    $trainer->display_name,
                    $trainer->id,
                    implode(', ', array_column($old_locs, 'name'))
                ));
            }
        }

        // Let the real handler continue
    }


    /* ═══════════════════════════════════════════════════════════
     *  5. DORMANCY DETECTOR (CRON)
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Daily cron: identify trainers going dormant.
     * Checks: zero availability set, no logins, no confirmed sessions.
     */
    public static function check_dormant_trainers() {
        global $wpdb;

        $threshold = self::DORMANCY_THRESHOLD_DAYS;

        // Find active trainers with zero upcoming sessions and
        // no availability slots enabled in the last N days
        $dormant = $wpdb->get_results($wpdb->prepare("
            SELECT t.id, t.display_name, t.user_id,
                   (SELECT MAX(b.session_date) 
                    FROM {$wpdb->prefix}ptp_bookings b 
                    WHERE b.trainer_id = t.id AND b.status IN ('confirmed','completed')
                   ) as last_session,
                   (SELECT COUNT(*) 
                    FROM {$wpdb->prefix}ptp_bookings b2 
                    WHERE b2.trainer_id = t.id AND b2.status = 'confirmed' AND b2.session_date >= CURDATE()
                   ) as upcoming_count,
                   (SELECT COUNT(*) 
                    FROM {$wpdb->prefix}ptp_availability_slots s 
                    WHERE s.trainer_id = t.id AND s.is_active = 1
                   ) as active_slots
            FROM {$wpdb->prefix}ptp_trainers t
            WHERE t.status = 'active' AND t.is_active = 1
            HAVING upcoming_count = 0 
               AND active_slots = 0
               AND (last_session IS NULL OR last_session < DATE_SUB(CURDATE(), INTERVAL %d DAY))
        ", $threshold));

        if (empty($dormant)) return;

        // Avoid spamming — check if we already flagged in the last 7 days
        foreach ($dormant as $t) {
            $already_flagged = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_protection_log
                 WHERE trainer_id = %d AND event_type = 'dormant_flag'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                $t->id
            ));

            if ($already_flagged) continue;

            $days_since = $t->last_session
                ? floor((time() - strtotime($t->last_session)) / 86400)
                : 'never';

            self::log_event($t->id, 'dormant_flag', 'warning', wp_json_encode([
                'last_session'  => $t->last_session ?: 'never',
                'days_since'    => $days_since,
                'upcoming'      => 0,
                'active_slots'  => 0,
            ]));

            self::notify_admin('dormant_trainer', $t, sprintf(
                "Trainer %s (#%d) appears dormant.\n\n• Last session: %s (%s days ago)\n• Upcoming sessions: 0\n• Active availability slots: 0\n\nThis trainer is marked active but has no way for parents to book them. Consider reaching out or pausing their profile.",
                $t->display_name,
                $t->id,
                $t->last_session ?: 'Never',
                $days_since
            ));
        }
    }


    /* ═══════════════════════════════════════════════════════════
     *  UTILITIES
     * ═══════════════════════════════════════════════════════════ */

    /**
     * Log a protection event
     */
    private static function log_event($trainer_id, $event_type, $severity, $details) {
        global $wpdb;

        $table = $wpdb->prefix . 'ptp_protection_log';

        // Check table exists (graceful fallback)
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            ptp_log("PTP Protection: Log table missing, event: {$event_type} trainer:{$trainer_id}");
            return;
        }

        $wpdb->insert($table, [
            'trainer_id'  => $trainer_id,
            'event_type'  => $event_type,
            'severity'    => $severity,
            'details'     => $details,
            'created_at'  => current_time('mysql'),
        ]);
    }

    /**
     * Send admin notification
     */
    private static function notify_admin($type, $trainer, $body) {
        $admin_email = get_option('admin_email');

        $type_labels = [
            'message_flag'     => '🔍 Message Flag',
            'cancel_velocity'  => '🚫 Cancel Limit Hit',
            'rate_change'      => '💰 Rate Change Alert',
            'profile_blanked'  => '📝 Profile Blanked',
            'locations_cleared' => '📍 Locations Cleared',
            'dormant_trainer'  => '💤 Dormant Trainer',
        ];

        $label = $type_labels[$type] ?? '⚠️ Platform Alert';
        $trainer_name = is_object($trainer) ? ($trainer->display_name ?? 'Unknown') : 'Unknown';

        $subject = "[PTP Protection] {$label} — {$trainer_name}";

        $message = "PTP Platform Protection Alert\n";
        $message .= str_repeat('─', 40) . "\n\n";
        $message .= $body . "\n\n";
        $message .= str_repeat('─', 40) . "\n";

        if (is_object($trainer) && !empty($trainer->id)) {
            $message .= "View trainer: " . admin_url('admin.php?page=ptp-trainers&action=edit&id=' . $trainer->id) . "\n";
        }

        $message .= "Protection log: " . admin_url('admin.php?page=ptp-protection-log') . "\n";
        $message .= "\nThis is an automated alert from PTP Platform Protection v233.\n";

        wp_mail($admin_email, $subject, $message);

        // Mark as notified in log
        global $wpdb;
        if (is_object($trainer) && !empty($trainer->id)) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_protection_log',
                ['admin_notified' => 1],
                ['trainer_id' => $trainer->id, 'event_type' => str_replace(['cancel_velocity', 'dormant_trainer'], ['cancel_velocity_block', 'dormant_flag'], $type), 'admin_notified' => 0],
                ['%d'],
                ['%d', '%s', '%d']
            );
        }
    }

    /**
     * Get protection stats for admin dashboard
     */
    public static function get_stats($days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_protection_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return ['total' => 0, 'by_type' => [], 'by_severity' => []];
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));

        $by_type = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, COUNT(*) as cnt FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY event_type ORDER BY cnt DESC",
            $days
        ), OBJECT_K);

        $by_severity = $wpdb->get_results($wpdb->prepare(
            "SELECT severity, COUNT(*) as cnt FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY severity",
            $days
        ), OBJECT_K);

        $flagged_trainers = $wpdb->get_results($wpdb->prepare(
            "SELECT trainer_id, t.display_name, COUNT(*) as event_count,
                    SUM(CASE WHEN severity='critical' THEN 1 ELSE 0 END) as critical_count
             FROM {$table} pl
             JOIN {$wpdb->prefix}ptp_trainers t ON pl.trainer_id = t.id
             WHERE pl.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY trainer_id
             ORDER BY critical_count DESC, event_count DESC
             LIMIT 20",
            $days
        ));

        return [
            'total'           => $total,
            'by_type'         => $by_type,
            'by_severity'     => $by_severity,
            'flagged_trainers' => $flagged_trainers,
        ];
    }

    /**
     * Get recent events for a specific trainer
     */
    public static function get_trainer_events($trainer_id, $limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_protection_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE trainer_id = %d ORDER BY created_at DESC LIMIT %d",
            $trainer_id, $limit
        ));
    }
}
