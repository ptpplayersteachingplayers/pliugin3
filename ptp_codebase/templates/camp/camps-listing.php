<?php
/**
 * PTP Camps Listing Template
 * 
 * Displays available camp products from Stripe
 * Usage: [ptp_camps]
 * 
 * @version 146.0.0
 */

defined('ABSPATH') || exit;

// Get camps
$camps = PTP_Camp_Checkout::get_camp_products(array(
    'active' => true,
    'type' => 'camp',
));

// Get clinics too
$clinics = PTP_Camp_Checkout::get_camp_products(array(
    'active' => true,
    'type' => 'clinic',
));
?>

<div class="ptp-camps-page">
    <!-- Hero Section -->
    <div class="camps-hero">
        <h1>PTP Soccer Camps</h1>
        <p>Train with NCAA Division 1 athletes and elite college players. Teaching what team coaches don't.</p>
    </div>
    
    <!-- Camp Features -->
    <div class="camps-features">
        <div class="feature">
            <div class="feature-icon">⚽</div>
            <h3>8:1 Ratio</h3>
            <p>Small groups mean more touches and personalized attention</p>
        </div>
        <div class="feature">
            <div class="feature-icon">🏆</div>
            <h3>Elite Coaches</h3>
            <p>Train with current NCAA D1 athletes and elite college players</p>
        </div>
        <div class="feature">
            <div class="feature-icon">🎯</div>
            <h3>Skill Focus</h3>
            <p>Individual skill development, not just scrimmages</p>
        </div>
        <div class="feature">
            <div class="feature-icon">😊</div>
            <h3>Fun First</h3>
            <p>Coaches play WITH kids, not just instruct</p>
        </div>
    </div>
    
    <!-- Camps Grid -->
    <?php if (!empty($camps)): ?>
        <h2 class="section-title">Summer Camps 2024</h2>
        <div class="camps-grid">
            <?php foreach ($camps as $camp): ?>
                <div class="camp-card">
                    <?php if ($camp->image_url): ?>
                        <div class="camp-image" style="background-image: url('<?php echo esc_url($camp->image_url); ?>')"></div>
                    <?php else: ?>
                        <div class="camp-image camp-image-default">
                            <span>⚽</span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="camp-content">
                        <h3><?php echo esc_html($camp->name); ?></h3>
                        
                        <?php if ($camp->description): ?>
                            <p class="camp-description"><?php echo esc_html(wp_trim_words($camp->description, 20)); ?></p>
                        <?php endif; ?>
                        
                        <div class="camp-details">
                            <?php if ($camp->camp_dates): ?>
                                <div class="detail">
                                    <span class="icon">📅</span>
                                    <span><?php echo esc_html($camp->camp_dates); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($camp->camp_location): ?>
                                <div class="detail">
                                    <span class="icon">📍</span>
                                    <span><?php echo esc_html($camp->camp_location); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($camp->camp_time): ?>
                                <div class="detail">
                                    <span class="icon">⏰</span>
                                    <span><?php echo esc_html($camp->camp_time); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($camp->camp_age_min || $camp->camp_age_max): ?>
                                <div class="detail">
                                    <span class="icon">👶</span>
                                    <span>Ages <?php echo esc_html($camp->camp_age_min ?: '5'); ?>-<?php echo esc_html($camp->camp_age_max ?: '14'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($camp->spots_remaining !== null): ?>
                            <div class="camp-spots <?php echo $camp->spots_remaining < 10 ? 'low' : ''; ?>">
                                <?php if ($camp->spots_remaining > 0): ?>
                                    <?php echo $camp->spots_remaining; ?> spots left
                                <?php else: ?>
                                    SOLD OUT
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="camp-footer">
                            <span class="camp-price"><?php echo esc_html($camp->price_formatted); ?></span>
                            <?php if ($camp->spots_remaining !== 0): ?>
                                <a href="<?php echo home_url('/ptp-checkout/?camp=' . esc_attr($camp->stripe_product_id)); ?>" class="btn-register">
                                    Register Now
                                </a>
                            <?php else: ?>
                                <button class="btn-register btn-disabled" disabled>Sold Out</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- Clinics Section -->
    <?php if (!empty($clinics)): ?>
        <h2 class="section-title">Specialty Clinics</h2>
        <div class="camps-grid">
            <?php foreach ($clinics as $clinic): ?>
                <div class="camp-card clinic-card">
                    <?php if ($clinic->image_url): ?>
                        <div class="camp-image" style="background-image: url('<?php echo esc_url($clinic->image_url); ?>')"></div>
                    <?php else: ?>
                        <div class="camp-image camp-image-default clinic">
                            <span>🎯</span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="camp-content">
                        <h3><?php echo esc_html($clinic->name); ?></h3>
                        
                        <?php if ($clinic->description): ?>
                            <p class="camp-description"><?php echo esc_html(wp_trim_words($clinic->description, 20)); ?></p>
                        <?php endif; ?>
                        
                        <div class="camp-details">
                            <?php if ($clinic->camp_dates): ?>
                                <div class="detail">
                                    <span class="icon">📅</span>
                                    <span><?php echo esc_html($clinic->camp_dates); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($clinic->camp_location): ?>
                                <div class="detail">
                                    <span class="icon">📍</span>
                                    <span><?php echo esc_html($clinic->camp_location); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="camp-footer">
                            <span class="camp-price"><?php echo esc_html($clinic->price_formatted); ?></span>
                            <a href="<?php echo home_url('/ptp-checkout/?camp=' . esc_attr($clinic->stripe_product_id)); ?>" class="btn-register">
                                Register
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <?php if (empty($camps) && empty($clinics)): ?>
        <div class="no-camps">
            <h2>Coming Soon!</h2>
            <p>We're currently planning our upcoming camps and clinics. Check back soon or sign up for our newsletter to be notified.</p>
            <a href="<?php echo home_url('/'); ?>" class="btn-register">Back to Home</a>
        </div>
    <?php endif; ?>
    
    <!-- Discount Info -->
    <div class="discount-info">
        <h2>Save on Registration</h2>
        <div class="discount-grid">
            <div class="discount-card">
                <h4>Sibling Discount</h4>
                <p>10% off for additional siblings</p>
            </div>
            <div class="discount-card">
                <h4>Multi-Week Discount</h4>
                <p>Save up to 20% on 4+ weeks</p>
            </div>
            <div class="discount-card">
                <h4>Referral Bonus</h4>
                <p>Give $25, Get $25 when you refer friends</p>
            </div>
            <div class="discount-card">
                <h4>Team Discount</h4>
                <p>Up to 20% off for groups of 15+</p>
            </div>
        </div>
    </div>
</div>

<style>
.ptp-camps-page {
    --ptp-gold: #FCB900;
    --ptp-black: #0A0A0A;
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

.camps-hero {
    text-align: center;
    padding: 60px 20px;
    background: linear-gradient(135deg, var(--ptp-black) 0%, #1a1a1a 100%);
    color: #fff;
    border-radius: 16px;
    margin-bottom: 40px;
}

.camps-hero h1 {
    font-size: 42px;
    font-weight: 800;
    margin: 0 0 15px;
    text-transform: uppercase;
}

.camps-hero p {
    font-size: 18px;
    color: #ccc;
    margin: 0;
}

.camps-features {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 50px;
}

.feature {
    text-align: center;
    padding: 25px 15px;
    background: #fff;
    border: 2px solid #f0f0f0;
    border-radius: 12px;
}

.feature-icon {
    font-size: 36px;
    margin-bottom: 10px;
}

.feature h3 {
    margin: 0 0 8px;
    font-size: 18px;
    color: var(--ptp-black);
}

.feature p {
    margin: 0;
    font-size: 14px;
    color: #666;
}

.section-title {
    font-size: 28px;
    font-weight: 700;
    color: var(--ptp-black);
    margin: 40px 0 25px;
    text-align: center;
}

.camps-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 25px;
    margin-bottom: 50px;
}

.camp-card {
    background: #fff;
    border: 2px solid #e5e7eb;
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.camp-card:hover {
    border-color: var(--ptp-gold);
    box-shadow: 0 10px 40px rgba(0,0,0,0.1);
    transform: translateY(-4px);
}

.camp-image {
    height: 180px;
    background-size: cover;
    background-position: center;
    position: relative;
}

.camp-image-default {
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #f0f0f0 0%, #e0e0e0 100%);
}

.camp-image-default span {
    font-size: 48px;
}

.camp-image-default.clinic {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
}

.camp-content {
    padding: 20px;
}

.camp-content h3 {
    margin: 0 0 10px;
    font-size: 20px;
    color: var(--ptp-black);
}

.camp-description {
    color: #666;
    font-size: 14px;
    margin: 0 0 15px;
    line-height: 1.5;
}

.camp-details {
    margin-bottom: 15px;
}

.camp-details .detail {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-size: 14px;
    color: #444;
}

.camp-details .icon {
    font-size: 16px;
}

.camp-spots {
    display: inline-block;
    padding: 4px 12px;
    background: #d1fae5;
    color: #065f46;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 15px;
}

.camp-spots.low {
    background: #fee2e2;
    color: #991b1b;
}

.camp-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 15px;
    border-top: 1px solid #e5e7eb;
}

.camp-price {
    font-size: 28px;
    font-weight: 800;
    color: var(--ptp-black);
}

.btn-register {
    display: inline-block;
    padding: 12px 24px;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    text-decoration: none;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.2s;
    border: none;
    cursor: pointer;
}

.btn-register:hover {
    background: #e5a800;
    transform: translateY(-2px);
}

.btn-register.btn-disabled {
    background: #e5e7eb;
    color: #9ca3af;
    cursor: not-allowed;
}

.no-camps {
    text-align: center;
    padding: 80px 20px;
    background: #f9fafb;
    border-radius: 16px;
}

.no-camps h2 {
    margin: 0 0 15px;
    color: var(--ptp-black);
}

.no-camps p {
    color: #666;
    margin: 0 0 25px;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}

.discount-info {
    background: var(--ptp-gold);
    padding: 40px;
    border-radius: 16px;
    text-align: center;
    margin-top: 50px;
}

.discount-info h2 {
    margin: 0 0 25px;
    color: var(--ptp-black);
}

.discount-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.discount-card {
    background: rgba(255,255,255,0.9);
    padding: 20px;
    border-radius: 12px;
}

.discount-card h4 {
    margin: 0 0 8px;
    color: var(--ptp-black);
}

.discount-card p {
    margin: 0;
    font-size: 14px;
    color: #444;
}

@media (max-width: 900px) {
    .camps-features,
    .discount-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 600px) {
    .camps-hero h1 {
        font-size: 28px;
    }
    
    .camps-features,
    .discount-grid {
        grid-template-columns: 1fr;
    }
    
    .camps-grid {
        grid-template-columns: 1fr;
    }
    
    .camp-footer {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
    
    .btn-register {
        width: 100%;
    }
}
</style>
