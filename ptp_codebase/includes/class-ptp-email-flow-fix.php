<?php
/**
 * PTP Email Flow Fix v222.1
 * 
 * ROOT CAUSE ANALYSIS — TRAINING BOOKING DUPLICATE EMAILS
 * ========================================================
 * Parent + trainer each receive 2-3 emails in different formats per booking.
 * 
 * Execution chain (Bulletproof Checkout):
 * 
 *   1. create_training_booking()
 *      → notify_trainer()  =  PLAIN TEXT email to trainer (#1)
 * 
 *   2. do_action('ptp_booking_confirmed', $bid)   [line 1015]
 *      → PTP_Booking_Fix_V121::ensure_booking_emails
 *        = HTML email (LIGHT theme) to trainer + parent (#2)
 *        → sets transient ptp_training_email_sent_
 *      → PTP_Order_Email_Wiring::on_training_booking_completed
 *        = checks transient → skips (good)
 * 
 *   3. send_confirmation_emails()  [line 1371]
 *      → PTP_Notifications::booking_created($bid)  (called DIRECTLY)
 *        = HTML email (DARK theme) to trainer + parent (#3)
 *        → does NOT check transient
 * 
 * RESULT: Trainer = 3 emails. Parent = 2 emails. Different templates.
 * 
 * FIX: Keep PTP_Notifications as the ONE canonical sender (most complete
 * template with player goals, skill level, age, rich data). Kill the rest.
 * 
 * ADDITIONAL POST-SESSION FIXES:
 * - Trainer gets zero post-session emails → add post-session summary
 * - No recap reminders → add 4hr SMS + 24hr email nudge
 * - Payout SMS is dead code → wire it in  
 * - Two competing review systems → consolidate
 * - Day 1 email overlap → retime review request to day 3
 * 
 * @version 222.1.0
 */

defined('ABSPATH') || exit;

class PTP_Email_Flow_Fix {

    public static function init() {
        // =====================================================================
        // FIX #1: Kill PTP_Booking_Fix_V121 duplicate emails
        // =====================================================================
        // Must run AFTER v121 registers (plugins_loaded priority 5) but
        // BEFORE any bookings are processed.
        add_action('plugins_loaded', array(__CLASS__, 'unhook_v121_emails'), 6);

        // =====================================================================
        // FIX #2: Block Bulletproof Checkout plain-text notify_trainer()
        // =====================================================================
        add_filter('ptp_bulletproof_skip_plain_notify', '__return_true');

        // =====================================================================
        // FIX #3: Set dedup transient on ptp_booking_confirmed (priority 99)
        // This prevents PTP_Order_Email_Wiring from also sending via the hook,
        // while PTP_Notifications::booking_created (called directly AFTER hooks)
        // remains the one that actually delivers the emails.
        // =====================================================================
        add_action('ptp_booking_confirmed', array(__CLASS__, 'set_dedup_transient'), 99, 1);

        // =====================================================================
        // FIX #4: Consolidate review request systems
        // Kill PTP_Cron day-1 review request (overlaps with post-session email).
        // Retime PTP_Email_Automation from day 7 → day 3.
        // =====================================================================
        add_action('init', array(__CLASS__, 'unhook_cron_review_requests'), 20);
        add_filter('ptp_review_request_delay_days', function() { return 3; });

        // =====================================================================
        // FIX #5: Wire payout SMS (dead code → live)
        // =====================================================================
        add_action('ptp_trainer_payout_completed', array(__CLASS__, 'send_payout_sms'), 10, 2);

        // =====================================================================
        // FIX #6: Trainer recap reminders
        // =====================================================================
        add_action('ptp_recap_reminder_4hr', array(__CLASS__, 'send_recap_reminder_sms'), 10, 1);
        add_action('ptp_recap_reminder_24hr', array(__CLASS__, 'send_recap_reminder_email'), 10, 1);
        if (!wp_next_scheduled('ptp_check_missing_recaps')) {
            wp_schedule_event(strtotime('today 09:00'), 'daily', 'ptp_check_missing_recaps');
        }
        add_action('ptp_check_missing_recaps', array(__CLASS__, 'daily_missing_recap_check'));

        // =====================================================================
        // FIX #7: Trainer post-session email (they currently get nothing)
        // =====================================================================
        add_action('ptp_session_completed', array(__CLASS__, 'send_trainer_post_session_email'), 10, 2);
        // v222.1: Removed ptp_booking_auto_completed hook — ptp_session_completed already fires
        // from cron auto_complete (line 341), booking.php manual complete, and escrow release.
    }


    // =========================================================================
    // FIX #1: Remove PTP_Booking_Fix_V121 duplicate email hook
    // =========================================================================

    public static function unhook_v121_emails() {
        if (!class_exists('PTP_Booking_Fix_V121')) return;

        $instance = PTP_Booking_Fix_V121::instance();
        $removed  = remove_action('ptp_booking_confirmed', array($instance, 'ensure_booking_emails'), 10);

        if ($removed) {
            ptp_log('[PTP Email Flow Fix v222.1] Removed PTP_Booking_Fix_V121::ensure_booking_emails — PTP_Notifications is the canonical sender');
        } else {
            ptp_log('[PTP Email Flow Fix v222.1] WARNING: Could not unhook ensure_booking_emails');
        }
    }


    // =========================================================================
    // FIX #3: Set dedup transient (priority 99 on ptp_booking_confirmed)
    // =========================================================================

    public static function set_dedup_transient($booking_id) {
        if (get_transient('ptp_training_email_sent_' . $booking_id)) return;

        set_transient('ptp_training_email_sent_' . $booking_id, array(
            'time'   => time(),
            'source' => 'email_flow_fix_v222',
        ), 24 * HOUR_IN_SECONDS);
    }


    // =========================================================================
    // FIX #4: Remove PTP_Cron's day-1 review requests
    // =========================================================================

    public static function unhook_cron_review_requests() {
        if (class_exists('PTP_Cron')) {
            remove_action('ptp_send_review_requests', array('PTP_Cron', 'send_review_requests'));
        }
        $ts = wp_next_scheduled('ptp_send_review_requests');
        if ($ts) wp_unschedule_event($ts, 'ptp_send_review_requests');
    }


    // =========================================================================
    // FIX #5: Wire payout SMS
    // =========================================================================

    public static function send_payout_sms($trainer_id, $amount) {
        global $wpdb;
        $phone = $wpdb->get_var($wpdb->prepare(
            "SELECT phone FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
        ));
        if (!$phone) return;

        if (class_exists('PTP_SMS') && method_exists('PTP_SMS', 'send_payout_notification')) {
            PTP_SMS::send_payout_notification($phone, $amount);
        }
    }


    // =========================================================================
    // FIX #6: Trainer recap reminders
    // =========================================================================

    /** 4-hour SMS nudge */
    public static function send_recap_reminder_sms($booking_id) {
        if (self::has_recap($booking_id)) return;

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT t.phone, p.name AS player_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            WHERE b.id = %d
        ", $booking_id));

        if (!$row || empty($row->phone)) return;

        $player = $row->player_name ?: 'your player';
        $url    = home_url('/trainer-dashboard/?tab=recaps&booking=' . $booking_id);

        if (class_exists('PTP_SMS') && method_exists('PTP_SMS', 'send')) {
            PTP_SMS::send($row->phone,
                "PTP: Quick recap for {$player}'s session? Parents love updates.\nSend recap: {$url}");
        }
    }

    /** 24-hour email nudge */
    public static function send_recap_reminder_email($booking_id) {
        if (self::has_recap($booking_id)) return;

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT b.session_date,
                   t.display_name AS trainer_name, t.user_id AS trainer_user_id,
                   p.name AS player_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            WHERE b.id = %d
        ", $booking_id));
        if (!$row) return;

        $user = get_userdata($row->trainer_user_id);
        if (!$user) return;

        $first  = explode(' ', $row->trainer_name)[0];
        $player = $row->player_name ?: 'your player';
        $date   = date('l, F j', strtotime($row->session_date));
        $url    = esc_url(home_url('/trainer-dashboard/?tab=recaps&booking=' . $booking_id));

        $html = self::email_wrap("
            <p style='margin:0 0 16px;font-size:16px;color:#111;'>Hey " . esc_html($first) . ",</p>
            <p style='margin:0 0 16px;font-size:15px;color:#374151;line-height:1.6;'>
                Your session with <strong>" . esc_html($player) . "</strong> on " . esc_html($date) . " doesn't have a recap yet.
            </p>
            <p style='margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6;'>
                A quick 2-minute recap goes a long way for rebooking.
            </p>
            <div style='text-align:center;margin:24px 0;'>
                <a href='{$url}' style='display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;font-weight:700;text-decoration:none;border-radius:8px;font-size:14px;'>SEND RECAP NOW</a>
            </div>
            <div style='background:#f9fafb;border-radius:8px;padding:16px;margin-top:20px;'>
                <p style='margin:0;font-size:13px;color:#6B7280;text-align:center;'>
                    Trainers who send recaps have a <strong style='color:#111;'>47% higher rebooking rate</strong>.
                </p>
            </div>
        ", 'SESSION RECAP NEEDED');

        wp_mail($user->user_email, "Send your recap for {$player}'s session", $html, array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_training'),
        ));
    }

    /** Daily catch-all for missed recap reminders */
    public static function daily_missing_recap_check() {
        global $wpdb;
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $ids = $wpdb->get_col($wpdb->prepare("
            SELECT b.id FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_session_notes sn ON b.id = sn.booking_id
            WHERE b.session_date = %s AND b.status = 'completed' AND sn.id IS NULL
        ", $yesterday));

        foreach ($ids as $id) {
            if (get_transient('ptp_recap_reminded_' . $id)) continue;
            if (!wp_next_scheduled('ptp_recap_reminder_24hr', array(intval($id)))) {
                wp_schedule_single_event(time() + 300, 'ptp_recap_reminder_24hr', array(intval($id)));
            }
            set_transient('ptp_recap_reminded_' . $id, true, 7 * DAY_IN_SECONDS);
        }
    }


    // =========================================================================
    // FIX #7: Trainer post-session email
    // =========================================================================

    public static function send_trainer_post_session_email($booking_id, $trainer_id = null) {
        if (get_transient('ptp_trainer_postsession_' . $booking_id)) return;

        global $wpdb;
        $b = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, t.display_name AS trainer_name, t.user_id AS trainer_user_id,
                   t.total_sessions AS trainer_total_sessions, p.name AS player_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            WHERE b.id = %d
        ", $booking_id));
        if (!$b) return;

        $user = get_userdata($b->trainer_user_id);
        if (!$user) return;

        $first    = esc_html(explode(' ', $b->trainer_name)[0]);
        $player   = esc_html($b->player_name ?: 'Player');
        $earned   = floatval($b->trainer_payout ?? 0);
        $earned_d = $earned > 0 ? '$' . number_format($earned, 2) : 'Pending';
        $date     = !empty($b->session_date) ? date('l, F j', strtotime($b->session_date)) : 'Today';
        $total    = intval($b->trainer_total_sessions ?? 0);
        $upcoming = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date > CURDATE() AND status IN ('confirmed','pending')",
            $b->trainer_id)));

        $recap    = esc_url(home_url('/trainer-dashboard/?tab=recaps&booking=' . $booking_id));
        $sched    = esc_url(home_url('/trainer-dashboard/?tab=schedule'));
        $earn_url = esc_url(home_url('/trainer-dashboard/?tab=earnings'));

        $html = self::email_wrap("
            <p style='margin:0 0 8px;font-size:16px;color:#111;'>Nice work, {$first}.</p>
            <p style='margin:0 0 24px;font-size:15px;color:#374151;'>
                Your session with <strong>{$player}</strong> on " . esc_html($date) . " is marked complete.
            </p>
            <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:24px;'>
                <tr>
                    <td width='33%' style='text-align:center;padding:16px 8px;background:#f9fafb;border-radius:8px 0 0 8px;'>
                        <p style='margin:0;font-size:22px;font-weight:700;color:#FCB900;'>{$earned_d}</p>
                        <p style='margin:4px 0 0;font-size:11px;color:#6B7280;text-transform:uppercase;letter-spacing:.5px;'>Earned</p>
                    </td>
                    <td width='34%' style='text-align:center;padding:16px 8px;background:#f9fafb;'>
                        <p style='margin:0;font-size:22px;font-weight:700;color:#111;'>{$total}</p>
                        <p style='margin:4px 0 0;font-size:11px;color:#6B7280;text-transform:uppercase;letter-spacing:.5px;'>Total Sessions</p>
                    </td>
                    <td width='33%' style='text-align:center;padding:16px 8px;background:#f9fafb;border-radius:0 8px 8px 0;'>
                        <p style='margin:0;font-size:22px;font-weight:700;color:#111;'>{$upcoming}</p>
                        <p style='margin:4px 0 0;font-size:11px;color:#6B7280;text-transform:uppercase;letter-spacing:.5px;'>Upcoming</p>
                    </td>
                </tr>
            </table>
            <div style='text-align:center;margin-bottom:16px;'>
                <a href='{$recap}' style='display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;font-weight:700;text-decoration:none;border-radius:8px;font-size:14px;width:80%;box-sizing:border-box;text-align:center;'>SEND SESSION RECAP</a>
            </div>
            <table width='100%' cellpadding='0' cellspacing='0'>
                <tr>
                    <td width='50%' style='padding:0 4px 0 0;'>
                        <a href='{$sched}' style='display:block;background:#f9fafb;border:1px solid #e5e7eb;color:#111;padding:12px;font-weight:600;text-decoration:none;border-radius:8px;font-size:13px;text-align:center;'>View Schedule</a>
                    </td>
                    <td width='50%' style='padding:0 0 0 4px;'>
                        <a href='{$earn_url}' style='display:block;background:#f9fafb;border:1px solid #e5e7eb;color:#111;padding:12px;font-weight:600;text-decoration:none;border-radius:8px;font-size:13px;text-align:center;'>View Earnings</a>
                    </td>
                </tr>
            </table>
        ", 'SESSION COMPLETE');

        $subject = "Session complete - {$player} on " . esc_html($date);
        $sent = wp_mail($user->user_email, $subject, $html, array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_training'),
        ));

        if ($sent) {
            set_transient('ptp_trainer_postsession_' . $booking_id, true, 7 * DAY_IN_SECONDS);

            // Schedule recap reminders
            $end_ts = strtotime($b->session_date . ' ' . ($b->end_time ?: '17:00:00'));
            $now    = time();

            $four_hr = $end_ts + (4 * HOUR_IN_SECONDS);
            if ($four_hr > $now && !wp_next_scheduled('ptp_recap_reminder_4hr', array($booking_id))) {
                wp_schedule_single_event($four_hr, 'ptp_recap_reminder_4hr', array($booking_id));
            }

            $next_10am = strtotime('+1 day 10:00:00', strtotime($b->session_date));
            if ($next_10am > $now && !wp_next_scheduled('ptp_recap_reminder_24hr', array($booking_id))) {
                wp_schedule_single_event($next_10am, 'ptp_recap_reminder_24hr', array($booking_id));
            }
        }
    }


    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function has_recap($booking_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_session_notes WHERE booking_id = %d", $booking_id
        ));
    }

    private static function email_wrap($inner, $badge = '') {
        $co = function_exists('ptp_email_brand') ? esc_html(ptp_email_brand('company')) : 'PTP';
        $hdr = $badge
            ? "<tr><td style='background:#0A0A0A;padding:20px 24px;text-align:center;border-radius:12px 12px 0 0;'>
                 <span style='font-size:11px;font-weight:700;color:#FCB900;letter-spacing:2px;text-transform:uppercase;'>" . esc_html($badge) . "</span>
               </td></tr>"
            : "";
        $br = $badge ? "border-top:none;" : "border-radius:12px 12px 0 0;";

        return "<!DOCTYPE html><html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1.0'></head>
<body style='margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f3f4f6;padding:32px 16px;'>
<tr><td align='center'>
<table width='520' cellpadding='0' cellspacing='0' style='max-width:520px;width:100%;'>
    {$hdr}
    <tr><td style='background:#fff;padding:32px 24px;border:1px solid #e5e7eb;{$br}'>
        {$inner}
    </td></tr>
    <tr><td style='padding:16px;text-align:center;'>
        <p style='margin:0;font-size:12px;color:#9CA3AF;'>{$co}</p>
    </td></tr>
</table>
</td></tr></table></body></html>";
    }
}

add_action('plugins_loaded', array('PTP_Email_Flow_Fix', 'init'), 20);
