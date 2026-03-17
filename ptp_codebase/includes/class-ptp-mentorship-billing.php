<?php
/**
 * PTP Mentorship Billing — Stripe purchase, cancel, webhooks, add-ons
 * 
 * Handles:
 *  - Package purchase (Step 3 — weekly Stripe billing)
 *  - Cancellation with A2A policy
 *  - Add-on purchases (video review packs, film breakdowns)
 *  - Stripe webhook handlers (invoice paid, payment failed, subscription ended)
 *  - Expiring package cron check
 */

if (!defined('ABSPATH')) exit;

class PTP_Mentorship_Billing {

    public static function init() {
        // Parent AJAX
        add_action('wp_ajax_ptp_mentorship_purchase', array(__CLASS__, 'ajax_purchase_package'));
        add_action('wp_ajax_ptp_mentorship_cancel', array(__CLASS__, 'ajax_cancel'));
        add_action('wp_ajax_ptp_mentorship_purchase_addon', array(__CLASS__, 'ajax_purchase_addon'));
        add_action('wp_ajax_ptp_mentorship_verify_payment', array(__CLASS__, 'ajax_verify_payment')); // v233 H2: 3DS return

        // Stripe Webhooks
        add_action('ptp_stripe_webhook_invoice.payment_succeeded', array(__CLASS__, 'handle_invoice_paid'));
        add_action('ptp_stripe_webhook_invoice.payment_failed', array(__CLASS__, 'handle_payment_failed'));
        add_action('ptp_stripe_webhook_customer.subscription.deleted', array(__CLASS__, 'handle_subscription_ended'));

        // Cron
        add_action('ptp_mentorship_check_expiring', array(__CLASS__, 'check_expiring_packages'));
        add_action('ptp_mentorship_check_session_gaps', array(__CLASS__, 'check_session_gaps'));
        if (!wp_next_scheduled('ptp_mentorship_check_session_gaps')) {
            wp_schedule_event(time() + 7200, 'daily', 'ptp_mentorship_check_session_gaps');
        }
    }

    // ================================================================
    // PURCHASE PACKAGE (Step 3 — weekly Stripe billing)
    // ================================================================
    public static function ajax_purchase_package() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $pair_id           = intval($_POST['pair_id'] ?? 0);
        $payment_method_id = sanitize_text_field($_POST['payment_method_id'] ?? '');
        $package_key       = sanitize_text_field($_POST['package'] ?? '');

        if (!$pair_id || !$payment_method_id) wp_send_json_error('Missing payment info');

        global $wpdb;
        $parent_id = get_current_user_id();
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND parent_id = %d AND status IN ('intro_done','paused','completed')",
            $pair_id, $parent_id
        ));
        if (!$pair) wp_send_json_error('Complete the free intro call with your coach first.');

        // Allow package change at purchase time
        if ($package_key && isset(PTP_Mentorship::PACKAGES[$package_key])) {
            $pkg = PTP_Mentorship::PACKAGES[$package_key];
            $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array(
                'package_type'           => $package_key,
                'sessions_total'         => $pkg['sessions'],
                'session_length_minutes' => $pkg['session_length'],
                'per_session_price'      => $pkg['per_session'],
            ), array('id' => $pair_id));
            $pair->package_type = $package_key;
            $pair->sessions_total = $pkg['sessions'];
            $pair->per_session_price = $pkg['per_session'];
        }

        // Reset counters on renewal (completed → active)
        if ($pair->status === 'completed') {
            $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array(
                'sessions_completed'     => 0,
                'completed_at'           => null,
                'stripe_subscription_id' => '',
                'stripe_payment_intent'  => '',
            ), array('id' => $pair_id));
            $pair->sessions_completed = 0;
        }

        $package = PTP_Mentorship::PACKAGES[$pair->package_type] ?? PTP_Mentorship::PACKAGES['development'];
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $pair->trainer_id
        ));

        $secret_key = PTP_Mentorship::get_stripe_key();
        if (empty($secret_key)) wp_send_json_error('Payment not configured');

        $user = wp_get_current_user();
        $customer_id = $pair->stripe_customer_id;

        // Create or get Stripe customer
        if (empty($customer_id)) {
            $cust_response = wp_remote_post('https://api.stripe.com/v1/customers', array(
                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                'body' => array(
                    'email' => $user->user_email,
                    'name'  => $user->display_name,
                    'payment_method' => $payment_method_id,
                    'invoice_settings[default_payment_method]' => $payment_method_id,
                    'metadata[ptp_pair_id]'   => $pair_id,
                    'metadata[ptp_parent_id]' => $parent_id,
                ),
            ));
            $cust_data = json_decode(wp_remote_retrieve_body($cust_response), true);
            $customer_id = $cust_data['id'] ?? '';
        } else {
            wp_remote_post("https://api.stripe.com/v1/payment_methods/{$payment_method_id}/attach", array(
                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                'body' => array('customer' => $customer_id),
            ));
        }
        if (empty($customer_id)) wp_send_json_error('Payment setup failed');

        $connect_id = $trainer->stripe_account_id ?? '';
        $is_one_time = ($package['billing'] ?? 'weekly') === 'one-time';

        if ($is_one_time) {
            // ── ONE-TIME: PaymentIntent for single sessions ──
            $pi_body = array(
                'amount'                 => $pair->per_session_price,
                'currency'               => 'usd',
                'customer'               => $customer_id,
                'payment_method'         => $payment_method_id,
                'confirm'                => 'true',
                'description'            => "PTP Mentorship — {$package['name']} ({$package['session_length']}min)",
                'metadata[ptp_pair_id]'  => $pair_id,
                'metadata[ptp_package]'  => $pair->package_type,
                'metadata[ptp_source]'   => $pair->source,
                'return_url'             => home_url('/mentorship-checkout/?pair_id=' . $pair_id . '&success=1'),
            );
            if (!empty($connect_id)) {
                $pi_body['application_fee_amount'] = round($pair->per_session_price * PTP_Mentorship::get_fee());
                $pi_body['transfer_data[destination]'] = $connect_id;
            }

            $pi_response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                'body' => $pi_body,
            ));
            $pi_data = json_decode(wp_remote_retrieve_body($pi_response), true);

            if (!empty($pi_data['error'])) {
                ptp_log('[PTP Mentorship] Stripe single: ' . ($pi_data['error']['message'] ?? 'Unknown'));
                wp_send_json_error('Payment failed: ' . ($pi_data['error']['message'] ?? 'Please try again'));
            }

            $pi_id  = $pi_data['id'] ?? '';
            $pi_status = $pi_data['status'] ?? '';
            $requires_action = $pi_status === 'requires_action';
            $client_secret = $requires_action ? ($pi_data['client_secret'] ?? '') : '';

            if (empty($pi_id)) wp_send_json_error('Payment processing failed');

            $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array(
                'status'                 => $requires_action ? 'paused' : 'active',
                'stripe_payment_intent'  => $pi_id,
                'stripe_customer_id'     => $customer_id,
                'started_at'             => $requires_action ? null : current_time('mysql'),
            ), array('id' => $pair_id));

            if ($requires_action) {
                wp_send_json_success(array(
                    'status' => 'requires_action',
                    'client_secret' => $client_secret,
                ));
            }

            do_action('ptp_mentorship_package_purchased', $pair_id, $pair->source);
            wp_send_json_success(array(
                'status'  => 'active',
                'message' => "You're in! 1 session with {$trainer->display_name}. You'll hear from your coach soon to schedule.",
                'pair_id' => $pair_id,
            ));

        } else {
            // ── RECURRING: Weekly subscription for packages ──

            // Create weekly recurring price
            $price_response = wp_remote_post('https://api.stripe.com/v1/prices', array(
                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                'body' => array(
                    'unit_amount' => $pair->per_session_price,
                    'currency' => 'usd',
                    'recurring[interval]' => 'week',
                    'product_data[name]' => "PTP Mentorship — {$package['name']} ({$package['session_length']}min x {$package['sessions']} sessions)",
                    'metadata[ptp_package]' => $pair->package_type,
                    'metadata[ptp_pair_id]' => $pair_id,
                ),
            ));
            $price_data = json_decode(wp_remote_retrieve_body($price_response), true);
            $price_id = $price_data['id'] ?? '';
            if (empty($price_id)) wp_send_json_error('Price creation failed');

            // Subscription — cancelled programmatically when sessions completed (v233: was time-based cancel_at)
            $sub_body = array(
                'customer' => $customer_id,
                'items[0][price]' => $price_id,
                'metadata[ptp_pair_id]'  => $pair_id,
                'metadata[ptp_package]'  => $pair->package_type,
                'metadata[ptp_source]'   => $pair->source,
                'metadata[ptp_sessions_total]' => $package['sessions'],
                'expand[]' => 'latest_invoice.payment_intent',
            );
            if (!empty($connect_id)) {
                $sub_body['application_fee_percent'] = PTP_Mentorship::get_fee() * 100;
                $sub_body['transfer_data[destination]'] = $connect_id;
            }

            $sub_response = wp_remote_post('https://api.stripe.com/v1/subscriptions', array(
                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                'body' => $sub_body,
            ));
            $sub_data = json_decode(wp_remote_retrieve_body($sub_response), true);

            if (!empty($sub_data['error'])) {
                ptp_log('[PTP Mentorship] Stripe: ' . ($sub_data['error']['message'] ?? 'Unknown'));
                wp_send_json_error('Payment failed: ' . ($sub_data['error']['message'] ?? 'Please try again'));
            }

            $sub_id = $sub_data['id'] ?? '';
            if (empty($sub_id)) wp_send_json_error('Payment processing failed');

            // Handle SCA / 3D Secure
            $pi = $sub_data['latest_invoice']['payment_intent'] ?? null;
            $requires_action = is_array($pi) && ($pi['status'] ?? '') === 'requires_action';
            $client_secret = $requires_action ? ($pi['client_secret'] ?? '') : '';

            $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array(
                'status'                 => $requires_action ? 'paused' : 'active',
                'stripe_subscription_id' => $sub_id,
                'stripe_customer_id'     => $customer_id,
                'started_at'             => $requires_action ? null : current_time('mysql'),
            ), array('id' => $pair_id));

            if ($requires_action) {
                wp_send_json_success(array(
                    'status' => 'requires_action',
                    'client_secret' => $client_secret,
                    'subscription_id' => $sub_id,
                ));
            }

            do_action('ptp_mentorship_package_purchased', $pair_id, $pair->source);
            wp_send_json_success(array(
                'status'  => 'active',
                'message' => "You're in! {$package['sessions']} sessions with {$trainer->display_name}. First session coming soon.",
                'pair_id' => $pair_id,
            ));
        } // end else (recurring)
    }

    // ================================================================
    // CANCEL (A2A policy: owe half of unused sessions)
    // ================================================================
    public static function ajax_cancel() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $pair_id = intval($_POST['pair_id'] ?? 0);
        $reason  = sanitize_textarea_field($_POST['reason'] ?? '');

        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND parent_id = %d",
            $pair_id, get_current_user_id()
        ));
        if (!$pair) wp_send_json_error('Not found');

        // Cancel Stripe subscription at period end
        $secret_key = PTP_Mentorship::get_stripe_key();
        $refund_msg = '';

        if (!empty($pair->stripe_subscription_id) && $secret_key) {
            wp_remote_post("https://api.stripe.com/v1/subscriptions/{$pair->stripe_subscription_id}", array(
                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                'body' => array('cancel_at_period_end' => 'true'),
            ));
            $refund_msg = ' Billing stops at end of current week.';
        }

        // v233 M1: Refund single-session (one-time) purchases if no sessions completed
        if (!empty($pair->stripe_payment_intent) && empty($pair->stripe_subscription_id)
            && $pair->sessions_completed == 0 && $secret_key) {
            $refund_response = wp_remote_post('https://api.stripe.com/v1/refunds', array(
                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                'body' => array('payment_intent' => $pair->stripe_payment_intent),
            ));
            $refund_data = json_decode(wp_remote_retrieve_body($refund_response), true);
            if (!empty($refund_data['id'])) {
                $refund_msg = ' A full refund has been issued.';
                ptp_log("[PTP Mentorship] Refunded PI {$pair->stripe_payment_intent} for pair #{$pair_id}");
            }
        }

        $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array(
            'status'        => 'cancelled',
            'cancelled_at'  => current_time('mysql'),
            'cancel_reason' => $reason,
        ), array('id' => $pair_id));

        do_action('ptp_mentorship_cancelled', $pair_id);
        wp_send_json_success(array(
            'message' => "Cancelled. {$pair->sessions_completed} of {$pair->sessions_total} sessions completed.{$refund_msg}",
        ));
    }

    // ================================================================
    // v233 H2: VERIFY PAYMENT (3DS return handler for one-time payments)
    // Called when parent returns from 3D Secure / Stripe redirect
    // ================================================================
    public static function ajax_verify_payment() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $pair_id = intval($_POST['pair_id'] ?? 0);
        if (!$pair_id) wp_send_json_error('Missing pair ID');

        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND parent_id = %d AND status = 'paused'",
            $pair_id, get_current_user_id()
        ));
        if (!$pair) wp_send_json_error('No pending payment found');

        $secret_key = PTP_Mentorship::get_stripe_key();
        if (!$secret_key) wp_send_json_error('Payment not configured');

        // Check PaymentIntent status (one-time payments)
        if (!empty($pair->stripe_payment_intent)) {
            $pi_response = wp_remote_get("https://api.stripe.com/v1/payment_intents/{$pair->stripe_payment_intent}", array(
                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
            ));
            $pi_data = json_decode(wp_remote_retrieve_body($pi_response), true);

            if (($pi_data['status'] ?? '') === 'succeeded') {
                $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array(
                    'status'     => 'active',
                    'started_at' => current_time('mysql'),
                ), array('id' => $pair_id));

                do_action('ptp_mentorship_package_purchased', $pair_id, $pair->source);
                wp_send_json_success(array('status' => 'active', 'message' => 'Payment confirmed! Your mentorship is now active.'));
            }
        }

        // Check subscription status (recurring payments after 3DS)
        if (!empty($pair->stripe_subscription_id)) {
            $sub_response = wp_remote_get("https://api.stripe.com/v1/subscriptions/{$pair->stripe_subscription_id}", array(
                'headers' => array('Authorization' => 'Bearer ' . $secret_key),
            ));
            $sub_data = json_decode(wp_remote_retrieve_body($sub_response), true);

            if (in_array($sub_data['status'] ?? '', array('active', 'trialing'))) {
                $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array(
                    'status'     => 'active',
                    'started_at' => current_time('mysql'),
                ), array('id' => $pair_id));

                do_action('ptp_mentorship_package_purchased', $pair_id, $pair->source);
                wp_send_json_success(array('status' => 'active', 'message' => 'Payment confirmed! Your mentorship is now active.'));
            }
        }

        wp_send_json_error('Payment is still processing. Please wait a moment and try again.');
    }

    // ================================================================
    // ADD-ON PURCHASES
    // ================================================================
    public static function ajax_purchase_addon() {
        PTP_Mentorship::verify_nonce();
        if (!is_user_logged_in()) wp_send_json_error('Login required');

        $pair_id    = intval($_POST['pair_id'] ?? 0);
        $addon_key  = sanitize_text_field($_POST['addon'] ?? '');
        $payment_method_id = sanitize_text_field($_POST['payment_method_id'] ?? '');

        if (!isset(PTP_Mentorship::ADDONS[$addon_key])) wp_send_json_error('Invalid add-on');

        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d AND parent_id = %d AND status = 'active'",
            $pair_id, get_current_user_id()
        ));
        if (!$pair) wp_send_json_error('Active mentorship not found');

        $addon      = PTP_Mentorship::ADDONS[$addon_key];
        $secret_key = PTP_Mentorship::get_stripe_key();

        $trainer = $wpdb->get_row($wpdb->prepare("SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $pair->trainer_id));
        $connect_id = $trainer->stripe_account_id ?? '';

        $pi_body = array(
            'amount' => $addon['price'], 'currency' => 'usd', 'customer' => $pair->stripe_customer_id,
            'payment_method' => $payment_method_id, 'confirm' => 'true',
            'description' => 'PTP ' . $addon['name'],
            'metadata[ptp_pair_id]' => $pair_id, 'metadata[ptp_addon]' => $addon_key,
        );
        if (!empty($connect_id)) {
            $pi_body['application_fee_amount'] = round($addon['price'] * PTP_Mentorship::get_fee());
            $pi_body['transfer_data[destination]'] = $connect_id;
        }

        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
            'headers' => array('Authorization' => 'Bearer ' . $secret_key), 'body' => $pi_body,
        ));
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (($data['status'] ?? '') !== 'succeeded') wp_send_json_error('Payment failed');

        $col = ($addon['type'] === 'video_reviews') ? 'video_reviews_remaining' : 'film_breakdowns_remaining';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_mentorship_pairs SET {$col} = {$col} + %d WHERE id = %d",
            $addon['credits'], $pair_id
        ));

        wp_send_json_success(array('message' => "{$addon['name']} purchased! Credits added."));
    }

    // ================================================================
    // STRIPE WEBHOOKS
    // ================================================================
    public static function handle_invoice_paid($event) {
        $invoice = $event['data']['object'] ?? array();
        $sub_id = $invoice['subscription'] ?? '';
        if (!$sub_id) return;

        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE stripe_subscription_id = %s", $sub_id
        ));
        if (!$pair) return;

        if ($pair->status === 'paused') {
            $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array(
                'status' => 'active', 'started_at' => current_time('mysql'),
            ), array('id' => $pair->id));
        }
    }

    public static function handle_payment_failed($event) {
        $invoice = $event['data']['object'] ?? array();
        $sub_id = $invoice['subscription'] ?? '';
        if (!$sub_id) return;

        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE stripe_subscription_id = %s", $sub_id
        ));
        if (!$pair) return;

        $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array('status' => 'paused'), array('id' => $pair->id));
        do_action('ptp_mentorship_payment_failed', $pair->id);
    }

    public static function handle_subscription_ended($event) {
        $sub = $event['data']['object'] ?? array();
        $sub_id = $sub['id'] ?? '';
        if (!$sub_id) return;

        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE stripe_subscription_id = %s", $sub_id
        ));
        if (!$pair || $pair->status === 'completed') return;

        $new_status = ($pair->sessions_completed >= $pair->sessions_total) ? 'completed' : 'cancelled';
        $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array(
            'status' => $new_status,
            $new_status === 'completed' ? 'completed_at' : 'cancelled_at' => current_time('mysql'),
        ), array('id' => $pair->id));

        if ($new_status === 'completed') {
            do_action('ptp_mentorship_package_completed', $pair->id);
        }
    }

    // ================================================================
    // CRON: Package expiring notifications
    // ================================================================
    public static function check_expiring_packages() {
        global $wpdb;
        $expiring = $wpdb->get_results(
            "SELECT p.*, t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
             WHERE p.status = 'active' AND (p.sessions_total - p.sessions_completed) <= 2
             AND p.sessions_completed > 0"
        );
        foreach ($expiring as $pair) {
            // v233 H4: Prevent daily re-notification
            $dedup_key = 'ptp_expiring_notified_' . $pair->id;
            if (get_transient($dedup_key)) continue;
            set_transient($dedup_key, 1, 7 * DAY_IN_SECONDS);
            do_action('ptp_mentorship_package_expiring', $pair);
        }
    }

    // ================================================================
    // v233 C2: CRON — Session-gap detection
    // Flags pairs where paid weeks significantly exceed completed sessions
    // ================================================================
    public static function check_session_gaps() {
        global $wpdb;

        $active_pairs = $wpdb->get_results(
            "SELECT p.*, t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
             WHERE p.status = 'active'
             AND p.stripe_subscription_id != ''
             AND p.started_at IS NOT NULL
             AND p.sessions_completed > 0"
        );

        $admin_email = get_option('admin_email');
        $secret_key  = PTP_Mentorship::get_stripe_key();

        foreach ($active_pairs as $pair) {
            $weeks_since_start = max(1, floor((time() - strtotime($pair->started_at)) / WEEK_IN_SECONDS));
            $gap = $weeks_since_start - $pair->sessions_completed;

            // Gap of 3+ weeks means parent is paying but not receiving sessions
            if ($gap < 3) continue;

            // Dedup — don't alert more than once per 7 days per pair
            $gap_key = 'ptp_session_gap_' . $pair->id;
            if (get_transient($gap_key)) continue;
            set_transient($gap_key, 1, 7 * DAY_IN_SECONDS);

            // Pause subscription to stop billing
            if (!empty($pair->stripe_subscription_id) && $secret_key) {
                wp_remote_post("https://api.stripe.com/v1/subscriptions/{$pair->stripe_subscription_id}", array(
                    'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                    'body' => array('pause_collection[behavior]' => 'void'),
                ));
            }

            // Update pair status
            $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs",
                array('status' => 'paused', 'notes' => ($pair->notes ? $pair->notes . "\n" : '') . "[Auto-paused] Session gap: {$gap} weeks paid, only {$pair->sessions_completed} sessions delivered. " . current_time('mysql')),
                array('id' => $pair->id)
            );

            // Alert admin
            wp_mail($admin_email,
                "[PTP Mentorship] Session Gap — {$pair->player_name} / {$pair->trainer_name}",
                "Mentorship pair #{$pair->id} has been AUTO-PAUSED due to session gap.\n\n" .
                "Player: {$pair->player_name}\n" .
                "Trainer: {$pair->trainer_name}\n" .
                "Weeks billed: {$weeks_since_start}\n" .
                "Sessions completed: {$pair->sessions_completed} of {$pair->sessions_total}\n" .
                "Gap: {$gap} weeks paid without sessions\n\n" .
                "Subscription billing has been paused. Please reach out to the trainer to resume.\n\n" .
                "Manage: " . admin_url('admin.php?page=ptp-mentorship')
            );

            ptp_log("[PTP Mentorship] Auto-paused pair #{$pair->id} — gap of {$gap} weeks (paid {$weeks_since_start}, completed {$pair->sessions_completed})");
        }
    }
}
