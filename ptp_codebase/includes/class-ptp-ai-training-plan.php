<?php
/**
 * PTP AI Pre-Session Plan
 * 
 * Sends parent a short, personalized note 24h before each session.
 * Two AI-generated pieces: what tomorrow covers + coach message to player.
 * 
 * @since v230
 */
if (!defined('ABSPATH')) exit;

class PTP_AI_Training_Plan {

    private static $api_key = null;

    public static function init() {
        add_action('ptp_send_pre_session_plan', array(__CLASS__, 'send_plan_for_booking'), 10, 1);
    }

    private static function get_api_key() {
        if (self::$api_key) return self::$api_key;
        self::$api_key = get_option('ptp_anthropic_api_key', '');
        if (empty(self::$api_key) && defined('PTP_ANTHROPIC_API_KEY'))
            self::$api_key = PTP_ANTHROPIC_API_KEY;
        return self::$api_key;
    }

    public static function is_enabled() {
        return !empty(self::get_api_key()) && get_option('ptp_ai_training_plans', true);
    }

    /**
     * Generate + send pre-session email
     */
    public static function send_plan_for_booking($booking_id) {
        if (!self::is_enabled()) return;
        global $wpdb;

        $b = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*,
                    t.display_name as trainer_name, t.school as trainer_school,
                    t.sport_level as trainer_level, t.photo_url as trainer_photo,
                    COALESCE(pa.display_name,'Parent') as parent_name,
                    pa.user_id as parent_user_id, pa.email as parent_email,
                    COALESCE(pl.name,'Player') as player_name,
                    pl.age as player_age, pl.skill_level as player_skill,
                    pl.position as player_position, pl.goals as player_goals,
                    pl.notes as player_notes, pl.id as real_player_id
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE b.id = %d", $booking_id
        ));
        if (!$b) return;

        $to = $b->parent_email ?: '';
        if (empty($to) && $b->parent_user_id) {
            $u = get_userdata($b->parent_user_id);
            if ($u) $to = $u->user_email;
        }
        if (empty($to)) return;

        // Last 3 session notes
        $prev = $b->real_player_id ? $wpdb->get_results($wpdb->prepare(
            "SELECT focus_worked_on, achievements, areas_to_improve, next_session_focus,
                    player_effort, session_date
             FROM {$wpdb->prefix}ptp_session_notes
             WHERE player_id=%d AND trainer_id=%d AND is_visible_to_parent=1
             ORDER BY session_date DESC LIMIT 3",
            $b->real_player_id, $b->trainer_id
        )) : array();

        $session_count = $b->real_player_id ? (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
             WHERE player_id=%d AND trainer_id=%d AND status='completed'",
            $b->real_player_id, $b->trainer_id
        )) : 0;

        // Build history
        $history = '';
        foreach ($prev as $p) {
            $d = date('M j', strtotime($p->session_date));
            $history .= "{$d}: {$p->focus_worked_on}";
            if ($p->next_session_focus) $history .= " | Next: {$p->next_session_focus}";
            $history .= "\n";
        }

        $last_next = !empty($prev[0]->next_session_focus) ? $prev[0]->next_session_focus : '';

        // Player profile
        $profile = $b->player_name;
        if ($b->player_age) $profile .= ", {$b->player_age}";
        if ($b->player_skill) $profile .= ", " . ucfirst($b->player_skill);
        if ($b->player_position) $profile .= ", {$b->player_position}";

        $ctx = '';
        if ($b->player_goals) $ctx .= "Goals: {$b->player_goals}\n";
        if ($b->player_notes) $ctx .= "Parent notes: {$b->player_notes}\n";
        if ($last_next) $ctx .= "Last session's next focus: {$last_next}\n";
        if ($history) $ctx .= "Recent sessions:\n{$history}";

        $is_first = $session_count === 0;

        $prompt = "You write pre-session notes for PTP Soccer, where NCAA D1 athletes and MLS players train alongside kids ages 6-14.\n\n"
            . "Tomorrow {$b->player_name} has session " . ($session_count + 1) . " with Coach {$b->trainer_name}"
            . ($b->trainer_school ? " ({$b->trainer_school})" : '') . ".\n"
            . "Player: {$profile}\n"
            . ($ctx ? "{$ctx}\n" : '')
            . ($is_first ? "This is the FIRST session. Focus on getting to know the player and having fun.\n" : '')
            . "\nWrite a short note for the parent ({$b->parent_name}). Be warm, specific, mention the player by name."
            . " If there's a 'next focus' from last session, build on it.\n\n"
            . "Respond ONLY with JSON, no markdown:\n"
            . '{"preview":"2-3 sentences. What tomorrow covers and why.","message":"1 sentence from the coach to the player. Something the parent can show them tonight."}';

        // Call Claude
        $resp = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => self::get_api_key(),
                'anthropic-version' => '2023-06-01',
            ),
            'body' => wp_json_encode(array(
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 300,
                'messages' => array(array('role' => 'user', 'content' => $prompt)),
            )),
        ));

        if (is_wp_error($resp)) { ptp_log('[PTP AI Plan] ' . $resp->get_error_message()); return; }
        if (wp_remote_retrieve_response_code($resp) !== 200) { ptp_log('[PTP AI Plan] API ' . wp_remote_retrieve_response_code($resp)); return; }

        $text = json_decode(wp_remote_retrieve_body($resp), true)['content'][0]['text'] ?? '';
        $plan = json_decode($text, true);
        if (!$plan) { if (preg_match('/\{.*\}/s', $text, $m)) $plan = json_decode($m[0], true); }
        if (!$plan || empty($plan['preview'])) { ptp_log('[PTP AI Plan] Parse fail'); return; }

        $preview = sanitize_textarea_field($plan['preview']);
        $message = sanitize_textarea_field($plan['message'] ?? '');

        // Render email
        $session_date = date('l, F j', strtotime($b->session_date));
        $session_time = date('g:i A', strtotime($b->start_time));

        ob_start();
        ?><!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><style>body{margin:0;padding:0;background:#0A0A0A;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}@media(max-width:600px){.mp{padding-left:16px!important;padding-right:16px!important}}</style></head>
<body style="margin:0;padding:0;background:#0A0A0A">
<div style="display:none">Tomorrow's session plan for <?php echo esc_html($b->player_name); ?></div>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#0A0A0A">
<tr><td align="center" style="padding:40px 20px" class="mp">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width:480px">

  <!-- Header -->
  <tr><td style="background:#1A1A1A;border-bottom:2px solid #FCB900;border-radius:12px 12px 0 0;padding:24px 28px;text-align:center" class="mp">
    <div style="font-family:Oswald,sans-serif;font-size:11px;font-weight:700;color:#FCB900;text-transform:uppercase;letter-spacing:2px;margin-bottom:4px">Training Plan</div>
    <div style="font-family:Oswald,sans-serif;font-size:22px;font-weight:700;color:#fff;text-transform:uppercase"><?php echo esc_html($b->player_name); ?></div>
    <div style="font-size:13px;color:#9CA3AF;margin-top:10px">
      Coach <span style="color:#FCB900;font-weight:600"><?php echo esc_html($b->trainer_name); ?></span>
      &nbsp;&middot;&nbsp; <?php echo esc_html($session_date); ?>
      &nbsp;&middot;&nbsp; <?php echo esc_html($session_time); ?>
    </div>
    <?php if ($b->location): ?>
    <div style="font-size:12px;color:#6B7280;margin-top:4px"><?php echo esc_html($b->location); ?></div>
    <?php endif; ?>
  </td></tr>

  <!-- Body -->
  <tr><td style="background:#ffffff;border-radius:0 0 12px 12px;padding:28px" class="mp">

    <div style="font-size:14px;color:#1A1A1A;line-height:1.6;margin-bottom:20px"><?php echo nl2br(esc_html($preview)); ?></div>

    <?php if ($message): ?>
    <div style="background:#0A0A0A;border-radius:10px;padding:18px 20px;text-align:center">
      <div style="font-family:Oswald,sans-serif;font-size:10px;font-weight:700;color:#FCB900;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">From Coach <?php echo esc_html($b->trainer_name); ?></div>
      <div style="font-size:15px;color:#fff;font-style:italic;line-height:1.5">&ldquo;<?php echo esc_html($message); ?>&rdquo;</div>
    </div>
    <?php endif; ?>

  </td></tr>

  <!-- Footer -->
  <tr><td style="padding:20px;text-align:center">
    <div style="font-size:11px;color:#555">PTP Soccer &middot; <a href="<?php echo esc_url(home_url()); ?>" style="color:#FCB900;text-decoration:none">ptpsoccertraining.com</a></div>
  </td></tr>

</table>
</td></tr></table>
</body></html><?php
        $body = ob_get_clean();

        wp_mail($to,
            "Tomorrow's Plan: {$b->player_name} with {$b->trainer_name}",
            $body,
            array('Content-Type: text/html; charset=UTF-8')
        );

        ptp_log("[PTP AI Plan] Sent for booking #{$booking_id}");
    }
}

add_action('plugins_loaded', array('PTP_AI_Training_Plan', 'init'), 20);
