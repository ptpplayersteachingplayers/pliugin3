<?php
/**
 * PTP Mentorship Recurring Sessions v1.0
 * 
 * Trainer sets a recurring day/time per mentee. Cron auto-creates
 * next week's session if one doesn't exist.
 * 
 * DB: Adds recurring_day, recurring_time, recurring_enabled to ptp_mentorship_pairs
 * Cron: ptp_mentorship_create_recurring (runs daily at 8am ET)
 * 
 * AJAX:
 *   ptp_mentorship_set_recurring   — trainer saves recurring schedule
 *   ptp_mentorship_pause_recurring — trainer pauses auto-schedule
 */

if (!defined('ABSPATH')) exit;

class PTP_Mentorship_Recurring {

    public static function init() {
        // AJAX
        add_action('wp_ajax_ptp_mentorship_set_recurring', array(__CLASS__, 'ajax_set_recurring'));
        add_action('wp_ajax_ptp_mentorship_pause_recurring', array(__CLASS__, 'ajax_pause_recurring'));
        add_action('wp_ajax_ptp_mentorship_dismiss_onboarding', array(__CLASS__, 'ajax_dismiss_onboarding'));

        // Cron
        add_action('ptp_mentorship_create_recurring', array(__CLASS__, 'create_recurring_sessions'));
        if (!wp_next_scheduled('ptp_mentorship_create_recurring')) {
            // Schedule for 8am ET daily
            $next_8am = strtotime('tomorrow 08:00 America/New_York');
            wp_schedule_event($next_8am, 'daily', 'ptp_mentorship_create_recurring');
        }

        // DB migration
        add_action('admin_init', array(__CLASS__, 'maybe_add_columns'), 30);
    }

    // ================================================================
    // DB MIGRATION
    // ================================================================
    public static function maybe_add_columns() {
        if (get_option('ptp_mentor_recurring_v') === '1.0') return;

        global $wpdb;
        $table = $wpdb->prefix . 'ptp_mentorship_pairs';
        if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) return;

        $cols = $wpdb->get_col("SHOW COLUMNS FROM $table");

        if (!in_array('recurring_enabled', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN recurring_enabled tinyint(1) DEFAULT 0 AFTER next_session_at");
        }
        if (!in_array('recurring_day', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN recurring_day tinyint(1) DEFAULT NULL AFTER recurring_enabled");
        }
        if (!in_array('recurring_time', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN recurring_time varchar(5) DEFAULT '16:00' AFTER recurring_day");
        }
        if (!in_array('recurring_duration', $cols)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN recurring_duration int(11) DEFAULT 45 AFTER recurring_time");
        }

        // Messages table
        $msg_table = $wpdb->prefix . 'ptp_mentorship_messages';
        if (!$wpdb->get_var("SHOW TABLES LIKE '$msg_table'")) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE $msg_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                pair_id bigint(20) UNSIGNED NOT NULL,
                sender_id bigint(20) UNSIGNED NOT NULL,
                sender_role enum('trainer','parent','system') DEFAULT 'parent',
                message text NOT NULL,
                attachment_url varchar(500) DEFAULT '',
                attachment_type varchar(20) DEFAULT '',
                is_read tinyint(1) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY pair_id (pair_id),
                KEY sender_id (sender_id),
                KEY is_read (is_read),
                KEY created_at (created_at)
            ) $charset;");
        }

        update_option('ptp_mentor_recurring_v', '1.0');
    }

    // ================================================================
    // SET RECURRING SCHEDULE (Trainer)
    // ================================================================
    public static function ajax_set_recurring() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Not a trainer');

        $pair_id  = intval($_POST['pair_id'] ?? 0);
        $day      = intval($_POST['recurring_day'] ?? -1); // 0=Sun, 1=Mon, ..., 6=Sat
        $time     = sanitize_text_field($_POST['recurring_time'] ?? '16:00');
        $duration = intval($_POST['recurring_duration'] ?? 45);

        if ($day < 0 || $day > 6) wp_send_json_error('Pick a day');
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) wp_send_json_error('Invalid time');
        $duration = in_array($duration, [30, 45, 60]) ? $duration : 45;

        // Verify trainer owns this pair
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND trainer_id = %d AND status = 'active'",
            $pair_id, $trainer->id
        ));
        if (!$pair) wp_send_json_error('Mentorship not found');

        $wpdb->update(
            $wpdb->prefix . 'ptp_mentorship_pairs',
            array(
                'recurring_enabled'  => 1,
                'recurring_day'      => $day,
                'recurring_time'     => $time,
                'recurring_duration' => $duration,
            ),
            array('id' => $pair_id)
        );

        $day_names = array('Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday');

        // Create next occurrence if none exists
        $next_date = self::get_next_occurrence($day);
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_sessions s
             JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
             WHERE a.pair_id = %d AND s.status = 'scheduled' AND s.scheduled_at >= NOW()",
            $pair_id
        ));

        $created_session = false;
        if (!$existing) {
            $created_session = self::create_session_for_pair($pair, $trainer, $next_date, $time, $duration);
        }

        wp_send_json_success(array(
            'message' => 'Recurring set: every ' . $day_names[$day] . ' at ' . date('g:i A', strtotime($time)) . ($created_session ? '. First session created for ' . date('M j', strtotime($next_date)) . '.' : '.'),
            'day_name' => $day_names[$day],
            'time'     => date('g:i A', strtotime($time)),
        ));
    }

    // ================================================================
    // PAUSE RECURRING (Trainer)
    // ================================================================
    public static function ajax_pause_recurring() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Not a trainer');

        $pair_id = intval($_POST['pair_id'] ?? 0);
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND trainer_id = %d",
            $pair_id, $trainer->id
        ));
        if (!$pair) wp_send_json_error('Not found');

        $wpdb->update(
            $wpdb->prefix . 'ptp_mentorship_pairs',
            array('recurring_enabled' => 0),
            array('id' => $pair_id)
        );

        wp_send_json_success(array('message' => 'Recurring schedule paused. Sessions won\'t auto-create until you re-enable.'));
    }

    // ================================================================
    // DISMISS ONBOARDING (Parent)
    // ================================================================
    public static function ajax_dismiss_onboarding() {
        check_ajax_referer('ptp_nonce', 'nonce');
        $pair_id = intval($_POST['pair_id'] ?? 0);
        if ($pair_id) {
            update_user_meta(get_current_user_id(), 'ptp_mentor_onboarding_done_' . $pair_id, 1);
        }
        wp_send_json_success();
    }

    // ================================================================
    // CRON: CREATE RECURRING SESSIONS
    // Runs daily. For each active pair with recurring_enabled:
    //   - Check if a session exists for the next occurrence of their day
    //   - If not, create one (up to 2 weeks ahead)
    //   - Skip if pair has 0 sessions remaining
    // ================================================================
    public static function create_recurring_sessions() {
        global $wpdb;

        $pairs = $wpdb->get_results(
            "SELECT p.*, t.display_name as trainer_name, t.user_id as trainer_user_id
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
             WHERE p.recurring_enabled = 1 AND p.status = 'active'
             AND (p.sessions_completed < p.sessions_total)"
        );

        if (empty($pairs)) return;

        foreach ($pairs as $pair) {
            if (is_null($pair->recurring_day)) continue;

            // Get next 2 occurrences of their day
            $dates = array(
                self::get_next_occurrence($pair->recurring_day),
                self::get_next_occurrence($pair->recurring_day, 2),
            );

            foreach ($dates as $date) {
                // Check if session already exists for this date
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT s.id FROM {$wpdb->prefix}ptp_mentorship_sessions s
                     JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
                     WHERE a.pair_id = %d AND DATE(s.scheduled_at) = %s AND s.status = 'scheduled'",
                    $pair->id, $date
                ));

                if ($existing) continue;

                // Check trainer availability exception (blocked date)
                $blocked = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_availability_exceptions
                     WHERE trainer_id = %d AND exception_date = %s AND is_available = 0",
                    $pair->trainer_id, $date
                ));
                if ($blocked) continue;

                // Get trainer object for meeting URL generation
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $pair->trainer_id
                ));

                self::create_session_for_pair(
                    $pair, $trainer, $date,
                    $pair->recurring_time ?: '16:00',
                    $pair->recurring_duration ?: 45
                );

                ptp_log("[PTP Recurring] Created session for pair #{$pair->id} on $date at {$pair->recurring_time}");
            }
        }
    }

    // ================================================================
    // HELPERS
    // ================================================================
    
    /**
     * Get the next date for a given day of week (0=Sun..6=Sat)
     * @param int $target_day  0–6
     * @param int $weeks_ahead Minimum weeks ahead (1 = next, 2 = week after)
     */
    private static function get_next_occurrence($target_day, $weeks_ahead = 1) {
        $today = new DateTime('now', new DateTimeZone('America/New_York'));
        $current_day = (int) $today->format('w'); // 0=Sun

        $diff = $target_day - $current_day;
        if ($diff <= 0) $diff += 7;
        if ($weeks_ahead > 1) $diff += 7 * ($weeks_ahead - 1);

        // Must be at least 1 day from now
        if ($diff < 1) $diff += 7;

        $next = clone $today;
        $next->modify("+{$diff} days");
        return $next->format('Y-m-d');
    }

    /**
     * Create a mentorship session for a pair
     */
    private static function create_session_for_pair($pair, $trainer, $date, $time, $duration) {
        global $wpdb;

        $scheduled_at = $date . ' ' . $time . ':00';

        // Generate meeting URL
        $meeting_url = '';
        $host_url = '';
        if (class_exists('PTP_Mentorship_Sessions') && $trainer) {
            $urls = PTP_Mentorship_Sessions::generate_meeting_url($trainer, 0, 'one_on_one');
            if (is_array($urls)) {
                $meeting_url = $urls['join_url'] ?? $urls['meeting_url'] ?? '';
                $host_url    = $urls['host_url'] ?? '';
            } elseif (is_string($urls)) {
                $meeting_url = $urls;
            }
        }

        $wpdb->insert(
            $wpdb->prefix . 'ptp_mentorship_sessions',
            array(
                'trainer_id'       => $pair->trainer_id,
                'session_type'     => 'one_on_one',
                'title'            => '1:1 Session',
                'scheduled_at'     => $scheduled_at,
                'duration_minutes' => $duration,
                'meeting_url'      => $meeting_url,
                'host_url'         => $host_url,
                'status'           => 'scheduled',
                'created_at'       => current_time('mysql'),
            )
        );
        $session_id = $wpdb->insert_id;
        if (!$session_id) return false;

        // Create attendee
        $wpdb->insert(
            $wpdb->prefix . 'ptp_mentorship_session_attendees',
            array(
                'session_id' => $session_id,
                'pair_id'    => $pair->id,
                'player_id'  => $pair->player_id,
                'attended'   => 0,
            )
        );

        // Update pair's next_session_at
        $wpdb->update(
            $wpdb->prefix . 'ptp_mentorship_pairs',
            array('next_session_at' => $scheduled_at),
            array('id' => $pair->id)
        );

        // Fire notification
        do_action('ptp_mentorship_session_scheduled', $session_id);

        return $session_id;
    }
}
