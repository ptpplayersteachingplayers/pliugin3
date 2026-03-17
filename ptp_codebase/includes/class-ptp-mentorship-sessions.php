<?php
/**
 * PTP Mentorship Sessions — Scheduling, completion, meeting URLs, reminders
 * 
 * Handles:
 *  - Session scheduling (1:1, group, film breakdown, intro)
 *  - Session completion with structured post-session data
 *  - Zoom / Jitsi meeting URL generation
 *  - Pre-session reminder cron
 *  - Intro call completion (Step 2)
 */

if (!defined('ABSPATH')) exit;

class PTP_Mentorship_Sessions {

    public static function init() {
        // Trainer AJAX
        add_action('wp_ajax_ptp_mentorship_schedule_intro', array(__CLASS__, 'ajax_schedule_intro'));
        add_action('wp_ajax_ptp_mentorship_complete_intro', array(__CLASS__, 'ajax_complete_intro_call'));
        add_action('wp_ajax_ptp_mentorship_schedule_session', array(__CLASS__, 'ajax_schedule_session'));
        add_action('wp_ajax_ptp_mentorship_mark_session_complete', array(__CLASS__, 'ajax_mark_session_complete'));
        
        // v228: Parent requests next session
        add_action('wp_ajax_ptp_mentorship_request_session', array(__CLASS__, 'ajax_request_session'));

        // v233 M6: Lazy-load mentorship tab data (parent + trainer dashboards)
        add_action('wp_ajax_ptp_mentorship_tab_data', array(__CLASS__, 'ajax_mentorship_tab_data'));

        // Cron
        add_action('ptp_mentorship_pre_session_reminder', array(__CLASS__, 'send_pre_session_reminder'), 10, 3);
    }

    // ================================================================
    // v233 M6: LAZY-LOAD TAB DATA
    // Returns goals, videos, upcoming sessions for a mentorship pair
    // Called via AJAX when parent/trainer switches to mentorship tab
    // ================================================================
    public static function ajax_mentorship_tab_data() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $pair_id = intval($_POST['pair_id'] ?? 0);
        if (!$pair_id) wp_send_json_error('Missing pair ID');

        global $wpdb;
        $user_id = get_current_user_id();

        // Verify access — parent or trainer
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, t.user_id as trainer_user_id
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
             WHERE p.id = %d AND (p.parent_id = %d OR t.user_id = %d)",
            $pair_id, $user_id, $user_id
        ));
        if (!$pair) wp_send_json_error('Access denied');

        $goals = $wpdb->get_results($wpdb->prepare(
            "SELECT id, goal_type, title, status, progress, created_at
             FROM {$wpdb->prefix}ptp_mentorship_goals
             WHERE pair_id = %d AND status = 'active'
             ORDER BY created_at DESC LIMIT 10",
            $pair_id
        ));

        $videos = $wpdb->get_results($wpdb->prepare(
            "SELECT id, video_url, player_note, trainer_feedback, status, created_at, reviewed_at
             FROM {$wpdb->prefix}ptp_mentorship_videos
             WHERE pair_id = %d
             ORDER BY created_at DESC LIMIT 8",
            $pair_id
        ));

        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.session_type, s.scheduled_at, s.duration_minutes, s.meeting_url, s.status
             FROM {$wpdb->prefix}ptp_mentorship_sessions s
             JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
             WHERE a.pair_id = %d AND s.status = 'scheduled' AND s.scheduled_at >= NOW()
             ORDER BY s.scheduled_at ASC LIMIT 3",
            $pair_id
        ));

        // Recent completed sessions (for recaps)
        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.session_type, s.completed_at, s.trainer_notes, s.parent_summary,
                    s.action_item, s.session_number, s.energy_rating
             FROM {$wpdb->prefix}ptp_mentorship_sessions s
             JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
             WHERE a.pair_id = %d AND s.status = 'completed'
             ORDER BY s.completed_at DESC LIMIT 5",
            $pair_id
        ));

        wp_send_json_success(array(
            'goals'    => $goals,
            'videos'   => $videos,
            'sessions' => $sessions,
            'recent'   => $recent,
        ));
    }

    // ================================================================
    // SESSION REQUEST (Parent → Trainer notification)
    // v228: Parent clicks "Request Next Session" from dashboard.
    // ================================================================
    public static function ajax_request_session() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $pair_id = intval($_POST['pair_id'] ?? 0);

        global $wpdb;
        $parent_id = get_current_user_id();
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT mp.*, t.display_name as trainer_name, t.user_id as trainer_user_id, 
                    u.user_email as trainer_email, t.phone as trainer_phone
             FROM {$wpdb->prefix}ptp_mentorship_pairs mp
             JOIN {$wpdb->prefix}ptp_trainers t ON mp.trainer_id = t.id
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
             WHERE mp.id = %d AND mp.parent_id = %d AND mp.status = 'active'",
            $pair_id, $parent_id
        ));

        if (!$pair) wp_send_json_error('Active mentorship not found');

        $remaining = max(0, $pair->sessions_total - $pair->sessions_completed);
        if ($remaining <= 0) wp_send_json_error('Package sessions are complete. Renew to continue.');

        // Rate limit: one request per hour
        $lock = 'ptp_mentor_req_' . $pair_id;
        if (get_transient($lock)) {
            wp_send_json_error(explode(' ', $pair->trainer_name)[0] . ' has been notified. They\'ll reach out soon.');
        }
        set_transient($lock, time(), HOUR_IN_SECONDS);

        $parent_user = wp_get_current_user();
        $parent_name = $parent_user->display_name ?: ($pair->parent_name ?: 'A parent');
        $player_name = $pair->player_name ?: 'their player';
        $first = explode(' ', $pair->trainer_name)[0];

        // Email trainer
        if (!empty($pair->trainer_email)) {
            $body = "Hi {$first},\n\n{$parent_name} is ready for {$player_name}'s next mentorship session.\n\nSessions remaining: {$remaining} of {$pair->sessions_total}\nPackage: " . ucfirst($pair->package_type) . "\n\nSchedule: " . home_url('/trainer-dashboard/?tab=mentorship') . "\n\n— PTP";
            wp_mail($pair->trainer_email, "{$player_name}'s parent wants to schedule next session", $body, array(
                'From: ' . (function_exists('ptp_email_brand') ? ptp_email_brand('from_training') : 'PTP <hello@ptpsummercamps.com>'),
            ));
        }

        // SMS trainer
        if (!empty($pair->trainer_phone) && class_exists('PTP_SMS') && method_exists('PTP_SMS', 'send')) {
            PTP_SMS::send($pair->trainer_phone, "{$parent_name} requested {$player_name}'s next session ({$remaining} left). Schedule from your dashboard.");
        }

        wp_send_json_success(array('message' => "{$first} has been notified. They'll reach out to schedule."));
    }

    // ================================================================
    // SCHEDULE INTRO (Step 1b — Trainer marks they've scheduled the intro)
    // ================================================================
    public static function ajax_schedule_intro() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $pair_id = intval($_POST['pair_id'] ?? 0);
        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found');

        // v235.6: Ensure mentorship tables exist
        $pairs_table = $wpdb->prefix . 'ptp_mentorship_pairs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$pairs_table}'") !== $pairs_table) {
            if (class_exists('PTP_Mentorship_Database')) PTP_Mentorship_Database::create_tables();
            if ($wpdb->get_var("SHOW TABLES LIKE '{$pairs_table}'") !== $pairs_table) {
                wp_send_json_error('Database tables not ready. Please contact support.');
            }
        }

        // v235.6: Accept interest OR intro_scheduled status (trainer may click twice)
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND trainer_id = %d AND status IN ('interest','intro_scheduled')",
            $pair_id, $trainer->id
        ));
        if (!$pair) wp_send_json_error('Request not found or already processed');

        $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs",
            array('status' => 'intro_scheduled'),
            array('id' => $pair_id)
        );

        wp_send_json_success(array('message' => 'Marked as intro scheduled. Reach out to the parent to set up the call.'));
    }

    // ================================================================
    // INTRO CALL COMPLETE (Step 2 — Trainer marks it done)
    // ================================================================
    public static function ajax_complete_intro_call() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $pair_id = intval($_POST['pair_id'] ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $recommended_package = sanitize_text_field($_POST['recommended_package'] ?? '');

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found');

        // v235.6: Ensure tables exist
        $pairs_table = $wpdb->prefix . 'ptp_mentorship_pairs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$pairs_table}'") !== $pairs_table) {
            if (class_exists('PTP_Mentorship_Database')) PTP_Mentorship_Database::create_tables();
        }

        // v235.6: Accept interest, intro_scheduled, or intro_done (idempotent)
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND trainer_id = %d AND status IN ('interest','intro_scheduled','intro_done')",
            $pair_id, $trainer->id
        ));
        if (!$pair) wp_send_json_error('Not found');

        $update = array(
            'status'           => 'intro_done',
            'intro_call_at'    => current_time('mysql'),
            'intro_call_notes' => $notes,
        );

        if ($recommended_package && isset(PTP_Mentorship::PACKAGES[$recommended_package])) {
            $pkg = PTP_Mentorship::PACKAGES[$recommended_package];
            $update['package_type'] = $recommended_package;
            $update['sessions_total'] = $pkg['sessions'];
            $update['session_length_minutes'] = $pkg['session_length'];
            $update['per_session_price'] = $pkg['per_session'];
        }

        $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", $update, array('id' => $pair_id));

        do_action('ptp_mentorship_intro_completed', $pair_id, array(
            'parent_email' => $pair->parent_email,
            'parent_name'  => $pair->parent_name,
            'player_name'  => $pair->player_name,
            'trainer_name' => $trainer->display_name,
            'package'      => $recommended_package ?: $pair->package_type,
        ));

        wp_send_json_success(array('message' => 'Intro marked complete. Parent will receive their purchase link.'));
    }

    // ================================================================
    // SESSION SCHEDULING
    // ================================================================
    public static function ajax_schedule_session() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $session_type = sanitize_text_field($_POST['session_type'] ?? 'one_on_one');
        $title        = sanitize_text_field($_POST['title'] ?? '');
        $scheduled_at = sanitize_text_field($_POST['scheduled_at'] ?? '');
        $duration     = intval($_POST['duration_minutes'] ?? 30);
        $meeting_url  = esc_url_raw($_POST['meeting_url'] ?? '');
        $pair_ids     = array_map('intval', (array)($_POST['pair_ids'] ?? array()));

        if (!$scheduled_at) wp_send_json_error('Schedule time required');
        
        // v235.6: Safety guardrails
        $duration = max(15, min(90, $duration)); // Cap at 90 minutes
        
        // v235.6: Prevent scheduling in the past
        if (strtotime($scheduled_at) < time() - 300) {
            wp_send_json_error('Cannot schedule a session in the past.');
        }

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found');
        
        // v235.6: Session frequency limit — max 3 sessions per mentee per week
        if (!empty($pair_ids) && $session_type !== 'group') {
            $sched_week_start = date('Y-m-d 00:00:00', strtotime('monday this week', strtotime($scheduled_at)));
            $sched_week_end   = date('Y-m-d 23:59:59', strtotime('sunday this week', strtotime($scheduled_at)));
            foreach ($pair_ids as $check_pid) {
                $week_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_sessions s
                     JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
                     WHERE a.pair_id = %d AND s.status = 'scheduled' 
                     AND s.scheduled_at BETWEEN %s AND %s",
                    $check_pid, $sched_week_start, $sched_week_end
                ));
                if ($week_count >= 3) {
                    $mentee_name = $wpdb->get_var($wpdb->prepare(
                        "SELECT player_name FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d", $check_pid
                    ));
                    wp_send_json_error(($mentee_name ?: 'This mentee') . ' already has 3 sessions scheduled this week. Maximum is 3 per week.');
                }
            }
        }

        $valid_types = array('one_on_one', 'group', 'film_breakdown', 'intro');
        $wpdb->insert("{$wpdb->prefix}ptp_mentorship_sessions", array(
            'trainer_id'       => $trainer->id,
            'session_type'     => in_array($session_type, $valid_types) ? $session_type : 'one_on_one',
            'title'            => $title ?: ($session_type === 'group' ? 'Group Session' : '1:1 Session'),
            'scheduled_at'     => $scheduled_at,
            'duration_minutes' => $duration,
            'meeting_url'      => '',
            'max_participants' => $session_type === 'group' ? 10 : 1,
            'status'           => 'scheduled',
        ));
        $session_id = $wpdb->insert_id;

        if (empty($meeting_url)) {
            $meeting_url = self::generate_meeting_url($trainer, $session_id, $session_type);
        }
        $wpdb->update("{$wpdb->prefix}ptp_mentorship_sessions", array('meeting_url' => $meeting_url), array('id' => $session_id));

        // Add attendees
        if (empty($pair_ids) && $session_type === 'group') {
            $pairs = $wpdb->get_results($wpdb->prepare(
                "SELECT id, player_id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND status = 'active'", $trainer->id
            ));
            foreach ($pairs as $p) {
                $wpdb->insert("{$wpdb->prefix}ptp_mentorship_session_attendees", array(
                    'session_id' => $session_id, 'pair_id' => $p->id, 'player_id' => $p->player_id,
                ));
            }
        } else {
            foreach ($pair_ids as $pid) {
                $p = $wpdb->get_row($wpdb->prepare(
                    "SELECT player_id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND trainer_id = %d", $pid, $trainer->id
                ));
                if ($p) {
                    $wpdb->insert("{$wpdb->prefix}ptp_mentorship_session_attendees", array(
                        'session_id' => $session_id, 'pair_id' => $pid, 'player_id' => $p->player_id,
                    ));
                }
            }
        }

        do_action('ptp_mentorship_session_scheduled', $session_id);
        wp_send_json_success(array('session_id' => $session_id, 'meeting_url' => $meeting_url, 'message' => 'Session scheduled!'));
    }

    // ================================================================
    // SESSION COMPLETION (with structured post-session data)
    // ================================================================
    public static function ajax_mark_session_complete() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $session_id     = intval($_POST['session_id']    ?? 0);
        $notes          = sanitize_textarea_field($_POST['notes']          ?? '');
        $action_item    = sanitize_text_field($_POST['action_item']        ?? '');
        $parent_summary = sanitize_textarea_field($_POST['parent_summary'] ?? '');
        $energy_rating  = intval($_POST['energy_rating'] ?? 0);

        if (empty($parent_summary)) {
            wp_send_json_error('Please write a brief summary for the parent before completing the session.');
        }
        if (empty($action_item)) {
            wp_send_json_error("Please set this week's action item before completing.");
        }

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found');

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id = %d AND trainer_id = %d", $session_id, $trainer->id
        ));
        if (!$session) wp_send_json_error('Session not found');

        // Calculate session number
        $session_number = 0;
        $first_attendee = $wpdb->get_row($wpdb->prepare(
            "SELECT pair_id FROM {$wpdb->prefix}ptp_mentorship_session_attendees WHERE session_id = %d LIMIT 1", $session_id
        ));
        if ($first_attendee) {
            $pair_for_count = $wpdb->get_row($wpdb->prepare(
                "SELECT sessions_completed FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d", $first_attendee->pair_id
            ));
            $session_number = $pair_for_count ? intval($pair_for_count->sessions_completed) + 1 : 1;
        }

        $wpdb->update("{$wpdb->prefix}ptp_mentorship_sessions", array(
            'status'         => 'completed',
            'completed_at'   => current_time('mysql'),
            'trainer_notes'  => $notes,
            'action_item'    => $action_item,
            'parent_summary' => $parent_summary,
            'session_number' => $session_number,
            'energy_rating'  => max(1, min(5, $energy_rating)) ?: null,
        ), array('id' => $session_id));

        // Deduct from each attendee's package (skip intro calls)
        if ($session->session_type !== 'intro') {
            $attendees = $wpdb->get_results($wpdb->prepare(
                "SELECT sa.pair_id, sa.id as attendee_id FROM {$wpdb->prefix}ptp_mentorship_session_attendees sa WHERE sa.session_id = %d", $session_id
            ));

            // v233 H1: For group sessions, only deduct from confirmed attendees
            $attended_ids = array_map('intval', (array)($_POST['attended_pair_ids'] ?? array()));
            $is_group = ($session->session_type === 'group');

            foreach ($attendees as $a) {
                // For group sessions, skip if not in attended list (when list is provided)
                if ($is_group && !empty($attended_ids) && !in_array($a->pair_id, $attended_ids)) {
                    continue;
                }

                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ptp_mentorship_pairs SET sessions_completed = sessions_completed + 1 WHERE id = %d", $a->pair_id
                ));
                $wpdb->update("{$wpdb->prefix}ptp_mentorship_session_attendees", array('attended' => 1), array('id' => $a->attendee_id));

                // Check if package finished
                $pair = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, sessions_total, sessions_completed, stripe_subscription_id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d", $a->pair_id
                ));
                if ($pair && $pair->sessions_completed >= $pair->sessions_total) {
                    $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array(
                        'status' => 'completed', 'completed_at' => current_time('mysql'),
                    ), array('id' => $a->pair_id));

                    // v233 C2: Cancel Stripe subscription now that all sessions are delivered
                    if (!empty($pair->stripe_subscription_id)) {
                        $secret_key = PTP_Mentorship::get_stripe_key();
                        if ($secret_key) {
                            wp_remote_post("https://api.stripe.com/v1/subscriptions/{$pair->stripe_subscription_id}", array(
                                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                                'body' => array('cancel_at_period_end' => 'true'),
                            ));
                        }
                    }

                    do_action('ptp_mentorship_package_completed', $a->pair_id);
                }
                do_action('ptp_mentorship_session_recap_ready', $session_id, $a->pair_id);
            }
        }

        wp_send_json_success(array('message' => 'Session complete. Recap sent to parent.', 'session_number' => $session_number));
    }

    // ================================================================
    // MEETING URL GENERATION (Zoom → Jitsi fallback)
    // ================================================================
    public static function generate_meeting_url($trainer, $session_id, $type = 'one_on_one') {
        // Try Zoom first
        $token = self::get_zoom_token();
        if ($token && !empty($trainer->zoom_email)) {
            $labels = array(
                'intro'          => 'PTP Intro Call',
                'one_on_one'     => 'PTP 1:1 Session',
                'group'          => 'PTP Group Session',
                'film_breakdown' => 'PTP Film Breakdown',
            );
            $response = wp_remote_post(
                'https://api.zoom.us/v2/users/' . rawurlencode($trainer->zoom_email) . '/meetings',
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ),
                    'body'    => json_encode(array(
                        'topic'    => $labels[$type] ?? 'PTP Session',
                        'type'     => 2,
                        'duration' => 45,
                        'timezone' => 'America/New_York',
                        'settings' => array(
                            'waiting_room'      => true,
                            'join_before_host'  => false,
                            'mute_upon_entry'   => true,
                            'auto_recording'    => 'cloud',
                            'host_video'        => true,
                            'participant_video' => true,
                        ),
                    )),
                    'timeout' => 15,
                )
            );

            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($data['join_url'])) {
                    if ($session_id) {
                        global $wpdb;
                        $wpdb->update(
                            $wpdb->prefix . 'ptp_mentorship_sessions',
                            array(
                                'zoom_meeting_id' => $data['id'],
                                'host_url'        => $data['start_url'],
                                'zoom_password'   => $data['password'] ?? '',
                            ),
                            array('id' => $session_id)
                        );
                    }
                    return $data['join_url'];
                }
            }
        }

        // Fallback: Jitsi
        $slug = $trainer->slug ?? sanitize_title($trainer->display_name ?? 'coach');
        // v233 L2: Use 16-char random token instead of 8-char MD5 (was guessable)
        $hash = substr(hash('sha256', 'ptp-' . $trainer->id . '-' . $session_id . '-' . wp_salt('auth') . '-' . wp_generate_password(8, false)), 0, 16);
        $room = 'PTP-' . $slug . '-' . ($session_id ?: 'intro') . '-' . $hash;
        $config = array(
            'config.subject'              => 'PTP Session',
            'config.startWithAudioMuted'  => 'true',
            'config.prejoinPageEnabled'   => 'true',
            'config.disableDeepLinking'   => 'true',
        );
        return 'https://meet.jit.si/' . $room . '#' . http_build_query($config);
    }

    public static function get_meeting_info($url) {
        if (strpos($url, 'meet.jit.si') !== false) return array('platform' => 'Jitsi Meet', 'icon' => 'video', 'note' => 'No download needed.', 'url' => $url);
        if (strpos($url, 'zoom.us') !== false)      return array('platform' => 'Zoom', 'icon' => 'video', 'note' => 'Opens in Zoom.', 'url' => $url);
        if (strpos($url, 'meet.google') !== false)   return array('platform' => 'Google Meet', 'icon' => 'video', 'note' => 'Opens in browser.', 'url' => $url);
        return array('platform' => 'Video Call', 'icon' => 'video', 'note' => '', 'url' => $url);
    }

    // ================================================================
    // ZOOM TOKEN
    // ================================================================
    private static function get_zoom_token() {
        $settings      = get_option('ptp_settings', array());
        $account_id    = $settings['zoom_account_id']    ?? '';
        $client_id     = $settings['zoom_client_id']     ?? '';
        $client_secret = $settings['zoom_client_secret'] ?? '';

        if (!$account_id || !$client_id || !$client_secret) return '';

        // v233 M3: Per-account token caching (was global)
        $cache_key = 'ptp_zoom_token_' . md5($account_id);
        $cached = get_transient($cache_key);
        if ($cached) return $cached;

        $response = wp_remote_post(
            'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . rawurlencode($account_id),
            array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                ),
                'timeout' => 10,
            )
        );

        if (is_wp_error($response)) return '';
        $data  = json_decode(wp_remote_retrieve_body($response), true);
        $token = $data['access_token'] ?? '';
        if ($token) set_transient($cache_key, $token, 55 * MINUTE_IN_SECONDS);
        return $token;
    }

    // ================================================================
    // PRE-SESSION REMINDER (fires 24hr before)
    // ================================================================
    public static function send_pre_session_reminder($session_id, $pair_id, $parent_email) {
        if (!is_email($parent_email)) return;

        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id=%d", $session_id));
        $pair    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        $trainer = $pair ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $pair->trainer_id)) : null;
        if (!$session || !$pair || !$trainer) return;

        $dt       = new DateTime($session->scheduled_at, new DateTimeZone('America/New_York'));
        $time_str = $dt->format('g:i A') . ' ET today';
        $join_url = $session->meeting_url ?: '';

        $note_url = add_query_arg(array(
            'session_id' => $session_id,
            'pair_id'    => $pair_id,
        ), home_url('/dashboard/#mentorship'));

        PTP_Notifications::mentorship_email(
            $parent_email,
            'Session in 24 Hours — Quick Note for ' . ($trainer->display_name),
            'Your Session is Tomorrow',
            array(
                'Your session with <strong>' . esc_html($trainer->display_name) . '</strong> is at <strong>' . esc_html($time_str) . '</strong>.',
                'One thing that would help: tell ' . esc_html($trainer->display_name) . ' one thing your player has been working on since the last call — or anything they should know going into today\'s session.',
                '<a href="' . esc_url($note_url) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:8px;font-weight:700;text-decoration:none">Submit a Note</a>',
                $join_url ? 'Join link for tomorrow: <a href="' . esc_url($join_url) . '">' . esc_html($join_url) . '</a>' : '',
            )
        );
    }
}
