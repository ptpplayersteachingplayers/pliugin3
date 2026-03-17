<?php
/**
 * Membership Tiers Comparison Template
 * Three-tier pricing with anchor psychology
 */

defined('ABSPATH') || exit;

$pass = PTP_All_Access_Pass::instance();
$config = $pass->get_config();
$tiers = $config['tiers'];
$components = $config['components'];
?>

<div class="ptp-tiers" id="ptp-tiers">
    
    <!-- Hero -->
    <section class="ptp-tiers-hero">
        <div class="ptp-tiers-container">
            <h1 class="ptp-tiers-title">CHOOSE YOUR PATH</h1>
            <p class="ptp-tiers-subtitle">Every tier includes year-round access to our pro coaching network</p>
        </div>
    </section>
    
    <!-- Tiers Grid -->
    <section class="ptp-tiers-grid-section">
        <div class="ptp-tiers-container">
            <div class="ptp-tiers-grid">
                
                <?php foreach ($tiers as $tier_key => $tier): ?>
                <div class="ptp-tier-card <?php echo $tier['highlight'] ? 'ptp-tier-highlight' : ''; ?>" data-tier="<?php echo esc_attr($tier_key); ?>">
                    
                    <?php if (!empty($tier['badge'])): ?>
                    <span class="ptp-tier-badge"><?php echo esc_html($tier['badge']); ?></span>
                    <?php endif; ?>
                    
                    <h2 class="ptp-tier-name"><?php echo esc_html($tier['name']); ?></h2>
                    
                    <div class="ptp-tier-pricing">
                        <span class="ptp-tier-value">$<?php echo number_format($tier['value']); ?> value</span>
                        <span class="ptp-tier-price">$<?php echo number_format($tier['price']); ?></span>
                        <span class="ptp-tier-savings">Save <?php echo round((($tier['value'] - $tier['price']) / $tier['value']) * 100); ?>%</span>
                    </div>
                    
                    <ul class="ptp-tier-features">
                        <?php foreach ($tier['includes'] as $comp_key => $qty): ?>
                        <?php if ($qty > 0): ?>
                        <li class="ptp-tier-feature">
                            <span class="ptp-tier-feature-icon"><?php echo $components[$comp_key]['icon']; ?></span>
                            <span class="ptp-tier-feature-text">
                                <strong><?php echo $qty; ?></strong> <?php echo $components[$comp_key]['name']; ?>
                            </span>
                        </li>
                        <?php else: ?>
                        <li class="ptp-tier-feature ptp-tier-feature-disabled">
                            <span class="ptp-tier-feature-icon"><?php echo $components[$comp_key]['icon']; ?></span>
                            <span class="ptp-tier-feature-text"><?php echo $components[$comp_key]['name']; ?></span>
                        </li>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                    
                    <button class="ptp-btn <?php echo $tier['highlight'] ? 'ptp-btn-gold' : 'ptp-btn-black'; ?> ptp-tier-btn" 
                            data-tier="<?php echo esc_attr($tier_key); ?>">
                        <?php echo $tier['highlight'] ? 'GET STARTED' : 'SELECT'; ?>
                    </button>
                    
                </div>
                <?php endforeach; ?>
                
            </div>
        </div>
    </section>
    
    <!-- Comparison Table -->
    <section class="ptp-tiers-comparison">
        <div class="ptp-tiers-container">
            <h2 class="ptp-tiers-section-title">DETAILED COMPARISON</h2>
            
            <div class="ptp-comparison-table-wrap">
                <table class="ptp-comparison-table">
                    <thead>
                        <tr>
                            <th>INCLUDED</th>
                            <?php foreach ($tiers as $tier_key => $tier): ?>
                            <th class="<?php echo $tier['highlight'] ? 'ptp-col-highlight' : ''; ?>">
                                <?php echo esc_html($tier['name']); ?>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($components as $comp_key => $comp): ?>
                        <tr>
                            <td>
                                <span class="ptp-comp-icon"><?php echo $comp['icon']; ?></span>
                                <?php echo esc_html($comp['name']); ?>
                            </td>
                            <?php foreach ($tiers as $tier_key => $tier): ?>
                            <td class="<?php echo $tier['highlight'] ? 'ptp-col-highlight' : ''; ?>">
                                <?php if ($tier['includes'][$comp_key] > 0): ?>
                                    <strong><?php echo $tier['includes'][$comp_key]; ?></strong>
                                <?php else: ?>
                                    <span class="ptp-na">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="ptp-comparison-total">
                            <td><strong>TOTAL VALUE</strong></td>
                            <?php foreach ($tiers as $tier_key => $tier): ?>
                            <td class="<?php echo $tier['highlight'] ? 'ptp-col-highlight' : ''; ?>">
                                <strong>$<?php echo number_format($tier['value']); ?></strong>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="ptp-comparison-price">
                            <td><strong>YOUR PRICE</strong></td>
                            <?php foreach ($tiers as $tier_key => $tier): ?>
                            <td class="<?php echo $tier['highlight'] ? 'ptp-col-highlight' : ''; ?>">
                                <strong class="ptp-price-highlight">$<?php echo number_format($tier['price']); ?></strong>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    
    <!-- Final CTA -->
    <section class="ptp-tiers-cta">
        <div class="ptp-tiers-container">
            <h2 class="ptp-tiers-cta-title">READY TO COMMIT?</h2>
            <p class="ptp-tiers-cta-text">Most families choose All-Access for the complete development package</p>
            <a href="#ptp-tiers" class="ptp-btn ptp-btn-gold ptp-btn-xl">VIEW OPTIONS</a>
        </div>
    </section>
    
</div>

<style>
/* Tiers-specific styles */
.ptp-tiers-hero {
    background: var(--ptp-black);
    color: var(--ptp-white);
    padding: var(--ptp-space-16) var(--ptp-space-5);
    text-align: center;
}

.ptp-tiers-title {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-5xl);
    font-weight: 700;
    text-transform: uppercase;
    margin: 0 0 var(--ptp-space-3) 0;
}

.ptp-tiers-subtitle {
    color: var(--ptp-gray-300);
    margin: 0;
}

.ptp-tiers-container {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0 var(--ptp-space-5);
}

.ptp-tiers-grid-section {
    padding: var(--ptp-space-16) var(--ptp-space-5);
    background: var(--ptp-gray-100);
}

.ptp-tiers-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--ptp-space-6);
}

@media (min-width: 768px) {
    .ptp-tiers-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

.ptp-tier-card {
    position: relative;
    background: var(--ptp-white);
    border: var(--ptp-border-width) solid var(--ptp-black);
    padding: var(--ptp-space-8) var(--ptp-space-6);
    text-align: center;
}

.ptp-tier-highlight {
    border-color: var(--ptp-gold);
    box-shadow: 0 8px 30px rgba(252, 185, 0, 0.3);
    transform: scale(1.02);
}

.ptp-tier-badge {
    position: absolute;
    top: -14px;
    left: 50%;
    transform: translateX(-50%);
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-xs);
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    padding: var(--ptp-space-2) var(--ptp-space-4);
    white-space: nowrap;
}

.ptp-tier-name {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-2xl);
    font-weight: 700;
    text-transform: uppercase;
    margin: 0 0 var(--ptp-space-4) 0;
}

.ptp-tier-pricing {
    margin-bottom: var(--ptp-space-6);
}

.ptp-tier-value {
    display: block;
    font-size: var(--ptp-text-sm);
    color: var(--ptp-gray-500);
    text-decoration: line-through;
    margin-bottom: var(--ptp-space-1);
}

.ptp-tier-price {
    display: block;
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-5xl);
    font-weight: 700;
    line-height: 1;
    margin-bottom: var(--ptp-space-2);
}

.ptp-tier-highlight .ptp-tier-price {
    color: var(--ptp-gold-hover);
}

.ptp-tier-savings {
    display: inline-block;
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-xs);
    font-weight: 600;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    padding: var(--ptp-space-1) var(--ptp-space-3);
}

.ptp-tier-features {
    list-style: none;
    padding: 0;
    margin: 0 0 var(--ptp-space-6) 0;
    text-align: left;
}

.ptp-tier-feature {
    display: flex;
    align-items: center;
    gap: var(--ptp-space-3);
    padding: var(--ptp-space-3) 0;
    border-bottom: 1px solid var(--ptp-gray-200);
}

.ptp-tier-feature:last-child {
    border-bottom: none;
}

.ptp-tier-feature-disabled {
    opacity: 0.4;
}

.ptp-tier-feature-icon {
    font-size: 20px;
}

.ptp-tier-feature-text strong {
    color: var(--ptp-gold-hover);
}

.ptp-tier-btn {
    width: 100%;
}

/* Comparison Table */
.ptp-tiers-comparison {
    padding: var(--ptp-space-16) var(--ptp-space-5);
    background: var(--ptp-white);
}

.ptp-tiers-section-title {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-3xl);
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
    margin: 0 0 var(--ptp-space-8) 0;
}

.ptp-comparison-table-wrap {
    overflow-x: auto;
}

.ptp-comparison-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--ptp-text-sm);
}

.ptp-comparison-table th,
.ptp-comparison-table td {
    padding: var(--ptp-space-4);
    border: 1px solid var(--ptp-gray-200);
    text-align: center;
}

.ptp-comparison-table th {
    font-family: var(--ptp-font-display);
    font-weight: 600;
    text-transform: uppercase;
    background: var(--ptp-gray-100);
}

.ptp-comparison-table td:first-child {
    text-align: left;
    font-weight: 500;
}

.ptp-col-highlight {
    background: rgba(252, 185, 0, 0.1);
}

.ptp-comparison-table th.ptp-col-highlight {
    background: var(--ptp-gold);
    color: var(--ptp-black);
}

.ptp-comp-icon {
    margin-right: var(--ptp-space-2);
}

.ptp-na {
    color: var(--ptp-gray-400);
}

.ptp-comparison-total,
.ptp-comparison-price {
    background: var(--ptp-gray-50);
}

.ptp-price-highlight {
    color: var(--ptp-gold-hover);
    font-size: var(--ptp-text-lg);
}

/* CTA */
.ptp-tiers-cta {
    padding: var(--ptp-space-16) var(--ptp-space-5);
    background: var(--ptp-black);
    text-align: center;
}

.ptp-tiers-cta-title {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-3xl);
    font-weight: 700;
    text-transform: uppercase;
    color: var(--ptp-white);
    margin: 0 0 var(--ptp-space-3) 0;
}

.ptp-tiers-cta-text {
    color: var(--ptp-gray-300);
    margin: 0 0 var(--ptp-space-8) 0;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.ptp-tier-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const tier = this.dataset.tier;
            window.location.href = '/all-access-pass/?tier=' + tier + '#ptp-pricing';
        });
    });
});
</script>
