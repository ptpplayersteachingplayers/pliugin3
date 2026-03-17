<?php
/**
 * PTP Mentorship Notifications v2 — Unified Orchestrator
 * 
 * REPLACES the fragmented notification system spread across:
 *   - PTP_Mentorship_Touchpoints (post-camp email/SMS)
 *   - PTP_Camp_Conversion (DUPLICATE post-camp email/SMS)
 *   - PTP_Notifications::mentorship_* (lifecycle emails)
 *   - PTP_Mentorship_Sessions::send_pre_session_reminder
 *
 * This class is the SINGLE source of truth for all mentorship comms.
 * 
 * INTEGRATIONS:
 *   - Command Center: CC_DB::log() for activity, CC_DB::send_sms() for SMS
 *   - Camps plugin: ptp_camp_order_completed → post-camp funnel
 *   - Training Platform: PTP_Email_Templates, PTP_SMS
 * 
 * FIXES:
 *   1. Double-send: Touchpoints + Camp Conversion both scheduling Day 2/5/10
 *   2. Dead hook: ptp_mentorship_training_upsell fires but nobody listens
 *   3. No SMS for lifecycle events (session scheduled, recap, payment failed)
 *   4. No purchase confirmation to parent
 *   5. No session-starting-soon reminder (only 24hr existed)
 *   6. No trainer-response escalation (48hr interest timeout)
 *   7. No missed session handling
 *   8. No win-back after cancellation
 *   9. Wrong dashboard links (/dashboard/ → /parent-dashboard/)
 *  10. Two different email template systems
 *  11. Source attribution lost on some CTA links
 * 
 * @since v137
 */
defined('ABSPATH') || exit;

class PTP_Mentorship_Notifications_V2 {

    // ================================================================
    // CONSTANTS
    // ================================================================
    const PARENT_DASH   = '/parent-dashboard/';
    const TRAINER_DASH  = '/trainer-dashboard/';
    const MENTORSHIP_LP = '/mentorship/';
    const SIGNUP_PATH   = '/mentorship-signup/';
    const CHECKOUT_PATH = '/mentorship-checkout/';

    // ================================================================
    // INIT — Register all hooks, disable legacy duplicates
    // ================================================================
    public static function init() {
        // ── Disable duplicate Camp Conversion listeners ──
        // Camp Conversion's schedule_post_camp_sequence would double-send Day 2/5/10
        if (class_exists('PTP_Camp_Conversion')) {
            remove_action('ptp_mentorship_post_camp_outreach', array('PTP_Camp_Conversion', 'schedule_post_camp_sequence'), 10);
            // Keep: render_thankyou_mentorship_cta (thank-you page CTA)
            // Keep: maybe_add_mentorship_ps_to_confirmation (P.S. in camp confirmation)
        }

        // ── Disable legacy Touchpoints listeners (we replace all of them) ──
        if (class_exists('PTP_Mentorship_Touchpoints')) {
            remove_action('ptp_mentorship_post_camp_outreach', array('PTP_Mentorship_Touchpoints', 'handle_post_camp_outreach'), 10);
            remove_action('ptp_mentorship_midcamp_sms', array('PTP_Mentorship_Touchpoints', 'send_midcamp_sms'), 10);
            remove_action('ptp_training_session_completed', array('PTP_Mentorship_Touchpoints', 'handle_training_touchpoint'), 20);
            // Also remove from correct hook in case someone patched it
            remove_action('ptp_session_completed', array('PTP_Mentorship_Touchpoints', 'handle_training_touchpoint'), 20);
            remove_action('ptp_booking_completed', array('PTP_Mentorship_Touchpoints', 'handle_training_touchpoint'), 20);
            remove_action('ptp_mentorship_touch_day2', array('PTP_Mentorship_Touchpoints', 'send_day2_email'), 10);
            remove_action('ptp_mentorship_touch_day5', array('PTP_Mentorship_Touchpoints', 'send_day5_sms'), 10);
            remove_action('ptp_mentorship_touch_day10', array('PTP_Mentorship_Touchpoints', 'send_day10_email'), 10);
            remove_action('ptp_mentorship_free_session_followup', array('PTP_Mentorship_Touchpoints', 'send_free_session_followup'), 10);
        }

        // ── Disable legacy Notifications mentorship methods ──
        // The orchestrator in PTP_Mentorship::register_notification_hooks() registers these.
        // We'll re-register them ourselves pointing to our unified handlers.
        $legacy_hooks = array(
            'ptp_mentorship_interest_submitted'  => 'mentorship_interest_submitted',
            'ptp_mentorship_intro_completed'     => 'mentorship_intro_completed',
            'ptp_mentorship_package_purchased'   => 'mentorship_package_purchased',
            'ptp_mentorship_video_submitted'     => 'mentorship_video_submitted',
            'ptp_mentorship_video_reviewed'      => 'mentorship_video_reviewed',
            'ptp_mentorship_session_scheduled'   => 'mentorship_session_scheduled',
            'ptp_mentorship_cancelled'           => 'mentorship_cancelled',
            'ptp_mentorship_package_completed'   => 'mentorship_package_completed',
            'ptp_mentorship_package_expiring'    => 'mentorship_package_expiring',
            'ptp_mentorship_payment_failed'      => 'mentorship_payment_failed',
        );
        foreach ($legacy_hooks as $action => $method) {
            if (class_exists('PTP_Notifications')) {
                remove_action($action, array('PTP_Notifications', $method));
            }
        }
        if (class_exists('PTP_Notifications')) {
            remove_action('ptp_mentorship_session_recap_ready', array('PTP_Notifications', 'mentorship_session_recap_ready'), 10);
        }

        // ── Disable legacy pre-session reminder ──
        if (class_exists('PTP_Mentorship_Sessions')) {
            remove_action('ptp_mentorship_pre_session_reminder', array('PTP_Mentorship_Sessions', 'send_pre_session_reminder'), 10);
        }

        // ── Disable legacy Pipeline training upsell (fires into void anyway) ──
        if (class_exists('PTP_Mentorship_Pipeline')) {
            remove_action('ptp_training_session_completed', array('PTP_Mentorship_Pipeline', 'inject_training_touchpoint'), 10);
        }

        // ================================================================
        // REGISTER OUR UNIFIED HANDLERS
        // ================================================================

        // ── CONVERSION FUNNEL ──
        add_action('ptp_mentorship_post_camp_outreach',  array(__CLASS__, 'on_post_camp_outreach'), 10, 1);
        add_action('ptp_mentorship_midcamp_sms',         array(__CLASS__, 'on_midcamp_sms'), 10, 1);
        // FIXED: was ptp_training_session_completed (never fires). Actual hooks from escrow/booking:
        add_action('ptp_session_completed',              array(__CLASS__, 'on_training_session_completed'), 20, 2);
        add_action('ptp_booking_completed',              array(__CLASS__, 'on_training_session_completed'), 20, 2); // backup
        add_action('ptp_mentorship_training_upsell',     array(__CLASS__, 'on_training_upsell'), 10, 1); // was dead hook

        // Scheduled conversion touches (cron)
        add_action('ptp_mn2_day2_email',   array(__CLASS__, 'send_day2_email'), 10, 1);
        add_action('ptp_mn2_day5_sms',     array(__CLASS__, 'send_day5_sms'), 10, 1);
        add_action('ptp_mn2_day10_email',  array(__CLASS__, 'send_day10_email'), 10, 1);
        add_action('ptp_mn2_free_followup', array(__CLASS__, 'send_free_session_followup'), 10, 1);

        // ── LIFECYCLE EVENTS ──
        add_action('ptp_mentorship_interest_submitted',   array(__CLASS__, 'on_interest_submitted'), 10, 2);
        add_action('ptp_mentorship_intro_completed',      array(__CLASS__, 'on_intro_completed'), 10, 2);
        add_action('ptp_mentorship_package_purchased',    array(__CLASS__, 'on_package_purchased'), 10, 2);
        add_action('ptp_mentorship_session_scheduled',    array(__CLASS__, 'on_session_scheduled'), 10, 1);
        add_action('ptp_mentorship_session_recap_ready',  array(__CLASS__, 'on_session_recap'), 10, 2);
        add_action('ptp_mentorship_video_submitted',      array(__CLASS__, 'on_video_submitted'), 10, 1);
        add_action('ptp_mentorship_video_reviewed',       array(__CLASS__, 'on_video_reviewed'), 10, 1);
        add_action('ptp_mentorship_package_expiring',     array(__CLASS__, 'on_package_expiring'), 10, 1);
        add_action('ptp_mentorship_package_completed',    array(__CLASS__, 'on_package_completed'), 10, 1);
        add_action('ptp_mentorship_cancelled',            array(__CLASS__, 'on_cancelled'), 10, 1);
        add_action('ptp_mentorship_payment_failed',       array(__CLASS__, 'on_payment_failed'), 10, 1);

        // ── NEW: Pre-session reminders (24hr + 1hr) ──
        add_action('ptp_mentorship_pre_session_reminder', array(__CLASS__, 'send_pre_session_24hr'), 10, 3);
        add_action('ptp_mn2_session_1hr_reminder',        array(__CLASS__, 'send_session_1hr_reminder'), 10, 2);

        // ── NEW: Escalation cron ──
        add_action('ptp_mn2_check_escalations', array(__CLASS__, 'check_escalations'));
        if (!wp_next_scheduled('ptp_mn2_check_escalations')) {
            wp_schedule_event(time() + 3600, 'hourly', 'ptp_mn2_check_escalations');
        }

        // ── NEW: Win-back sequence after cancellation ──
        add_action('ptp_mn2_winback_day7',  array(__CLASS__, 'send_winback_day7'), 10, 1);
        add_action('ptp_mn2_winback_day14', array(__CLASS__, 'send_winback_day14'), 10, 1);

        // ── FIX: Bridge legacy cron hooks to V2 ──
        // In-flight cron events scheduled before V2 deployment use old hook names.
        // Without these bridges, any parent mid-sequence loses remaining touches.
        add_action('ptp_mentorship_touch_day2',  array(__CLASS__, 'send_day2_email'), 10, 1);
        add_action('ptp_mentorship_touch_day5',  array(__CLASS__, 'send_day5_sms'), 10, 1);
        add_action('ptp_mentorship_touch_day10', array(__CLASS__, 'send_day10_email'), 10, 1);
        add_action('ptp_mentorship_free_session_followup', array(__CLASS__, 'send_free_session_followup'), 10, 1);
        // Camp Conversion legacy hooks (in case that class was ever wired up)
        add_action('ptp_camp_conversion_day2_email',  array(__CLASS__, 'send_day2_email'), 10, 1);
        add_action('ptp_camp_conversion_day5_sms',    array(__CLASS__, 'send_day5_sms'), 10, 1);
        add_action('ptp_camp_conversion_day10_email', array(__CLASS__, 'send_day10_email'), 10, 1);

        // v233 M7: Unsubscribe handler
        add_action('init', array(__CLASS__, 'handle_unsubscribe'));
    }

    // ================================================================
    // v233 M7: UNSUBSCRIBE MECHANISM
    // ================================================================
    public static function handle_unsubscribe() {
        if (!isset($_GET['ptp_unsub'])) return;

        $email = sanitize_email($_GET['ptp_email'] ?? '');
        $token = sanitize_text_field($_GET['ptp_unsub']);

        if (!$email || !wp_verify_nonce($token, 'ptp_unsub_' . $email)) {
            // Invalid token — show generic unsubscribe page
            if (trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/') === 'unsubscribe') {
                nocache_headers();
                echo '<html><body style="font-family:Inter,sans-serif;max-width:480px;margin:80px auto;text-align:center">';
                echo '<h1 style="font-family:Oswald,sans-serif">Unsubscribe</h1>';
                echo '<p>To unsubscribe from PTP marketing emails, please contact us at <a href="mailto:luke@ptpsummercamps.com">luke@ptpsummercamps.com</a> or call <a href="tel:6106714778">(610) 671-4778</a>.</p>';
                echo '</body></html>';
                exit;
            }
            return;
        }

        // Store opt-out in user meta or options
        $user = get_user_by('email', $email);
        if ($user) {
            update_user_meta($user->ID, 'ptp_mentorship_optout', 1);
            update_user_meta($user->ID, 'ptp_mentorship_optout_at', current_time('mysql'));
        }
        // Also store in options for non-registered emails
        $optouts = get_option('ptp_mentorship_optouts', array());
        $optouts[$email] = current_time('mysql');
        update_option('ptp_mentorship_optouts', $optouts, false);

        nocache_headers();
        echo '<html><body style="font-family:Inter,sans-serif;max-width:480px;margin:80px auto;text-align:center">';
        echo '<h1 style="font-family:Oswald,sans-serif">Unsubscribed</h1>';
        echo '<p>You\'ve been removed from PTP mentorship marketing emails. You\'ll still receive transactional emails about your active sessions and bookings.</p>';
        echo '<p><a href="' . esc_url(home_url('/')) . '">Return to PTP</a></p>';
        echo '</body></html>';
        exit;
    }

    /**
     * v233 M7: Check if email has opted out of marketing
     */
    public static function is_opted_out($email) {
        if (!$email) return false;

        // Check user meta
        $user = get_user_by('email', $email);
        if ($user && get_user_meta($user->ID, 'ptp_mentorship_optout', true)) {
            return true;
        }

        // Check options-based optout list
        $optouts = get_option('ptp_mentorship_optouts', array());
        return isset($optouts[$email]);
    }

    /**
     * v233 M7: Generate signed unsubscribe URL for an email
     */
    public static function unsub_url($email) {
        return add_query_arg(array(
            'ptp_unsub'  => wp_create_nonce('ptp_unsub_' . $email),
            'ptp_email'  => rawurlencode($email),
        ), home_url('/unsubscribe/'));
    }


    // ================================================================
    // 
    //  CONVERSION FUNNEL
    // 
    // ================================================================

    /**
     * Post-camp outreach — schedule Day 2/5/10 sequence (SINGLE source)
     * Fires from: PTP_Mentorship_Pipeline::trigger_post_camp_sequence
     */
    public static function on_post_camp_outreach($data) {
        $email = $data['parent_email'] ?? '';
        $trainer_id = intval($data['trainer_id'] ?? 0);
        $camp_id = intval($data['camp_id'] ?? 0);
        if (!$email || !$trainer_id) return;

        // Dedup — single key prevents both old and new from double-scheduling
        $key = 'ptp_mn2_postcamp_' . md5($email . $trainer_id . $camp_id);
        if (get_transient($key)) return;
        set_transient($key, 1, 15 * DAY_IN_SECONDS);

        $touch = array(
            'email'        => $email,
            'parent_name'  => $data['parent_name'] ?? '',
            'player_name'  => $data['player_name'] ?? '',
            'trainer_id'   => $trainer_id,
            'trainer_name' => $data['trainer_name'] ?? '',
            'camp_id'      => $camp_id,
            'phone'        => self::get_parent_phone($email),
            'source'       => 'post_camp',
        );

        $now = time();
        wp_schedule_single_event($now + (2 * DAY_IN_SECONDS),  'ptp_mn2_day2_email',  array($touch));
        wp_schedule_single_event($now + (5 * DAY_IN_SECONDS),  'ptp_mn2_day5_sms',    array($touch));
        wp_schedule_single_event($now + (10 * DAY_IN_SECONDS), 'ptp_mn2_day10_email', array($touch));

        self::cc_log('postcamp_sequence_scheduled', 'mentorship', $camp_id, 
            "Post-camp sequence for {$email}, trainer #{$trainer_id}");
    }

    /** Day 2: Camp recap + mentorship CTA */
    public static function send_day2_email($data) {
        if (self::already_enrolled($data['email'], $data['trainer_id'])) return;

        $trainer = self::get_trainer($data['trainer_id']);
        if (!$trainer) return;

        $first  = self::first_name($data['trainer_name']);
        $player = $data['player_name'] ?: 'your player';
        $spots  = self::spots_left($trainer);
        $signup = self::signup_url($trainer->id, 'post_camp_day2');

        $subject = "{$player}'s week with {$first} — and what comes next";
        $body = self::build_email(
            'Camp Week Recap',
            "Hey " . self::first_name($data['parent_name'] ?: 'there') . ",",
            "{$player} had an awesome week training with {$first}. The energy, the reps, the competitive games — that's the " . ptp_email_brand('company') . " experience.\n\n"
            . "But here's what most parents don't realize: <strong>the camp is just the beginning</strong>.\n\n"
            . "{$first} offers weekly 1-on-1 video mentorship — same coach, same relationship, year-round. Weekly video calls, film review, goal tracking, and real accountability."
            . ($spots <= 5 ? "\n\n<strong>{$first} has {$spots} mentorship spot" . ($spots !== 1 ? 's' : '') . " left.</strong>" : ''),
            'Free Intro Call with ' . $first,
            $signup,
            'No payment until after the intro call. Just a 15-minute video chat so ' . $player . ' and ' . $first . ' can reconnect.'
        );

        self::send_email($data['email'], $subject, $body, true); // v233 M7: marketing
        self::cc_log('postcamp_day2_email', 'mentorship', $data['camp_id'] ?? 0, "Day 2 email to {$data['email']}");
    }

    /** Day 5: Urgency SMS */
    public static function send_day5_sms($data) {
        if (self::already_enrolled($data['email'], $data['trainer_id'])) return;
        if (empty($data['phone'])) return;

        $trainer = self::get_trainer($data['trainer_id']);
        if (!$trainer) return;

        $first  = self::first_name($data['trainer_name']);
        $player = $data['player_name'] ?: 'Your player';
        $spots  = self::spots_left($trainer);

        $msg = "{$player} trained with {$first} at camp last week.";
        if ($spots <= 5) $msg .= " {$first} has {$spots} spot" . ($spots !== 1 ? 's' : '') . " left.";
        $msg .= " Want weekly video calls with {$first}? Free intro call: " . home_url(self::MENTORSHIP_LP);

        self::send_sms($data['phone'], $msg);
        self::cc_log('postcamp_day5_sms', 'mentorship', $data['camp_id'] ?? 0, "Day 5 SMS to {$data['phone']}");
    }

    /** Day 10: Last chance email */
    public static function send_day10_email($data) {
        if (self::already_enrolled($data['email'], $data['trainer_id'])) return;

        $trainer = self::get_trainer($data['trainer_id']);
        if (!$trainer) return;

        $first  = self::first_name($data['trainer_name']);
        $player = $data['player_name'] ?: 'your player';
        $spots  = self::spots_left($trainer);
        $signup = self::signup_url($trainer->id, 'post_camp_day10');

        $subject = "Last call — {$first}'s mentorship spots are filling up";
        $body = self::build_email(
            'Spots Are Going',
            "Hey " . self::first_name($data['parent_name'] ?: 'there') . ",",
            "Quick follow-up. {$first}'s mentorship spots are almost full."
            . ($spots <= 5 ? " <strong>Only {$spots} left.</strong>" : '')
            . "\n\nOnce they're gone, it's waitlist only."
            . "\n\nIf {$player} clicked with {$first} at camp, this is the way to keep that going — weekly video calls, real goals, real accountability.",
            'Grab a Spot Before They\'re Gone',
            $signup,
            'Free intro call. No payment until you\'re ready.'
        );

        self::send_email($data['email'], $subject, $body, true); // v233 M7: marketing
        self::cc_log('postcamp_day10_email', 'mentorship', $data['camp_id'] ?? 0, "Day 10 email to {$data['email']}");
    }

    /** Mid-camp Day 3 SMS */
    public static function on_midcamp_sms($data) {
        $phone = $data['phone'] ?? '';
        $trainer_id = intval($data['trainer_id'] ?? 0);
        if (!$phone || !$trainer_id) return;

        $trainer = self::get_trainer($trainer_id);
        if (!$trainer || empty($trainer->mentorship_enabled)) return;
        if (self::already_enrolled_by_phone($phone, $trainer_id)) return;

        $first  = self::first_name($trainer->display_name ?? 'Your coach');
        $player = $data['player_name'] ?? 'Your player';
        $parent = $data['parent_name'] ?? '';

        $msg = 'Hey' . ($parent ? ' ' . self::first_name($parent) : '') . ' — ' 
             . $first . ' says ' . $player . ' is doing great this week! Did you know ' 
             . $first . ' also does weekly 1-on-1 video mentorship? Free intro call: ' 
             . home_url(self::MENTORSHIP_LP);

        self::send_sms($phone, $msg);
        self::cc_log('midcamp_sms', 'mentorship', $trainer_id, "Mid-camp SMS to {$phone}");
    }

    /** Free session accepted → schedule 24hr follow-up */
    public static function on_free_session_accepted($app_id, $app_data = null, $trainer_slug = '') {
        if (!$app_data) return;
        global $wpdb;

        $app = is_object($app_data) ? $app_data : $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_session_applications WHERE id = %d", $app_id
        ));
        if (!$app) return;

        $email      = $app->parent_email ?? $app->email ?? '';
        $trainer_id = intval($app->assigned_trainer ?? $app->trainer_id ?? 0);
        if (!$email || !$trainer_id) return;

        $trainer = self::get_trainer($trainer_id);
        if (!$trainer || empty($trainer->mentorship_enabled)) return;
        if (self::already_enrolled($email, $trainer_id)) return;

        $key = 'ptp_mn2_freesesh_' . md5($email . $trainer_id);
        if (get_transient($key)) return;
        set_transient($key, 1, 10 * DAY_IN_SECONDS);

        $touch = array(
            'email'        => $email,
            'phone'        => $app->phone ?? '',
            'player_name'  => $app->child_name ?? '',
            'parent_name'  => $app->parent_name ?? '',
            'trainer_id'   => $trainer_id,
            'trainer_name' => $trainer->display_name,
            'source'       => 'free_session',
        );

        wp_schedule_single_event(time() + DAY_IN_SECONDS, 'ptp_mn2_free_followup', array($touch));
        self::cc_log('free_session_followup_scheduled', 'application', $app_id, "Follow-up for {$email}");
    }

    /** Free session follow-up: SMS + Email */
    public static function send_free_session_followup($data) {
        if (self::already_enrolled($data['email'], $data['trainer_id'])) return;

        $trainer = self::get_trainer($data['trainer_id']);
        if (!$trainer) return;

        $first  = self::first_name($data['trainer_name']);
        $player = $data['player_name'] ?: 'your player';
        $signup = self::signup_url($trainer->id, 'free_session_followup');

        // SMS
        if (!empty($data['phone'])) {
            $sms = "How'd {$player}'s session with {$first} go? Want to keep it going? {$first} offers weekly video mentorship — free intro call: " . home_url(self::MENTORSHIP_LP);
            self::send_sms($data['phone'], $sms);
        }

        // Email
        $subject = "{$player}'s session with {$first} — want to keep going?";
        $body = self::build_email(
            'Keep the Momentum',
            "Hey " . self::first_name($data['parent_name'] ?: 'there') . ",",
            "Hope {$player}'s session with {$first} went well!\n\n"
            . "If {$player} clicked with {$first}, there's a way to keep that relationship going year-round: <strong>weekly video mentorship</strong>.\n\n"
            . "Weekly video calls, film review, goal tracking, and an action item every week. {$first} becomes {$player}'s personal coach — the same person they already know and trust.",
            'Book a Free Intro Call',
            $signup,
            'It starts with a free 15-minute video call. No payment, no commitment.'
        );

        self::send_email($data['email'], $subject, $body, true); // v233 M7: marketing
        self::cc_log('free_session_followup_sent', 'mentorship', 0, "Follow-up to {$data['email']}");
    }

    /** Training session (2nd+) → mentorship suggestion */
    public static function on_training_session_completed($booking_id, $booking_data = null) {
        // Dedup: both ptp_session_completed and ptp_booking_completed fire for the same booking
        static $processed_bookings = array();
        if (isset($processed_bookings[$booking_id])) return;
        $processed_bookings[$booking_id] = true;

        // ptp_session_completed passes ($booking_id, $booking_object)
        // Handle both object and array formats
        if (is_object($booking_data)) {
            $trainer_id = intval($booking_data->trainer_id ?? 0);
            $parent_id  = intval($booking_data->parent_id ?? 0);
        } elseif (is_array($booking_data)) {
            $trainer_id = intval($booking_data['trainer_id'] ?? 0);
            $parent_id  = intval($booking_data['parent_id'] ?? 0);
        } else {
            // Fallback: look up from booking ID
            global $wpdb;
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT trainer_id, parent_id FROM {$wpdb->prefix}ptp_bookings WHERE id = %d", $booking_id
            ));
            if (!$booking) return;
            $trainer_id = intval($booking->trainer_id);
            $parent_id  = intval($booking->parent_id);
        }
        if (!$trainer_id || !$parent_id) return;

        global $wpdb;

        $session_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND parent_id = %d AND status IN ('completed','confirmed')",
            $trainer_id, $parent_id
        )));
        if ($session_count < 2) return;

        $trainer = self::get_trainer($trainer_id);
        if (!$trainer || empty($trainer->mentorship_enabled)) return;

        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT email, first_name, phone FROM {$wpdb->prefix}ptp_parents WHERE id = %d", $parent_id
        ));
        if (!$parent || !$parent->email) return;
        if (self::already_enrolled($parent->email, $trainer_id)) return;

        $key = 'ptp_mn2_trtouch_' . md5($parent->email . $trainer_id);
        if (get_transient($key)) return;
        set_transient($key, 1, 30 * DAY_IN_SECONDS);

        $first  = self::first_name($trainer->display_name);
        $signup = self::signup_url($trainer->id, 'training_upsell');

        // Email
        $subject = "{$first} + your player — ready for the next level?";
        $body = self::build_email(
            'Next Level',
            "Hey " . ($parent->first_name ?: 'there') . ",",
            "Your player has done {$session_count} sessions with {$first}. That's a real relationship forming.\n\n"
            . "If you want to take it further, {$first} offers <strong>weekly video mentorship</strong> — structured calls, film review, goal tracking, and a clear action item every week.\n\n"
            . "It's the difference between occasional training and real, compounding growth.",
            "Free Intro Call with {$first}",
            $signup
        );
        self::send_email($parent->email, $subject, $body, true); // v233 M7: marketing

        // SMS if available
        if (!empty($parent->phone)) {
            $sms = "Your player has done {$session_count} sessions with {$first}. Want to go weekly? {$first} offers video mentorship — free intro call: " . home_url(self::MENTORSHIP_LP);
            self::send_sms($parent->phone, $sms);
        }

        self::cc_log('training_upsell_sent', 'mentorship', $booking_id, "Upsell after session #{$session_count} to {$parent->email}");
    }

    /** Training upsell hook (was dead — now connected) */
    public static function on_training_upsell($data) {
        // Pipeline already validated eligibility. Just log to CC.
        self::cc_log('training_upsell_triggered', 'mentorship', $data['parent_id'] ?? 0,
            "Pipeline upsell: trainer #{$data['trainer_id']}, {$data['sessions']} sessions");
    }


    // ================================================================
    // 
    //  LIFECYCLE EVENTS
    // 
    // ================================================================

    /** Interest submitted — notify trainer + confirm parent + schedule escalation */
    public static function on_interest_submitted($pair_id, $data = null) {
        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;

        $trainer = self::get_trainer($pair->trainer_id);
        if (!$trainer) return;

        $player_name = self::resolve_player_name($pair);
        $parent_email = self::resolve_parent_email($pair);
        $trainer_email = self::resolve_trainer_email($trainer);

        // Notify trainer (email + in-app)
        self::create_notification($trainer->user_id, 'mentorship_interest', 'New Mentorship Interest',
            "{$player_name} is interested in mentorship with you.", array('pair_id' => $pair_id));

        self::send_email($trainer_email, "New Mentorship Interest — {$player_name}", self::build_email(
            'New Mentorship Interest', '',
            "<strong>{$player_name}</strong> has expressed interest in mentorship with you.\n\n"
            . "Goals: " . esc_html($pair->player_goals ?: 'Not specified') . "\n\n"
            . "Log in to your dashboard to review and schedule an intro call.",
            'View in Dashboard', home_url(self::TRAINER_DASH . '?tab=mentorship')
        ));

        // SMS to trainer
        if (!empty($trainer->phone)) {
            self::send_sms($trainer->phone, "New mentorship interest: {$player_name} wants to train with you. Log in to schedule the intro call: " . home_url(self::TRAINER_DASH));
        }

        // Confirm to parent
        if (is_email($parent_email)) {
            self::send_email($parent_email, 'Mentorship Interest Received — ' . ptp_email_brand('company'), self::build_email(
                'Interest Received', '',
                "We received your interest in mentorship for {$player_name} with " . esc_html($trainer->display_name) . ".\n\n"
                . "Your coach will reach out to schedule a free intro call within 48 hours.\n\n"
                . "The intro call is 15 minutes, free, and a chance for {$player_name} to meet their potential mentor."
            ));
        }

        // NEW: Schedule 48hr escalation check
        wp_schedule_single_event(time() + (48 * HOUR_IN_SECONDS), 'ptp_mn2_check_escalations');

        self::cc_log('mentorship_interest', 'mentorship', $pair_id, "Interest submitted: {$player_name}");
    }

    /** Intro completed — send checkout link to parent */
    public static function on_intro_completed($pair_id, $data = null) {
        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;
        $trainer = self::get_trainer($pair->trainer_id);
        if (!$trainer) return;

        $player_name  = self::resolve_player_name($pair);
        $parent_email = self::resolve_parent_email($pair);
        $checkout_url = add_query_arg(array('pair_id' => $pair_id, 'source' => 'intro_complete'), home_url(self::CHECKOUT_PATH));

        if (is_email($parent_email)) {
            self::send_email($parent_email, 'Your Intro Call is Complete — Next Steps', self::build_email(
                'Intro Call Complete', '',
                esc_html($trainer->display_name) . " has confirmed your intro call with {$player_name} is complete.\n\n"
                . "Ready to start? Choose a mentorship package below and your first weekly session will be scheduled automatically.",
                'CHOOSE YOUR PACKAGE',
                $checkout_url,
                'Questions? Reply to this email or message your coach directly in the dashboard.'
            ));

            // SMS with checkout link
            $parent_phone = self::get_parent_phone($parent_email);
            if ($parent_phone) {
                self::send_sms($parent_phone, "Great news! " . self::first_name($trainer->display_name) . " marked your intro call done. Pick your package to get started: {$checkout_url}");
            }
        }

        self::cc_log('mentorship_intro_done', 'mentorship', $pair_id, "Intro completed, checkout link sent");
    }

    /** NEW: Package purchased — confirm to parent (was missing!) + notify trainer */
    public static function on_package_purchased($pair_id, $source = null) {
        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;
        $trainer = self::get_trainer($pair->trainer_id);
        if (!$trainer) return;

        $player_name   = self::resolve_player_name($pair);
        $parent_email  = self::resolve_parent_email($pair);
        $trainer_email = self::resolve_trainer_email($trainer);
        $package       = ucfirst($pair->package_type ?: 'development');

        // Notify trainer
        self::create_notification($trainer->user_id, 'mentorship_purchase', 'New Mentorship Active',
            "{$player_name} has purchased the {$package} package.", array('pair_id' => $pair_id));

        self::send_email($trainer_email, "{$player_name} is now an active mentee", self::build_email(
            'New Mentee Active', '',
            "<strong>{$player_name}</strong> has purchased the <strong>{$package}</strong> package.\n\n"
            . "Sessions included: " . intval($pair->sessions_total) . ". Video reviews: " . intval($pair->video_reviews_remaining ?? 0) . ".\n\n"
            . "Log in to schedule their first session.",
            'Open Dashboard', home_url(self::TRAINER_DASH . '?tab=mentorship')
        ));

        // SMS to trainer
        if (!empty($trainer->phone)) {
            self::send_sms($trainer->phone, "{$player_name} just purchased {$package} mentorship! Log in to schedule their first session: " . home_url(self::TRAINER_DASH));
        }

        // NEW: Confirm to parent (was missing in old system!)
        if (is_email($parent_email)) {
            $price_map = array('kickstart' => '$49/session', 'development' => '$69/session', 'elite' => '$89/session');
            $price_label = $price_map[strtolower($pair->package_type)] ?? '$' . intval($pair->per_session_price) . '/session';

            $mentorship_code = 'PTP-M-' . str_pad($pair_id, 5, '0', STR_PAD_LEFT);

            self::send_email($parent_email, "You're in! Mentorship confirmed for {$player_name}", self::build_email(
                'Welcome to Mentorship', '',
                "You're officially set up with <strong>" . esc_html($trainer->display_name) . "</strong> for {$player_name}.\n\n"
                . "<strong>Booking Code:</strong> {$mentorship_code}\n"
                . "<strong>Package:</strong> {$package}\n"
                . "<strong>Price:</strong> {$price_label} (billed weekly)\n"
                . "<strong>Sessions:</strong> " . intval($pair->sessions_total) . " total\n"
                . "<strong>Video Reviews:</strong> " . intval($pair->video_reviews_remaining ?? 0) . "\n\n"
                . "Save your booking code <strong>{$mentorship_code}</strong> for your records.\n\n"
                . esc_html($trainer->display_name) . " will schedule your first session within the next few days. You'll get an email with the date, time, and Zoom link.",
                'View Your Dashboard',
                home_url(self::PARENT_DASH . '#mentorship'),
                'Questions anytime? Reply to this email.'
            ));

            $parent_phone = self::get_parent_phone($parent_email);
            if ($parent_phone) {
                self::send_sms($parent_phone, "Welcome to " . ptp_email_brand('company') . " Mentorship! {$player_name} is set up with " . self::first_name($trainer->display_name) . " ({$package}). First session coming soon.");
            }
        }

        self::cc_log('mentorship_purchased', 'mentorship', $pair_id, "Package purchased: {$package}");
    }

    /** Session scheduled — email + SMS to parent, schedule 24hr + 1hr reminders */
    public static function on_session_scheduled($session_id) {
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id=%d", $session_id));
        if (!$session) return;
        $trainer = self::get_trainer($session->trainer_id);
        if (!$trainer) return;

        $attendees = $wpdb->get_results($wpdb->prepare(
            "SELECT sa.pair_id, mp.parent_id, mp.parent_email, mp.player_id
             FROM {$wpdb->prefix}ptp_mentorship_session_attendees sa
             JOIN {$wpdb->prefix}ptp_mentorship_pairs mp ON sa.pair_id = mp.id
             WHERE sa.session_id = %d", $session_id
        ));

        $dt       = new DateTime($session->scheduled_at, new DateTimeZone('America/New_York'));
        $date_str = $dt->format('l, F j');
        $time_str = $dt->format('g:i A') . ' ET';
        $join_url = $session->meeting_url ?: '';
        $type_lbl = ucfirst(str_replace('_', ' ', $session->session_type));

        foreach ($attendees as $a) {
            $parent_email = self::resolve_email_from_attendee($a);
            if (!is_email($parent_email)) continue;

            // In-app notification
            if ($a->parent_id) {
                self::create_notification($a->parent_id, 'session_scheduled', 'Session Scheduled',
                    "{$type_lbl} session with {$trainer->display_name} on {$date_str} at {$time_str}",
                    array('session_id' => $session_id));
            }

            // Email
            self::send_email($parent_email, "{$type_lbl} Scheduled — {$date_str}", self::build_email(
                "{$type_lbl} Confirmed", '',
                esc_html($trainer->display_name) . " has scheduled a <strong>{$type_lbl}</strong>.\n\n"
                . "<strong>Date:</strong> {$date_str}\n"
                . "<strong>Time:</strong> {$time_str}\n"
                . ($join_url ? "<strong>Join:</strong> <a href=\"" . esc_url($join_url) . "\">" . esc_html($join_url) . "</a>\n\n" : "\n")
                . "Add it to your calendar so your player is ready on time."
            ));

            // SMS with join link
            $parent_phone = self::get_parent_phone($parent_email);
            if ($parent_phone) {
                $sms = "Mentorship session with " . self::first_name($trainer->display_name) . " confirmed: {$date_str} at {$time_str}.";
                if ($join_url) $sms .= " Join: {$join_url}";
                self::send_sms($parent_phone, $sms);
            }

            // Schedule 24hr reminder
            $remind_24 = $dt->getTimestamp() - 86400;
            if ($remind_24 > time()) {
                wp_schedule_single_event($remind_24, 'ptp_mentorship_pre_session_reminder', array($session_id, $a->pair_id, $parent_email));
            }

            // NEW: Schedule 1hr reminder
            $remind_1hr = $dt->getTimestamp() - 3600;
            if ($remind_1hr > time()) {
                wp_schedule_single_event($remind_1hr, 'ptp_mn2_session_1hr_reminder', array($session_id, $parent_email));
            }
        }

        self::cc_log('mentorship_session_scheduled', 'mentorship', $session_id, "Session scheduled: {$date_str} {$time_str}");
    }

    /** 24hr pre-session reminder */
    public static function send_pre_session_24hr($session_id, $pair_id, $parent_email) {
        if (!is_email($parent_email)) return;
        global $wpdb;

        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id=%d", $session_id));
        $pair    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        $trainer = $pair ? self::get_trainer($pair->trainer_id) : null;
        if (!$session || !$pair || !$trainer) return;

        // Skip if session was cancelled/rescheduled
        if (!in_array($session->status, array('scheduled', 'confirmed'))) return;

        $dt       = new DateTime($session->scheduled_at, new DateTimeZone('America/New_York'));
        $time_str = $dt->format('g:i A') . ' ET tomorrow';
        $join_url = $session->meeting_url ?: '';
        $note_url = home_url(self::PARENT_DASH . '#mentorship');

        self::send_email($parent_email, 'Session Tomorrow — Quick Note for ' . $trainer->display_name, self::build_email(
            'Your Session is Tomorrow', '',
            "Your session with <strong>" . esc_html($trainer->display_name) . "</strong> is at <strong>{$time_str}</strong>.\n\n"
            . "One thing that would help: tell " . esc_html($trainer->display_name) . " one thing your player has been working on since the last call — or anything they should know going into today's session.",
            'Submit a Note', $note_url,
            $join_url ? "Join link for tomorrow: <a href=\"" . esc_url($join_url) . "\">" . esc_html($join_url) . "</a>" : ''
        ));
    }

    /** NEW: 1hr pre-session reminder (SMS only) */
    public static function send_session_1hr_reminder($session_id, $parent_email) {
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id=%d", $session_id));
        if (!$session || !in_array($session->status, array('scheduled', 'confirmed'))) return;

        $trainer = self::get_trainer($session->trainer_id);
        if (!$trainer) return;

        $join_url = $session->meeting_url ?: '';
        $phone    = self::get_parent_phone($parent_email);

        if ($phone) {
            $sms = "Reminder: Mentorship session with " . self::first_name($trainer->display_name) . " starts in 1 hour!";
            if ($join_url) $sms .= " Join: {$join_url}";
            self::send_sms($phone, $sms);
        }
    }

    /** Session recap — send to parent with notes, action item, energy rating */
    public static function on_session_recap($session_id, $pair_id) {
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id=%d", $session_id));
        if (!$session || empty($session->parent_summary)) return;

        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;

        $trainer      = self::get_trainer($pair->trainer_id);
        $trainer_name = $trainer ? $trainer->display_name : 'Your Mentor';
        $player_name  = self::resolve_player_name($pair);
        $parent_email = self::resolve_parent_email($pair);
        $session_num  = $session->session_number ?: ($pair->sessions_completed ?: 1);
        $total        = $pair->sessions_total ?: 24;
        $remaining    = max(0, $total - intval($pair->sessions_completed));

        $energy_stars = '';
        if ($session->energy_rating) {
            $energy_stars = str_repeat('&#9733;', intval($session->energy_rating)) . str_repeat('&#9734;', 5 - intval($session->energy_rating));
        }

        $details = "<strong>Session #{$session_num} of {$total} complete.</strong>";
        if ($energy_stars) $details .= "\n<strong>Energy:</strong> {$energy_stars}";
        $details .= "\n\n<strong>What happened this session:</strong>\n" . esc_html($session->parent_summary);
        if ($session->action_item) {
            $details .= "\n\n<strong>This week's mission for {$player_name}:</strong>\n<em>" . esc_html($session->action_item) . "</em>";
        }
        if ($remaining > 0) {
            $details .= "\n\n{$remaining} sessions remaining in this package.";
        }

        if (is_email($parent_email)) {
            self::send_email($parent_email, "Session #{$session_num} recap — {$player_name} & {$trainer_name}", self::build_email(
                "Session #{$session_num} Recap", '', $details,
                'View Dashboard', home_url(self::PARENT_DASH . '#mentorship')
            ));

            // SMS summary
            $phone = self::get_parent_phone($parent_email);
            if ($phone) {
                $action = $session->action_item ? " This week's mission: " . substr($session->action_item, 0, 80) : '';
                self::send_sms($phone, "Session #{$session_num} with " . self::first_name($trainer_name) . " complete!{$action} Full recap in your dashboard.");
            }
        }

        // In-app notification
        if ($pair->parent_id) {
            self::create_notification($pair->parent_id, 'session_recap',
                "Session #{$session_num} recap from {$trainer_name}",
                $session->parent_summary,
                array('pair_id' => $pair_id, 'session_id' => $session_id));
        }

        self::cc_log('mentorship_session_done', 'mentorship', $pair_id, "Session #{$session_num} recap sent");
    }

    /** Video submitted — notify trainer */
    public static function on_video_submitted($video_id) {
        global $wpdb;
        $video = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_videos WHERE id=%d", $video_id));
        if (!$video) return;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $video->pair_id));
        if (!$pair) return;
        $trainer = self::get_trainer($pair->trainer_id);
        if (!$trainer) return;

        $player_name   = self::resolve_player_name($pair);
        $trainer_email = self::resolve_trainer_email($trainer);

        self::create_notification($trainer->user_id, 'mentorship_video', 'New Video Submission',
            "{$player_name} submitted a video for review.", array('video_id' => $video_id, 'pair_id' => $video->pair_id));

        self::send_email($trainer_email, "{$player_name} submitted a video — 48hr review window", self::build_email(
            'Video Submitted for Review', '',
            "<strong>{$player_name}</strong> just submitted a video for your feedback."
            . ($video->player_note ? "\n\nTheir note: <em>" . esc_html($video->player_note) . "</em>" : '')
            . "\n\nPlease review within 48 hours. Log in to your dashboard to watch and respond.",
            'Review Now', home_url(self::TRAINER_DASH . '?tab=mentorship')
        ));

        // SMS to trainer
        if (!empty($trainer->phone)) {
            self::send_sms($trainer->phone, "{$player_name} submitted a video for review. Please review within 48hrs: " . home_url(self::TRAINER_DASH));
        }

        self::cc_log('mentorship_video_submitted', 'mentorship', $video_id, "{$player_name} submitted video");
    }

    /** Video reviewed — notify parent */
    public static function on_video_reviewed($video_id) {
        global $wpdb;
        $video = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_videos WHERE id=%d", $video_id));
        if (!$video) return;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $video->pair_id));
        if (!$pair) return;
        $trainer = self::get_trainer($pair->trainer_id);

        $player_name  = self::resolve_player_name($pair);
        $parent_email = self::resolve_parent_email($pair);
        $trainer_name = $trainer ? $trainer->display_name : 'Your coach';

        if ($pair->parent_id) {
            self::create_notification($pair->parent_id, 'mentorship_feedback', 'Video Feedback Ready',
                "{$trainer_name} reviewed {$player_name}'s video.", array('video_id' => $video_id));
        }

        if (is_email($parent_email)) {
            $feedback_preview = $video->coach_feedback ? "\n\nFeedback: <em>" . esc_html(substr($video->coach_feedback, 0, 200)) . (strlen($video->coach_feedback) > 200 ? '...' : '') . "</em>" : '';

            self::send_email($parent_email, "{$trainer_name} reviewed {$player_name}'s video", self::build_email(
                'Video Feedback Is Ready', '',
                esc_html($trainer_name) . " has reviewed {$player_name}'s video submission." . $feedback_preview
                . "\n\nLog in to see the full feedback and any video response.",
                'View Feedback', home_url(self::PARENT_DASH . '#mentorship')
            ));

            $phone = self::get_parent_phone($parent_email);
            if ($phone) {
                self::send_sms($phone, self::first_name($trainer_name) . " reviewed {$player_name}'s video! Check the feedback in your dashboard: " . home_url(self::PARENT_DASH));
            }
        }

        self::cc_log('mentorship_video_reviewed', 'mentorship', $video_id, "{$trainer_name} reviewed video");
    }

    /** Package expiring — nudge parent + trainer */
    public static function on_package_expiring($pair) {
        if (!$pair || !is_object($pair)) return;
        $trainer = self::get_trainer($pair->trainer_id);
        if (!$trainer) return;

        $player_name  = $pair->player_name ?: 'your player';
        $trainer_name = $trainer->display_name;
        $remaining    = max(0, intval($pair->sessions_total) - intval($pair->sessions_completed));
        $parent_email = self::resolve_parent_email($pair);
        $renew_url    = add_query_arg(array('pair_id' => $pair->id, 'renew' => 1, 'source' => 'expiring_email'), home_url(self::CHECKOUT_PATH));

        // Parent email + SMS
        if (is_email($parent_email)) {
            self::send_email($parent_email, "Only {$remaining} sessions left — {$player_name}", self::build_email(
                "{$remaining} Sessions Remaining", '',
                esc_html($player_name) . " has <strong>{$remaining} session" . ($remaining !== 1 ? 's' : '') . " left</strong> with " . esc_html($trainer_name) . ".\n\n"
                . "When your current package ends, you can renew to keep the momentum going. Your coach already knows {$player_name}'s strengths, goals, and growth areas — starting fresh with someone new means losing all that context.",
                'RENEW PACKAGE', $renew_url
            ));

            $phone = self::get_parent_phone($parent_email);
            if ($phone) {
                self::send_sms($phone, "{$player_name} has {$remaining} mentorship session" . ($remaining !== 1 ? 's' : '') . " left with " . self::first_name($trainer_name) . ". Renew to keep going: {$renew_url}");
            }
        }

        // Trainer nudge
        $trainer_email = self::resolve_trainer_email($trainer);
        self::send_email($trainer_email, "{$player_name} — {$remaining} sessions left", self::build_email(
            'Package Almost Done', '',
            "<strong>{$player_name}</strong> has {$remaining} session" . ($remaining !== 1 ? 's' : '') . " remaining.\n\n"
            . "Now is a great time to talk about continuing. Mention it during your next session — a personal ask converts better than any email.",
            'View Dashboard', home_url(self::TRAINER_DASH . '?tab=mentorship')
        ));

        self::cc_log('mentorship_expiring', 'mentorship', $pair->id, "{$remaining} sessions left for {$player_name}");
    }

    /** Package completed — celebration + renewal CTA */
    public static function on_package_completed($pair_id) {
        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;
        $trainer = self::get_trainer($pair->trainer_id);
        if (!$trainer) return;

        $player_name  = self::resolve_player_name($pair);
        $trainer_name = $trainer->display_name;
        $total        = intval($pair->sessions_total);
        $parent_email = self::resolve_parent_email($pair);
        $renew_url    = add_query_arg(array('pair_id' => $pair_id, 'renew' => 1, 'source' => 'completed_email'), home_url(self::CHECKOUT_PATH));
        $is_single    = $total <= 1;

        if (is_email($parent_email)) {
            $subject = $is_single
                ? "{$player_name}'s session with {$trainer_name} is complete!"
                : "{$player_name} completed all {$total} sessions!";
            $body = $is_single
                ? "<strong>{$player_name}</strong>'s session with {$trainer_name} is done!\n\n"
                  . "Ready for more? Book another single session or upgrade to a weekly package — {$trainer_name} already knows {$player_name} and can pick up right where they left off."
                : "Congratulations! <strong>{$player_name}</strong> has completed all {$total} sessions with {$trainer_name}.\n\n"
                  . "{$trainer_name} knows {$player_name} better than any coach out there now. Keep the momentum going with a new package.";
            $cta = $is_single
                ? 'BOOK ANOTHER SESSION'
                : "CONTINUE WITH " . strtoupper(self::first_name($trainer_name));
            self::send_email($parent_email, $subject, self::build_email(
                $is_single ? 'Session Complete!' : 'Package Complete!', '',
                $body, $cta, $renew_url,
                'Or reply to this email with any questions.'
            ));

            $phone = self::get_parent_phone($parent_email);
            if ($phone) {
                $sms = $is_single
                    ? "{$player_name}'s session with " . self::first_name($trainer_name) . " is done! Book another or upgrade: {$renew_url}"
                    : "Congrats! {$player_name} completed all {$total} sessions with " . self::first_name($trainer_name) . "! Ready to keep going? {$renew_url}";
                self::send_sms($phone, $sms);
            }
        }

        // Trainer
        $trainer_email = self::resolve_trainer_email($trainer);
        $t_body = $is_single
            ? "<strong>{$player_name}</strong>'s single session is complete.\n\n"
              . "We've sent the parent a link to book another session or upgrade to a package. A quick personal text goes a long way."
            : "<strong>{$player_name}</strong> has completed all {$total} sessions.\n\n"
              . "We've sent them a renewal email. If you want them to continue, reach out personally — a quick text goes a long way.";
        self::send_email($trainer_email, ($is_single ? "Session" : "Package") . " Complete — {$player_name}", self::build_email(
            $is_single ? 'Session Complete' : 'Package Complete', '',
            $t_body,
            'View Dashboard', home_url(self::TRAINER_DASH . '?tab=mentorship')
        ));

        self::cc_log('mentorship_package_completed', 'mentorship', $pair_id, ($is_single ? "Single session" : "Package") . " complete: {$total} sessions");
    }

    /** Cancelled — confirm to both + schedule win-back */
    public static function on_cancelled($pair_id) {
        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;
        $trainer = self::get_trainer($pair->trainer_id);
        if (!$trainer) return;

        $player_name  = self::resolve_player_name($pair);
        $trainer_name = $trainer->display_name;
        $completed    = intval($pair->sessions_completed);
        $total        = intval($pair->sessions_total);
        $reason       = $pair->cancel_reason ?: 'No reason provided';
        $parent_email = self::resolve_parent_email($pair);

        // Trainer
        $trainer_email = self::resolve_trainer_email($trainer);
        self::send_email($trainer_email, "Mentorship Cancelled — {$player_name}", self::build_email(
            'Mentorship Cancelled', '',
            "<strong>{$player_name}</strong> has cancelled their mentorship.\n\n"
            . "Sessions completed: {$completed} of {$total}.\n"
            . "<strong>Reason:</strong> " . esc_html($reason) . "\n\n"
            . "Billing will stop at the end of the current billing period."
        ));

        // Parent
        if (is_email($parent_email)) {
            self::send_email($parent_email, 'Mentorship Cancellation Confirmed — ' . ptp_email_brand('company'), self::build_email(
                'Cancellation Confirmed', '',
                "Your mentorship with {$trainer_name} for {$player_name} has been cancelled.\n\n"
                . "You completed {$completed} of {$total} sessions. Billing will stop at the end of the current week.\n\n"
                . "We'd love to have you back anytime. You can restart mentorship from the dashboard.",
                'Explore Mentorship', home_url(self::MENTORSHIP_LP)
            ));
        }

        // NEW: Schedule win-back sequence
        $wb_data = array(
            'pair_id'      => $pair_id,
            'email'        => $parent_email,
            'phone'        => self::get_parent_phone($parent_email),
            'player_name'  => $player_name,
            'trainer_id'   => $pair->trainer_id,
            'trainer_name' => $trainer_name,
            'completed'    => $completed,
        );
        wp_schedule_single_event(time() + (7 * DAY_IN_SECONDS),  'ptp_mn2_winback_day7',  array($wb_data));
        wp_schedule_single_event(time() + (14 * DAY_IN_SECONDS), 'ptp_mn2_winback_day14', array($wb_data));

        self::cc_log('mentorship_cancelled', 'mentorship', $pair_id, "Cancelled: {$completed}/{$total}, reason: {$reason}");
    }

    /** Payment failed — email + SMS */
    public static function on_payment_failed($pair_id) {
        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;

        $parent_email = self::resolve_parent_email($pair);
        $player_name  = self::resolve_player_name($pair);

        if (is_email($parent_email)) {
            self::send_email($parent_email, 'Payment Issue — Mentorship', self::build_email(
                'Payment Failed', '',
                "We had trouble processing your weekly mentorship payment.\n\n"
                . "Please update your payment method to avoid any interruption to {$player_name}'s sessions.",
                'Update Payment', home_url(self::PARENT_DASH . '#mentorship'),
                'If you have any questions, reply to this email.'
            ));

            $phone = self::get_parent_phone($parent_email);
            if ($phone) {
                self::send_sms($phone, ptp_email_brand('company') . ": We had trouble processing your mentorship payment. Please update your payment method to avoid interruption: " . home_url(self::PARENT_DASH));
            }
        }

        self::cc_log('mentorship_payment_failed', 'mentorship', $pair_id, "Payment failed for {$player_name}");
    }


    // ================================================================
    // 
    //  NEW: WIN-BACK SEQUENCE
    // 
    // ================================================================

    /** Day 7 after cancel — "We miss you" email */
    public static function send_winback_day7($data) {
        // Don't send if they re-enrolled
        if (self::already_enrolled($data['email'], $data['trainer_id'])) return;

        $first  = self::first_name($data['trainer_name']);
        $player = $data['player_name'];
        $signup = self::signup_url($data['trainer_id'], 'winback_day7');

        self::send_email($data['email'], "{$first} is still here for {$player}", self::build_email(
            "We Miss You", '',
            "Hey — just checking in. {$player} completed {$data['completed']} sessions with {$first}, and that foundation doesn't go away.\n\n"
            . "If things got busy or the timing wasn't right, {$first} still has a spot open. You can pick right back up where you left off — same coach, same relationship, no starting over.",
            "Restart with {$first}",
            $signup,
            'No pressure at all. Just wanted you to know the door is open.'
        ), true); // v233 M7: marketing

        self::cc_log('winback_day7', 'mentorship', $data['pair_id'], "Win-back Day 7 email sent");
    }

    /** Day 14 after cancel — Final nudge SMS + email */
    public static function send_winback_day14($data) {
        if (self::already_enrolled($data['email'], $data['trainer_id'])) return;

        $first  = self::first_name($data['trainer_name']);
        $signup = self::signup_url($data['trainer_id'], 'winback_day14');

        // SMS only
        if (!empty($data['phone'])) {
            self::send_sms($data['phone'], "Last note about mentorship — {$first} still has a spot if {$data['player_name']} wants to come back. No commitment: {$signup}");
        }

        self::cc_log('winback_day14', 'mentorship', $data['pair_id'], "Win-back Day 14 SMS sent");
    }


    // ================================================================
    // 
    //  NEW: ESCALATION CHECK (runs hourly)
    // 
    // ================================================================

    /**
     * Check for:
     * 1. Interest submitted 48+ hours ago with no intro_scheduled
     * 2. Scheduled sessions that were missed (past + still "scheduled")
     */
    public static function check_escalations() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_mentorship_pairs';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) return;

        // ── 1. Stale interests (48hr+ no response from trainer) ──
        $stale = $wpdb->get_results(
            "SELECT mp.*, t.display_name as trainer_name, t.user_id as trainer_user_id, t.phone as trainer_phone
             FROM {$table} mp
             JOIN {$wpdb->prefix}ptp_trainers t ON mp.trainer_id = t.id
             WHERE mp.status = 'interest'
             AND mp.created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
             AND mp.created_at > DATE_SUB(NOW(), INTERVAL 72 HOUR)
             LIMIT 10"
        );

        foreach ($stale as $pair) {
            $key = 'ptp_mn2_escalate_' . $pair->id;
            if (get_transient($key)) continue;
            set_transient($key, 1, 7 * DAY_IN_SECONDS);

            $player_name = self::resolve_player_name($pair);

            // Nudge trainer via SMS
            if (!empty($pair->trainer_phone)) {
                self::send_sms($pair->trainer_phone, "Reminder: {$player_name} expressed mentorship interest 2 days ago and is waiting for an intro call. Log in to schedule: " . home_url(self::TRAINER_DASH));
            }

            // Notify admin
            $admin_email = get_option('admin_email');
            if ($admin_email) {
                self::send_email($admin_email, "[" . ptp_email_brand('company') . "] Mentorship interest stale — {$player_name}", self::build_email(
                    'Stale Interest Alert', '',
                    "{$player_name} submitted mentorship interest with {$pair->trainer_name} over 48 hours ago, but no intro call has been scheduled.\n\n"
                    . "Pair ID: {$pair->id}\nTrainer: {$pair->trainer_name}\nCreated: {$pair->created_at}",
                    'View in CC', home_url('/wp-admin/')
                ));
            }

            self::cc_log('mentorship_interest_stale', 'mentorship', $pair->id, "48hr no response from {$pair->trainer_name}");
        }

        // ── 2. Missed sessions (scheduled time passed, still "scheduled") ──
        $sess_table = $wpdb->prefix . 'ptp_mentorship_sessions';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$sess_table}'")) return;

        $missed = $wpdb->get_results(
            "SELECT s.id, s.scheduled_at, s.trainer_id, sa.pair_id,
                    mp.parent_email, mp.player_name, mp.parent_id,
                    t.display_name as trainer_name, t.user_id as trainer_user_id
             FROM {$sess_table} s
             JOIN {$wpdb->prefix}ptp_mentorship_session_attendees sa ON s.id = sa.session_id
             JOIN {$table} mp ON sa.pair_id = mp.id
             JOIN {$wpdb->prefix}ptp_trainers t ON s.trainer_id = t.id
             WHERE s.status IN ('scheduled','confirmed')
             AND s.scheduled_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             AND s.scheduled_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             LIMIT 20"
        );

        foreach ($missed as $ms) {
            $key = 'ptp_mn2_missed_' . $ms->id;
            if (get_transient($key)) continue;
            set_transient($key, 1, 3 * DAY_IN_SECONDS);

            $player_name = $ms->player_name ?: 'Mentee';

            // Mark session as missed
            $wpdb->update($sess_table, array('status' => 'missed'), array('id' => $ms->id));

            // Notify trainer
            self::create_notification($ms->trainer_user_id, 'session_missed', 'Missed Session',
                "{$player_name}'s session was missed. Please reschedule.",
                array('session_id' => $ms->id));

            // Notify parent
            if ($ms->parent_id) {
                self::create_notification($ms->parent_id, 'session_missed', 'Missed Session',
                    "Your session with {$ms->trainer_name} was missed. It will be rescheduled.",
                    array('session_id' => $ms->id));
            }

            if (is_email($ms->parent_email)) {
                self::send_email($ms->parent_email, 'Missed Session — Let\'s Reschedule', self::build_email(
                    'Session Missed', '',
                    "It looks like today's session with {$ms->trainer_name} was missed.\n\n"
                    . "No worries — this session won't count against your package. Your coach will reach out to reschedule.",
                    'View Dashboard', home_url(self::PARENT_DASH . '#mentorship')
                ));
            }

            self::cc_log('mentorship_session_missed', 'mentorship', $ms->id, "Missed session: {$player_name} + {$ms->trainer_name}");
        }
    }


    // ================================================================
    // 
    //  UTILITIES
    // 
    // ================================================================

    // ── Email ──
    private static function send_email($to, $subject, $html, $is_marketing = false) {
        if (!is_email($to)) return;

        // v233 M7: Check opt-out for marketing emails
        if ($is_marketing && self::is_opted_out($to)) {
            self::cc_log('email_skipped_optout', null, null, "Skipped marketing email to {$to}: {$subject}");
            return;
        }

        // v233 M7: Replace generic unsub link with signed one
        $generic_unsub = home_url('/unsubscribe/');
        $signed_unsub  = self::unsub_url($to);
        $html = str_replace(esc_url($generic_unsub), esc_url($signed_unsub), $html);

        // V2's build_email() produces complete branded HTML.
        if (class_exists('PTP_Email_Templates') && method_exists('PTP_Email_Templates', 'send')) {
            PTP_Email_Templates::send($to, $subject, $html);
        } else {
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . ptp_email_brand('from_training'));
            wp_mail($to, $subject, $html, $headers);
        }
    }

    // ── SMS (CC_DB > PTP_SMS > skip) ──
    private static function send_sms($phone, $message) {
        if (!$phone) return;
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (strlen(preg_replace('/\D/', '', $phone)) < 10) return;

        if (class_exists('CC_DB')) {
            CC_DB::send_sms($phone, $message);
        } elseif (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            PTP_SMS::send($phone, $message);
        }
    }

    // ── Command Center logging ──
    private static function cc_log($action, $entity_type = null, $entity_id = null, $detail = '') {
        if (class_exists('CC_DB')) {
            CC_DB::log($action, $entity_type, $entity_id, $detail, 'mentorship_v2');
        }
        ptp_log("[PTP MN2] {$action}: {$detail}");
    }

    // ── In-app notification ──
    private static function create_notification($user_id, $type, $title, $message, $meta = array()) {
        if (class_exists('PTP_Notifications') && method_exists('PTP_Notifications', 'create')) {
            PTP_Notifications::create($user_id, $type, $title, $message, $meta);
        }
    }

    // ── Email template builder (consistent across all mentorship emails) ──
    private static function build_email($heading, $greeting = '', $body_text = '', $cta_label = '', $cta_url = '', $footer_note = '') {
        $b = function_exists('ptp_email_brand') ? ptp_email_brand() : array();
        $logo_url = $b['logo_url'] ?? '';
        $company  = $b['company'] ?? 'PTP';
        $tagline  = $b['tagline'] ?? '';
        $html  = '<div style="font-family:Inter,-apple-system,sans-serif;max-width:560px;margin:0 auto;background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden">';
        $html .= '<div style="background:#0A0A0A;padding:20px 24px;text-align:center">';
        $html .= $logo_url 
            ? '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($company) . '" height="36" style="max-height:36px">'
            : '<span style="font-size:24px;font-weight:700;color:#fff;letter-spacing:2px">' . esc_html($company) . '</span>';
        $html .= '</div>';
        $html .= '<div style="padding:28px 28px 10px">';
        $html .= '<div style="font-family:Oswald,sans-serif;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#FCB900;margin-bottom:8px">' . esc_html(strtoupper($company)) . ' MENTORSHIP</div>';
        $html .= '<h2 style="font-family:Oswald,sans-serif;font-size:22px;font-weight:700;text-transform:uppercase;color:#0A0A0A;margin:0 0 16px;line-height:1.2">' . esc_html($heading) . '</h2>';

        if ($greeting) {
            $html .= '<p style="font-size:15px;color:#333;line-height:1.65;margin:0 0 12px">' . esc_html($greeting) . '</p>';
        }

        // Body — split on \n\n for paragraphs, allow inline HTML
        $paragraphs = preg_split('/\n\n+/', $body_text);
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if (!$p) continue;
            // Handle \n within a paragraph as <br>
            $p = str_replace("\n", '<br>', $p);
            $html .= '<p style="font-size:14px;color:#374151;line-height:1.6;margin:0 0 12px">' . $p . '</p>';
        }

        if ($cta_label && $cta_url) {
            $html .= '<div style="text-align:center;margin:20px 0">';
            $html .= '<a href="' . esc_url($cta_url) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 28px;border-radius:10px;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;text-decoration:none;font-size:14px;letter-spacing:0.5px">' . esc_html($cta_label) . '</a>';
            $html .= '</div>';
        }

        if ($footer_note) {
            $html .= '<p style="font-size:13px;color:#6B7280;line-height:1.5;margin:8px 0 0">' . $footer_note . '</p>';
        }

        $html .= '</div>';
        $html .= '<div style="padding:16px 28px 20px;border-top:1px solid #f3f4f6;font-size:12px;color:#9ca3af">';
        $html .= esc_html($company) . ($tagline ? ' &mdash; ' . esc_html($tagline) : '') . '<br>';
        $html .= '<a href="' . esc_url(home_url('/unsubscribe/')) . '" style="color:#9ca3af;text-decoration:underline;font-size:11px">Unsubscribe</a>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    // ── Data helpers ──
    private static function get_trainer($trainer_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
        ));
    }

    private static function spots_left($trainer) {
        global $wpdb;
        $max = max(1, intval($trainer->mentorship_max_mentees ?? 20));
        $active = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND status = 'active'",
            $trainer->id
        )));
        return max(0, $max - $active);
    }

    private static function already_enrolled($email, $trainer_id) {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return false;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND parent_id = %d AND status NOT IN ('cancelled') LIMIT 1",
            $trainer_id, $user->ID
        ));
    }

    private static function already_enrolled_by_phone($phone, $trainer_id) {
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
        $phone = $wpdb->get_var($wpdb->prepare(
            "SELECT phone FROM {$wpdb->prefix}ptp_parents WHERE email = %s AND phone != '' LIMIT 1", $email
        ));
        if ($phone) return $phone;
        $phone = $wpdb->get_var($wpdb->prepare(
            "SELECT billing_phone FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE billing_email = %s AND billing_phone != '' ORDER BY id DESC LIMIT 1", $email
        ));
        return $phone ?: '';
    }

    private static function first_name($name) {
        return explode(' ', trim($name ?: 'there'))[0];
    }

    private static function signup_url($trainer_id, $source = '') {
        $args = array('trainer_id' => $trainer_id, 'package' => 'development');
        if ($source) $args['source'] = $source;
        return add_query_arg($args, home_url(self::SIGNUP_PATH));
    }

    private static function resolve_player_name($pair) {
        if (!empty($pair->player_name)) return $pair->player_name;
        if (!empty($pair->player_id)) {
            global $wpdb;
            $player = $wpdb->get_row($wpdb->prepare("SELECT name, first_name FROM {$wpdb->prefix}ptp_players WHERE id=%d", $pair->player_id));
            if ($player) return $player->name ?: $player->first_name ?: 'your player';
        }
        return 'your player';
    }

    private static function resolve_parent_email($pair) {
        if (!empty($pair->parent_email) && is_email($pair->parent_email)) return $pair->parent_email;
        if (!empty($pair->parent_id)) {
            $user = get_userdata($pair->parent_id);
            if ($user && is_email($user->user_email)) return $user->user_email;
        }
        return '';
    }

    private static function resolve_trainer_email($trainer) {
        $user = get_userdata($trainer->user_id);
        return ($user && is_email($user->user_email)) ? $user->user_email : '';
    }

    private static function resolve_email_from_attendee($attendee) {
        if (!empty($attendee->parent_email) && is_email($attendee->parent_email)) return $attendee->parent_email;
        if (!empty($attendee->parent_id)) {
            $user = get_userdata($attendee->parent_id);
            if ($user && is_email($user->user_email)) return $user->user_email;
        }
        return '';
    }
}
