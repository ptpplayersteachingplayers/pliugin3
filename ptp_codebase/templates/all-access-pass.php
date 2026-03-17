<?php
/**
 * All-Access Pass Template v102
 * Full-width desktop layout with white labels
 * Mobile-first, value-stack pricing with anchor psychology
 */

defined('ABSPATH') || exit;

$pass = PTP_All_Access_Pass::instance();
$config = $pass->get_config();
$membership = $config['membership'];
$components = $config['components'];
$is_logged_in = is_user_logged_in();
$has_membership = $is_logged_in ? $pass->user_has_membership(get_current_user_id()) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5">
<meta name="theme-color" content="#0A0A0A">
<title>All-Access Pass — PTP Soccer | Camp + Training Bundle</title>
<meta name="description" content="Get the PTP All-Access Pass: summer camp weeks plus private training sessions with D1 athletes bundled at a discount. Ages 6-14 across PA & NJ.">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<link rel="canonical" href="<?php echo esc_url(home_url('/all-access-pass/')); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="All-Access Pass — PTP Soccer">
<meta property="og:description" content="Summer camp + private training with D1 athletes, bundled at a discount. Ages 6-14 across PA & NJ.">
<meta property="og:url" content="<?php echo esc_url(home_url('/all-access-pass/')); ?>">
<meta property="og:site_name" content="PTP Soccer">
<meta property="og:image" content="https://ptpsummercamps.com/wp-content/uploads/2026/02/group-photo.jpg">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="All-Access Pass — PTP Soccer">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FCB900;--black:#0A0A0A;--white:#FFFFFF;--gray:#F5F5F5;--gray2:#E5E7EB;--gray3:#D1D5DB;--gray4:#9CA3AF;--gray5:#6B7280;--gray6:#4B5563;--green:#22C55E;--red:#EF4444}
/* v133.2: Hide scrollbar */
html{scroll-behavior:smooth;scrollbar-width:none;-ms-overflow-style:none}
html::-webkit-scrollbar{display:none;width:0}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;background:var(--black);color:var(--white);line-height:1.5;-webkit-font-smoothing:antialiased;scrollbar-width:none;-ms-overflow-style:none}
body::-webkit-scrollbar{display:none;width:0}
h1,h2,h3,h4{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;line-height:1.1}
a{color:inherit;text-decoration:none}

/* Full-width Layout */
.aap-container{width:100%;max-width:1400px;margin:0 auto;padding:0 20px}
@media(min-width:768px){.aap-container{padding:0 40px}}
@media(min-width:1200px){.aap-container{padding:0 60px}}

/* Hero */
.aap-hero{background:linear-gradient(180deg,#0A0A0A 0%,#1a1a1a 100%);padding:60px 0;text-align:center}
@media(min-width:768px){.aap-hero{padding:80px 0 100px}}
@media(min-width:1200px){.aap-hero{padding:100px 0 120px}}
.aap-badge{display:inline-block;font-family:'Oswald',sans-serif;font-size:11px;font-weight:600;letter-spacing:0.15em;background:var(--gold);color:var(--black);padding:8px 16px;margin-bottom:20px}
@media(min-width:768px){.aap-badge{font-size:12px;padding:10px 20px}}
.aap-title{font-size:clamp(42px,10vw,80px);color:var(--white);margin-bottom:12px}
.aap-title span{color:var(--gold)}
.aap-subtitle{font-size:16px;color:var(--gray4);max-width:500px;margin:0 auto 40px}
@media(min-width:768px){.aap-subtitle{font-size:18px}}

/* Price Block */
.aap-price-block{background:rgba(255,255,255,0.03);border:2px solid rgba(255,255,255,0.1);border-radius:16px;padding:24px 20px;max-width:500px;margin:0 auto 32px}
@media(min-width:768px){.aap-price-block{padding:32px;max-width:600px}}
.aap-price-row{display:flex;justify-content:space-between;align-items:center;gap:16px}
.aap-price-item{text-align:center}
.aap-price-label{display:block;font-size:10px;font-weight:600;letter-spacing:0.1em;color:var(--white);margin-bottom:4px}
@media(min-width:768px){.aap-price-label{font-size:11px}}
.aap-price-strike{font-family:'Oswald',sans-serif;font-size:24px;color:var(--gray5);text-decoration:line-through}
@media(min-width:768px){.aap-price-strike{font-size:32px}}
.aap-price-main .aap-price-label{color:var(--gold)}
.aap-price-amount{font-family:'Oswald',sans-serif;font-size:42px;font-weight:700;color:var(--gold)}
@media(min-width:768px){.aap-price-amount{font-size:56px}}
.aap-savings{display:inline-block;font-family:'Oswald',sans-serif;font-size:12px;font-weight:600;background:var(--green);color:var(--white);padding:6px 12px;border-radius:4px;margin-top:8px}
@media(min-width:768px){.aap-savings{font-size:14px}}

/* CTA Button */
.aap-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font-family:'Oswald',sans-serif;font-size:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;padding:18px 40px;border:none;border-radius:8px;cursor:pointer;transition:all 0.2s}
@media(min-width:768px){.aap-btn{font-size:18px;padding:20px 48px}}
.aap-btn-gold{background:var(--gold);color:var(--black)}
.aap-btn-gold:hover{background:#e5a800;transform:translateY(-2px)}
.aap-btn-black{background:var(--black);color:var(--white);border:2px solid var(--white)}
.aap-btn-black:hover{background:var(--gold);color:var(--black);border-color:var(--gold)}
.aap-weekly{font-size:14px;color:var(--gray4);margin-top:16px}
.aap-weekly strong{color:var(--white)}

/* What's Included */
.aap-included{background:var(--black);padding:60px 0}
@media(min-width:768px){.aap-included{padding:80px 0}}
.aap-section-title{font-size:12px;letter-spacing:0.2em;color:var(--gold);text-align:center;margin-bottom:8px}
.aap-section-heading{font-size:clamp(28px,5vw,40px);color:var(--white);text-align:center;margin-bottom:40px}
@media(min-width:768px){.aap-section-heading{margin-bottom:60px}}

/* Components Grid */
.aap-components{display:flex;flex-direction:column;gap:12px;max-width:800px;margin:0 auto}
@media(min-width:768px){.aap-components{gap:16px}}
.aap-component{display:flex;align-items:center;gap:16px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 20px;transition:border-color 0.2s}
@media(min-width:768px){.aap-component{padding:20px 24px;gap:20px}}
.aap-component:hover{border-color:var(--gold)}
.aap-component-icon{width:44px;height:44px;background:rgba(252,185,0,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
@media(min-width:768px){.aap-component-icon{width:52px;height:52px;font-size:26px}}
.aap-component-info{flex:1;min-width:0}
.aap-component-name{font-family:'Oswald',sans-serif;font-size:16px;font-weight:600;color:var(--white);text-transform:uppercase;margin-bottom:2px}
@media(min-width:768px){.aap-component-name{font-size:18px}}
.aap-component-qty{font-size:13px;color:var(--gray4)}
.aap-component-value{text-align:right;flex-shrink:0}
.aap-component-rate{display:block;font-size:12px;color:var(--gray5)}
.aap-component-total{font-family:'Oswald',sans-serif;font-size:18px;font-weight:600;color:var(--white)}
@media(min-width:768px){.aap-component-total{font-size:20px}}

/* Total Bar */
.aap-total-bar{display:flex;justify-content:space-between;align-items:center;background:var(--gold);border-radius:12px;padding:20px 24px;max-width:800px;margin:20px auto 0}
@media(min-width:768px){.aap-total-bar{padding:24px 32px;margin-top:24px}}
.aap-total-label{font-family:'Oswald',sans-serif;font-size:14px;font-weight:600;color:var(--black);letter-spacing:0.05em}
.aap-total-amount{font-family:'Oswald',sans-serif;font-size:28px;font-weight:700;color:var(--black)}
@media(min-width:768px){.aap-total-amount{font-size:32px}}

/* Year-Round Section */
.aap-yearround{background:linear-gradient(180deg,#0A0A0A 0%,#111 100%);padding:60px 0}
@media(min-width:768px){.aap-yearround{padding:80px 0}}
.aap-features{display:grid;grid-template-columns:1fr;gap:20px;max-width:1200px;margin:0 auto}
@media(min-width:600px){.aap-features{grid-template-columns:repeat(2,1fr)}}
@media(min-width:900px){.aap-features{grid-template-columns:repeat(3,1fr);gap:24px}}
.aap-feature{background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:16px;padding:24px;text-align:center}
@media(min-width:768px){.aap-feature{padding:32px}}
.aap-feature-icon{font-size:32px;margin-bottom:16px}
@media(min-width:768px){.aap-feature-icon{font-size:40px}}
.aap-feature-name{font-family:'Oswald',sans-serif;font-size:16px;font-weight:600;color:var(--white);text-transform:uppercase;margin-bottom:4px}
@media(min-width:768px){.aap-feature-name{font-size:18px}}
.aap-feature-included{font-size:12px;font-weight:600;color:var(--gold);margin-bottom:12px}
.aap-feature-desc{font-size:13px;color:var(--gray4);line-height:1.6}
@media(min-width:768px){.aap-feature-desc{font-size:14px}}

/* Compare Table */
.aap-compare{background:var(--black);padding:60px 0}
@media(min-width:768px){.aap-compare{padding:80px 0}}
.aap-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:1000px;margin:0 auto}
.aap-table{width:100%;min-width:600px;border-collapse:collapse}
.aap-table th,.aap-table td{padding:14px 16px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.06)}
@media(min-width:768px){.aap-table th,.aap-table td{padding:16px 20px}}
.aap-table th{font-family:'Oswald',sans-serif;font-size:12px;font-weight:600;letter-spacing:0.08em;color:var(--white);text-transform:uppercase}
.aap-table th:first-child{text-align:left}
.aap-table td:first-child{text-align:left;font-size:13px;font-weight:500;color:var(--white)}
@media(min-width:768px){.aap-table td:first-child{font-size:14px}}
.aap-table td{font-size:14px;color:var(--gray4)}
.aap-table .highlight{background:rgba(252,185,0,0.08)}
.aap-table .highlight td{color:var(--white)}
.aap-table .check{color:var(--green)}
.aap-table .price-row td{font-family:'Oswald',sans-serif;font-size:18px;font-weight:600;color:var(--white)}
.aap-table .price-row .highlight td{color:var(--gold)}
.aap-table .savings-row td{font-size:14px;font-weight:600;color:var(--green)}

/* FAQ */
.aap-faq{background:linear-gradient(180deg,#0A0A0A 0%,#111 100%);padding:60px 0}
@media(min-width:768px){.aap-faq{padding:80px 0}}
.aap-faq-list{max-width:700px;margin:0 auto}
.aap-faq-item{border-bottom:1px solid rgba(255,255,255,0.08)}
.aap-faq-q{width:100%;display:flex;justify-content:space-between;align-items:center;padding:20px 0;background:none;border:none;font-family:'Oswald',sans-serif;font-size:15px;font-weight:600;color:var(--white);text-transform:uppercase;text-align:left;cursor:pointer}
@media(min-width:768px){.aap-faq-q{font-size:16px;padding:24px 0}}
.aap-faq-toggle{font-size:24px;color:var(--gold);transition:transform 0.2s}
.aap-faq-item.open .aap-faq-toggle{transform:rotate(45deg)}
.aap-faq-a{max-height:0;overflow:hidden;transition:max-height 0.3s}
.aap-faq-item.open .aap-faq-a{max-height:200px}
.aap-faq-a p{padding:0 0 20px;font-size:14px;color:var(--gray4);line-height:1.7}
@media(min-width:768px){.aap-faq-a p{font-size:15px}}

/* Final CTA */
.aap-cta{background:var(--gold);padding:60px 0;text-align:center}
@media(min-width:768px){.aap-cta{padding:80px 0}}
.aap-cta-title{font-size:clamp(24px,5vw,36px);color:var(--black);margin-bottom:8px}
.aap-cta-subtitle{font-size:15px;color:rgba(0,0,0,0.6);margin-bottom:24px}
@media(min-width:768px){.aap-cta-subtitle{font-size:16px;margin-bottom:32px}}
.aap-cta .aap-btn-black{background:var(--black);color:var(--white);border:none}
.aap-cta .aap-btn-black:hover{background:#1a1a1a}
.aap-cta-price{font-size:14px;color:rgba(0,0,0,0.5);margin-top:16px}
.aap-cta-price strong{color:var(--black)}
</style>
</head>
<body style="margin: 0; padding: 0; overflow-y: scroll !important; height: auto !important; position: static !important;">
<script>
// v133.2.1: Force scroll to work
(function(){
    document.documentElement.style.cssText = 'overflow-y: scroll !important; height: auto !important; position: static !important;';
    document.body.style.cssText = 'overflow-y: scroll !important; height: auto !important; position: static !important; margin: 0; padding: 0;';
    document.body.classList.remove('modal-open', 'menu-open', 'no-scroll', 'overflow-hidden');
    document.documentElement.classList.remove('modal-open', 'menu-open', 'no-scroll', 'overflow-hidden');
})();
</script>
<div id="ptp-scroll-wrapper" style="width: 100%;">

<div class="aap-page">
    
    <!-- Hero -->
    <section class="aap-hero">
        <div class="aap-container">
            <span class="aap-badge">BEST VALUE FOR SERIOUS PLAYERS</span>
            <h1 class="aap-title">ALL-ACCESS <span>PASS</span></h1>
            <p class="aap-subtitle">The complete PTP experience — camps, training, mentorship</p>
            
            <div class="aap-price-block">
                <div class="aap-price-row">
                    <div class="aap-price-item">
                        <span class="aap-price-label">TOTAL VALUE</span>
                        <span class="aap-price-strike">$<?php echo number_format($membership['total_value']); ?></span>
                    </div>
                    <div class="aap-price-item aap-price-main">
                        <span class="aap-price-label">YOUR PRICE</span>
                        <span class="aap-price-amount">$<?php echo number_format($membership['price']); ?></span>
                    </div>
                    <div class="aap-price-item">
                        <span class="aap-savings">SAVE $<?php echo number_format($membership['total_value'] - $membership['price']); ?></span>
                    </div>
                </div>
            </div>
            
            <a href="#pricing" class="aap-btn aap-btn-gold">GET YOUR PASS →</a>
            <p class="aap-weekly">That's just <strong>$<?php echo $membership['weekly_cost']; ?>/week</strong> for elite development</p>
        </div>
    </section>
    
    <!-- What's Included -->
    <section class="aap-included">
        <div class="aap-container">
            <p class="aap-section-title">WHAT'S INCLUDED</p>
            <h2 class="aap-section-heading">Everything You Need</h2>
            
            <div class="aap-components">
                <?php foreach ($components as $key => $component): ?>
                <div class="aap-component">
                    <div class="aap-component-icon"><?php echo $component['icon']; ?></div>
                    <div class="aap-component-info">
                        <h3 class="aap-component-name"><?php echo esc_html($component['name']); ?></h3>
                        <span class="aap-component-qty"><?php echo $component['quantity']; ?> <?php echo $component['unit']; ?><?php echo $component['quantity'] > 1 ? 's' : ''; ?> included</span>
                    </div>
                    <div class="aap-component-value">
                        <span class="aap-component-rate">$<?php echo $component['rate']; ?> each</span>
                        <span class="aap-component-total">$<?php echo number_format($component['value']); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="aap-total-bar">
                <span class="aap-total-label">TOTAL VALUE</span>
                <span class="aap-total-amount">$<?php echo number_format($membership['total_value']); ?></span>
            </div>
        </div>
    </section>
    
    <!-- Year-Round Development -->
    <section class="aap-yearround">
        <div class="aap-container">
            <p class="aap-section-title">YEAR-ROUND DEVELOPMENT</p>
            <h2 class="aap-section-heading">Train Like The Pros</h2>
            
            <div class="aap-features">
                <div class="aap-feature">
                    <div class="aap-feature-icon">⚽</div>
                    <h3 class="aap-feature-name">Summer Camps</h3>
                    <p class="aap-feature-included">6 Weeks Included</p>
                    <p class="aap-feature-desc">Full-day camps with D1 college coaches. Pick any 6 weeks all summer.</p>
                </div>
                <div class="aap-feature">
                    <div class="aap-feature-icon">🎯</div>
                    <h3 class="aap-feature-name">Private Training</h3>
                    <p class="aap-feature-included">12 Sessions Included</p>
                    <p class="aap-feature-desc">1-on-1 sessions with your dedicated coach. Focus on your specific needs.</p>
                </div>
                <div class="aap-feature">
                    <div class="aap-feature-icon">🔥</div>
                    <h3 class="aap-feature-name">Skills Clinics</h3>
                    <p class="aap-feature-included">6 Clinics Included</p>
                    <p class="aap-feature-desc">Specialized sessions: Finishing, defending, ball mastery, speed & agility.</p>
                </div>
                <div class="aap-feature">
                    <div class="aap-feature-icon">📹</div>
                    <h3 class="aap-feature-name">Video Analysis</h3>
                    <p class="aap-feature-included">4 Hours Included</p>
                    <p class="aap-feature-desc">Break down your game film with pro-level feedback and action plans.</p>
                </div>
                <div class="aap-feature">
                    <div class="aap-feature-icon">💬</div>
                    <h3 class="aap-feature-name">Mentorship</h3>
                    <p class="aap-feature-included">4 Hours Included</p>
                    <p class="aap-feature-desc">Career guidance from players who've been there. College prep, mindset, nutrition.</p>
                </div>
                <div class="aap-feature">
                    <div class="aap-feature-icon">⭐</div>
                    <h3 class="aap-feature-name">Priority Access</h3>
                    <p class="aap-feature-included">All Year</p>
                    <p class="aap-feature-desc">Early registration for camps, first pick of time slots, VIP event access.</p>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Compare Options -->
    <section class="aap-compare" id="pricing">
        <div class="aap-container">
            <p class="aap-section-title">COMPARE OPTIONS</p>
            <h2 class="aap-section-heading">Choose Your Path</h2>
            
            <div class="aap-table-wrap">
                <table class="aap-table">
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
                            <td class="highlight">6</td>
                        </tr>
                        <tr>
                            <td>Private Sessions</td>
                            <td>—</td>
                            <td>—</td>
                            <td class="highlight">12</td>
                        </tr>
                        <tr>
                            <td>Skills Clinics</td>
                            <td>—</td>
                            <td>—</td>
                            <td class="highlight">6</td>
                        </tr>
                        <tr>
                            <td>Video Analysis</td>
                            <td>—</td>
                            <td>—</td>
                            <td class="highlight">4 hrs</td>
                        </tr>
                        <tr>
                            <td>Mentorship</td>
                            <td>—</td>
                            <td>—</td>
                            <td class="highlight">4 hrs</td>
                        </tr>
                        <tr>
                            <td>Priority Booking</td>
                            <td>—</td>
                            <td>—</td>
                            <td class="highlight"><span class="check">✓</span></td>
                        </tr>
                        <tr class="price-row">
                            <td>Price</td>
                            <td>$525</td>
                            <td>$1,260</td>
                            <td class="highlight">$<?php echo number_format($membership['price']); ?></td>
                        </tr>
                        <tr class="savings-row">
                            <td>Savings</td>
                            <td>—</td>
                            <td>$315 (20%)</td>
                            <td class="highlight">$<?php echo number_format($membership['total_value'] - $membership['price']); ?> (<?php echo $membership['savings_percent']; ?>%)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    
    <!-- FAQ -->
    <section class="aap-faq">
        <div class="aap-container">
            <p class="aap-section-title">QUESTIONS</p>
            <h2 class="aap-section-heading">Got Questions?</h2>
            
            <div class="aap-faq-list">
                <div class="aap-faq-item">
                    <button class="aap-faq-q"><span>When can I use my sessions?</span><span class="aap-faq-toggle">+</span></button>
                    <div class="aap-faq-a"><p>Your pass is valid for 12 months from purchase. Use your camps during summer (June-August) and schedule private training, clinics, and mentorship year-round.</p></div>
                </div>
                <div class="aap-faq-item">
                    <button class="aap-faq-q"><span>Can I share with siblings?</span><span class="aap-faq-toggle">+</span></button>
                    <div class="aap-faq-a"><p>The pass is for one player. However, camps and clinics can be transferred to a sibling if schedules conflict. Private sessions are tied to the registered player.</p></div>
                </div>
                <div class="aap-faq-item">
                    <button class="aap-faq-q"><span>What if I can't use all 6 camps?</span><span class="aap-faq-toggle">+</span></button>
                    <div class="aap-faq-a"><p>Unused camp weeks can be converted to additional private sessions or carried over to the following summer (one-time rollover).</p></div>
                </div>
                <div class="aap-faq-item">
                    <button class="aap-faq-q"><span>Is there a payment plan?</span><span class="aap-faq-toggle">+</span></button>
                    <div class="aap-faq-a"><p>Yes! We offer 3-month and 6-month payment plans through Affirm at checkout. Spread $4,000 into manageable monthly payments.</p></div>
                </div>
                <div class="aap-faq-item">
                    <button class="aap-faq-q"><span>Who are the coaches?</span><span class="aap-faq-toggle">+</span></button>
                    <div class="aap-faq-a"><p>All PTP coaches are current NCAA Division 1 athletes and elite college players. They don't just coach — they play alongside your child.</p></div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Final CTA -->
    <section class="aap-cta">
        <div class="aap-container">
            <h2 class="aap-cta-title">Ready to Go All-Access?</h2>
            <p class="aap-cta-subtitle">Join 200+ families who chose the complete PTP experience</p>
            <a href="<?php echo home_url('/checkout/?add-to-cart=' . ($membership['product_id'] ?? '')); ?>" class="aap-btn aap-btn-black">GET ALL-ACCESS PASS →</a>
            <p class="aap-cta-price"><strong>$<?php echo number_format($membership['price']); ?></strong> — Save $<?php echo number_format($membership['total_value'] - $membership['price']); ?> (<?php echo $membership['savings_percent']; ?>% off)</p>
        </div>
    </section>
    
</div>

<script>
document.querySelectorAll('.aap-faq-q').forEach(function(btn) {
    btn.onclick = function() {
        var item = this.parentElement;
        item.classList.toggle('open');
    };
});
</script>

</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
