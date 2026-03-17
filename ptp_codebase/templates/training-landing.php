<?php
/**
 * Training Landing Page v187.4 — MasterClass Design System
 * 
 * Matches trainer-profile-v177.php and other training platform pages.
 * Uses ptp-masterclass.css design tokens (light theme).
 * Loaded via get_header() / get_footer() from serve_training_fullpage().
 */
defined('ABSPATH') || exit;

global $wpdb;

// v227: Use query cache for table checks and trainer data
$trainer_count = class_exists('PTP_Query_Cache') 
    ? PTP_Query_Cache::get_trainer_count()
    : get_transient('ptp_active_trainer_count');
if ($trainer_count === false || $trainer_count === 0) {
    $table_ok = class_exists('PTP_Query_Cache') ? PTP_Query_Cache::table_exists('ptp_trainers') : $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_trainers'");
    $trainer_count = $table_ok
        ? ($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'") ?: 5)
        : 5;
}

$featured = get_transient('ptp_featured_trainers_landing');
if ($featured === false || empty($featured)) {
    $table_ok = class_exists('PTP_Query_Cache') ? PTP_Query_Cache::table_exists('ptp_trainers') : $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_trainers'");
    if ($table_ok) {
        $featured = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active' ORDER BY is_featured DESC, average_rating DESC, total_sessions DESC LIMIT 6");
        if (!empty($featured)) {
            set_transient('ptp_featured_trainers_landing', $featured, 10 * MINUTE_IN_SECONDS);
        }
    }
    if (empty($featured)) $featured = array();
}
$level_labels = array('pro'=>'PRO','college_d1'=>'NCAA D1','college_d2'=>'NCAA D2','college_d3'=>'NCAA D3','academy'=>'ACADEMY','semi_pro'=>'SEMI-PRO');
$is_logged_in = is_user_logged_in();
$home = home_url('/');
$find_url = home_url('/find-trainers/');
// v228: Free session promo removed
$apply_url = home_url('/apply/');
$login_url = home_url('/login/');
$camps_url = home_url('/summer-camps/');
$dash_url = $is_logged_in ? home_url('/parent-dashboard/') : $login_url;
?>

<link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-masterclass.css">
<style>
/* ============================================
   TRAINING LANDING v187.4 — MASTERCLASS
   ============================================ */

/* Full-width bust out of Astra wrappers */
body.tl-body .site-content,
body.tl-body .entry-content,
body.tl-body .post-content,
body.tl-body .page-content,
body.tl-body main, body.tl-body main.site-main,
body.tl-body article, body.tl-body #content,
body.tl-body #main, body.tl-body #primary,
body.tl-body .content-area, body.tl-body .site-main,
body.tl-body #page, body.tl-body .hentry,
body.tl-body .type-page, body.tl-body .ast-container {
    width: 100% !important;
    max-width: 100% !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
}

/* Hide Astra/Elementor chrome — PTP owns the page */
@media (max-width: 1023px) {
    body.tl-body .site-header:not(.ph),
    body.tl-body .elementor-location-header,
    body.tl-body [data-elementor-type="header"],
    body.tl-body #masthead,
    body.tl-body .site-footer:not(.ptp-footer),
    body.tl-body .elementor-location-footer,
    body.tl-body [data-elementor-type="footer"] {
        display: none !important;
    }
}
body.tl-body .ptp-header,
body.tl-body .ptp-bottom-nav,
body.tl-body #ptpHeader { display: none !important; }

/* Protect PTP header */
body.tl-body header.ph { overflow: visible !important; background: #0A0A0A !important; z-index: 200 !important; }
body.tl-body header.ph nav.ph-m { overflow-y: auto !important; z-index: 190 !important; }

.tl {
    max-width: 100%;
    background: var(--mc-bg, #ffffff);
    color: var(--mc-text, #1a1a1a);
    font-family: var(--mc-font-sans, 'Inter', sans-serif);
    -webkit-font-smoothing: antialiased;
}

/* ---- HERO ---- */
.tl-hero {
    position: relative;
    min-height: 92vh; min-height: 92dvh;
    display: flex;
    align-items: flex-end;
    overflow: hidden;
    background: #0a0a0a;
}
.tl-hero-bg {
    position: absolute; inset: 0;
    background: url('https://ptpsummercamps.com/wp-content/uploads/2024/09/IMG_8693-scaled.jpg') center 35%/cover no-repeat;
    filter: brightness(0.4) saturate(1.1);
}
.tl-hero::after {
    content: ''; position: absolute; inset: 0; z-index: 1; pointer-events: none;
    background: linear-gradient(0deg, #0a0a0a 0%, rgba(10,10,10,0.4) 50%, transparent 80%);
}
.tl-hero-inner {
    position: relative; z-index: 2;
    width: 100%; max-width: 1200px;
    margin: 0 auto;
    padding: 0 24px 64px;
}
.tl-hero-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--mc-gold, #FCB900); color: #0a0a0a;
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 10px; font-weight: 700;
    letter-spacing: 0.12em; text-transform: uppercase;
    padding: 6px 14px; margin-bottom: 20px;
}
.tl-hero h1 {
    font-family: var(--mc-font-serif, 'Playfair Display', serif);
    font-size: clamp(36px, 8vw, 68px);
    font-weight: 500; line-height: 1.05;
    color: #fff; letter-spacing: -0.02em;
    margin-bottom: 20px;
}
.tl-hero h1 em { font-style: italic; color: var(--mc-gold, #FCB900); }
.tl-hero-sub {
    font-size: clamp(15px, 2vw, 18px);
    color: rgba(255,255,255,0.7);
    line-height: 1.6; max-width: 540px;
    margin-bottom: 32px;
}
.tl-hero-cta {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--mc-gold, #FCB900); color: #0a0a0a;
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 14px; font-weight: 700;
    letter-spacing: 0.08em; text-transform: uppercase;
    padding: 16px 36px; text-decoration: none;
    transition: all 0.3s;
}
.tl-hero-cta:hover { background: var(--mc-gold-hover, #e5a800); transform: translateY(-2px); box-shadow: 0 12px 40px rgba(252,185,0,0.3); }
.tl-hero-cta svg { width: 16px; height: 16px; }

/* ---- STATS BAR ---- */
.tl-proof {
    background: var(--mc-bg-alt, #f8f8f8);
    border-top: 1px solid var(--mc-border, #e5e5e5);
    border-bottom: 1px solid var(--mc-border, #e5e5e5);
    padding: 28px 24px;
}
.tl-proof-inner {
    max-width: 1200px; margin: 0 auto;
    display: flex; justify-content: center;
    align-items: center; gap: 48px; flex-wrap: wrap;
}
.tl-proof-item { text-align: center; }
.tl-proof-num {
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 28px; font-weight: 700;
    color: var(--mc-text, #1a1a1a); line-height: 1;
}
.tl-proof-num.gold { color: var(--mc-gold, #FCB900); }
.tl-proof-lbl {
    font-size: 10px; text-transform: uppercase;
    letter-spacing: 0.08em; color: var(--mc-text-muted, #999);
    margin-top: 4px;
}

/* ---- PROMO BANNER ---- */
.tl-promo {
    max-width: 1200px; margin: 0 auto;
    padding: 40px 24px 0;
}
.tl-promo-card {
    background: var(--mc-gold-dim, rgba(252,185,0,0.1));
    border: 1px solid var(--mc-gold, #FCB900);
    padding: 32px; text-align: center;
    border-radius: 8px;
}
.tl-promo-eyebrow {
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 10px; font-weight: 700;
    letter-spacing: 0.15em; text-transform: uppercase;
    color: var(--mc-gold, #FCB900); margin-bottom: 8px;
}
.tl-promo-card h2 {
    font-family: var(--mc-font-serif, 'Playfair Display', serif);
    font-size: clamp(24px, 4vw, 32px); font-weight: 500;
    color: var(--mc-text, #1a1a1a); margin-bottom: 8px;
}
.tl-promo-card h2 em { font-style: italic; color: var(--mc-gold, #FCB900); }
.tl-promo-card p { font-size: 14px; color: var(--mc-text-secondary, #666); margin-bottom: 20px; }
.tl-promo-btn {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--mc-gold, #FCB900); color: #0a0a0a;
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 13px; font-weight: 700;
    letter-spacing: 0.08em; text-transform: uppercase;
    padding: 14px 28px; text-decoration: none;
    transition: all 0.3s;
}
.tl-promo-btn:hover { background: var(--mc-gold-hover, #e5a800); }
.tl-promo-btn svg { width: 14px; height: 14px; }

/* ---- SECTIONS ---- */
.tl-section {
    padding: 80px 24px;
    max-width: 1200px;
    margin: 0 auto;
}
.tl-section-alt {
    background: var(--mc-bg-alt, #f8f8f8);
    padding: 80px 24px;
}
.tl-section-alt .tl-section-wrap {
    max-width: 1200px; margin: 0 auto;
}
.tl-section-header {
    text-align: center;
    max-width: 700px;
    margin: 0 auto 48px;
}
.tl-section-eyebrow {
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 11px; font-weight: 600;
    letter-spacing: 0.15em; text-transform: uppercase;
    color: var(--mc-gold, #FCB900); margin-bottom: 12px;
}
.tl-section-title {
    font-family: var(--mc-font-serif, 'Playfair Display', serif);
    font-size: clamp(26px, 5vw, 40px);
    font-weight: 500; line-height: 1.15;
    color: var(--mc-text, #1a1a1a);
}
.tl-section-title em { font-style: italic; color: var(--mc-gold, #FCB900); }

/* ---- FEATURES ---- */
.tl-features {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
}
.tl-feature {
    background: var(--mc-bg-card, #fff);
    border: 1px solid var(--mc-border, #e5e5e5);
    padding: 32px; border-radius: 8px;
    transition: 0.3s ease;
}
.tl-feature:hover { border-color: var(--mc-gold, #FCB900); box-shadow: var(--mc-shadow-sm, 0 2px 12px rgba(0,0,0,0.06)); }
.tl-feature-icon {
    width: 48px; height: 48px; border-radius: 50%;
    background: var(--mc-gold-dim, rgba(252,185,0,0.1));
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 20px;
}
.tl-feature-icon svg { width: 22px; height: 22px; fill: none; stroke: var(--mc-gold, #FCB900); stroke-width: 1.5; }
.tl-feature h3 {
    font-family: var(--mc-font-serif, 'Playfair Display', serif);
    font-size: 20px; font-weight: 500;
    color: var(--mc-text, #1a1a1a); margin-bottom: 8px;
}
.tl-feature p { font-size: 14px; color: var(--mc-text-secondary, #666); line-height: 1.7; }

/* ---- TRAINERS GRID ---- */
.tl-trainers {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 20px;
}
@media (min-width: 768px) { .tl-trainers { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 1024px) { .tl-trainers { grid-template-columns: repeat(3, 1fr); gap: 24px; } }

.tl-trainer {
    position: relative;
    aspect-ratio: 3/4;
    border-radius: 8px;
    overflow: hidden;
    display: block;
    text-decoration: none;
}
.tl-trainer img {
    width: 100%; height: 100%;
    object-fit: cover;
    object-position: center top;
    transition: transform 0.4s;
    display: block;
    min-height: 100%;
    min-width: 100%;
}
.tl-trainer:hover img { transform: scale(1.05); }
.tl-trainer-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(0deg, rgba(0,0,0,0.8) 0%, transparent 50%);
}
.tl-trainer-info {
    position: absolute; bottom: 0; left: 0; right: 0;
    padding: 20px; color: #fff;
}
.tl-trainer-level {
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 9px; font-weight: 700;
    letter-spacing: 0.12em; text-transform: uppercase;
    color: var(--mc-gold, #FCB900); margin-bottom: 4px;
}
.tl-trainer-name {
    font-family: var(--mc-font-serif, 'Playfair Display', serif);
    font-size: 18px; font-weight: 500; line-height: 1.2;
}
.tl-trainer-meta {
    font-size: 12px; color: rgba(255,255,255,0.6); margin-top: 2px;
}
.tl-trainers-cta {
    display: inline-flex; align-items: center; gap: 8px;
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 14px; font-weight: 700;
    letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--mc-text, #1a1a1a);
    text-decoration: none;
    border: 2px solid var(--mc-border, #e5e5e5);
    padding: 16px 32px;
    transition: all 0.3s;
}
.tl-trainers-cta:hover { border-color: var(--mc-gold, #FCB900); color: var(--mc-gold, #FCB900); }
.tl-trainers-cta svg { width: 16px; height: 16px; }

/* ---- HOW IT WORKS ---- */
.tl-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 32px; }
.tl-step { display: flex; gap: 20px; align-items: flex-start; }
.tl-step-num {
    flex-shrink: 0;
    width: 48px; height: 48px;
    background: var(--mc-gold, #FCB900); color: #0a0a0a;
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 18px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
}
.tl-step h3 {
    font-family: var(--mc-font-serif, 'Playfair Display', serif);
    font-size: 18px; font-weight: 500;
    color: var(--mc-text, #1a1a1a); margin-bottom: 6px;
}
.tl-step p { font-size: 14px; color: var(--mc-text-secondary, #666); line-height: 1.6; }

/* ---- FINAL CTA ---- */
.tl-final {
    text-align: center;
    padding: 80px 24px;
    background: #0a0a0a; color: #fff;
}
.tl-final-eyebrow {
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 11px; font-weight: 600;
    letter-spacing: 0.15em; text-transform: uppercase;
    color: var(--mc-gold, #FCB900); margin-bottom: 16px;
}
.tl-final h2 {
    font-family: var(--mc-font-serif, 'Playfair Display', serif);
    font-size: clamp(28px, 5vw, 44px);
    font-weight: 500; margin-bottom: 12px;
}
.tl-final p {
    font-size: 16px; color: rgba(255,255,255,0.6);
    max-width: 500px; margin: 0 auto 32px;
}
.tl-final-btn {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--mc-gold, #FCB900); color: #0a0a0a;
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 14px; font-weight: 700;
    letter-spacing: 0.08em; text-transform: uppercase;
    padding: 16px 36px; text-decoration: none;
    transition: all 0.3s;
}
.tl-final-btn:hover { background: var(--mc-gold-hover, #e5a800); transform: translateY(-2px); box-shadow: 0 12px 40px rgba(252,185,0,0.3); }
.tl-final-btn svg { width: 16px; height: 16px; }
.tl-final-note { margin-top: 16px; font-size: 13px; color: rgba(255,255,255,0.4); }

/* ---- FOOTER ---- */
.tl-footer {
    background: #0a0a0a; color: rgba(255,255,255,0.5);
    border-top: 1px solid rgba(255,255,255,0.08);
    padding: 40px 24px; text-align: center;
}
.tl-footer-logo {
    font-family: var(--mc-font-display, 'Oswald', sans-serif);
    font-size: 20px; font-weight: 700;
    letter-spacing: 0.08em; color: var(--mc-gold, #FCB900);
    margin-bottom: 16px;
}
.tl-footer-links { display: flex; justify-content: center; gap: 24px; flex-wrap: wrap; margin-bottom: 20px; }
.tl-footer-links a {
    font-size: 13px; color: rgba(255,255,255,0.5);
    text-decoration: none; transition: color 0.2s;
}
.tl-footer-links a:hover { color: var(--mc-gold, #FCB900); }
.tl-footer-copy { font-size: 12px; }

/* ---- REVEAL ANIMATION ---- */
.tl-reveal { opacity: 0; transform: translateY(20px); transition: opacity 0.6s ease, transform 0.6s ease; }
.tl-reveal.visible { opacity: 1; transform: translateY(0); }

/* ---- MOBILE ---- */
@media (max-width: 767px) {
    .tl-hero { min-height: 85vh; min-height: 85dvh; }
    .tl-hero-inner { padding: 0 16px 48px; }
    .tl-section, .tl-section-alt { padding: 56px 16px; }
    .tl-proof-inner { gap: 32px; }
    .tl-proof-num { font-size: 24px; }
    .tl-features { grid-template-columns: 1fr; }
    .tl-trainers { grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .tl-trainer-name { font-size: 15px; }
    .tl-trainer-info { padding: 14px; }
    .tl-steps { grid-template-columns: 1fr; gap: 24px; }
}

/* Hide chat widgets on mobile */
@media (max-width: 1023px) {
    #tidio-chat, #crisp-chatbox, .crisp-client, #intercom-container, #intercom-frame,
    .intercom-lightweight-app, #hubspot-messages-iframe-container, #tawk-bubble-container,
    #tawkchat-container, .tawk-min-container, #drift-widget-container, #fc_frame, #fc_widget,
    .fb_dialog, .fb_iframe_widget, #chat-widget-container, .joinchat, .wp-social-chat-container,
    #olark-wrapper, [id*="chat-widget"], [class*="chat-widget"], [class*="chat-bubble"],
    iframe[title*="chat" i], iframe[title*="messenger" i] {
        display: none !important;
    }
}
/* ---- BUTTON CLICKABILITY FIXES v194 ---- */
.tl-hero-cta, .tl-promo-btn, .tl-final-btn, .tl-trainers-cta, .tl-trainer {
    cursor: pointer; position: relative; z-index: 2;
}
.tl-promo-card { position: relative; z-index: 2; }
.tl-final .tl-reveal { position: relative; z-index: 2; }
.tl-footer a { cursor: pointer; }
/* Failsafe: if JS doesn't load, still show content after 2s */
@keyframes tl-reveal-fallback { to { opacity: 1; transform: none; } }
.tl-reveal { animation: tl-reveal-fallback 0.6s ease 2s forwards; }
.tl-reveal.visible { animation: none; }
</style>

<script>document.body.classList.add('tl-body', 'mc-light');</script>

<div class="tl mc-light">

<!-- HERO -->
<section class="tl-hero">
    <div class="tl-hero-bg"></div>
    <div class="tl-hero-inner tl-reveal">
        <div class="tl-hero-badge">Teaching What Team Coaches Don't</div>
        <h1>Private Soccer<br>Training With <em>Real Pros</em></h1>
        <p class="tl-hero-sub">1-on-1 sessions with NCAA D1 athletes and elite college players. Background checked, SafeSport certified. Trusted by 500+ families across 5 states.</p>
        <a href="<?php echo esc_url($find_url); ?>" class="tl-hero-cta">
            Find Your Trainer
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
    </div>
</section>

<!-- SOCIAL PROOF BAR -->
<div class="tl-proof">
    <div class="tl-proof-inner">
        <div class="tl-proof-item">
            <div class="tl-proof-num gold"><?php echo esc_html($trainer_count); ?>+</div>
            <div class="tl-proof-lbl">Pro Trainers</div>
        </div>
        <div class="tl-proof-item">
            <div class="tl-proof-num">5</div>
            <div class="tl-proof-lbl">States</div>
        </div>
        <div class="tl-proof-item">
            <div class="tl-proof-num">4.9</div>
            <div class="tl-proof-lbl">Avg Rating</div>
        </div>
        <div class="tl-proof-item">
            <div class="tl-proof-num">500+</div>
            <div class="tl-proof-lbl">Families</div>
        </div>
    </div>
</div>

<!-- WHY PTP -->
<section class="tl-section">
    <div class="tl-section-header tl-reveal">
        <div class="tl-section-eyebrow">The PTP Difference</div>
        <h2 class="tl-section-title">Why Choose <em>PTP</em></h2>
    </div>
    <div class="tl-features tl-reveal">
        <div class="tl-feature">
            <div class="tl-feature-icon">
                <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <h3>Verified Pros Only</h3>
            <p>Every trainer is background checked and SafeSport certified. NCAA D1 athletes and elite college players — not random coaching students.</p>
        </div>
        <div class="tl-feature">
            <div class="tl-feature-icon">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            </div>
            <h3>Flexible Scheduling</h3>
            <p>Book sessions that fit your life. Morning, evening, weekends. Cancel anytime. No contracts ever.</p>
        </div>
        <div class="tl-feature">
            <div class="tl-feature-icon">
                <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </div>
            <h3>Real Results</h3>
            <p>Individual skills that team coaches don't have time to teach. 4.9-star average from 500+ families across 5 states.</p>
        </div>
    </div>
</section>

<!-- TRAINERS -->
<?php if (!empty($featured)): ?>
<div class="tl-section-alt">
    <div class="tl-section-wrap">
        <div class="tl-section-header tl-reveal">
            <div class="tl-section-eyebrow">Meet Your Coaches</div>
            <h2 class="tl-section-title">Train With <em>The Best</em></h2>
        </div>
        <div class="tl-trainers tl-reveal">
            <?php foreach ($featured as $t):
                $photo = $t->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($t->display_name) . '&size=400&background=FCB900&color=0A0A0A&bold=true&format=svg';
                $level = $level_labels[$t->playing_level] ?? 'TRAINER';
                $slug = $t->slug ?: sanitize_title($t->display_name);
                $school = !empty($t->college) ? $t->college : (!empty($t->team) ? $t->team : '');
            ?>
            <a href="<?php echo esc_url(home_url('/trainer/' . $slug . '/')); ?>" class="tl-trainer">
                <img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($t->display_name); ?>" loading="lazy">
                <div class="tl-trainer-overlay"></div>
                <div class="tl-trainer-info">
                    <div class="tl-trainer-level"><?php echo esc_html($level); ?></div>
                    <div class="tl-trainer-name"><?php echo esc_html($t->display_name); ?></div>
                    <?php if ($school): ?>
                    <div class="tl-trainer-meta"><?php echo esc_html($school); ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <div style="text-align:center;margin-top:40px;">
            <a href="<?php echo esc_url($find_url); ?>" class="tl-trainers-cta tl-reveal">
                View All Trainers
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- HOW IT WORKS -->
<section class="tl-section">
    <div class="tl-section-header tl-reveal">
        <div class="tl-section-eyebrow">Simple Process</div>
        <h2 class="tl-section-title">How It <em>Works</em></h2>
    </div>
    <div class="tl-steps tl-reveal">
        <div class="tl-step">
            <div class="tl-step-num">1</div>
            <div>
                <h3>Browse Trainers</h3>
                <p>Search by location. View profiles, credentials, and reviews from other families in your area.</p>
            </div>
        </div>
        <div class="tl-step">
            <div class="tl-step-num">2</div>
            <div>
                <h3>Book Online</h3>
                <p>Pick your date, time, and location. Pay securely through Stripe. No commitment — single sessions or packs.</p>
            </div>
        </div>
        <div class="tl-step">
            <div class="tl-step-num">3</div>
            <div>
                <h3>Train &amp; Improve</h3>
                <p>Meet your trainer at the field. Get personalized 1-on-1 coaching tailored to your player's goals.</p>
            </div>
        </div>
    </div>
</section>

<!-- ONLINE MENTORSHIP -->
<?php if (class_exists('PTP_Mentorship')): ?>
<section class="tl-section" style="padding:56px 20px;background:#0A0A0A">
    <div style="max-width:720px;margin:0 auto;text-align:center">
        <div style="font-family:'Oswald',sans-serif;font-size:11px;letter-spacing:3px;text-transform:uppercase;color:#FCB900;margin-bottom:12px" class="tl-reveal">Beyond The Field</div>
        <h2 style="font-family:'Oswald',sans-serif;font-size:clamp(28px,5vw,40px);font-weight:700;text-transform:uppercase;color:#fff;line-height:1.1;margin-bottom:16px" class="tl-reveal">Online <span style="color:#FCB900">Mentorship</span></h2>
        <p style="font-size:15px;color:#A3A3A3;line-height:1.7;max-width:540px;margin:0 auto 32px" class="tl-reveal">Weekly video calls, film review, goal tracking, and a real relationship with your coach — built over months, not just one session.</p>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;max-width:480px;margin:0 auto 32px" class="tl-reveal">
            <?php
            $m_pkgs = array();
            $_ml_src = class_exists('PTP_Mentorship') && defined('PTP_Mentorship::PACKAGES') ? PTP_Mentorship::PACKAGES : array();
            foreach (array('kickstart','development','elite') as $_mk) {
                $m_pkgs[$_mk] = array(
                    'label'    => $_ml_src[$_mk]['name'] ?? ucfirst($_mk),
                    'price'    => $_ml_src[$_mk]['per_session_display'] ?? array('kickstart'=>49,'development'=>69,'elite'=>89)[$_mk],
                    'sessions' => $_ml_src[$_mk]['sessions'] ?? array('kickstart'=>12,'development'=>24,'elite'=>36)[$_mk],
                );
            }
            foreach ($m_pkgs as $mk => $mp):
                $is_dev = ($mk === 'development');
            ?>
            <div style="background:<?php echo $is_dev ? 'rgba(252,185,0,0.12)' : 'rgba(255,255,255,0.06)'; ?>;border:1px solid <?php echo $is_dev ? 'rgba(252,185,0,0.4)' : 'rgba(255,255,255,0.1)'; ?>;border-radius:12px;padding:16px 10px;text-align:center">
                <div style="font-family:'Oswald',sans-serif;font-size:10px;text-transform:uppercase;color:<?php echo $is_dev ? '#FCB900' : '#737373'; ?>;margin-bottom:4px"><?php echo esc_html($mp['label']); ?></div>
                <div style="font-family:'Oswald',sans-serif;font-size:26px;font-weight:700;color:<?php echo $is_dev ? '#FCB900' : '#fff'; ?>;line-height:1">$<?php echo $mp['price']; ?></div>
                <div style="font-size:11px;color:#737373;margin-top:4px">/wk &middot; <?php echo $mp['sessions']; ?> sessions</div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin-bottom:32px" class="tl-reveal">
            <?php foreach (array('Weekly video call','Film review','Goal tracking','1-on-1 feedback','Custom training plan') as $feat): ?>
            <div style="background:rgba(255,255,255,0.07);border-radius:8px;padding:7px 14px;font-size:12px;color:#D4D4D4;display:flex;align-items:center;gap:6px">
                <span style="color:#FCB900">&#10003;</span> <?php echo esc_html($feat); ?>
            </div>
            <?php endforeach; ?>
        </div>

        <a href="<?php echo esc_url($find_url); ?>" style="display:inline-block;padding:16px 40px;background:#FCB900;color:#0A0A0A;font-family:'Oswald',sans-serif;font-size:15px;font-weight:700;text-transform:uppercase;border-radius:10px;text-decoration:none;letter-spacing:.5px;transition:all .2s" class="tl-reveal">
            Find Your Mentor &rarr;
        </a>
        <div style="font-size:12px;color:#525252;margin-top:12px" class="tl-reveal">Free intro call with every coach — no payment required</div>
    </div>
</section>
<?php endif; ?>

<!-- FINAL CTA -->
<section class="tl-final">
    <div class="tl-reveal">
        <div class="tl-final-eyebrow">Start Today</div>
        <h2>Ready to Level Up?</h2>
        <p>Join 500+ families who trust PTP for private soccer training with real pros.</p>
        <a href="<?php echo esc_url($find_url); ?>" class="tl-final-btn">
            Find Your Trainer
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
        <div class="tl-final-note"><a href="<?php echo esc_url(home_url('/mentorship/')); ?>" style="color:var(--mc-gold,#FCB900);text-decoration:underline;">1-on-1 mentorship</a> — weekly sessions with your coach</div>
    </div>
</section>

<!-- FOOTER -->
<footer class="tl-footer ptp-footer">
    <div class="tl-footer-logo">PTP SOCCER</div>
    <div class="tl-footer-links">
        <a href="<?php echo esc_url($find_url); ?>">Find Trainers</a>
        <a href="<?php echo esc_url(home_url('/mentorship/')); ?>">Mentorship</a>
        <a href="<?php echo esc_url($find_url); ?>">Mentorship</a>
        <a href="<?php echo esc_url($camps_url); ?>">Summer Camps</a>
        <a href="<?php echo esc_url($apply_url); ?>">Become a Coach</a>
        <a href="<?php echo esc_url($login_url); ?>">Sign In</a>
    </div>
    <div class="tl-footer-copy">&copy; <?php echo date('Y'); ?> Players Teaching Players. All rights reserved.</div>
</footer>

</div><!-- /.tl -->

<script>
(function(){
    // Reveal animations
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function(entries) {
            entries.forEach(function(e) { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
        document.querySelectorAll('.tl-reveal').forEach(function(el) { io.observe(el); });
    } else {
        document.querySelectorAll('.tl-reveal').forEach(function(el) { el.classList.add('visible'); });
    }
    // Force light mode
    document.body.classList.remove('mc-dark');
    document.body.classList.add('mc-light');
})();
</script>
