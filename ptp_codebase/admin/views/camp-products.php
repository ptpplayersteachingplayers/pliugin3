<?php
/**
 * Camp Products Admin View
 * @version 146.0.0
 */

defined('ABSPATH') || exit;
?>

<div class="wrap ptp-camp-admin">
    <h1 class="wp-heading-inline">Camp Products</h1>
    <button type="button" class="page-title-action" id="btn-sync-stripe">Sync from Stripe</button>
    <a href="https://dashboard.stripe.com/products" target="_blank" class="page-title-action">Manage in Stripe →</a>
    
    <div class="notice notice-info" style="margin: 20px 0;">
        <p><strong>How it works:</strong> Create camp products directly in Stripe Dashboard with the required metadata. Then click "Sync from Stripe" to import them here. Orders are processed through Stripe Checkout.</p>
    </div>
    
    <div class="ptp-stripe-setup">
        <h3>Stripe Product Setup Guide</h3>
        <p>When creating a product in Stripe, add these metadata fields:</p>
        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th>Metadata Key</th>
                    <th>Description</th>
                    <th>Example</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>type</code></td>
                    <td>Product type (required)</td>
                    <td><code>camp</code> or <code>clinic</code></td>
                </tr>
                <tr>
                    <td><code>dates</code></td>
                    <td>Camp dates</td>
                    <td><code>June 24-28, 2024</code></td>
                </tr>
                <tr>
                    <td><code>location</code></td>
                    <td>Venue name/address</td>
                    <td><code>Villanova Stadium</code></td>
                </tr>
                <tr>
                    <td><code>time</code></td>
                    <td>Daily schedule</td>
                    <td><code>9:00 AM - 12:00 PM</code></td>
                </tr>
                <tr>
                    <td><code>age_min</code></td>
                    <td>Minimum age</td>
                    <td><code>6</code></td>
                </tr>
                <tr>
                    <td><code>age_max</code></td>
                    <td>Maximum age</td>
                    <td><code>14</code></td>
                </tr>
                <tr>
                    <td><code>capacity</code></td>
                    <td>Max registrations</td>
                    <td><code>50</code></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <h2 style="margin-top: 30px;">Synced Products</h2>
    
    <?php if (empty($products)): ?>
        <div class="notice notice-warning">
            <p>No camp products found. Click "Sync from Stripe" to import products, or create new products in Stripe Dashboard first.</p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 60px;">Image</th>
                    <th>Name</th>
                    <th>Price</th>
                    <th>Dates</th>
                    <th>Location</th>
                    <th>Capacity</th>
                    <th>Status</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td>
                            <?php if ($product->image_url): ?>
                                <img src="<?php echo esc_url($product->image_url); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
                            <?php else: ?>
                                <div style="width: 50px; height: 50px; background: #f0f0f0; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #999;">
                                    ⚽
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html($product->name); ?></strong>
                            <?php if ($product->description): ?>
                                <br><small style="color: #666;"><?php echo esc_html(wp_trim_words($product->description, 10)); ?></small>
                            <?php endif; ?>
                            <br><code style="font-size: 10px; color: #999;"><?php echo esc_html($product->stripe_product_id); ?></code>
                        </td>
                        <td>
                            <strong>$<?php echo number_format($product->price_cents / 100, 0); ?></strong>
                        </td>
                        <td>
                            <?php echo esc_html($product->camp_dates ?: '—'); ?>
                        </td>
                        <td>
                            <?php echo esc_html($product->camp_location ?: '—'); ?>
                        </td>
                        <td>
                            <?php if ($product->camp_capacity): ?>
                                <?php 
                                $remaining = $product->camp_capacity - $product->camp_registered;
                                $percent = ($product->camp_registered / $product->camp_capacity) * 100;
                                ?>
                                <div style="background: #f0f0f0; border-radius: 10px; height: 10px; width: 80px; overflow: hidden;">
                                    <div style="background: <?php echo $percent > 80 ? '#ef4444' : '#10b981'; ?>; height: 100%; width: <?php echo min(100, $percent); ?>%;"></div>
                                </div>
                                <small><?php echo $product->camp_registered; ?>/<?php echo $product->camp_capacity; ?> registered</small>
                            <?php else: ?>
                                <span style="color: #999;">Unlimited</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($product->active): ?>
                                <span class="status-badge status-completed">Active</span>
                            <?php else: ?>
                                <span class="status-badge status-cancelled">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="https://dashboard.stripe.com/products/<?php echo esc_attr($product->stripe_product_id); ?>" target="_blank" class="button button-small">Edit in Stripe</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
.ptp-stripe-setup {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
}

.ptp-stripe-setup h3 {
    margin-top: 0;
}

.ptp-stripe-setup code {
    background: #f0f0f0;
    padding: 2px 6px;
    border-radius: 3px;
}
</style>

<script>
jQuery(document).ready(function($) {
    $('#btn-sync-stripe').on('click', function() {
        var $btn = $(this);
        $btn.text('Syncing...').prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'ptp_sync_stripe_products',
                nonce: ptpCampAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.data.message);
                }
            },
            error: function() {
                alert('Failed to sync products. Please try again.');
            },
            complete: function() {
                $btn.text('Sync from Stripe').prop('disabled', false);
            }
        });
    });
});
</script>
