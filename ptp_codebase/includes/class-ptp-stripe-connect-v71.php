<?php
/**
 * PTP Stripe Connect Integration - v71
 * Trainer onboarding and payouts via Stripe Connect
 * 
 * @since 71.0.0
 */

defined('ABSPATH') || exit;

class PTP_Stripe_Connect_V71 {
    
    private static $instance = null;
    private $secret_key;
    private $publishable_key;
    private $client_id;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // v193: Use mode-specific keys with fallback to legacy key names
        $test_mode = get_option('ptp_stripe_test_mode', true);
        if ($test_mode) {
            $this->secret_key = get_option('ptp_stripe_test_secret', '');
            $this->publishable_key = get_option('ptp_stripe_test_publishable', '');
        } else {
            $this->secret_key = get_option('ptp_stripe_live_secret', '');
            $this->publishable_key = get_option('ptp_stripe_live_publishable', '');
        }
        // Fallback to legacy option names
        if (empty($this->secret_key)) {
            $this->secret_key = get_option('ptp_stripe_secret_key', '');
        }
        if (empty($this->publishable_key)) {
            $this->publishable_key = get_option('ptp_stripe_publishable_key', '');
        }
        $this->client_id = get_option('ptp_stripe_connect_client_id', '');
        
        // AJAX handlers
        add_action('wp_ajax_ptp_stripe_connect_start', array($this, 'ajax_start_connect'));
        add_action('wp_ajax_ptp_stripe_connect_callback', array($this, 'handle_oauth_callback'));
        add_action('wp_ajax_ptp_stripe_account_status', array($this, 'ajax_account_status'));
        add_action('wp_ajax_ptp_stripe_dashboard_link', array($this, 'ajax_dashboard_link'));
        add_action('wp_ajax_ptp_stripe_disconnect', array($this, 'ajax_disconnect'));
        add_action('wp_ajax_ptp_request_payout', array($this, 'ajax_request_payout'));
        add_action('wp_ajax_ptp_get_earnings', array($this, 'ajax_get_earnings'));
        
        // OAuth callback (non-AJAX) — kept for legacy, but modern flow uses Account Links
        add_action('init', array($this, 'check_oauth_callback'));
        
        // v193: Handle Account Links return/refresh
        add_action('init', array($this, 'check_account_link_return'));
        
        // Webhook handler
        add_action('rest_api_init', array($this, 'register_webhook'));
    }
    
    /**
     * Initialize Stripe API
     */
    private function init_stripe() {
        if (!class_exists('\Stripe\Stripe')) {
            $stripe_init = PTP_PLUGIN_DIR . 'vendor/stripe/stripe-php/init.php';
            if (file_exists($stripe_init)) {
                require_once $stripe_init;
            } else {
                ptp_log('[PTP Stripe Connect] Stripe SDK not found');
                return;
            }
        }
        \Stripe\Stripe::setApiKey($this->secret_key);
    }
    
    /**
     * Get Connect onboarding URL
     */
    public function get_connect_url($trainer_id) {
        if (empty($this->client_id)) {
            return new WP_Error('not_configured', 'Stripe Connect not configured');
        }
        
        $state = wp_create_nonce('stripe_connect_' . $trainer_id);
        set_transient('ptp_stripe_connect_' . $state, $trainer_id, 1800);
        
        $redirect_uri = home_url('/trainer-dashboard/?stripe_callback=1');
        
        $params = array(
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'scope' => 'read_write',
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'stripe_user[business_type]' => 'individual',
            'stripe_user[country]' => 'US',
            'suggested_capabilities[]' => 'transfers'
        );
        
        return 'https://connect.stripe.com/oauth/authorize?' . http_build_query($params);
    }
    
    /**
     * AJAX: Start Stripe Connect flow
     * v193: Uses modern Account Links (Express) instead of deprecated OAuth
     */
    public function ajax_start_connect() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in to continue.'));
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, stripe_account_id, user_id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found. Please complete your profile first.'));
        }
        
        $trainer_id = $trainer->id;
        
        // v193: Try modern Account Links flow first (PTP_Stripe::start_connect_onboarding)
        if (class_exists('PTP_Stripe') && method_exists('PTP_Stripe', 'start_connect_onboarding')) {
            $result = PTP_Stripe::start_connect_onboarding($trainer_id);
            
            if (!is_wp_error($result) && !empty($result['url'])) {
                ptp_log('[PTP Stripe v193] Account Link created for trainer ' . $trainer_id . ' → ' . ($result['account_id'] ?? 'unknown'));
                wp_send_json_success(array(
                    'connect_url' => $result['url'],
                    'url' => $result['url'],
                    'method' => 'account_links'
                ));
                return;
            }
            
            // Log the error but try fallback
            if (is_wp_error($result)) {
                ptp_log('[PTP Stripe v193] Account Links failed: ' . $result->get_error_message() . ' — trying fallback');
            }
        }
        
        // v193: Fallback — if trainer already has a Stripe account, create a new Account Link directly
        if (!empty($trainer->stripe_account_id)) {
            $link = $this->create_account_link_direct($trainer->stripe_account_id);
            if (!is_wp_error($link) && !empty($link['url'])) {
                wp_send_json_success(array(
                    'connect_url' => $link['url'],
                    'url' => $link['url'],
                    'method' => 'account_link_direct'
                ));
                return;
            }
        }
        
        // v193: Fallback to legacy OAuth if Account Links not available
        if (!empty($this->client_id)) {
            $url = $this->get_connect_url($trainer_id);
            if (!is_wp_error($url)) {
                wp_send_json_success(array('connect_url' => $url, 'method' => 'oauth'));
                return;
            }
        }
        
        // v193: Detailed error message for trainers
        $config = array(
            'has_secret' => !empty($this->secret_key),
            'has_publishable' => !empty($this->publishable_key),
            'ptp_stripe_exists' => class_exists('PTP_Stripe'),
            'connect_enabled' => get_option('ptp_stripe_connect_enabled', false),
        );
        ptp_log('[PTP Stripe v193] All connect methods failed for trainer ' . $trainer_id . '. Config: ' . json_encode($config));
        
        wp_send_json_error(array(
            'message' => 'Unable to start Stripe setup. This usually means Stripe Connect hasn\'t been enabled yet. Please contact PTP support and we\'ll get you set up right away.'
        ));
    }
    
    /**
     * v193: Create Account Link directly using Stripe API (bypasses PTP_Stripe class)
     */
    private function create_account_link_direct($account_id) {
        if (empty($this->secret_key)) {
            return new WP_Error('no_key', 'Stripe secret key not configured');
        }
        
        $response = wp_remote_post('https://api.stripe.com/v1/account_links', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
            ),
            'body' => array(
                'account' => $account_id,
                'refresh_url' => home_url('/trainer-onboarding/?stripe_refresh=1'),
                'return_url' => home_url('/trainer-dashboard/?tab=earnings&stripe_connected=1'),
                'type' => 'account_onboarding',
                'collect' => 'eventually_due',
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 200 || isset($body['error'])) {
            $err_msg = $body['error']['message'] ?? 'Unknown Stripe error';
            ptp_log('[PTP Stripe v193] Account Link direct error: ' . $err_msg);
            return new WP_Error('stripe_error', $err_msg);
        }
        
        return $body;
    }
    
    /**
     * v193: Handle Account Links return (stripe_refresh = trainer came back but didn't finish)
     */
    public function check_account_link_return() {
        // Handle refresh — trainer left Stripe mid-onboarding and needs a new link
        if (isset($_GET['stripe_refresh']) && $_GET['stripe_refresh'] == '1' && is_user_logged_in()) {
            global $wpdb;
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT id, stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
                get_current_user_id()
            ));
            
            if ($trainer && !empty($trainer->stripe_account_id)) {
                $link = $this->create_account_link_direct($trainer->stripe_account_id);
                if (!is_wp_error($link) && !empty($link['url'])) {
                    wp_redirect($link['url']);
                    exit;
                }
            }
            // If we can't create a new link, let them continue to the page normally
        }
        
        // v222.1: Sync Stripe status when trainer returns from onboarding
        if ((isset($_GET['stripe_connected']) || isset($_GET['connected'])) && is_user_logged_in()) {
            global $wpdb;
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT id, stripe_account_id, stripe_payouts_enabled FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
                get_current_user_id()
            ));
            if ($trainer && !empty($trainer->stripe_account_id) && !$trainer->stripe_payouts_enabled) {
                $this->sync_stripe_status($trainer->id, $trainer->stripe_account_id);
            }
        }
    }
    
    /**
     * v222.1: Sync trainer's Stripe status from live API to local DB.
     * Returns true if payouts are now enabled.
     */
    public function sync_stripe_status($trainer_id, $stripe_account_id) {
        if (empty($stripe_account_id)) return false;
        
        $this->init_stripe();
        
        try {
            $account = \Stripe\Account::retrieve($stripe_account_id);
            
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                array(
                    'stripe_charges_enabled' => $account->charges_enabled ? 1 : 0,
                    'stripe_payouts_enabled' => $account->payouts_enabled ? 1 : 0,
                ),
                array('id' => $trainer_id)
            );
            
            // v222.1: Bust trainer object cache so stale data isn't re-read
            wp_cache_delete('ptp_trainer_' . $trainer_id, 'ptp');
            
            ptp_log("[PTP Stripe v222.1] Synced Stripe status for trainer {$trainer_id}: charges=" . ($account->charges_enabled ? '1' : '0') . " payouts=" . ($account->payouts_enabled ? '1' : '0'));
            
            return $account->payouts_enabled;
        } catch (\Exception $e) {
            ptp_log("[PTP Stripe v222.1] Sync error for trainer {$trainer_id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check for OAuth callback on page load
     */
    public function check_oauth_callback() {
        if (!isset($_GET['stripe_callback']) || !isset($_GET['code'])) {
            return;
        }
        
        $code = sanitize_text_field($_GET['code']);
        $state = sanitize_text_field($_GET['state'] ?? '');
        
        // Verify state
        $trainer_id = get_transient('ptp_stripe_connect_' . $state);
        if (!$trainer_id) {
            add_action('wp_footer', function() {
                echo '<script>alert("Connection failed: Invalid state");</script>';
            });
            return;
        }
        
        delete_transient('ptp_stripe_connect_' . $state);
        
        // Exchange code for account ID
        $result = $this->exchange_oauth_code($code);
        
        if (is_wp_error($result)) {
            add_action('wp_footer', function() use ($result) {
                echo '<script>alert("Connection failed: ' . esc_js($result->get_error_message()) . '");</script>';
            });
            return;
        }
        
        // Save Connect account ID
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'stripe_account_id' => $result['stripe_user_id'],
                'stripe_connected_at' => current_time('mysql')
            ),
            array('id' => $trainer_id)
        );
        
        // v222.1: Immediately sync charges/payouts status from Stripe
        $this->sync_stripe_status($trainer_id, $result['stripe_user_id']);
        
        // v191: Fire hook so SMS/notifications can trigger
        do_action('ptp_trainer_stripe_connected', $trainer_id);
        
        // v191: Redirect back to onboarding if profile is incomplete, otherwise dashboard
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT onboarding_completed_at FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
        ));
        if (empty($trainer->onboarding_completed_at) || $trainer->onboarding_completed_at === '0000-00-00 00:00:00') {
            wp_redirect(home_url('/trainer-onboarding/?stripe_connected=1'));
        } else {
            wp_redirect(home_url('/trainer-dashboard/?tab=earnings&connected=1'));
        }
        exit;
    }
    
    /**
     * Exchange OAuth code for Stripe account
     */
    private function exchange_oauth_code($code) {
        $response = wp_remote_post('https://connect.stripe.com/oauth/token', array(
            'body' => array(
                'client_secret' => $this->secret_key,
                'code' => $code,
                'grant_type' => 'authorization_code'
            )
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('stripe_error', $body['error_description']);
        }
        
        return $body;
    }
    
    /**
     * AJAX: Get Stripe account status
     */
    public function ajax_account_status() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT stripe_account_id, stripe_connected_at 
             FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$trainer || !$trainer->stripe_account_id) {
            wp_send_json_success(array(
                'connected' => false,
                'connect_url' => null
            ));
        }
        
        // Get account details from Stripe
        $this->init_stripe();
        
        try {
            $account = \Stripe\Account::retrieve($trainer->stripe_account_id);
            
            wp_send_json_success(array(
                'connected' => true,
                'account_id' => $trainer->stripe_account_id,
                'charges_enabled' => $account->charges_enabled,
                'payouts_enabled' => $account->payouts_enabled,
                'details_submitted' => $account->details_submitted,
                'connected_at' => $trainer->stripe_connected_at
            ));
            
        } catch (Exception $e) {
            wp_send_json_success(array(
                'connected' => true,
                'error' => $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX: Get Stripe Express dashboard link
     */
    public function ajax_dashboard_link() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        $account_id = $wpdb->get_var($wpdb->prepare(
            "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$account_id) {
            wp_send_json_error(array('message' => 'Not connected'));
        }
        
        $this->init_stripe();
        
        try {
            $link = \Stripe\Account::createLoginLink($account_id);
            wp_send_json_success(array('url' => $link->url));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Disconnect Stripe account
     */
    public function ajax_disconnect() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        
        $account_id = $wpdb->get_var($wpdb->prepare(
            "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if ($account_id) {
            // Revoke access via Stripe API
            $this->init_stripe();
            try {
                \Stripe\OAuth::deauthorize(array(
                    'client_id' => $this->client_id,
                    'stripe_user_id' => $account_id
                ));
            } catch (Exception $e) {
                // Continue anyway
            }
        }
        
        // Clear from database
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('stripe_account_id' => null, 'stripe_connected_at' => null),
            array('user_id' => get_current_user_id())
        );
        
        wp_send_json_success(array('message' => 'Disconnected'));
    }
    
    /**
     * AJAX: Get trainer earnings
     */
    public function ajax_get_earnings() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        // v222.1: Read earnings from ptp_bookings (source of truth), not ptp_payouts (log table)
        // Total all-time earnings from completed sessions
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed'",
            $trainer_id
        ));
        
        // Available for payout (completed + pending payout)
        $available = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'",
            $trainer_id
        ));
        
        // Upcoming confirmed sessions (not yet payable)
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'confirmed' AND session_date >= CURDATE()",
            $trainer_id
        ));
        
        // Total already paid out
        $paid = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ptp_payouts 
             WHERE trainer_id = %d AND status = 'completed'",
            $trainer_id
        ));
        
        // This month completed
        $this_month = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed'
             AND MONTH(session_date) = MONTH(CURDATE()) AND YEAR(session_date) = YEAR(CURDATE())",
            $trainer_id
        ));
        
        // Recent transactions
        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, b.session_date, par.first_name as parent_name
             FROM {$wpdb->prefix}ptp_payouts p
             LEFT JOIN {$wpdb->prefix}ptp_bookings b ON p.booking_id = b.id
             LEFT JOIN {$wpdb->prefix}ptp_parents par ON b.parent_id = par.id
             WHERE p.trainer_id = %d
             ORDER BY p.created_at DESC LIMIT 20",
            $trainer_id
        ));
        
        wp_send_json_success(array(
            'total' => floatval($total),
            'available' => floatval($available),
            'pending' => floatval($pending),
            'paid' => floatval($paid),
            'this_month' => floatval($this_month),
            'transactions' => $transactions
        ));
    }
    
    /**
     * AJAX: Request payout
     */
    public function ajax_request_payout() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in to request a payout'));
        }
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, stripe_account_id, stripe_payouts_enabled FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer account not found'));
        }
        
        // v225: Prevent double-payout from mobile retry taps or race conditions
        $lock_key = 'ptp_payout_lock_' . $trainer->id;
        if (get_transient($lock_key)) {
            wp_send_json_error(array('message' => 'A payout is already being processed. Please wait a moment and check your earnings tab.'));
        }
        set_transient($lock_key, time(), 30); // 30-second lock
        
        if (!$trainer->stripe_account_id) {
            wp_send_json_error(array('message' => 'Please connect your Stripe account first'));
        }
        
        if (!$trainer->stripe_payouts_enabled) {
            // v222.1: DB might be stale — check Stripe live before blocking
            $synced = $this->sync_stripe_status($trainer->id, $trainer->stripe_account_id);
            if (!$synced) {
                wp_send_json_error(array('message' => 'Your Stripe account is not fully set up for payouts. Please complete your Stripe onboarding.'));
            }
            // Re-read after sync
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT id, stripe_account_id, stripe_payouts_enabled FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
                get_current_user_id()
            ));
            if (!$trainer->stripe_payouts_enabled) {
                wp_send_json_error(array('message' => 'Your Stripe account is not fully set up for payouts. Please complete your Stripe onboarding.'));
            }
        }
        
        // Get available balance from completed sessions with pending payouts
        // v222.1: CRITICAL FIX - Was reading from ptp_payouts (log table, always $0)
        // Must read from ptp_bookings which is where earnings actually accumulate
        $available = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'",
            $trainer->id
        )));
        
        $min_payout = floatval(get_option('ptp_min_payout', 25));
        if ($available < $min_payout) {
            wp_send_json_error(array('message' => 'Minimum payout is $' . number_format($min_payout, 2) . '. Your current balance is $' . number_format($available, 2)));
        }
        
        // Get the booking IDs we're paying out (for marking complete after transfer)
        $pending_bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id, trainer_payout FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'",
            $trainer->id
        ));
        
        $this->init_stripe();
        
        try {
            // Verify the connected account can receive transfers
            $account = \Stripe\Account::retrieve($trainer->stripe_account_id);
            
            if (!$account->payouts_enabled) {
                wp_send_json_error(array('message' => 'Your Stripe account cannot receive payouts yet. Please complete your account setup in Stripe.'));
            }
            
            // Create transfer
            // v228: Idempotency key prevents duplicate if trainer double-taps or network retries
            $transfer = \Stripe\Transfer::create(array(
                'amount' => intval($available * 100), // Convert to cents
                'currency' => 'usd',
                'destination' => $trainer->stripe_account_id,
                'description' => 'PTP Training Payout - ' . date('M j, Y'),
                'metadata' => array(
                    'trainer_id' => $trainer->id,
                    'payout_date' => date('Y-m-d H:i:s')
                )
            ), array(
                'idempotency_key' => 'dashboard_payout_' . $trainer->id . '_' . date('Y-m-d-H')
            ));
            
            // v222.1: Mark bookings as paid out (matches cron payout logic)
            $booking_ids = array_map(function($b) { return $b->id; }, $pending_bookings);
            if (!empty($booking_ids)) {
                $placeholders = implode(',', array_fill(0, count($booking_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ptp_bookings 
                     SET payout_status = 'completed', 
                         payout_date = NOW(),
                         stripe_transfer_id = %s
                     WHERE id IN ($placeholders)",
                    array_merge(array($transfer->id), $booking_ids)
                ));
                
                // v228: Also close any escrow records for these bookings so
                // the escrow auto-release cron doesn't transfer again
                $escrow_table = $wpdb->prefix . 'ptp_escrow';
                if ($wpdb->get_var("SHOW TABLES LIKE '{$escrow_table}'") === $escrow_table) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$escrow_table} 
                         SET status = 'released', 
                             released_at = NOW(),
                             release_method = 'dashboard_payout',
                             stripe_transfer_id = %s
                         WHERE booking_id IN ($placeholders)
                         AND status IN ('holding', 'session_complete', 'confirmed')",
                        array_merge(array($transfer->id), $booking_ids)
                    ));
                }
            }
            
            // Log payout to ptp_payouts table
            $wpdb->insert($wpdb->prefix . 'ptp_payouts', array(
                'trainer_id' => $trainer->id,
                'amount' => $available,
                'stripe_transfer_id' => $transfer->id,
                'status' => 'completed',
                'booking_count' => count($pending_bookings),
                'created_at' => current_time('mysql'),
            ));
            
            // Log successful payout
            ptp_log("PTP Stripe: Payout of \${$available} to trainer {$trainer->id} - Transfer {$transfer->id}");
            
            wp_send_json_success(array(
                'message' => 'Payout of $' . number_format($available, 2) . ' initiated! Funds typically arrive in 2-3 business days.',
                'amount' => $available
            ));
            
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            delete_transient($lock_key); // v225: Release lock so trainer can retry
            ptp_log('PTP Stripe Payout Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Unable to process payout. Please ensure your Stripe account is fully set up.'));
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            delete_transient($lock_key); // v225: Release lock
            ptp_log('PTP Stripe Connection Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Unable to connect to payment processor. Please try again.'));
        } catch (Exception $e) {
            delete_transient($lock_key); // v225: Release lock
            ptp_log('PTP Stripe Payout Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'An error occurred processing your payout. Please contact support.'));
        }
    }
    
    /**
     * Process payment and split with trainer
     */
    public function process_payment_with_split($amount, $trainer_id, $metadata = array()) {
        global $wpdb;
        
        $trainer_account = $wpdb->get_var($wpdb->prepare(
            "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer_account) {
            return new WP_Error('no_connect', 'Trainer not connected to Stripe');
        }
        
        // v194: Graduated fee - extract parent_id from metadata if available
        $parent_id = intval($metadata['parent_id'] ?? 0);
        $num_sessions = intval($metadata['num_sessions'] ?? 1);
        if (class_exists('PTP_Unified_Checkout') && ($parent_id || $trainer_id)) {
            $fee_calc = PTP_Unified_Checkout::calculate_graduated_fee($trainer_id, $parent_id, $amount, $num_sessions);
            $trainer_amount = $fee_calc['trainer_payout'];
        } else {
            $platform_fee_percent = floatval(get_option('ptp_platform_fee_percent', 25));
            $trainer_amount = $amount * (1 - ($platform_fee_percent / 100));
        }
        
        $this->init_stripe();
        
        try {
            $intent = \Stripe\PaymentIntent::create(array(
                'amount' => intval($amount * 100),
                'currency' => 'usd',
                'automatic_payment_methods' => array(
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ),
                'transfer_data' => array(
                    'destination' => $trainer_account,
                    'amount' => intval($trainer_amount * 100)
                ),
                'metadata' => $metadata
            ));
            
            return $intent;
            
        } catch (Exception $e) {
            return new WP_Error('stripe_error', $e->getMessage());
        }
    }
    
    /**
     * Create payout record
     */
    public static function create_payout_record($booking_id, $order_id) {
        global $wpdb;
        
        // Get booking details
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking) return false;
        
        // Get order total for this item
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order) return false;
        
        $session_price = floatval($booking->price ?? 75);
        // v194: Graduated fee using booking's parent-trainer pair
        $parent_id_payout = intval($booking->parent_id ?? 0);
        if (class_exists('PTP_Unified_Checkout') && $parent_id_payout) {
            $payout_fee = PTP_Unified_Checkout::calculate_graduated_fee($booking->trainer_id, $parent_id_payout, $session_price, 1);
            $trainer_amount = $payout_fee['trainer_payout'];
        } else {
            $platform_fee_percent = floatval(get_option('ptp_platform_fee_percent', 25));
            $trainer_amount = $session_price * (1 - ($platform_fee_percent / 100));
        }
        
        return $wpdb->insert(
            $wpdb->prefix . 'ptp_payouts',
            array(
                'trainer_id' => $booking->trainer_id,
                'booking_id' => $booking_id,
                'order_id' => $order_id,
                'total_amount' => $session_price,
                'platform_fee' => $session_price - $trainer_amount,
                'trainer_amount' => $trainer_amount,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            )
        );
    }
    
    /**
     * Register webhook endpoint
     */
    public function register_webhook() {
        register_rest_route('ptp/v1', '/stripe-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * Handle Stripe webhook
     */
    public function handle_webhook($request) {
        $payload = $request->get_body();
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $webhook_secret = get_option('ptp_stripe_webhook_secret', '');
        
        // SECURITY: Always require webhook signature verification
        if (empty($webhook_secret)) {
            ptp_log('PTP Stripe: Webhook secret not configured - rejecting request');
            return new WP_REST_Response(array('error' => 'Webhook not configured'), 400);
        }
        
        if (empty($sig_header)) {
            ptp_log('PTP Stripe: Missing signature header');
            return new WP_REST_Response(array('error' => 'Missing signature'), 400);
        }
        
        $this->init_stripe();
        
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            ptp_log('PTP Stripe: Invalid signature - ' . $e->getMessage());
            return new WP_REST_Response(array('error' => 'Invalid signature'), 400);
        } catch (Exception $e) {
            ptp_log('PTP Stripe: Webhook error - ' . $e->getMessage());
            return new WP_REST_Response(array('error' => 'Webhook error'), 400);
        }
        
        // Log the event for debugging
        ptp_log('PTP Stripe: Received event ' . $event->type);
        
        // Handle event types
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handle_payment_success($event->data->object);
                break;
                
            case 'payment_intent.payment_failed':
                $this->handle_payment_failed($event->data->object);
                break;
                
            case 'charge.refunded':
                $this->handle_refund($event->data->object);
                break;
                
            case 'account.updated':
                $this->handle_account_updated($event->data->object);
                break;
                
            case 'transfer.created':
                $this->handle_transfer_created($event->data->object);
                break;

            // v233: Mentorship billing — subscription lifecycle events
            case 'invoice.payment_succeeded':
                do_action('ptp_stripe_webhook_invoice.payment_succeeded', json_decode($payload, true));
                break;

            case 'invoice.payment_failed':
                do_action('ptp_stripe_webhook_invoice.payment_failed', json_decode($payload, true));
                break;

            case 'customer.subscription.deleted':
                do_action('ptp_stripe_webhook_customer.subscription.deleted', json_decode($payload, true));
                break;

            case 'customer.subscription.updated':
                do_action('ptp_stripe_webhook_customer.subscription.updated', json_decode($payload, true));
                break;
        }

        // v233: Generic event dispatch — allows any class to hook into any Stripe event
        do_action('ptp_stripe_webhook_' . $event->type, json_decode($payload, true));
        
        return new WP_REST_Response(array('received' => true), 200);
    }
    
    /**
     * Handle successful payment
     */
    private function handle_payment_success($payment_intent) {
        global $wpdb;
        
        $booking_id = $payment_intent->metadata->booking_id ?? null;
        
        if ($booking_id) {
            // Update booking status
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'status' => 'confirmed', 
                    'payment_status' => 'paid',
                    'paid_at' => current_time('mysql'),
                    'payment_intent_id' => $payment_intent->id
                ),
                array('id' => intval($booking_id)),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            ptp_log("PTP Stripe: Booking {$booking_id} marked as paid");
        } else {
            // v213: No booking_id in metadata - create booking if needed
            // Handles case where user closes browser after payment but before client AJAX fires
            $pi_id = $payment_intent->id ?? '';
            $md = $payment_intent->metadata ?? new \stdClass();
            $trainer_id_wh = intval($md->trainer_id ?? 0);
            $checkout_session_key = $md->checkout_session ?? '';
            
            if ($trainer_id_wh > 0 && !empty($pi_id)) {
                // Check if booking already exists for this PI
                $existing_booking = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s LIMIT 1",
                    $pi_id
                ));
                
                if ($existing_booking) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        array('payment_status' => 'paid'),
                        array('id' => $existing_booking)
                    );
                    ptp_log("PTP Stripe Connect: Booking {$existing_booking} already exists for PI {$pi_id}");
                } else {
                    // Try checkout session transient first
                    $created = false;
                    if (!empty($checkout_session_key)) {
                        $checkout_data = get_transient('ptp_checkout_' . $checkout_session_key);
                        if ($checkout_data && class_exists('PTP_Unified_Checkout')) {
                            $uc = new PTP_Unified_Checkout();
                            // Convert Stripe object to array for the method
                            $pi_arr = json_decode(json_encode($payment_intent), true);
                            $result = $uc->create_orders_from_session_public($checkout_data, $pi_id, $pi_arr);
                            if (!empty($result['booking_id'])) {
                                ptp_log("PTP Stripe Connect: Backup booking created: {$result['booking_id']}");
                                $created = true;
                                set_transient('ptp_processed_' . $pi_id, true, DAY_IN_SECONDS);
                            }
                        }
                    }
                    
                    // Last resort: minimal booking from PI metadata
                    if (!$created) {
                        $amount = ($payment_intent->amount ?? 0) / 100;
                        $booking_number = 'PTP-WH-' . strtoupper(substr(md5($pi_id), 0, 8));
                        
                        $wh_insert = array(
                            'booking_number' => $booking_number,
                            'trainer_id' => $trainer_id_wh,
                            'session_date' => !empty($md->session_date) ? sanitize_text_field($md->session_date) : null,
                            'start_time' => !empty($md->session_time) ? sanitize_text_field($md->session_time) : null,
                            'location' => sanitize_text_field($md->session_location ?? ''),
                            'location_notes' => sanitize_text_field($md->session_location_address ?? ''),
                            'status' => 'confirmed',
                            'payment_status' => 'paid',
                            'payment_intent_id' => $pi_id,
                            'created_at' => current_time('mysql'),
                        );
                        
                        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings");
                        if (in_array('total_amount', $columns)) $wh_insert['total_amount'] = $amount;
                        if (in_array('amount_paid', $columns)) $wh_insert['amount_paid'] = $amount;
                        if (in_array('package_type', $columns)) $wh_insert['package_type'] = sanitize_text_field($md->package ?? 'single');
                        if (in_array('group_size', $columns)) $wh_insert['group_size'] = intval($md->group_size ?? 1);
                        if (in_array('notes', $columns)) {
                            $wh_insert['notes'] = 'Created by webhook backup - client callback may have failed.';
                        }
                        
                        $insert_ok = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $wh_insert);
                        if ($insert_ok) {
                            $new_id = $wpdb->insert_id;
                            ptp_log("PTP Stripe Connect: Minimal booking created: {$new_id}");
                            
                            if (class_exists('PTP_Escrow') && $amount > 0) {
                                PTP_Escrow::create_hold($new_id, $pi_id, $amount);
                            }
                            // v136: Dispatch hook instead of direct email calls
                            do_action('ptp_training_booking_completed', $new_id);
                            
                            update_option('ptp_webhook_bookings_to_review', array_merge(
                                (array) get_option('ptp_webhook_bookings_to_review', array()),
                                array($new_id)
                            ));
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Handle failed payment
     */
    private function handle_payment_failed($payment_intent) {
        global $wpdb;
        
        $booking_id = $payment_intent->metadata->booking_id ?? null;
        
        if ($booking_id) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('payment_status' => 'failed'),
                array('id' => intval($booking_id))
            );
            
            ptp_log("PTP Stripe: Payment failed for booking {$booking_id}");
        }
    }
    
    /**
     * Handle refund
     */
    private function handle_refund($charge) {
        global $wpdb;
        
        // Find booking by payment intent
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s",
            $charge->payment_intent
        ));
        
        if ($booking) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('payment_status' => 'refunded', 'status' => 'cancelled'),
                array('id' => $booking->id)
            );
            
            ptp_log("PTP Stripe: Booking {$booking->id} refunded");
        }
    }
    
    /**
     * Handle transfer created (payout to trainer)
     */
    private function handle_transfer_created($transfer) {
        ptp_log('PTP Stripe: Transfer created - ' . $transfer->id . ' to ' . $transfer->destination);
    }
    
    /**
     * Handle account status update
     */
    private function handle_account_updated($account) {
        global $wpdb;
        
        // Update trainer's Stripe status
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'stripe_charges_enabled' => $account->charges_enabled ? 1 : 0,
                'stripe_payouts_enabled' => $account->payouts_enabled ? 1 : 0
            ),
            array('stripe_account_id' => $account->id)
        );
        
        // v222.1: Bust trainer object cache
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE stripe_account_id = %s",
            $account->id
        ));
        if ($trainer_id) {
            wp_cache_delete('ptp_trainer_' . $trainer_id, 'ptp');
        }
        
        ptp_log('PTP Stripe: Account updated - ' . $account->id . ' charges=' . ($account->charges_enabled ? '1' : '0') . ' payouts=' . ($account->payouts_enabled ? '1' : '0'));
    }
}

// Initialize
PTP_Stripe_Connect_V71::instance();
