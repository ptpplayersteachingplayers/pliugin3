<?php
/**
 * PTP Trainer Payouts - Stripe Connect Only
 * Automatic instant payouts via Stripe Connect
 * Version 26.0
 */

defined('ABSPATH') || exit;

class PTP_Trainer_Payouts {

    /**
     * Minimum payout amount
     */
    const MIN_PAYOUT = 1;

    public static function init() {
        // AJAX handlers for trainer payout settings
        add_action('wp_ajax_ptp_start_stripe_onboarding', array(__CLASS__, 'ajax_start_stripe_onboarding'));
        add_action('wp_ajax_ptp_get_stripe_status', array(__CLASS__, 'ajax_get_stripe_status'));
        add_action('wp_ajax_ptp_get_trainer_payout_info', array(__CLASS__, 'ajax_get_trainer_payout_info'));
        add_action('wp_ajax_ptp_get_stripe_dashboard_link', array(__CLASS__, 'ajax_get_stripe_dashboard_link'));
    }

    /**
     * Get trainer's payout configuration
     */
    public static function get_trainer_payout_info($trainer_id) {
        global $wpdb;

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));

        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found');
        }

        $is_configured = !empty($trainer->stripe_account_id) && $trainer->stripe_payouts_enabled;

        return array(
            'method' => 'stripe',
            'method_name' => 'Stripe Connect',
            'processing_time' => 'Instant after session confirmation',
            'min_payout' => self::MIN_PAYOUT,
            'is_configured' => $is_configured,
            'stripe_account_id' => $trainer->stripe_account_id ?? '',
            'charges_enabled' => (bool) ($trainer->stripe_charges_enabled ?? false),
            'payouts_enabled' => (bool) ($trainer->stripe_payouts_enabled ?? false),
            'onboarding_complete' => $is_configured,
        );
    }

    /**
     * Check if trainer can receive payouts
     */
    public static function trainer_can_receive_payouts($trainer_id) {
        $info = self::get_trainer_payout_info($trainer_id);

        if (is_wp_error($info)) {
            return false;
        }

        return $info['is_configured'];
    }

    /**
     * Create a payout (transfer to trainer's Stripe account)
     */
    public static function create_payout($trainer_id, $amount, $booking_ids = array(), $description = '') {
        global $wpdb;

        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_connect_enabled()) {
            return new WP_Error('stripe_not_enabled', 'Stripe Connect is not enabled');
        }

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));

        if (!$trainer) {
            return new WP_Error('trainer_not_found', 'Trainer not found');
        }

        if (empty($trainer->stripe_account_id)) {
            return new WP_Error('no_stripe_account', 'Trainer has not connected their Stripe account');
        }

        if (!$trainer->stripe_payouts_enabled) {
            return new WP_Error('payouts_not_enabled', 'Trainer\'s Stripe account is not fully set up for payouts');
        }

        if ($amount < self::MIN_PAYOUT) {
            return new WP_Error('below_minimum', sprintf(
                'Payout amount ($%.2f) is below minimum ($%.2f)',
                $amount,
                self::MIN_PAYOUT
            ));
        }

        // Create payout record
        $wpdb->insert(
            $wpdb->prefix . 'ptp_payouts',
            array(
                'trainer_id' => $trainer_id,
                'amount' => $amount,
                'status' => 'processing',
                'payout_method' => 'stripe',
                'payout_reference' => '',
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%f', '%s', '%s', '%s', '%s')
        );

        $payout_id = $wpdb->insert_id;

        if (!$payout_id) {
            return new WP_Error('db_error', 'Failed to create payout record');
        }

        // Link booking IDs to this payout
        if (!empty($booking_ids)) {
            foreach ($booking_ids as $booking_id) {
                $wpdb->insert(
                    $wpdb->prefix . 'ptp_payout_items',
                    array(
                        'payout_id' => $payout_id,
                        'booking_id' => $booking_id,
                    ),
                    array('%d', '%d')
                );
            }
        }

        // Process the transfer via Stripe
        $transfer_description = $description ?: 'PTP Training Payout #' . $payout_id;
        $amount_cents = round($amount * 100);

        $transfer = PTP_Stripe::create_transfer(
            $amount_cents,
            $trainer->stripe_account_id,
            $transfer_description
        );

        if (is_wp_error($transfer)) {
            // Update payout as failed
            $wpdb->update(
                $wpdb->prefix . 'ptp_payouts',
                array(
                    'status' => 'failed',
                    'notes' => $transfer->get_error_message(),
                ),
                array('id' => $payout_id)
            );

            return $transfer;
        }

        // Update payout as completed
        $wpdb->update(
            $wpdb->prefix . 'ptp_payouts',
            array(
                'status' => 'completed',
                'payout_reference' => $transfer['id'],
                'processed_at' => current_time('mysql'),
            ),
            array('id' => $payout_id)
        );

        // Send notification to trainer
        if (class_exists('PTP_Email')) {
            PTP_Email::send_payout_completed($payout_id);
        }

        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            PTP_SMS::send_payout_notification($payout_id);
        }

        return array(
            'payout_id' => $payout_id,
            'transfer_id' => $transfer['id'],
            'amount' => $amount,
            'status' => 'completed',
        );
    }

    /**
     * Get trainer's payout history
     */
    public static function get_trainer_payouts($trainer_id, $limit = 20) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_payouts
            WHERE trainer_id = %d
            ORDER BY created_at DESC
            LIMIT %d
        ", $trainer_id, $limit));
    }

    /**
     * Get trainer's pending balance
     */
    public static function get_pending_balance($trainer_id) {
        global $wpdb;

        // Get completed sessions that haven't been paid out yet
        $pending = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(trainer_payout), 0)
            FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d
            AND status = 'completed'
            AND payment_status = 'paid'
            AND payout_status = 'pending'
        ", $trainer_id));
        
        // Add referral bonuses
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if ($trainer && $trainer->user_id) {
            $referral_bonus = get_user_meta($trainer->user_id, 'ptp_referral_bonus_pending', true) ?: 0;
            $pending += floatval($referral_bonus);
        }

        return floatval($pending);
    }

    /**
     * Get trainer's total earnings
     */
    public static function get_total_earnings($trainer_id) {
        global $wpdb;

        $total = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM {$wpdb->prefix}ptp_payouts
            WHERE trainer_id = %d
            AND status = 'completed'
        ", $trainer_id));

        return floatval($total);
    }

    /**
     * AJAX: Start Stripe onboarding
     */
    public static function ajax_start_stripe_onboarding() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }

        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));

        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }

        if (!class_exists('PTP_Stripe')) {
            wp_send_json_error(array('message' => 'Payment system not configured'));
        }

        $result = PTP_Stripe::start_connect_onboarding($trainer->id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'url' => $result['url'],
            'account_id' => $result['account_id'],
        ));
    }

    /**
     * AJAX: Get Stripe account status
     */
    public static function ajax_get_stripe_status() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }

        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));

        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }

        $info = self::get_trainer_payout_info($trainer->id);

        if (is_wp_error($info)) {
            wp_send_json_error(array('message' => $info->get_error_message()));
        }

        // Add balance info
        $info['pending_balance'] = self::get_pending_balance($trainer->id);
        $info['total_earnings'] = self::get_total_earnings($trainer->id);

        wp_send_json_success($info);
    }

    /**
     * AJAX: Get trainer's payout info
     */
    public static function ajax_get_trainer_payout_info() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }

        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));

        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }

        $info = self::get_trainer_payout_info($trainer->id);

        if (is_wp_error($info)) {
            wp_send_json_error(array('message' => $info->get_error_message()));
        }

        // Add balance and history
        $info['pending_balance'] = self::get_pending_balance($trainer->id);
        $info['total_earnings'] = self::get_total_earnings($trainer->id);
        $info['recent_payouts'] = self::get_trainer_payouts($trainer->id, 10);

        wp_send_json_success($info);
    }

    /**
     * AJAX: Get Stripe Express Dashboard link
     */
    public static function ajax_get_stripe_dashboard_link() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }

        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));

        if (!$trainer || empty($trainer->stripe_account_id)) {
            wp_send_json_error(array('message' => 'Stripe account not connected'));
        }

        if (!class_exists('PTP_Stripe')) {
            wp_send_json_error(array('message' => 'Payment system not configured'));
        }

        $link = PTP_Stripe::create_login_link($trainer->stripe_account_id);

        if (is_wp_error($link)) {
            wp_send_json_error(array('message' => $link->get_error_message()));
        }

        wp_send_json_success(array('url' => $link['url']));
    }

    /**
     * Get pending payout queue for admin
     * Returns trainers with pending payouts
     */
    public static function get_payout_queue() {
        global $wpdb;

        // Get pending payouts from ptp_payouts table
        $payouts = $wpdb->get_results("
            SELECT 
                p.*,
                t.display_name as trainer_name,
                t.email as trainer_email,
                t.payout_method,
                t.payout_venmo,
                t.payout_paypal,
                t.payout_zelle,
                t.payout_cashapp,
                t.payout_bank_name,
                t.payout_bank_routing,
                t.payout_bank_account,
                t.stripe_account_id
            FROM {$wpdb->prefix}ptp_payouts p
            JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
            WHERE p.status = 'pending'
            ORDER BY p.created_at ASC
        ");

        return $payouts ?: array();
    }

    /**
     * Get payout instructions for a specific payout
     */
    public static function get_payout_instructions($payout) {
        $method = $payout->payout_method ?? 'venmo';
        
        $instructions = array(
            'platform' => 'Unknown',
            'recipient' => 'Not configured',
            'note' => 'PTP Training Payout #' . ($payout->id ?? ''),
            'method' => $method,
        );

        switch ($method) {
            case 'venmo':
                $instructions['platform'] = 'Venmo';
                $instructions['recipient'] = $payout->payout_venmo ?? '';
                $instructions['note'] = 'PTP Training Session Payout';
                break;

            case 'paypal':
                $instructions['platform'] = 'PayPal';
                $instructions['recipient'] = $payout->payout_paypal ?? '';
                break;

            case 'zelle':
                $instructions['platform'] = 'Zelle';
                $instructions['recipient'] = $payout->payout_zelle ?? '';
                break;

            case 'cashapp':
                $instructions['platform'] = 'Cash App';
                $instructions['recipient'] = $payout->payout_cashapp ?? '';
                break;

            case 'direct_deposit':
                $instructions['platform'] = 'Direct Deposit';
                $instructions['recipient'] = ($payout->payout_bank_name ?? 'Bank') . ' - ' . 
                    substr($payout->payout_bank_account ?? '', -4);
                $instructions['bank_name'] = $payout->payout_bank_name ?? '';
                $instructions['routing'] = $payout->payout_bank_routing ?? '';
                $instructions['account'] = $payout->payout_bank_account ?? '';
                break;

            case 'stripe':
                $instructions['platform'] = 'Stripe Connect';
                $instructions['recipient'] = $payout->stripe_account_id ?? 'Not connected';
                $instructions['automatic'] = true;
                break;
        }

        return $instructions;
    }
}

// Initialize
PTP_Trainer_Payouts::init();
