<?php
/**
 * Admin Payout Queue
 * Process trainer payouts via Venmo, PayPal, Zelle, Cash App, etc.
 */

defined('ABSPATH') || exit;

class PTP_Admin_Payouts {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }
    
    public static function add_menu() {
        // Note: Main payouts page is registered in class-ptp-admin.php
        // This class only provides the render_page function as an alternative
        // Commenting out to avoid duplicate menu registration
        /*
        add_submenu_page(
            'ptp-dashboard',
            'Trainer Payouts',
            '💰 Payouts',
            'manage_options',
            'ptp-payouts',
            array(__CLASS__, 'render_page')
        );
        */
    }
    
    public static function enqueue_scripts($hook) {
        if ($hook !== 'ptp-training_page_ptp-payouts') {
            return;
        }
        
        wp_enqueue_style('ptp-admin-payouts', plugins_url('../assets/css/admin-payouts.css', __FILE__), array(), PTP_VERSION);
    }
    
    public static function render_page() {
        global $wpdb;
        
        // Get stats
        $stats = self::get_payout_stats();
        $pending = PTP_Trainer_Payouts::get_payout_queue();
        
        ?>
        <div class="wrap ptp-payouts-admin">
            <h1>💰 Trainer Payouts</h1>
            
            <!-- Stats Cards -->
            <div class="ptp-stats-row">
                <div class="ptp-stat-card pending">
                    <div class="ptp-stat-icon">⏳</div>
                    <div class="ptp-stat-content">
                        <div class="ptp-stat-value"><?php echo count($pending); ?></div>
                        <div class="ptp-stat-label">Pending Payouts</div>
                    </div>
                </div>
                <div class="ptp-stat-card total">
                    <div class="ptp-stat-icon">💵</div>
                    <div class="ptp-stat-content">
                        <div class="ptp-stat-value">$<?php echo number_format($stats->pending_total, 2); ?></div>
                        <div class="ptp-stat-label">Total Pending</div>
                    </div>
                </div>
                <div class="ptp-stat-card paid">
                    <div class="ptp-stat-icon">✅</div>
                    <div class="ptp-stat-content">
                        <div class="ptp-stat-value">$<?php echo number_format($stats->paid_this_week, 2); ?></div>
                        <div class="ptp-stat-label">Paid This Week</div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Tabs -->
            <div class="ptp-payout-filters">
                <button class="ptp-filter-btn active" data-method="all">All Methods</button>
                <button class="ptp-filter-btn" data-method="venmo">Venmo</button>
                <button class="ptp-filter-btn" data-method="paypal">PayPal</button>
                <button class="ptp-filter-btn" data-method="zelle">Zelle</button>
                <button class="ptp-filter-btn" data-method="cashapp">Cash App</button>
                <button class="ptp-filter-btn" data-method="direct_deposit">Direct Deposit</button>
            </div>
            
            <!-- Payout Queue -->
            <div class="ptp-payout-queue">
                <?php if (empty($pending)) : ?>
                    <div class="ptp-empty-state">
                        <div class="ptp-empty-icon">🎉</div>
                        <h3>All caught up!</h3>
                        <p>No pending payouts to process.</p>
                    </div>
                <?php else : ?>
                    <table class="ptp-payout-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all"></th>
                                <th>Trainer</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Pay To</th>
                                <th>Requested</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending as $payout) : 
                                $instructions = PTP_Trainer_Payouts::get_payout_instructions($payout);
                            ?>
                            <tr data-payout-id="<?php echo esc_attr($payout->id); ?>" data-method="<?php echo esc_attr($payout->payout_method); ?>">
                                <td><input type="checkbox" class="payout-checkbox" value="<?php echo esc_attr($payout->id); ?>"></td>
                                <td>
                                    <strong><?php echo esc_html($payout->trainer_name); ?></strong>
                                    <br><small><?php echo esc_html($payout->trainer_email); ?></small>
                                </td>
                                <td class="ptp-amount">
                                    <strong>$<?php echo number_format($payout->amount, 2); ?></strong>
                                </td>
                                <td>
                                    <span class="ptp-method-badge ptp-method-<?php echo esc_attr($payout->payout_method); ?>">
                                        <?php echo esc_html($instructions['platform']); ?>
                                    </span>
                                </td>
                                <td class="ptp-pay-to">
                                    <code class="ptp-copyable" data-copy="<?php echo esc_attr($instructions['recipient']); ?>">
                                        <?php echo esc_html($instructions['recipient']); ?>
                                    </code>
                                    <button class="ptp-copy-btn" title="Copy">📋</button>
                                </td>
                                <td>
                                    <?php echo human_time_diff(strtotime($payout->created_at), current_time('timestamp')); ?> ago
                                </td>
                                <td class="ptp-actions">
                                    <button class="button button-primary ptp-mark-paid" data-id="<?php echo esc_attr($payout->id); ?>">
                                        Mark Paid
                                    </button>
                                    <button class="button ptp-view-details" data-id="<?php echo esc_attr($payout->id); ?>" data-instructions="<?php echo esc_attr(json_encode($instructions)); ?>">
                                        Details
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="ptp-bulk-actions">
                        <button class="button button-primary" id="bulk-mark-paid" disabled>
                            Mark Selected as Paid
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Instructions Modal -->
            <div id="ptp-payout-modal" class="ptp-modal" style="display: none;">
                <div class="ptp-modal-content">
                    <button class="ptp-modal-close">&times;</button>
                    <h2>Payment Instructions</h2>
                    <div class="ptp-modal-body">
                        <div class="ptp-instruction-header">
                            <span class="ptp-instruction-platform"></span>
                            <span class="ptp-instruction-amount"></span>
                        </div>
                        <div class="ptp-instruction-recipient">
                            <label>Send to:</label>
                            <code class="ptp-copyable"></code>
                            <button class="ptp-copy-btn">📋 Copy</button>
                        </div>
                        <div class="ptp-instruction-steps">
                            <label>Steps:</label>
                            <pre></pre>
                        </div>
                    </div>
                    <div class="ptp-modal-footer">
                        <div class="ptp-reference-input">
                            <label>Reference/Confirmation # (optional):</label>
                            <input type="text" id="payout-reference" placeholder="e.g., Venmo transaction ID">
                        </div>
                        <button class="button button-primary ptp-confirm-paid" data-id="">
                            ✓ Confirm Payment Sent
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .ptp-payouts-admin {
            max-width: 1200px;
        }
        
        .ptp-stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0 30px;
        }
        
        .ptp-stat-card {
            display: flex;
            align-items: center;
            gap: 15px;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .ptp-stat-icon {
            font-size: 32px;
        }
        
        .ptp-stat-value {
            font-size: 24px;
            font-weight: 700;
        }
        
        .ptp-stat-label {
            color: #666;
            font-size: 13px;
        }
        
        .ptp-stat-card.pending { border-left: 4px solid #ffc107; }
        .ptp-stat-card.total { border-left: 4px solid #17a2b8; }
        .ptp-stat-card.paid { border-left: 4px solid #28a745; }
        
        .ptp-payout-filters {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .ptp-filter-btn {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .ptp-filter-btn:hover {
            border-color: #FCB900;
        }
        
        .ptp-filter-btn.active {
            background: #FCB900;
            border-color: #FCB900;
            color: #0E0F11;
            font-weight: 600;
        }
        
        .ptp-payout-queue {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .ptp-empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .ptp-empty-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        
        .ptp-payout-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .ptp-payout-table th,
        .ptp-payout-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .ptp-payout-table th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            color: #666;
        }
        
        .ptp-payout-table tr:hover {
            background: #fffdf5;
        }
        
        .ptp-amount {
            font-size: 16px;
            color: #28a745;
        }
        
        .ptp-method-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .ptp-method-venmo { background: #3D95CE20; color: #3D95CE; }
        .ptp-method-paypal { background: #00308720; color: #003087; }
        .ptp-method-zelle { background: #6D1ED420; color: #6D1ED4; }
        .ptp-method-cashapp { background: #00D63220; color: #00a025; }
        .ptp-method-direct_deposit { background: #4A556820; color: #4A5568; }
        
        .ptp-pay-to {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .ptp-copyable {
            background: #f1f1f1;
            padding: 4px 8px;
            border-radius: 4px;
            font-family: monospace;
        }
        
        .ptp-copy-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            opacity: 0.7;
        }
        
        .ptp-copy-btn:hover {
            opacity: 1;
        }
        
        .ptp-actions {
            display: flex;
            gap: 8px;
        }
        
        .ptp-bulk-actions {
            padding: 16px;
            border-top: 1px solid #eee;
            background: #f8f9fa;
        }
        
        /* Modal */
        .ptp-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100000;
        }
        
        .ptp-modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            position: relative;
        }
        
        .ptp-modal-content h2 {
            margin: 0;
            padding: 20px 24px;
            border-bottom: 1px solid #eee;
        }
        
        .ptp-modal-close {
            position: absolute;
            top: 16px;
            right: 20px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        
        .ptp-modal-body {
            padding: 24px;
        }
        
        .ptp-instruction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .ptp-instruction-platform {
            font-size: 20px;
            font-weight: 600;
        }
        
        .ptp-instruction-amount {
            font-size: 24px;
            font-weight: 700;
            color: #28a745;
        }
        
        .ptp-instruction-recipient {
            margin-bottom: 20px;
        }
        
        .ptp-instruction-recipient label,
        .ptp-instruction-steps label {
            display: block;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        
        .ptp-instruction-recipient code {
            display: inline-block;
            font-size: 18px;
            background: #f1f1f1;
            padding: 10px 16px;
            border-radius: 8px;
            margin-right: 10px;
        }
        
        .ptp-instruction-steps pre {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin: 0;
            white-space: pre-wrap;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .ptp-modal-footer {
            padding: 20px 24px;
            border-top: 1px solid #eee;
            background: #f8f9fa;
            border-radius: 0 0 16px 16px;
        }
        
        .ptp-reference-input {
            margin-bottom: 16px;
        }
        
        .ptp-reference-input label {
            display: block;
            font-size: 13px;
            margin-bottom: 6px;
        }
        
        .ptp-reference-input input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .ptp-confirm-paid {
            width: 100%;
            padding: 12px !important;
            font-size: 15px !important;
        }
        
        /* Hidden rows for filtering */
        .ptp-payout-table tr.hidden {
            display: none;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            const $modal = $('#ptp-payout-modal');
            
            // Copy to clipboard
            $(document).on('click', '.ptp-copy-btn', function() {
                const text = $(this).prev('.ptp-copyable').text() || $(this).prev('.ptp-copyable').data('copy');
                navigator.clipboard.writeText(text);
                
                const $btn = $(this);
                const original = $btn.text();
                $btn.text('✓ Copied!');
                setTimeout(() => $btn.text(original), 1500);
            });
            
            // Filter by method
            $('.ptp-filter-btn').on('click', function() {
                const method = $(this).data('method');
                
                $('.ptp-filter-btn').removeClass('active');
                $(this).addClass('active');
                
                if (method === 'all') {
                    $('.ptp-payout-table tr[data-method]').removeClass('hidden');
                } else {
                    $('.ptp-payout-table tr[data-method]').addClass('hidden');
                    $(`.ptp-payout-table tr[data-method="${method}"]`).removeClass('hidden');
                }
            });
            
            // Select all
            $('#select-all').on('change', function() {
                const checked = $(this).prop('checked');
                $('.payout-checkbox:visible').prop('checked', checked);
                updateBulkButton();
            });
            
            // Individual checkbox
            $(document).on('change', '.payout-checkbox', updateBulkButton);
            
            function updateBulkButton() {
                const count = $('.payout-checkbox:checked').length;
                $('#bulk-mark-paid').prop('disabled', count === 0)
                    .text(count > 0 ? `Mark ${count} Selected as Paid` : 'Mark Selected as Paid');
            }
            
            // View details
            $('.ptp-view-details').on('click', function() {
                const instructions = $(this).data('instructions');
                const id = $(this).data('id');
                
                $modal.find('.ptp-instruction-platform').text(instructions.platform);
                $modal.find('.ptp-instruction-amount').text(instructions.amount);
                $modal.find('.ptp-instruction-recipient code').text(instructions.recipient);
                $modal.find('.ptp-instruction-steps pre').text(instructions.instructions);
                $modal.find('.ptp-confirm-paid').data('id', id);
                $modal.find('#payout-reference').val('');
                
                $modal.show();
            });
            
            // Close modal
            $('.ptp-modal-close').on('click', function() {
                $modal.hide();
            });
            
            $modal.on('click', function(e) {
                if (e.target === this) {
                    $modal.hide();
                }
            });
            
            // Mark paid (single)
            $('.ptp-mark-paid').on('click', function() {
                const id = $(this).data('id');
                markPaid([id]);
            });
            
            // Mark paid from modal
            $('.ptp-confirm-paid').on('click', function() {
                const id = $(this).data('id');
                const reference = $('#payout-reference').val();
                markPaid([id], reference);
            });
            
            // Bulk mark paid
            $('#bulk-mark-paid').on('click', function() {
                const ids = $('.payout-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (ids.length > 0) {
                    markPaid(ids);
                }
            });
            
            function markPaid(ids, reference = '') {
                if (!confirm(`Mark ${ids.length} payout(s) as paid?`)) {
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ptp_admin_process_manual_payout',
                        nonce: '<?php echo wp_create_nonce('ptp_admin_nonce'); ?>',
                        payout_id: ids[0], // For single, use first
                        reference: reference
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || 'Error processing payout');
                        }
                    },
                    error: function() {
                        alert('Error connecting to server');
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    private static function get_payout_stats() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_payouts';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return (object) array(
                'pending_total' => 0,
                'paid_this_week' => 0,
            );
        }
        
        $stats = new stdClass();
        $stats->pending_total = $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = 'pending'") ?: 0;
        $stats->paid_this_week = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE status = 'completed' AND processed_at >= %s",
            date('Y-m-d', strtotime('-7 days'))
        )) ?: 0;
        
        return $stats;
    }
}

PTP_Admin_Payouts::init();
