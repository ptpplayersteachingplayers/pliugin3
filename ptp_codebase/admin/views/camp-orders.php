<?php
/**
 * Camp Orders Admin View
 * @version 146.0.0
 */

defined('ABSPATH') || exit;
?>

<div class="wrap ptp-camp-admin">
    <h1 class="wp-heading-inline">Camp Orders</h1>
    <a href="#" class="page-title-action" id="btn-export-orders">Export</a>
    
    <!-- Stats Cards -->
    <div class="ptp-stats-grid">
        <div class="ptp-stat-card">
            <span class="stat-value">$<?php echo number_format($stats['today_revenue'], 0); ?></span>
            <span class="stat-label">Today's Revenue</span>
            <span class="stat-sub"><?php echo $stats['today_orders']; ?> orders</span>
        </div>
        <div class="ptp-stat-card">
            <span class="stat-value">$<?php echo number_format($stats['month_revenue'], 0); ?></span>
            <span class="stat-label">This Month</span>
            <span class="stat-sub"><?php echo $stats['month_orders']; ?> orders</span>
        </div>
        <div class="ptp-stat-card">
            <span class="stat-value"><?php echo number_format($stats['total_campers']); ?></span>
            <span class="stat-label">Total Campers</span>
            <span class="stat-sub">All time</span>
        </div>
        <div class="ptp-stat-card highlight">
            <span class="stat-value">$<?php echo number_format($stats['total_revenue'], 0); ?></span>
            <span class="stat-label">Total Revenue</span>
            <span class="stat-sub"><?php echo $stats['total_orders']; ?> orders</span>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="ptp-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="ptp-camp-orders">
            
            <select name="status">
                <option value="">All Statuses</option>
                <option value="pending" <?php selected($status, 'pending'); ?>>Pending</option>
                <option value="processing" <?php selected($status, 'processing'); ?>>Processing</option>
                <option value="completed" <?php selected($status, 'completed'); ?>>Completed</option>
                <option value="cancelled" <?php selected($status, 'cancelled'); ?>>Cancelled</option>
                <option value="refunded" <?php selected($status, 'refunded'); ?>>Refunded</option>
            </select>
            
            <input type="text" name="search" placeholder="Search orders..." value="<?php echo esc_attr($search); ?>">
            
            <button type="submit" class="button">Filter</button>
            
            <?php if ($status || $search): ?>
                <a href="?page=ptp-camp-orders" class="button">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Orders Table -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 120px;">Order</th>
                <th>Customer</th>
                <th>Campers</th>
                <th style="width: 100px;">Total</th>
                <th style="width: 100px;">Status</th>
                <th style="width: 140px;">Date</th>
                <th style="width: 120px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 40px;">
                        No orders found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <tr data-order-id="<?php echo $order->id; ?>">
                        <td>
                            <strong><a href="#" class="view-order" data-order-id="<?php echo $order->id; ?>">#<?php echo esc_html($order->order_number); ?></a></strong>
                            <?php if ($order->referral_code_used): ?>
                                <br><small style="color: #10b981;">Referral: <?php echo esc_html($order->referral_code_used); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html($order->billing_first_name . ' ' . $order->billing_last_name); ?></strong>
                            <br><a href="mailto:<?php echo esc_attr($order->billing_email); ?>"><?php echo esc_html($order->billing_email); ?></a>
                            <?php if ($order->billing_phone): ?>
                                <br><small><?php echo esc_html($order->billing_phone); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="camper-count"><?php echo $order->item_count; ?> camper<?php echo $order->item_count > 1 ? 's' : ''; ?></span>
                            <br><small style="color: #6b7280;"><?php echo esc_html(wp_trim_words($order->campers, 5)); ?></small>
                        </td>
                        <td>
                            <strong>$<?php echo number_format($order->total_amount, 2); ?></strong>
                            <?php if ($order->discount_amount > 0): ?>
                                <br><small style="color: #10b981;">Saved $<?php echo number_format($order->discount_amount, 0); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo esc_attr($order->status); ?>">
                                <?php echo ucfirst($order->status); ?>
                            </span>
                            <?php if ($order->payment_status === 'refunded'): ?>
                                <br><small style="color: #ef4444;">Refunded</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo date('M j, Y', strtotime($order->created_at)); ?>
                            <br><small><?php echo date('g:i A', strtotime($order->created_at)); ?></small>
                        </td>
                        <td>
                            <button type="button" class="button button-small view-order" data-order-id="<?php echo $order->id; ?>">View</button>
                            <?php if ($order->status === 'completed' && $order->payment_status !== 'refunded'): ?>
                                <button type="button" class="button button-small refund-order" data-order-id="<?php echo $order->id; ?>" data-amount="<?php echo $order->total_amount; ?>">Refund</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total; ?> items</span>
                <span class="pagination-links">
                    <?php if ($paged > 1): ?>
                        <a class="prev-page button" href="<?php echo add_query_arg('paged', $paged - 1); ?>">‹</a>
                    <?php endif; ?>
                    
                    <span class="paging-input">
                        <span class="current-page"><?php echo $paged; ?></span> of 
                        <span class="total-pages"><?php echo $total_pages; ?></span>
                    </span>
                    
                    <?php if ($paged < $total_pages): ?>
                        <a class="next-page button" href="<?php echo add_query_arg('paged', $paged + 1); ?>">›</a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Order Detail Modal -->
<div id="order-modal" class="ptp-modal" style="display: none;">
    <div class="ptp-modal-content">
        <div class="ptp-modal-header">
            <h2>Order Details</h2>
            <button type="button" class="ptp-modal-close">&times;</button>
        </div>
        <div class="ptp-modal-body" id="order-modal-body">
            Loading...
        </div>
    </div>
</div>

<!-- Export Modal -->
<div id="export-modal" class="ptp-modal" style="display: none;">
    <div class="ptp-modal-content" style="max-width: 400px;">
        <div class="ptp-modal-header">
            <h2>Export Orders</h2>
            <button type="button" class="ptp-modal-close">&times;</button>
        </div>
        <div class="ptp-modal-body">
            <form id="export-form">
                <p>
                    <label>Status</label>
                    <select name="export_status" style="width: 100%;">
                        <option value="">All Statuses</option>
                        <option value="completed">Completed</option>
                        <option value="pending">Pending</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </p>
                <p>
                    <label>Date From</label>
                    <input type="date" name="date_from" style="width: 100%;">
                </p>
                <p>
                    <label>Date To</label>
                    <input type="date" name="date_to" style="width: 100%;">
                </p>
                <p>
                    <button type="submit" class="button button-primary" style="width: 100%;">Download CSV</button>
                </p>
            </form>
        </div>
    </div>
</div>

<style>
.ptp-camp-admin .ptp-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin: 20px 0;
}

.ptp-stat-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}

.ptp-stat-card.highlight {
    background: #FCB900;
    border-color: #FCB900;
}

.ptp-stat-card .stat-value {
    display: block;
    font-size: 28px;
    font-weight: 700;
    color: #1d2327;
}

.ptp-stat-card .stat-label {
    display: block;
    font-size: 14px;
    color: #50575e;
    margin-top: 5px;
}

.ptp-stat-card .stat-sub {
    display: block;
    font-size: 12px;
    color: #8c8f94;
    margin-top: 3px;
}

.ptp-filters {
    background: #fff;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 20px;
}

.ptp-filters form {
    display: flex;
    gap: 10px;
    align-items: center;
}

.ptp-filters select, .ptp-filters input[type="text"] {
    padding: 6px 10px;
}

.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.status-pending { background: #fef3c7; color: #92400e; }
.status-processing { background: #dbeafe; color: #1e40af; }
.status-completed { background: #d1fae5; color: #065f46; }
.status-cancelled { background: #f3f4f6; color: #374151; }
.status-refunded { background: #fee2e2; color: #991b1b; }

.camper-count {
    background: #f3f4f6;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
}

/* Modal */
.ptp-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ptp-modal-content {
    background: #fff;
    border-radius: 8px;
    max-width: 700px;
    width: 90%;
    max-height: 80vh;
    overflow: auto;
}

.ptp-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
}

.ptp-modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.ptp-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.ptp-modal-body {
    padding: 20px;
}

@media (max-width: 1200px) {
    .ptp-camp-admin .ptp-stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // View order
    $(document).on('click', '.view-order', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        
        $('#order-modal').show();
        $('#order-modal-body').html('Loading...');
        
        $.ajax({
            url: ajaxurl,
            method: 'GET',
            data: {
                action: 'ptp_get_camp_order_details',
                nonce: ptpCampAdmin.nonce,
                order_id: orderId
            },
            success: function(response) {
                if (response.success) {
                    $('#order-modal-body').html(response.data.html);
                } else {
                    $('#order-modal-body').html('<p>Error loading order details.</p>');
                }
            }
        });
    });
    
    // Close modal
    $(document).on('click', '.ptp-modal-close, .ptp-modal', function(e) {
        if (e.target === this) {
            $('.ptp-modal').hide();
        }
    });
    
    // Refund order
    $(document).on('click', '.refund-order', function() {
        var orderId = $(this).data('order-id');
        var amount = $(this).data('amount');
        
        if (!confirm('Are you sure you want to refund $' + amount + ' for this order?')) {
            return;
        }
        
        var reason = prompt('Refund reason (optional):');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ptp_refund_camp_order',
                nonce: ptpCampAdmin.nonce,
                order_id: orderId,
                amount: amount,
                reason: reason
            },
            success: function(response) {
                if (response.success) {
                    alert('Refund processed successfully');
                    location.reload();
                } else {
                    alert('Error: ' + response.data.message);
                }
            }
        });
    });
    
    // Export
    $('#btn-export-orders').on('click', function(e) {
        e.preventDefault();
        $('#export-modal').show();
    });
    
    $('#export-form').on('submit', function(e) {
        e.preventDefault();
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ptp_export_camp_orders',
                nonce: ptpCampAdmin.nonce,
                status: $('[name="export_status"]').val(),
                date_from: $('[name="date_from"]').val(),
                date_to: $('[name="date_to"]').val()
            },
            success: function(response) {
                if (response.success) {
                    var blob = new Blob([response.data.csv], { type: 'text/csv' });
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = response.data.filename;
                    a.click();
                    $('#export-modal').hide();
                } else {
                    alert('Export failed: ' + response.data.message);
                }
            }
        });
    });
});
</script>
