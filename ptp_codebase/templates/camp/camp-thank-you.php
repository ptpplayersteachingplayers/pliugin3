<?php
/**
 * PTP Camp Thank You Template
 * 
 * Displayed after successful camp registration payment
 * 
 * @version 146.0.0
 */

defined('ABSPATH') || exit;

// Get order from URL
$order_number = sanitize_text_field($_GET['order'] ?? '');
$session_id = sanitize_text_field($_GET['session_id'] ?? '');

$order = null;
if ($order_number) {
    $order = PTP_Camp_Orders::get_order_by_number($order_number);
}

// If coming from Stripe, verify and complete order
if ($order && $session_id && $order->status === 'pending') {
    // Complete the order if not already done by webhook
    PTP_Camp_Orders::complete_order($order->id);
    // Refresh order data
    $order = PTP_Camp_Orders::get_order($order->id);
}
?>

<div class="ptp-thank-you">
    <?php if ($order && $order->status === 'completed'): ?>
        <!-- Success State -->
        <div class="thank-you-header">
            <div class="success-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <h1>Registration Complete!</h1>
            <p class="subtitle">Thank you for registering for PTP Soccer Camp</p>
        </div>
        
        <div class="thank-you-content">
            <!-- Order Summary Card -->
            <div class="order-card">
                <div class="order-card-header">
                    <h2>Order Confirmation</h2>
                    <span class="order-number">#<?php echo esc_html($order->order_number); ?></span>
                </div>
                
                <div class="order-details">
                    <div class="detail-row">
                        <span class="label">Date</span>
                        <span class="value"><?php echo date('F j, Y', strtotime($order->created_at)); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Email</span>
                        <span class="value"><?php echo esc_html($order->billing_email); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">Total Paid</span>
                        <span class="value total">$<?php echo number_format($order->total_amount, 2); ?></span>
                    </div>
                </div>
                
                <div class="campers-list">
                    <h3>Registered Campers</h3>
                    <?php foreach ($order->items as $item): ?>
                        <div class="camper-item">
                            <div class="camper-avatar">
                                <?php echo strtoupper(substr($item->camper_first_name, 0, 1)); ?>
                            </div>
                            <div class="camper-info">
                                <strong><?php echo esc_html($item->camper_first_name . ' ' . $item->camper_last_name); ?></strong>
                                <span class="camp-name"><?php echo esc_html($item->camp_name); ?></span>
                                <?php if ($item->camp_dates): ?>
                                    <span class="camp-dates"><?php echo esc_html($item->camp_dates); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="camper-price">
                                $<?php echo number_format($item->final_price, 2); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($order->discount_amount > 0): ?>
                    <div class="savings-banner">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                            <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
                            <line x1="7" y1="7" x2="7.01" y2="7"/>
                        </svg>
                        You saved $<?php echo number_format($order->discount_amount, 2); ?>!
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Referral Card -->
            <?php if (!empty($order->referral_code_generated)): ?>
                <div class="referral-card">
                    <div class="referral-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-6"/>
                            <path d="M12 3v13"/>
                            <path d="M8 7l4-4 4 4"/>
                        </svg>
                    </div>
                    <h3>Share & Earn $25!</h3>
                    <p>Give your friends $25 off their registration and you'll get $25 credit toward your next camp!</p>
                    
                    <div class="referral-code-box">
                        <span class="code"><?php echo esc_html($order->referral_code_generated); ?></span>
                        <button type="button" class="copy-btn" onclick="copyReferralCode(this)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                            </svg>
                            Copy
                        </button>
                    </div>
                    
                    <div class="share-buttons">
                        <a href="sms:?body=Use my code <?php echo esc_attr($order->referral_code_generated); ?> for $25 off <?php echo esc_attr(function_exists('ptp_email_brand') ? ptp_email_brand('company') : 'PTP'); ?> Camp! Register at <?php echo esc_attr(str_replace(array('https://','http://'), '', home_url())); ?>" class="share-btn sms">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                            Text a Friend
                        </a>
                        <a href="mailto:?subject=<?php echo esc_attr(function_exists('ptp_email_brand') ? ptp_email_brand('company') : 'PTP'); ?> Camp - $25 Off!&body=Use my referral code <?php echo esc_attr($order->referral_code_generated); ?> for $25 off <?php echo esc_attr(function_exists('ptp_email_brand') ? ptp_email_brand('company') : 'PTP'); ?> Camp! Register at <?php echo esc_attr(str_replace(array('https://','http://'), '', home_url())); ?>" class="share-btn email">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                <polyline points="22,6 12,13 2,6"/>
                            </svg>
                            Email
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- What's Next -->
            <div class="whats-next">
                <h3>What's Next?</h3>
                
                <div class="next-steps">
                    <div class="step">
                        <div class="step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                        </div>
                        <div class="step-content">
                            <strong>Confirmation Email Sent</strong>
                            <p>Check your inbox for order details and what to bring</p>
                        </div>
                    </div>
                    
                    <div class="step">
                        <div class="step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                        </div>
                        <div class="step-content">
                            <strong>Reminder Email (1 Week Before)</strong>
                            <p>We'll send a reminder with check-in details</p>
                        </div>
                    </div>
                    
                    <div class="step">
                        <div class="step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                        </div>
                        <div class="step-content">
                            <strong>Arrive 15 Minutes Early</strong>
                            <p>Check in at the PTP tent on Day 1</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- What to Bring -->
            <div class="what-to-bring">
                <h3>What to Bring</h3>
                <div class="items-grid">
                    <div class="item">
                        <span class="emoji">⚽</span>
                        <span>Cleats</span>
                    </div>
                    <div class="item">
                        <span class="emoji">🦵</span>
                        <span>Shin Guards</span>
                    </div>
                    <div class="item">
                        <span class="emoji">💧</span>
                        <span>Water Bottle</span>
                    </div>
                    <div class="item">
                        <span class="emoji">☀️</span>
                        <span>Sunscreen</span>
                    </div>
                    <div class="item">
                        <span class="emoji">🍎</span>
                        <span>Snacks</span>
                    </div>
                    <div class="item">
                        <span class="emoji">👕</span>
                        <span>Change of Shirt</span>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="thank-you-actions">
                <a href="<?php echo home_url('/camps/'); ?>" class="btn-secondary">
                    Register Another Camper
                </a>
                <a href="<?php echo home_url('/'); ?>" class="btn-primary">
                    Back to Home
                </a>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Error/Not Found State -->
        <div class="thank-you-header error">
            <div class="error-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <h1>Order Not Found</h1>
            <p class="subtitle">We couldn't find your order. Please check your email for confirmation or contact us for help.</p>
            
            <div class="error-actions">
                <a href="<?php echo home_url('/camps/'); ?>" class="btn-primary">
                    View Camps
                </a>
                <a href="mailto:<?php echo esc_attr(function_exists('ptp_email_brand') ? ptp_email_brand('support_email') : get_option('admin_email')); ?>" class="btn-secondary">
                    Contact Support
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function copyReferralCode(btn) {
    const code = btn.previousElementSibling.textContent;
    navigator.clipboard.writeText(code).then(() => {
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><polyline points="20 6 9 17 4 12"/></svg> Copied!';
        setTimeout(() => {
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy';
        }, 2000);
    });
}
</script>

<style>
.ptp-thank-you {
    --ptp-gold: #FCB900;
    --ptp-black: #0A0A0A;
    --ptp-gray: #f3f4f6;
    --ptp-border: #e5e7eb;
    --ptp-text: #374151;
    --ptp-text-light: #6b7280;
    --ptp-success: #10b981;
    --ptp-error: #ef4444;
    
    max-width: 800px;
    margin: 0 auto;
    padding: 40px 20px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

.thank-you-header {
    text-align: center;
    margin-bottom: 40px;
}

.success-icon, .error-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.success-icon {
    background: #d1fae5;
    color: var(--ptp-success);
}

.error-icon {
    background: #fee2e2;
    color: var(--ptp-error);
}

.success-icon svg, .error-icon svg {
    width: 40px;
    height: 40px;
}

.thank-you-header h1 {
    margin: 0 0 10px;
    color: var(--ptp-black);
    font-size: 32px;
}

.thank-you-header .subtitle {
    color: var(--ptp-text-light);
    font-size: 18px;
    margin: 0;
}

.order-card {
    background: #fff;
    border: 2px solid var(--ptp-border);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 30px;
}

.order-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    background: var(--ptp-black);
    color: #fff;
}

.order-card-header h2 {
    margin: 0;
    font-size: 18px;
}

.order-number {
    font-family: monospace;
    background: rgba(255,255,255,0.2);
    padding: 4px 12px;
    border-radius: 4px;
}

.order-details {
    padding: 20px;
    border-bottom: 1px solid var(--ptp-border);
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
}

.detail-row .label {
    color: var(--ptp-text-light);
}

.detail-row .value {
    font-weight: 500;
    color: var(--ptp-black);
}

.detail-row .value.total {
    font-size: 20px;
    color: var(--ptp-success);
}

.campers-list {
    padding: 20px;
}

.campers-list h3 {
    margin: 0 0 15px;
    font-size: 16px;
    color: var(--ptp-text-light);
}

.camper-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: var(--ptp-gray);
    border-radius: 8px;
    margin-bottom: 10px;
}

.camper-avatar {
    width: 48px;
    height: 48px;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 20px;
}

.camper-info {
    flex: 1;
}

.camper-info strong {
    display: block;
    color: var(--ptp-black);
}

.camper-info .camp-name {
    display: block;
    font-size: 14px;
    color: var(--ptp-text);
}

.camper-info .camp-dates {
    display: block;
    font-size: 13px;
    color: var(--ptp-text-light);
}

.camper-price {
    font-weight: 600;
    color: var(--ptp-black);
}

.savings-banner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 15px;
    background: #d1fae5;
    color: #065f46;
    font-weight: 600;
}

/* Referral Card */
.referral-card {
    background: linear-gradient(135deg, var(--ptp-gold) 0%, #f59e0b 100%);
    border-radius: 12px;
    padding: 30px;
    text-align: center;
    margin-bottom: 30px;
}

.referral-icon {
    width: 60px;
    height: 60px;
    background: rgba(255,255,255,0.3);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
}

.referral-icon svg {
    width: 30px;
    height: 30px;
    color: var(--ptp-black);
}

.referral-card h3 {
    margin: 0 0 10px;
    color: var(--ptp-black);
    font-size: 24px;
}

.referral-card p {
    margin: 0 0 20px;
    color: rgba(0,0,0,0.7);
}

.referral-code-box {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #fff;
    padding: 10px 10px 10px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.referral-code-box .code {
    font-family: monospace;
    font-size: 24px;
    font-weight: 700;
    letter-spacing: 2px;
    color: var(--ptp-black);
}

.copy-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 8px 16px;
    background: var(--ptp-black);
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
}

.share-buttons {
    display: flex;
    justify-content: center;
    gap: 15px;
}

.share-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: rgba(0,0,0,0.2);
    color: var(--ptp-black);
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
    transition: background 0.2s;
}

.share-btn:hover {
    background: rgba(0,0,0,0.3);
}

/* What's Next */
.whats-next {
    background: #fff;
    border: 2px solid var(--ptp-border);
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
}

.whats-next h3 {
    margin: 0 0 20px;
    color: var(--ptp-black);
}

.next-steps {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.step {
    display: flex;
    gap: 15px;
}

.step-icon {
    width: 40px;
    height: 40px;
    background: var(--ptp-gray);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.step-icon svg {
    width: 20px;
    height: 20px;
    color: var(--ptp-success);
}

.step-content strong {
    display: block;
    color: var(--ptp-black);
    margin-bottom: 4px;
}

.step-content p {
    margin: 0;
    font-size: 14px;
    color: var(--ptp-text-light);
}

/* What to Bring */
.what-to-bring {
    background: #fffbeb;
    border: 2px solid var(--ptp-gold);
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
}

.what-to-bring h3 {
    margin: 0 0 20px;
    color: var(--ptp-black);
}

.items-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 15px;
    background: #fff;
    border-radius: 8px;
}

.item .emoji {
    font-size: 24px;
}

/* Actions */
.thank-you-actions, .error-actions {
    display: flex;
    justify-content: center;
    gap: 15px;
    margin-top: 30px;
}

.btn-primary, .btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 28px;
    border-radius: 8px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-primary {
    background: var(--ptp-gold);
    color: var(--ptp-black);
}

.btn-primary:hover {
    background: #e5a800;
}

.btn-secondary {
    background: #fff;
    color: var(--ptp-black);
    border: 2px solid var(--ptp-border);
}

.btn-secondary:hover {
    background: var(--ptp-gray);
}

/* Responsive */
@media (max-width: 600px) {
    .items-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .share-buttons {
        flex-direction: column;
    }
    
    .thank-you-actions, .error-actions {
        flex-direction: column;
    }
    
    .btn-primary, .btn-secondary {
        justify-content: center;
    }
}
</style>
