<?php
/**
 * PTP Cron Jobs v140
 * Handles scheduled tasks like reminders, payouts, and cleanup
 * 
 * V140 Changes:
 * - Added camp week/day reminder crons
 * - Added onboarding reminder sequence (24h, 3d, 7d, 14d)
 * - Camp reminders integrated with PTP Camps plugin
 */

defined('ABSPATH') || exit;

class PTP_Cron {
    
    public static function init() {
        // Register cron hooks
        add_action('ptp_send_session_reminders', array(__CLASS__, 'send_session_reminders'));
        add_action('ptp_send_hour_reminders', array(__CLASS__, 'send_hour_reminders'));
        add_action('ptp_send_review_requests', array(__CLASS__, 'send_review_requests'));
        add_action('ptp_process_payouts', array(__CLASS__, 'process_payouts'));
        add_action('ptp_process_refunds', array(__CLASS__, 'process_refunds'));
        add_action('ptp_cleanup_old_data', array(__CLASS__, 'cleanup_old_data'));
        add_action('ptp_send_completion_requests', array(__CLASS__, 'send_completion_requests'));
        add_action('ptp_auto_complete_sessions', array(__CLASS__, 'auto_complete_sessions'));
        add_action('ptp_process_recurring_bookings', array(__CLASS__, 'process_recurring_bookings'));
        
        // V140: Camp reminder hooks
        add_action('ptp_camp_week_reminder', array(__CLASS__, 'send_camp_week_reminders'));
        add_action('ptp_camp_day_reminder', array(__CLASS__, 'send_camp_day_reminders'));
        
        // V140: Onboarding reminder hook
        add_action('ptp_check_onboarding_reminders', array(__CLASS__, 'check_onboarding_reminders'));
        
        // v214: Weekly schedule reminder for trainers
        add_action('ptp_weekly_schedule_reminder', array(__CLASS__, 'send_weekly_schedule_reminders'));
        
        // Compliance email hooks
        add_action('ptp_send_w9_email', array(__CLASS__, 'send_w9_email'), 10, 2);
        add_action('ptp_compliance_reminder', array(__CLASS__, 'send_compliance_reminder'), 10, 1);
        
        // Add 15-minute interval
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_intervals'));
        
        // Schedule events on init
        add_action('init', array(__CLASS__, 'schedule_events'));
    }
    
    /**
     * Send W9 request email (scheduled after SafeSport email)
     */
    public static function send_w9_email($email, $name) {
        if (class_exists('PTP_Email')) {
            PTP_Email::send_w9_request($email, $name);
        }
    }
    
    /**
     * Send compliance reminder email
     */
    public static function send_compliance_reminder($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return;
        
        // Check what's still missing
        $missing_safesport = empty($trainer->safesport_verified) || !$trainer->safesport_verified;
        $missing_w9 = empty($trainer->w9_verified) || !$trainer->w9_verified;
        
        // If nothing is missing, don't send reminder
        if (!$missing_safesport && !$missing_w9) {
            return;
        }
        
        if (class_exists('PTP_Email')) {
            PTP_Email::send_compliance_reminder($trainer->email, $trainer->display_name, $missing_safesport, $missing_w9);
        }
    }
    
    /**
     * Add custom cron intervals
     */
    public static function add_cron_intervals($schedules) {
        $schedules['fifteen_minutes'] = array(
            'interval' => 900,
            'display' => 'Every 15 Minutes'
        );
        return $schedules;
    }
    
    /**
     * Schedule cron events
     */
    public static function schedule_events() {
        // Session reminders (24 hours) - run every hour
        if (!wp_next_scheduled('ptp_send_session_reminders')) {
            wp_schedule_event(time(), 'hourly', 'ptp_send_session_reminders');
        }
        
        // 1-hour reminders - run every 15 minutes
        if (!wp_next_scheduled('ptp_send_hour_reminders')) {
            wp_schedule_event(time(), 'fifteen_minutes', 'ptp_send_hour_reminders');
        }
        
        // Review requests - run every hour
        if (!wp_next_scheduled('ptp_send_review_requests')) {
            wp_schedule_event(time(), 'hourly', 'ptp_send_review_requests');
        }
        
        // Payout processing - run twice daily
        if (!wp_next_scheduled('ptp_process_payouts')) {
            wp_schedule_event(time(), 'twicedaily', 'ptp_process_payouts');
        }
        
        // Refund processing - run hourly
        if (!wp_next_scheduled('ptp_process_refunds')) {
            wp_schedule_event(time(), 'hourly', 'ptp_process_refunds');
        }
        
        // Data cleanup - run weekly
        if (!wp_next_scheduled('ptp_cleanup_old_data')) {
            wp_schedule_event(time(), 'weekly', 'ptp_cleanup_old_data');
        }
        
        // Completion requests - run every hour
        if (!wp_next_scheduled('ptp_send_completion_requests')) {
            wp_schedule_event(time(), 'hourly', 'ptp_send_completion_requests');
        }
        
        // Auto-complete old sessions - run daily
        if (!wp_next_scheduled('ptp_auto_complete_sessions')) {
            wp_schedule_event(time(), 'daily', 'ptp_auto_complete_sessions');
        }
        
        // Process recurring bookings - run daily
        if (!wp_next_scheduled('ptp_process_recurring_bookings')) {
            wp_schedule_event(time(), 'daily', 'ptp_process_recurring_bookings');
        }
        
        // V140: Camp week reminder - run daily at 9am
        if (!wp_next_scheduled('ptp_camp_week_reminder')) {
            wp_schedule_event(strtotime('09:00:00'), 'daily', 'ptp_camp_week_reminder');
        }
        
        // V140: Camp day reminder - run daily at 9am
        if (!wp_next_scheduled('ptp_camp_day_reminder')) {
            wp_schedule_event(strtotime('09:00:00'), 'daily', 'ptp_camp_day_reminder');
        }
        
        // V140: Onboarding reminders - run twice daily at 9am and 5pm
        if (!wp_next_scheduled('ptp_check_onboarding_reminders')) {
            wp_schedule_event(strtotime('09:00:00'), 'twicedaily', 'ptp_check_onboarding_reminders');
        }
        
        // v214: Weekly schedule reminder for trainers - Sundays at 10am ET
        if (!wp_next_scheduled('ptp_weekly_schedule_reminder')) {
            // Schedule for next Sunday at 10am
            $next_sunday = strtotime('next sunday 10:00:00');
            wp_schedule_event($next_sunday, 'weekly', 'ptp_weekly_schedule_reminder');
        }
    }
    
    /**
     * Clear all scheduled events (on deactivation)
     */
    public static function clear_events() {
        wp_clear_scheduled_hook('ptp_send_session_reminders');
        wp_clear_scheduled_hook('ptp_send_hour_reminders');
        wp_clear_scheduled_hook('ptp_send_review_requests');
        wp_clear_scheduled_hook('ptp_process_payouts');
        wp_clear_scheduled_hook('ptp_process_refunds');
        wp_clear_scheduled_hook('ptp_cleanup_old_data');
        wp_clear_scheduled_hook('ptp_send_completion_requests');
        wp_clear_scheduled_hook('ptp_auto_complete_sessions');
        wp_clear_scheduled_hook('ptp_process_recurring_bookings');
        
        // V140: Clear new cron jobs
        wp_clear_scheduled_hook('ptp_camp_week_reminder');
        wp_clear_scheduled_hook('ptp_camp_day_reminder');
        wp_clear_scheduled_hook('ptp_check_onboarding_reminders');
        
        // v214: Clear weekly schedule reminder
        wp_clear_scheduled_hook('ptp_weekly_schedule_reminder');
    }
    
    /**
     * v223.1: Acquire a transient-based execution lock for a cron job.
     * Prevents overlapping runs on slow hosts.
     *
     * @param string $hook Cron hook name.
     * @param int    $ttl  Lock duration in seconds (default 300 = 5 min).
     * @return bool True if lock acquired, false if already running.
     */
    private static function acquire_lock($hook, $ttl = 300) {
        $key = 'ptp_cron_lock_' . $hook;
        if (get_transient($key)) {
            return false; // Already running
        }
        set_transient($key, time(), $ttl);
        return true;
    }

    /**
     * v223.1: Release a cron execution lock.
     */
    private static function release_lock($hook) {
        delete_transient('ptp_cron_lock_' . $hook);
    }

    /**
     * Send session reminders (24 hours before)
     */
    public static function send_session_reminders() {
        if (!self::acquire_lock('ptp_send_session_reminders')) return;
        global $wpdb;

        if (!get_option('ptp_email_session_reminder', true) && !get_option('ptp_sms_session_reminder', true)) {
            self::release_lock('ptp_send_session_reminders');
            return;
        }
        
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}ptp_bookings 
            WHERE session_date = %s 
            AND status IN ('confirmed', 'pending')
            AND reminder_sent = 0
        ", $tomorrow));
        
        foreach ($bookings as $booking) {
            if (get_option('ptp_email_session_reminder', true)) {
                PTP_Email::send_session_reminder($booking->id);
            }
            
            if (get_option('ptp_sms_session_reminder', true) && class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
                PTP_SMS::send_session_reminder($booking->id);
            }
            
            // v230: AI pre-session training plan (fires after normal reminder)
            if (class_exists('PTP_AI_Training_Plan') && PTP_AI_Training_Plan::is_enabled()) {
                do_action('ptp_send_pre_session_plan', $booking->id);
            }
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('reminder_sent' => 1),
                array('id' => $booking->id)
            );
        }
        self::release_lock('ptp_send_session_reminders');
    }

    /**
     * Send 1-hour reminders (push notifications)
     */
    public static function send_hour_reminders() {
        if (!self::acquire_lock('ptp_send_hour_reminders')) return;
        global $wpdb;

        // Get sessions starting in the next 60-75 minutes
        $now = current_time('mysql');
        $hour_from_now = date('Y-m-d H:i:s', strtotime('+60 minutes'));
        $hour_15_from_now = date('Y-m-d H:i:s', strtotime('+75 minutes'));

        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}ptp_bookings
            WHERE CONCAT(session_date, ' ', start_time) BETWEEN %s AND %s
            AND status = 'confirmed'
            AND hour_reminder_sent = 0
        ", $hour_from_now, $hour_15_from_now));

        foreach ($bookings as $booking) {
            // Send push notification
            if (class_exists('PTP_Push_Notifications')) {
                do_action('ptp_session_reminder', $booking->id);
            }

            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('hour_reminder_sent' => 1),
                array('id' => $booking->id)
            );
        }
        self::release_lock('ptp_send_hour_reminders');
    }
    
    /**
     * Send review requests (after session completes)
     */
    public static function send_review_requests() {
        // v222.1: DEPRECATED - Consolidated into PTP_Email_Automation::send_review_request_emails()
        // which sends on Day 3 with email_logs deduplication. This Day-1 sender was causing
        // parents to receive 2 review request emails (Day 1 + Day 3).
        return;
    }
    
    /**
     * Send completion confirmation requests
     */
    public static function send_completion_requests() {
        if (!self::acquire_lock('ptp_send_completion_requests')) return;
        global $wpdb;

        $two_hours_ago = date('Y-m-d H:i:s', strtotime('-2 hours'));
        $now = current_time('mysql');

        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}ptp_bookings
            WHERE CONCAT(session_date, ' ', end_time) BETWEEN %s AND %s
            AND status = 'confirmed'
            AND completion_request_sent = 0
        ", $two_hours_ago, $now));

        foreach ($bookings as $booking) {
            if (class_exists('PTP_Push_Notifications')) {
                do_action('ptp_booking_completed', $booking->id);
            }

            // Send SMS post-training followup
            if (get_option('ptp_sms_post_training', true) && class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
                PTP_SMS::send_completion_request($booking->id);
            }

            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('completion_request_sent' => 1),
                array('id' => $booking->id)
            );
        }
        self::release_lock('ptp_send_completion_requests');
    }
    
    /**
     * Auto-complete sessions that are 48+ hours old
     */
    public static function auto_complete_sessions() {
        if (!self::acquire_lock('ptp_auto_complete_sessions')) return;
        global $wpdb;

        $two_days_ago = date('Y-m-d', strtotime('-2 days'));

        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT id, trainer_id, total_amount, trainer_payout FROM {$wpdb->prefix}ptp_bookings
            WHERE session_date <= %s
            AND status = 'confirmed'
            AND payment_status = 'paid'
        ", $two_days_ago));

        $platform_fee = floatval(get_option('ptp_platform_fee_percent', 25)) / 100;

        foreach ($bookings as $booking) {
            // v222.1: Preserve checkout's trainer_payout if already calculated
            // Only recalculate if checkout didn't set it (e.g., legacy bookings)
            $trainer_payout = floatval($booking->trainer_payout ?? 0);
            if ($trainer_payout <= 0) {
                $trainer_payout = $booking->total_amount * (1 - $platform_fee);
            }

            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'status' => 'completed',
                    'payout_status' => 'pending',
                    'trainer_payout' => $trainer_payout,
                ),
                array('id' => $booking->id)
            );

            // Update trainer stats
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_trainers SET total_sessions = total_sessions + 1 WHERE id = %d",
                $booking->trainer_id
            ));

            // v222.1: Fire action for trainer post-session email and recap reminders
            do_action('ptp_booking_auto_completed', $booking->id, $booking->trainer_id);
            do_action('ptp_session_completed', $booking->id, $booking->trainer_id);
        }
        self::release_lock('ptp_auto_complete_sessions');
    }
    
    /**
     * Process pending payouts via Stripe Connect
     */
    public static function process_payouts() {
        if (!self::acquire_lock('ptp_process_payouts')) return;
        global $wpdb;

        if (!class_exists('PTP_Stripe') || !get_option('ptp_stripe_connect_enabled')) {
            self::release_lock('ptp_process_payouts');
            return;
        }

        $min_payout = floatval(get_option('ptp_min_payout', 25));

        // Get trainers with pending payouts
        $trainers = $wpdb->get_results($wpdb->prepare("
            SELECT trainer_id, SUM(trainer_payout) as total_pending
            FROM {$wpdb->prefix}ptp_bookings
            WHERE status = 'completed'
            AND payout_status = 'pending'
            AND trainer_payout > 0
            GROUP BY trainer_id
            HAVING total_pending >= %f
        ", $min_payout));

        foreach ($trainers as $row) {
            $trainer = PTP_Trainer::get($row->trainer_id);

            if (!$trainer || empty($trainer->stripe_account_id)) {
                continue;
            }

            // v222.1: Self-heal stale stripe_payouts_enabled
            if (!$trainer->stripe_payouts_enabled) {
                $connect = PTP_Stripe_Connect_V71::instance();
                if (method_exists($connect, 'sync_stripe_status')) {
                    $now_enabled = $connect->sync_stripe_status($row->trainer_id, $trainer->stripe_account_id);
                    if (!$now_enabled) continue;
                } else {
                    continue;
                }
            }

            // Get bookings to include in this payout
            $bookings = $wpdb->get_results($wpdb->prepare("
                SELECT id, trainer_payout FROM {$wpdb->prefix}ptp_bookings
                WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'
            ", $row->trainer_id));

            $amount_cents = intval($row->total_pending * 100);

            // Create Stripe transfer
            // v228: Idempotency key prevents duplicate transfers if cron runs twice
            $booking_ids_for_key = array_map(function($b) { return $b->id; }, $bookings);
            sort($booking_ids_for_key);
            $idem_key = 'cron_payout_' . $row->trainer_id . '_' . md5(implode(',', $booking_ids_for_key));
            
            $result = PTP_Stripe::create_transfer(
                $amount_cents,
                $trainer->stripe_account_id,
                'Trainer payout - ' . count($bookings) . ' sessions',
                $idem_key
            );

            if (!is_wp_error($result)) {
                // Mark bookings as paid out
                $booking_ids = array_map(function($b) { return $b->id; }, $bookings);
                $placeholders = implode(',', array_fill(0, count($booking_ids), '%d'));

                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ptp_bookings
                     SET payout_status = 'completed',
                         payout_date = NOW(),
                         stripe_transfer_id = %s
                     WHERE id IN ($placeholders)",
                    array_merge(array($result['id']), $booking_ids)
                ));
                
                // v228: Close any escrow records for these bookings
                $escrow_table = $wpdb->prefix . 'ptp_escrow';
                if ($wpdb->get_var("SHOW TABLES LIKE '{$escrow_table}'") === $escrow_table) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$escrow_table} 
                         SET status = 'released', 
                             released_at = NOW(),
                             release_method = 'cron_payout',
                             stripe_transfer_id = %s
                         WHERE booking_id IN ($placeholders)
                         AND status IN ('holding', 'session_complete', 'confirmed')",
                        array_merge(array($result['id']), $booking_ids)
                    ));
                }

                // Log payout
                $wpdb->insert($wpdb->prefix . 'ptp_payouts', array(
                    'trainer_id' => $row->trainer_id,
                    'amount' => $row->total_pending,
                    'stripe_transfer_id' => $result['id'],
                    'status' => 'completed',
                    'booking_count' => count($bookings),
                    'created_at' => current_time('mysql'),
                ));

                // Notify trainer
                if (class_exists('PTP_Push_Notifications')) {
                    PTP_Push_Notifications::send(
                        $trainer->user_id,
                        '💰 Payout Sent!',
                        '$' . number_format($row->total_pending, 2) . ' is on its way to your bank',
                        array('type' => 'payout', 'amount' => $row->total_pending)
                    );
                }

                // v222.1: Fire action for SMS and other integrations
                do_action('ptp_trainer_payout_completed', $row->trainer_id, $row->total_pending);
            }
        }
        self::release_lock('ptp_process_payouts');
    }
    
    /**
     * Process pending refunds
     */
    public static function process_refunds() {
        if (!self::acquire_lock('ptp_process_refunds')) return;
        global $wpdb;

        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            self::release_lock('ptp_process_refunds');
            return;
        }

        // Get cancelled bookings needing refund
        $bookings = $wpdb->get_results("
            SELECT id, stripe_payment_id, total_amount, cancelled_by, cancelled_at, session_date, start_time
            FROM {$wpdb->prefix}ptp_bookings
            WHERE status = 'cancelled'
            AND payment_status = 'paid'
            AND refund_status = 'pending'
            AND stripe_payment_id IS NOT NULL
            AND stripe_payment_id != ''
        ");

        foreach ($bookings as $booking) {
            // Calculate refund amount based on cancellation policy
            $hours_before = (strtotime($booking->session_date . ' ' . $booking->start_time) - strtotime($booking->cancelled_at)) / 3600;

            $refund_percent = 100;
            if ($hours_before < 24 && $booking->cancelled_by === 'parent') {
                $refund_percent = 50; // 50% refund if cancelled < 24 hours by parent
            }
            if ($hours_before < 2 && $booking->cancelled_by === 'parent') {
                $refund_percent = 0; // No refund if cancelled < 2 hours by parent
            }
            if ($booking->cancelled_by === 'trainer') {
                $refund_percent = 100; // Full refund if trainer cancels
            }

            if ($refund_percent > 0) {
                // v222.1: Pass dollar amount (create_refund converts to cents internally)
                $refund_amount = round($booking->total_amount * ($refund_percent / 100), 2);

                $result = PTP_Stripe::create_refund($booking->stripe_payment_id, $refund_amount);

                if (!is_wp_error($result)) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        array(
                            'refund_status' => 'completed',
                            'refund_amount' => $refund_amount,
                            'stripe_refund_id' => $result['id'],
                        ),
                        array('id' => $booking->id)
                    );
                }
            } else {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_bookings',
                    array('refund_status' => 'none'),
                    array('id' => $booking->id)
                );
            }
        }
        self::release_lock('ptp_process_refunds');
    }
    
    /**
     * Cleanup old data
     */
    public static function cleanup_old_data() {
        if (!self::acquire_lock('ptp_cleanup_old_data')) return;
        global $wpdb;

        // Delete old notifications (90 days)
        $wpdb->query("
            DELETE FROM {$wpdb->prefix}ptp_notifications
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
        ");

        // Delete old FCM tokens (30 days inactive)
        $wpdb->query("
            DELETE FROM {$wpdb->prefix}ptp_fcm_tokens
            WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");

        // Delete expired sessions (failed payments after 24 hours)
        $wpdb->query("
            DELETE FROM {$wpdb->prefix}ptp_bookings
            WHERE status = 'pending'
            AND payment_status = 'pending'
            AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        // Archive old conversations (90 days no activity)
        $wpdb->query("
            UPDATE {$wpdb->prefix}ptp_conversations
            SET status = 'archived'
            WHERE updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
            AND status = 'active'
        ");
        self::release_lock('ptp_cleanup_old_data');
    }
    
    /**
     * Process recurring bookings - creates future sessions automatically
     *
     * FIXED: Was querying non-existent table `ptp_recurring_bookings` with wrong columns.
     * Now uses actual `ptp_recurring` table created by PTP_Recurring::create_tables()
     * and delegates booking generation to PTP_Recurring::generate_upcoming_bookings().
     */
    public static function process_recurring_bookings() {
        if (!self::acquire_lock('ptp_process_recurring_bookings')) return;
        global $wpdb;

        $recurring_table = $wpdb->prefix . 'ptp_recurring';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $recurring_table)) === $recurring_table;
        if (!$table_exists) {
            self::release_lock('ptp_process_recurring_bookings');
            return;
        }

        // Get active recurring series where next_session_date is within 4 days
        $upcoming_date = date('Y-m-d', strtotime('+4 days'));

        $active = $wpdb->get_results($wpdb->prepare("
            SELECT id, next_session_date, sessions_remaining, sessions_completed, total_sessions
            FROM {$recurring_table}
            WHERE status = 'active'
            AND next_session_date <= %s
        ", $upcoming_date));

        if (!$active) {
            self::release_lock('ptp_process_recurring_bookings');
            return;
        }

        // Delegate to PTP_Recurring which handles dedup, pricing, and booking creation
        if (!class_exists('PTP_Recurring')) {
            self::release_lock('ptp_process_recurring_bookings');
            return;
        }

        foreach ($active as $rec) {
            // Skip if package is exhausted (finite packages only)
            if ($rec->sessions_remaining !== null && intval($rec->sessions_remaining) <= 0) {
                continue;
            }

            $created = PTP_Recurring::generate_upcoming_bookings($rec->id, 4);

            if ($created && $created > 0) {
                ptp_log('[PTP Cron] Generated ' . $created . ' recurring bookings for recurring_id=' . $rec->id);
            }
        }
        self::release_lock('ptp_process_recurring_bookings');
    }
    
    /**
     * Run specific task manually (for admin/testing)
     */
    public static function run_task($task) {
        switch ($task) {
            case 'reminders': self::send_session_reminders(); break;
            case 'hour_reminders': self::send_hour_reminders(); break;
            case 'reviews': self::send_review_requests(); break;
            case 'payouts': self::process_payouts(); break;
            case 'refunds': self::process_refunds(); break;
            case 'cleanup': self::cleanup_old_data(); break;
            case 'completions': self::send_completion_requests(); break;
            case 'auto_complete': self::auto_complete_sessions(); break;
            case 'recurring': self::process_recurring_bookings(); break;
            case 'camp_week': self::send_camp_week_reminders(); break;
            case 'camp_day': self::send_camp_day_reminders(); break;
            case 'onboarding': self::check_onboarding_reminders(); break;
        }
    }
    
    // ===========================================
    // V140: CAMP REMINDER METHODS
    // ===========================================
    
    /**
     * Send reminders 1 week before camp starts (V140)
     */
    public static function send_camp_week_reminders() {
        if (!self::acquire_lock('ptp_camp_week_reminder')) return;
        global $wpdb;

        $target_date = date('Y-m-d', strtotime('+7 days'));

        ptp_log('[PTP Cron] Running camp week reminders for camps starting on ' . $target_date);

        // Check for camps in ptp_camp_orders (PTP Camps plugin)
        $camps_table = $wpdb->prefix . 'ptp_camp_orders';
        $items_table = $wpdb->prefix . 'ptp_camp_order_items';
        $camps_def_table = $wpdb->prefix . 'ptp_camps';

        // Check if tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$camps_table}'") !== $camps_table) {
            ptp_log('[PTP Cron] ptp_camp_orders table not found, skipping camp reminders');
            self::release_lock('ptp_camp_week_reminder');
            return;
        }

        // Get orders for camps starting in 7 days that haven't had reminders sent
        $orders = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT co.*,
                   oi.camp_id,
                   COALESCE(c.name, oi.product_name) as camp_name,
                   COALESCE(c.location_name, oi.camp_location) as location_name,
                   COALESCE(c.daily_start_time, oi.camp_time) as camp_time
            FROM {$camps_table} co
            JOIN {$items_table} oi ON co.id = oi.order_id
            LEFT JOIN {$camps_def_table} c ON oi.camp_id = c.id
            WHERE (c.start_date = %s OR oi.camp_date LIKE %s)
            AND co.status = 'completed'
            AND co.payment_status = 'paid'
            AND (co.reminder_sent = 0 OR co.reminder_sent IS NULL)
        ", $target_date, $target_date . '%'));

        $sent_count = 0;

        foreach ($orders as $order) {
            // Send email reminder
            if (class_exists('PTP_Camps_Emails')) {
                PTP_Camps_Emails::send_reminder($order->id);
                $sent_count++;
            } elseif (class_exists('PTP_Email')) {
                // Fallback to training platform email
                PTP_Email::send_camp_week_reminder(
                    $order->customer_email,
                    $order->customer_name,
                    $order->camp_name,
                    $order->location_name,
                    $target_date
                );
                $sent_count++;
            }

            // Mark reminder sent
            $wpdb->update(
                $camps_table,
                array(
                    'reminder_sent' => 1,
                    'reminder_sent_at' => current_time('mysql'),
                ),
                array('id' => $order->id)
            );
        }

        ptp_log('[PTP Cron] Sent ' . $sent_count . ' camp week reminders');
        self::release_lock('ptp_camp_week_reminder');
    }
    
    /**
     * Send reminders 1 day before camp starts (V140)
     */
    public static function send_camp_day_reminders() {
        if (!self::acquire_lock('ptp_camp_day_reminder')) return;
        global $wpdb;

        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        ptp_log('[PTP Cron] Running camp day reminders for camps starting on ' . $tomorrow);

        $camps_table = $wpdb->prefix . 'ptp_camp_orders';
        $items_table = $wpdb->prefix . 'ptp_camp_order_items';
        $camps_def_table = $wpdb->prefix . 'ptp_camps';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$camps_table}'") !== $camps_table) {
            self::release_lock('ptp_camp_day_reminder');
            return;
        }

        // Get orders for camps starting tomorrow
        $orders = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT co.*,
                   oi.camp_id,
                   COALESCE(c.name, oi.product_name) as camp_name,
                   COALESCE(c.location_name, oi.camp_location) as location_name,
                   COALESCE(c.daily_start_time, oi.camp_time) as camp_time,
                   c.what_to_bring
            FROM {$camps_table} co
            JOIN {$items_table} oi ON co.id = oi.order_id
            LEFT JOIN {$camps_def_table} c ON oi.camp_id = c.id
            WHERE (c.start_date = %s OR oi.camp_date LIKE %s)
            AND co.status = 'completed'
            AND co.payment_status = 'paid'
        ", $tomorrow, $tomorrow . '%'));

        $sent_count = 0;

        foreach ($orders as $order) {
            // Send email
            if (class_exists('PTP_Camps_Emails')) {
                PTP_Camps_Emails::send_day_before_reminder($order->id);
            }

            // Send SMS
            if ($order->customer_phone && class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
                $time_formatted = $order->camp_time ? date('g:ia', strtotime($order->camp_time)) : 'scheduled time';

                $message = "PTP Reminder: {$order->camp_name} starts TOMORROW! ";
                $message .= "Drop-off at {$time_formatted} at {$order->location_name}. ";
                $message .= "Don't forget soccer gear and water!";

                PTP_SMS::send($order->customer_phone, $message);
                $sent_count++;
            }
        }

        ptp_log('[PTP Cron] Sent ' . $sent_count . ' camp day reminders');
        self::release_lock('ptp_camp_day_reminder');
    }
    
    // ===========================================
    // V140: ONBOARDING REMINDER METHODS
    // ===========================================
    
    /**
     * Check and send onboarding reminders (V140)
     * Sequence: 24h, 3d, 7d, 14d after approval
     */
    public static function check_onboarding_reminders() {
        if (!self::acquire_lock('ptp_check_onboarding_reminders')) return;
        global $wpdb;

        ptp_log('[PTP Cron] Running onboarding reminder check');

        $trainers_table = $wpdb->prefix . 'ptp_trainers';

        // Get approved trainers who haven't completed onboarding
        $trainers = $wpdb->get_results("
            SELECT * FROM {$trainers_table}
            WHERE status = 'approved'
            AND (onboarding_completed_at IS NULL OR onboarding_completed_at = '')
            AND approved_at IS NOT NULL
        ");

        $sent_count = 0;

        foreach ($trainers as $trainer) {
            $approved_at = strtotime($trainer->approved_at);
            $now = time();
            $days_since_approval = floor(($now - $approved_at) / 86400);

            // Get current reminder count
            $reminder_count = intval($trainer->onboarding_reminder_count ?? 0);

            // Check last reminder time to avoid spamming
            $last_reminder = $trainer->last_onboarding_reminder_at ? strtotime($trainer->last_onboarding_reminder_at) : 0;
            $hours_since_last = $last_reminder ? floor(($now - $last_reminder) / 3600) : 999;

            // Minimum 12 hours between reminders
            if ($hours_since_last < 12) {
                continue;
            }

            // Determine which reminder to send based on days and count
            $reminder_type = null;

            if ($reminder_count == 0 && $days_since_approval >= 1) {
                // 24 hour nudge
                $reminder_type = '24h_nudge';
            } elseif ($reminder_count == 1 && $days_since_approval >= 3) {
                // 3 day progress check
                $reminder_type = 'progress_check';
            } elseif ($reminder_count == 2 && $days_since_approval >= 7) {
                // 7 day offer help
                $reminder_type = 'offer_help';
            } elseif ($reminder_count == 3 && $days_since_approval >= 14) {
                // 14 day final warning
                $reminder_type = 'final_warning';
            }

            if ($reminder_type) {
                // Send the reminder
                $sent = self::send_onboarding_reminder($trainer, $reminder_type);

                if ($sent) {
                    // Update trainer record
                    $wpdb->update(
                        $trainers_table,
                        array(
                            'onboarding_reminder_count' => $reminder_count + 1,
                            'last_onboarding_reminder_at' => current_time('mysql'),
                        ),
                        array('id' => $trainer->id)
                    );

                    $sent_count++;
                    ptp_log('[PTP Cron] Sent ' . $reminder_type . ' reminder to trainer ' . $trainer->id);
                }
            }
        }

        ptp_log('[PTP Cron] Sent ' . $sent_count . ' onboarding reminders');
        self::release_lock('ptp_check_onboarding_reminders');
    }
    
    /**
     * Send specific onboarding reminder (V140)
     */
    private static function send_onboarding_reminder($trainer, $reminder_type) {
        if (!class_exists('PTP_Email')) {
            return false;
        }
        
        // Calculate onboarding completion percentage
        $steps = array(
            'photo' => !empty($trainer->photo_url),
            'bio' => strlen($trainer->bio ?? '') >= 50,
            'experience' => !empty($trainer->playing_level),
            'rate' => floatval($trainer->hourly_rate ?? 0) > 0,
            'location' => !empty($trainer->city) && !empty($trainer->state),
            'contract' => !empty($trainer->contractor_agreement_signed),
            'stripe' => !empty($trainer->stripe_account_id),
        );
        
        $completed_steps = count(array_filter($steps));
        $total_steps = count($steps);
        $percentage = round(($completed_steps / $total_steps) * 100);
        
        // Get incomplete steps for message
        $incomplete = array();
        foreach ($steps as $step => $done) {
            if (!$done) {
                $incomplete[] = $step;
            }
        }
        
        // Build subject and message based on type
        $subject = '';
        $message = '';
        $first_name = $trainer->first_name ?? explode(' ', $trainer->display_name ?? '')[0] ?? 'Trainer';
        $login_url = home_url('/trainer-onboarding/');
        
        switch ($reminder_type) {
            case '24h_nudge':
                $subject = "Complete Your PTP Trainer Profile ({$percentage}% done)";
                $message = "Hi {$first_name},\n\nWelcome to PTP Training! You're {$percentage}% of the way to completing your profile.\n\n";
                $message .= "Finish setting up to start accepting bookings: {$login_url}\n\n";
                $message .= "- The PTP Team";
                break;
                
            case 'progress_check':
                $subject = "Quick Check-In: Your PTP Profile is {$percentage}% Complete";
                $message = "Hi {$first_name},\n\nHow's it going? We noticed your profile is {$percentage}% complete.\n\n";
                if (!empty($incomplete)) {
                    $message .= "You still need to: " . implode(', ', array_slice($incomplete, 0, 3)) . ".\n\n";
                }
                $message .= "Complete your profile to start earning: {$login_url}\n\n";
                $message .= "Questions? Just reply to this email!";
                break;
                
            case 'offer_help':
                $subject = "Need Help Completing Your PTP Profile?";
                $message = "Hi {$first_name},\n\nWe noticed you haven't finished setting up your trainer profile yet.\n\n";
                $message .= "Is there anything blocking you? We're here to help!\n\n";
                $message .= "You can:\n";
                $message .= "- Reply to this email with any questions\n";
                $message .= "- Visit {$login_url} to continue setup\n\n";
                $message .= "Once your profile is complete, you'll be able to accept bookings and start training.";
                break;
                
            case 'final_warning':
                $subject = "Action Needed: Complete Your PTP Profile";
                $message = "Hi {$first_name},\n\nThis is a reminder that your PTP trainer profile is still incomplete.\n\n";
                $message .= "Incomplete profiles may be removed after 30 days to keep our trainer marketplace active.\n\n";
                $message .= "Please complete your profile soon: {$login_url}\n\n";
                $message .= "If you're having trouble or have decided not to proceed, please let us know by replying to this email.";
                break;
        }
        
        // Send the email
        return PTP_Email::send($trainer->email, $subject, $message);
    }
    
    /**
     * v214: Send weekly schedule reminders to active trainers
     * Runs every Sunday at 10am — reminds coaches to keep their availability updated
     */
    public static function send_weekly_schedule_reminders() {
        if (!self::acquire_lock('ptp_weekly_schedule_reminder')) return;
        global $wpdb;

        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        $avail_table    = $wpdb->prefix . 'ptp_availability';
        $bookings_table = $wpdb->prefix . 'ptp_bookings';

        // Get all active trainers with their email
        $trainers = $wpdb->get_results("
            SELECT t.id, t.display_name, t.email, t.user_id, t.photo_url, t.slug
            FROM {$trainers_table} t
            WHERE t.status = 'active'
            AND (t.email IS NOT NULL AND t.email != '')
        ");

        if (empty($trainers)) {
            ptp_log('[PTP Cron v214] Weekly schedule reminder: No active trainers found');
            self::release_lock('ptp_weekly_schedule_reminder');
            return;
        }

        $sent_count = 0;
        $week_start = date('Y-m-d');
        $week_end   = date('Y-m-d', strtotime('+7 days'));
        $two_weeks  = date('Y-m-d', strtotime('+14 days'));

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_training'),
            'Reply-To: ' . ptp_email_brand('from_email')
        );

        foreach ($trainers as $trainer) {
            // Skip if no valid email
            $email = $trainer->email;
            if (!$email && $trainer->user_id) {
                $wp_user = get_user_by('ID', $trainer->user_id);
                if ($wp_user) $email = $wp_user->user_email;
            }
            if (!$email || !is_email($email)) continue;

            // Get active availability slots count
            $avail_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$avail_table} WHERE trainer_id = %d AND is_active = 1",
                $trainer->id
            ));

            // Get upcoming bookings this week
            $upcoming_bookings = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$bookings_table}
                 WHERE trainer_id = %d AND session_date BETWEEN %s AND %s
                 AND status IN ('confirmed', 'pending')",
                $trainer->id, $week_start, $week_end
            ));

            // Get next 2 weeks bookings
            $two_week_bookings = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$bookings_table}
                 WHERE trainer_id = %d AND session_date BETWEEN %s AND %s
                 AND status IN ('confirmed', 'pending')",
                $trainer->id, $week_start, $two_weeks
            ));

            // Total lifetime sessions
            $total_sessions = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$bookings_table}
                 WHERE trainer_id = %d AND status = 'completed'",
                $trainer->id
            ));

            // Build the email
            $first_name = explode(' ', $trainer->display_name)[0];
            $dashboard_url = home_url('/trainer-dashboard/');

            // Determine the nudge message based on their state
            if ($avail_count === 0) {
                $nudge = "You don't have any availability set right now. Parents can't book you until you open up some time slots.";
                $urgency = 'high';
            } elseif ($avail_count <= 2) {
                $nudge = "You only have {$avail_count} time slot" . ($avail_count > 1 ? 's' : '') . " open. More availability = more bookings.";
                $urgency = 'medium';
            } else {
                $nudge = "You have {$avail_count} time slots open this week. Make sure they're still accurate for the upcoming week.";
                $urgency = 'low';
            }

            // Stats line
            $stats_parts = array();
            if ($upcoming_bookings > 0) $stats_parts[] = "{$upcoming_bookings} session" . ($upcoming_bookings > 1 ? 's' : '') . " this week";
            if ($two_week_bookings > $upcoming_bookings) $stats_parts[] = ($two_week_bookings - $upcoming_bookings) . " next week";
            if ($total_sessions > 0) $stats_parts[] = "{$total_sessions} total completed";

            $subject = $avail_count === 0
                ? "Action Needed: Update Your Schedule"
                : "Weekly Check-In: Is Your Schedule Up to Date?";

            $body = self::get_schedule_reminder_email_html($first_name, $trainer->display_name, $trainer->photo_url, $nudge, $urgency, $avail_count, $upcoming_bookings, $stats_parts, $dashboard_url);

            $sent = wp_mail($email, $subject, $body, $headers);

            if ($sent) $sent_count++;

            ptp_log("[PTP Cron v214] Schedule reminder to {$email}: " . ($sent ? 'sent' : 'failed') . " (avail={$avail_count}, upcoming={$upcoming_bookings})");
        }

        ptp_log("[PTP Cron v214] Weekly schedule reminders complete: {$sent_count}/" . count($trainers) . " sent");
        self::release_lock('ptp_weekly_schedule_reminder');
    }
    
    /**
     * v214: Generate HTML email for weekly schedule reminder
     */
    private static function get_schedule_reminder_email_html($first_name, $full_name, $photo_url, $nudge, $urgency, $avail_count, $upcoming, $stats_parts, $dashboard_url) {
        $avatar = !empty($photo_url) 
            ? $photo_url 
            : 'https://ui-avatars.com/api/?name=' . urlencode($full_name) . '&size=80&background=FCB900&color=0A0A0A&bold=true';
        
        $urgency_color = $urgency === 'high' ? '#EF4444' : ($urgency === 'medium' ? '#FCB900' : '#22C55E');
        $urgency_bg    = $urgency === 'high' ? '#FEF2F2' : ($urgency === 'medium' ? '#FFF9E5' : '#F0FDF4');
        $urgency_label = $urgency === 'high' ? 'NO AVAILABILITY SET' : ($urgency === 'medium' ? 'LOW AVAILABILITY' : 'LOOKING GOOD');
        
        $stats_html = '';
        if (!empty($stats_parts)) {
            $stats_html = '<tr><td style="padding:0 32px 24px">
                <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#1A1A1A;border-radius:8px;border:1px solid #333">
                    <tr><td style="padding:16px 20px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;font-size:13px;color:#9CA3AF;line-height:1.6">' 
                    . esc_html(implode(' &bull; ', $stats_parts)) . 
                '</td></tr></table>
            </td></tr>';
        }
        
        $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>PTP Schedule Reminder</title>
</head>
<body style="margin:0;padding:0;background-color:#0E0F11;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#0E0F11;">
        <tr><td align="center" style="padding:24px 16px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width:600px;width:100%;">
                
                <!-- Logo -->
                <tr><td align="center" style="padding:0 0 24px;">
                    <span style="font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:#FCB900;letter-spacing:2px;">PTP</span>
                </td></tr>
                
                <!-- Main Card -->
                <tr><td style="background:#111213;border-radius:12px;border:1px solid #2A2A2A;overflow:hidden;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                        
                        <!-- Header -->
                        <tr><td style="padding:32px 32px 16px;">
                            <table cellpadding="0" cellspacing="0" border="0"><tr>
                                <td style="padding-right:16px;vertical-align:middle;">
                                    <img src="' . esc_url($avatar) . '" width="56" height="56" style="width:56px;height:56px;border-radius:50%;border:2px solid #FCB900;object-fit:cover;display:block;" alt="">
                                </td>
                                <td style="vertical-align:middle;">
                                    <p style="margin:0 0 2px;font-family:Oswald,sans-serif;font-size:20px;font-weight:700;color:#fff;text-transform:uppercase;">' . esc_html($first_name) . '</p>
                                    <p style="margin:0;font-size:12px;color:#9CA3AF;">WEEKLY SCHEDULE CHECK-IN</p>
                                </td>
                            </tr></table>
                        </td></tr>
                        
                        <!-- Status Badge -->
                        <tr><td style="padding:8px 32px 16px;">
                            <span style="display:inline-block;background:' . $urgency_bg . ';color:' . $urgency_color . ';font-size:11px;font-weight:700;padding:4px 12px;border-radius:4px;letter-spacing:0.5px;font-family:Oswald,sans-serif;">' . $urgency_label . '</span>
                        </td></tr>
                        
                        <!-- Nudge Message -->
                        <tr><td style="padding:0 32px 24px;">
                            <p style="margin:0;font-size:15px;color:#E5E7EB;line-height:1.6;">' . esc_html($nudge) . '</p>
                        </td></tr>
                        
                        <!-- Quick Stats -->
                        <tr><td style="padding:0 32px 24px;">
                            <table cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
                                <td width="50%" style="background:#1A1A1A;border-radius:8px 0 0 8px;border:1px solid #333;border-right:none;padding:16px;text-align:center;">
                                    <p style="margin:0;font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:' . ($avail_count === 0 ? '#EF4444' : '#FCB900') . ';">' . $avail_count . '</p>
                                    <p style="margin:4px 0 0;font-size:11px;color:#9CA3AF;text-transform:uppercase;letter-spacing:0.5px;">Open Slots</p>
                                </td>
                                <td width="50%" style="background:#1A1A1A;border-radius:0 8px 8px 0;border:1px solid #333;padding:16px;text-align:center;">
                                    <p style="margin:0;font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:#FCB900;">' . $upcoming . '</p>
                                    <p style="margin:4px 0 0;font-size:11px;color:#9CA3AF;text-transform:uppercase;letter-spacing:0.5px;">This Week</p>
                                </td>
                            </tr></table>
                        </td></tr>
                        
                        ' . $stats_html . '
                        
                        <!-- CTA Button -->
                        <tr><td style="padding:0 32px 32px;">
                            <table cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr><td align="center" style="background:#FCB900;border-radius:8px;">
                                    <a href="' . esc_url($dashboard_url) . '" style="display:block;padding:14px 24px;font-family:Oswald,sans-serif;font-size:15px;font-weight:700;color:#0A0A0A;text-decoration:none;text-transform:uppercase;letter-spacing:0.5px;">Update My Schedule</a>
                                </td></tr>
                            </table>
                        </td></tr>
                        
                        <!-- Tip -->
                        <tr><td style="padding:0 32px 24px;">
                            <p style="margin:0;font-size:12px;color:#6B7280;line-height:1.5;">Tip: Trainers with 5+ open slots get 3x more bookings. Keep your availability fresh and parents will find you faster.</p>
                        </td></tr>
                        
                    </table>
                </td></tr>
                
                <!-- Footer -->
                <tr><td style="padding:24px;text-align:center;">
                    <p style="margin:0;font-size:11px;color:#4B5563;line-height:1.5;">
                        You\'re receiving this because you\'re an active PTP trainer.<br>
                        <a href="' . esc_url(home_url('/trainer-dashboard/')) . '" style="color:#FCB900;text-decoration:none;">Open Dashboard</a>
                    </p>
                </td></tr>
                
            </table>
        </td></tr>
    </table>
</body>
</html>';
        
        return $html;
    }
}
