<?php
/**
 * PTP Camp-to-Mentorship Conversion Touchpoints v227
 * 
 * Wires up every touchpoint in the camp→mentorship funnel:
 * 1. Post-camp 3-touch sequence (Day 2 email, Day 5 SMS, Day 10 email)
 * 2. Mid-camp Day 3 SMS
 * 3. Free session → mentorship follow-up
 * 4. Training session (2nd+) → mentorship suggestion
 * 5. Parent dashboard mentorship card
 */
defined('ABSPATH') || exit;

class PTP_Mentorship_Touchpoints {

    public static function init() {
        // ── Post-camp sequence (fires from PTP_Mentorship::trigger_post_camp_sequence) ──
        add_action('ptp_mentorship_post_camp_outreach', array(__CLASS__, 'handle_post_camp_outreach'), 10, 1);

        // ── Mid-camp Day 3 SMS ──
        add_action('ptp_mentorship_midcamp_sms', array(__CLASS__, 'send_midcamp_sms'), 10, 1);

        // ── Free session completed → mentorship follow-up ──

        // ── Training session completed (2nd+) → mentorship suggestion ──
        // FIXED: was ptp_training_session_completed (never fires), now matches actual hook
        add_action('ptp_session_completed', array(__CLASS__, 'handle_training_touchpoint'), 20, 2);

        // ── Parent dashboard shortcode filter ──
        add_action('ptp_parent_dashboard_after_bookings', array(__CLASS__, 'render_dashboard_mentorship_card'), 10, 1);

        // ── Cron: process scheduled touches ──
        add_action('ptp_mentorship_touch_day2',  array(__CLASS__, 'send_day2_email'), 10, 1);
        add_action('ptp_mentorship_touch_day5',  array(__CLASS__, 'send_day5_sms'),   10, 1);
        add_action('ptp_mentorship_touch_day10', array(__CLASS__, 'send_day10_email'), 10, 1);
        add_action('ptp_mentorship_free_session_followup', array(__CLASS__, 'send_free_session_followup'), 10, 1);
    }

    // ================================================================
    // 1. POST-CAMP OUTREACH: Schedule 3-touch sequence
    // Fires from ptp_mentorship_post_camp_outreach (set by PTP_Mentorship)
    // ================================================================
    public static function handle_post_camp_outreach($data) {
        $email       = $data['parent_email'] ?? '';
        $parent_name = $data['parent_name'] ?? '';
        $player_name = $data['player_name'] ?? '';
        $trainer_id  = intval($data['trainer_id'] ?? 0);
        $trainer_name= $data['trainer_name'] ?? '';
        $camp_id     = intval($data['camp_id'] ?? 0);

        if (!$email || !$trainer_id) return;

        // Don't double-schedule
        $key = 'ptp_mtouch_' . md5($email . $trainer_id . $camp_id);
        if (get_transient($key)) return;
        set_transient($key, 1, 15 * DAY_IN_SECONDS);

        $touch_data = array(
            'email'        => $email,
            'parent_name'  => $parent_name,
            'player_name'  => $player_name,
            'trainer_id'   => $trainer_id,
            'trainer_name' => $trainer_name,
            'camp_id'      => $camp_id,
            'phone'        => self::get_parent_phone($email),
        );

        // Schedule Day 2, Day 5, Day 10
        $now = time();
        wp_schedule_single_event($now + (2 * DAY_IN_SECONDS),  'ptp_mentorship_touch_day2',  array($touch_data));
        wp_schedule_single_event($now + (5 * DAY_IN_SECONDS),  'ptp_mentorship_touch_day5',  array($touch_data));
        wp_schedule_single_event($now + (10 * DAY_IN_SECONDS), 'ptp_mentorship_touch_day10', array($touch_data));

        ptp_log('[PTP Mentorship Touchpoints] Scheduled 3-touch post-camp sequence for ' . $email . ' / trainer ' . $trainer_id);
    }

    // ── Day 2: Camp Recap + Mentorship CTA Email ──
    public static function send_day2_email($data) {
        if (self::already_has_mentorship($data['email'], $data['trainer_id'])) return;

        $trainer  = self::get_trainer($data['trainer_id']);
        if (!$trainer) return;

        $first    = explode(' ', $data['trainer_name'])[0];
        $player   = $data['player_name'] ?: 'your player';
        $spots    = self::get_spots_left($trainer);
        $signup   = add_query_arg(['trainer_id' => $trainer->id, 'package' => 'development'], home_url('/mentorship-signup/'));

        $subject = $player . "'s week with " . $first . " — and what comes next";

        $body = self::email_template(
            'Camp Week Recap',
            array(
                'Hey ' . ($data['parent_name'] ? explode(' ', $data['parent_name'])[0] : 'there') . ',',
                '',
                $player . ' had an awesome week training with ' . $first . '. The energy, the reps, the competitive games — that\'s the PTP experience.',
                '',
                'But here\'s what most parents don\'t realize: <strong>the camp is just the beginning</strong>.',
                '',
                $first . ' offers weekly 1-on-1 video mentorship — same coach, same relationship, year-round. Weekly video calls, film review, goal tracking, and real accountability.',
                '',
                $spots <= 5 ? '<strong>' . $first . ' has ' . $spots . ' mentorship spot' . ($spots !== 1 ? 's' : '') . ' left.</strong>' : '',
                '',
                '<a href="' . esc_url($signup) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 28px;border-radius:10px;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;text-decoration:none;font-size:14px;">Book a Free Intro Call with ' . esc_html($first) . '</a>',
                '',
                'No payment until after the intro call. Just a 15-minute video chat so ' . $player . ' and ' . $first . ' can reconnect.',
                '',
                '— PTP Team',
            )
        );

        self::send_email($data['email'], $subject, $body);
        ptp_log('[PTP Mentorship Touchpoints] Sent Day 2 email to ' . $data['email']);
    }

    // ── Day 5: Urgency SMS ──
    public static function send_day5_sms($data) {
        if (self::already_has_mentorship($data['email'], $data['trainer_id'])) return;
        if (empty($data['phone'])) return;

        $trainer = self::get_trainer($data['trainer_id']);
        if (!$trainer) return;

        $first  = explode(' ', $data['trainer_name'])[0];
        $player = $data['player_name'] ?: 'Your player';
        $spots  = self::get_spots_left($trainer);
        $link   = add_query_arg(['trainer_id' => $trainer->id, 'package' => 'development'], home_url('/mentorship-signup/'));
        $short  = home_url('/mentorship/');

        $msg = $player . ' trained with ' . $first . ' at camp last week.';
        if ($spots <= 5) {
            $msg .= ' ' . $first . ' has ' . $spots . ' mentorship spot' . ($spots !== 1 ? 's' : '') . ' left.';
        }
        $msg .= ' Want weekly video calls with ' . $first . '? Free intro call: ' . $short;

        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            PTP_SMS::send($data['phone'], $msg);
            ptp_log('[PTP Mentorship Touchpoints] Sent Day 5 SMS to ' . $data['phone']);
        }
    }

    // ── Day 10: Last Chance Email ──
    public static function send_day10_email($data) {
        if (self::already_has_mentorship($data['email'], $data['trainer_id'])) return;

        $trainer = self::get_trainer($data['trainer_id']);
        if (!$trainer) return;

        $first  = explode(' ', $data['trainer_name'])[0];
        $player = $data['player_name'] ?: 'your player';
        $spots  = self::get_spots_left($trainer);
        $signup = add_query_arg(['trainer_id' => $trainer->id, 'package' => 'development'], home_url('/mentorship-signup/'));

        $subject = 'Last call — ' . $first . "'s mentorship spots are filling up";

        $body = self::email_template(
            'Spots Are Going',
            array(
                'Hey ' . ($data['parent_name'] ? explode(' ', $data['parent_name'])[0] : 'there') . ',',
                '',
                'Quick follow-up. ' . $first . '\'s mentorship spots are almost full.' . ($spots <= 5 ? ' <strong>Only ' . $spots . ' left.</strong>' : ''),
                '',
                'Once they\'re gone, it\'s waitlist only.',
                '',
                'If ' . $player . ' clicked with ' . $first . ' at camp, this is the way to keep that going — weekly video calls, real goals, real accountability.',
                '',
                '<a href="' . esc_url($signup) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 28px;border-radius:10px;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;text-decoration:none;font-size:14px;">Grab a Spot Before They\'re Gone</a>',
                '',
                'Free intro call. No payment until you\'re ready.',
                '',
                '— PTP Team',
            )
        );

        self::send_email($data['email'], $subject, $body);
        ptp_log('[PTP Mentorship Touchpoints] Sent Day 10 email to ' . $data['email']);
    }

    // ================================================================
    // 2. MID-CAMP DAY 3 SMS
    // Called via cron, scheduled when camp starts
    // ================================================================
    public static function send_midcamp_sms($data) {
        $phone       = $data['phone'] ?? '';
        $parent_name = $data['parent_name'] ?? '';
        $player_name = $data['player_name'] ?? 'Your player';
        $trainer_id  = intval($data['trainer_id'] ?? 0);

        if (!$phone || !$trainer_id) return;

        $trainer = self::get_trainer($trainer_id);
        if (!$trainer || empty($trainer->mentorship_enabled)) return;

        // Already has mentorship?
        if (self::already_has_mentorship_by_phone($phone, $trainer_id)) return;

        $first = explode(' ', $trainer->display_name ?? 'Your coach')[0];
        $link  = home_url('/mentorship/');

        $msg = 'Hey' . ($parent_name ? ' ' . explode(' ', $parent_name)[0] : '') . ' — ' . $first . ' says ' . $player_name . ' is doing great this week! Did you know ' . $first . ' also does weekly 1-on-1 video mentorship? Free intro call: ' . $link;

        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            PTP_SMS::send($phone, $msg);
            ptp_log('[PTP Mentorship Touchpoints] Sent mid-camp SMS to ' . $phone);
        }
    }

    // ================================================================
    // 3. FREE SESSION → MENTORSHIP FOLLOW-UP
    // Hooks into ptp_free_session_accepted
    // ================================================================
    public static function schedule_free_session_followup($app_id, $app_data = null, $trainer_slug = '') {
        if (!$app_data) return;

        // Get application data
        global $wpdb;
        $app = is_object($app_data) ? $app_data : $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_session_applications WHERE id = %d", $app_id
        ));
        if (!$app) return;

        $email      = $app->parent_email ?? $app->email ?? '';
        $phone      = $app->phone ?? '';
        $player     = $app->child_name ?? '';
        $trainer_id = intval($app->assigned_trainer ?? $app->trainer_id ?? 0);
        
        if (!$email || !$trainer_id) return;

        $trainer = self::get_trainer($trainer_id);
        if (!$trainer || empty($trainer->mentorship_enabled)) return;
        if (self::already_has_mentorship($email, $trainer_id)) return;

        // Don't double-schedule
        $key = 'ptp_fsmtouch_' . md5($email . $trainer_id);
        if (get_transient($key)) return;
        set_transient($key, 1, 10 * DAY_IN_SECONDS);

        $touch_data = array(
            'email'        => $email,
            'phone'        => $phone,
            'player_name'  => $player,
            'parent_name'  => $app->parent_name ?? '',
            'trainer_id'   => $trainer_id,
            'trainer_name' => $trainer->display_name,
        );

        // Send 24 hours after the free session is accepted/booked
        wp_schedule_single_event(time() + DAY_IN_SECONDS, 'ptp_mentorship_free_session_followup', array($touch_data));
        ptp_log('[PTP Mentorship Touchpoints] Scheduled free session follow-up for ' . $email);
    }

    public static function send_free_session_followup($data) {
        if (self::already_has_mentorship($data['email'], $data['trainer_id'])) return;

        $trainer = self::get_trainer($data['trainer_id']);
        if (!$trainer) return;

        $first  = explode(' ', $data['trainer_name'])[0];
        $player = $data['player_name'] ?: 'your player';
        $signup = add_query_arg(['trainer_id' => $trainer->id, 'package' => 'development'], home_url('/mentorship-signup/'));

        // Send SMS if we have phone
        if (!empty($data['phone']) && class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            $sms = 'How\'d ' . $player . '\'s session with ' . $first . ' go? Want to keep it going? ' . $first . ' offers weekly video mentorship — free intro call: ' . home_url('/mentorship/');
            PTP_SMS::send($data['phone'], $sms);
        }

        // Send email
        $subject = $player . "'s session with " . $first . " — want to keep going?";
        $body = self::email_template(
            'Keep the Momentum',
            array(
                'Hey ' . ($data['parent_name'] ? explode(' ', $data['parent_name'])[0] : 'there') . ',',
                '',
                'Hope ' . $player . '\'s session with ' . $first . ' went well!',
                '',
                'If ' . $player . ' clicked with ' . $first . ', there\'s a way to keep that relationship going year-round: <strong>weekly video mentorship</strong>.',
                '',
                'Weekly video calls, film review, goal tracking, and an action item every week. ' . $first . ' becomes ' . $player . '\'s personal coach — the same person they already know and trust.',
                '',
                '<a href="' . esc_url($signup) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 28px;border-radius:10px;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;text-decoration:none;font-size:14px;">Book a Free Intro Call</a>',
                '',
                'It starts with a free 15-minute video call. No payment, no commitment.',
                '',
                '— PTP Team',
            )
        );

        self::send_email($data['email'], $subject, $body);
        ptp_log('[PTP Mentorship Touchpoints] Sent free session follow-up to ' . $data['email']);
    }

    // ================================================================
    // 4. TRAINING SESSION (2nd+) → MENTORSHIP
    // Hooks into ptp_session_completed (FIXED from ptp_training_session_completed)
    // ================================================================
    public static function handle_training_touchpoint($booking_id, $booking_data = array()) {
        // Handle both object (from $wpdb->get_row) and array
        if (is_object($booking_data)) {
            $trainer_id = intval($booking_data->trainer_id ?? 0);
            $parent_id  = intval($booking_data->parent_id ?? 0);
        } elseif (is_numeric($booking_data)) {
            // Cron passes just trainer_id as second param
            $trainer_id = intval($booking_data);
            global $wpdb;
            $parent_id = intval($wpdb->get_var($wpdb->prepare(
                "SELECT parent_id FROM {$wpdb->prefix}ptp_bookings WHERE id = %d", $booking_id
            )));
        } else {
            $trainer_id = intval($booking_data['trainer_id'] ?? 0);
            $parent_id  = intval($booking_data['parent_id'] ?? 0);
        }
        if (!$trainer_id || !$parent_id) return;

        global $wpdb;

        // Only after 2nd session with same trainer
        $session_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND parent_id = %d AND status IN ('completed','confirmed')",
            $trainer_id, $parent_id
        )));
        if ($session_count < 2) return;

        $trainer = self::get_trainer($trainer_id);
        if (!$trainer || empty($trainer->mentorship_enabled)) return;

        // Get parent email
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT email, first_name, phone FROM {$wpdb->prefix}ptp_parents WHERE id = %d", $parent_id
        ));
        if (!$parent || !$parent->email) return;
        if (self::already_has_mentorship($parent->email, $trainer_id)) return;

        // Don't send more than once
        $key = 'ptp_trtouch_' . md5($parent->email . $trainer_id);
        if (get_transient($key)) return;
        set_transient($key, 1, 30 * DAY_IN_SECONDS);

        $first  = explode(' ', $trainer->display_name)[0];
        $signup = add_query_arg(['trainer_id' => $trainer->id, 'package' => 'development'], home_url('/mentorship-signup/'));

        $subject = $first . ' + your player — ready for the next level?';
        $body = self::email_template(
            'Next Level',
            array(
                'Hey ' . ($parent->first_name ?: 'there') . ',',
                '',
                'Your player has done ' . $session_count . ' sessions with ' . $first . '. That\'s a real relationship forming.',
                '',
                'If you want to take it further, ' . $first . ' offers <strong>weekly video mentorship</strong> — structured calls, film review, goal tracking, and a clear action item every week.',
                '',
                'It\'s the difference between occasional training and real, compounding growth.',
                '',
                '<a href="' . esc_url($signup) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 28px;border-radius:10px;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;text-decoration:none;font-size:14px;">Free Intro Call with ' . esc_html($first) . '</a>',
                '',
                '— PTP Team',
            )
        );

        self::send_email($parent->email, $subject, $body);
        ptp_log('[PTP Mentorship Touchpoints] Sent training upsell to ' . $parent->email . ' after session #' . $session_count);
    }

    // ================================================================
    // 5. PARENT DASHBOARD MENTORSHIP CARD
    // Hook: ptp_parent_dashboard_after_bookings
    // ================================================================
    public static function render_dashboard_mentorship_card($parent_id = 0) {
        if (!$parent_id) $parent_id = get_current_user_id();
        if (!$parent_id) return;

        global $wpdb;

        // Skip if parent already has active mentorship
        $has_mentorship = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE parent_id = %d AND status NOT IN ('cancelled','completed') LIMIT 1",
            $parent_id
        ));
        if ($has_mentorship) return;

        // Find trainers they've worked with who offer mentorship
        $trainers = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT t.id, t.display_name, t.photo_url, t.slug, t.mentorship_bio
             FROM {$wpdb->prefix}ptp_trainers t
             WHERE t.mentorship_enabled = 1 AND t.status = 'active'
             AND t.id IN (
                SELECT trainer_id FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = %d AND status IN ('completed','confirmed')
                UNION
                SELECT trainer_id FROM {$wpdb->prefix}ptp_camp_order_items coi
                JOIN {$wpdb->prefix}ptp_unified_camp_orders co ON coi.order_id = co.id
                WHERE co.user_id = %d AND coi.trainer_id > 0
             )
             ORDER BY t.average_rating DESC LIMIT 3",
            $parent_id, $parent_id
        ));

        // Fallback: show featured mentorship trainers
        if (empty($trainers)) {
            $trainers = $wpdb->get_results(
                "SELECT id, display_name, photo_url, slug, mentorship_bio 
                 FROM {$wpdb->prefix}ptp_trainers 
                 WHERE mentorship_enabled = 1 AND status = 'active' AND is_featured = 1
                 ORDER BY average_rating DESC LIMIT 2"
            );
        }

        if (empty($trainers)) return;
        $t = $trainers[0];
        $first = explode(' ', $t->display_name)[0];
        $signup = add_query_arg(['trainer_id' => $t->id, 'package' => 'development'], home_url('/mentorship-signup/'));
        ?>
        <div style="background:linear-gradient(135deg,rgba(252,185,0,0.06),transparent);border:2px solid rgba(252,185,0,0.2);border-radius:16px;padding:20px;margin:16px 0">
            <div style="font-family:Oswald,sans-serif;font-size:10px;letter-spacing:2px;text-transform:uppercase;color:#FCB900;margin-bottom:8px">Take It Further</div>
            <div style="font-weight:700;font-size:16px;margin-bottom:6px">Your coaches offer 1-on-1 mentorship</div>
            <p style="font-size:13px;color:#737373;line-height:1.5;margin-bottom:16px">
                Weekly video calls, film review, goal tracking. The same coach<?php echo count($trainers) > 1 ? 'es' : ''; ?> your kid already knows — year-round.
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
                <?php foreach ($trainers as $mt): 
                    $mt_photo = $mt->photo_url ?? '';
                    $mt_first = explode(' ', $mt->display_name)[0];
                ?>
                <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.05);border-radius:10px;padding:8px 12px">
                    <?php if ($mt_photo): ?>
                    <img src="<?php echo esc_url($mt_photo); ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover">
                    <?php else: ?>
                    <div style="width:32px;height:32px;border-radius:50%;background:#FCB900;display:flex;align-items:center;justify-content:center;font-family:Oswald,sans-serif;font-weight:700;font-size:14px;color:#0A0A0A"><?php echo strtoupper(substr($mt_first, 0, 1)); ?></div>
                    <?php endif; ?>
                    <span style="font-weight:600;font-size:13px"><?php echo esc_html($mt->display_name); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <a href="<?php echo esc_url($signup); ?>" style="display:block;text-align:center;padding:12px;background:#FCB900;color:#0A0A0A;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;font-size:13px;border-radius:10px;text-decoration:none;letter-spacing:.5px">Free Intro Call</a>
        </div>
        <?php
    }

    // ================================================================
    // UTILITIES
    // ================================================================
    private static function get_trainer($trainer_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
        ));
    }

    private static function get_spots_left($trainer) {
        global $wpdb;
        $max = max(1, intval($trainer->mentorship_max_mentees ?? 20));
        $active = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND status = 'active'",
            $trainer->id
        )));
        return max(0, $max - $active);
    }

    private static function already_has_mentorship($email, $trainer_id) {
        global $wpdb;
        // Check by email → user_id → parent_id
        $user = get_user_by('email', $email);
        if (!$user) return false;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND parent_id = %d AND status NOT IN ('cancelled') LIMIT 1",
            $trainer_id, $user->ID
        ));
    }

    private static function already_has_mentorship_by_phone($phone, $trainer_id) {
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}ptp_parents WHERE phone = %s LIMIT 1",
            preg_replace('/[^0-9]/', '', $phone)
        ));
        if (!$parent) return false;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND parent_id = %d AND status NOT IN ('cancelled') LIMIT 1",
            $trainer_id, $parent->user_id
        ));
    }

    private static function get_parent_phone($email) {
        global $wpdb;
        // Try parents table
        $phone = $wpdb->get_var($wpdb->prepare(
            "SELECT phone FROM {$wpdb->prefix}ptp_parents WHERE email = %s AND phone != '' LIMIT 1", $email
        ));
        if ($phone) return $phone;
        // Try camp orders
        $phone = $wpdb->get_var($wpdb->prepare(
            "SELECT billing_phone FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE billing_email = %s AND billing_phone != '' ORDER BY id DESC LIMIT 1", $email
        ));
        return $phone ?: '';
    }

    private static function send_email($to, $subject, $html) {
        if (class_exists('PTP_Email_Templates')) {
            $tpl = new PTP_Email_Templates();
            $wrapped = $tpl->wrap_in_template($html, $subject);
            PTP_Email_Templates::send($to, $subject, $wrapped);
        } else {
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . ptp_email_brand('from_training'));
            wp_mail($to, $subject, $html, $headers);
        }
    }

    private static function email_template($heading, $lines) {
        $html = '<div style="font-family:Inter,-apple-system,sans-serif;max-width:560px;margin:0 auto;">';
        $html .= '<div style="font-family:Oswald,sans-serif;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#FCB900;margin-bottom:8px">PTP Mentorship</div>';
        $html .= '<h2 style="font-family:Oswald,sans-serif;font-size:24px;font-weight:700;text-transform:uppercase;color:#0A0A0A;margin:0 0 20px;line-height:1.2">' . esc_html($heading) . '</h2>';
        foreach ($lines as $line) {
            if ($line === '') {
                $html .= '<div style="height:12px"></div>';
            } elseif (strpos($line, '<a ') !== false) {
                $html .= '<div style="text-align:center;margin:16px 0">' . $line . '</div>';
            } else {
                $html .= '<p style="font-size:15px;color:#333;line-height:1.65;margin:0 0 4px">' . $line . '</p>';
            }
        }
        $html .= '</div>';
        return $html;
    }
}
