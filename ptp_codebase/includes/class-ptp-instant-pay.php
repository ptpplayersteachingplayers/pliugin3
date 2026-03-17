<?php
/**
 * PTP Instant Pay v1.0.0
 * 
 * Enhanced trainer payment system - easy, fast, and secure
 * Mobile-first dashboard with real-time earnings tracking
 * 
 * Features:
 * - Instant payouts via Stripe Connect
 * - Real-time earnings dashboard
 * - Payout scheduling options
 * - Tax document generation
 * - Earnings analytics
 * - Multi-device support
 * 
 * @since 57.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PTP_Instant_Pay {
    
    private static $instance = null;
    
    // Payout settings
    const MIN_PAYOUT = 1;              // Minimum payout amount
    const INSTANT_PAYOUT_FEE = 1;      // $1 fee for instant payout
    const STANDARD_PAYOUT_DAYS = 2;    // Days for standard payout
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Initialize
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Shortcodes
        add_shortcode('ptp_earnings_dashboard', array($this, 'shortcode_earnings_dashboard'));
        add_shortcode('ptp_payout_settings', array($this, 'shortcode_payout_settings'));
        add_shortcode('ptp_earnings_card', array($this, 'shortcode_earnings_card'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_get_earnings_data', array($this, 'ajax_get_earnings_data'));
        add_action('wp_ajax_ptp_request_instant_payout', array($this, 'ajax_request_instant_payout'));
        add_action('wp_ajax_ptp_update_payout_schedule', array($this, 'ajax_update_payout_schedule'));
        add_action('wp_ajax_ptp_get_payout_history', array($this, 'ajax_get_payout_history'));
        add_action('wp_ajax_ptp_start_stripe_connect', array($this, 'ajax_start_stripe_connect'));
        add_action('wp_ajax_ptp_get_stripe_dashboard_link', array($this, 'ajax_get_stripe_dashboard_link'));
        add_action('wp_ajax_ptp_get_earnings_breakdown', array($this, 'ajax_get_earnings_breakdown'));
        
        // Auto-payout processing
        add_action('ptp_process_scheduled_payouts', array($this, 'process_scheduled_payouts'));
        
        // Schedule payout processing
        if (!wp_next_scheduled('ptp_process_scheduled_payouts')) {
            wp_schedule_event(time(), 'daily', 'ptp_process_scheduled_payouts');
        }
        
        // Footer - earnings widget for trainers
        add_action('wp_footer', array($this, 'render_earnings_widget'));
    }
    
    /**
     * Initialize
     */
    public function init() {
        // Handle Stripe Connect return
        if (isset($_GET['ptp_stripe_return']) && isset($_GET['trainer_id'])) {
            $this->handle_stripe_return();
        }
        
        // Handle Stripe Connect refresh
        if (isset($_GET['ptp_stripe_refresh']) && isset($_GET['trainer_id'])) {
            $this->handle_stripe_refresh();
        }
    }
    
    /**
     * Enqueue assets
     */
    public function enqueue_assets() {
        if (!$this->is_trainer_page()) {
            return;
        }
        
        wp_enqueue_style(
            'ptp-instant-pay',
            PTP_PLUGIN_URL . 'assets/css/instant-pay.css',
            array(),
            PTP_VERSION
        );
        
        wp_enqueue_script(
            'ptp-instant-pay',
            PTP_PLUGIN_URL . 'assets/js/instant-pay.js',
            array('jquery'),
            PTP_VERSION,
            true
        );
        
        wp_localize_script('ptp-instant-pay', 'ptpInstantPay', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_instant_pay'),
            'minPayout' => self::MIN_PAYOUT,
            'instantFee' => self::INSTANT_PAYOUT_FEE,
            'i18n' => array(
                'processing' => __('Processing...', 'ptp-training'),
                'success' => __('Payout initiated!', 'ptp-training'),
                'error' => __('Error processing payout', 'ptp-training'),
                'confirmInstant' => __('Request instant payout of $%s? (Fee: $%s)', 'ptp-training'),
            ),
        ));
    }
    
    /**
     * Check if on trainer-related page
     */
    private function is_trainer_page() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return (
            stripos($uri, 'trainer-dashboard') !== false ||
            stripos($uri, '/dashboard') !== false ||
            stripos($uri, 'my-earnings') !== false ||
            stripos($uri, 'payout') !== false ||
            is_page('trainer-dashboard') ||
            is_page('dashboard')
        );
    }
    
    /**
     * Get trainer from current user
     */
    private function get_current_trainer() {
        global $wpdb;
        
        $user_id = get_current_user_id();
        if (!$user_id) return null;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
    }
    
    /**
     * Get trainer earnings data
     * 
     * IMPORTANT: Trainers only get paid for COMPLETED sessions
     * - completed = session happened AND was confirmed by trainer/parent
     * - confirmed = upcoming booked session (NOT payable yet)
     * - pending = awaiting confirmation (NOT payable)
     */
    public function get_earnings_data($trainer_id, $period = 'all') {
        global $wpdb;
        
        $date_filter = '';
        switch ($period) {
            case 'today':
                $date_filter = "AND b.session_date = CURDATE()";
                break;
            case 'week':
                $date_filter = "AND b.session_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $date_filter = "AND b.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                break;
            case 'year':
                $date_filter = "AND YEAR(b.session_date) = YEAR(CURDATE())";
                break;
        }
        
        // ONLY completed sessions count toward earnings
        // completed = session happened AND was marked complete
        // payment_status = 'paid' ensures parent's payment was processed
        $completed_earnings = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as completed_sessions,
                COALESCE(SUM(b.trainer_payout), 0) as completed_amount
             FROM {$wpdb->prefix}ptp_bookings b
             WHERE b.trainer_id = %d
             AND b.status = 'completed'
             AND b.payment_status = 'paid'
             $date_filter",
            $trainer_id
        ));
        
        // Upcoming confirmed sessions (not yet payable - session hasn't happened)
        $upcoming = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as upcoming_sessions,
                COALESCE(SUM(b.trainer_payout), 0) as upcoming_amount
             FROM {$wpdb->prefix}ptp_bookings b
             WHERE b.trainer_id = %d
             AND b.status = 'confirmed'
             AND b.session_date >= CURDATE()
             $date_filter",
            $trainer_id
        ));
        
        // Get already paid out
        $paid_out = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ptp_payouts 
             WHERE trainer_id = %d AND status IN ('completed', 'pending_manual')",
            $trainer_id
        ));
        
        // v228: Safety net — also count escrow releases that may not have a ptp_payouts record
        // (fixes balance for bookings processed before the ptp_payouts insert was added)
        $escrow_table = $wpdb->prefix . 'ptp_escrow';
        $escrow_released = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$escrow_table}'") === $escrow_table) {
            $escrow_released = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(e.trainer_amount), 0) 
                 FROM {$escrow_table} e
                 WHERE e.trainer_id = %d 
                 AND e.status = 'released'
                 AND e.stripe_transfer_id IS NOT NULL
                 AND e.stripe_transfer_id != ''
                 AND NOT EXISTS (
                     SELECT 1 FROM {$wpdb->prefix}ptp_payouts p 
                     WHERE p.trainer_id = e.trainer_id 
                     AND p.payout_reference = e.stripe_transfer_id
                 )",
                $trainer_id
            ));
            if ($escrow_released > 0) {
                ptp_log("[PTP Instant Pay v228] Found \${$escrow_released} in escrow releases without payout records for trainer {$trainer_id}");
            }
        }
        
        // Available = completed sessions minus what's already been paid (from both sources)
        $total_earned = floatval($completed_earnings->completed_amount);
        $available_for_payout = max(0, $total_earned - $paid_out - $escrow_released);
        
        return array(
            'total_sessions' => intval($completed_earnings->completed_sessions),
            'total_earned' => $total_earned,
            'available_balance' => $available_for_payout, // Only from COMPLETED sessions
            'pending_balance' => floatval($upcoming->upcoming_amount), // Upcoming = not yet payable
            'upcoming_sessions' => intval($upcoming->upcoming_sessions),
            'paid_out' => $paid_out,
            'available_for_payout' => $available_for_payout,
            'can_instant_payout' => $available_for_payout >= self::MIN_PAYOUT,
        );
    }
    
    /**
     * Get earnings breakdown by time period
     * Only counts COMPLETED sessions (actual earnings)
     */
    public function get_earnings_breakdown($trainer_id, $period = 'month') {
        global $wpdb;
        
        $breakdown = array();
        
        if ($period === 'week') {
            // Daily breakdown for last 7 days
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $day_name = date('D', strtotime($date));
                
                // Only COMPLETED sessions count as earnings
                $amount = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(trainer_payout), 0) 
                     FROM {$wpdb->prefix}ptp_bookings 
                     WHERE trainer_id = %d AND session_date = %s AND status = 'completed'",
                    $trainer_id, $date
                ));
                
                $breakdown[] = array(
                    'label' => $day_name,
                    'date' => $date,
                    'amount' => $amount,
                );
            }
        } elseif ($period === 'month') {
            // Weekly breakdown for last 4 weeks
            for ($i = 3; $i >= 0; $i--) {
                $start = date('Y-m-d', strtotime("-" . ($i * 7 + 6) . " days"));
                $end = date('Y-m-d', strtotime("-" . ($i * 7) . " days"));
                
                // Only COMPLETED sessions count as earnings
                $amount = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(trainer_payout), 0) 
                     FROM {$wpdb->prefix}ptp_bookings 
                     WHERE trainer_id = %d 
                     AND session_date BETWEEN %s AND %s 
                     AND status = 'completed'",
                    $trainer_id, $start, $end
                ));
                
                $breakdown[] = array(
                    'label' => 'Week ' . (4 - $i),
                    'start' => $start,
                    'end' => $end,
                    'amount' => $amount,
                );
            }
        } elseif ($period === 'year') {
            // Monthly breakdown
            for ($i = 11; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-{$i} months"));
                $month_name = date('M', strtotime($month . '-01'));
                
                // Only COMPLETED sessions count as earnings
                $amount = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(trainer_payout), 0) 
                     FROM {$wpdb->prefix}ptp_bookings 
                     WHERE trainer_id = %d 
                     AND DATE_FORMAT(session_date, '%%Y-%%m') = %s 
                     AND status = 'completed'",
                    $trainer_id, $month
                ));
                
                $breakdown[] = array(
                    'label' => $month_name,
                    'month' => $month,
                    'amount' => $amount,
                );
            }
        }
        
        return $breakdown;
    }
    
    /**
     * Request instant payout
     */
    public function request_instant_payout($trainer_id, $amount = null) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('trainer_not_found', 'Trainer not found');
        }
        
        if (empty($trainer->stripe_account_id) || !$trainer->stripe_payouts_enabled) {
            // v222.1: Self-heal stale DB — check Stripe live
            if (!empty($trainer->stripe_account_id) && !$trainer->stripe_payouts_enabled) {
                $connect = PTP_Stripe_Connect_V71::instance();
                if (method_exists($connect, 'sync_stripe_status')) {
                    $now_enabled = $connect->sync_stripe_status($trainer_id, $trainer->stripe_account_id);
                    if (!$now_enabled) {
                        return new WP_Error('stripe_not_setup', 'Stripe Connect not configured. Please complete setup first.');
                    }
                } else {
                    return new WP_Error('stripe_not_setup', 'Stripe Connect not configured. Please complete setup first.');
                }
            } else {
                return new WP_Error('stripe_not_setup', 'Stripe Connect not configured. Please complete setup first.');
            }
        }
        
        // Get available balance
        $earnings = $this->get_earnings_data($trainer_id);
        $available = $earnings['available_for_payout'];
        
        if ($amount === null) {
            $amount = $available;
        }
        
        if ($amount < self::MIN_PAYOUT) {
            return new WP_Error('below_minimum', sprintf('Minimum payout is $%s', self::MIN_PAYOUT));
        }
        
        if ($amount > $available) {
            return new WP_Error('insufficient_balance', 'Insufficient available balance');
        }
        
        // Apply instant payout fee
        $net_amount = $amount - self::INSTANT_PAYOUT_FEE;
        
        // Create payout record
        $wpdb->insert(
            $wpdb->prefix . 'ptp_payouts',
            array(
                'trainer_id' => $trainer_id,
                'amount' => $net_amount,
                'fee' => self::INSTANT_PAYOUT_FEE,
                'gross_amount' => $amount,
                'status' => 'processing',
                'payout_method' => 'stripe_instant',
                'created_at' => current_time('mysql'),
            )
        );
        
        $payout_id = $wpdb->insert_id;
        
        // Process via Stripe
        if (class_exists('PTP_Stripe')) {
            $amount_cents = round($net_amount * 100);
            
            // v228: Idempotency key tied to payout record prevents duplicate transfers
            $transfer = PTP_Stripe::create_transfer(
                $amount_cents,
                $trainer->stripe_account_id,
                'PTP Instant Payout #' . $payout_id,
                'instant_payout_' . $payout_id
            );
            
            if (is_wp_error($transfer)) {
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
            
            // Update payout record
            $wpdb->update(
                $wpdb->prefix . 'ptp_payouts',
                array(
                    'status' => 'completed',
                    'payout_reference' => $transfer->id ?? ($transfer['id'] ?? ''),
                    'processed_at' => current_time('mysql'),
                ),
                array('id' => $payout_id)
            );
            
            // v222.1: Mark bookings as paid out so they don't get double-paid
            $transfer_id = $transfer->id ?? ($transfer['id'] ?? 'instant-' . $payout_id);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_bookings 
                 SET payout_status = 'completed', 
                     payout_date = NOW(),
                     stripe_transfer_id = %s
                 WHERE trainer_id = %d 
                 AND status = 'completed' 
                 AND payout_status = 'pending'",
                $transfer_id,
                $trainer_id
            ));
            
            // v228: Close any escrow records for this trainer's paid bookings
            $escrow_table = $wpdb->prefix . 'ptp_escrow';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$escrow_table}'") === $escrow_table) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$escrow_table} 
                     SET status = 'released', 
                         released_at = NOW(),
                         release_method = 'instant_payout',
                         stripe_transfer_id = %s
                     WHERE trainer_id = %d 
                     AND status IN ('holding', 'session_complete', 'confirmed')",
                    $transfer_id,
                    $trainer_id
                ));
            }
            
            // Send notification
            $this->notify_payout_success($trainer, $net_amount, 'instant');
            
            return array(
                'success' => true,
                'payout_id' => $payout_id,
                'amount' => $net_amount,
                'fee' => self::INSTANT_PAYOUT_FEE,
                'message' => sprintf(__('$%s sent to your bank account!', 'ptp-training'), number_format($net_amount, 2)),
            );
        }
        
        return new WP_Error('stripe_error', 'Payment processor not available');
    }
    
    /**
     * Notify trainer of successful payout
     */
    private function notify_payout_success($trainer, $amount, $type = 'instant') {
        $user = get_user_by('ID', $trainer->user_id);
        if (!$user) return;
        
        $type_label = $type === 'instant' ? 'Instant' : 'Scheduled';
        
        $subject = sprintf(__('💰 %s Payout Complete - $%s', 'ptp-training'), $type_label, number_format($amount, 2));
        
        $message = sprintf(
            __("Great news, %s!\n\nYour %s payout of $%s has been processed and is on its way to your bank account.\n\nPayout Details:\n- Amount: $%s\n- Type: %s Payout\n- Status: Completed\n\nFunds typically arrive within 1-2 business days for instant payouts, or 2-3 days for standard payouts.\n\nView your earnings dashboard: %s\n\nKeep up the great work!\n\n- The PTP Team", 'ptp-training'),
            $trainer->display_name,
            strtolower($type_label),
            number_format($amount, 2),
            number_format($amount, 2),
            $type_label,
            home_url('/trainer-dashboard/?tab=earnings')
        );
        
        wp_mail($user->user_email, $subject, $message);
    }
    
    /**
     * Process scheduled payouts (daily cron)
     */
    public function process_scheduled_payouts() {
        global $wpdb;
        
        // Get trainers with auto-payout enabled and balance >= threshold
        $trainers = $wpdb->get_results(
            "SELECT t.*, 
                    (SELECT COALESCE(SUM(b.trainer_payout), 0) 
                     FROM {$wpdb->prefix}ptp_bookings b 
                     WHERE b.trainer_id = t.id 
                     AND b.status = 'completed' 
                     AND b.payment_status = 'paid') as earned,
                    (SELECT COALESCE(SUM(p.amount), 0) 
                     FROM {$wpdb->prefix}ptp_payouts p 
                     WHERE p.trainer_id = t.id 
                     AND p.status = 'completed') as paid
             FROM {$wpdb->prefix}ptp_trainers t
             WHERE t.status = 'active'
             AND t.stripe_payouts_enabled = 1
             AND t.stripe_account_id IS NOT NULL
             AND t.auto_payout_enabled = 1
             HAVING (earned - paid) >= t.auto_payout_threshold"
        );
        
        foreach ($trainers as $trainer) {
            $available = floatval($trainer->earned) - floatval($trainer->paid);
            
            if ($available >= floatval($trainer->auto_payout_threshold ?: 50)) {
                $this->request_instant_payout($trainer->id, $available);
            }
        }
    }
    
    /**
     * Get payout history
     */
    public function get_payout_history($trainer_id, $limit = 20) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_payouts 
             WHERE trainer_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d",
            $trainer_id, $limit
        ));
    }
    
    /**
     * Handle Stripe Connect return
     */
    private function handle_stripe_return() {
        $trainer_id = absint($_GET['trainer_id']);
        
        if (!class_exists('PTP_Stripe')) {
            wp_redirect(home_url('/trainer-dashboard/?stripe_error=1'));
            exit;
        }
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer || !$trainer->stripe_account_id) {
            wp_redirect(home_url('/trainer-dashboard/?stripe_error=1'));
            exit;
        }
        
        // Check account status
        $account_status = PTP_Stripe::get_connect_account_status($trainer->stripe_account_id);
        
        if (!is_wp_error($account_status)) {
            // Check if this is first time connecting (wasn't enabled before)
            $was_enabled = $trainer->stripe_payouts_enabled;
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                array(
                    'stripe_charges_enabled' => $account_status['charges_enabled'] ? 1 : 0,
                    'stripe_payouts_enabled' => $account_status['payouts_enabled'] ? 1 : 0,
                    'stripe_connected_at' => $account_status['payouts_enabled'] && !$was_enabled ? current_time('mysql') : $trainer->stripe_connected_at,
                ),
                array('id' => $trainer_id)
            );
            
            // v222.1: Bust trainer object cache
            wp_cache_delete('ptp_trainer_' . $trainer_id, 'ptp');
            
            // Fire hook if newly connected with payouts enabled
            if ($account_status['payouts_enabled'] && !$was_enabled) {
                do_action('ptp_trainer_stripe_connected', $trainer_id);
                ptp_log("[PTP] Trainer #{$trainer_id} Stripe connected - hook fired");
            }
        }
        
        wp_redirect(home_url('/trainer-dashboard/?stripe_connected=1&tab=earnings'));
        exit;
    }
    
    /**
     * Handle Stripe Connect refresh
     */
    private function handle_stripe_refresh() {
        $trainer_id = absint($_GET['trainer_id']);
        
        if (!class_exists('PTP_Stripe')) {
            wp_redirect(home_url('/trainer-dashboard/?stripe_error=1'));
            exit;
        }
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer || !$trainer->stripe_account_id) {
            wp_redirect(home_url('/trainer-dashboard/?stripe_error=1'));
            exit;
        }
        
        // Create new onboarding link
        $return_url = add_query_arg(array('ptp_stripe_return' => 1, 'trainer_id' => $trainer_id), home_url());
        $refresh_url = add_query_arg(array('ptp_stripe_refresh' => 1, 'trainer_id' => $trainer_id), home_url());
        
        $link = PTP_Stripe::create_connect_account_link($trainer->stripe_account_id, $return_url, $refresh_url);
        
        if (is_wp_error($link)) {
            wp_redirect(home_url('/trainer-dashboard/?stripe_error=1'));
            exit;
        }
        
        wp_redirect($link);
        exit;
    }
    
    /**
     * AJAX: Get earnings data
     */
    public function ajax_get_earnings_data() {
        check_ajax_referer('ptp_instant_pay', 'nonce');
        
        $trainer = $this->get_current_trainer();
        if (!$trainer) {
            wp_send_json_error('Not a trainer');
        }
        
        $period = sanitize_text_field($_POST['period'] ?? 'all');
        $earnings = $this->get_earnings_data($trainer->id, $period);
        $breakdown = $this->get_earnings_breakdown($trainer->id, $period === 'all' ? 'month' : $period);
        
        wp_send_json_success(array(
            'earnings' => $earnings,
            'breakdown' => $breakdown,
            'stripe_connected' => !empty($trainer->stripe_account_id) && $trainer->stripe_payouts_enabled,
        ));
    }
    
    /**
     * AJAX: Request instant payout
     */
    public function ajax_request_instant_payout() {
        // Accept either nonce for compatibility
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_instant_pay') && 
            !wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_nonce')) {
            wp_send_json_error('Invalid security token');
        }
        
        $trainer = $this->get_current_trainer();
        if (!$trainer) {
            wp_send_json_error('Not a trainer');
        }
        
        $amount = floatval($_POST['amount'] ?? 0);
        
        $result = $this->request_instant_payout($trainer->id, $amount > 0 ? $amount : null);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Update payout schedule
     */
    public function ajax_update_payout_schedule() {
        check_ajax_referer('ptp_instant_pay', 'nonce');
        
        $trainer = $this->get_current_trainer();
        if (!$trainer) {
            wp_send_json_error('Not a trainer');
        }
        
        global $wpdb;
        
        $auto_enabled = isset($_POST['auto_enabled']) ? 1 : 0;
        $threshold = max(10, floatval($_POST['threshold'] ?? 50));
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'auto_payout_enabled' => $auto_enabled,
                'auto_payout_threshold' => $threshold,
            ),
            array('id' => $trainer->id)
        );
        
        wp_send_json_success(array(
            'message' => __('Payout settings updated!', 'ptp-training'),
        ));
    }
    
    /**
     * AJAX: Get payout history
     */
    public function ajax_get_payout_history() {
        check_ajax_referer('ptp_instant_pay', 'nonce');
        
        $trainer = $this->get_current_trainer();
        if (!$trainer) {
            wp_send_json_error('Not a trainer');
        }
        
        $history = $this->get_payout_history($trainer->id);
        
        wp_send_json_success(array('history' => $history));
    }
    
    /**
     * AJAX: Start Stripe Connect
     */
    public function ajax_start_stripe_connect() {
        check_ajax_referer('ptp_instant_pay', 'nonce');
        
        $trainer = $this->get_current_trainer();
        if (!$trainer) {
            wp_send_json_error('Not a trainer');
        }
        
        if (!class_exists('PTP_Stripe')) {
            wp_send_json_error('Stripe not configured');
        }
        
        global $wpdb;
        
        // Create or get Stripe account
        if (empty($trainer->stripe_account_id)) {
            $user = get_user_by('ID', $trainer->user_id);
            
            $account = PTP_Stripe::create_connect_account(
                $user->user_email,
                $trainer->display_name
            );
            
            if (is_wp_error($account)) {
                wp_send_json_error($account->get_error_message());
            }
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                array('stripe_account_id' => $account->id),
                array('id' => $trainer->id)
            );
            
            $account_id = $account->id;
        } else {
            $account_id = $trainer->stripe_account_id;
        }
        
        // Create onboarding link
        $return_url = add_query_arg(array('ptp_stripe_return' => 1, 'trainer_id' => $trainer->id), home_url());
        $refresh_url = add_query_arg(array('ptp_stripe_refresh' => 1, 'trainer_id' => $trainer->id), home_url());
        
        $link = PTP_Stripe::create_connect_account_link($account_id, $return_url, $refresh_url);
        
        if (is_wp_error($link)) {
            wp_send_json_error($link->get_error_message());
        }
        
        wp_send_json_success(array('url' => $link));
    }
    
    /**
     * AJAX: Get Stripe dashboard link
     */
    public function ajax_get_stripe_dashboard_link() {
        check_ajax_referer('ptp_instant_pay', 'nonce');
        
        $trainer = $this->get_current_trainer();
        if (!$trainer || empty($trainer->stripe_account_id)) {
            wp_send_json_error('Stripe not connected');
        }
        
        if (!class_exists('PTP_Stripe')) {
            wp_send_json_error('Stripe not configured');
        }
        
        $link = PTP_Stripe::create_login_link($trainer->stripe_account_id);
        
        if (is_wp_error($link)) {
            wp_send_json_error($link->get_error_message());
        }
        
        wp_send_json_success(array('url' => $link));
    }
    
    /**
     * AJAX: Get earnings breakdown
     */
    public function ajax_get_earnings_breakdown() {
        check_ajax_referer('ptp_instant_pay', 'nonce');
        
        $trainer = $this->get_current_trainer();
        if (!$trainer) {
            wp_send_json_error('Not a trainer');
        }
        
        $period = sanitize_text_field($_POST['period'] ?? 'month');
        $breakdown = $this->get_earnings_breakdown($trainer->id, $period);
        
        wp_send_json_success(array('breakdown' => $breakdown));
    }
    
    /**
     * Shortcode: Full earnings dashboard
     */
    public function shortcode_earnings_dashboard($atts) {
        $trainer = $this->get_current_trainer();
        
        if (!$trainer) {
            return '<div class="ptp-notice">You must be a trainer to view this page.</div>';
        }
        
        $earnings = $this->get_earnings_data($trainer->id);
        $breakdown = $this->get_earnings_breakdown($trainer->id, 'month');
        $history = $this->get_payout_history($trainer->id, 5);
        $stripe_connected = !empty($trainer->stripe_account_id) && $trainer->stripe_payouts_enabled;
        
        ob_start();
        ?>
        <div class="ptp-earnings-dashboard" style="font-family:'Inter',-apple-system,sans-serif;">
            
            <!-- Header Stats -->
            <div class="ptp-earnings-header" style="background:linear-gradient(135deg,#0A0A0A 0%,#1a1a1a 100%);border-radius:16px;padding:24px;color:#fff;margin-bottom:24px;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
                    <div>
                        <h2 style="font-family:'Oswald',sans-serif;font-size:28px;margin:0;text-transform:uppercase;">
                            💰 Your Earnings
                        </h2>
                        <p style="color:#9CA3AF;margin:4px 0 0;font-size:14px;">
                            Real-time earnings & payouts
                        </p>
                    </div>
                    <?php if ($stripe_connected && $earnings['can_instant_payout']): ?>
                    <button onclick="ptpInstantPay.requestPayout(<?php echo $earnings['available_for_payout']; ?>)"
                            class="ptp-instant-payout-btn"
                            style="background:#10B981;color:#fff;border:none;padding:14px 28px;border-radius:10px;
                                   font-weight:700;font-size:16px;cursor:pointer;display:flex;align-items:center;gap:8px;
                                   transition:all 0.2s;">
                        <span>⚡</span>
                        <span>Instant Payout - $<?php echo number_format($earnings['available_for_payout'], 2); ?></span>
                    </button>
                    <?php endif; ?>
                </div>
                
                <!-- Stats Grid -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin-top:24px;">
                    <div style="background:rgba(16,185,129,0.2);border:1px solid rgba(16,185,129,0.3);border-radius:12px;padding:16px;">
                        <div style="font-size:28px;font-weight:700;color:#10B981;">
                            $<?php echo number_format($earnings['available_for_payout'], 0); ?>
                        </div>
                        <div style="font-size:13px;color:#9CA3AF;">Available to Cash Out</div>
                        <div style="font-size:11px;color:#6B7280;margin-top:2px;">From completed sessions</div>
                    </div>
                    <div style="background:rgba(252,185,0,0.15);border:1px solid rgba(252,185,0,0.3);border-radius:12px;padding:16px;">
                        <div style="font-size:28px;font-weight:700;color:#FCB900;">
                            $<?php echo number_format($earnings['pending_balance'], 0); ?>
                        </div>
                        <div style="font-size:13px;color:#9CA3AF;">Upcoming Sessions</div>
                        <div style="font-size:11px;color:#6B7280;margin-top:2px;"><?php echo $earnings['upcoming_sessions'] ?? 0; ?> sessions booked</div>
                    </div>
                    <div style="background:rgba(255,255,255,0.1);border-radius:12px;padding:16px;">
                        <div style="font-size:28px;font-weight:700;color:#fff;">
                            $<?php echo number_format($earnings['total_earned'], 0); ?>
                        </div>
                        <div style="font-size:13px;color:#9CA3AF;">Total Earned</div>
                        <div style="font-size:11px;color:#6B7280;margin-top:2px;">All-time completed</div>
                    </div>
                    <div style="background:rgba(255,255,255,0.1);border-radius:12px;padding:16px;">
                        <div style="font-size:28px;font-weight:700;color:#fff;">
                            <?php echo $earnings['total_sessions']; ?>
                        </div>
                        <div style="font-size:13px;color:#9CA3AF;">Sessions Completed</div>
                        <div style="font-size:11px;color:#6B7280;margin-top:2px;">Confirmed & paid</div>
                    </div>
                </div>
            </div>
            
            <?php if (!$stripe_connected): ?>
            <!-- Stripe Setup Banner -->
            <div style="background:#FEF3C7;border:2px solid #FCB900;border-radius:12px;padding:20px;margin-bottom:24px;text-align:center;">
                <h3 style="font-family:'Oswald',sans-serif;margin:0 0 8px;">🏦 Set Up Instant Payouts</h3>
                <p style="color:#666;margin:0 0 16px;">Connect your bank account to receive instant payouts</p>
                <button onclick="ptpInstantPay.startStripeConnect()"
                        style="background:#0A0A0A;color:#fff;border:none;padding:14px 32px;border-radius:8px;
                               font-weight:700;cursor:pointer;font-size:16px;">
                    Connect Bank Account
                </button>
            </div>
            <?php endif; ?>
            
            <!-- How Payouts Work -->
            <div style="background:#F0F9FF;border:1px solid #0EA5E9;border-radius:12px;padding:16px 20px;margin-bottom:24px;">
                <div style="display:flex;align-items:flex-start;gap:12px;">
                    <span style="font-size:24px;">ℹ️</span>
                    <div>
                        <strong style="font-size:14px;color:#0369A1;">How Payouts Work</strong>
                        <p style="margin:6px 0 0;font-size:13px;color:#0C4A6E;line-height:1.5;">
                            You get paid once a session is <strong>completed and confirmed</strong>. After your training session, 
                            mark it complete in your dashboard. Once confirmed, earnings move to "Available to Cash Out" and you can 
                            instantly transfer to your bank. Upcoming booked sessions show as "Upcoming" but aren't payable until completed.
                        </p>
                    </div>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                
                <!-- Earnings Chart -->
                <div style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                        <h3 style="font-family:'Oswald',sans-serif;font-size:16px;margin:0;text-transform:uppercase;">
                            📊 Earnings Trend
                        </h3>
                        <select id="earnings-period" onchange="ptpInstantPay.updateChart(this.value)"
                                style="padding:6px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
                            <option value="week">This Week</option>
                            <option value="month" selected>This Month</option>
                            <option value="year">This Year</option>
                        </select>
                    </div>
                    <div id="earnings-chart" style="height:200px;display:flex;align-items:flex-end;gap:8px;">
                        <?php foreach ($breakdown as $item): ?>
                        <?php $max = max(array_column($breakdown, 'amount')) ?: 1; ?>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;">
                            <div style="background:#FCB900;border-radius:4px 4px 0 0;width:100%;
                                        height:<?php echo max(4, ($item['amount'] / $max) * 160); ?>px;
                                        transition:height 0.3s;"></div>
                            <span style="font-size:11px;color:#666;margin-top:4px;"><?php echo $item['label']; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Recent Payouts -->
                <div style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                    <h3 style="font-family:'Oswald',sans-serif;font-size:16px;margin:0 0 16px;text-transform:uppercase;">
                        📜 Recent Payouts
                    </h3>
                    <?php if (empty($history)): ?>
                    <p style="color:#666;text-align:center;padding:40px 0;">No payouts yet</p>
                    <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <?php foreach ($history as $payout): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;
                                    background:#f9f9f9;border-radius:8px;">
                            <div>
                                <strong style="font-size:14px;">$<?php echo number_format($payout->amount, 2); ?></strong>
                                <span style="font-size:12px;color:#666;margin-left:8px;">
                                    <?php echo date('M j, Y', strtotime($payout->created_at)); ?>
                                </span>
                            </div>
                            <span style="padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;
                                         background:<?php echo $payout->status === 'completed' ? '#D1FAE5' : ($payout->status === 'failed' ? '#FEE2E2' : '#FEF3C7'); ?>;
                                         color:<?php echo $payout->status === 'completed' ? '#059669' : ($payout->status === 'failed' ? '#DC2626' : '#D97706'); ?>;">
                                <?php echo ucfirst($payout->status); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Payout Settings -->
            <div style="background:#fff;border-radius:12px;padding:20px;margin-top:24px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                <h3 style="font-family:'Oswald',sans-serif;font-size:16px;margin:0 0 16px;text-transform:uppercase;">
                    ⚙️ Payout Settings
                </h3>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                    <div>
                        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;">
                            <input type="checkbox" id="auto-payout" <?php echo $trainer->auto_payout_enabled ? 'checked' : ''; ?>
                                   onchange="ptpInstantPay.updateSettings()"
                                   style="width:20px;height:20px;">
                            <span>
                                <strong>Auto-Payout</strong><br>
                                <small style="color:#666;">Automatically request payout when balance reaches threshold</small>
                            </span>
                        </label>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:8px;">
                            <strong>Payout Threshold</strong>
                        </label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span>$</span>
                            <input type="number" id="payout-threshold" 
                                   value="<?php echo $trainer->auto_payout_threshold ?: 50; ?>"
                                   min="10" step="10"
                                   onchange="ptpInstantPay.updateSettings()"
                                   style="width:100px;padding:8px;border:1px solid #ddd;border-radius:6px;">
                        </div>
                    </div>
                </div>
                
                <?php if ($stripe_connected): ?>
                <div style="margin-top:20px;padding-top:20px;border-top:1px solid #eee;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <strong>Stripe Connected</strong>
                            <span style="display:inline-block;width:8px;height:8px;background:#10B981;border-radius:50%;margin-left:8px;"></span>
                            <br>
                            <small style="color:#666;">Account ID: <?php echo substr($trainer->stripe_account_id, 0, 12); ?>...</small>
                        </div>
                        <button onclick="ptpInstantPay.openStripeDashboard()"
                                style="background:#635BFF;color:#fff;border:none;padding:10px 20px;border-radius:6px;
                                       cursor:pointer;font-weight:600;font-size:14px;">
                            Open Stripe Dashboard
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
        </div>
        
        <style>
        @media (max-width: 768px) {
            .ptp-earnings-dashboard > div:nth-child(3) {
                grid-template-columns: 1fr !important;
            }
            .ptp-earnings-header > div:last-child {
                grid-template-columns: repeat(2, 1fr) !important;
            }
            .ptp-instant-payout-btn {
                width: 100%;
                justify-content: center;
            }
        }
        .ptp-instant-payout-btn:hover {
            background: #059669 !important;
            transform: translateY(-2px);
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Compact earnings card
     */
    public function shortcode_earnings_card($atts) {
        $trainer = $this->get_current_trainer();
        if (!$trainer) return '';
        
        $earnings = $this->get_earnings_data($trainer->id);
        
        ob_start();
        ?>
        <div class="ptp-earnings-card" style="background:#0A0A0A;border-radius:12px;padding:20px;color:#fff;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-size:13px;color:#9CA3AF;margin-bottom:4px;">Available Balance</div>
                    <div style="font-size:28px;font-weight:700;color:#10B981;">
                        $<?php echo number_format($earnings['available_for_payout'], 2); ?>
                    </div>
                </div>
                <?php if ($earnings['can_instant_payout']): ?>
                <button onclick="ptpInstantPay.requestPayout()"
                        style="background:#10B981;color:#fff;border:none;padding:12px 20px;border-radius:8px;
                               font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;">
                    <span>⚡</span> Cash Out
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Payout settings
     */
    public function shortcode_payout_settings($atts) {
        $trainer = $this->get_current_trainer();
        if (!$trainer) return '';
        
        ob_start();
        $template = PTP_PLUGIN_DIR . 'templates/trainer-payout-settings.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="ptp-payout-settings" style="padding: 40px; text-align: center;">
                <h2>Payout Settings</h2>
                <p>Configure your payout preferences in your dashboard.</p>
                <p><a href="' . home_url('/trainer-dashboard/') . '" class="ptp-btn">Go to Dashboard →</a></p>
            </div>';
        }
        return ob_get_clean();
    }
    
    /**
     * Render floating earnings widget for trainers
     */
    public function render_earnings_widget() {
        // v226: Removed — trainer dashboard has its own built-in payout UI
        return;
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Instant_Pay::instance();
}, 15);
