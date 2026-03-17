<?php
/**
 * PTP Admin Payments v3 - Clean Redesign
 * Modern, minimal dashboard for payment management
 * 
 * v130.3: Complete redesign with clean UI
 */

defined('ABSPATH') || exit;

class PTP_Admin_Payouts_V3 {
    
    /**
     * v135: Ensure escrow_log table exists
     */
    private static function ensure_log_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_escrow_log';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE $table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                escrow_id bigint(20) unsigned NOT NULL,
                event_type varchar(50) NOT NULL,
                message text,
                user_id bigint(20) unsigned DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY escrow_id (escrow_id),
                KEY event_type (event_type)
            ) $charset;");
        }
    }
    
    public static function init() {
        add_action('wp_ajax_ptp_admin_release_escrow', array(__CLASS__, 'ajax_release_escrow'));
        add_action('wp_ajax_ptp_admin_refund_escrow', array(__CLASS__, 'ajax_refund_escrow'));
        add_action('wp_ajax_ptp_admin_resolve_dispute', array(__CLASS__, 'ajax_resolve_dispute'));
        add_action('wp_ajax_ptp_admin_manual_payout', array(__CLASS__, 'ajax_manual_payout'));
        
        // v135: Ensure escrow_log table exists
        self::ensure_log_table();
    }
    
    /**
     * Get dashboard stats
     */
    public static function get_stats() {
        global $wpdb;
        
        $stats = new stdClass();
        
        // Escrow
        $stats->escrow_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'holding'") ?: 0;
        $stats->escrow_amount = $wpdb->get_var("SELECT COALESCE(SUM(total_amount), 0) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'holding'") ?: 0;
        
        // Pending confirmation
        $stats->pending_confirm = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'session_complete'") ?: 0;
        
        // Disputes
        $stats->disputes = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'disputed'") ?: 0;
        
        // Released this week
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $stats->released_week = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_amount), 0) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'released' AND released_at >= %s",
            $week_start
        )) ?: 0;
        
        // Released this month
        $month_start = date('Y-m-01');
        $stats->released_month = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_amount), 0) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'released' AND released_at >= %s",
            $month_start
        )) ?: 0;
        
        // Trainers without Stripe
        $stats->no_stripe = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active' AND (stripe_account_id IS NULL OR stripe_account_id = '')") ?: 0;
        
        // Platform fee percentage
        $stats->platform_fee = function_exists('ptp_get_platform_fee_percent') ? ptp_get_platform_fee_percent() : 20;
        
        return $stats;
    }
    
    /**
     * Get escrow records
     */
    public static function get_escrow($status = null, $limit = 20) {
        global $wpdb;
        
        $where = $status ? $wpdb->prepare("WHERE e.status = %s", $status) : "";
        
        return $wpdb->get_results("
            SELECT e.*, 
                   t.display_name as trainer_name, t.photo_url as trainer_photo, t.stripe_account_id,
                   p.display_name as parent_name,
                   b.session_date, b.start_time
            FROM {$wpdb->prefix}ptp_escrow e
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON e.parent_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_bookings b ON e.booking_id = b.id
            {$where}
            ORDER BY e.created_at DESC
            LIMIT {$limit}
        ");
    }
    
    /**
     * Get trainer balances
     */
    public static function get_trainer_balances() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT 
                t.id, t.display_name, t.photo_url, t.email, t.stripe_account_id,
                COALESCE(SUM(CASE WHEN e.status = 'released' THEN e.trainer_amount ELSE 0 END), 0) as total_earned,
                COALESCE(SUM(CASE WHEN e.status IN ('holding', 'session_complete') THEN e.trainer_amount ELSE 0 END), 0) as pending_amount,
                COUNT(CASE WHEN e.status IN ('holding', 'session_complete') THEN 1 END) as pending_sessions
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->prefix}ptp_escrow e ON t.id = e.trainer_id
            WHERE t.status = 'active'
            GROUP BY t.id
            ORDER BY pending_amount DESC, total_earned DESC
        ");
    }
    
    /**
     * Render the page
     */
    public static function render_page() {
        $stats = self::get_stats();
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'dashboard';
        ?>
        <div class="ptp-pay-wrap">
            <?php self::render_styles(); ?>
            
            <header class="ptp-pay-header">
                <div>
                    <h1>Payments</h1>
                    <p class="ptp-pay-subtitle">Manage escrow, payouts, and disputes</p>
                </div>
                <div class="ptp-pay-actions">
                    <span class="ptp-pay-fee" title="Graduated: 50%/50%/25%/25%/15% per new parent">Commission: 50% → 25% → 15%</span>
                </div>
            </header>
            
            <?php if ($stats->no_stripe > 0): ?>
            <div class="ptp-pay-alert">
                <strong><?php echo $stats->no_stripe; ?> trainer<?php echo $stats->no_stripe > 1 ? 's' : ''; ?></strong> haven't connected Stripe yet
            </div>
            <?php endif; ?>
            
            <!-- Stats Cards -->
            <div class="ptp-pay-stats">
                <div class="ptp-pay-stat">
                    <div class="ptp-pay-stat-icon gold">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <div class="ptp-pay-stat-info">
                        <span class="ptp-pay-stat-value">$<?php echo number_format($stats->escrow_amount, 0); ?></span>
                        <span class="ptp-pay-stat-label">In Escrow</span>
                    </div>
                    <span class="ptp-pay-stat-badge"><?php echo $stats->escrow_count; ?></span>
                </div>
                
                <div class="ptp-pay-stat">
                    <div class="ptp-pay-stat-icon blue">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    </div>
                    <div class="ptp-pay-stat-info">
                        <span class="ptp-pay-stat-value"><?php echo $stats->pending_confirm; ?></span>
                        <span class="ptp-pay-stat-label">Awaiting Confirm</span>
                    </div>
                </div>
                
                <div class="ptp-pay-stat <?php echo $stats->disputes > 0 ? 'has-alert' : ''; ?>">
                    <div class="ptp-pay-stat-icon <?php echo $stats->disputes > 0 ? 'red' : 'gray'; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div class="ptp-pay-stat-info">
                        <span class="ptp-pay-stat-value"><?php echo $stats->disputes; ?></span>
                        <span class="ptp-pay-stat-label">Disputes</span>
                    </div>
                </div>
                
                <div class="ptp-pay-stat">
                    <div class="ptp-pay-stat-icon green">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <div class="ptp-pay-stat-info">
                        <span class="ptp-pay-stat-value">$<?php echo number_format($stats->released_week, 0); ?></span>
                        <span class="ptp-pay-stat-label">Released This Week</span>
                    </div>
                </div>
            </div>
            
            <!-- Navigation -->
            <nav class="ptp-pay-nav">
                <a href="?page=ptp-payments&view=dashboard" class="<?php echo $view === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
                <a href="?page=ptp-payments&view=escrow" class="<?php echo $view === 'escrow' ? 'active' : ''; ?>">Escrow</a>
                <a href="?page=ptp-payments&view=disputes" class="<?php echo $view === 'disputes' ? 'active' : ''; ?>">
                    Disputes
                    <?php if ($stats->disputes > 0): ?><span class="ptp-pay-count"><?php echo $stats->disputes; ?></span><?php endif; ?>
                </a>
                <a href="?page=ptp-payments&view=trainers" class="<?php echo $view === 'trainers' ? 'active' : ''; ?>">Trainers</a>
                <a href="?page=ptp-payments&view=history" class="<?php echo $view === 'history' ? 'active' : ''; ?>">History</a>
            </nav>
            
            <!-- Content -->
            <div class="ptp-pay-content">
                <?php
                switch ($view) {
                    case 'escrow':
                        self::render_escrow_view();
                        break;
                    case 'disputes':
                        self::render_disputes_view();
                        break;
                    case 'trainers':
                        self::render_trainers_view();
                        break;
                    case 'history':
                        self::render_history_view();
                        break;
                    default:
                        self::render_dashboard_view();
                }
                ?>
            </div>
            
            <?php self::render_scripts(); ?>
        </div>
        <?php
    }
    
    /**
     * Dashboard View
     */
    public static function render_dashboard_view() {
        $recent = self::get_escrow(null, 5);
        $disputes = self::get_escrow('disputed', 3);
        ?>
        <div class="ptp-pay-grid">
            <!-- How It Works -->
            <div class="ptp-pay-card">
                <h3>Payment Flow</h3>
                <div class="ptp-pay-flow">
                    <div class="ptp-pay-flow-step">
                        <span class="ptp-pay-flow-num">1</span>
                        <strong>Parent Pays</strong>
                        <small>Funds held in escrow</small>
                    </div>
                    <div class="ptp-pay-flow-arrow">→</div>
                    <div class="ptp-pay-flow-step">
                        <span class="ptp-pay-flow-num">2</span>
                        <strong>Session Complete</strong>
                        <small>Trainer confirms</small>
                    </div>
                    <div class="ptp-pay-flow-arrow">→</div>
                    <div class="ptp-pay-flow-step">
                        <span class="ptp-pay-flow-num">3</span>
                        <strong>24hr Window</strong>
                        <small>Parent can dispute</small>
                    </div>
                    <div class="ptp-pay-flow-arrow">→</div>
                    <div class="ptp-pay-flow-step done">
                        <span class="ptp-pay-flow-num">✓</span>
                        <strong>Auto Release</strong>
                        <small>Trainer gets paid</small>
                    </div>
                </div>
                <div class="ptp-pay-commission-info" style="margin-top:16px;padding:12px;background:#f9f9f9;border-radius:8px;font-size:13px;">
                    <strong>Tiered Commission:</strong><br>
                    • <span style="color:#D97706;">First Session:</span> PTP keeps 50%, Trainer gets 50%<br>
                    • <span style="color:#059669;">Sessions 1-2:</span> PTP keeps 50%, Trainer gets 50%<br>
                    • <span style="color:#059669;">Sessions 3-4:</span> PTP keeps 25%, Trainer gets 75%<br>
                    • <span style="color:#059669;">Session 5+:</span> PTP keeps 15%, Trainer gets 85%
                </div>
            </div>
            
            <?php if (!empty($disputes)): ?>
            <!-- Disputes -->
            <div class="ptp-pay-card urgent">
                <h3>⚠️ Disputes Need Attention</h3>
                <div class="ptp-pay-list">
                    <?php foreach ($disputes as $d): ?>
                    <div class="ptp-pay-item">
                        <div class="ptp-pay-item-info">
                            <strong><?php echo esc_html($d->trainer_name); ?> vs <?php echo esc_html($d->parent_name); ?></strong>
                            <span>$<?php echo number_format($d->total_amount, 2); ?> • <?php echo date('M j', strtotime($d->session_date)); ?></span>
                        </div>
                        <a href="?page=ptp-payments&view=disputes" class="ptp-pay-btn sm">Review</a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Recent Activity -->
            <div class="ptp-pay-card">
                <h3>Recent Transactions</h3>
                <?php if (empty($recent)): ?>
                <div class="ptp-pay-empty">
                    <span>💰</span>
                    <p>No transactions yet</p>
                </div>
                <?php else: ?>
                <div class="ptp-pay-list">
                    <?php foreach ($recent as $r): ?>
                    <div class="ptp-pay-item">
                        <div class="ptp-pay-item-status <?php echo esc_attr($r->status); ?>"></div>
                        <div class="ptp-pay-item-info">
                            <strong><?php echo esc_html($r->trainer_name ?: 'Trainer'); ?></strong>
                            <span><?php echo ucfirst(str_replace('_', ' ', $r->status)); ?> • <?php echo date('M j', strtotime($r->created_at)); ?></span>
                        </div>
                        <div class="ptp-pay-item-amount">$<?php echo number_format($r->total_amount, 2); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Escrow View
     */
    public static function render_escrow_view() {
        $holding = self::get_escrow('holding', 50);
        $pending = self::get_escrow('session_complete', 50);
        ?>
        <div class="ptp-pay-sections">
            <!-- Holding in Escrow -->
            <div class="ptp-pay-section">
                <h3>Holding in Escrow <span class="ptp-pay-count"><?php echo count($holding); ?></span></h3>
                <?php if (empty($holding)): ?>
                <div class="ptp-pay-empty success">
                    <span>✅</span>
                    <p>No funds in escrow</p>
                </div>
                <?php else: ?>
                <table class="ptp-pay-table">
                    <thead>
                        <tr>
                            <th>Trainer</th>
                            <th>Parent</th>
                            <th>Session</th>
                            <th>Amount</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($holding as $e): ?>
                        <tr>
                            <td><strong><?php echo esc_html($e->trainer_name); ?></strong></td>
                            <td><?php echo esc_html($e->parent_name); ?></td>
                            <td><?php echo $e->session_date ? date('M j, Y', strtotime($e->session_date)) : '-'; ?></td>
                            <td><strong>$<?php echo number_format($e->total_amount, 2); ?></strong></td>
                            <td>
                                <button class="ptp-pay-btn sm green" onclick="PTPPay.release(<?php echo $e->id; ?>)">Release</button>
                                <button class="ptp-pay-btn sm outline" onclick="PTPPay.refund(<?php echo $e->id; ?>)">Refund</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            
            <!-- Awaiting Confirmation -->
            <div class="ptp-pay-section">
                <h3>Awaiting Parent Confirmation <span class="ptp-pay-count"><?php echo count($pending); ?></span></h3>
                <?php if (empty($pending)): ?>
                <div class="ptp-pay-empty">
                    <span>⏳</span>
                    <p>No sessions awaiting confirmation</p>
                </div>
                <?php else: ?>
                <table class="ptp-pay-table">
                    <thead>
                        <tr>
                            <th>Trainer</th>
                            <th>Parent</th>
                            <th>Session</th>
                            <th>Amount</th>
                            <th>Auto-Release</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $e): 
                            $auto_release = strtotime($e->session_date . ' +24 hours');
                            $hours_left = max(0, round(($auto_release - time()) / 3600));
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($e->trainer_name); ?></strong></td>
                            <td><?php echo esc_html($e->parent_name); ?></td>
                            <td><?php echo date('M j, Y', strtotime($e->session_date)); ?></td>
                            <td><strong>$<?php echo number_format($e->total_amount, 2); ?></strong></td>
                            <td>
                                <?php if ($hours_left > 0): ?>
                                <span class="ptp-pay-countdown"><?php echo $hours_left; ?>h left</span>
                                <?php else: ?>
                                <span class="ptp-pay-ready">Ready</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Disputes View
     */
    public static function render_disputes_view() {
        $disputes = self::get_escrow('disputed', 50);
        ?>
        <?php if (empty($disputes)): ?>
        <div class="ptp-pay-empty success large">
            <span>🎉</span>
            <h3>No Active Disputes</h3>
            <p>All payments are running smoothly</p>
        </div>
        <?php else: ?>
        <div class="ptp-pay-disputes">
            <?php foreach ($disputes as $d): ?>
            <div class="ptp-pay-dispute">
                <div class="ptp-pay-dispute-header">
                    <div>
                        <h4>Dispute #<?php echo $d->id; ?></h4>
                        <span class="ptp-pay-dispute-date"><?php echo date('M j, Y', strtotime($d->created_at)); ?></span>
                    </div>
                    <div class="ptp-pay-dispute-amount">
                        <strong>$<?php echo number_format($d->total_amount, 2); ?></strong>
                    </div>
                </div>
                
                <div class="ptp-pay-dispute-parties">
                    <div class="ptp-pay-dispute-party">
                        <label>Trainer</label>
                        <strong><?php echo esc_html($d->trainer_name); ?></strong>
                        <small>Gets $<?php echo number_format($d->trainer_amount, 2); ?></small>
                    </div>
                    <span class="ptp-pay-dispute-vs">vs</span>
                    <div class="ptp-pay-dispute-party">
                        <label>Parent</label>
                        <strong><?php echo esc_html($d->parent_name); ?></strong>
                        <small>Paid $<?php echo number_format($d->total_amount, 2); ?></small>
                    </div>
                </div>
                
                <?php if (!empty($d->dispute_reason)): ?>
                <div class="ptp-pay-dispute-reason">
                    <label>Reason</label>
                    <p><?php echo esc_html($d->dispute_reason); ?></p>
                </div>
                <?php endif; ?>
                
                <div class="ptp-pay-dispute-actions">
                    <button class="ptp-pay-btn green" onclick="PTPPay.resolve(<?php echo $d->id; ?>, 'trainer')">
                        Pay Trainer
                    </button>
                    <button class="ptp-pay-btn" onclick="PTPPay.resolve(<?php echo $d->id; ?>, 'parent')">
                        Refund Parent
                    </button>
                    <button class="ptp-pay-btn outline" onclick="PTPPay.resolve(<?php echo $d->id; ?>, 'split')">
                        Split 50/50
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Trainers View
     */
    public static function render_trainers_view() {
        $trainers = self::get_trainer_balances();
        ?>
        <table class="ptp-pay-table">
            <thead>
                <tr>
                    <th>Trainer</th>
                    <th>Stripe</th>
                    <th>Pending</th>
                    <th>Total Earned</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trainers as $t): ?>
                <tr>
                    <td>
                        <div class="ptp-pay-trainer">
                            <?php if ($t->photo_url): ?>
                            <img src="<?php echo esc_url($t->photo_url); ?>" alt="">
                            <?php else: ?>
                            <div class="ptp-pay-trainer-avatar"><?php echo strtoupper(substr($t->display_name, 0, 1)); ?></div>
                            <?php endif; ?>
                            <div>
                                <strong><?php echo esc_html($t->display_name); ?></strong>
                                <small><?php echo esc_html($t->email); ?></small>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($t->stripe_account_id): ?>
                        <span class="ptp-pay-badge green">Connected</span>
                        <?php else: ?>
                        <span class="ptp-pay-badge red">Not Connected</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($t->pending_amount > 0): ?>
                        <strong>$<?php echo number_format($t->pending_amount, 2); ?></strong>
                        <small><?php echo $t->pending_sessions; ?> session<?php echo $t->pending_sessions != 1 ? 's' : ''; ?></small>
                        <?php else: ?>
                        <span class="ptp-pay-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>$<?php echo number_format($t->total_earned, 2); ?></td>
                    <td>
                        <?php if ($t->stripe_account_id && $t->pending_amount > 0): ?>
                        <button class="ptp-pay-btn sm" onclick="PTPPay.payoutTrainer(<?php echo $t->id; ?>)">Payout Now</button>
                        <?php elseif (!$t->stripe_account_id): ?>
                        <span class="ptp-pay-muted">Needs Stripe</span>
                        <?php else: ?>
                        <span class="ptp-pay-muted">No pending</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * History View
     */
    public static function render_history_view() {
        global $wpdb;
        
        $history = $wpdb->get_results("
            SELECT e.*, 
                   t.display_name as trainer_name,
                   p.display_name as parent_name
            FROM {$wpdb->prefix}ptp_escrow e
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON e.parent_id = p.id
            WHERE e.status IN ('released', 'refunded')
            ORDER BY e.released_at DESC, e.created_at DESC
            LIMIT 100
        ");
        ?>
        <?php if (empty($history)): ?>
        <div class="ptp-pay-empty">
            <span>📋</span>
            <p>No payment history yet</p>
        </div>
        <?php else: ?>
        <table class="ptp-pay-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Trainer</th>
                    <th>Parent</th>
                    <th>Amount</th>
                    <th>Trainer Got</th>
                    <th>Status</th>
                    <th>Stripe</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?php echo date('M j, Y', strtotime($h->released_at ?: $h->created_at)); ?></td>
                    <td><?php echo esc_html($h->trainer_name); ?></td>
                    <td><?php echo esc_html($h->parent_name); ?></td>
                    <td>$<?php echo number_format($h->total_amount, 2); ?></td>
                    <td><strong>$<?php echo number_format($h->trainer_amount, 2); ?></strong></td>
                    <td>
                        <span class="ptp-pay-badge <?php echo $h->status === 'released' ? 'green' : 'gray'; ?>">
                            <?php echo ucfirst($h->status); ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($h->stripe_transfer_id)): ?>
                        <span class="ptp-pay-badge green" title="<?php echo esc_attr($h->stripe_transfer_id); ?>">✓ Sent</span>
                        <?php elseif ($h->status === 'released'): ?>
                        <span class="ptp-pay-badge orange" title="No Stripe transfer - manual processing needed">Manual</span>
                        <?php elseif ($h->status === 'refunded'): ?>
                        <span class="ptp-pay-badge gray">Refund</span>
                        <?php else: ?>
                        <span class="ptp-pay-badge gray">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Render Styles
     */
    public static function render_styles() {
        ?>
        <style>
        .ptp-pay-wrap {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        /* Header */
        .ptp-pay-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .ptp-pay-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
            color: #0A0A0A;
        }
        .ptp-pay-subtitle {
            color: #666;
            margin: 4px 0 0;
        }
        .ptp-pay-fee {
            background: #f5f5f5;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            color: #666;
        }
        
        /* Alert */
        .ptp-pay-alert {
            background: #FFF3CD;
            border-left: 4px solid #FCB900;
            padding: 12px 16px;
            margin-bottom: 24px;
            border-radius: 0 8px 8px 0;
            font-size: 14px;
        }
        
        /* Stats */
        .ptp-pay-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .ptp-pay-stat {
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
        }
        .ptp-pay-stat.has-alert {
            border-color: #EF4444;
            background: #FEF2F2;
        }
        .ptp-pay-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .ptp-pay-stat-icon svg {
            width: 24px;
            height: 24px;
        }
        .ptp-pay-stat-icon.gold { background: #FEF3C7; color: #D97706; }
        .ptp-pay-stat-icon.blue { background: #DBEAFE; color: #2563EB; }
        .ptp-pay-stat-icon.green { background: #D1FAE5; color: #059669; }
        .ptp-pay-stat-icon.red { background: #FEE2E2; color: #DC2626; }
        .ptp-pay-stat-icon.gray { background: #F3F4F6; color: #6B7280; }
        .ptp-pay-stat-info {
            flex: 1;
        }
        .ptp-pay-stat-value {
            display: block;
            font-size: 24px;
            font-weight: 700;
            color: #0A0A0A;
            line-height: 1.2;
        }
        .ptp-pay-stat-label {
            display: block;
            font-size: 13px;
            color: #666;
        }
        .ptp-pay-stat-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: #E5E5E5;
            color: #666;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
        }
        
        /* Navigation */
        .ptp-pay-nav {
            display: flex;
            gap: 4px;
            border-bottom: 1px solid #e5e5e5;
            margin-bottom: 24px;
        }
        .ptp-pay-nav a {
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 500;
            color: #666;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ptp-pay-nav a:hover {
            color: #0A0A0A;
        }
        .ptp-pay-nav a.active {
            color: #0A0A0A;
            border-bottom-color: #FCB900;
        }
        .ptp-pay-count {
            background: #EF4444;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 10px;
        }
        
        /* Content Grid */
        .ptp-pay-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 24px;
        }
        
        /* Cards */
        .ptp-pay-card {
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 12px;
            padding: 24px;
        }
        .ptp-pay-card.urgent {
            border-color: #EF4444;
            background: #FEF2F2;
        }
        .ptp-pay-card h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 16px;
            color: #0A0A0A;
        }
        
        /* Flow Diagram */
        .ptp-pay-flow {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }
        .ptp-pay-flow-step {
            flex: 1;
            min-width: 100px;
            text-align: center;
            padding: 16px 8px;
            background: #f9f9f9;
            border-radius: 8px;
            border: 1px solid #e5e5e5;
        }
        .ptp-pay-flow-step.done {
            background: #D1FAE5;
            border-color: #059669;
        }
        .ptp-pay-flow-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            background: #FCB900;
            color: #0A0A0A;
            border-radius: 50%;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .ptp-pay-flow-step.done .ptp-pay-flow-num {
            background: #059669;
            color: #fff;
        }
        .ptp-pay-flow-step strong {
            display: block;
            font-size: 13px;
            margin-bottom: 2px;
        }
        .ptp-pay-flow-step small {
            font-size: 11px;
            color: #666;
        }
        .ptp-pay-flow-arrow {
            color: #ccc;
            font-size: 18px;
        }
        
        /* List */
        .ptp-pay-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .ptp-pay-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f9f9f9;
            border-radius: 8px;
        }
        .ptp-pay-item-status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .ptp-pay-item-status.holding { background: #FCB900; }
        .ptp-pay-item-status.session_complete { background: #3B82F6; }
        .ptp-pay-item-status.released { background: #10B981; }
        .ptp-pay-item-status.disputed { background: #EF4444; }
        .ptp-pay-item-status.refunded { background: #6B7280; }
        .ptp-pay-item-info {
            flex: 1;
        }
        .ptp-pay-item-info strong {
            display: block;
            font-size: 14px;
        }
        .ptp-pay-item-info span {
            font-size: 12px;
            color: #666;
        }
        .ptp-pay-item-amount {
            font-weight: 600;
            font-size: 14px;
        }
        
        /* Empty State */
        .ptp-pay-empty {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        .ptp-pay-empty.large {
            padding: 80px 20px;
        }
        .ptp-pay-empty.success {
            background: #D1FAE5;
            border-radius: 12px;
        }
        .ptp-pay-empty span {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
        }
        .ptp-pay-empty h3 {
            margin: 0 0 8px;
            color: #0A0A0A;
        }
        .ptp-pay-empty p {
            margin: 0;
        }
        
        /* Table */
        .ptp-pay-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e5e5e5;
        }
        .ptp-pay-table th {
            background: #f9f9f9;
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: #666;
            border-bottom: 1px solid #e5e5e5;
        }
        .ptp-pay-table td {
            padding: 16px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        .ptp-pay-table tr:last-child td {
            border-bottom: none;
        }
        .ptp-pay-table small {
            display: block;
            font-size: 12px;
            color: #666;
        }
        
        /* Sections */
        .ptp-pay-sections {
            display: flex;
            flex-direction: column;
            gap: 32px;
        }
        .ptp-pay-section h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .ptp-pay-section .ptp-pay-count {
            background: #E5E5E5;
            color: #666;
        }
        
        /* Buttons */
        .ptp-pay-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            background: #0A0A0A;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .ptp-pay-btn:hover {
            background: #333;
        }
        .ptp-pay-btn.sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        .ptp-pay-btn.green {
            background: #059669;
        }
        .ptp-pay-btn.green:hover {
            background: #047857;
        }
        .ptp-pay-btn.outline {
            background: transparent;
            color: #666;
            border: 1px solid #e5e5e5;
        }
        .ptp-pay-btn.outline:hover {
            border-color: #ccc;
            color: #0A0A0A;
        }
        
        /* Badge */
        .ptp-pay-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .ptp-pay-badge.green {
            background: #D1FAE5;
            color: #059669;
        }
        .ptp-pay-badge.red {
            background: #FEE2E2;
            color: #DC2626;
        }
        .ptp-pay-badge.gray {
            background: #F3F4F6;
            color: #6B7280;
        }
        .ptp-pay-badge.orange {
            background: #FEF3C7;
            color: #D97706;
        }
        
        /* Trainer */
        .ptp-pay-trainer {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .ptp-pay-trainer img,
        .ptp-pay-trainer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .ptp-pay-trainer-avatar {
            background: #FCB900;
            color: #0A0A0A;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
        }
        .ptp-pay-trainer small {
            display: block;
            color: #666;
            font-size: 12px;
        }
        
        /* Misc */
        .ptp-pay-muted {
            color: #999;
            font-size: 13px;
        }
        .ptp-pay-countdown {
            color: #D97706;
            font-weight: 600;
            font-size: 13px;
        }
        .ptp-pay-ready {
            color: #059669;
            font-weight: 600;
            font-size: 13px;
        }
        
        /* Disputes */
        .ptp-pay-disputes {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .ptp-pay-dispute {
            background: #fff;
            border: 2px solid #FCA5A5;
            border-radius: 16px;
            padding: 24px;
        }
        .ptp-pay-dispute-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        .ptp-pay-dispute-header h4 {
            margin: 0;
            font-size: 18px;
        }
        .ptp-pay-dispute-date {
            color: #666;
            font-size: 13px;
        }
        .ptp-pay-dispute-amount strong {
            font-size: 24px;
            color: #DC2626;
        }
        .ptp-pay-dispute-parties {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }
        .ptp-pay-dispute-party {
            flex: 1;
            background: #f9f9f9;
            padding: 16px;
            border-radius: 12px;
        }
        .ptp-pay-dispute-party label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            color: #999;
            margin-bottom: 4px;
        }
        .ptp-pay-dispute-party strong {
            display: block;
            font-size: 16px;
        }
        .ptp-pay-dispute-party small {
            color: #666;
            font-size: 12px;
        }
        .ptp-pay-dispute-vs {
            font-weight: 700;
            color: #ccc;
        }
        .ptp-pay-dispute-reason {
            background: #FEF3C7;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .ptp-pay-dispute-reason label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            color: #92400E;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .ptp-pay-dispute-reason p {
            margin: 0;
            color: #78350F;
        }
        .ptp-pay-dispute-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .ptp-pay-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .ptp-pay-stats {
                grid-template-columns: 1fr 1fr;
            }
            .ptp-pay-nav {
                overflow-x: auto;
            }
            .ptp-pay-flow {
                flex-direction: column;
            }
            .ptp-pay-flow-arrow {
                transform: rotate(90deg);
            }
            .ptp-pay-dispute-parties {
                flex-direction: column;
            }
            .ptp-pay-table {
                display: block;
                overflow-x: auto;
            }
        }
        </style>
        <?php
    }
    
    /**
     * Render Scripts
     */
    public static function render_scripts() {
        ?>
        <script>
        var PTPPay = {
            nonce: '<?php echo wp_create_nonce('ptp_admin_payments'); ?>',
            loading: false,
            
            release: function(id) {
                if (this.loading) return;
                if (!confirm('Release funds to trainer?')) return;
                this.loading = true;
                var btn = event.target;
                btn.textContent = 'Releasing...';
                btn.disabled = true;
                
                this.ajax('ptp_admin_release_escrow', {escrow_id: id}, function(response) {
                    if (response.data && response.data.stripe_success) {
                        // Stripe transfer worked
                        console.log('Stripe transfer ID:', response.data.stripe_transfer_id);
                    } else if (response.data && !response.data.stripe_success) {
                        // Released but no Stripe transfer
                        alert('Escrow released but Stripe transfer was not processed. Check logs for details.');
                    }
                    location.reload();
                }, function() {
                    btn.textContent = 'Release';
                    btn.disabled = false;
                    PTPPay.loading = false;
                });
            },
            
            refund: function(id) {
                if (this.loading) return;
                if (!confirm('Refund to parent? This cannot be undone.')) return;
                this.loading = true;
                var btn = event.target;
                btn.textContent = 'Refunding...';
                btn.disabled = true;
                
                this.ajax('ptp_admin_refund_escrow', {escrow_id: id}, function() {
                    location.reload();
                }, function() {
                    btn.textContent = 'Refund';
                    btn.disabled = false;
                    PTPPay.loading = false;
                });
            },
            
            resolve: function(id, resolution) {
                if (this.loading) return;
                var msg = {
                    'trainer': 'Pay full amount to trainer?',
                    'parent': 'Refund full amount to parent?',
                    'split': 'Split 50/50 between trainer and parent?'
                };
                if (!confirm(msg[resolution])) return;
                this.loading = true;
                var btn = event.target;
                var originalText = btn.textContent;
                btn.textContent = 'Processing...';
                btn.disabled = true;
                
                this.ajax('ptp_admin_resolve_dispute', {escrow_id: id, resolution: resolution}, function() {
                    location.reload();
                }, function() {
                    btn.textContent = originalText;
                    btn.disabled = false;
                    PTPPay.loading = false;
                });
            },
            
            payoutTrainer: function(id) {
                if (this.loading) return;
                if (!confirm('Process manual payout for this trainer?')) return;
                this.loading = true;
                var btn = event.target;
                btn.textContent = 'Processing...';
                btn.disabled = true;
                
                this.ajax('ptp_admin_manual_payout', {trainer_id: id}, function(response) {
                    if (response.data && response.data.message) {
                        alert(response.data.message);
                    }
                    location.reload();
                }, function() {
                    btn.textContent = 'Payout Now';
                    btn.disabled = false;
                    PTPPay.loading = false;
                });
            },
            
            ajax: function(action, data, success, error) {
                data.action = action;
                data.nonce = this.nonce;
                
                jQuery.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        success(response);
                    } else {
                        alert('Error: ' + (response.data || 'Unknown error occurred'));
                        if (error) error();
                    }
                }).fail(function(xhr, status, err) {
                    console.error('AJAX Error:', status, err);
                    alert('Network error: ' + err + '. Please try again.');
                    if (error) error();
                });
            }
        };
        </script>
        <?php
    }
    
    /**
     * AJAX Handlers
     */
    public static function ajax_release_escrow() {
        check_ajax_referer('ptp_admin_payments', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        
        global $wpdb;
        $id = intval($_POST['escrow_id']);
        
        $escrow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_escrow WHERE id = %d", $id));
        if (!$escrow) wp_send_json_error('Escrow not found');
        
        if ($escrow->status === 'released') {
            wp_send_json_error('Already released');
        }
        
        // Update status
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => 'released', 
                'released_at' => current_time('mysql'),
                'release_method' => 'admin_manual',
                'release_notes' => 'Released by admin user ID ' . get_current_user_id()
            ),
            array('id' => $id)
        );
        
        // Log the event
        $wpdb->insert(
            $wpdb->prefix . 'ptp_escrow_log',
            array(
                'escrow_id' => $id,
                'event_type' => 'released',
                'message' => sprintf('Released $%s to trainer by admin', number_format($escrow->trainer_amount, 2)),
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql')
            )
        );
        
        // Update booking status
        if ($escrow->booking_id) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('payout_status' => 'completed'),
                array('id' => $escrow->booking_id)
            );
        }
        
        // Try Stripe transfer
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $escrow->trainer_id
        ));
        
        $stripe_result = null;
        $stripe_transfer_id = '';
        
        if ($trainer && !empty($trainer->stripe_account_id) && class_exists('PTP_Stripe')) {
            if (method_exists('PTP_Stripe', 'create_transfer')) {
                $stripe_result = PTP_Stripe::create_transfer(
                    $escrow->trainer_amount * 100, // Convert to cents
                    $trainer->stripe_account_id,
                    'PTP Escrow #' . $id . ' - Trainer payout'
                );
                
                if (is_wp_error($stripe_result)) {
                    // Log error but don't fail - admin can process manually
                    ptp_log('[PTP Payout] Stripe transfer failed for escrow #' . $id . ': ' . $stripe_result->get_error_message());
                    
                    // Update escrow with error note
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_escrow',
                        array('release_notes' => 'Released by admin. Stripe transfer FAILED: ' . $stripe_result->get_error_message()),
                        array('id' => $id)
                    );
                    
                    // Log the failure
                    $wpdb->insert(
                        $wpdb->prefix . 'ptp_escrow_log',
                        array(
                            'escrow_id' => $id,
                            'event_type' => 'stripe_transfer_failed',
                            'message' => 'Stripe error: ' . $stripe_result->get_error_message(),
                            'user_id' => get_current_user_id(),
                            'created_at' => current_time('mysql')
                        )
                    );
                } elseif (isset($stripe_result['id'])) {
                    // Success! Save transfer ID
                    $stripe_transfer_id = $stripe_result['id'];
                    
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_escrow',
                        array(
                            'stripe_transfer_id' => $stripe_transfer_id,
                            'release_notes' => 'Released by admin. Stripe transfer: ' . $stripe_transfer_id
                        ),
                        array('id' => $id)
                    );
                    
                    // Log success
                    $wpdb->insert(
                        $wpdb->prefix . 'ptp_escrow_log',
                        array(
                            'escrow_id' => $id,
                            'event_type' => 'stripe_transfer_success',
                            'message' => sprintf('Stripe transfer %s completed. Amount: $%s to %s', 
                                $stripe_transfer_id,
                                number_format($escrow->trainer_amount, 2),
                                $trainer->stripe_account_id
                            ),
                            'user_id' => get_current_user_id(),
                            'created_at' => current_time('mysql')
                        )
                    );
                    
                    ptp_log('[PTP Payout] Stripe transfer SUCCESS for escrow #' . $id . ': ' . $stripe_transfer_id);
                }
            }
        } else {
            // No Stripe account - note this
            $reason = !$trainer ? 'Trainer not found' : 
                      (empty($trainer->stripe_account_id) ? 'Trainer has no Stripe account' : 'PTP_Stripe class not found');
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_escrow',
                array('release_notes' => 'Released by admin. No Stripe transfer: ' . $reason),
                array('id' => $id)
            );
        }
        
        wp_send_json_success(array(
            'escrow_id' => $id,
            'stripe_transfer_id' => $stripe_transfer_id,
            'stripe_success' => !empty($stripe_transfer_id)
        ));
    }
    
    public static function ajax_refund_escrow() {
        check_ajax_referer('ptp_admin_payments', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        
        global $wpdb;
        $id = intval($_POST['escrow_id']);
        
        $escrow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_escrow WHERE id = %d", $id));
        if (!$escrow) wp_send_json_error('Escrow not found');
        
        if ($escrow->status === 'refunded') {
            wp_send_json_error('Already refunded');
        }
        
        // Try Stripe refund first
        $stripe_refund_success = false;
        if (!empty($escrow->payment_intent_id) && class_exists('PTP_Stripe')) {
            try {
                if (method_exists('PTP_Stripe', 'create_refund')) {
                    PTP_Stripe::create_refund($escrow->payment_intent_id, $escrow->total_amount);
                    $stripe_refund_success = true;
                }
            } catch (Exception $e) {
                ptp_log('[PTP Refund] Stripe refund failed for escrow #' . $id . ': ' . $e->getMessage());
            }
        }
        
        // Update status
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => 'refunded', 
                'refunded_at' => current_time('mysql'),
                'refund_amount' => $escrow->total_amount,
                'release_notes' => 'Refunded by admin user ID ' . get_current_user_id() . ($stripe_refund_success ? ' (Stripe refund processed)' : ' (Manual refund - Stripe not processed)')
            ),
            array('id' => $id)
        );
        
        // Log the event
        $wpdb->insert(
            $wpdb->prefix . 'ptp_escrow_log',
            array(
                'escrow_id' => $id,
                'event_type' => 'refunded',
                'message' => sprintf('Refunded $%s to parent by admin%s', 
                    number_format($escrow->total_amount, 2),
                    $stripe_refund_success ? ' (Stripe processed)' : ''
                ),
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql')
            )
        );
        
        // Update booking status
        if ($escrow->booking_id) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('status' => 'cancelled', 'payment_status' => 'refunded'),
                array('id' => $escrow->booking_id)
            );
        }
        
        wp_send_json_success(array(
            'stripe_processed' => $stripe_refund_success
        ));
    }
    
    public static function ajax_resolve_dispute() {
        check_ajax_referer('ptp_admin_payments', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        
        global $wpdb;
        $id = intval($_POST['escrow_id']);
        $resolution = sanitize_text_field($_POST['resolution']);
        
        $escrow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_escrow WHERE id = %d", $id));
        if (!$escrow) wp_send_json_error('Escrow not found');
        
        $status = $resolution === 'parent' ? 'refunded' : 'released';
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => $status, 
                'released_at' => current_time('mysql'), 
                'dispute_resolution' => $resolution,
                'dispute_resolved_at' => current_time('mysql'),
                'dispute_resolved_by' => get_current_user_id()
            ),
            array('id' => $id)
        );
        
        // Log the resolution
        $resolution_labels = array(
            'trainer' => 'Paid to trainer',
            'parent' => 'Refunded to parent',
            'split' => 'Split 50/50'
        );
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_escrow_log',
            array(
                'escrow_id' => $id,
                'event_type' => 'dispute_resolved',
                'message' => sprintf('Dispute resolved: %s ($%s) by admin', 
                    $resolution_labels[$resolution] ?? $resolution,
                    number_format($escrow->total_amount, 2)
                ),
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql')
            )
        );
        
        // If split, handle partial refund/payout
        if ($resolution === 'split') {
            $refund_amount = round($escrow->total_amount * 0.5, 2);
            $platform_fee = round($escrow->platform_fee * 0.5, 2);
            $trainer_gets = round($escrow->trainer_amount * 0.5, 2);
            
            // Refund 50% to parent
            if (!empty($escrow->payment_intent_id) && class_exists('PTP_Stripe')) {
                $refund = PTP_Stripe::create_refund($escrow->payment_intent_id, round($refund_amount * 100));
                if (is_wp_error($refund)) {
                    ptp_log('[PTP Split] Refund failed for escrow ' . $id . ': ' . $refund->get_error_message());
                }
            }
            
            // Transfer 50% to trainer
            if ($trainer_gets > 0) {
                $trainer_stripe_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $escrow->trainer_id
                ));
                if ($trainer_stripe_id && class_exists('PTP_Stripe')) {
                    $transfer = PTP_Stripe::create_transfer(
                        round($trainer_gets * 100),
                        $trainer_stripe_id,
                        'Split dispute resolution - Booking #' . $escrow->booking_id
                    );
                    if (is_wp_error($transfer)) {
                        ptp_log('[PTP Split] Transfer failed for escrow ' . $id . ': ' . $transfer->get_error_message());
                    }
                }
            }
            
            // Update escrow with split amounts
            $wpdb->update(
                $wpdb->prefix . 'ptp_escrow',
                array('refund_amount' => $refund_amount),
                array('id' => $id)
            );
        }
        
        wp_send_json_success(array(
            'resolution' => $resolution,
            'status' => $status
        ));
    }
    
    public static function ajax_manual_payout() {
        check_ajax_referer('ptp_admin_payments', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        
        global $wpdb;
        $trainer_id = intval($_POST['trainer_id']);
        
        // Get trainer
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            wp_send_json_error('Trainer not found');
        }
        
        if (empty($trainer->stripe_account_id)) {
            wp_send_json_error('Trainer has not connected Stripe');
        }
        
        // Get all pending escrow for this trainer
        $pending = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_escrow 
            WHERE trainer_id = %d 
            AND status IN ('holding', 'session_complete')
        ", $trainer_id));
        
        if (empty($pending)) {
            wp_send_json_error('No pending payouts for this trainer');
        }
        
        $total_amount = 0;
        $released_ids = array();
        
        foreach ($pending as $escrow) {
            // Update status to released
            $wpdb->update(
                $wpdb->prefix . 'ptp_escrow',
                array(
                    'status' => 'released',
                    'released_at' => current_time('mysql'),
                    'release_method' => 'manual_admin',
                    'release_notes' => 'Manual payout by admin user ID ' . get_current_user_id()
                ),
                array('id' => $escrow->id)
            );
            
            $total_amount += $escrow->trainer_amount;
            $released_ids[] = $escrow->id;
            
            // Log the release
            $wpdb->insert(
                $wpdb->prefix . 'ptp_escrow_log',
                array(
                    'escrow_id' => $escrow->id,
                    'event_type' => 'manual_release',
                    'message' => sprintf('Manual payout by admin. Trainer amount: $%s', number_format($escrow->trainer_amount, 2)),
                    'user_id' => get_current_user_id(),
                    'created_at' => current_time('mysql')
                )
            );
        }
        
        // Try to process Stripe transfer if configured
        if (class_exists('PTP_Stripe') && method_exists('PTP_Stripe', 'create_transfer')) {
            try {
                PTP_Stripe::create_transfer(
                    $total_amount * 100, // Convert to cents
                    $trainer->stripe_account_id,
                    'Manual payout for escrow IDs: ' . implode(', ', $released_ids)
                );
            } catch (Exception $e) {
                ptp_log('[PTP Payout] Stripe transfer failed: ' . $e->getMessage());
                // Don't fail - funds are marked as released, admin can process manually
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf('Released $%s to %s', number_format($total_amount, 2), $trainer->display_name),
            'amount' => $total_amount,
            'count' => count($released_ids)
        ));
    }
}
