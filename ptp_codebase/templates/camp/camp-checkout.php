<?php
/**
 * PTP Camp Checkout Template
 * 
 * Stripe-direct checkout without PTP Native
 * 
 * @version 146.0.0
 */

defined('ABSPATH') || exit;

// Get available camps
$camps = PTP_Camp_Checkout::get_camp_products();
?>

<div id="ptp-camp-checkout" class="ptp-camp-checkout">
    <!-- Progress Steps -->
    <div class="checkout-progress">
        <div class="progress-step active" data-step="1">
            <span class="step-number">1</span>
            <span class="step-label">Select Camp</span>
        </div>
        <div class="progress-step" data-step="2">
            <span class="step-number">2</span>
            <span class="step-label">Camper Info</span>
        </div>
        <div class="progress-step" data-step="3">
            <span class="step-number">3</span>
            <span class="step-label">Payment</span>
        </div>
    </div>

    <div class="checkout-container">
        <!-- Step 1: Select Camp -->
        <div class="checkout-step" id="step-1">
            <h2>Select Your Camp</h2>
            
            <div class="camps-grid" id="camps-grid">
                <?php if (empty($camps)): ?>
                    <div class="no-camps">
                        <p>No camps currently available. Check back soon!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($camps as $camp): ?>
                        <div class="camp-card" data-product-id="<?php echo esc_attr($camp->stripe_product_id); ?>" data-price-id="<?php echo esc_attr($camp->stripe_price_id); ?>" data-price="<?php echo esc_attr($camp->price); ?>" data-db-id="<?php echo esc_attr($camp->id); ?>">
                            <?php if ($camp->image_url): ?>
                                <div class="camp-image" style="background-image: url('<?php echo esc_url($camp->image_url); ?>')"></div>
                            <?php else: ?>
                                <div class="camp-image camp-image-placeholder">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                                </div>
                            <?php endif; ?>
                            
                            <div class="camp-content">
                                <h3 class="camp-name"><?php echo esc_html($camp->name); ?></h3>
                                
                                <?php if ($camp->camp_dates): ?>
                                    <p class="camp-dates">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        <?php echo esc_html($camp->camp_dates); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <?php if ($camp->camp_location): ?>
                                    <p class="camp-location">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                        <?php echo esc_html($camp->camp_location); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <?php if ($camp->camp_time): ?>
                                    <p class="camp-time">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        <?php echo esc_html($camp->camp_time); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <?php if ($camp->spots_remaining !== null): ?>
                                    <p class="camp-spots <?php echo $camp->spots_remaining < 10 ? 'low-spots' : ''; ?>">
                                        <?php if ($camp->spots_remaining > 0): ?>
                                            <?php echo esc_html($camp->spots_remaining); ?> spots left
                                        <?php else: ?>
                                            <span class="sold-out">SOLD OUT</span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                
                                <div class="camp-footer">
                                    <span class="camp-price"><?php echo esc_html($camp->price_formatted); ?></span>
                                    <button type="button" class="btn-select-camp" <?php echo ($camp->spots_remaining === 0) ? 'disabled' : ''; ?>>
                                        Select
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Selected Camps Cart -->
            <div class="selected-camps" id="selected-camps" style="display: none;">
                <h3>Selected Camps</h3>
                <div class="selected-camps-list"></div>
                <div class="selected-camps-actions">
                    <button type="button" class="btn-add-camper" id="btn-add-camper">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Add Another Camper
                    </button>
                    <button type="button" class="btn-continue" id="btn-continue-step1">
                        Continue to Camper Info
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 2: Camper Information -->
        <div class="checkout-step" id="step-2" style="display: none;">
            <h2>Camper Information</h2>
            
            <div class="campers-forms" id="campers-forms">
                <!-- Camper forms will be dynamically generated -->
            </div>
            
            <!-- Parent/Guardian Info -->
            <div class="parent-info-section">
                <h3>Parent/Guardian Information</h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="billing_first_name">First Name *</label>
                        <input type="text" id="billing_first_name" name="billing_first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="billing_last_name">Last Name *</label>
                        <input type="text" id="billing_last_name" name="billing_last_name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="billing_email">Email *</label>
                        <input type="email" id="billing_email" name="billing_email" required>
                    </div>
                    <div class="form-group">
                        <label for="billing_phone">Phone *</label>
                        <input type="tel" id="billing_phone" name="billing_phone" required>
                    </div>
                </div>
                
                <h4>Emergency Contact</h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="emergency_name">Emergency Contact Name *</label>
                        <input type="text" id="emergency_name" name="emergency_name" required>
                    </div>
                    <div class="form-group">
                        <label for="emergency_phone">Emergency Contact Phone *</label>
                        <input type="tel" id="emergency_phone" name="emergency_phone" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="emergency_relation">Relationship to Camper *</label>
                    <select id="emergency_relation" name="emergency_relation" required>
                        <option value="">Select...</option>
                        <option value="parent">Parent</option>
                        <option value="grandparent">Grandparent</option>
                        <option value="aunt_uncle">Aunt/Uncle</option>
                        <option value="sibling">Sibling (18+)</option>
                        <option value="family_friend">Family Friend</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            
            <!-- Waiver -->
            <div class="waiver-section">
                <h3>Liability Waiver & Photo Release</h3>
                <div class="waiver-text">
                    <p>By checking the boxes below, I acknowledge that I have read and agree to the following:</p>
                    <ul>
                        <li>I understand that participation in soccer camp involves physical activity and inherent risks.</li>
                        <li>I release PTP Soccer Camps, its coaches, and staff from any liability for injuries that may occur during camp activities.</li>
                        <li>I confirm that my child is physically fit to participate and has no medical conditions that would prevent participation.</li>
                        <li>I authorize PTP Soccer Camps to seek emergency medical treatment if necessary.</li>
                    </ul>
                </div>
                
                <div class="form-group checkbox-group">
                    <label>
                        <input type="checkbox" id="waiver_agree" name="waiver_agree" required>
                        I agree to the liability waiver and terms of participation *
                    </label>
                </div>
                
                <div class="form-group checkbox-group">
                    <label>
                        <input type="checkbox" id="photo_release" name="photo_release" checked>
                        I grant permission for my child's photo/video to be used for promotional purposes
                    </label>
                </div>
            </div>
            
            <div class="step-actions">
                <button type="button" class="btn-back" id="btn-back-step2">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Back
                </button>
                <button type="button" class="btn-continue" id="btn-continue-step2">
                    Continue to Payment
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </button>
            </div>
        </div>

        <!-- Step 3: Payment -->
        <div class="checkout-step" id="step-3" style="display: none;">
            <h2>Review & Pay</h2>
            
            <div class="payment-layout">
                <!-- Order Summary -->
                <div class="order-summary">
                    <h3>Order Summary</h3>
                    <div class="summary-items" id="summary-items">
                        <!-- Populated by JS -->
                    </div>
                    
                    <!-- Referral Code -->
                    <div class="referral-section">
                        <label for="referral_code">Have a referral code?</label>
                        <div class="referral-input">
                            <input type="text" id="referral_code" name="referral_code" placeholder="Enter code">
                            <button type="button" id="btn-apply-referral">Apply</button>
                        </div>
                        <div class="referral-message" id="referral-message"></div>
                    </div>
                    
                    <!-- Add-ons -->
                    <div class="addons-section">
                        <h4>Add-ons</h4>
                        
                        <div class="addon-item">
                            <label>
                                <input type="checkbox" id="addon_care_bundle" name="addon_care_bundle">
                                <span class="addon-info">
                                    <strong>Before + After Care Bundle</strong>
                                    <span class="addon-desc">Extended hours: 8:00 AM - 4:30 PM</span>
                                </span>
                                <span class="addon-price">+$60</span>
                            </label>
                        </div>
                        
                        <div class="addon-item">
                            <label>
                                <input type="checkbox" id="addon_jersey" name="addon_jersey">
                                <span class="addon-info">
                                    <strong>PTP Camp Jersey</strong>
                                    <span class="addon-desc">Custom jersey with name and number</span>
                                </span>
                                <span class="addon-price">+$50 <span class="original-price">$75</span></span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Totals -->
                    <div class="summary-totals">
                        <div class="total-row">
                            <span>Subtotal</span>
                            <span id="subtotal">$0</span>
                        </div>
                        <div class="total-row discount-row" id="discount-row" style="display: none;">
                            <span>Discounts</span>
                            <span id="discount-amount">-$0</span>
                        </div>
                        <div class="total-row" id="care-row" style="display: none;">
                            <span>Before + After Care</span>
                            <span id="care-amount">$0</span>
                        </div>
                        <div class="total-row" id="jersey-row" style="display: none;">
                            <span>Camp Jersey</span>
                            <span id="jersey-amount">$0</span>
                        </div>
                        <div class="total-row processing-row">
                            <span>Processing Fee (3% + $0.30)</span>
                            <span id="processing-fee">$0</span>
                        </div>
                        <div class="total-row total-final">
                            <span>Total</span>
                            <span id="total-amount">$0</span>
                        </div>
                    </div>
                </div>
                
                <!-- Payment Button -->
                <div class="payment-section">
                    <div class="trust-badges">
                        <div class="badge">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <span>Secure Payment</span>
                        </div>
                        <div class="badge">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            <span>SSL Encrypted</span>
                        </div>
                    </div>
                    
                    <button type="button" class="btn-pay" id="btn-pay">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        Pay Now
                    </button>
                    
                    <p class="payment-note">You'll be redirected to Stripe's secure checkout to complete payment.</p>
                    
                    <div class="step-actions">
                        <button type="button" class="btn-back" id="btn-back-step3">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                            Back to Camper Info
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div class="checkout-loading" id="checkout-loading" style="display: none;">
        <div class="loading-spinner"></div>
        <p>Processing your registration...</p>
    </div>
</div>

<!-- Camper Form Template -->
<template id="camper-form-template">
    <div class="camper-form" data-camper-index="">
        <div class="camper-form-header">
            <h4>Camper <span class="camper-number"></span></h4>
            <span class="camp-name-badge"></span>
            <button type="button" class="btn-remove-camper" title="Remove camper">×</button>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>First Name *</label>
                <input type="text" class="camper-first-name" required>
            </div>
            <div class="form-group">
                <label>Last Name *</label>
                <input type="text" class="camper-last-name" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Date of Birth *</label>
                <input type="date" class="camper-dob" required>
            </div>
            <div class="form-group">
                <label>Gender</label>
                <select class="camper-gender">
                    <option value="">Select...</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                    <option value="prefer_not_say">Prefer not to say</option>
                </select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>T-Shirt Size *</label>
                <select class="camper-shirt-size" required>
                    <option value="">Select...</option>
                    <option value="YS">Youth Small</option>
                    <option value="YM">Youth Medium</option>
                    <option value="YL">Youth Large</option>
                    <option value="AS">Adult Small</option>
                    <option value="AM">Adult Medium</option>
                    <option value="AL">Adult Large</option>
                    <option value="AXL">Adult XL</option>
                </select>
            </div>
            <div class="form-group">
                <label>Skill Level</label>
                <select class="camper-skill-level">
                    <option value="">Select...</option>
                    <option value="beginner">Beginner (just starting)</option>
                    <option value="recreational">Recreational (plays for fun)</option>
                    <option value="travel">Travel/Club team</option>
                    <option value="advanced">Advanced/Competitive</option>
                </select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Current Team (if any)</label>
                <input type="text" class="camper-team" placeholder="e.g., FC United U12">
            </div>
            <div class="form-group">
                <label>Preferred Position</label>
                <select class="camper-position">
                    <option value="">Select...</option>
                    <option value="goalkeeper">Goalkeeper</option>
                    <option value="defender">Defender</option>
                    <option value="midfielder">Midfielder</option>
                    <option value="forward">Forward</option>
                    <option value="no_preference">No preference</option>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label>Medical Conditions / Allergies</label>
            <textarea class="camper-medical" rows="2" placeholder="List any medical conditions, allergies, or special needs we should be aware of"></textarea>
        </div>
    </div>
</template>

<style>
/* Scoped styles for camp checkout */
.ptp-camp-checkout {
    --ptp-gold: #FCB900;
    --ptp-black: #0A0A0A;
    --ptp-gray: #f3f4f6;
    --ptp-border: #e5e7eb;
    --ptp-text: #374151;
    --ptp-text-light: #6b7280;
    --ptp-success: #10b981;
    --ptp-error: #ef4444;
    
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

/* Progress Steps */
.checkout-progress {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-bottom: 40px;
    padding: 20px;
    background: var(--ptp-gray);
    border-radius: 8px;
}

.progress-step {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--ptp-text-light);
}

.progress-step .step-number {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    border: 2px solid var(--ptp-border);
    border-radius: 50%;
    font-weight: 600;
}

.progress-step.active .step-number {
    background: var(--ptp-gold);
    border-color: var(--ptp-gold);
    color: var(--ptp-black);
}

.progress-step.completed .step-number {
    background: var(--ptp-success);
    border-color: var(--ptp-success);
    color: #fff;
}

/* Camps Grid */
.camps-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.camp-card {
    background: #fff;
    border: 2px solid var(--ptp-border);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.2s;
    cursor: pointer;
}

.camp-card:hover {
    border-color: var(--ptp-gold);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.camp-card.selected {
    border-color: var(--ptp-gold);
    background: #fffbeb;
}

.camp-image {
    height: 160px;
    background-size: cover;
    background-position: center;
    background-color: var(--ptp-gray);
}

.camp-image-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ptp-text-light);
}

.camp-image-placeholder svg {
    width: 48px;
    height: 48px;
}

.camp-content {
    padding: 15px;
}

.camp-name {
    margin: 0 0 10px 0;
    font-size: 18px;
    color: var(--ptp-black);
}

.camp-dates, .camp-location, .camp-time {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 5px 0;
    font-size: 14px;
    color: var(--ptp-text-light);
}

.camp-dates svg, .camp-location svg, .camp-time svg {
    flex-shrink: 0;
}

.camp-spots {
    margin: 10px 0;
    font-size: 13px;
    color: var(--ptp-success);
    font-weight: 500;
}

.camp-spots.low-spots {
    color: var(--ptp-error);
}

.sold-out {
    background: var(--ptp-error);
    color: #fff;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
}

.camp-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid var(--ptp-border);
}

.camp-price {
    font-size: 24px;
    font-weight: 700;
    color: var(--ptp-black);
}

.btn-select-camp {
    padding: 8px 20px;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-select-camp:hover {
    background: #e5a800;
}

.btn-select-camp:disabled {
    background: var(--ptp-border);
    color: var(--ptp-text-light);
    cursor: not-allowed;
}

/* Selected Camps */
.selected-camps {
    background: #fff;
    border: 2px solid var(--ptp-gold);
    border-radius: 12px;
    padding: 20px;
    margin-top: 30px;
}

.selected-camps h3 {
    margin: 0 0 15px 0;
    color: var(--ptp-black);
}

.selected-camps-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.selected-camp-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 15px;
    background: var(--ptp-gray);
    border-radius: 8px;
}

.selected-camp-item .camp-info {
    flex: 1;
}

.selected-camp-item .camp-info strong {
    display: block;
    color: var(--ptp-black);
}

.selected-camp-item .camp-info span {
    font-size: 14px;
    color: var(--ptp-text-light);
}

.selected-camp-item .remove-btn {
    background: none;
    border: none;
    color: var(--ptp-error);
    cursor: pointer;
    font-size: 20px;
    padding: 5px;
}

.selected-camps-actions {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    margin-top: 20px;
}

/* Buttons */
.btn-add-camper, .btn-continue, .btn-back, .btn-pay {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-add-camper {
    background: #fff;
    border: 2px solid var(--ptp-black);
    color: var(--ptp-black);
}

.btn-add-camper:hover {
    background: var(--ptp-gray);
}

.btn-continue, .btn-pay {
    background: var(--ptp-gold);
    border: none;
    color: var(--ptp-black);
}

.btn-continue:hover, .btn-pay:hover {
    background: #e5a800;
}

.btn-back {
    background: none;
    border: 2px solid var(--ptp-border);
    color: var(--ptp-text);
}

.btn-back:hover {
    background: var(--ptp-gray);
}

.btn-pay {
    width: 100%;
    justify-content: center;
    padding: 16px 24px;
    font-size: 18px;
}

/* Forms */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 15px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.form-group label {
    font-size: 14px;
    font-weight: 500;
    color: var(--ptp-text);
}

.form-group input, .form-group select, .form-group textarea {
    padding: 10px 12px;
    border: 2px solid var(--ptp-border);
    border-radius: 6px;
    font-size: 16px;
    transition: border-color 0.2s;
}

.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline: none;
    border-color: var(--ptp-gold);
}

.checkbox-group label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
}

.checkbox-group input[type="checkbox"] {
    margin-top: 3px;
}

/* Camper Forms */
.camper-form {
    background: #fff;
    border: 2px solid var(--ptp-border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.camper-form-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid var(--ptp-border);
}

.camper-form-header h4 {
    margin: 0;
    color: var(--ptp-black);
}

.camp-name-badge {
    background: var(--ptp-gold);
    color: var(--ptp-black);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.btn-remove-camper {
    margin-left: auto;
    background: none;
    border: none;
    font-size: 24px;
    color: var(--ptp-text-light);
    cursor: pointer;
    padding: 0 5px;
}

.btn-remove-camper:hover {
    color: var(--ptp-error);
}

/* Payment Section */
.payment-layout {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 30px;
}

.order-summary {
    background: #fff;
    border: 2px solid var(--ptp-border);
    border-radius: 12px;
    padding: 20px;
}

.order-summary h3 {
    margin: 0 0 20px 0;
    color: var(--ptp-black);
}

.summary-items {
    margin-bottom: 20px;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid var(--ptp-border);
}

.summary-item:last-child {
    border-bottom: none;
}

.referral-section {
    padding: 15px;
    background: var(--ptp-gray);
    border-radius: 8px;
    margin-bottom: 20px;
}

.referral-section label {
    display: block;
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: 500;
}

.referral-input {
    display: flex;
    gap: 10px;
}

.referral-input input {
    flex: 1;
    padding: 8px 12px;
    border: 2px solid var(--ptp-border);
    border-radius: 6px;
    font-size: 14px;
    text-transform: uppercase;
}

.referral-input button {
    padding: 8px 16px;
    background: var(--ptp-black);
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
}

.referral-message {
    margin-top: 10px;
    font-size: 14px;
}

.referral-message.success {
    color: var(--ptp-success);
}

.referral-message.error {
    color: var(--ptp-error);
}

.addons-section {
    margin-bottom: 20px;
}

.addons-section h4 {
    margin: 0 0 15px 0;
    color: var(--ptp-black);
}

.addon-item {
    padding: 15px;
    background: var(--ptp-gray);
    border-radius: 8px;
    margin-bottom: 10px;
}

.addon-item label {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
}

.addon-info {
    flex: 1;
}

.addon-info strong {
    display: block;
    color: var(--ptp-black);
}

.addon-desc {
    font-size: 13px;
    color: var(--ptp-text-light);
}

.addon-price {
    font-weight: 600;
    color: var(--ptp-black);
}

.addon-price .original-price {
    text-decoration: line-through;
    color: var(--ptp-text-light);
    font-weight: normal;
    margin-left: 5px;
}

.summary-totals {
    padding-top: 15px;
    border-top: 2px solid var(--ptp-border);
}

.total-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    color: var(--ptp-text);
}

.discount-row {
    color: var(--ptp-success);
}

.processing-row {
    font-size: 14px;
    color: var(--ptp-text-light);
}

.total-final {
    font-size: 20px;
    font-weight: 700;
    color: var(--ptp-black);
    padding-top: 15px;
    border-top: 2px solid var(--ptp-black);
    margin-top: 10px;
}

/* Trust Badges */
.trust-badges {
    display: flex;
    justify-content: center;
    gap: 30px;
    margin-bottom: 20px;
}

.badge {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--ptp-text-light);
    font-size: 14px;
}

.payment-note {
    text-align: center;
    font-size: 13px;
    color: var(--ptp-text-light);
    margin-top: 15px;
}

/* Loading */
.checkout-loading {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255,255,255,0.95);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.loading-spinner {
    width: 48px;
    height: 48px;
    border: 4px solid var(--ptp-border);
    border-top-color: var(--ptp-gold);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Responsive */
@media (max-width: 768px) {
    .checkout-progress {
        flex-direction: column;
        gap: 10px;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .payment-layout {
        grid-template-columns: 1fr;
    }
    
    .selected-camps-actions {
        flex-direction: column;
    }
    
    .camps-grid {
        grid-template-columns: 1fr;
    }
}
</style>
