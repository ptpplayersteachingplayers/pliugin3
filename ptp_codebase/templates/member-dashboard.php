<?php
/**
 * Member Dashboard Template
 * Shows membership status, credits, and usage
 */

defined('ABSPATH') || exit;

// $membership and $credits passed from shortcode
$pass = PTP_All_Access_Pass::instance();
$config = $pass->get_config();
$components = $config['components'];
$tier_config = $membership ? ($config['tiers'][$membership->tier] ?? $config['tiers']['commit']) : null;
?>

<div class="ptp-member-dash">
    
    <?php if (!$membership): ?>
    <!-- No Membership -->
    <section class="ptp-md-no-membership">
        <div class="ptp-md-container">
            <div class="ptp-md-empty">
                <span class="ptp-md-empty-icon">🎯</span>
                <h2>No Active Membership</h2>
                <p>Get year-round access to camps, private training, clinics, and more.</p>
                <a href="/all-access-pass/" class="ptp-btn ptp-btn-gold ptp-btn-lg">VIEW ALL-ACCESS PASS</a>
            </div>
        </div>
    </section>
    
    <?php else: ?>
    <!-- Active Membership -->
    <section class="ptp-md-header">
        <div class="ptp-md-container">
            <div class="ptp-md-status">
                <span class="ptp-md-badge">ACTIVE MEMBER</span>
                <h1 class="ptp-md-tier"><?php echo esc_html($tier_config['name']); ?></h1>
                <p class="ptp-md-expires">Valid through <?php echo date('F j, Y', strtotime($membership->expires_at)); ?></p>
            </div>
        </div>
    </section>
    
    <!-- Credits Overview -->
    <section class="ptp-md-credits">
        <div class="ptp-md-container">
            <h2 class="ptp-md-section-title">YOUR CREDITS</h2>
            
            <div class="ptp-md-credits-grid">
                <?php foreach ($components as $key => $component): ?>
                <?php 
                $credit = $credits[$key] ?? null;
                $total = $credit ? $credit->credits_total : $tier_config['includes'][$key];
                $remaining = $credit ? $credit->credits_remaining : $total;
                $used = $credit ? $credit->credits_used : 0;
                $percent = $total > 0 ? ($remaining / $total) * 100 : 0;
                ?>
                <div class="ptp-md-credit-card">
                    <div class="ptp-md-credit-header">
                        <span class="ptp-md-credit-icon"><?php echo $component['icon']; ?></span>
                        <span class="ptp-md-credit-name"><?php echo esc_html($component['name']); ?></span>
                    </div>
                    <div class="ptp-md-credit-count">
                        <span class="ptp-md-credit-remaining"><?php echo $remaining; ?></span>
                        <span class="ptp-md-credit-total">/ <?php echo $total; ?> remaining</span>
                    </div>
                    <div class="ptp-md-credit-bar">
                        <div class="ptp-md-credit-fill" style="width: <?php echo $percent; ?>%;"></div>
                    </div>
                    <?php if ($remaining > 0): ?>
                    <a href="<?php echo $key === 'camps' ? '/ptp-find-a-camp/' : ($key === 'private' ? '/find-trainers/' : '/clinics/'); ?>" 
                       class="ptp-md-credit-action">
                        <?php echo $key === 'video' || $key === 'mentorship' ? 'SCHEDULE' : 'BOOK NOW'; ?>
                    </a>
                    <?php else: ?>
                    <span class="ptp-md-credit-depleted">All used!</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    
    <!-- Quick Actions -->
    <section class="ptp-md-actions">
        <div class="ptp-md-container">
            <h2 class="ptp-md-section-title">QUICK ACTIONS</h2>
            
            <div class="ptp-md-actions-grid">
                <a href="/ptp-find-a-camp/" class="ptp-md-action-card">
                    <span class="ptp-md-action-icon">⚽</span>
                    <span class="ptp-md-action-label">Browse Camps</span>
                </a>
                <a href="/find-trainers/" class="ptp-md-action-card">
                    <span class="ptp-md-action-icon">🎯</span>
                    <span class="ptp-md-action-label">Book Training</span>
                </a>
                <a href="/clinics/" class="ptp-md-action-card">
                    <span class="ptp-md-action-icon">🔥</span>
                    <span class="ptp-md-action-label">View Clinics</span>
                </a>
                <a href="/account/" class="ptp-md-action-card">
                    <span class="ptp-md-action-icon">⚙️</span>
                    <span class="ptp-md-action-label">Account Settings</span>
                </a>
            </div>
        </div>
    </section>
    
    <!-- Membership Details -->
    <section class="ptp-md-details">
        <div class="ptp-md-container">
            <h2 class="ptp-md-section-title">MEMBERSHIP DETAILS</h2>
            
            <div class="ptp-md-details-card">
                <div class="ptp-md-detail-row">
                    <span class="ptp-md-detail-label">Plan</span>
                    <span class="ptp-md-detail-value"><?php echo esc_html($tier_config['name']); ?></span>
                </div>
                <div class="ptp-md-detail-row">
                    <span class="ptp-md-detail-label">Status</span>
                    <span class="ptp-md-detail-value ptp-md-status-active">Active</span>
                </div>
                <div class="ptp-md-detail-row">
                    <span class="ptp-md-detail-label">Started</span>
                    <span class="ptp-md-detail-value"><?php echo date('F j, Y', strtotime($membership->starts_at)); ?></span>
                </div>
                <div class="ptp-md-detail-row">
                    <span class="ptp-md-detail-label">Expires</span>
                    <span class="ptp-md-detail-value"><?php echo date('F j, Y', strtotime($membership->expires_at)); ?></span>
                </div>
                <div class="ptp-md-detail-row">
                    <span class="ptp-md-detail-label">Payment Plan</span>
                    <span class="ptp-md-detail-value"><?php echo ucfirst($membership->payment_plan); ?></span>
                </div>
                <?php if ($membership->payment_plan !== 'full' && $membership->payments_made < $membership->payments_total): ?>
                <div class="ptp-md-detail-row">
                    <span class="ptp-md-detail-label">Payments Made</span>
                    <span class="ptp-md-detail-value"><?php echo $membership->payments_made; ?> / <?php echo $membership->payments_total; ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="ptp-md-upgrade">
                <p>Need more credits? <a href="/membership-tiers/">Upgrade your plan</a></p>
            </div>
        </div>
    </section>
    <?php endif; ?>
    
</div>

<style>
.ptp-member-dash {
    font-family: var(--ptp-font-body);
    min-height: 60vh;
}

.ptp-md-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 0 var(--ptp-space-5);
}

/* No Membership */
.ptp-md-no-membership {
    padding: var(--ptp-space-20) var(--ptp-space-5);
    background: var(--ptp-gray-100);
}

.ptp-md-empty {
    text-align: center;
    max-width: 400px;
    margin: 0 auto;
}

.ptp-md-empty-icon {
    font-size: 64px;
    display: block;
    margin-bottom: var(--ptp-space-4);
}

.ptp-md-empty h2 {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-2xl);
    text-transform: uppercase;
    margin: 0 0 var(--ptp-space-3) 0;
}

.ptp-md-empty p {
    color: var(--ptp-gray-600);
    margin: 0 0 var(--ptp-space-6) 0;
}

/* Header */
.ptp-md-header {
    background: var(--ptp-black);
    color: var(--ptp-white);
    padding: var(--ptp-space-12) var(--ptp-space-5);
    text-align: center;
}

.ptp-md-badge {
    display: inline-block;
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-xs);
    font-weight: 600;
    letter-spacing: 0.1em;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    padding: var(--ptp-space-2) var(--ptp-space-4);
    margin-bottom: var(--ptp-space-4);
}

.ptp-md-tier {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-5xl);
    font-weight: 700;
    text-transform: uppercase;
    margin: 0 0 var(--ptp-space-2) 0;
}

.ptp-md-expires {
    color: var(--ptp-gray-300);
    margin: 0;
}

/* Section Title */
.ptp-md-section-title {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-2xl);
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
    margin: 0 0 var(--ptp-space-8) 0;
}

/* Credits */
.ptp-md-credits {
    padding: var(--ptp-space-12) var(--ptp-space-5);
    background: var(--ptp-white);
}

.ptp-md-credits-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--ptp-space-4);
}

@media (min-width: 600px) {
    .ptp-md-credits-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 900px) {
    .ptp-md-credits-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

.ptp-md-credit-card {
    background: var(--ptp-white);
    border: var(--ptp-border-width) solid var(--ptp-black);
    padding: var(--ptp-space-5);
}

.ptp-md-credit-header {
    display: flex;
    align-items: center;
    gap: var(--ptp-space-2);
    margin-bottom: var(--ptp-space-3);
}

.ptp-md-credit-icon {
    font-size: 24px;
}

.ptp-md-credit-name {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-sm);
    font-weight: 600;
    text-transform: uppercase;
}

.ptp-md-credit-count {
    margin-bottom: var(--ptp-space-3);
}

.ptp-md-credit-remaining {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-4xl);
    font-weight: 700;
    color: var(--ptp-gold-hover);
}

.ptp-md-credit-total {
    font-size: var(--ptp-text-sm);
    color: var(--ptp-gray-500);
}

.ptp-md-credit-bar {
    height: 8px;
    background: var(--ptp-gray-200);
    margin-bottom: var(--ptp-space-4);
}

.ptp-md-credit-fill {
    height: 100%;
    background: var(--ptp-gold);
    transition: width 0.3s ease;
}

.ptp-md-credit-action {
    display: block;
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-sm);
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    text-align: center;
    text-decoration: none;
    background: var(--ptp-black);
    color: var(--ptp-white);
    padding: var(--ptp-space-3);
    transition: background 0.2s;
}

.ptp-md-credit-action:hover {
    background: var(--ptp-gold);
    color: var(--ptp-black);
}

.ptp-md-credit-depleted {
    display: block;
    font-size: var(--ptp-text-sm);
    color: var(--ptp-gray-500);
    text-align: center;
    padding: var(--ptp-space-3);
}

/* Actions */
.ptp-md-actions {
    padding: var(--ptp-space-12) var(--ptp-space-5);
    background: var(--ptp-gray-100);
}

.ptp-md-actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--ptp-space-4);
}

@media (min-width: 600px) {
    .ptp-md-actions-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

.ptp-md-action-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--ptp-space-2);
    background: var(--ptp-white);
    border: var(--ptp-border-width) solid var(--ptp-black);
    padding: var(--ptp-space-6) var(--ptp-space-4);
    text-decoration: none;
    color: var(--ptp-black);
    transition: all 0.2s;
}

.ptp-md-action-card:hover {
    box-shadow: 4px 4px 0 var(--ptp-black);
    transform: translate(-2px, -2px);
}

.ptp-md-action-icon {
    font-size: 32px;
}

.ptp-md-action-label {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-sm);
    font-weight: 600;
    text-transform: uppercase;
}

/* Details */
.ptp-md-details {
    padding: var(--ptp-space-12) var(--ptp-space-5);
    background: var(--ptp-white);
}

.ptp-md-details-card {
    background: var(--ptp-gray-50);
    border: var(--ptp-border-width) solid var(--ptp-gray-200);
    max-width: 500px;
    margin: 0 auto;
}

.ptp-md-detail-row {
    display: flex;
    justify-content: space-between;
    padding: var(--ptp-space-4) var(--ptp-space-5);
    border-bottom: 1px solid var(--ptp-gray-200);
}

.ptp-md-detail-row:last-child {
    border-bottom: none;
}

.ptp-md-detail-label {
    color: var(--ptp-gray-600);
}

.ptp-md-detail-value {
    font-weight: 600;
}

.ptp-md-status-active {
    color: var(--ptp-success);
}

.ptp-md-upgrade {
    text-align: center;
    margin-top: var(--ptp-space-6);
}

.ptp-md-upgrade a {
    color: var(--ptp-gold-hover);
    font-weight: 600;
}
</style>
