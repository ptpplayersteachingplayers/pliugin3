<?php
/**
 * PTP Unified Checkout Template v16
 * 
 * Handles both camps and trainings in single checkout
 * Uses Stripe Payment Element for payment processing
 */

defined('ABSPATH') || exit;

get_header();

// Get cart data
$cart_data = class_exists('PTP_Cart_Helper') ? PTP_Cart_Helper::get_cart_data() : array();
$totals = function_exists('ptp_checkout') ? ptp_checkout()->calculate_cart_totals() : array();

// Get Stripe keys
$test_mode = get_option('ptp_stripe_test_mode', true);
$publishable_key = $test_mode 
    ? get_option('ptp_stripe_test_publishable', get_option('ptp_stripe_publishable_key', ''))
    : get_option('ptp_stripe_live_publishable', get_option('ptp_stripe_publishable_key', ''));

// Check if cart is empty
$is_empty = empty($totals['total']) || $totals['total'] <= 0;

// Source URL for back navigation
$source_url = '';
if (function_exists('ptp_session') && ptp_session()) {
    $source_url = ptp_session()->get('ptp_source_url', '');
}
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root {
    --ptp-gold: #FCB900;
    --ptp-gold-dark: #E5A800;
    --ptp-black: #0A0A0A;
    --ptp-green: #22C55E;
    --ptp-white: #FFF;
    --ptp-g50: #FAFAFA;
    --ptp-g200: #E5E5E5;
    --ptp-g400: #A3A3A3;
    --ptp-g500: #737373;
}

/* v200.2: Full-width — bust out of ALL theme/Elementor containers */
body.ptp-checkout-active .site-content,body.ptp-checkout-active .entry-content,body.ptp-checkout-active .post-content,body.ptp-checkout-active .page-content,body.ptp-checkout-active main,body.ptp-checkout-active main.site-main,body.ptp-checkout-active article,body.ptp-checkout-active #content,body.ptp-checkout-active #main,body.ptp-checkout-active #primary,body.ptp-checkout-active .content-area,body.ptp-checkout-active .site-main,body.ptp-checkout-active .ast-container,body.ptp-checkout-active .elementor-section-wrap,body.ptp-checkout-active .elementor-section,body.ptp-checkout-active .elementor-container,body.ptp-checkout-active .elementor-column-wrap,body.ptp-checkout-active .elementor-widget-wrap,body.ptp-checkout-active .elementor-widget-theme-post-content,body.ptp-checkout-active .elementor-element,body.ptp-checkout-active .e-con,body.ptp-checkout-active .e-con-inner,body.ptp-checkout-active .elementor-location-single{width:100%!important;max-width:100%!important;padding-left:0!important;padding-right:0!important;margin-left:0!important;margin-right:0!important}

body.ptp-checkout-active { background: var(--ptp-g50) !important; }

.ptp-checkout {
    min-height: 100vh;
    background: var(--ptp-g50);
    font-family: 'Inter', -apple-system, sans-serif;
    -webkit-font-smoothing: antialiased;
    padding: 0;
}

/* v200.2: Banner matching cart page */
.ptp-checkout-banner {
    background: linear-gradient(135deg, var(--ptp-black), #1a1a1a);
    padding: 48px 24px;
    text-align: center;
}
.ptp-checkout-banner img { display: inline-block; height: 32px; width: auto; max-width: 180px; margin-bottom: 12px; }
.ptp-checkout-banner h1 { font-family: 'Oswald', sans-serif; font-size: clamp(28px, 5vw, 48px); font-weight: 700; text-transform: uppercase; color: var(--ptp-white); margin: 0 0 8px; }
.ptp-checkout-banner p { color: var(--ptp-g400); font-size: 15px; }

.ptp-checkout-container {
    width: 100%;
    max-width: 100%;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 48px;
    padding: 40px 5vw 80px;
}

@media (min-width: 1400px) { .ptp-checkout-container { grid-template-columns: 1fr 440px; gap: 56px; padding: 48px 6vw 80px; } }
@media (min-width: 1800px) { .ptp-checkout-container { grid-template-columns: 1fr 480px; gap: 64px; padding: 48px 8vw 80px; } }

@media (max-width: 900px) {
    .ptp-checkout-container {
        grid-template-columns: 1fr;
        padding: 28px 20px 120px;
    }
}

/* Cart Summary */
.ptp-cart-summary {
    background: #fff;
    border-radius: 20px;
    padding: 28px;
    box-shadow: 0 4px 24px rgba(0,0,0,.06);
}

/* Checkout Form */
.ptp-checkout-form {
    background: #fff;
    border-radius: 20px;
    padding: 28px;
    box-shadow: 0 4px 24px rgba(0,0,0,.06);
    height: fit-content;
}
@media (min-width: 901px) {
    .ptp-checkout-form {
        position: sticky;
        top: 24px;
    }
}

.ptp-cart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #eee;
}

.ptp-cart-title {
    font-family: 'Oswald', sans-serif;
    font-size: 24px;
    font-weight: 700;
    margin: 0;
}

.ptp-back-link {
    color: #666;
    text-decoration: none;
    font-size: 14px;
}

.ptp-cart-item {
    display: flex;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid #f0f0f0;
}

.ptp-item-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}

.ptp-badge-camp { background: var(--ptp-gold); color: var(--ptp-black); }
.ptp-badge-training { background: var(--ptp-green); color: #fff; }
.ptp-badge-addon { background: #8B5CF6; color: #fff; }

.ptp-item-details {
    flex: 1;
}

.ptp-item-name {
    font-weight: 600;
    margin-bottom: 4px;
}

.ptp-item-meta {
    font-size: 13px;
    color: #666;
}

.ptp-item-price {
    font-family: 'Oswald', sans-serif;
    font-size: 20px;
    font-weight: 600;
}

/* Totals */
.ptp-totals {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #eee;
}

.ptp-total-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
}

.ptp-discount-row {
    color: var(--ptp-green);
}

.ptp-total-final {
    font-size: 18px;
    font-weight: 700;
    border-top: 1px solid #eee;
    margin-top: 8px;
    padding-top: 12px;
}

.ptp-total-final .ptp-total-amount {
    font-family: 'Oswald', sans-serif;
    font-size: 28px;
    color: var(--ptp-gold);
}


.ptp-form-section {
    margin-bottom: 24px;
}

.ptp-section-title {
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--ptp-gold);
}

.ptp-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 12px;
}

@media (max-width: 500px) {
    .ptp-form-row { grid-template-columns: 1fr; }
}
@media (max-width: 380px) {
    .ptp-checkout-container { padding-left: 12px; padding-right: 12px; }
    .ptp-cart-summary, .ptp-checkout-form { padding: 20px 16px; border-radius: 14px; }
}

.ptp-form-field {
    display: flex;
    flex-direction: column;
}

.ptp-form-field.full-width {
    grid-column: 1 / -1;
}

.ptp-form-field label {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #333;
}

.ptp-form-field input,
.ptp-form-field select {
    padding: 12px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: 16px;
    font-family: inherit;
    transition: border-color 0.2s, box-shadow 0.2s;
    -webkit-appearance: none;
}

.ptp-form-field input:focus,
.ptp-form-field select:focus {
    outline: none;
    border-color: var(--ptp-gold);
    box-shadow: 0 0 0 3px rgba(252,185,0,0.12);
}

/* Stripe Payment Element */
#payment-element {
    min-height: 200px;
    padding: 16px;
    background: #fafafa;
    border-radius: 8px;
    margin-bottom: 20px;
}

/* Submit Button */
.ptp-submit-btn {
    width: 100%;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    border: none;
    padding: 18px;
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    border-radius: 14px;
    cursor: pointer;
    transition: transform 0.15s, background 0.15s;
    touch-action: manipulation;
    -webkit-tap-highlight-color: rgba(252,185,0,0.25);
    min-height: 56px;
    -webkit-appearance: none;
    user-select: none;
}

.ptp-submit-btn:hover {
    transform: scale(1.02);
    background: var(--ptp-gold-dark);
}

.ptp-submit-btn:active {
    transform: scale(0.97);
    background: #d49a00;
    transition: all 0.05s;
}

.ptp-submit-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.ptp-submit-btn.loading {
    position: relative;
}

.ptp-submit-btn.loading::after {
    content: '';
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    border: 2px solid transparent;
    border-top-color: currentColor;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: translateY(-50%) rotate(360deg); }
}

/* Waiver */
.ptp-waiver {
    background: #f9f9f9;
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.ptp-waiver-check {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.ptp-waiver-check input {
    margin-top: 2px;
    width: 22px;
    height: 22px;
    accent-color: var(--ptp-gold);
    flex-shrink: 0;
}

.ptp-waiver-text {
    font-size: 13px;
    color: #555;
    line-height: 1.5;
}

/* Trust Badges */
.ptp-trust {
    display: flex;
    justify-content: center;
    gap: 24px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #eee;
}

.ptp-trust-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #666;
}

/* Empty Cart */
.ptp-empty-cart {
    text-align: center;
    padding: 60px 20px;
}

.ptp-empty-icon {
    font-size: 48px;
    margin-bottom: 16px;
}

.ptp-empty-title {
    font-family: 'Oswald', sans-serif;
    font-size: 24px;
    margin-bottom: 12px;
}

.ptp-empty-cta {
    display: inline-block;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    padding: 14px 28px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    margin-top: 20px;
}

/* Error Message */
.ptp-error {
    background: #fee2e2;
    color: #dc2626;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: none;
}

.ptp-error.visible {
    display: block;
}
</style>

<script>document.body.classList.add('ptp-checkout-active');</script>

<div class="ptp-checkout">
    <!-- v200.2: Banner matching cart page -->
    <div class="ptp-checkout-banner">
        <?php $uc_logo = get_option('ptp_logo_url', ''); if ($uc_logo): ?>
        <a href="<?php echo esc_url(home_url()); ?>"><img src="<?php echo esc_url($uc_logo); ?>" alt="PTP Soccer"></a>
        <?php endif; ?>
        <h1><?php echo $is_empty ? 'Your Cart' : 'Secure Checkout'; ?></h1>
        <p><?php echo $is_empty ? 'Your cart is empty' : 'Complete your purchase below'; ?></p>
    </div>

    <?php if ($is_empty): ?>
    
    <div class="ptp-checkout-container" style="max-width:600px;">
        <div class="ptp-cart-summary">
            <div class="ptp-empty-cart">
                <div class="ptp-empty-icon">🛒</div>
                <h2 class="ptp-empty-title">Your Cart is Empty</h2>
                <p>Add a camp or training session to get started!</p>
                <a href="<?php echo esc_url($source_url ?: home_url('/camps/')); ?>" class="ptp-empty-cta">
                    Browse Camps
                </a>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    
    <div class="ptp-checkout-container">
        <!-- Cart Summary -->
        <div class="ptp-cart-summary">
            <div class="ptp-cart-header">
                <h2 class="ptp-cart-title">Your Order</h2>
                <?php if ($source_url): ?>
                <a href="<?php echo esc_url($source_url); ?>" class="ptp-back-link">← Back</a>
                <?php endif; ?>
            </div>
            
            <?php 
            $items = function_exists('ptp_cart') ? ptp_cart()->get_cart() : array();
            foreach ($items as $item): 
                $type = $item['item_type'] ?? 'product';
                $metadata = $item['metadata'] ?? array();
                $badge_class = 'ptp-badge-' . $type;
            ?>
            <div class="ptp-cart-item">
                <div class="ptp-item-details">
                    <span class="ptp-item-badge <?php echo esc_attr($badge_class); ?>">
                        <?php echo esc_html(strtoupper($type)); ?>
                    </span>
                    <div class="ptp-item-name"><?php echo esc_html($metadata['name'] ?? 'Item'); ?></div>
                    <?php if (!empty($metadata['date'])): ?>
                    <div class="ptp-item-meta">📅 <?php echo esc_html($metadata['date']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($metadata['location'])): ?>
                    <div class="ptp-item-meta">📍 <?php echo esc_html($metadata['location']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="ptp-item-price">$<?php echo number_format($item['line_total'], 0); ?></div>
            </div>
            <?php endforeach; ?>
            
            <div class="ptp-totals">
                <div class="ptp-total-row">
                    <span>Subtotal</span>
                    <span>$<?php echo number_format($totals['subtotal'] ?? 0, 2); ?></span>
                </div>
                
                <?php foreach (($totals['discounts'] ?? array()) as $discount): ?>
                <div class="ptp-total-row ptp-discount-row">
                    <span><?php echo esc_html($discount['label']); ?></span>
                    <span>-$<?php echo number_format($discount['amount'], 2); ?></span>
                </div>
                <?php endforeach; ?>
                
                <div class="ptp-total-row ptp-total-final">
                    <span>Total</span>
                    <span class="ptp-total-amount">$<?php echo number_format($totals['total'] ?? 0, 2); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Checkout Form -->
        <div class="ptp-checkout-form">
            <form id="checkout-form">
                <?php wp_nonce_field('ptp_checkout', 'ptp_checkout_nonce'); ?>
                <input type="hidden" name="checkout_session" id="checkout_session" value="">
                
                <div class="ptp-error" id="error-message"></div>
                
                <!-- Parent/Guardian -->
                <div class="ptp-form-section">
                    <h3 class="ptp-section-title">Parent/Guardian</h3>
                    <div class="ptp-form-row">
                        <div class="ptp-form-field">
                            <label for="parent_first_name">First Name *</label>
                            <input type="text" id="parent_first_name" name="parent_first_name" required>
                        </div>
                        <div class="ptp-form-field">
                            <label for="parent_last_name">Last Name *</label>
                            <input type="text" id="parent_last_name" name="parent_last_name" required>
                        </div>
                    </div>
                    <div class="ptp-form-row">
                        <div class="ptp-form-field">
                            <label for="parent_email">Email *</label>
                            <input type="email" id="parent_email" name="parent_email" required>
                        </div>
                        <div class="ptp-form-field">
                            <label for="parent_phone">Phone *</label>
                            <input type="tel" id="parent_phone" name="parent_phone" required>
                        </div>
                    </div>
                </div>
                
                <!-- Camper/Player — v200.2: Full camp details matching camp-checkout -->
                <?php 
                $has_camps_in_cart = false;
                $has_training_in_cart = false;
                if (function_exists('ptp_cart')) {
                    $uc_items = ptp_cart()->get_cart();
                    foreach ($uc_items as $uc_item) {
                        $t = $uc_item['item_type'] ?? 'product';
                        if ($t === 'camp') $has_camps_in_cart = true;
                        if ($t === 'training') $has_training_in_cart = true;
                    }
                }
                ?>
                
                <?php if ($has_camps_in_cart): ?>
                <div class="ptp-form-section">
                    <h3 class="ptp-section-title">Camper Information</h3>
                    <div class="ptp-form-row">
                        <div class="ptp-form-field">
                            <label for="camper_first_name">First Name *</label>
                            <input type="text" id="camper_first_name" name="camper_first_name" required>
                        </div>
                        <div class="ptp-form-field">
                            <label for="camper_last_name">Last Name *</label>
                            <input type="text" id="camper_last_name" name="camper_last_name" required>
                        </div>
                    </div>
                    <div class="ptp-form-row">
                        <div class="ptp-form-field">
                            <label for="camper_dob">Date of Birth *</label>
                            <input type="date" id="camper_dob" name="camper_dob" required>
                        </div>
                        <div class="ptp-form-field">
                            <label for="camper_gender">Gender</label>
                            <select id="camper_gender" name="camper_gender">
                                <option value="">Select...</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                                <option value="prefer_not_say">Prefer not to say</option>
                            </select>
                        </div>
                    </div>
                    <div class="ptp-form-row">
                        <div class="ptp-form-field">
                            <label for="camper_shirt_size">T-Shirt Size *</label>
                            <select id="camper_shirt_size" name="camper_shirt_size" required>
                                <option value="">Select size</option>
                                <option value="YS">Youth Small</option>
                                <option value="YM">Youth Medium</option>
                                <option value="YL">Youth Large</option>
                                <option value="AS">Adult Small</option>
                                <option value="AM">Adult Medium</option>
                                <option value="AL">Adult Large</option>
                                <option value="AXL">Adult XL</option>
                            </select>
                        </div>
                        <div class="ptp-form-field">
                            <label for="camper_skill_level">Skill Level</label>
                            <select id="camper_skill_level" name="camper_skill_level">
                                <option value="">Select...</option>
                                <option value="beginner">Beginner (just starting)</option>
                                <option value="recreational">Recreational (plays for fun)</option>
                                <option value="travel">Travel/Club team</option>
                                <option value="advanced">Advanced/Competitive</option>
                            </select>
                        </div>
                    </div>
                    <div class="ptp-form-row">
                        <div class="ptp-form-field">
                            <label for="camper_team">Current Team</label>
                            <input type="text" id="camper_team" name="camper_team" placeholder="e.g., FC United U12">
                        </div>
                        <div class="ptp-form-field">
                            <label for="camper_position">Preferred Position</label>
                            <select id="camper_position" name="camper_position">
                                <option value="">Select...</option>
                                <option value="goalkeeper">Goalkeeper</option>
                                <option value="defender">Defender</option>
                                <option value="midfielder">Midfielder</option>
                                <option value="forward">Forward</option>
                                <option value="no_preference">No preference</option>
                            </select>
                        </div>
                    </div>
                    <div class="ptp-form-field" style="margin-top:4px;">
                        <label for="camper_medical">Medical Conditions / Allergies</label>
                        <textarea id="camper_medical" name="camper_medical" rows="2" placeholder="List any medical conditions, allergies, or special needs we should be aware of" style="width:100%;padding:10px 14px;border:1px solid #ddd;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;"></textarea>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($has_training_in_cart && !$has_camps_in_cart): ?>
                <div class="ptp-form-section">
                    <h3 class="ptp-section-title">Player Information</h3>
                    <div class="ptp-form-row">
                        <div class="ptp-form-field">
                            <label for="camper_first_name">Player First Name *</label>
                            <input type="text" id="camper_first_name" name="camper_first_name" required>
                        </div>
                        <div class="ptp-form-field">
                            <label for="camper_last_name">Player Last Name *</label>
                            <input type="text" id="camper_last_name" name="camper_last_name" required>
                        </div>
                    </div>
                    <div class="ptp-form-row">
                        <div class="ptp-form-field">
                            <label for="camper_dob">Date of Birth *</label>
                            <input type="date" id="camper_dob" name="camper_dob" required>
                        </div>
                        <div class="ptp-form-field">
                            <label for="camper_skill_level">Skill Level</label>
                            <select id="camper_skill_level" name="camper_skill_level">
                                <option value="">Select...</option>
                                <option value="beginner">Beginner</option>
                                <option value="recreational">Recreational</option>
                                <option value="travel">Travel/Club</option>
                                <option value="advanced">Advanced</option>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Emergency Contact -->
                <div class="ptp-form-section">
                    <h3 class="ptp-section-title">Emergency Contact</h3>
                    <div class="ptp-form-row">
                        <div class="ptp-form-field">
                            <label for="emergency_name">Contact Name *</label>
                            <input type="text" id="emergency_name" name="emergency_name" required>
                        </div>
                        <div class="ptp-form-field">
                            <label for="emergency_phone">Contact Phone *</label>
                            <input type="tel" id="emergency_phone" name="emergency_phone" required>
                        </div>
                    </div>
                </div>
                
                <!-- Waiver -->
                <div class="ptp-waiver">
                    <div class="ptp-waiver-check">
                        <input type="checkbox" id="waiver_accepted" name="waiver_accepted" required>
                        <label for="waiver_accepted" class="ptp-waiver-text">
                            I have read and agree to the <a href="/waiver/" target="_blank">Liability Waiver</a>. 
                            I understand the risks involved in athletic activities and authorize emergency medical treatment if needed.
                        </label>
                    </div>
                </div>
                
                <!-- Payment -->
                <div class="ptp-form-section">
                    <h3 class="ptp-section-title">Payment</h3>
                    <div id="payment-element">
                        <!-- Stripe Payment Element -->
                        <div style="text-align:center;padding:40px;color:#999;">
                            Loading payment form...
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="ptp-submit-btn" id="submit-btn">
                    PAY $<?php echo number_format($totals['total'] ?? 0, 0); ?>
                </button>
                
                <div class="ptp-trust">
                    <div class="ptp-trust-item">🔒 Secure Payment</div>
                    <div class="ptp-trust-item">🛡️ 14-Day Refund</div>
                    <div class="ptp-trust-item">✓ Instant Confirmation</div>
                </div>
            </form>
        </div>
    </div>
    
    <script src="https://js.stripe.com/v3/"></script>
    <script>
    (function() {
        'use strict';
        
        const publishableKey = '<?php echo esc_js($publishable_key); ?>';
        const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        const nonce = '<?php echo esc_js(wp_create_nonce('ptp_checkout')); ?>';
        
        let stripe, elements, paymentElement;
        let checkoutSession = '';
        let paymentIntentId = '';
        
        const form = document.getElementById('checkout-form');
        const submitBtn = document.getElementById('submit-btn');
        const errorEl = document.getElementById('error-message');
        
        function showError(message) {
            errorEl.textContent = message;
            errorEl.classList.add('visible');
            submitBtn.disabled = false;
            submitBtn.classList.remove('loading');
        }
        
        function hideError() {
            errorEl.classList.remove('visible');
        }
        
        // Initialize Stripe
        async function initStripe() {
            if (!publishableKey) {
                showError('Payment system not configured');
                return;
            }
            
            stripe = Stripe(publishableKey);
            
            // Create payment intent (with UTM attribution)
            const utmParams = {};
            try {
                const getCk = n => { const m = document.cookie.match(new RegExp('(^| )' + n + '=([^;]+)')); return m ? decodeURIComponent(m[2]) : ''; };
                const lt = getCk('ptp_lt') || getCk('ptp_ft');
                if (lt) { const p = JSON.parse(lt); if(p) { ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'].forEach(f => { if(p[f]) utmParams[f] = p[f]; }); if(p.click_id||p.fbclid) utmParams.click_id = p.click_id||p.fbclid; if(p.landing_page) utmParams.landing_page = p.landing_page; } }
                const sp = new URLSearchParams(location.search);
                if(!utmParams.utm_source && sp.get('utm_source')) { ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'].forEach(f => { const v = sp.get(f); if(v) utmParams[f] = v; }); if(sp.get('fbclid')) utmParams.click_id = sp.get('fbclid'); }
                if(!utmParams.landing_page) utmParams.landing_page = location.pathname;
            } catch(e) {}
            
            const piBody = { action: 'ptp_create_payment_intent', nonce: nonce, email: '', ...utmParams };
            const response = await fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(piBody)
            });
            
            const result = await response.json();
            
            if (!result.success) {
                showError(result.data?.message || 'Failed to initialize payment');
                return;
            }
            
            checkoutSession = result.data.checkout_session;
            paymentIntentId = result.data.payment_intent_id;
            document.getElementById('checkout_session').value = checkoutSession;
            
            // Create Payment Element
            elements = stripe.elements({
                clientSecret: result.data.client_secret,
                appearance: {
                    theme: 'stripe',
                    variables: {
                        colorPrimary: '#FCB900',
                        fontFamily: 'Inter, system-ui, sans-serif',
                    }
                }
            });
            
            paymentElement = elements.create('payment');
            paymentElement.mount('#payment-element');
        }
        
        // Handle form submission
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            hideError();
            
            // Validate form
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            if (!document.getElementById('waiver_accepted').checked) {
                showError('Please accept the waiver to continue');
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.classList.add('loading');
            submitBtn.textContent = 'Processing...';
            
            // Confirm payment
            const { error } = await stripe.confirmPayment({
                elements,
                confirmParams: {
                    return_url: window.location.origin + '/checkout/confirm/',
                    receipt_email: document.getElementById('parent_email').value,
                },
                redirect: 'if_required'
            });
            
            if (error) {
                showError(error.message);
                submitBtn.textContent = 'PAY $<?php echo number_format($totals['total'] ?? 0, 0); ?>';
                return;
            }
            
            // Payment succeeded - create orders
            const formData = new FormData(form);
            formData.append('action', 'ptp_confirm_checkout');
            formData.append('nonce', nonce);
            formData.append('checkout_session', checkoutSession);
            formData.append('payment_intent_id', paymentIntentId);
            
            // Append UTM attribution data
            if (typeof utmParams === 'object') {
                Object.keys(utmParams).forEach(k => { if (utmParams[k] && !formData.has(k)) formData.append(k, utmParams[k]); });
            }
            
            const confirmResponse = await fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            });
            
            const confirmResult = await confirmResponse.json();
            
            if (confirmResult.success && confirmResult.data.redirect) {
                window.location.href = confirmResult.data.redirect;
            } else {
                showError(confirmResult.data?.message || 'Order creation failed');
                submitBtn.textContent = 'PAY $<?php echo number_format($totals['total'] ?? 0, 0); ?>';
            }
        });
        
        // Initialize on load
        initStripe();
    })();
    </script>
    
    <?php endif; ?>
</div>

<?php get_footer(); ?>
