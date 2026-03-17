<?php
/**
 * PTP All-Access Pass Landing Page
 * 
 * Shows full value breakdown and purchase option
 * URL: /all-access/
 * 
 * @since 100.0.0
 */

defined('ABSPATH') || exit;

get_header();

// Pricing
$aa_price = 4000;
$aa_value = 5930;
$aa_save = $aa_value - $aa_price;
$save_pct = round(($aa_save / $aa_value) * 100);

// Line items
$items = [
    ['icon' => '⚽', 'name' => '6 Summer Camps', 'unit' => '$525 each', 'value' => 3150],
    ['icon' => '🎯', 'name' => '12 Private Sessions', 'unit' => '$100 each', 'value' => 1200],
    ['icon' => '🔥', 'name' => '6 Skills Clinics', 'unit' => '$130 each', 'value' => 780],
    ['icon' => '📹', 'name' => '4 Video Analysis Hours', 'unit' => '$100 each', 'value' => 400],
    ['icon' => '💪', 'name' => '4 Mentorship Hours', 'unit' => '$100 each', 'value' => 400],
];

// Get PTP Native product
$aa_product_id = get_option('ptp_all_access_product_id', 0);
$product = $aa_product_id ? ptp_get_camp_product($aa_product_id) : null;

// Build add to cart URL
if ($product) {
    $add_to_cart_url = add_query_arg('add-to-cart', $aa_product_id, home_url('/ptp-checkout/'));
} else {
    $add_to_cart_url = home_url('/contact/');
}
?>

<style>
/* ==========================================
   ALL-ACCESS PASS LANDING PAGE
   ========================================== */

:root {
    --gold: #FCB900;
    --black: #0A0A0A;
    --green: #22c55e;
    --gray: #666;
    --gray-lt: #f5f5f5;
}

.aa-page {
    font-family: 'Inter', -apple-system, sans-serif;
    background: var(--black);
    min-height: 100vh;
    color: #fff;
}

.aa-hero {
    text-align: center;
    padding: 60px 20px 40px;
    background: linear-gradient(180deg, #1a1a1a 0%, var(--black) 100%);
}

.aa-badge {
    display: inline-block;
    background: var(--gold);
    color: var(--black);
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    padding: 8px 20px;
    margin-bottom: 20px;
}

.aa-title {
    font-family: 'Oswald', sans-serif;
    font-size: clamp(42px, 10vw, 72px);
    font-weight: 700;
    text-transform: uppercase;
    line-height: 1;
    margin: 0 0 10px;
}

.aa-title em {
    color: var(--gold);
    font-style: normal;
}

.aa-subtitle {
    font-size: 18px;
    color: #999;
    margin: 0 0 30px;
}

.aa-price-hero {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    flex-wrap: wrap;
}

.aa-price-was {
    font-family: 'Oswald', sans-serif;
    font-size: 32px;
    color: #666;
    text-decoration: line-through;
}

.aa-price-now {
    font-family: 'Oswald', sans-serif;
    font-size: 56px;
    font-weight: 700;
    color: var(--gold);
}

.aa-save-badge {
    background: var(--green);
    color: #fff;
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 700;
    padding: 8px 16px;
    text-transform: uppercase;
}

/* Value Breakdown */
.aa-breakdown {
    max-width: 700px;
    margin: 0 auto;
    padding: 40px 20px;
}

.aa-section-title {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--gold);
    text-align: center;
    margin-bottom: 30px;
}

.aa-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px 0;
    border-bottom: 1px solid #333;
}

.aa-item:last-child {
    border-bottom: none;
}

.aa-item-icon {
    font-size: 28px;
    width: 50px;
    text-align: center;
}

.aa-item-info {
    flex: 1;
}

.aa-item-name {
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.aa-item-unit {
    font-size: 13px;
    color: #888;
}

.aa-item-value {
    font-family: 'Oswald', sans-serif;
    font-size: 22px;
    font-weight: 700;
    color: #fff;
    text-align: right;
}

/* Totals */
.aa-totals {
    background: #1a1a1a;
    border: 2px solid #333;
    padding: 24px;
    margin-top: 30px;
}

.aa-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
}

.aa-total-row.value {
    border-bottom: 1px solid #333;
}

.aa-total-label {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #888;
}

.aa-total-amount {
    font-family: 'Oswald', sans-serif;
    font-size: 24px;
    font-weight: 700;
}

.aa-total-row.price .aa-total-label {
    color: var(--gold);
    font-size: 16px;
}

.aa-total-row.price .aa-total-amount {
    color: var(--gold);
    font-size: 36px;
}

.aa-total-row.save {
    background: var(--green);
    margin: 16px -24px -24px;
    padding: 16px 24px;
}

.aa-total-row.save .aa-total-label,
.aa-total-row.save .aa-total-amount {
    color: #fff;
}

/* What's Included */
.aa-includes {
    background: #111;
    padding: 60px 20px;
}

.aa-includes-grid {
    max-width: 900px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.aa-include-card {
    background: #1a1a1a;
    border: 2px solid #333;
    padding: 24px;
    text-align: center;
    transition: border-color 0.2s;
}

.aa-include-card:hover {
    border-color: var(--gold);
}

.aa-include-icon {
    font-size: 40px;
    margin-bottom: 12px;
}

.aa-include-title {
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.aa-include-qty {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    color: var(--gold);
    margin-bottom: 8px;
}

.aa-include-desc {
    font-size: 13px;
    color: #888;
    line-height: 1.5;
}

/* CTA Section */
.aa-cta {
    text-align: center;
    padding: 60px 20px;
    background: linear-gradient(180deg, var(--black) 0%, #1a1a1a 100%);
}

.aa-cta-title {
    font-family: 'Oswald', sans-serif;
    font-size: 28px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 10px;
}

.aa-cta-sub {
    color: #888;
    margin-bottom: 30px;
}

.aa-cta-btn {
    display: inline-block;
    background: var(--gold);
    color: var(--black);
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 18px 50px;
    text-decoration: none;
    transition: all 0.2s;
}

.aa-cta-btn:hover {
    background: #fff;
    color: var(--black);
    transform: translateY(-2px);
}

.aa-cta-price {
    margin-top: 16px;
    font-size: 14px;
    color: #666;
}

.aa-cta-price span {
    color: var(--gold);
    font-weight: 600;
}

/* Comparison */
.aa-compare {
    max-width: 800px;
    margin: 0 auto;
    padding: 40px 20px 60px;
}

.aa-compare-table {
    width: 100%;
    border-collapse: collapse;
}

.aa-compare-table th,
.aa-compare-table td {
    padding: 16px;
    text-align: center;
    border-bottom: 1px solid #333;
}

.aa-compare-table th {
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #888;
}

.aa-compare-table th:first-child {
    text-align: left;
}

.aa-compare-table td:first-child {
    text-align: left;
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    text-transform: uppercase;
}

.aa-compare-table .highlight {
    background: rgba(252, 185, 0, 0.1);
}

.aa-compare-table .highlight th {
    color: var(--gold);
}

.aa-check {
    color: var(--green);
    font-size: 18px;
}

.aa-x {
    color: #666;
    font-size: 14px;
}

/* FAQ */
.aa-faq {
    max-width: 700px;
    margin: 0 auto;
    padding: 40px 20px 80px;
}

.aa-faq-item {
    border-bottom: 1px solid #333;
    padding: 20px 0;
}

.aa-faq-q {
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 8px;
    color: var(--gold);
}

.aa-faq-a {
    font-size: 14px;
    color: #999;
    line-height: 1.6;
}

/* Mobile */
@media (max-width: 600px) {
    .aa-hero {
        padding: 40px 16px 30px;
    }
    
    .aa-price-hero {
        flex-direction: column;
        gap: 10px;
    }
    
    .aa-price-was {
        font-size: 24px;
    }
    
    .aa-price-now {
        font-size: 44px;
    }
    
    .aa-item {
        flex-wrap: wrap;
    }
    
    .aa-item-value {
        width: 100%;
        text-align: left;
        margin-top: 8px;
        padding-left: 66px;
    }
    
    .aa-compare-table {
        font-size: 12px;
    }
    
    .aa-compare-table th,
    .aa-compare-table td {
        padding: 10px 6px;
    }
}
</style>

<div class="aa-page">
    
    <!-- HERO -->
    <section class="aa-hero">
        <div class="aa-badge">⭐ Best Value for Serious Players</div>
        <h1 class="aa-title">ALL-ACCESS <em>PASS</em></h1>
        <p class="aa-subtitle">The complete PTP experience — camps, training, mentorship</p>
        
        <div class="aa-price-hero">
            <span class="aa-price-was">$<?php echo number_format($aa_value); ?></span>
            <span class="aa-price-now">$<?php echo number_format($aa_price); ?></span>
            <span class="aa-save-badge">Save $<?php echo number_format($aa_save); ?></span>
        </div>
    </section>
    
    <!-- VALUE BREAKDOWN -->
    <section class="aa-breakdown">
        <h2 class="aa-section-title">What's Included</h2>
        
        <?php foreach ($items as $item): ?>
        <div class="aa-item">
            <span class="aa-item-icon"><?php echo $item['icon']; ?></span>
            <div class="aa-item-info">
                <div class="aa-item-name"><?php echo esc_html($item['name']); ?></div>
                <div class="aa-item-unit"><?php echo esc_html($item['unit']); ?></div>
            </div>
            <div class="aa-item-value">$<?php echo number_format($item['value']); ?></div>
        </div>
        <?php endforeach; ?>
        
        <!-- TOTALS BOX -->
        <div class="aa-totals">
            <div class="aa-total-row value">
                <span class="aa-total-label">Total Value</span>
                <span class="aa-total-amount">$<?php echo number_format($aa_value); ?></span>
            </div>
            <div class="aa-total-row price">
                <span class="aa-total-label">Your Price</span>
                <span class="aa-total-amount">$<?php echo number_format($aa_price); ?></span>
            </div>
            <div class="aa-total-row save">
                <span class="aa-total-label">You Save</span>
                <span class="aa-total-amount">$<?php echo number_format($aa_save); ?> (<?php echo $save_pct; ?>% OFF)</span>
            </div>
        </div>
    </section>
    
    <!-- WHAT'S INCLUDED CARDS -->
    <section class="aa-includes">
        <h2 class="aa-section-title">Year-Round Development</h2>
        
        <div class="aa-includes-grid">
            <div class="aa-include-card">
                <div class="aa-include-icon">⚽</div>
                <div class="aa-include-title">Summer Camps</div>
                <div class="aa-include-qty">6 Weeks Included</div>
                <div class="aa-include-desc">Full-day camps with D1 college coaches. Pick any 6 weeks all summer.</div>
            </div>
            
            <div class="aa-include-card">
                <div class="aa-include-icon">🎯</div>
                <div class="aa-include-title">Private Training</div>
                <div class="aa-include-qty">12 Sessions Included</div>
                <div class="aa-include-desc">1-on-1 sessions with your dedicated coach. Focus on your specific needs.</div>
            </div>
            
            <div class="aa-include-card">
                <div class="aa-include-icon">🔥</div>
                <div class="aa-include-title">Skills Clinics</div>
                <div class="aa-include-qty">6 Clinics Included</div>
                <div class="aa-include-desc">Specialized sessions: finishing, defending, ball mastery, speed & agility.</div>
            </div>
            
            <div class="aa-include-card">
                <div class="aa-include-icon">📹</div>
                <div class="aa-include-title">Video Analysis</div>
                <div class="aa-include-qty">4 Hours Included</div>
                <div class="aa-include-desc">Break down your game film with pro-level feedback and action plans.</div>
            </div>
            
            <div class="aa-include-card">
                <div class="aa-include-icon">💪</div>
                <div class="aa-include-title">Mentorship</div>
                <div class="aa-include-qty">4 Hours Included</div>
                <div class="aa-include-desc">Career guidance from players who've been there. College prep, mindset, nutrition.</div>
            </div>
            
            <div class="aa-include-card">
                <div class="aa-include-icon">🎫</div>
                <div class="aa-include-title">Priority Access</div>
                <div class="aa-include-qty">All Year</div>
                <div class="aa-include-desc">Early registration for camps, first pick of time slots, VIP event access.</div>
            </div>
        </div>
    </section>
    
    <!-- COMPARISON TABLE -->
    <section class="aa-compare">
        <h2 class="aa-section-title">Compare Options</h2>
        
        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
        <table class="aa-compare-table">
            <thead>
                <tr>
                    <th>Feature</th>
                    <th>Single Camp</th>
                    <th>3-Camp Pack</th>
                    <th class="highlight">All-Access</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Summer Camps</td>
                    <td>1</td>
                    <td>3</td>
                    <td class="highlight"><strong>6</strong></td>
                </tr>
                <tr>
                    <td>Private Sessions</td>
                    <td class="aa-x">—</td>
                    <td class="aa-x">—</td>
                    <td class="highlight"><strong>12</strong></td>
                </tr>
                <tr>
                    <td>Skills Clinics</td>
                    <td class="aa-x">—</td>
                    <td class="aa-x">—</td>
                    <td class="highlight"><strong>6</strong></td>
                </tr>
                <tr>
                    <td>Video Analysis</td>
                    <td class="aa-x">—</td>
                    <td class="aa-x">—</td>
                    <td class="highlight"><strong>4 hrs</strong></td>
                </tr>
                <tr>
                    <td>Mentorship</td>
                    <td class="aa-x">—</td>
                    <td class="aa-x">—</td>
                    <td class="highlight"><strong>4 hrs</strong></td>
                </tr>
                <tr>
                    <td>Priority Booking</td>
                    <td class="aa-x">—</td>
                    <td class="aa-x">—</td>
                    <td class="highlight"><span class="aa-check">✓</span></td>
                </tr>
                <tr>
                    <td>Price</td>
                    <td>$525</td>
                    <td>$1,260</td>
                    <td class="highlight"><strong style="color: var(--gold);">$4,000</strong></td>
                </tr>
                <tr>
                    <td>Savings</td>
                    <td>—</td>
                    <td>$315 (20%)</td>
                    <td class="highlight"><strong style="color: var(--green);">$1,930 (33%)</strong></td>
                </tr>
            </tbody>
        </table>
        </div>
    </section>
    
    <!-- FAQ -->
    <section class="aa-faq">
        <h2 class="aa-section-title">Questions</h2>
        
        <div class="aa-faq-item">
            <div class="aa-faq-q">When can I use my sessions?</div>
            <div class="aa-faq-a">Your pass is valid for 12 months from purchase. Use your camps during summer (June-August) and schedule private training, clinics, and mentorship year-round.</div>
        </div>
        
        <div class="aa-faq-item">
            <div class="aa-faq-q">Can I share with siblings?</div>
            <div class="aa-faq-a">The pass is for one player. However, camps and clinics can be transferred to a sibling if schedules conflict. Private sessions are tied to the registered player.</div>
        </div>
        
        <div class="aa-faq-item">
            <div class="aa-faq-q">What if I can't use all 6 camps?</div>
            <div class="aa-faq-a">Unused camp weeks can be converted to additional private sessions or carried over to the following summer (one-time rollover).</div>
        </div>
        
        <div class="aa-faq-item">
            <div class="aa-faq-q">Is there a payment plan?</div>
            <div class="aa-faq-a">Yes! We offer 3-month and 6-month payment plans through Affirm at checkout. Spread $4,000 into manageable monthly payments.</div>
        </div>
        
        <div class="aa-faq-item">
            <div class="aa-faq-q">Who are the coaches?</div>
            <div class="aa-faq-a">All PTP coaches are current NCAA Division 1 athletes and elite college players. They don't just coach — they play alongside your child.</div>
        </div>
    </section>
    
    <!-- CTA -->
    <section class="aa-cta">
        <h2 class="aa-cta-title">Ready to Go All-Access?</h2>
        <p class="aa-cta-sub">Join 200+ families who chose the complete PTP experience</p>
        
        <a href="<?php echo esc_url($add_to_cart_url); ?>" class="aa-cta-btn">
            GET ALL-ACCESS PASS →
        </a>
        
        <p class="aa-cta-price">
            <span>$<?php echo number_format($aa_price); ?></span> — Save $<?php echo number_format($aa_save); ?> (<?php echo $save_pct; ?>% off)
        </p>
    </section>
    
</div>

<?php get_footer(); ?>
