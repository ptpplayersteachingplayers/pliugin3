<?php
/**
 * PTP Stripe Subscriptions
 * Handles recurring billing for training subscriptions
 * - Weekly/biweekly/monthly billing
 * - Pause/resume subscriptions
 * - Prorated changes
 * 
 * Version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_Subscriptions {
    
    const PLATFORM_FEE_PERCENT = 0.15; // 15% platform fee for subscriptions (ongoing rate)
    
    public static function init() {
        // AJAX handlers
        add_action('wp_ajax_ptp_create_subscription', array(__CLASS__, 'ajax_create_subscription'));
        add_action('wp_ajax_ptp_pause_subscription', array(__CLASS__, 'ajax_pause_subscription'));
        add_action('wp_ajax_ptp_resume_subscription', array(__CLASS__, 'ajax_resume_subscription'));
        add_action('wp_ajax_ptp_cancel_subscription', array(__CLASS__, 'ajax_cancel_subscription'));
        add_action('wp_ajax_ptp_update_subscription', array(__CLASS__, 'ajax_update_subscription'));
        add_action('wp_ajax_ptp_get_subscription', array(__CLASS__, 'ajax_get_subscription'));
        
        // Stripe webhook handlers
        add_action('ptp_stripe_webhook_invoice.payment_succeeded', array(__CLASS__, 'handle_invoice_paid'));
        add_action('ptp_stripe_webhook_invoice.payment_failed', array(__CLASS__, 'handle_invoice_failed'));
        add_action('ptp_stripe_webhook_customer.subscription.updated', array(__CLASS__, 'handle_subscription_updated'));
        add_action('ptp_stripe_webhook_customer.subscription.deleted', array(__CLASS__, 'handle_subscription_deleted'));
        
        // Cron for session generation
        add_action('ptp_generate_subscription_sessions', array(__CLASS__, 'generate_upcoming_sessions'));
        
        if (!wp_next_scheduled('ptp_generate_subscription_sessions')) {
            wp_schedule_event(time(), 'daily', 'ptp_generate_subscription_sessions');
        }
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_subscriptions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED NOT NULL,
            
            -- Schedule
            frequency enum('weekly','biweekly','twice_weekly','monthly') DEFAULT 'weekly',
            preferred_day tinyint(1) NOT NULL,
            preferred_day_2 tinyint(1) DEFAULT NULL,
            preferred_time time NOT NULL,
            preferred_time_2 time DEFAULT NULL,
            lesson_length int(11) DEFAULT 60,
            participants int(11) DEFAULT 1,
            location_id bigint(20) UNSIGNED DEFAULT NULL,
            location_text varchar(255) DEFAULT '',
            
            -- Pricing
            price_per_lesson decimal(10,2) NOT NULL,
            platform_fee decimal(10,2) NOT NULL,
            trainer_payout decimal(10,2) NOT NULL,
            
            -- Stripe
            stripe_subscription_id varchar(255) DEFAULT '',
            stripe_customer_id varchar(255) DEFAULT '',
            stripe_price_id varchar(255) DEFAULT '',
            
            -- Status
            status enum('active','paused','cancelled','past_due') DEFAULT 'active',
            
            -- Tracking
            sessions_completed int(11) DEFAULT 0,
            total_billed decimal(10,2) DEFAULT 0,
            
            -- Dates
            start_date date NOT NULL,
            paused_at datetime DEFAULT NULL,
            pause_resumes_at date DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            cancellation_reason text,
            next_billing_date date DEFAULT NULL,
            
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY parent_id (parent_id),
            KEY trainer_id (trainer_id),
            KEY status (status),
            KEY stripe_subscription_id (stripe_subscription_id)
        ) $charset_collate;";
        dbDelta($sql);
    }
    
    /**
     * Create a new subscription
     */
    public static function create($data) {
        global $wpdb;
        
        // Validate required fields
        $required = array('parent_id', 'trainer_id', 'player_id', 'preferred_day', 'preferred_time', 'price_per_lesson');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Missing required field: $field");
            }
        }
        
        // Get trainer for Stripe account
        $trainer = PTP_Trainer::get($data['trainer_id']);
        if (!$trainer) {
            return new WP_Error('trainer_not_found', 'Trainer not found');
        }
        
        if (!$trainer->stripe_account_id || !$trainer->stripe_payouts_enabled) {
            return new WP_Error('trainer_not_ready', 'Trainer has not completed payment setup');
        }
        
        // Get or create Stripe customer
        $parent = PTP_Parent::get($data['parent_id']);
        $user = get_userdata($parent->user_id);
        
        $stripe_customer_id = get_user_meta($parent->user_id, 'ptp_stripe_customer_id', true);
        
        if (!$stripe_customer_id) {
            $customer = self::create_stripe_customer($user->user_email, $user->display_name);
            if (is_wp_error($customer)) {
                return $customer;
            }
            $stripe_customer_id = $customer['id'];
            update_user_meta($parent->user_id, 'ptp_stripe_customer_id', $stripe_customer_id);
        }
        
        // Calculate pricing
        $price_per_lesson = floatval($data['price_per_lesson']);
        $platform_fee = round($price_per_lesson * self::PLATFORM_FEE_PERCENT, 2);
        $trainer_payout = $price_per_lesson - $platform_fee;
        
        // Determine billing interval
        $frequency = sanitize_text_field($data['frequency'] ?? 'weekly');
        $interval = 'week';
        $interval_count = 1;
        
        switch ($frequency) {
            case 'biweekly':
                $interval_count = 2;
                break;
            case 'twice_weekly':
                $interval_count = 1; // Still billed weekly, but 2 sessions
                break;
            case 'monthly':
                $interval = 'month';
                $interval_count = 1;
                break;
        }
        
        // Create Stripe Price (for recurring billing)
        $stripe_price = self::create_stripe_price(
            $price_per_lesson,
            $interval,
            $interval_count,
            $trainer->display_name . ' Training'
        );
        
        if (is_wp_error($stripe_price)) {
            return $stripe_price;
        }
        
        // Create Stripe Subscription
        $stripe_subscription = self::create_stripe_subscription(
            $stripe_customer_id,
            $stripe_price['id'],
            $trainer->stripe_account_id,
            $trainer_payout,
            $data['payment_method_id'] ?? null
        );
        
        if (is_wp_error($stripe_subscription)) {
            return $stripe_subscription;
        }
        
        // Calculate start date
        $start_date = !empty($data['start_date']) 
            ? sanitize_text_field($data['start_date'])
            : date('Y-m-d', strtotime('next ' . self::day_name($data['preferred_day'])));
        
        // Insert subscription record
        $result = $wpdb->insert(
            $wpdb->prefix . 'ptp_subscriptions',
            array(
                'parent_id' => intval($data['parent_id']),
                'trainer_id' => intval($data['trainer_id']),
                'player_id' => intval($data['player_id']),
                'frequency' => $frequency,
                'preferred_day' => intval($data['preferred_day']),
                'preferred_day_2' => $frequency === 'twice_weekly' ? intval($data['preferred_day_2'] ?? null) : null,
                'preferred_time' => sanitize_text_field($data['preferred_time']),
                'preferred_time_2' => $frequency === 'twice_weekly' ? sanitize_text_field($data['preferred_time_2'] ?? '') : null,
                'lesson_length' => intval($data['lesson_length'] ?? 60),
                'participants' => intval($data['participants'] ?? 1),
                'location_text' => sanitize_text_field($data['location'] ?? ''),
                'price_per_lesson' => $price_per_lesson,
                'platform_fee' => $platform_fee,
                'trainer_payout' => $trainer_payout,
                'stripe_subscription_id' => $stripe_subscription['id'],
                'stripe_customer_id' => $stripe_customer_id,
                'stripe_price_id' => $stripe_price['id'],
                'status' => 'active',
                'start_date' => $start_date,
                'next_billing_date' => $start_date,
            ),
            array('%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if (!$result) {
            // Try to cancel Stripe subscription since DB insert failed
            self::cancel_stripe_subscription($stripe_subscription['id']);
            return new WP_Error('db_error', 'Failed to create subscription record');
        }
        
        $subscription_id = $wpdb->insert_id;
        
        // Generate first few sessions
        self::generate_sessions_for_subscription($subscription_id, 4);
        
        // Send confirmation
        do_action('ptp_subscription_created', $subscription_id);
        
        return array(
            'subscription_id' => $subscription_id,
            'stripe_subscription_id' => $stripe_subscription['id'],
            'start_date' => $start_date,
        );
    }
    
    /**
     * Pause a subscription
     */
    public static function pause($subscription_id, $resume_date = null) {
        global $wpdb;
        
        $subscription = self::get($subscription_id);
        if (!$subscription) {
            return new WP_Error('not_found', 'Subscription not found');
        }
        
        if ($subscription->status !== 'active') {
            return new WP_Error('invalid_status', 'Only active subscriptions can be paused');
        }
        
        // Pause in Stripe
        $result = self::pause_stripe_subscription($subscription->stripe_subscription_id);
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Update local record
        $wpdb->update(
            $wpdb->prefix . 'ptp_subscriptions',
            array(
                'status' => 'paused',
                'paused_at' => current_time('mysql'),
                'pause_resumes_at' => $resume_date,
            ),
            array('id' => $subscription_id)
        );
        
        // Cancel upcoming unpaid sessions
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array('status' => 'cancelled', 'cancellation_reason' => 'Subscription paused'),
            array(
                'subscription_id' => $subscription_id,
                'status' => 'pending',
            )
        );
        
        do_action('ptp_subscription_paused', $subscription_id);
        
        return true;
    }
    
    /**
     * Resume a paused subscription
     */
    public static function resume($subscription_id) {
        global $wpdb;
        
        $subscription = self::get($subscription_id);
        if (!$subscription) {
            return new WP_Error('not_found', 'Subscription not found');
        }
        
        if ($subscription->status !== 'paused') {
            return new WP_Error('invalid_status', 'Only paused subscriptions can be resumed');
        }
        
        // Resume in Stripe
        $result = self::resume_stripe_subscription($subscription->stripe_subscription_id);
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Update local record
        $wpdb->update(
            $wpdb->prefix . 'ptp_subscriptions',
            array(
                'status' => 'active',
                'paused_at' => null,
                'pause_resumes_at' => null,
            ),
            array('id' => $subscription_id)
        );
        
        // Generate upcoming sessions
        self::generate_sessions_for_subscription($subscription_id, 4);
        
        do_action('ptp_subscription_resumed', $subscription_id);
        
        return true;
    }
    
    /**
     * Cancel a subscription
     */
    public static function cancel($subscription_id, $reason = '', $immediate = false) {
        global $wpdb;
        
        $subscription = self::get($subscription_id);
        if (!$subscription) {
            return new WP_Error('not_found', 'Subscription not found');
        }
        
        // Cancel in Stripe
        $result = self::cancel_stripe_subscription($subscription->stripe_subscription_id, $immediate);
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Update local record
        $wpdb->update(
            $wpdb->prefix . 'ptp_subscriptions',
            array(
                'status' => 'cancelled',
                'cancelled_at' => current_time('mysql'),
                'cancellation_reason' => sanitize_textarea_field($reason),
            ),
            array('id' => $subscription_id)
        );
        
        // Handle future sessions
        if ($immediate) {
            // Cancel all future sessions
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('status' => 'cancelled', 'cancellation_reason' => 'Subscription cancelled'),
                array(
                    'subscription_id' => $subscription_id,
                    'status' => 'pending',
                )
            );
        }
        
        do_action('ptp_subscription_cancelled', $subscription_id);
        
        return true;
    }
    
    /**
     * Get subscription by ID
     */
    public static function get($subscription_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_subscriptions WHERE id = %d",
            $subscription_id
        ));
    }
    
    /**
     * Get subscriptions for a parent
     */
    public static function get_for_parent($parent_id, $status = null) {
        global $wpdb;
        
        $where = "parent_id = %d";
        $params = array($parent_id);
        
        if ($status) {
            $where .= " AND status = %s";
            $params[] = $status;
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
                    p.name as player_name
             FROM {$wpdb->prefix}ptp_subscriptions s
             JOIN {$wpdb->prefix}ptp_trainers t ON s.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_players p ON s.player_id = p.id
             WHERE $where
             ORDER BY s.created_at DESC",
            ...$params
        ));
    }
    
    /**
     * Get subscriptions for a trainer
     */
    public static function get_for_trainer($trainer_id, $status = null) {
        global $wpdb;
        
        $where = "trainer_id = %d";
        $params = array($trainer_id);
        
        if ($status) {
            $where .= " AND status = %s";
            $params[] = $status;
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, pa.display_name as parent_name,
                    p.name as player_name
             FROM {$wpdb->prefix}ptp_subscriptions s
             JOIN {$wpdb->prefix}ptp_parents pa ON s.parent_id = pa.id
             JOIN {$wpdb->prefix}ptp_players p ON s.player_id = p.id
             WHERE $where
             ORDER BY s.created_at DESC",
            ...$params
        ));
    }
    
    /**
     * Generate upcoming sessions for a subscription
     */
    public static function generate_sessions_for_subscription($subscription_id, $weeks_ahead = 4) {
        global $wpdb;
        
        $subscription = self::get($subscription_id);
        if (!$subscription || $subscription->status !== 'active') {
            return;
        }
        
        $start_date = max(strtotime($subscription->start_date), strtotime('today'));
        $end_date = strtotime("+{$weeks_ahead} weeks");
        
        $created = 0;
        $current = $start_date;
        
        while ($current <= $end_date) {
            $day_of_week = date('w', $current);
            
            // Check if this day matches subscription schedule
            $should_create = ($day_of_week == $subscription->preferred_day);
            
            if ($subscription->frequency === 'twice_weekly' && $subscription->preferred_day_2 !== null) {
                $should_create = $should_create || ($day_of_week == $subscription->preferred_day_2);
            }
            
            if ($subscription->frequency === 'biweekly') {
                // Only every other week
                $week_diff = floor(($current - $start_date) / (7 * 24 * 60 * 60));
                $should_create = $should_create && ($week_diff % 2 === 0);
            }
            
            if ($should_create) {
                $session_date = date('Y-m-d', $current);
                $time = ($day_of_week == $subscription->preferred_day_2 && $subscription->preferred_time_2) 
                    ? $subscription->preferred_time_2 
                    : $subscription->preferred_time;
                
                // Check if session already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_bookings 
                     WHERE subscription_id = %d AND session_date = %s",
                    $subscription_id, $session_date
                ));
                
                if (!$exists) {
                    // Create booking
                    $booking_data = array(
                        'trainer_id' => $subscription->trainer_id,
                        'parent_id' => $subscription->parent_id,
                        'player_id' => $subscription->player_id,
                        'session_date' => $session_date,
                        'start_time' => $time,
                        'duration_minutes' => $subscription->lesson_length,
                        'location' => $subscription->location_text,
                        'is_recurring' => 1,
                        'recurring_id' => $subscription_id,
                    );
                    
                    $result = PTP_Booking::create($booking_data);
                    
                    if (!is_wp_error($result)) {
                        // Link to subscription
                        $wpdb->update(
                            $wpdb->prefix . 'ptp_bookings',
                            array('subscription_id' => $subscription_id),
                            array('id' => $result)
                        );
                        $created++;
                    }
                }
            }
            
            $current = strtotime('+1 day', $current);
        }
        
        return $created;
    }
    
    /**
     * Generate sessions for all active subscriptions (cron job)
     */
    public static function generate_upcoming_sessions() {
        global $wpdb;
        
        $active_subscriptions = $wpdb->get_col("
            SELECT id FROM {$wpdb->prefix}ptp_subscriptions 
            WHERE status = 'active'
        ");
        
        $total_created = 0;
        
        foreach ($active_subscriptions as $sub_id) {
            $created = self::generate_sessions_for_subscription($sub_id, 4);
            $total_created += $created;
        }
        
        ptp_log("PTP: Generated {$total_created} sessions for " . count($active_subscriptions) . " subscriptions");
    }
    
    // ==========================================
    // STRIPE API HELPERS
    // ==========================================
    
    private static function create_stripe_customer($email, $name) {
        if (!class_exists('PTP_Stripe')) {
            return new WP_Error('stripe_not_configured', 'Stripe is not configured');
        }
        
        return PTP_Stripe::api_request('customers', 'POST', array(
            'email' => $email,
            'name' => $name,
            'metadata[source]' => 'ptp_subscription',
        ));
    }
    
    private static function create_stripe_price($amount, $interval, $interval_count, $product_name) {
        $amount_cents = round($amount * 100);
        
        return PTP_Stripe::api_request('prices', 'POST', array(
            'unit_amount' => $amount_cents,
            'currency' => 'usd',
            'recurring[interval]' => $interval,
            'recurring[interval_count]' => $interval_count,
            'product_data[name]' => $product_name,
        ));
    }
    
    private static function create_stripe_subscription($customer_id, $price_id, $connected_account_id, $trainer_amount, $payment_method_id = null) {
        $data = array(
            'customer' => $customer_id,
            'items[0][price]' => $price_id,
            'transfer_data[destination]' => $connected_account_id,
            'transfer_data[amount_percent]' => round((1 - self::PLATFORM_FEE_PERCENT) * 100),
            'payment_behavior' => 'default_incomplete',
            'expand[]' => 'latest_invoice.payment_intent',
        );
        
        if ($payment_method_id) {
            $data['default_payment_method'] = $payment_method_id;
        }
        
        return PTP_Stripe::api_request('subscriptions', 'POST', $data);
    }
    
    private static function pause_stripe_subscription($subscription_id) {
        return PTP_Stripe::api_request("subscriptions/{$subscription_id}", 'POST', array(
            'pause_collection[behavior]' => 'void',
        ));
    }
    
    private static function resume_stripe_subscription($subscription_id) {
        return PTP_Stripe::api_request("subscriptions/{$subscription_id}", 'POST', array(
            'pause_collection' => '',
        ));
    }
    
    private static function cancel_stripe_subscription($subscription_id, $immediate = false) {
        if ($immediate) {
            return PTP_Stripe::api_request("subscriptions/{$subscription_id}", 'DELETE');
        }
        
        return PTP_Stripe::api_request("subscriptions/{$subscription_id}", 'POST', array(
            'cancel_at_period_end' => true,
        ));
    }
    
    // ==========================================
    // WEBHOOK HANDLERS
    // ==========================================
    
    public static function handle_invoice_paid($event) {
        $invoice = $event['data']['object'];
        $stripe_subscription_id = $invoice['subscription'] ?? '';
        
        if (!$stripe_subscription_id) return;
        
        global $wpdb;
        
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_subscriptions WHERE stripe_subscription_id = %s",
            $stripe_subscription_id
        ));
        
        if ($subscription) {
            // Update billing tracking
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_subscriptions 
                 SET total_billed = total_billed + %f,
                     next_billing_date = DATE_ADD(next_billing_date, INTERVAL 1 WEEK)
                 WHERE id = %d",
                $invoice['amount_paid'] / 100,
                $subscription->id
            ));
            
            do_action('ptp_subscription_payment_succeeded', $subscription->id, $invoice);
        }
    }
    
    public static function handle_invoice_failed($event) {
        $invoice = $event['data']['object'];
        $stripe_subscription_id = $invoice['subscription'] ?? '';
        
        if (!$stripe_subscription_id) return;
        
        global $wpdb;
        
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_subscriptions WHERE stripe_subscription_id = %s",
            $stripe_subscription_id
        ));
        
        if ($subscription) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_subscriptions',
                array('status' => 'past_due'),
                array('id' => $subscription->id)
            );
            
            do_action('ptp_subscription_payment_failed', $subscription->id, $invoice);
        }
    }
    
    public static function handle_subscription_deleted($event) {
        $stripe_subscription = $event['data']['object'];
        
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_subscriptions',
            array(
                'status' => 'cancelled',
                'cancelled_at' => current_time('mysql'),
            ),
            array('stripe_subscription_id' => $stripe_subscription['id'])
        );
    }
    
    // ==========================================
    // AJAX HANDLERS
    // ==========================================
    
    public static function ajax_create_subscription() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in to create a subscription'));
        }
        
        $result = self::create($_POST);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    public static function ajax_pause_subscription() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        $resume_date = sanitize_text_field($_POST['resume_date'] ?? '');
        
        $result = self::pause($subscription_id, $resume_date ?: null);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => 'Subscription paused'));
    }
    
    public static function ajax_resume_subscription() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        
        $result = self::resume($subscription_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => 'Subscription resumed'));
    }
    
    public static function ajax_cancel_subscription() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $subscription_id = intval($_POST['subscription_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        $immediate = isset($_POST['immediate']) && $_POST['immediate'] === '1';
        
        $result = self::cancel($subscription_id, $reason, $immediate);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => 'Subscription cancelled'));
    }
    
    public static function ajax_get_subscription() {
        $subscription_id = intval($_GET['subscription_id'] ?? 0);
        
        $subscription = self::get($subscription_id);
        
        if (!$subscription) {
            wp_send_json_error(array('message' => 'Subscription not found'));
        }
        
        // Security check
        $user_id = get_current_user_id();
        $parent = PTP_Parent::get_by_user_id($user_id);
        $trainer = PTP_Trainer::get_by_user_id($user_id);
        
        if (
            (!$parent || $parent->id != $subscription->parent_id) &&
            (!$trainer || $trainer->id != $subscription->trainer_id) &&
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(array('message' => 'Access denied'));
        }
        
        wp_send_json_success($subscription);
    }
    
    // ==========================================
    // HELPERS
    // ==========================================
    
    private static function day_name($day_num) {
        $days = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
        return $days[$day_num] ?? 'Monday';
    }
}

// Initialize
PTP_Subscriptions::init();
