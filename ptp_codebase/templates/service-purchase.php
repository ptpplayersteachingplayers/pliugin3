<?php
/**
 * Individual Service Purchase Template
 * For Video Analysis and Mentorship calls
 */

defined('ABSPATH') || exit;

// $service is passed from the shortcode
$pass = PTP_All_Access_Pass::instance();
$config = $pass->get_config();
$membership = $config['membership'];

// Determine service type from shortcode or URL
$service_type = $service['slug'] ?? 'video-analysis';
$service_key = strpos($service_type, 'video') !== false ? 'video' : 'mentorship';
$service = $config['components'][$service_key];
?>

<div class="ptp-service-page">
    
    <section class="ptp-service-hero">
        <div class="ptp-service-container">
            <div class="ptp-service-card">
                <div class="ptp-service-icon"><?php echo $service['icon']; ?></div>
                <h1 class="ptp-service-title"><?php echo esc_html($service['name']); ?></h1>
                <p class="ptp-service-desc"><?php echo esc_html($service['description']); ?></p>
                
                <div class="ptp-service-price-block">
                    <span class="ptp-service-price">$<?php echo $service['purchase_price']; ?></span>
                    <span class="ptp-service-per">per hour</span>
                </div>
                
                <div class="ptp-service-qty-selector">
                    <label class="ptp-service-qty-label">HOURS</label>
                    <div class="ptp-service-qty-controls">
                        <button class="ptp-qty-btn ptp-qty-minus">−</button>
                        <input type="number" id="ptp-service-qty" value="1" min="1" max="10" class="ptp-qty-input">
                        <button class="ptp-qty-btn ptp-qty-plus">+</button>
                    </div>
                    <span class="ptp-service-total">Total: $<span id="ptp-service-total"><?php echo $service['purchase_price']; ?></span></span>
                </div>
                
                <button class="ptp-btn ptp-btn-gold ptp-btn-lg ptp-btn-full ptp-service-buy-btn" 
                        data-service="<?php echo esc_attr($service_key); ?>" 
                        data-price="<?php echo $service['purchase_price']; ?>">
                    BOOK NOW
                </button>
                
                <div class="ptp-service-upsell">
                    <p>Want more value?</p>
                    <p><strong><?php echo $service['quantity']; ?> <?php echo $service['name']; ?></strong> included in the <a href="/all-access-pass/">All-Access Pass</a></p>
                    <span class="ptp-upsell-savings">Save <?php echo round((($service['value'] - ($service['quantity'] * $service['purchase_price'] * 0.67)) / $service['value']) * 100); ?>%+ with membership</span>
                </div>
            </div>
        </div>
    </section>
    
    <!-- What's Included -->
    <section class="ptp-service-details">
        <div class="ptp-service-container">
            <h2 class="ptp-service-section-title">WHAT YOU GET</h2>
            
            <div class="ptp-service-features">
                <?php if ($service_key === 'video'): ?>
                <div class="ptp-feature-item">
                    <span class="ptp-feature-icon">🎬</span>
                    <h3>Game Film Breakdown</h3>
                    <p>Send us your game footage and get detailed analysis from coaches who've played at the highest levels.</p>
                </div>
                <div class="ptp-feature-item">
                    <span class="ptp-feature-icon">📊</span>
                    <h3>Tactical Insights</h3>
                    <p>Positioning, movement patterns, decision-making—we break down what's working and what to improve.</p>
                </div>
                <div class="ptp-feature-item">
                    <span class="ptp-feature-icon">📝</span>
                    <h3>Action Plan</h3>
                    <p>Walk away with specific drills and focus areas for your next training session.</p>
                </div>
                <?php else: ?>
                <div class="ptp-feature-item">
                    <span class="ptp-feature-icon">🎯</span>
                    <h3>Career Guidance</h3>
                    <p>Navigate the path from youth soccer to college recruitment with coaches who've done it.</p>
                </div>
                <div class="ptp-feature-item">
                    <span class="ptp-feature-icon">🧠</span>
                    <h3>Mental Performance</h3>
                    <p>Build confidence, handle pressure, and develop the mindset of elite players.</p>
                </div>
                <div class="ptp-feature-item">
                    <span class="ptp-feature-icon">💬</span>
                    <h3>1-on-1 Attention</h3>
                    <p>Direct access to a pro coach focused entirely on your development and goals.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    
    <!-- CTA -->
    <section class="ptp-service-cta">
        <div class="ptp-service-container">
            <h2>WANT THE COMPLETE PACKAGE?</h2>
            <p>The All-Access Pass includes <?php echo $service['quantity']; ?> <?php echo $service['name']; ?> plus camps, private training, and unlimited clinics.</p>
            <a href="/all-access-pass/" class="ptp-btn ptp-btn-gold ptp-btn-lg">VIEW ALL-ACCESS PASS</a>
        </div>
    </section>
    
</div>

<!-- Checkout Modal -->
<div class="ptp-aap-modal" id="ptp-checkout-modal" style="display:none;">
    <div class="ptp-aap-modal-overlay"></div>
    <div class="ptp-aap-modal-content">
        <button class="ptp-aap-modal-close">&times;</button>
        <div class="ptp-aap-modal-body">
            <h2 class="ptp-aap-modal-title">Complete Your Booking</h2>
            <div id="ptp-checkout-form">
                <div class="ptp-aap-form-group">
                    <label class="ptp-aap-label">EMAIL</label>
                    <input type="email" id="ptp-checkout-email" class="ptp-aap-input" placeholder="your@email.com" required>
                </div>
                <div class="ptp-aap-form-group">
                    <label class="ptp-aap-label">PLAYER NAME</label>
                    <input type="text" id="ptp-checkout-player" class="ptp-aap-input" placeholder="Player's name">
                </div>
                <div class="ptp-aap-checkout-summary">
                    <div class="ptp-aap-summary-row">
                        <span id="ptp-checkout-item"><?php echo $service['name']; ?></span>
                        <span id="ptp-checkout-price">$<?php echo $service['purchase_price']; ?></span>
                    </div>
                </div>
                <button type="button" id="ptp-checkout-submit" class="ptp-btn ptp-btn-gold ptp-btn-lg ptp-btn-full">
                    PROCEED TO PAYMENT
                </button>
                <p class="ptp-aap-secure-note">🔒 Secure checkout powered by Stripe</p>
            </div>
            <div id="ptp-checkout-loading" style="display:none;">
                <div class="ptp-aap-spinner"></div>
                <p>Preparing secure checkout...</p>
            </div>
        </div>
    </div>
</div>

<style>
.ptp-service-page {
    font-family: var(--ptp-font-body);
}

.ptp-service-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 0 var(--ptp-space-5);
}

.ptp-service-hero {
    padding: var(--ptp-space-12) var(--ptp-space-5);
    background: var(--ptp-gray-100);
    display: flex;
    justify-content: center;
}

.ptp-service-card {
    background: var(--ptp-white);
    border: var(--ptp-border-width) solid var(--ptp-black);
    padding: var(--ptp-space-10) var(--ptp-space-8);
    text-align: center;
    max-width: 480px;
    width: 100%;
    box-shadow: 4px 4px 0 var(--ptp-black);
}

.ptp-service-icon {
    font-size: 64px;
    margin-bottom: var(--ptp-space-4);
}

.ptp-service-title {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-3xl);
    font-weight: 700;
    text-transform: uppercase;
    margin: 0 0 var(--ptp-space-3) 0;
}

.ptp-service-desc {
    color: var(--ptp-gray-600);
    margin: 0 0 var(--ptp-space-6) 0;
    line-height: 1.6;
}

.ptp-service-price-block {
    margin-bottom: var(--ptp-space-6);
}

.ptp-service-price {
    font-family: var(--ptp-font-display);
    font-size: 56px;
    font-weight: 700;
    line-height: 1;
}

.ptp-service-per {
    display: block;
    color: var(--ptp-gray-500);
    margin-top: var(--ptp-space-1);
}

.ptp-service-qty-selector {
    margin-bottom: var(--ptp-space-6);
}

.ptp-service-qty-label {
    display: block;
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-xs);
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--ptp-gray-600);
    margin-bottom: var(--ptp-space-2);
}

.ptp-service-qty-controls {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0;
    margin-bottom: var(--ptp-space-2);
}

.ptp-qty-btn {
    width: 44px;
    height: 44px;
    font-size: 24px;
    font-weight: 500;
    background: var(--ptp-gray-100);
    border: var(--ptp-border-width) solid var(--ptp-black);
    cursor: pointer;
    transition: background 0.2s;
}

.ptp-qty-btn:hover {
    background: var(--ptp-gold);
}

.ptp-qty-input {
    width: 60px;
    height: 44px;
    text-align: center;
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-xl);
    font-weight: 600;
    border: var(--ptp-border-width) solid var(--ptp-black);
    border-left: none;
    border-right: none;
}

.ptp-qty-input::-webkit-inner-spin-button,
.ptp-qty-input::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.ptp-service-total {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-lg);
    font-weight: 600;
}

.ptp-service-upsell {
    margin-top: var(--ptp-space-6);
    padding-top: var(--ptp-space-6);
    border-top: 1px solid var(--ptp-gray-200);
}

.ptp-service-upsell p {
    margin: 0 0 var(--ptp-space-2) 0;
    color: var(--ptp-gray-600);
}

.ptp-service-upsell a {
    color: var(--ptp-gold-hover);
    font-weight: 600;
}

.ptp-upsell-savings {
    display: inline-block;
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-xs);
    font-weight: 600;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    padding: var(--ptp-space-1) var(--ptp-space-3);
    margin-top: var(--ptp-space-2);
}

/* Details Section */
.ptp-service-details {
    padding: var(--ptp-space-16) var(--ptp-space-5);
    background: var(--ptp-white);
}

.ptp-service-section-title {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-3xl);
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
    margin: 0 0 var(--ptp-space-10) 0;
}

.ptp-service-features {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--ptp-space-6);
}

@media (min-width: 600px) {
    .ptp-service-features {
        grid-template-columns: repeat(3, 1fr);
    }
}

.ptp-feature-item {
    text-align: center;
    padding: var(--ptp-space-6);
    border: var(--ptp-border-width) solid var(--ptp-gray-200);
}

.ptp-feature-icon {
    font-size: 40px;
    display: block;
    margin-bottom: var(--ptp-space-3);
}

.ptp-feature-item h3 {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-lg);
    font-weight: 600;
    text-transform: uppercase;
    margin: 0 0 var(--ptp-space-2) 0;
}

.ptp-feature-item p {
    color: var(--ptp-gray-600);
    font-size: var(--ptp-text-sm);
    margin: 0;
    line-height: 1.5;
}

/* CTA */
.ptp-service-cta {
    padding: var(--ptp-space-16) var(--ptp-space-5);
    background: var(--ptp-black);
    text-align: center;
}

.ptp-service-cta h2 {
    font-family: var(--ptp-font-display);
    font-size: var(--ptp-text-3xl);
    font-weight: 700;
    text-transform: uppercase;
    color: var(--ptp-white);
    margin: 0 0 var(--ptp-space-3) 0;
}

.ptp-service-cta p {
    color: var(--ptp-gray-300);
    margin: 0 0 var(--ptp-space-8) 0;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const qtyInput = document.getElementById('ptp-service-qty');
    const totalEl = document.getElementById('ptp-service-total');
    const checkoutPrice = document.getElementById('ptp-checkout-price');
    const checkoutItem = document.getElementById('ptp-checkout-item');
    const price = <?php echo $service['purchase_price']; ?>;
    const serviceName = '<?php echo esc_js($service['name']); ?>';
    
    function updateTotal() {
        const qty = parseInt(qtyInput.value) || 1;
        const total = qty * price;
        totalEl.textContent = total;
        checkoutPrice.textContent = '$' + total;
        checkoutItem.textContent = serviceName + (qty > 1 ? ' (' + qty + ' hours)' : '');
    }
    
    document.querySelector('.ptp-qty-minus').addEventListener('click', function() {
        const current = parseInt(qtyInput.value) || 1;
        if (current > 1) {
            qtyInput.value = current - 1;
            updateTotal();
        }
    });
    
    document.querySelector('.ptp-qty-plus').addEventListener('click', function() {
        const current = parseInt(qtyInput.value) || 1;
        if (current < 10) {
            qtyInput.value = current + 1;
            updateTotal();
        }
    });
    
    qtyInput.addEventListener('change', updateTotal);
    
    // Buy button
    document.querySelector('.ptp-service-buy-btn').addEventListener('click', function() {
        const modal = document.getElementById('ptp-checkout-modal');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    });
    
    // Close modal
    document.querySelectorAll('.ptp-aap-modal-close, .ptp-aap-modal-overlay').forEach(el => {
        el.addEventListener('click', function() {
            document.getElementById('ptp-checkout-modal').style.display = 'none';
            document.body.style.overflow = '';
        });
    });
    
    // Checkout submit
    document.getElementById('ptp-checkout-submit').addEventListener('click', function() {
        const email = document.getElementById('ptp-checkout-email').value;
        if (!email || !email.includes('@')) {
            alert('Please enter a valid email');
            return;
        }
        
        document.getElementById('ptp-checkout-form').style.display = 'none';
        document.getElementById('ptp-checkout-loading').style.display = 'block';
        
        const formData = new FormData();
        formData.append('action', 'ptp_service_purchase');
        formData.append('nonce', ptpAllAccess.nonce);
        formData.append('service', '<?php echo esc_js($service_key); ?>');
        formData.append('quantity', qtyInput.value);
        formData.append('email', email);
        
        fetch(ptpAllAccess.ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data.url) {
                window.location.href = data.data.url;
            } else {
                alert(data.data?.message || 'Something went wrong');
                document.getElementById('ptp-checkout-form').style.display = 'block';
                document.getElementById('ptp-checkout-loading').style.display = 'none';
            }
        });
    });
});
</script>
