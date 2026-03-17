<?php
/**
 * PTP AI Assistant v224
 * 
 * Provides AI-powered draft suggestions for:
 * - Training session recaps (What We Worked On, Wins, Keep Working On, Homework)
 * - Mentorship end-session summaries (Parent Summary, Action Item)
 * - Mentorship session prep briefs (personalized pre-call context)
 * - Video review feedback suggestions
 * 
 * Uses OpenAI GPT-4o-mini for cost-effective, fast generation.
 * API key stored in wp_options as 'ptp_openai_api_key'.
 */

defined('ABSPATH') || exit;

class PTP_AI_Assistant {

    const OPTION_KEY = 'ptp_openai_api_key';
    const MODEL      = 'gpt-4o-mini';
    const MAX_TOKENS = 800;
    const TEMP       = 0.7;

    public static function init() {
        // AJAX endpoints (trainer-only)
        add_action('wp_ajax_ptp_ai_draft_recap',       array(__CLASS__, 'ajax_draft_recap'));
        add_action('wp_ajax_ptp_ai_draft_mentorship',  array(__CLASS__, 'ajax_draft_mentorship'));
        add_action('wp_ajax_ptp_ai_session_prep',      array(__CLASS__, 'ajax_session_prep'));
        add_action('wp_ajax_ptp_ai_video_feedback',    array(__CLASS__, 'ajax_video_feedback'));

        // Admin settings
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    // ──────────────────────────────────────────────
    //  Settings
    // ──────────────────────────────────────────────

    public static function register_settings() {
        register_setting('ptp_settings', self::OPTION_KEY, array(
            'sanitize_callback' => 'sanitize_text_field',
        ));

        // Add to PTP admin page if it exists
        add_action('admin_menu', array(__CLASS__, 'add_settings_page'), 99);
    }

    public static function add_settings_page() {
        add_submenu_page(
            'ptp-admin',
            'AI Assistant',
            'AI Assistant',
            'manage_options',
            'ptp-ai-settings',
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function render_settings_page() {
        if (isset($_POST['ptp_openai_key_save']) && check_admin_referer('ptp_ai_settings')) {
            update_option(self::OPTION_KEY, sanitize_text_field($_POST['ptp_openai_api_key'] ?? ''));
            echo '<div class="notice notice-success"><p>API key saved.</p></div>';
        }
        $key = self::get_api_key();
        $masked = $key ? substr($key, 0, 7) . str_repeat('•', 20) . substr($key, -4) : '';
        ?>
        <div class="wrap">
            <h1>PTP AI Assistant Settings</h1>
            <p>The AI assistant helps trainers draft session recaps, mentorship summaries, and video feedback using player context and history.</p>
            <form method="post">
                <?php wp_nonce_field('ptp_ai_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th>OpenAI API Key</th>
                        <td>
                            <input type="password" name="ptp_openai_api_key" value="<?php echo esc_attr($key); ?>"
                                   class="regular-text" placeholder="sk-..." autocomplete="off">
                            <?php if ($key): ?>
                                <p class="description">Current: <?php echo esc_html($masked); ?></p>
                            <?php endif; ?>
                            <p class="description">Uses GPT-4o-mini (~$0.15/1M input tokens). Get a key at <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td>
                            <?php if (self::is_enabled()): ?>
                                <span style="color:green;font-weight:bold">✓ Active</span> — AI buttons visible to trainers
                            <?php else: ?>
                                <span style="color:#999">○ Not configured</span> — AI buttons hidden from trainers
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="ptp_openai_key_save" class="button button-primary">Save Settings</button>
                </p>
            </form>

            <h2>What It Does</h2>
            <table class="widefat striped" style="max-width:700px">
                <thead><tr><th>Feature</th><th>Where</th><th>What It Generates</th></tr></thead>
                <tbody>
                    <tr><td><strong>Recap Drafts</strong></td><td>Training → Send Recap sheet</td><td>Focus, Wins, Improve, Homework fields based on player history</td></tr>
                    <tr><td><strong>Mentorship Summaries</strong></td><td>End Session form</td><td>Parent summary + action item using goals & past sessions</td></tr>
                    <tr><td><strong>Session Prep</strong></td><td>Live session card</td><td>Personalized pre-call brief with opener, focus, and action item idea</td></tr>
                    <tr><td><strong>Video Feedback</strong></td><td>Video Review sheet</td><td>Draft feedback connecting to player's goals</td></tr>
                </tbody>
            </table>
            <p style="margin-top:12px;color:#666">All drafts are suggestions — trainers review and edit before sending. The AI never sends anything directly to parents.</p>
        </div>
        <?php
    }

    private static function get_api_key() {
        return get_option(self::OPTION_KEY, '');
    }

    private static function is_enabled() {
        return !empty(self::get_api_key());
    }

    // ──────────────────────────────────────────────
    //  AJAX: Draft Training Recap
    // ──────────────────────────────────────────────

    public static function ajax_draft_recap() {
        check_ajax_referer('ptp_nonce', 'nonce');
        if (!self::is_enabled()) wp_send_json_error('AI assistant not configured.');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) wp_send_json_error('Missing booking ID.');

        global $wpdb;
        $trainer = self::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found.');

        // Get booking + player data
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, pl.name as player_name, pl.age as player_age, pl.position as player_position,
                    pl.goals as player_goals, pl.skill_level as player_skill,
                    pa.display_name as parent_name
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             WHERE b.id = %d AND b.trainer_id = %d",
            $booking_id, $trainer->id
        ));
        if (!$booking) wp_send_json_error('Booking not found.');

        // Get last 3 session notes for this player
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT sn.focus_worked_on, sn.achievements, sn.areas_to_improve, sn.homework,
                    sn.player_effort, sn.session_date, sn.skill_ratings, sn.private_notes
             FROM {$wpdb->prefix}ptp_session_notes sn
             WHERE sn.player_id = %d AND sn.trainer_id = %d
             ORDER BY sn.session_date DESC LIMIT 3",
            $booking->player_id, $trainer->id
        ));

        // Get any partial text the trainer already typed
        $partial = array(
            'focus'   => sanitize_text_field($_POST['partial_focus'] ?? ''),
            'wins'    => sanitize_text_field($_POST['partial_wins'] ?? ''),
            'improve' => sanitize_text_field($_POST['partial_improve'] ?? ''),
            'homework'=> sanitize_text_field($_POST['partial_homework'] ?? ''),
        );

        // Build context
        $context = self::build_training_context($booking, $history, $trainer, $partial);
        $prompt  = self::build_training_prompt($context);

        $result = self::call_openai($prompt);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

        wp_send_json_success($result);
    }

    // ──────────────────────────────────────────────
    //  AJAX: Draft Mentorship End-Session
    // ──────────────────────────────────────────────

    public static function ajax_draft_mentorship() {
        check_ajax_referer('ptp_nonce', 'nonce');
        if (!self::is_enabled()) wp_send_json_error('AI assistant not configured.');

        $session_id = intval($_POST['session_id'] ?? 0);
        if (!$session_id) wp_send_json_error('Missing session ID.');

        global $wpdb;
        $trainer = self::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found.');

        // Get session
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id = %d AND trainer_id = %d",
            $session_id, $trainer->id
        ));
        if (!$session) wp_send_json_error('Session not found.');

        // Get pair + player
        $attendee = $wpdb->get_row($wpdb->prepare(
            "SELECT pair_id FROM {$wpdb->prefix}ptp_mentorship_session_attendees WHERE session_id = %d LIMIT 1",
            $session_id
        ));
        $pair = $attendee ? $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, pl.name as player_name, pl.first_name as player_first, pl.age as player_age,
                    pl.position as player_position, pl.goals as player_goals,
                    u.display_name as parent_name
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON p.player_id = pl.id
             LEFT JOIN {$wpdb->users} u ON p.parent_id = u.ID
             WHERE p.id = %d", $attendee->pair_id
        )) : null;

        // Goals
        $goals = $pair ? $wpdb->get_results($wpdb->prepare(
            "SELECT title, goal_type, status FROM {$wpdb->prefix}ptp_mentorship_goals
             WHERE pair_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT 5",
            $pair->id
        )) : array();

        // Last 3 completed sessions
        $prev_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT parent_summary, action_item, trainer_notes, energy_rating, scheduled_at, session_type
             FROM {$wpdb->prefix}ptp_mentorship_sessions
             WHERE trainer_id = %d AND status = 'completed' AND id != %d
             ORDER BY scheduled_at DESC LIMIT 3",
            $trainer->id, $session_id
        ));

        // Partial inputs
        $partial = array(
            'action_item'    => sanitize_text_field($_POST['partial_action'] ?? ''),
            'parent_summary' => sanitize_textarea_field($_POST['partial_summary'] ?? ''),
            'private_notes'  => sanitize_textarea_field($_POST['partial_notes'] ?? ''),
        );

        $context = self::build_mentorship_context($session, $pair, $goals, $prev_sessions, $trainer, $partial);
        $prompt  = self::build_mentorship_prompt($context);

        $result = self::call_openai($prompt);
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

        wp_send_json_success($result);
    }

    // ──────────────────────────────────────────────
    //  AJAX: Session Prep Brief (pre-call)
    // ──────────────────────────────────────────────

    public static function ajax_session_prep() {
        check_ajax_referer('ptp_nonce', 'nonce');
        if (!self::is_enabled()) wp_send_json_error('AI assistant not configured.');

        $session_id = intval($_POST['session_id'] ?? 0);
        if (!$session_id) wp_send_json_error('Missing session ID.');

        global $wpdb;
        $trainer = self::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found.');

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id = %d AND trainer_id = %d",
            $session_id, $trainer->id
        ));
        if (!$session) wp_send_json_error('Session not found.');

        $attendee = $wpdb->get_row($wpdb->prepare(
            "SELECT pair_id FROM {$wpdb->prefix}ptp_mentorship_session_attendees WHERE session_id = %d LIMIT 1",
            $session_id
        ));
        $pair = $attendee ? $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, pl.name as player_name, pl.first_name as player_first, pl.age as player_age,
                    pl.position as player_position, pl.goals as player_goals
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON p.player_id = pl.id
             WHERE p.id = %d", $attendee->pair_id
        )) : null;

        // Active goals
        $goals = $pair ? $wpdb->get_results($wpdb->prepare(
            "SELECT title, goal_type FROM {$wpdb->prefix}ptp_mentorship_goals
             WHERE pair_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT 5",
            $pair->id
        )) : array();

        // Last session recap
        $last = $wpdb->get_row($wpdb->prepare(
            "SELECT parent_summary, action_item, trainer_notes, energy_rating, scheduled_at
             FROM {$wpdb->prefix}ptp_mentorship_sessions
             WHERE trainer_id = %d AND status = 'completed'
             ORDER BY scheduled_at DESC LIMIT 1",
            $trainer->id
        ));

        // Recent videos
        $videos = $pair ? $wpdb->get_results($wpdb->prepare(
            "SELECT parent_note, status, created_at FROM {$wpdb->prefix}ptp_mentorship_videos
             WHERE pair_id = %d ORDER BY created_at DESC LIMIT 3",
            $pair->id
        )) : array();

        // Pre-session note from parent
        $pre_note = $session->pre_session_note ?? '';

        // Session number + phase
        $session_num = $pair ? (intval($pair->sessions_completed) + 1) : 1;
        $total       = $pair ? intval($pair->sessions_total) : 0;
        $pct         = $total > 0 ? ($pair->sessions_completed / $total) : 0;
        $phase       = $pct < 0.25 ? 'opening' : ($pct < 0.80 ? 'building' : 'closing');

        $player_name = $pair ? ($pair->player_name ?: $pair->player_first ?: 'the player') : 'the player';

        $system = "You are a PTP mentorship assistant helping a D1/MLS soccer mentor prepare for their upcoming session with a youth player. Be specific, actionable, and warm. Write in second person ('you'). Keep it to 150 words max.";

        $user_msg = "Prepare a quick session prep brief for my call with {$player_name}.\n\n";
        $user_msg .= "Session type: " . ucfirst(str_replace('_', ' ', $session->session_type)) . "\n";
        $user_msg .= "Phase: {$phase} (session {$session_num}" . ($total ? " of {$total}" : "") . ")\n";
        if ($pair && $pair->player_age) $user_msg .= "Age: {$pair->player_age}\n";
        if ($pair && $pair->player_position) $user_msg .= "Position: {$pair->player_position}\n";

        if (!empty($goals)) {
            $user_msg .= "\nActive goals:\n";
            foreach ($goals as $g) $user_msg .= "- [{$g->goal_type}] {$g->title}\n";
        }

        if ($last) {
            $user_msg .= "\nLast session:\n";
            $user_msg .= "- Action item given: " . ($last->action_item ?: '(none)') . "\n";
            if ($last->trainer_notes) $user_msg .= "- My private notes: " . substr($last->trainer_notes, 0, 200) . "\n";
            $user_msg .= "- Energy: " . ($last->energy_rating ?: '?') . "/5\n";
        }

        if ($pre_note) $user_msg .= "\nParent's note for today: {$pre_note}\n";

        if (!empty($videos)) {
            $pending = array_filter($videos, function($v) { return $v->status === 'pending'; });
            if ($pending) {
                $user_msg .= "\nUnreviewed videos: " . count($pending) . "\n";
                $first = reset($pending);
                if ($first->parent_note) $user_msg .= "Latest video note: {$first->parent_note}\n";
            }
        }

        $user_msg .= "\nGive me: (1) One specific thing to open with, (2) The main thing to focus on today, (3) One action item idea for this week. Format with clear labels.";

        $messages = array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user_msg),
        );

        $response = self::call_openai_raw($messages, 400);
        if (is_wp_error($response)) wp_send_json_error($response->get_error_message());

        wp_send_json_success(array('prep' => $response));
    }

    // ──────────────────────────────────────────────
    //  AJAX: Video Review Feedback
    // ──────────────────────────────────────────────

    public static function ajax_video_feedback() {
        check_ajax_referer('ptp_nonce', 'nonce');
        if (!self::is_enabled()) wp_send_json_error('AI assistant not configured.');

        $video_id = intval($_POST['video_id'] ?? 0);
        if (!$video_id) wp_send_json_error('Missing video ID.');

        global $wpdb;
        $trainer = self::get_current_trainer();
        if (!$trainer) wp_send_json_error('Trainer not found.');

        $video = $wpdb->get_row($wpdb->prepare(
            "SELECT v.*, pl.name as player_name, pl.first_name as player_first,
                    pl.age as player_age, pl.position as player_position,
                    mp.tier, mp.package_type
             FROM {$wpdb->prefix}ptp_mentorship_videos v
             LEFT JOIN {$wpdb->prefix}ptp_mentorship_pairs mp ON v.pair_id = mp.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON v.player_id = pl.id
             WHERE v.id = %d AND v.trainer_id = %d",
            $video_id, $trainer->id
        ));
        if (!$video) wp_send_json_error('Video not found.');

        // Goals for this pair
        $goals = $wpdb->get_results($wpdb->prepare(
            "SELECT title, goal_type FROM {$wpdb->prefix}ptp_mentorship_goals
             WHERE pair_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT 5",
            $video->pair_id
        ));

        $player_name = $video->player_name ?: $video->player_first ?: 'the player';

        $system = "You are a PTP soccer mentorship assistant helping a D1/MLS player write video review feedback for a youth mentee. Be encouraging but specific. Use the kid's name. Write as if talking directly to them. Keep total response under 120 words.";

        $user_msg = "Help me draft feedback for {$player_name}'s video submission.\n\n";
        if ($video->player_age) $user_msg .= "Age: {$video->player_age}\n";
        if ($video->player_position) $user_msg .= "Position: {$video->player_position}\n";
        if ($video->parent_note) $user_msg .= "Parent/player note with video: {$video->parent_note}\n";

        if (!empty($goals)) {
            $user_msg .= "\nActive goals:\n";
            foreach ($goals as $g) $user_msg .= "- [{$g->goal_type}] {$g->title}\n";
        }

        $partial = sanitize_textarea_field($_POST['partial_feedback'] ?? '');
        if ($partial) $user_msg .= "\nI've started writing: {$partial}\n";

        $user_msg .= "\nDraft feedback that: (1) starts with something positive, (2) gives one specific thing to work on, (3) connects to their goals. Write it as if I'm speaking to the kid directly.";

        $messages = array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user_msg),
        );

        $response = self::call_openai_raw($messages, 300);
        if (is_wp_error($response)) wp_send_json_error($response->get_error_message());

        wp_send_json_success(array('feedback' => $response));
    }

    // ──────────────────────────────────────────────
    //  Context Builders
    // ──────────────────────────────────────────────

    private static function build_training_context($booking, $history, $trainer, $partial) {
        $ctx = array();
        $ctx['player_name']     = $booking->player_name ?: 'Player';
        $ctx['player_age']      = $booking->player_age ?? null;
        $ctx['player_position'] = $booking->player_position ?? null;
        $ctx['player_skill']    = $booking->player_skill ?? null;
        $ctx['player_goals']    = $booking->player_goals ?? null;
        $ctx['session_date']    = $booking->session_date ?? date('Y-m-d');
        $ctx['trainer_name']    = $trainer->display_name ?? 'Coach';
        $ctx['partial']         = $partial;

        $ctx['history'] = array();
        if ($history) {
            foreach ($history as $h) {
                $ctx['history'][] = array(
                    'date'       => $h->session_date,
                    'focus'      => $h->focus_worked_on,
                    'wins'       => $h->achievements,
                    'improve'    => $h->areas_to_improve,
                    'homework'   => $h->homework,
                    'effort'     => $h->player_effort,
                    'skills'     => $h->skill_ratings ? json_decode($h->skill_ratings, true) : null,
                );
            }
        }

        return $ctx;
    }

    private static function build_training_prompt($ctx) {
        $system = "You are a PTP training session recap assistant helping a D1/MLS soccer player write session recaps for parents. Your tone is warm, professional, encouraging, and specific. Use the kid's first name. Write as if the trainer is speaking. Never be generic — reference specific skills and drills. Keep each field to 1-3 sentences.\n\nIMPORTANT: Return ONLY valid JSON with these exact keys: focus, wins, improve, homework. No markdown, no backticks, no explanation.";

        $user_msg = "Draft a session recap for {$ctx['player_name']}.\n\n";
        $user_msg .= "Session date: {$ctx['session_date']}\n";
        if ($ctx['player_age'])      $user_msg .= "Age: {$ctx['player_age']}\n";
        if ($ctx['player_position']) $user_msg .= "Position: {$ctx['player_position']}\n";
        if ($ctx['player_skill'])    $user_msg .= "Skill level: {$ctx['player_skill']}\n";
        if ($ctx['player_goals'])    $user_msg .= "Parent-stated goals: {$ctx['player_goals']}\n";

        if (!empty($ctx['history'])) {
            $user_msg .= "\nPrevious session history (most recent first):\n";
            foreach ($ctx['history'] as $i => $h) {
                $user_msg .= "Session " . ($i + 1) . " ({$h['date']}):\n";
                if ($h['focus'])   $user_msg .= "  Focus: {$h['focus']}\n";
                if ($h['wins'])    $user_msg .= "  Wins: {$h['wins']}\n";
                if ($h['improve']) $user_msg .= "  Improve: {$h['improve']}\n";
                if ($h['homework'])$user_msg .= "  Homework: {$h['homework']}\n";
                if ($h['effort'])  $user_msg .= "  Effort: {$h['effort']}/5\n";
            }
        }

        // Include partial text so AI continues from where trainer started
        $has_partial = false;
        foreach ($ctx['partial'] as $v) { if ($v) { $has_partial = true; break; } }
        if ($has_partial) {
            $user_msg .= "\nI've already started writing some fields. Build on what I have:\n";
            if ($ctx['partial']['focus'])    $user_msg .= "  Focus (my start): {$ctx['partial']['focus']}\n";
            if ($ctx['partial']['wins'])     $user_msg .= "  Wins (my start): {$ctx['partial']['wins']}\n";
            if ($ctx['partial']['improve'])  $user_msg .= "  Improve (my start): {$ctx['partial']['improve']}\n";
            if ($ctx['partial']['homework']) $user_msg .= "  Homework (my start): {$ctx['partial']['homework']}\n";
        }

        $user_msg .= "\nReturn JSON: {\"focus\": \"...\", \"wins\": \"...\", \"improve\": \"...\", \"homework\": \"...\"}";

        return array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user_msg),
        );
    }

    private static function build_mentorship_context($session, $pair, $goals, $prev_sessions, $trainer, $partial) {
        $ctx = array();
        $ctx['session_type']  = $session->session_type ?? 'one_on_one';
        $ctx['player_name']   = $pair ? ($pair->player_name ?: $pair->player_first ?: 'Player') : 'Player';
        $ctx['player_age']    = $pair ? ($pair->player_age ?? null) : null;
        $ctx['player_position']= $pair ? ($pair->player_position ?? null) : null;
        $ctx['package_type']  = $pair ? ($pair->package_type ?? null) : null;
        $ctx['parent_name']   = $pair ? ($pair->parent_name ?? null) : null;
        $ctx['session_num']   = $pair ? (intval($pair->sessions_completed) + 1) : 1;
        $ctx['sessions_total']= $pair ? intval($pair->sessions_total) : 0;
        $ctx['trainer_name']  = $trainer->display_name ?? 'Coach';
        $ctx['partial']       = $partial;

        $ctx['goals'] = array();
        foreach ($goals as $g) {
            $ctx['goals'][] = "[{$g->goal_type}] {$g->title}";
        }

        $ctx['prev_sessions'] = array();
        foreach ($prev_sessions as $ps) {
            $ctx['prev_sessions'][] = array(
                'date'           => $ps->scheduled_at,
                'type'           => $ps->session_type,
                'action_item'    => $ps->action_item,
                'parent_summary' => $ps->parent_summary,
                'notes'          => $ps->trainer_notes,
                'energy'         => $ps->energy_rating,
            );
        }

        return $ctx;
    }

    private static function build_mentorship_prompt($ctx) {
        $system = "You are a PTP mentorship assistant helping a D1/MLS soccer mentor write end-of-session communications. The parent summary should be warm, specific, and make the parent feel their investment is worth it. The action item must be concrete and measurable. Write as if the mentor is speaking.\n\nIMPORTANT: Return ONLY valid JSON with these exact keys: parent_summary, action_item. No markdown, no backticks, no explanation.";

        $user_msg = "Draft my end-of-session summary for {$ctx['player_name']}.\n\n";
        $user_msg .= "Session type: " . ucfirst(str_replace('_', ' ', $ctx['session_type'])) . "\n";
        $user_msg .= "Session " . $ctx['session_num'] . ($ctx['sessions_total'] ? " of " . $ctx['sessions_total'] : "") . "\n";
        if ($ctx['player_age'])      $user_msg .= "Age: {$ctx['player_age']}\n";
        if ($ctx['player_position']) $user_msg .= "Position: {$ctx['player_position']}\n";
        if ($ctx['parent_name'])     $user_msg .= "Parent: {$ctx['parent_name']}\n";

        if (!empty($ctx['goals'])) {
            $user_msg .= "\nActive goals:\n";
            foreach ($ctx['goals'] as $g) $user_msg .= "- {$g}\n";
        }

        if (!empty($ctx['prev_sessions'])) {
            $user_msg .= "\nRecent session history:\n";
            foreach ($ctx['prev_sessions'] as $i => $ps) {
                $d = date('M j', strtotime($ps['date']));
                $user_msg .= "  {$d}: action item was \"{$ps['action_item']}\"\n";
                if ($ps['notes']) $user_msg .= "    Private notes: " . substr($ps['notes'], 0, 150) . "\n";
                $user_msg .= "    Energy: " . ($ps['energy'] ?: '?') . "/5\n";
            }
        }

        // Include partial text
        $has_partial = false;
        foreach ($ctx['partial'] as $v) { if ($v) { $has_partial = true; break; } }
        if ($has_partial) {
            $user_msg .= "\nI've started writing some fields — expand on what I have:\n";
            if ($ctx['partial']['action_item'])    $user_msg .= "  Action item (my start): {$ctx['partial']['action_item']}\n";
            if ($ctx['partial']['parent_summary']) $user_msg .= "  Parent summary (my start): {$ctx['partial']['parent_summary']}\n";
            if ($ctx['partial']['private_notes'])  $user_msg .= "  Private notes (my start): {$ctx['partial']['private_notes']}\n";
        }

        $user_msg .= "\nParent summary: 2-3 sentences written TO the parent (e.g. 'Great session today. Jake's first touch...'). Make them feel their kid is progressing.\n";
        $user_msg .= "Action item: One specific, measurable thing for the kid to do this week (e.g. 'Film 10 Cruyff turns at speed and send by Thursday').\n";
        $user_msg .= "\nReturn JSON: {\"parent_summary\": \"...\", \"action_item\": \"...\"}";

        return array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user_msg),
        );
    }

    // ──────────────────────────────────────────────
    //  OpenAI API
    // ──────────────────────────────────────────────

    /**
     * Structured JSON call — parses response as JSON
     */
    private static function call_openai($messages) {
        $raw = self::call_openai_raw($messages, self::MAX_TOKENS);
        if (is_wp_error($raw)) return $raw;

        // Strip markdown fences if present
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($raw));
        $parsed = json_decode($clean, true);

        if (!$parsed || !is_array($parsed)) {
            ptp_log('PTP AI: Failed to parse JSON response: ' . substr($raw, 0, 500));
            return new WP_Error('parse_error', 'AI response was not valid JSON. Please try again.');
        }

        return $parsed;
    }

    /**
     * Raw text call to OpenAI
     */
    private static function call_openai_raw($messages, $max_tokens = 400) {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return new WP_Error('no_key', 'OpenAI API key not configured.');
        }

        $body = array(
            'model'       => self::MODEL,
            'messages'    => $messages,
            'max_tokens'  => $max_tokens,
            'temperature' => self::TEMP,
        );

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            ptp_log('PTP AI: HTTP error: ' . $response->get_error_message());
            return new WP_Error('http_error', 'Could not reach AI service.');
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $err_msg = $data['error']['message'] ?? 'Unknown API error (HTTP ' . $code . ')';
            ptp_log('PTP AI: API error: ' . $err_msg);
            return new WP_Error('api_error', 'AI service error. Please try again.');
        }

        $content = $data['choices'][0]['message']['content'] ?? '';
        if (empty($content)) {
            return new WP_Error('empty', 'AI returned empty response.');
        }

        return $content;
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    private static function get_current_trainer() {
        global $wpdb;
        $user_id = get_current_user_id();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", $user_id
        ));
    }

    /**
     * Check if AI is available (for frontend conditional rendering)
     */
    public static function is_available() {
        return self::is_enabled();
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_AI_Assistant', 'init'), 26);
