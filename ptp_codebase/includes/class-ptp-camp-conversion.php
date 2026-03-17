<?php
/**
 * PTP Camp Conversion — Camp → Mentorship Funnel Touchpoints v227
 *
 * Handles:
 * 1. Thank-you page mentorship CTA (reads transient from PTP_Mentorship::inject_camp_touchpoint)
 * 2. Post-camp 3-touch sequence (Day 2 email, Day 5 SMS, Day 10 last-chance email)
 * 3. Free session → mentorship follow-up
 * 4. Confirmation email P.S. line
 * 5. Parent dashboard mentorship promo card
 */
defined('ABSPATH') || exit;

class PTP_Camp_Conversion {

    public static function init() {
        // ── 1. Thank-you page mentorship CTA ──
        add_action('ptp_thankyou_after_whats_next', array(__CLASS__, 'render_thankyou_mentorship_cta'), 10, 3);

        // ── 2. Post-camp sequence — listen for the hook that already fires ──
        add_action('ptp_mentorship_post_camp_outreach', array(__CLASS__, 'schedule_post_camp_sequence'), 10, 1);
        add_action('ptp_camp_conversion_day2_email',    array(__CLASS__, 'send_day2_email'), 10, 1);
        add_action('ptp_camp_conversion_day5_sms',      array(__CLASS__, 'send_day5_sms'), 10, 1);
        add_action('ptp_camp_conversion_day10_email',   array(__CLASS__, 'send_day10_email'), 10, 1);

        // ── 3. Free session → mentorship follow-up ──

        // ── 4. Camp order completed — add mentorship P.S. to confirmation ──
        add_action('ptp_camp_order_completed', array(__CLASS__, 'maybe_add_mentorship_ps_to_confirmation'), 20, 2);
    }

    // ================================================================
    // 1. THANK-YOU PAGE — Mentorship CTA Card
    // ================================================================
    /**
     * Renders a mentorship CTA card on the thank-you page.
     * Called by do_action('ptp_thankyou_after_whats_next') which we'll add to thank-you.php.
     *
     * Also works as a static method callable directly from the template.
     */
    public static function render_thankyou_mentorship_cta($order_id = 0, $booking = null, $order = null) {
        // Try to find mentorship CTA data from transient
        $cta_data = null;

        // Method 1: Transient from PTP_Mentorship::inject_camp_touchpoint
        if ($order_id) {
            $cta_data = get_transient('ptp_mentorship_cta_' . $order_id);
        }

        // Method 2: Look up trainer from booking
        if (!$cta_data && $booking && !empty($booking->trainer_id)) {
            $cta_data = self::build_cta_from_trainer($booking->trainer_id);
        }

        // Method 3: Look up trainer from camp order items
        if (!$cta_data && $order && !empty($order->id)) {
            global $wpdb;
            // Camp orders may not have trainer_id directly — check if any coach assigned
            $trainer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT t.id FROM {$wpdb->prefix}ptp_trainers t
                 WHERE t.mentorship_enabled = 1 AND t.status = 'active'
                 ORDER BY t.is_featured DESC, t.average_rating DESC LIMIT 1"
            ));
            if ($trainer_id) {
                $cta_data = self::build_cta_from_trainer($trainer_id);
            }
        }

        // Fallback: generic mentorship promo (no specific trainer)
        if (!$cta_data) {
            // Check if ANY trainer has mentorship enabled
            global $wpdb;
            $has_any = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE mentorship_enabled = 1 AND status = 'active'"
            );
            if (!$has_any) return; // No mentors available, don't show
            $cta_data = array(
                'trainer_id'   => 0,
                'trainer_name' => '',
                'trainer_photo'=> '',
                'generic'      => true,
            );
        }

        self::output_thankyou_card($cta_data);
    }

    private static function build_cta_from_trainer($trainer_id) {
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, photo_url, mentorship_enabled, mentorship_bio, slug
             FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
        ));
        if (!$trainer || empty($trainer->mentorship_enabled)) return null;
        return array(
            'trainer_id'    => $trainer->id,
            'trainer_name'  => $trainer->display_name,
            'trainer_photo' => $trainer->photo_url ?: '',
            'trainer_slug'  => $trainer->slug ?: '',
            'trainer_bio'   => $trainer->mentorship_bio ?: '',
        );
    }

    private static function output_thankyou_card($data) {
        $is_generic = !empty($data['generic']);
        $name = $data['trainer_name'] ?? '';
        $first = explode(' ', $name)[0] ?? '';
        $photo = $data['trainer_photo'] ?? '';
        $signup_url = $is_generic
            ? home_url('/mentorship/')
            : add_query_arg(array('trainer_id' => $data['trainer_id'], 'package' => 'development'), home_url('/mentorship-signup/'));
        $landing_url = home_url('/mentorship/');
        ?>
        <div class="ptp-card" style="background:linear-gradient(135deg,rgba(252,185,0,0.08),rgba(252,185,0,0.02));border-color:rgba(252,185,0,0.3);margin-top:4px">
            <p class="ptp-card-title" style="color:var(--gold)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="stroke:var(--gold)"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                Keep Training After Camp
            </p>

            <?php if (!$is_generic && $photo): ?>
            <div style="display:flex;gap:14px;align-items:center;margin-bottom:14px">
                <img src="<?php echo esc_url($photo); ?>" alt="" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:3px solid var(--gold)">
                <div>
                    <div style="font-family:Oswald,sans-serif;font-size:15px;font-weight:700;text-transform:uppercase;color:#fff"><?php echo esc_html($name); ?></div>
                    <div style="font-size:12px;color:var(--gold)">Offers 1-on-1 Mentorship</div>
                </div>
            </div>
            <?php endif; ?>

            <p style="font-size:14px;color:rgba(255,255,255,0.8);line-height:1.6;margin:0 0 16px 0">
                <?php if (!$is_generic): ?>
                    <?php echo esc_html($first); ?> offers weekly video mentorship — film review, goal tracking, and real accountability. Camp families get first access to open spots.
                <?php else: ?>
                    PTP coaches offer weekly 1-on-1 video mentorship. Film review, goal tracking, and a real relationship that continues after camp ends.
                <?php endif; ?>
            </p>

            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">
                <?php foreach (array('Weekly Video Calls','Film Review','Goal Tracking','Parent Recaps') as $feat): ?>
                <span style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(252,185,0,0.1);color:var(--gold);font-weight:600"><?php echo $feat; ?></span>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;gap:8px">
                <?php if (!$is_generic): ?>
                <a href="<?php echo esc_url($signup_url); ?>" class="ptp-btn gold" style="flex:1;padding:13px 16px;font-size:13px">
                    Free Intro Call with <?php echo esc_html($first); ?>
                </a>
                <?php else: ?>
                <a href="<?php echo esc_url($landing_url); ?>" class="ptp-btn gold" style="flex:1;padding:13px 16px;font-size:13px">
                    Learn About Mentorship
                </a>
                <?php endif; ?>
            </div>
            <div style="text-align:center;font-size:11px;color:rgba(255,255,255,0.35);margin-top:8px">No payment until after your free intro call</div>
        </div>
        <?php
    }

    // ================================================================
    // 2. POST-CAMP 3-TOUCH SEQUENCE
    // ================================================================
    /**
     * Schedules Day 2/5/10 outreach. Called when ptp_mentorship_post_camp_outreach fires.
     */
    public static function schedule_post_camp_sequence($data) {
        $parent_email = $data['parent_email'] ?? '';
        if (!is_email($parent_email)) return;

        $key = md5($parent_email . ($data['trainer_id'] ?? '') . ($data['camp_id'] ?? ''));

        // Don't schedule if already sent
        if (get_transient('ptp_camp_seq_' . $key)) return;
        set_transient('ptp_camp_seq_' . $key, true, 15 * DAY_IN_SECONDS);

        // Day 2: Email
        wp_schedule_single_event(time() + (2 * DAY_IN_SECONDS), 'ptp_camp_conversion_day2_email', array($data));

        // Day 5: SMS
        wp_schedule_single_event(time() + (5 * DAY_IN_SECONDS), 'ptp_camp_conversion_day5_sms', array($data));

        // Day 10: Last chance email
        wp_schedule_single_event(time() + (10 * DAY_IN_SECONDS), 'ptp_camp_conversion_day10_email', array($data));

        ptp_log('[PTP Camp Conversion] Scheduled 3-touch sequence for ' . $parent_email . ' / trainer ' . ($data['trainer_id'] ?? 'none'));
    }

    /**
     * Day 2: Camp recap + mentorship CTA email
     */
    public static function send_day2_email($data) {
        $email   = $data['parent_email'] ?? '';
        $pname   = $data['parent_name'] ?? 'there';
        $first   = explode(' ', $pname)[0] ?? 'there';
        $kid     = $data['player_name'] ?? 'your player';
        $trainer = $data['trainer_name'] ?? 'their coach';
        $tid     = $data['trainer_id'] ?? 0;

        // Check if parent already has mentorship
        if (self::parent_has_mentorship($email, $tid)) return;

        $signup_url = $tid
            ? add_query_arg(array('trainer_id' => $tid, 'package' => 'development'), home_url('/mentorship-signup/'))
            : home_url('/mentorship/');

        $subject = $kid . "'s PTP Camp Recap — Plus a Way to Keep It Going";

        if (class_exists('PTP_Notifications') && method_exists('PTP_Notifications', 'mentorship_email')) {
            PTP_Notifications::mentorship_email($email, $subject, 'Camp Week Was Just the Start', array(
                "Hey {$first},",
                "{$kid} had an awesome week at PTP camp. The energy, the reps, the coaching — that's the PTP difference.",
                "But here's the thing: camp ends. The momentum doesn't have to.",
                "<strong>{$trainer}</strong> offers weekly 1-on-1 video mentorship — film review, goal tracking, and real accountability. Camp families get first access.",
                "<a href=\"{$signup_url}\" style=\"display:inline-block;background:#FCB900;color:#0A0A0A;padding:12px 24px;border-radius:8px;font-weight:700;text-decoration:none;font-family:Oswald,sans-serif;text-transform:uppercase\">Book a Free Intro Call</a>",
                "No payment needed. Just a 15-minute video call so {$kid} and {$trainer} can see if it's the right fit.",
            ));
        } else {
            // Fallback: wp_mail
            $body = "Hey {$first},\n\n{$kid} had a great week at PTP camp. Want to keep the momentum going?\n\n{$trainer} offers weekly 1-on-1 video mentorship. Camp families get first access.\n\nBook a free intro call: {$signup_url}\n\n— PTP Soccer";
            wp_mail($email, $subject, $body);
        }

        ptp_log('[PTP Camp Conversion] Day 2 email sent to ' . $email);
    }

    /**
     * Day 5: SMS nudge
     */
    public static function send_day5_sms($data) {
        $phone   = $data['parent_phone'] ?? '';
        $kid     = $data['player_name'] ?? 'your player';
        $trainer = $data['trainer_name'] ?? 'their coach';
        $tid     = $data['trainer_id'] ?? 0;
        $email   = $data['parent_email'] ?? '';

        if (self::parent_has_mentorship($email, $tid)) return;

        // Try to get phone from parent record if not in data
        if (!$phone && $email) {
            global $wpdb;
            $phone = $wpdb->get_var($wpdb->prepare(
                "SELECT phone FROM {$wpdb->prefix}ptp_parents WHERE email = %s", $email
            ));
        }

        if (!$phone) {
            ptp_log('[PTP Camp Conversion] Day 5 SMS skipped — no phone for ' . $email);
            return;
        }

        $signup_url = $tid
            ? add_query_arg(array('trainer_id' => $tid, 'package' => 'development'), home_url('/mentorship-signup/'))
            : home_url('/mentorship/');

        // Get spots remaining
        $spots_text = '';
        if ($tid) {
            global $wpdb;
            $trainer_row = $wpdb->get_row($wpdb->prepare(
                "SELECT mentorship_max_mentees, (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND status = 'active') AS active_count
                 FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $tid, $tid
            ));
            if ($trainer_row) {
                $spots = max(0, intval($trainer_row->mentorship_max_mentees ?: 20) - intval($trainer_row->active_count));
                if ($spots > 0 && $spots <= 10) {
                    $spots_text = " ({$spots} spots left)";
                }
            }
        }

        $msg = "{$kid} trained with {$trainer} last week at PTP camp. Want to keep it going? {$trainer} does weekly video mentorship for camp families{$spots_text}. Free intro call: {$signup_url}";

        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            PTP_SMS::send($phone, $msg);
            ptp_log('[PTP Camp Conversion] Day 5 SMS sent to ' . $phone);
        } else {
            ptp_log('[PTP Camp Conversion] Day 5 SMS skipped — SMS not enabled');
        }
    }

    /**
     * Day 10: Last chance urgency email
     */
    public static function send_day10_email($data) {
        $email   = $data['parent_email'] ?? '';
        $first   = explode(' ', ($data['parent_name'] ?? 'there'))[0] ?? 'there';
        $kid     = $data['player_name'] ?? 'your player';
        $trainer = $data['trainer_name'] ?? 'their coach';
        $tid     = $data['trainer_id'] ?? 0;

        if (self::parent_has_mentorship($email, $tid)) return;

        // Get real spot count
        $spots = 0;
        if ($tid) {
            global $wpdb;
            $trainer_row = $wpdb->get_row($wpdb->prepare(
                "SELECT mentorship_max_mentees, (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND status = 'active') AS active_count
                 FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $tid, $tid
            ));
            if ($trainer_row) {
                $spots = max(0, intval($trainer_row->mentorship_max_mentees ?: 20) - intval($trainer_row->active_count));
            }
        }

        $spots_line = $spots > 0 ? "{$trainer} has <strong>{$spots} mentorship spot" . ($spots !== 1 ? 's' : '') . " left</strong>. Camp families had first access — but that window is closing." : "{$trainer}'s mentorship spots are going fast. Once they fill, it's waitlist only.";

        $signup_url = $tid
            ? add_query_arg(array('trainer_id' => $tid, 'package' => 'development'), home_url('/mentorship-signup/'))
            : home_url('/mentorship/');

        $subject = "Last call: Mentorship spots with {$trainer} are filling up";

        if (class_exists('PTP_Notifications') && method_exists('PTP_Notifications', 'mentorship_email')) {
            PTP_Notifications::mentorship_email($email, $subject, 'Spots Are Filling Up', array(
                "Hey {$first},",
                $spots_line,
                "Weekly video calls. Film review. Goal tracking. A real relationship with someone {$kid} already knows and trusts from camp.",
                "<a href=\"{$signup_url}\" style=\"display:inline-block;background:#FCB900;color:#0A0A0A;padding:12px 24px;border-radius:8px;font-weight:700;text-decoration:none;font-family:Oswald,sans-serif;text-transform:uppercase\">Book a Free Intro Call</a>",
                "Still free to try. 15-minute video call, no payment needed.",
            ));
        } else {
            $body = "Hey {$first},\n\n{$spots_line}\n\nWeekly video calls, film review, and real accountability with someone {$kid} already knows from camp.\n\nFree intro call: {$signup_url}\n\n— PTP Soccer";
            wp_mail($email, $subject, $body);
        }

        ptp_log('[PTP Camp Conversion] Day 10 email sent to ' . $email);
    }

    // ================================================================
    // 3. FREE SESSION → MENTORSHIP FOLLOW-UP
    // ================================================================
    /**
     * After a free session is marked "completed", send mentorship follow-up
     * if the assigned trainer has mentorship enabled.
     *
     * Hooked into ptp_free_session_call_completed (we'll fire this from ajax_update_call_status).
     */
    public static function send_free_session_mentorship_followup($application_id, $application) {
        if (empty($application->phone) || empty($application->email)) return;

        // Get the trainer assigned to this free session
        global $wpdb;
        $booking = null;
        if (!empty($application->booking_id)) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT b.trainer_id, t.display_name, t.mentorship_enabled, t.id as tid, t.slug
                 FROM {$wpdb->prefix}ptp_bookings b
                 JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 WHERE b.id = %d", $application->booking_id
            ));
        }
        if (!$booking || empty($booking->mentorship_enabled)) return;

        // Check if parent already has mentorship
        if (self::parent_has_mentorship($application->email, $booking->tid)) return;

        // Don't spam — check if we already sent this
        $key = 'ptp_fs_mentor_' . md5($application->email . $booking->tid);
        if (get_transient($key)) return;
        set_transient($key, true, 30 * DAY_IN_SECONDS);

        $kid     = $application->child_name ?: 'your player';
        $trainer = $booking->display_name;
        $first_t = explode(' ', $trainer)[0];

        $signup_url = add_query_arg(
            array('trainer_id' => $booking->tid, 'package' => 'development'),
            home_url('/mentorship-signup/')
        );

        // SMS (immediate, high open rate)
        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled() && !empty($application->phone) && !empty($application->sms_consent)) {
            $msg = "How was {$kid}'s free session with {$first_t}? Want to keep that going? {$first_t} offers weekly video mentorship — film review, goals, and real accountability. Book a free intro call: {$signup_url}";
            PTP_SMS::send($application->phone, $msg);
            ptp_log('[PTP Camp Conversion] Free session mentorship SMS sent to ' . $application->phone);
        }

        // Email (follow-up, more detail)
        wp_schedule_single_event(time() + (2 * HOUR_IN_SECONDS), 'ptp_camp_conversion_free_session_email', array(array(
            'email'        => $application->email,
            'parent_name'  => $application->parent_name ?: '',
            'kid'          => $kid,
            'trainer_name' => $trainer,
            'trainer_id'   => $booking->tid,
            'signup_url'   => $signup_url,
        )));
    }

    // ================================================================
    // 4. CONFIRMATION EMAIL P.S.
    // ================================================================
    /**
     * After camp order confirmed, store data so email system can add P.S. line.
     * This fires on ptp_camp_order_completed at priority 20 (after the main email at 10).
     */
    public static function maybe_add_mentorship_ps_to_confirmation($order_id, $order_data) {
        // The transient is already set by PTP_Mentorship::inject_camp_touchpoint at priority 10.
        // This method exists as a hook point for future use — the actual P.S. rendering
        // happens in the email template via a filter.

        // Store a flag that mentorship should be promoted for this order
        update_post_meta($order_id, '_ptp_mentorship_promo', 1);
    }

    // ================================================================
    // UTILITIES
    // ================================================================
    private static function parent_has_mentorship($email, $trainer_id = 0) {
        if (!$email) return false;
        global $wpdb;

        $sql = "SELECT id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE parent_email = %s AND status NOT IN ('cancelled')";
        $params = array($email);

        if ($trainer_id) {
            $sql .= " AND trainer_id = %d";
            $params[] = $trainer_id;
        }

        $sql .= " LIMIT 1";
        return (bool) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }
}
