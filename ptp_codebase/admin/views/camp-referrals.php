<?php
/**
 * Camp Referrals Admin View
 * @version 146.0.0
 */

defined('ABSPATH') || exit;

// Calculate stats
global $wpdb;
$total_codes = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_camp_referrals");
$total_uses = $wpdb->get_var("SELECT SUM(times_used) FROM {$wpdb->prefix}ptp_camp_referrals") ?: 0;
$total_discount = $wpdb->get_var("SELECT SUM(total_discount_given) FROM {$wpdb->prefix}ptp_camp_referrals") ?: 0;
?>

<div class="wrap ptp-camp-admin">
    <h1 class="wp-heading-inline">Referral Codes</h1>
    
    <!-- Stats -->
    <div class="ptp-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
        <div class="ptp-stat-card">
            <span class="stat-value"><?php echo number_format($total_codes); ?></span>
            <span class="stat-label">Total Codes Generated</span>
        </div>
        <div class="ptp-stat-card">
            <span class="stat-value"><?php echo number_format($total_uses); ?></span>
            <span class="stat-label">Times Used</span>
        </div>
        <div class="ptp-stat-card highlight">
            <span class="stat-value">$<?php echo number_format($total_discount, 0); ?></span>
            <span class="stat-label">Total Discounts Given</span>
        </div>
    </div>
    
    <div class="notice notice-info" style="margin: 20px 0;">
        <p><strong>How referrals work:</strong> When a customer completes a camp registration, they receive a unique referral code. When someone uses their code, both parties get $25 off. Codes are automatically generated and tracked.</p>
    </div>
    
    <h2>Recent Referral Codes</h2>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 120px;">Code</th>
                <th>Created By</th>
                <th>Source Order</th>
                <th style="width: 100px;">Times Used</th>
                <th style="width: 120px;">Discounts Given</th>
                <th style="width: 80px;">Status</th>
                <th style="width: 120px;">Created</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($referrals)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 40px;">
                        No referral codes yet. They are automatically generated when orders are completed.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($referrals as $referral): ?>
                    <tr>
                        <td>
                            <code style="font-size: 14px; font-weight: bold; background: #FCB900; padding: 4px 10px; border-radius: 4px;">
                                <?php echo esc_html($referral->code); ?>
                            </code>
                        </td>
                        <td>
                            <?php if ($referral->billing_first_name): ?>
                                <strong><?php echo esc_html($referral->billing_first_name . ' ' . $referral->billing_last_name); ?></strong>
                                <br><small><?php echo esc_html($referral->order_email); ?></small>
                            <?php else: ?>
                                <span style="color: #999;">Unknown</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($referral->order_id): ?>
                                <a href="<?php echo admin_url('admin.php?page=ptp-camp-orders&search=' . $referral->order_id); ?>">
                                    Order #<?php echo $referral->order_id; ?>
                                </a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo $referral->times_used; ?></strong>
                            <?php if ($referral->max_uses): ?>
                                <small style="color: #666;">/ <?php echo $referral->max_uses; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            $<?php echo number_format($referral->total_discount_given, 0); ?>
                        </td>
                        <td>
                            <?php if ($referral->is_active): ?>
                                <span class="status-badge status-completed">Active</span>
                            <?php else: ?>
                                <span class="status-badge status-cancelled">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo date('M j, Y', strtotime($referral->created_at)); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <h2 style="margin-top: 40px;">Top Referrers</h2>
    
    <?php
    $top_referrers = $wpdb->get_results("
        SELECT r.*, o.billing_first_name, o.billing_last_name, o.billing_email
        FROM {$wpdb->prefix}ptp_camp_referrals r
        LEFT JOIN {$wpdb->prefix}ptp_unified_camp_orders o ON r.order_id = o.id
        WHERE r.times_used > 0
        ORDER BY r.times_used DESC
        LIMIT 10
    ");
    ?>
    
    <?php if (empty($top_referrers)): ?>
        <p style="color: #666;">No referrals have been used yet.</p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped" style="max-width: 600px;">
            <thead>
                <tr>
                    <th>Referrer</th>
                    <th style="width: 100px;">Code</th>
                    <th style="width: 100px;">Referrals</th>
                    <th style="width: 120px;">Value Generated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_referrers as $referrer): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($referrer->billing_first_name . ' ' . $referrer->billing_last_name); ?></strong>
                            <br><small><?php echo esc_html($referrer->billing_email); ?></small>
                        </td>
                        <td>
                            <code><?php echo esc_html($referrer->code); ?></code>
                        </td>
                        <td>
                            <strong><?php echo $referrer->times_used; ?></strong>
                        </td>
                        <td>
                            $<?php echo number_format($referrer->total_discount_given, 0); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
