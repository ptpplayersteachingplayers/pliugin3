<?php
/**
 * PTP Mentorship AJAX Actions v2
 * 
 * Handles all AJAX endpoints for the improved mentorship dashboard:
 * - Session completion (trainer)
 * - Session scheduling (trainer)
 * - Session request (parent)
 * - Goal creation with types
 * - Video upload with progress
 */

if (!defined('ABSPATH')) exit;

class PTP_Mentorship_Ajax {

    public static function init() {
        // Session completion (trainer marks session as done + recap)
        add_action('wp_ajax_ptp_mentorship_complete_session', array(__CLASS__, 'complete_session'));
        
        // Session scheduling (trainer creates a new session)
        add_action('wp_ajax_ptp_mentorship_schedule_session', array(__CLASS__, 'schedule_session'));
        
        // Session request (parent requests a session)
        // v235.3: Remove old handler in Sessions class so ours wins (has preferred date/time/note)
        if (class_exists('PTP_Mentorship_Sessions')) {
            remove_action('wp_ajax_ptp_mentorship_request_session', array('PTP_Mentorship_Sessions', 'ajax_request_session'));
        }
        add_action('wp_ajax_ptp_mentorship_request_session', array(__CLASS__, 'request_session'));
        
        // Goal creation with goal_type
        add_action('wp_ajax_ptp_mentorship_set_goal', array(__CLASS__, 'set_goal'));
        
        // Goal completion
        add_action('wp_ajax_ptp_mentorship_complete_goal', array(__CLASS__, 'complete_goal'));
        
        // Video upload
        add_action('wp_ajax_ptp_mentorship_upload_video', array(__CLASS__, 'upload_video'));

        // Video review (trainer)
        add_action('wp_ajax_ptp_mentorship_review_video', array(__CLASS__, 'review_video'));

        // Cancel mentorship
        add_action('wp_ajax_ptp_mentorship_cancel', array(__CLASS__, 'cancel_mentorship'));

        // Addon purchase
        add_action('wp_ajax_ptp_mentorship_purchase_addon', array(__CLASS__, 'purchase_addon'));

        // Verify payment (3DS)
        add_action('wp_ajax_ptp_mentorship_verify_payment', array(__CLASS__, 'verify_payment'));

        // v235.2: Mentee detail (trainer)
        add_action('wp_ajax_ptp_mentorship_mentee_detail', array(__CLASS__, 'mentee_detail'));
        
        // v235.2: Private notes (trainer)
        add_action('wp_ajax_ptp_mentorship_save_notes', array(__CLASS__, 'save_notes'));

        // v235.2: Pair messaging
        add_action('wp_ajax_ptp_mentorship_get_messages', array(__CLASS__, 'get_messages'));
        add_action('wp_ajax_ptp_mentorship_send_message', array(__CLASS__, 'send_message'));
        add_action('wp_ajax_ptp_mentorship_mark_read', array(__CLASS__, 'mark_read'));
    }

    // ================================================================
    // COMPLETE SESSION (Trainer)
    // ================================================================
    public static function complete_session() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Not a trainer');

        $session_id     = intval($_POST['session_id'] ?? 0);
        $pair_id        = intval($_POST['pair_id'] ?? 0);
        $trainer_notes  = sanitize_textarea_field($_POST['trainer_notes'] ?? '');
        $parent_summary = sanitize_textarea_field($_POST['parent_summary'] ?? '');
        $action_item    = sanitize_text_field($_POST['action_item'] ?? '');
        $energy_rating  = intval($_POST['energy_rating'] ?? 0);

        if (!$session_id) wp_send_json_error('Invalid session');

        // Verify ownership
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id = %d AND trainer_id = %d",
            $session_id, $trainer->id
        ));
        if (!$session) wp_send_json_error('Session not found');

        // Get pair for session counting
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d", $pair_id
        ));

        // Calculate session number
        $session_number = $pair ? intval($pair->sessions_completed) + 1 : 1;

        // Update session
        $wpdb->update(
            $wpdb->prefix . 'ptp_mentorship_sessions',
            array(
                'status'          => 'completed',
                'completed_at'    => current_time('mysql'),
                'trainer_notes'   => $trainer_notes,
                'parent_summary'  => $parent_summary,
                'action_item'     => $action_item,
                'energy_rating'   => $energy_rating > 0 ? min(5, $energy_rating) : null,
                'session_number'  => $session_number,
            ),
            array('id' => $session_id),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d'),
            array('%d')
        );

        // Update pair: increment sessions_completed
        if ($pair) {
            $new_completed = $pair->sessions_completed + 1;
            $update_data = array(
                'sessions_completed' => $new_completed,
                'updated_at'         => current_time('mysql'),
            );

            // If all sessions done, mark as completed
            if ($new_completed >= $pair->sessions_total) {
                $update_data['status']       = 'completed';
                $update_data['completed_at'] = current_time('mysql');
            }

            $wpdb->update(
                $wpdb->prefix . 'ptp_mentorship_pairs',
                $update_data,
                array('id' => $pair->id)
            );

            // Fire notification hook
            if (!empty($parent_summary)) {
                do_action('ptp_mentorship_session_recap_ready', $session_id, $pair->id);
            }

            // If package just completed, cancel Stripe subscription + notify
            if ($new_completed >= $pair->sessions_total) {
                // CRITICAL: Cancel the Stripe subscription so parent stops getting charged
                if (!empty($pair->stripe_subscription_id)) {
                    self::cancel_stripe_subscription($pair->stripe_subscription_id, $pair->id);
                }
                do_action('ptp_mentorship_package_completed', $pair->id);
            }
        }

        // Update attendee record
        $wpdb->update(
            $wpdb->prefix . 'ptp_mentorship_session_attendees',
            array('attended' => 1),
            array('session_id' => $session_id)
        );

        wp_send_json_success(array(
            'message'        => 'Session completed!',
            'session_number' => $session_number,
        ));
    }

    // ================================================================
    // SCHEDULE SESSION (Trainer)
    // ================================================================
    public static function schedule_session() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Not a trainer');

        $pair_id     = intval($_POST['pair_id'] ?? 0);
        $date        = sanitize_text_field($_POST['date'] ?? '');
        $time        = sanitize_text_field($_POST['time'] ?? '');
        $duration    = intval($_POST['duration'] ?? 45);
        $type        = sanitize_text_field($_POST['session_type'] ?? 'one_on_one');
        $note        = sanitize_textarea_field($_POST['pre_session_note'] ?? '');

        if (!$pair_id || !$date || !$time) wp_send_json_error('Missing required fields');

        // Verify this pair belongs to this trainer
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND trainer_id = %d AND status = 'active'",
            $pair_id, $trainer->id
        ));
        if (!$pair) wp_send_json_error('Mentorship not found or not active');

        // Parse datetime
        $scheduled_at = $date . ' ' . $time . ':00';

        // Generate meeting URL
        $meeting_url = '';
        $host_url = '';
        if (class_exists('PTP_Mentorship_Sessions')) {
            $urls = PTP_Mentorship_Sessions::generate_meeting_url($trainer, 0, $type);
            if (is_array($urls)) {
                $meeting_url = $urls['join_url'] ?? $urls['meeting_url'] ?? '';
                $host_url    = $urls['host_url'] ?? '';
            } elseif (is_string($urls)) {
                $meeting_url = $urls;
            }
        }

        // Create session
        $wpdb->insert(
            $wpdb->prefix . 'ptp_mentorship_sessions',
            array(
                'trainer_id'       => $trainer->id,
                'session_type'     => $type,
                'title'            => ucfirst(str_replace('_', ' ', $type)),
                'scheduled_at'     => $scheduled_at,
                'duration_minutes' => $duration,
                'meeting_url'      => $meeting_url,
                'host_url'         => $host_url,
                'status'           => 'scheduled',
                'pre_session_note' => $note,
                'created_at'       => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        $session_id = $wpdb->insert_id;

        if (!$session_id) wp_send_json_error('Failed to create session');

        // Create attendee record
        $wpdb->insert(
            $wpdb->prefix . 'ptp_mentorship_session_attendees',
            array(
                'session_id' => $session_id,
                'pair_id'    => $pair_id,
                'player_id'  => $pair->player_id,
                'attended'   => 0,
            ),
            array('%d', '%d', '%d', '%d')
        );

        // Update pair's next_session_at
        $wpdb->update(
            $wpdb->prefix . 'ptp_mentorship_pairs',
            array('next_session_at' => $scheduled_at),
            array('id' => $pair_id)
        );

        // Fire notification
        do_action('ptp_mentorship_session_scheduled', $session_id);

        wp_send_json_success(array(
            'message'    => 'Session scheduled!',
            'session_id' => $session_id,
            'meeting_url'=> $meeting_url,
        ));
    }

    // ================================================================
    // REQUEST SESSION (Parent)
    // ================================================================
    public static function request_session() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $user_id = get_current_user_id();

        $pair_id = intval($_POST['pair_id'] ?? 0);
        $date    = sanitize_text_field($_POST['preferred_date'] ?? '');
        $time    = intval($_POST['preferred_time'] ?? 2);
        $note    = sanitize_textarea_field($_POST['note'] ?? '');

        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, t.display_name as trainer_name, t.user_id as trainer_user_id,
                    t.phone as trainer_phone, u.user_email as trainer_email
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
             WHERE p.id = %d AND p.parent_id = %d AND p.status = 'active'",
            $pair_id, $user_id
        ));
        if (!$pair) wp_send_json_error('Mentorship not found');

        // Check remaining sessions
        $remaining = max(0, $pair->sessions_total - $pair->sessions_completed);
        if ($remaining <= 0) wp_send_json_error('Package sessions are complete. Renew to continue.');

        // Rate limit: one request per hour
        $lock = 'ptp_mentor_req_' . $pair_id;
        if (get_transient($lock)) {
            wp_send_json_error(explode(' ', $pair->trainer_name)[0] . ' has been notified. They\'ll reach out soon.');
        }
        set_transient($lock, time(), HOUR_IN_SECONDS);

        $time_labels = array(0 => 'Morning (9-12)', 1 => 'Afternoon (12-4)', 2 => 'Evening (4-8)');
        $time_label = $time_labels[$time] ?? 'Flexible';
        $parent_name = wp_get_current_user()->display_name;
        $player_name = $pair->player_name ?: 'their player';
        $trainer_first = explode(' ', $pair->trainer_name)[0];

        // Email trainer (branded if possible)
        if (!empty($pair->trainer_email)) {
            $subject = "{$player_name}'s parent wants to schedule next session";
            $body = "Hi {$trainer_first},\n\n"
                . "{$parent_name} is ready for {$player_name}'s next mentorship session.\n\n"
                . "Sessions remaining: {$remaining} of {$pair->sessions_total}\n"
                . "Package: " . ucfirst($pair->package_type) . "\n";
            if ($date) $body .= "Preferred date: " . date('l, M j', strtotime($date)) . "\n";
            if ($time_label !== 'Flexible') $body .= "Preferred time: {$time_label}\n";
            if ($note) $body .= "\nNote from parent: {$note}\n";
            $body .= "\nSchedule from your dashboard: " . home_url('/trainer-dashboard/?tab=mentorship') . "\n\n— PTP";

            $headers = array(
                'From: ' . (function_exists('ptp_email_brand') ? ptp_email_brand('from_training') : 'PTP <hello@ptpsummercamps.com>'),
            );

            // Try branded HTML email via PTP_Email_Templates
            if (class_exists('PTP_Email_Templates') && method_exists('PTP_Email_Templates', 'send')) {
                $html_body = nl2br(esc_html($body));
                PTP_Email_Templates::send($pair->trainer_email, $subject, $html_body);
            } else {
                wp_mail($pair->trainer_email, $subject, $body, $headers);
            }
        }

        // SMS to trainer
        if (!empty($pair->trainer_phone) && class_exists('PTP_SMS') && method_exists('PTP_SMS', 'send')) {
            $sms = "{$parent_name} requested {$player_name}'s next session ({$remaining} left).";
            if ($date) $sms .= " Preferred: " . date('M j', strtotime($date)) . " {$time_label}.";
            PTP_SMS::send($pair->trainer_phone, $sms);
        }

        // In-app notification for trainer
        if ($pair->trainer_user_id && class_exists('PTP_Notifications')) {
            PTP_Notifications::create(
                $pair->trainer_user_id,
                'session_request',
                'Session Request',
                "{$parent_name} wants to schedule {$player_name}'s next session"
                    . ($date ? " (preferred: " . date('M j', strtotime($date)) . ")" : ''),
                array('pair_id' => $pair_id)
            );
        }

        wp_send_json_success(array('message' => "{$trainer_first} has been notified. They'll reach out to schedule."));
    }

    // ================================================================
    // SET GOAL (with goal_type support)
    // ================================================================
    public static function set_goal() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $pair_id     = intval($_POST['pair_id'] ?? 0);
        $title       = sanitize_text_field($_POST['title'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $goal_type   = sanitize_text_field($_POST['goal_type'] ?? 'game');
        $target_date = sanitize_text_field($_POST['target_date'] ?? '');

        if (!$pair_id || !$title) wp_send_json_error('Goal title required');

        // Verify the current user owns this pair (either as parent or trainer)
        $user_id = get_current_user_id();
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND (parent_id = %d OR trainer_id IN (SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d))",
            $pair_id, $user_id, $user_id
        ));
        if (!$pair) wp_send_json_error('Unauthorized');

        $valid_types = array('game', 'mental', 'identity', 'life');
        if (!in_array($goal_type, $valid_types)) $goal_type = 'game';

        $data = array(
            'pair_id'     => $pair_id,
            'title'       => $title,
            'description' => $description,
            'goal_type'   => $goal_type,
            'status'      => 'active',
            'created_at'  => current_time('mysql'),
        );
        $formats = array('%d', '%s', '%s', '%s', '%s', '%s');

        if ($target_date) {
            $data['target_date'] = $target_date;
            $formats[] = '%s';
        }

        $wpdb->insert($wpdb->prefix . 'ptp_mentorship_goals', $data, $formats);

        if (!$wpdb->insert_id) wp_send_json_error('Failed to save goal');

        wp_send_json_success(array('message' => 'Goal set!', 'goal_id' => $wpdb->insert_id));
    }

    // ================================================================
    // COMPLETE GOAL
    // ================================================================
    public static function complete_goal() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $goal_id = intval($_POST['goal_id'] ?? 0);
        if (!$goal_id) wp_send_json_error('Invalid goal');

        // Get goal and verify ownership
        $goal = $wpdb->get_row($wpdb->prepare(
            "SELECT g.*, p.parent_id, p.trainer_id
             FROM {$wpdb->prefix}ptp_mentorship_goals g
             JOIN {$wpdb->prefix}ptp_mentorship_pairs p ON g.pair_id = p.id
             WHERE g.id = %d",
            $goal_id
        ));
        if (!$goal) wp_send_json_error('Goal not found');

        $user_id = get_current_user_id();
        $is_parent = ($goal->parent_id == $user_id);
        $is_trainer = false;
        if (!$is_parent) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d AND id = %d",
                $user_id, $goal->trainer_id
            ));
            $is_trainer = !empty($trainer);
        }
        if (!$is_parent && !$is_trainer) wp_send_json_error('Unauthorized');

        $wpdb->update(
            $wpdb->prefix . 'ptp_mentorship_goals',
            array('status' => 'completed', 'completed_at' => current_time('mysql')),
            array('id' => $goal_id)
        );

        // Award milestone if they've completed 5+ goals
        $completed_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_goals WHERE pair_id = %d AND status = 'completed'",
            $goal->pair_id
        )));
        $milestones = array(5 => 'Goal Crusher', 10 => 'Locked In', 20 => 'Elite Mindset');
        if (isset($milestones[$completed_count])) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_mentorship_milestones WHERE pair_id = %d AND title = %s",
                $goal->pair_id, $milestones[$completed_count]
            ));
            if (!$exists) {
                $wpdb->insert($wpdb->prefix . 'ptp_mentorship_milestones', array(
                    'pair_id'    => $goal->pair_id,
                    'title'      => $milestones[$completed_count],
                    'badge_type' => 'goal_milestone',
                    'awarded_at' => current_time('mysql'),
                ));
            }
        }

        wp_send_json_success(array('message' => 'Goal completed!', 'total_completed' => $completed_count));
    }

    // ================================================================
    // UPLOAD VIDEO
    // ================================================================
    public static function upload_video() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $pair_id     = intval($_POST['pair_id'] ?? 0);
        $player_note = sanitize_textarea_field($_POST['player_note'] ?? '');

        if (!$pair_id) wp_send_json_error('Invalid pair');

        // Verify ownership
        $user_id = get_current_user_id();
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND parent_id = %d AND status = 'active'",
            $pair_id, $user_id
        ));
        if (!$pair) wp_send_json_error('Mentorship not found');

        if (intval($pair->video_reviews_remaining) <= 0) {
            wp_send_json_error('No video reviews remaining. Purchase a Video Review Pack to continue.');
        }

        if (empty($_FILES['video'])) wp_send_json_error('No video file provided');

        // Validate file type server-side
        $allowed_video_types = array('mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'avi' => 'video/x-msvideo');
        $file_info = wp_check_filetype(basename($_FILES['video']['name']), $allowed_video_types);
        if (empty($file_info['ext'])) {
            wp_send_json_error('Invalid file type. Please upload MP4, MOV, WebM, or AVI.');
        }

        // 200MB max for video
        if ($_FILES['video']['size'] > 200 * 1024 * 1024) {
            wp_send_json_error('File too large. Maximum size is 200MB.');
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $uploaded = wp_handle_upload($_FILES['video'], array(
            'test_form' => false,
            'mimes' => $allowed_video_types,
        ));
        if (isset($uploaded['error'])) wp_send_json_error($uploaded['error']);

        $video_url = $uploaded['url'];

        $wpdb->insert(
            $wpdb->prefix . 'ptp_mentorship_videos',
            array(
                'pair_id'     => $pair_id,
                'trainer_id'  => $pair->trainer_id,
                'player_id'   => $pair->player_id,
                'video_url'   => $video_url,
                'player_note' => $player_note,
                'status'      => 'pending',
                'created_at'  => current_time('mysql'),
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s')
        );

        // Decrement video reviews
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_mentorship_pairs SET video_reviews_remaining = GREATEST(0, video_reviews_remaining - 1) WHERE id = %d",
            $pair_id
        ));

        // Fire notification
        do_action('ptp_mentorship_video_submitted', $wpdb->insert_id);

        wp_send_json_success(array('message' => 'Video submitted for review!'));
    }

    // ================================================================
    // REVIEW VIDEO (Trainer)
    // ================================================================
    public static function review_video() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Not a trainer');

        $video_id       = intval($_POST['video_id'] ?? 0);
        $feedback        = sanitize_textarea_field($_POST['feedback'] ?? '');
        $coach_video_url = esc_url_raw($_POST['coach_video_url'] ?? '');
        $effort_rating   = intval($_POST['effort_rating'] ?? 0);

        if (!$video_id || !$feedback) wp_send_json_error('Feedback required');

        $video = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_videos WHERE id = %d AND trainer_id = %d",
            $video_id, $trainer->id
        ));
        if (!$video) wp_send_json_error('Video not found');

        $wpdb->update(
            $wpdb->prefix . 'ptp_mentorship_videos',
            array(
                'coach_feedback'  => $feedback,
                'coach_video_url' => $coach_video_url,
                'status'          => 'reviewed',
                'reviewed_at'     => current_time('mysql'),
                'tags'            => $effort_rating > 0 ? 'effort:' . $effort_rating : '',
            ),
            array('id' => $video_id)
        );

        do_action('ptp_mentorship_video_reviewed', $video_id);

        wp_send_json_success(array('message' => 'Review submitted!'));
    }

    // ================================================================
    // CANCEL MENTORSHIP
    // ================================================================
    public static function cancel_mentorship() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $user_id = get_current_user_id();
        $pair_id = intval($_POST['pair_id'] ?? 0);
        $reason  = sanitize_textarea_field($_POST['reason'] ?? '');

        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND parent_id = %d AND status IN ('active','paused')",
            $pair_id, $user_id
        ));
        if (!$pair) wp_send_json_error('Mentorship not found');

        // Cancel Stripe subscription if exists
        if (!empty($pair->stripe_subscription_id)) {
            self::cancel_stripe_subscription($pair->stripe_subscription_id, $pair_id);
        }

        // Refund single-session payment if no sessions completed
        if (!empty($pair->stripe_payment_intent) && empty($pair->stripe_subscription_id)
            && intval($pair->sessions_completed) === 0) {
            $secret_key = PTP_Mentorship::get_stripe_key();
            if ($secret_key) {
                wp_remote_post('https://api.stripe.com/v1/refunds', array(
                    'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                    'body' => array('payment_intent' => $pair->stripe_payment_intent),
                ));
            }
        }

        $wpdb->update(
            $wpdb->prefix . 'ptp_mentorship_pairs',
            array(
                'status'       => 'cancelled',
                'cancelled_at' => current_time('mysql'),
                'cancel_reason'=> $reason,
            ),
            array('id' => $pair_id)
        );

        do_action('ptp_mentorship_cancelled', $pair_id);

        wp_send_json_success(array('message' => 'Mentorship cancelled. We hope to see you again soon.'));
    }

    // ================================================================
    // PURCHASE ADDON
    // ================================================================
    public static function purchase_addon() {
        PTP_Mentorship::verify_nonce();

        if (!class_exists('PTP_Mentorship_Billing')) {
            wp_send_json_error('Billing not configured');
        }

        PTP_Mentorship_Billing::purchase_addon();
    }

    // ================================================================
    // VERIFY PAYMENT (3DS)
    // ================================================================
    public static function verify_payment() {
        PTP_Mentorship::verify_nonce();

        if (!class_exists('PTP_Mentorship_Billing')) {
            wp_send_json_error('Billing not configured');
        }

        PTP_Mentorship_Billing::verify_payment();
    }

    // ================================================================
    // MENTEE DETAIL (Trainer — full data for detail view)
    // ================================================================
    public static function mentee_detail() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Not a trainer');

        $pair_id = intval($_POST['pair_id'] ?? 0);
        if (!$pair_id) wp_send_json_error('Missing pair');

        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND trainer_id = %d",
            $pair_id, $trainer->id
        ));
        if (!$pair) wp_send_json_error('Mentorship not found');

        // Player
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d", $pair->player_id
        ));
        $player_data = array(
            'name'     => $player ? trim($player->first_name . ' ' . $player->last_name) : ($pair->player_name ?: 'Player'),
            'age'      => $player ? intval($player->age) : null,
            'position' => $player ? ($player->position ?: '') : ($pair->player_position ?: ''),
        );

        // Parent
        $parent_user = $pair->parent_id ? get_userdata($pair->parent_id) : null;
        $parent_row = $pair->parent_id ? $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d", $pair->parent_id
        )) : null;
        $parent_data = array(
            'name'  => $parent_row ? ($parent_row->display_name ?: ($parent_user ? $parent_user->display_name : '')) : ($pair->parent_name ?: ''),
            'email' => $parent_row ? ($parent_row->email ?: ($parent_user ? $parent_user->user_email : '')) : ($pair->parent_email ?: ''),
            'phone' => $parent_row ? ($parent_row->phone ?: '') : '',
        );

        // Sessions (all — completed + scheduled)
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*
             FROM {$wpdb->prefix}ptp_mentorship_sessions s
             JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
             WHERE a.pair_id = %d
             ORDER BY s.scheduled_at DESC LIMIT 50",
            $pair_id
        ));

        // Goals
        $goals = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_goals WHERE pair_id = %d ORDER BY status ASC, created_at DESC LIMIT 20",
            $pair_id
        ));

        // Videos
        $videos = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_videos WHERE pair_id = %d ORDER BY created_at DESC LIMIT 20",
            $pair_id
        ));

        // Pair data (including recurring fields)
        $pair_data = array(
            'id'                  => $pair->id,
            'package_type'        => $pair->package_type,
            'status'              => $pair->status,
            'sessions_total'      => intval($pair->sessions_total),
            'sessions_completed'  => intval($pair->sessions_completed),
            'started_at'          => $pair->started_at,
            'notes'               => $pair->notes ?? '',
            'recurring_enabled'   => intval($pair->recurring_enabled ?? 0),
            'recurring_day'       => $pair->recurring_day ?? null,
            'recurring_time'      => $pair->recurring_time ?? '16:00',
            'recurring_duration'  => intval($pair->recurring_duration ?? 45),
        );

        wp_send_json_success(array(
            'pair'     => $pair_data,
            'player'   => $player_data,
            'parent'   => $parent_data,
            'sessions' => $sessions,
            'goals'    => $goals,
            'videos'   => $videos,
        ));
    }

    // ================================================================
    // SAVE PRIVATE NOTES (Trainer)
    // ================================================================
    public static function save_notes() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Not a trainer');

        $pair_id = intval($_POST['pair_id'] ?? 0);
        $notes   = sanitize_textarea_field($_POST['notes'] ?? '');

        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND trainer_id = %d",
            $pair_id, $trainer->id
        ));
        if (!$pair) wp_send_json_error('Not found');

        $wpdb->update(
            $wpdb->prefix . 'ptp_mentorship_pairs',
            array('notes' => $notes),
            array('id' => $pair_id)
        );

        wp_send_json_success(array('message' => 'Notes saved'));
    }

    // ================================================================
    // GET MESSAGES (Pair Chat)
    // ================================================================
    public static function get_messages() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $user_id = get_current_user_id();
        $pair_id = intval($_POST['pair_id'] ?? 0);
        if (!$pair_id) wp_send_json_error('Missing pair');

        // Verify access
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, t.user_id as trainer_user_id
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
             WHERE p.id = %d AND (p.parent_id = %d OR t.user_id = %d)",
            $pair_id, $user_id, $user_id
        ));
        if (!$pair) wp_send_json_error('Access denied');

        $table = $wpdb->prefix . 'ptp_mentorship_messages';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            wp_send_json_success(array('messages' => array(), 'unread' => 0));
            return;
        }

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.display_name as sender_name
             FROM $table m
             LEFT JOIN {$wpdb->users} u ON m.sender_id = u.ID
             WHERE m.pair_id = %d
             ORDER BY m.created_at ASC LIMIT 100",
            $pair_id
        ));

        // Count unread for this user
        $unread = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE pair_id = %d AND sender_id != %d AND is_read = 0",
            $pair_id, $user_id
        )));

        wp_send_json_success(array(
            'messages' => $messages,
            'unread'   => $unread,
        ));
    }

    // ================================================================
    // SEND MESSAGE (Pair Chat)
    // ================================================================
    public static function send_message() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $user_id = get_current_user_id();
        $pair_id = intval($_POST['pair_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        if (!$pair_id || !$message) wp_send_json_error('Message required');

        // Verify access and determine role
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, t.user_id as trainer_user_id
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
             WHERE p.id = %d AND (p.parent_id = %d OR t.user_id = %d)",
            $pair_id, $user_id, $user_id
        ));
        if (!$pair) wp_send_json_error('Access denied');

        $role = ($user_id == $pair->trainer_user_id) ? 'trainer' : 'parent';

        $table = $wpdb->prefix . 'ptp_mentorship_messages';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            wp_send_json_error('Messaging not yet available. Please refresh the page.');
            return;
        }

        $wpdb->insert($table, array(
            'pair_id'     => $pair_id,
            'sender_id'   => $user_id,
            'sender_role' => $role,
            'message'     => $message,
            'is_read'     => 0,
            'created_at'  => current_time('mysql'),
        ), array('%d', '%d', '%s', '%s', '%d', '%s'));

        if (!$wpdb->insert_id) wp_send_json_error('Failed to send');

        wp_send_json_success(array('message_id' => $wpdb->insert_id));
    }

    // ================================================================
    // MARK MESSAGES READ (Pair Chat)
    // ================================================================
    public static function mark_read() {
        PTP_Mentorship::verify_nonce();

        global $wpdb;
        $user_id = get_current_user_id();
        $pair_id = intval($_POST['pair_id'] ?? 0);
        if (!$pair_id) wp_send_json_error('Missing pair');

        $table = $wpdb->prefix . 'ptp_mentorship_messages';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            wp_send_json_success();
            return;
        }

        // Mark all messages from the OTHER person as read
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET is_read = 1 WHERE pair_id = %d AND sender_id != %d AND is_read = 0",
            $pair_id, $user_id
        ));

        wp_send_json_success();
    }

    // ================================================================
    // CANCEL STRIPE SUBSCRIPTION (shared helper)
    // Used by both manual cancel and auto-cancel on package completion.
    // ================================================================
    private static function cancel_stripe_subscription($subscription_id, $pair_id = 0) {
        $secret_key = PTP_Mentorship::get_stripe_key();
        if (empty($secret_key) || empty($subscription_id)) return false;

        // Cancel immediately (not at period end) since sessions are done
        $response = wp_remote_post("https://api.stripe.com/v1/subscriptions/{$subscription_id}", array(
            'headers' => array('Authorization' => 'Bearer ' . $secret_key),
            'method'  => 'DELETE',
        ));

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $success = !empty($data['id']) && ($data['status'] ?? '') === 'canceled';

        if ($success) {
            ptp_log("[PTP Mentorship] Subscription {$subscription_id} cancelled for pair #{$pair_id}");
        } else {
            // Fallback: try cancel_at_period_end
            wp_remote_post("https://api.stripe.com/v1/subscriptions/{$subscription_id}", array(
                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                'body'    => array('cancel_at_period_end' => 'true'),
            ));
            ptp_log("[PTP Mentorship] Subscription {$subscription_id} set to cancel at period end for pair #{$pair_id}");
        }

        return $success;
    }
}
