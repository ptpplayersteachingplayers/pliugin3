<?php
/**
 * PTP Mentorship Content — Videos, goals, challenges, plans, milestones, settings
 * 
 * Handles:
 *  - Video upload (file + URL) and coach review
 *  - Goal setting and completion
 *  - Weekly challenges
 *  - Training plans
 *  - Milestone/badge awards
 *  - Trainer mentorship settings
 *  - Player management
 *  - Mentee list and video queue (trainer dashboard data)
 */

if (!defined('ABSPATH')) exit;

class PTP_Mentorship_Content {

    public static function init() {
        // Parent AJAX
        add_action('wp_ajax_ptp_mentorship_submit_video', array(__CLASS__, 'ajax_submit_video'));
        add_action('wp_ajax_ptp_mentorship_upload_video', array(__CLASS__, 'ajax_upload_video'));
        add_action('wp_ajax_ptp_mentorship_set_goal', array(__CLASS__, 'ajax_set_goal'));
        add_action('wp_ajax_ptp_mentorship_complete_goal', array(__CLASS__, 'ajax_complete_goal'));
        add_action('wp_ajax_ptp_mentorship_get_dashboard', array(__CLASS__, 'ajax_get_parent_dashboard'));
        add_action('wp_ajax_ptp_add_player', array(__CLASS__, 'ajax_add_player'));

        // Trainer AJAX
        add_action('wp_ajax_ptp_mentorship_review_video', array(__CLASS__, 'ajax_review_video'));
        add_action('wp_ajax_ptp_mentorship_create_plan', array(__CLASS__, 'ajax_create_training_plan'));
        add_action('wp_ajax_ptp_mentorship_post_challenge', array(__CLASS__, 'ajax_post_challenge'));
        add_action('wp_ajax_ptp_mentorship_add_milestone', array(__CLASS__, 'ajax_add_milestone'));
        add_action('wp_ajax_ptp_mentorship_get_mentees', array(__CLASS__, 'ajax_get_mentees'));
        add_action('wp_ajax_ptp_mentorship_get_video_queue', array(__CLASS__, 'ajax_get_video_queue'));
        add_action('wp_ajax_ptp_save_mentorship_settings', array(__CLASS__, 'ajax_save_mentorship_settings'));
    }

    // ================================================================
    // VIDEO UPLOAD (file)
    // ================================================================
    public static function ajax_upload_video() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');
        if (empty($_FILES['video'])) wp_send_json_error('No video file');

        $pair_id = intval($_POST['pair_id'] ?? 0);
        $note    = sanitize_textarea_field($_POST['player_note'] ?? '');

        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND parent_id = %d AND status = 'active'",
            $pair_id, get_current_user_id()
        ));
        if (!$pair) wp_send_json_error('Active mentorship not found');

        // v233 H3: Atomic decrement prevents race condition (was check-then-decrement)
        $decremented = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_mentorship_pairs SET video_reviews_remaining = video_reviews_remaining - 1 WHERE id = %d AND video_reviews_remaining > 0",
            $pair_id
        ));
        if (!$decremented) wp_send_json_error('No video reviews remaining. Purchase a Video Review Pack to continue.');

        $file = $_FILES['video'];
        $allowed_video_types = array('mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm');
        $file_check = wp_check_filetype(basename($file['name']), $allowed_video_types);
        if (empty($file_check['ext'])) {
            // Restore credit since we already decremented
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ptp_mentorship_pairs SET video_reviews_remaining = video_reviews_remaining + 1 WHERE id = %d", $pair_id));
            wp_send_json_error('Only MP4, MOV, or WebM');
        }
        if ($file['size'] > 100 * 1024 * 1024) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ptp_mentorship_pairs SET video_reviews_remaining = video_reviews_remaining + 1 WHERE id = %d", $pair_id));
            wp_send_json_error('Max 100MB');
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $upload = wp_handle_upload($file, array('test_form' => false, 'mimes' => $allowed_video_types));
        if (isset($upload['error'])) {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ptp_mentorship_pairs SET video_reviews_remaining = video_reviews_remaining + 1 WHERE id = %d", $pair_id));
            wp_send_json_error($upload['error']);
        }

        $wpdb->insert("{$wpdb->prefix}ptp_mentorship_videos", array(
            'pair_id' => $pair_id, 'trainer_id' => $pair->trainer_id, 'player_id' => $pair->player_id,
            'video_url' => $upload['url'], 'player_note' => $note, 'status' => 'pending',
        ));

        do_action('ptp_mentorship_video_submitted', $wpdb->insert_id);
        wp_send_json_success(array('message' => 'Video submitted! Your coach will review it soon.'));
    }

    // ================================================================
    // VIDEO SUBMIT (URL)
    // ================================================================
    public static function ajax_submit_video() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $pair_id   = intval($_POST['pair_id'] ?? 0);
        $video_url = esc_url_raw($_POST['video_url'] ?? '');
        $note      = sanitize_textarea_field($_POST['player_note'] ?? '');
        if (!$pair_id || !$video_url) wp_send_json_error('Missing video');

        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND parent_id = %d AND status = 'active'",
            $pair_id, get_current_user_id()
        ));
        if (!$pair) wp_send_json_error('Active mentorship not found');

        // v233 H3: Atomic decrement prevents race condition
        $decremented = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_mentorship_pairs SET video_reviews_remaining = video_reviews_remaining - 1 WHERE id = %d AND video_reviews_remaining > 0",
            $pair_id
        ));
        if (!$decremented) wp_send_json_error('No video reviews remaining');

        $wpdb->insert("{$wpdb->prefix}ptp_mentorship_videos", array(
            'pair_id' => $pair_id, 'trainer_id' => $pair->trainer_id, 'player_id' => $pair->player_id,
            'video_url' => $video_url, 'player_note' => $note, 'status' => 'pending',
        ));

        wp_send_json_success(array('message' => 'Video submitted!'));
    }

    // ================================================================
    // VIDEO REVIEW (trainer)
    // ================================================================
    public static function ajax_review_video() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $video_id    = intval($_POST['video_id'] ?? 0);
        $feedback    = sanitize_textarea_field($_POST['feedback'] ?? '');
        $coach_video = esc_url_raw($_POST['coach_video_url'] ?? '');

        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found');

        $video = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_videos WHERE id = %d AND trainer_id = %d", $video_id, $trainer->id
        ));
        if (!$video) wp_send_json_error('Video not found');

        $wpdb->update("{$wpdb->prefix}ptp_mentorship_videos", array(
            'coach_feedback' => $feedback, 'coach_video_url' => $coach_video,
            'status' => 'reviewed', 'reviewed_at' => current_time('mysql'),
        ), array('id' => $video_id));

        do_action('ptp_mentorship_video_reviewed', $video_id);
        wp_send_json_success(array('message' => 'Review sent!'));
    }

    // ================================================================
    // GOALS
    // ================================================================
    public static function ajax_set_goal() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');
        $pair_id = intval($_POST['pair_id'] ?? 0);
        $title   = sanitize_text_field($_POST['title'] ?? '');
        if (!$pair_id || !$title) wp_send_json_error('Goal title required');

        $valid_types = array('game', 'mental', 'identity', 'life');
        $goal_type   = in_array($_POST['goal_type'] ?? '', $valid_types) ? $_POST['goal_type'] : 'game';

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}ptp_mentorship_goals", array(
            'pair_id'     => $pair_id,
            'title'       => $title,
            'goal_type'   => $goal_type,
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'target_date' => sanitize_text_field($_POST['target_date'] ?? '') ?: null,
        ));
        wp_send_json_success(array('goal_id' => $wpdb->insert_id, 'goal_type' => $goal_type, 'message' => 'Goal set!'));
    }

    public static function ajax_complete_goal() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}ptp_mentorship_goals", array(
            'status' => 'completed', 'completed_at' => current_time('mysql'),
        ), array('id' => intval($_POST['goal_id'] ?? 0)));
        wp_send_json_success(array('message' => 'Goal completed!'));
    }

    // ================================================================
    // CHALLENGES
    // ================================================================
    public static function ajax_post_challenge() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');
        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found');

        $title = sanitize_text_field($_POST['title'] ?? '');
        if (!$title) wp_send_json_error('Title required');

        // Archive previous active challenge
        $wpdb->update("{$wpdb->prefix}ptp_mentorship_challenges", array('status' => 'archived'),
            array('trainer_id' => $trainer->id, 'status' => 'active'));

        $wpdb->insert("{$wpdb->prefix}ptp_mentorship_challenges", array(
            'trainer_id' => $trainer->id, 'title' => $title,
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'video_required' => intval($_POST['video_required'] ?? 0),
            'due_date' => sanitize_text_field($_POST['due_date'] ?? '') ?: null,
        ));
        wp_send_json_success(array('message' => 'Challenge posted!'));
    }

    // ================================================================
    // TRAINING PLANS
    // ================================================================
    public static function ajax_create_training_plan() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}ptp_mentorship_plans", array(
            'pair_id' => intval($_POST['pair_id'] ?? 0),
            'title'   => sanitize_text_field($_POST['title'] ?? 'Training Plan'),
            'content' => wp_kses_post($_POST['content'] ?? ''),
        ));
        wp_send_json_success(array('message' => 'Plan created!'));
    }

    // ================================================================
    // MILESTONES
    // ================================================================
    public static function ajax_add_milestone() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}ptp_mentorship_milestones", array(
            'pair_id'    => intval($_POST['pair_id'] ?? 0),
            'title'      => sanitize_text_field($_POST['title'] ?? ''),
            'badge_type' => sanitize_text_field($_POST['badge_type'] ?? 'custom'),
        ));
        wp_send_json_success(array('message' => 'Milestone awarded!'));
    }

    // ================================================================
    // TRAINER MENTORSHIP SETTINGS
    // ================================================================
    public static function ajax_save_mentorship_settings() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found');

        $enabled = intval($_POST['mentorship_enabled'] ?? 0);
        $bio     = sanitize_textarea_field($_POST['mentorship_bio'] ?? '');
        $max_m   = max(1, min(100, intval($_POST['mentorship_max_mentees'] ?? 20)));
        $zoom    = sanitize_email($_POST['zoom_email'] ?? '');

        // Sanitize packages
        $raw_pkgs   = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['mentorship_packages'] ?? ''))));
        $valid_pkgs = array_intersect($raw_pkgs, array('single', 'kickstart', 'development', 'elite'));
        $packages   = implode(',', $valid_pkgs) ?: 'single,kickstart,development,elite';

        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'mentorship_enabled'     => $enabled,
                'mentorship_bio'         => $bio,
                'mentorship_max_mentees' => $max_m,
                'mentorship_packages'    => $packages,
                'zoom_email'             => $zoom,
            ),
            array('id' => $trainer->id)
        );

        if ($result === false) {
            wp_send_json_error('Database error — please try again.');
        }

        wp_send_json_success(array(
            'message' => 'Mentorship settings saved.',
            'enabled' => (bool) $enabled,
        ));
    }

    // ================================================================
    // PLAYER, MENTEES, VIDEO QUEUE, DASHBOARD
    // ================================================================
    public static function ajax_add_player() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');
        $name = sanitize_text_field($_POST['player_name'] ?? '');
        if (!$name) wp_send_json_error('Name required');

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}ptp_players", array(
            'parent_id' => get_current_user_id(), 'first_name' => $name,
            'age' => intval($_POST['player_age'] ?? 0) ?: null,
        ));
        wp_send_json_success(array('player_id' => $wpdb->insert_id, 'name' => $name));
    }

    public static function ajax_get_mentees() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');
        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found');

        $mentees = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, pl.first_name as player_name_db, pl.age as player_age_db, pl.position,
                    u.display_name as parent_name_db, u.user_email as parent_email_db
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON p.player_id = pl.id
             LEFT JOIN {$wpdb->users} u ON p.parent_id = u.ID
             WHERE p.trainer_id = %d AND p.status IN ('active','interest','intro_scheduled','intro_done')
             ORDER BY FIELD(p.status,'interest','intro_scheduled','intro_done','active'), p.created_at ASC",
            $trainer->id
        ));
        wp_send_json_success(array('mentees' => $mentees));
    }

    public static function ajax_get_video_queue() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');
        global $wpdb;
        $trainer = PTP_Mentorship::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found');

        $videos = $wpdb->get_results($wpdb->prepare(
            "SELECT v.*, pl.first_name as player_name, mp.package_type
             FROM {$wpdb->prefix}ptp_mentorship_videos v
             JOIN {$wpdb->prefix}ptp_mentorship_pairs mp ON v.pair_id = mp.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON v.player_id = pl.id
             WHERE v.trainer_id = %d AND v.status = 'pending'
             ORDER BY v.created_at ASC LIMIT 20",
            $trainer->id
        ));
        wp_send_json_success(array('videos' => $videos));
    }

    public static function ajax_get_parent_dashboard() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');
        wp_send_json_success(array('message' => 'Use the dashboard page'));
    }
}
