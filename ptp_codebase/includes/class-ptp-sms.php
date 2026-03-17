<?php
/**
 * PTP SMS System - v228.0 (OpenPhone)
 * 
 * Replaces Twilio with OpenPhone API (api.openphone.com/v1).
 * Drop-in replacement: all existing PTP_SMS / PTP_SMS_V71 calls work unchanged.
 * 
 * OpenPhone API docs: https://www.openphone.com/docs/api-reference/messages/send-a-text-message
 * 
 * Settings checked (in priority order):
 *   1. ptp_openphone_api_key + ptp_openphone_from  (new)
 *   2. ptp_sms_openphone_key + ptp_sms_openphone_from  (SMS Hub bridge)
 *   3. Legacy Twilio options are ignored
 * 
 * @since 228.0.0
 */

defined('ABSPATH') || exit;

class PTP_SMS_V71 {

    /** @var string OpenPhone API key */
    private static $api_key = '';

    /** @var string OpenPhone "from" — phone number ID or E.164 number */
    private static $from_number = '';

    /** @var string OpenPhone user ID (optional — defaults to phone number owner) */
    private static $user_id = '';

    /** @var string Provider label for logging */
    private static $provider = 'openphone';

    /** @var bool Whether SMS sending is enabled */
    private static $enabled = false;

    /** @var bool Prevent double init */
    private static $initialized = false;

    // =========================================================================
    //  INIT
    // =========================================================================

    /**
     * Initialize settings from wp_options
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // Priority 1: Direct OpenPhone settings
        $key  = get_option('ptp_openphone_api_key', 'fRwRNgl2ynRQFy5oBUhvRuZtQA2Cz4CS');
        $from = get_option('ptp_openphone_from', '+16106714778');

        // Priority 2: SMS Hub bridge keys
        if (empty($key) || empty($from)) {
            $hub_key  = get_option('ptp_sms_openphone_key', '');
            $hub_from = get_option('ptp_sms_openphone_from', '');
            if (!empty($hub_key) && !empty($hub_from)) {
                $key  = $hub_key;
                $from = $hub_from;
            }
        }

        if (!empty($key) && !empty($from)) {
            self::$api_key     = $key;
            self::$from_number = $from;
            self::$user_id     = get_option('ptp_openphone_user_id', '');
            self::$enabled     = true;
        }

        // ---- Hook: Trainer onboarding ----
        add_action('ptp_trainer_approved',          [__CLASS__, 'send_trainer_welcome'], 10, 1);
        add_action('ptp_trainer_onboarding_step',   [__CLASS__, 'send_onboarding_step_sms'], 10, 3);
        add_action('ptp_trainer_profile_complete',  [__CLASS__, 'send_profile_complete_sms'], 10, 1);
        add_action('ptp_trainer_stripe_connected',  [__CLASS__, 'send_stripe_connected_sms'], 10, 1);
        add_action('ptp_trainer_first_booking',     [__CLASS__, 'send_first_booking_sms'], 10, 2);

        // ---- Hook: Bookings ----
        add_action('ptp_booking_confirmed', [__CLASS__, 'send_booking_confirmation'], 10, 1);
        add_action('ptp_session_reminder',  [__CLASS__, 'send_session_reminder'], 10, 1);

        // Post-training follow-up (single hook to avoid duplicates)
        add_action('ptp_session_completed', [__CLASS__, 'send_post_training_followup'], 10, 2);

        // ---- Hook: Messages ----
        add_action('ptp_message_sent', [__CLASS__, 'send_message_notification'], 10, 3);
    }

    // =========================================================================
    //  PUBLIC HELPERS
    // =========================================================================

    /** Is the SMS system configured and enabled? */
    public static function is_enabled() {
        return self::$enabled;
    }

    /**
     * Send an SMS via OpenPhone
     *
     * @param  string  $to       Recipient phone number (any common US format)
     * @param  string  $message  Message body (max 1 600 chars per OpenPhone)
     * @return string|WP_Error   OpenPhone message ID on success
     */
    public static function send($to, $message) {
        if (!self::$enabled) {
            ptp_log('PTP SMS: Not enabled — check OpenPhone settings');
            return new WP_Error('sms_disabled', 'SMS is not configured');
        }

        $to = self::format_phone($to);
        if (!$to) {
            return new WP_Error('invalid_phone', 'Invalid phone number');
        }

        return self::send_via_openphone($to, $message);
    }

    // =========================================================================
    //  OPENPHONE API
    // =========================================================================

    /**
     * POST to https://api.openphone.com/v1/messages
     *
     * @param string $to      E.164 formatted phone
     * @param string $message Body text
     * @return string|WP_Error
     */
    private static function send_via_openphone($to, $message) {

        $body = [
            'content' => mb_substr($message, 0, 1600),
            'from'    => self::$from_number,
            'to'      => [$to],
        ];

        // Optional: set sender user
        if (!empty(self::$user_id)) {
            $body['userId'] = self::$user_id;
        }

        // Optional: auto-mark conversation done (keep inbox clean for automated msgs)
        if (apply_filters('ptp_openphone_auto_done', true)) {
            $body['setInboxStatus'] = 'done';
        }

        $response = wp_remote_post('https://api.openphone.com/v1/messages', [
            'headers' => [
                'Authorization' => self::$api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ]);

        // -- Network / WP error --
        if (is_wp_error($response)) {
            self::log_error('OpenPhone Network Error', $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        // 202 = Accepted (success)
        if ($code === 202 && isset($data['data']['id'])) {
            $msg_id = $data['data']['id'];
            self::log_message($to, $message, $msg_id);

            // Fire hook for cross-plugin logging (Command Center bridge)
            do_action('ptp_sms_sent', $to, $message, $msg_id);

            return $msg_id;
        }

        // -- API error --
        $error_msg = $data['message'] ?? $data['error'] ?? "HTTP {$code}";
        self::log_error('OpenPhone API Error', $error_msg);
        return new WP_Error('openphone_error', $error_msg);
    }

    // =========================================================================
    //  PHONE FORMATTING
    // =========================================================================

    /**
     * Normalize to E.164 format
     */
    private static function format_phone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($phone) === 10) {
            return '+1' . $phone;
        }
        if (strlen($phone) === 11 && substr($phone, 0, 1) === '1') {
            return '+' . $phone;
        }
        if (strlen($phone) > 10) {
            return '+' . $phone;
        }

        return false;
    }

    // =========================================================================
    //  TRAINER ONBOARDING SMS
    // =========================================================================

    /** Welcome SMS on trainer approval */
    public static function send_trainer_welcome($trainer_id) {
        global $wpdb;

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        if (!$trainer) return;

        // Backfill phone from application if missing
        $phone = $trainer->phone;
        if (empty($phone)) {
            $app_phone = $wpdb->get_var($wpdb->prepare(
                "SELECT phone FROM {$wpdb->prefix}ptp_applications WHERE email = %s AND phone IS NOT NULL AND phone != '' ORDER BY created_at DESC LIMIT 1",
                $trainer->email
            ));
            if ($app_phone) {
                $phone = $app_phone;
                $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    ['phone' => $phone],
                    ['id' => $trainer_id]
                );
                ptp_log("PTP SMS: Backfilled phone from application for trainer #{$trainer_id}");
            }
        }

        if (empty($phone)) {
            ptp_log("PTP SMS: No phone for trainer #{$trainer_id} -- skipping welcome SMS");
            return;
        }

        $first_name = explode(' ', $trainer->display_name)[0];
        $login_url  = home_url('/trainer-onboarding/');

        $message  = "Welcome to PTP Training, {$first_name}!\n\n";
        $message .= "Your trainer application has been approved.\n\n";
        $message .= "Next steps:\n";
        $message .= "1. Complete your profile\n";
        $message .= "2. Set up your schedule\n";
        $message .= "3. Connect payments\n\n";
        $message .= "Login: {$login_url}";

        return self::send($phone, $message);
    }

    /** Onboarding step completion SMS */
    public static function send_onboarding_step_sms($trainer_id, $step, $is_complete) {
        if (!$is_complete) return;
        global $wpdb;

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        if (!$trainer || empty($trainer->phone)) return;

        $first_name = explode(' ', $trainer->display_name)[0];

        $messages = [
            'photo'     => "Great photo, {$first_name}! You're making a great first impression. Next: Add your bio and specialties.",
            'bio'       => "Bio added! Parents love learning about their trainers. Next: Set your training locations.",
            'locations' => "Training locations set! You're almost ready. Next: Set your availability schedule.",
            'schedule'  => "Schedule configured! One more step: Connect your payments to get paid.",
            'payments'  => "Payments connected! You're ready to start receiving bookings. Share your profile!",
        ];

        if (!isset($messages[$step])) return;

        return self::send($trainer->phone, "PTP: " . $messages[$step]);
    }

    /** Profile 100 % complete celebration */
    public static function send_profile_complete_sms($trainer_id) {
        global $wpdb;

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        if (!$trainer || empty($trainer->phone)) return;

        $first_name  = explode(' ', $trainer->display_name)[0];
        $profile_url = home_url('/trainer/' . ($trainer->slug ?: sanitize_title($trainer->display_name)) . '/');

        $message  = "Congratulations {$first_name}!\n\n";
        $message .= "Your trainer profile is 100% complete and LIVE.\n\n";
        $message .= "Share your profile to get bookings:\n{$profile_url}\n\n";
        $message .= "Pro tip: Share on social media and with local soccer programs!";

        return self::send($trainer->phone, $message);
    }

    /** Stripe connected notification */
    public static function send_stripe_connected_sms($trainer_id) {
        global $wpdb;

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        if (!$trainer || empty($trainer->phone)) return;

        $first_name = explode(' ', $trainer->display_name)[0];

        $message  = "PTP: Payment setup complete!\n\n";
        $message .= "{$first_name}, you're now ready to receive payouts. ";
        $message .= "When you complete sessions, earnings go directly to your account within 2-3 business days.";

        return self::send($trainer->phone, $message);
    }

    /** First booking celebration */
    public static function send_first_booking_sms($trainer_id, $booking_id) {
        global $wpdb;

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*,
                   t.display_name as trainer_name, t.phone as trainer_phone,
                   p.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_players  p ON b.player_id  = p.id
            WHERE b.id = %d
        ", $booking_id));

        if (!$booking || !$booking->trainer_phone) return;

        $first_name = explode(' ', $booking->trainer_name)[0];
        $raw_d = $booking->session_date ?? ''; $date = (!empty($raw_d) && $raw_d !== '0000-00-00' && strtotime($raw_d) > 0) ? date('D, M j', strtotime($raw_d)) : 'TBD';
        $raw_t = $booking->start_time ?? ''; $time = (!empty($raw_t) && $raw_t !== '00:00:00') ? date('g:i A', strtotime($raw_t)) : 'TBD';

        $message  = "FIRST BOOKING! {$first_name}!\n\n";
        $message .= "Your first training session is booked:\n";
        $message .= "Player: {$booking->player_name}\n";
        $message .= "{$date} at {$time}\n\n";
        $message .= "You're officially a PTP Trainer! Make it a great session!";

        return self::send($booking->trainer_phone, $message);
    }

    // =========================================================================
    //  BOOKING SMS
    // =========================================================================

    /**
     * Booking confirmation to parent
     */
    public static function send_booking_confirmation($booking_id) {
        global $wpdb;

        $has_guest_phone = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings LIKE 'guest_phone'");

        $phone_field = $has_guest_phone
            ? "COALESCE(pa.phone, b.guest_phone, '')"
            : "COALESCE(pa.phone, '')";

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*,
                   t.display_name as trainer_name,
                   COALESCE(p.name, 'Player') as player_name,
                   {$phone_field} as parent_phone,
                   pa.user_id as parent_user_id
            FROM {$wpdb->prefix}ptp_bookings  b
            JOIN {$wpdb->prefix}ptp_trainers  t  ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players  p  ON b.player_id  = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents  pa ON b.parent_id  = pa.id
            WHERE b.id = %d
        ", $booking_id));

        if (!$booking || !$booking->parent_phone) {
            ptp_log("[PTP SMS] send_booking_confirmation: No phone for booking #{$booking_id}");
            return false;
        }

        $raw_d = $booking->session_date ?? ''; $date = (!empty($raw_d) && $raw_d !== '0000-00-00' && strtotime($raw_d) > 0) ? date('D, M j', strtotime($raw_d)) : 'TBD';
        $raw_t = $booking->start_time ?? ''; $time = (!empty($raw_t) && $raw_t !== '00:00:00') ? date('g:i A', strtotime($raw_t)) : 'TBD';

        $message  = "PTP Training Confirmed!\n\n";
        $message .= "{$booking->player_name} with {$booking->trainer_name}\n";
        $message .= "{$date} at {$time}\n";
        if ($booking->location) {
            $message .= "Location: {$booking->location}\n";
        }
        $message .= "\nRef: {$booking->booking_number}";

        // Referral link
        if ($booking->parent_user_id) {
            $referral_code = '';
            if (class_exists('PTP_Referral_System')) {
                $referral_code = PTP_Referral_System::generate_code($booking->parent_user_id, 'parent');
            } else {
                $referral_code = get_user_meta($booking->parent_user_id, 'ptp_referral_code', true);
            }
            if ($referral_code) {
                $referral_link = home_url('/?ref=' . $referral_code);
                $message .= "\n\nShare & get \$25: " . $referral_link;
            }
        }

        $sent = self::send($booking->parent_phone, $message);
        ptp_log("[PTP SMS] Parent confirmation to {$booking->parent_phone}: " . ($sent ? 'sent' : 'failed'));
        return $sent;
    }

    /**
     * New-booking notification to trainer
     */
    public static function send_trainer_new_booking($booking_id) {
        global $wpdb;

        $has_guest_phone = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings LIKE 'guest_phone'");
        $phone_field = $has_guest_phone
            ? "COALESCE(pa.phone, b.guest_phone, '')"
            : "COALESCE(pa.phone, '')";

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*,
                   t.phone as trainer_phone, t.display_name as trainer_name,
                   COALESCE(p.name, 'New Player') as player_name,
                   COALESCE(p.age, 0)              as player_age,
                   COALESCE(pa.display_name, 'Guest') as parent_name,
                   {$phone_field} as parent_phone
            FROM {$wpdb->prefix}ptp_bookings  b
            JOIN {$wpdb->prefix}ptp_trainers  t  ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players  p  ON b.player_id  = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents  pa ON b.parent_id  = pa.id
            WHERE b.id = %d
        ", $booking_id));

        if (!$booking || !$booking->trainer_phone) {
            ptp_log("[PTP SMS] send_trainer_new_booking: No trainer phone for booking #{$booking_id}");
            return false;
        }

        $raw_d = $booking->session_date ?? ''; $date = (!empty($raw_d) && $raw_d !== '0000-00-00' && strtotime($raw_d) > 0) ? date('D, M j', strtotime($raw_d)) : 'TBD';
        $raw_t = $booking->start_time ?? ''; $time = (!empty($raw_t) && $raw_t !== '00:00:00') ? date('g:i A', strtotime($raw_t)) : 'TBD';
        $earnings = number_format($booking->trainer_payout, 2);

        $message  = "New PTP Booking!\n\n";
        $message .= "Player: {$booking->player_name}";
        if ($booking->player_age) $message .= ", {$booking->player_age}yo";
        $message .= "\nParent: {$booking->parent_name}";
        if ($booking->parent_phone) $message .= "\nPhone: {$booking->parent_phone}";
        $message .= "\n{$date} at {$time}";
        if ($booking->location) {
            $message .= "\nLocation: {$booking->location}";
        }
        $message .= "\n\nYour Earnings: \${$earnings}";

        $sent = self::send($booking->trainer_phone, $message);
        ptp_log("[PTP SMS] Trainer notification to {$booking->trainer_phone}: " . ($sent ? 'sent' : 'failed'));
        return $sent;
    }

    /** Session reminder (parent + trainer) */
    public static function send_session_reminder($booking_id) {
        global $wpdb;

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*,
                   t.display_name as trainer_name, t.phone as trainer_phone,
                   p.name as player_name,
                   pa.display_name as parent_name, pa.phone as parent_phone
            FROM {$wpdb->prefix}ptp_bookings  b
            JOIN {$wpdb->prefix}ptp_trainers  t  ON b.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_players   p  ON b.player_id  = p.id
            JOIN {$wpdb->prefix}ptp_parents   pa ON b.parent_id  = pa.id
            WHERE b.id = %d
        ", $booking_id));

        if (!$booking) return false;

        $raw_t = $booking->start_time ?? ''; $time = (!empty($raw_t) && $raw_t !== '00:00:00') ? date('g:i A', strtotime($raw_t)) : 'TBD';

        // Parent
        if ($booking->parent_phone) {
            $msg = "PTP Reminder\n\n{$booking->player_name}'s session is tomorrow at {$time} with {$booking->trainer_name}.";
            if ($booking->location) $msg .= "\nLocation: {$booking->location}";
            self::send($booking->parent_phone, $msg);
        }

        // Trainer
        if ($booking->trainer_phone) {
            $msg = "PTP Reminder\n\nSession with {$booking->player_name} tomorrow at {$time}.\nParent: {$booking->parent_name}";
            if ($booking->location) $msg .= "\nLocation: {$booking->location}";
            self::send($booking->trainer_phone, $msg);
        }

        return true;
    }

    /** Post-training review prompt */
    public static function send_post_training_followup($booking_id, $booking = null) {
        global $wpdb;

        if (!$booking) {
            $booking = $wpdb->get_row($wpdb->prepare("
                SELECT b.*,
                       t.display_name as trainer_name, t.slug as trainer_slug,
                       p.name as player_name,
                       pa.phone as parent_phone, pa.display_name as parent_name
                FROM {$wpdb->prefix}ptp_bookings  b
                LEFT JOIN {$wpdb->prefix}ptp_trainers t  ON b.trainer_id = t.id
                LEFT JOIN {$wpdb->prefix}ptp_players  p  ON b.player_id  = p.id
                LEFT JOIN {$wpdb->prefix}ptp_parents  pa ON b.parent_id  = pa.id
                WHERE b.id = %d
            ", $booking_id));
        }

        if (!$booking || !$booking->parent_phone) return false;

        // Friendly first-name extraction
        $parent_first = 'there';
        if ($booking->parent_name) {
            $name = $booking->parent_name;
            if (strpos($name, '@') !== false) {
                $name = explode('@', $name)[0];
                $name = preg_replace('/[0-9]+/', '', $name);
                $name = str_replace(['.', '_', '-'], ' ', $name);
                $name = ucwords(trim($name));
            }
            $parts = explode(' ', $name);
            $parent_first = $parts[0] ?: 'there';
        }

        $player_name = $booking->player_name ?: 'your player';
        $review_url  = home_url('/trainer/' . ($booking->trainer_slug ?: 'profile') . '/?review=' . $booking_id);

        $message  = "Hi {$parent_first}! Hope {$player_name} had a great session with {$booking->trainer_name} today!\n\n";
        $message .= "Quick feedback helps other families:\n{$review_url}\n\n";
        $message .= "Thanks for choosing PTP!";

        self::send($booking->parent_phone, $message);
        ptp_log("[PTP SMS] Post-training follow-up sent for booking #{$booking_id}");
        return true;
    }

    /** In-app message notification via SMS */
    public static function send_message_notification($conversation_id, $sender_id, $message_preview) {
        global $wpdb;

        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*,
                    t.display_name as trainer_name, t.phone as trainer_phone, t.user_id as trainer_user_id,
                    p.display_name as parent_name,  p.phone as parent_phone,  p.user_id as parent_user_id
             FROM {$wpdb->prefix}ptp_conversations c
             JOIN {$wpdb->prefix}ptp_trainers t ON c.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_parents  p ON c.parent_id  = p.id
             WHERE c.id = %d",
            $conversation_id
        ));

        if (!$conversation) return;

        if ($sender_id == $conversation->trainer_user_id) {
            $recipient_phone = $conversation->parent_phone;
            $sender_name     = $conversation->trainer_name;
        } else {
            $recipient_phone = $conversation->trainer_phone;
            $sender_name     = $conversation->parent_name;
        }

        if (!$recipient_phone) return;

        $preview      = strlen($message_preview) > 50 ? substr($message_preview, 0, 47) . '...' : $message_preview;
        $messages_url = home_url('/messages/?conversation=' . $conversation_id);

        $message  = "PTP: New message from {$sender_name}\n\n";
        $message .= "\"{$preview}\"\n\n";
        $message .= "Reply: {$messages_url}";

        return self::send($recipient_phone, $message);
    }

    // =========================================================================
    //  UTILITY / MIGRATED METHODS
    // =========================================================================

    /** Payout notification to trainer */
    public static function send_payout_notification($trainer_phone, $amount) {
        if (!$trainer_phone) return false;

        $message  = "PTP Payout Processed\n\n";
        $message .= "Amount: $" . number_format($amount, 2) . "\n";
        $message .= "Funds will arrive in 1-3 business days.\n\n";
        $message .= "View details: " . home_url('/trainer-dashboard/?tab=earnings');

        return self::send($trainer_phone, $message);
    }

    /** Session completion request */
    public static function send_completion_request($booking_id) {
        global $wpdb;

        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*,
                   t.display_name as trainer_name, t.phone as trainer_phone,
                   p.name as player_name,
                   pa.display_name as parent_name, pa.phone as parent_phone
            FROM {$wpdb->prefix}ptp_bookings  b
            JOIN {$wpdb->prefix}ptp_trainers  t  ON b.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_players   p  ON b.player_id  = p.id
            JOIN {$wpdb->prefix}ptp_parents   pa ON b.parent_id  = pa.id
            WHERE b.id = %d
        ", $booking_id));

        if (!$booking) return false;

        // Build secure confirm URL with token (works without WordPress login)
        $confirm_url = class_exists('PTP_Session_Confirm_Page')
            ? PTP_Session_Confirm_Page::get_confirm_url($booking_id, $booking->parent_phone)
            : home_url('/confirm-session/?booking=' . $booking_id);

        if ($booking->parent_phone) {
            $message  = "PTP: Please confirm {$booking->player_name}'s session was completed today.\n\n";
            $message .= "Confirm: {$confirm_url}";
            self::send($booking->parent_phone, $message);
        }

        return true;
    }

    // =========================================================================
    //  LOGGING
    // =========================================================================

    /** Create SMS log table */
    public static function create_table() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_sms_log (
            id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            phone_to      varchar(20)   NOT NULL,
            message       text          NOT NULL,
            provider      varchar(20)   DEFAULT 'openphone',
            provider_sid  varchar(100),
            status        varchar(20)   DEFAULT 'sent',
            error_message text,
            created_at    datetime      DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY phone_to   (phone_to),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /** Log sent message */
    private static function log_message($to, $message, $sid) {
        global $wpdb;

        $table = $wpdb->prefix . 'ptp_sms_log';

        // Auto-create table on first write
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            self::create_table();
        }

        $wpdb->insert($table, [
            'phone_to'     => $to,
            'message'      => $message,
            'provider'     => self::$provider,
            'provider_sid' => $sid,
            'status'       => 'sent',
            'created_at'   => current_time('mysql'),
        ]);
    }

    /** Log error */
    private static function log_error($type, $message) {
        ptp_log("PTP SMS Error [{$type}]: {$message}");
    }
}

// -------------------------------------------------------------------------
//  Bootstrap
// -------------------------------------------------------------------------
add_action('plugins_loaded', ['PTP_SMS_V71', 'init'], 15);

// Backwards compat alias — all existing PTP_SMS::method() calls keep working
if (!class_exists('PTP_SMS')) {
    class_alias('PTP_SMS_V71', 'PTP_SMS');
}
