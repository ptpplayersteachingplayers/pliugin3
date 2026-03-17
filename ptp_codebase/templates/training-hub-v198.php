<?php
/**
 * Training Hub v198 — MasterClass Design
 * 
 * Main landing page for /training — the private training homepage.
 * For existing and returning clients to book sessions, browse trainers,
 * and understand PTP's value.
 * 
 * Design: MasterClass cinematic system (Playfair Display / Oswald / Inter)
 * Nav: Floating transparent overlay on hero (matches trainer profile)
 * Mobile: 44px touch targets, safe areas, iOS zoom prevention
 * 
 * @since 198.0
 */
defined('ABSPATH') || exit;

global $wpdb;

// Get active trainers
$trainers = array();
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_trainers'");
if ($table_exists) {
    $trainers = $wpdb->get_results("
        SELECT t.id, t.display_name, t.slug, t.photo_url, t.city, t.state,
               t.hourly_rate, t.playing_level, t.bio, t.headline, t.specialties,
               t.is_featured, t.position, t.college, t.team,
               COALESCE(t.average_rating, 5.0) as avg_rating,
               COALESCE(t.review_count, 0) as reviews,
               COALESCE(t.total_sessions, 0) as sessions
        FROM {$wpdb->prefix}ptp_trainers t
        WHERE t.status = 'active'
        ORDER BY t.is_featured DESC, t.sort_order ASC, t.average_rating DESC
        LIMIT 8
    ");
}
$trainer_count = count($trainers);

// Aggregate stats
$total_sessions = 0;
$total_reviews = 0;
foreach ($trainers as $t) {
    $total_sessions += intval($t->sessions);
    $total_reviews += intval($t->reviews);
}
$total_sessions = max($total_sessions, 500);

$level_labels = array(
    'pro' => 'PRO', 'college_d1' => 'D1', 'college_d2' => 'D2',
    'college_d3' => 'D3', 'academy' => 'ACADEMY', 'semi_pro' => 'SEMI-PRO'
);

$is_logged_in = is_user_logged_in();
$user_first_name = '';
$dash_url = home_url('/parent-dashboard/');
if ($is_logged_in) {
    $user = wp_get_current_user();
    $user_first_name = $user->first_name ?: explode(' ', $user->display_name)[0];
    if (class_exists('PTP_Trainer') && PTP_Trainer::get_by_user_id($user->ID)) {
        $dash_url = home_url('/trainer-dashboard/');
    }
}

$find_url = home_url('/find-trainers/');
// v228: Free session promo removed
$camps_url = home_url('/ptp-find-a-camp/');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0A0A0A">
<title>Private Soccer Training | PTP - Players Teaching Players</title>
<meta name="description" content="Book private soccer training with current D1 athletes and pro players who train alongside your kid. 1-on-1 sessions across Philadelphia & South Jersey.">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<link rel="canonical" href="<?php echo esc_url(home_url('/training/')); ?>">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:title" content="Private Soccer Training | PTP - Players Teaching Players">
<meta property="og:description" content="Book private soccer training with current D1 athletes and pro players who train alongside your kid. 1-on-1 sessions across PA, NJ, DE, MD & NY.">
<meta property="og:url" content="<?php echo esc_url(home_url('/training/')); ?>">
<meta property="og:site_name" content="PTP Soccer Camps">
<meta property="og:locale" content="en_US">
<meta property="og:image" content="https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="PTP private soccer training with D1 and pro athlete coaches">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Private Soccer Training | PTP - Players Teaching Players">
<meta name="twitter:description" content="Book private soccer training with current D1 athletes and pro players who train alongside your kid.">
<meta name="twitter:image" content="https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg">

<!-- Schema: Service -->
<script type="application/ld+json">
<?php echo wp_json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => 'Private Soccer Training',
    'description' => 'Private 1-on-1 and small group soccer training with current NCAA D1 athletes and MLS professionals. Coaches play with your kid — not drills from a cone.',
    'provider' => [
        '@type' => 'SportsOrganization',
        '@id' => home_url('/#organization'),
        'name' => function_exists('ptp_email_brand') ? ptp_email_brand('company') : 'PTP Soccer',
        'alternateName' => function_exists('ptp_email_brand') ? ptp_email_brand('tagline') : 'Players Teaching Players',
        'url' => home_url('/'),
        'telephone' => function_exists('ptp_email_brand') ? ptp_email_brand('support_phone') : '',
        'logo' => function_exists('ptp_email_brand') ? ptp_email_brand('logo_url') : '',
        'aggregateRating' => [
            '@type' => 'AggregateRating',
            'ratingValue' => '4.9',
            'bestRating' => '5',
            'reviewCount' => 50,
        ],
    ],
    'serviceType' => 'Private Soccer Training',
    'category' => 'Youth Soccer Training',
    'areaServed' => [
        ['@type' => 'State', 'name' => 'Pennsylvania'],
        ['@type' => 'State', 'name' => 'New Jersey'],
        ['@type' => 'State', 'name' => 'Delaware'],
        ['@type' => 'State', 'name' => 'Maryland'],
        ['@type' => 'State', 'name' => 'New York'],
    ],
    'hasOfferCatalog' => [
        '@type' => 'OfferCatalog',
        'name' => 'Training Sessions',
        'itemListElement' => [
            [
                '@type' => 'Offer',
                'itemOffered' => ['@type' => 'Service', 'name' => '1-on-1 Private Training'],
                'priceCurrency' => 'USD',
            ],
            [
                '@type' => 'Offer',
                'itemOffered' => ['@type' => 'Service', 'name' => 'Small Group Training'],
                'priceCurrency' => 'USD',
            ],
        ],
    ],
    'url' => home_url('/training/'),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
</script>

<!-- Schema: FAQ -->
<script type="application/ld+json">
<?php echo wp_json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => [
        ['@type' => 'Question', 'name' => 'Who are PTP\'s private trainers?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Current NCAA Division 1 athletes and MLS professionals who actively play with your child during sessions — not retired coaches running drills from a cone.']],
        ['@type' => 'Question', 'name' => 'What areas does PTP private training cover?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'PTP offers private soccer training across Pennsylvania, New Jersey, Delaware, Maryland, and New York.']],
        ['@type' => 'Question', 'name' => 'What ages is private training for?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Private training is available for youth players ages 6-14, with sessions tailored to each player\'s level and goals.']],
        ['@type' => 'Question', 'name' => 'How do I book a private training session?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Pick a trainer from the platform, choose an available time slot, and book online. Your first session can be free.']],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
</script>

<!-- Schema: BreadcrumbList -->
<script type="application/ld+json">
<?php echo wp_json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Private Training', 'item' => home_url('/training/')],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php wp_head(); ?>
<link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-masterclass.css">
<style>
/* ═══ PTP Training Hub v198 — Inline CSS (restored v228) ═══ */
/* ============================================
   WP RESET — same pattern as trainer profile
   ============================================ */
html,body{scrollbar-width:none;-ms-overflow-style:none}
html::-webkit-scrollbar,body::-webkit-scrollbar{display:none;width:0}
*{box-sizing:border-box}
#page,#content,#primary,.site,.site-content,.content-area,
main,main.site-main,article,.hentry,.entry-content,.post-content,
.page-content,.ast-container,.ast-row,.container,.site-main,#main,
.wp-block-post-content,.is-layout-constrained,.is-layout-flow,
.elementor-widget-container,.ast-article-single,.ast-article-post,
.ast-separate-container,.ast-plain-container,.ast-page-builder-template{
    margin:0 !important;padding:0 !important;border:none !important;background:transparent !important;overflow:visible !important;
}
.is-layout-constrained > * + *,.is-layout-flow > * + *{margin-block-start:0 !important}

/* Hide Elementor/Astra chrome */
body .elementor-location-header, body header.elementor-element, body #masthead,
body .site-header, body [data-elementor-type="header"],
body .site-footer, body #colophon, body .elementor-location-footer,
body [data-elementor-type="footer"],
body .ptp-header, body #ptpHeader, body .ptp-bottom-nav, body .ptp-mobile-nav,
body .ptp-mobile-nav-overlay { display:none !important; }

/* v216: Nuclear white text override — any header that bleeds through gets white text */
body .site-header *,
body #masthead *,
body .elementor-location-header *,
body [data-elementor-type="header"] *,
body .ast-header-sections *,
body .ast-above-header-wrap *,
body .ast-below-header-wrap *,
body .ast-mobile-header-wrap *,
body header *,
body .ptp-header *,
body #ptpHeader * {
    color: #fff !important;
}
body .site-header a,
body #masthead a,
body .elementor-location-header a,
body [data-elementor-type="header"] a,
body header a,
body .ptp-header a,
body #ptpHeader a {
    color: #fff !important;
}
body .site-header,
body #masthead,
body .elementor-location-header,
body [data-elementor-type="header"],
body header.header,
body .ptp-header,
body #ptpHeader {
    background: #0A0A0A !important;
    border-bottom: 1px solid rgba(255,255,255,.08) !important;
}

/* iOS input fixes */
input,select,button,textarea{-webkit-appearance:none;-moz-appearance:none;appearance:none;border-radius:0;font-family:inherit}
input[type="text"],input[type="email"],select{font-size:16px}

/* ============================================
   INLINE CSS VARIABLES — prevent FOUC
   The external ptp-masterclass.css defines these
   under .mc-light / .mc-dark classes, but those
   depend on body class added via JS. Define them
   directly on .th so the page never renders raw.
   ============================================ */
.th{
    --mc-gold: #FCB900;
    --mc-gold-hover: #e5a800;
    --mc-gold-dim: rgba(252,185,0,0.1);
    --mc-gold-glow: rgba(252,185,0,0.3);
    --mc-green: #22c55e;
    --mc-red: #ef4444;
    --mc-font-serif: 'Playfair Display', Georgia, 'Times New Roman', serif;
    --mc-font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    --mc-font-display: 'Oswald', -apple-system, sans-serif;
    --mc-space-xs: 4px;
    --mc-space-sm: 8px;
    --mc-space-md: 16px;
    --mc-space-lg: 24px;
    --mc-space-xl: 40px;
    --mc-space-2xl: 60px;
    --mc-space-3xl: 80px;
    --mc-transition: 0.3s ease;
    --mc-transition-fast: 0.15s ease;
    --mc-safe-top: env(safe-area-inset-top, 0px);
    --mc-safe-bottom: env(safe-area-inset-bottom, 0px);
    --mc-black: #0a0a0a;
    --mc-dark: #111111;
    --mc-dark-2: #1a1a1a;
    --mc-dark-3: #222222;
    --mc-light-gray: #999999;
    --mc-bg: #ffffff;
    --mc-bg-alt: #f8f8f8;
    --mc-bg-card: #ffffff;
    --mc-bg-elevated: #fafafa;
    --mc-text: #1a1a1a;
    --mc-text-secondary: #666666;
    --mc-text-muted: #999999;
    --mc-border: #e5e5e5;
    --mc-border-light: #f0f0f0;
}

/* ============================================
   PAGE WRAPPER (layout properties)
   ============================================ */
.th{
    max-width:100%;min-height:100vh;min-height:100dvh;
    background:var(--mc-bg);color:var(--mc-text);
    font-family:var(--mc-font-sans);-webkit-font-smoothing:antialiased;
    -webkit-tap-highlight-color:transparent;line-height:1.6;
    overflow-x:hidden;
}
.th h1,.th h2,.th h3{margin:0}

/* ============================================
   HERO — FULL BLEED CINEMATIC
   ============================================ */
.th-hero{
    position:relative;min-height:92vh;min-height:92dvh;
    display:flex;align-items:flex-end;overflow:hidden;
    background:var(--mc-black);
}
.th-hero-bg{
    position:absolute;inset:0;
    background:url('https://ptpsummercamps.com/wp-content/uploads/2025/12/scrimmage-1.jpg') center/cover no-repeat;
}
.th-hero-grad{
    position:absolute;inset:0;
    background:linear-gradient(180deg,
        rgba(10,10,10,0.25) 0%,
        rgba(10,10,10,0.15) 30%,
        rgba(10,10,10,0.5) 55%,
        rgba(10,10,10,0.92) 80%,
        rgba(10,10,10,1) 100%
    );
}

/* Floating Nav — matches trainer profile */
.th-nav{
    position:absolute;top:0;left:0;right:0;z-index:10;
    display:flex;align-items:center;justify-content:space-between;
    padding:calc(var(--mc-safe-top) + 14px) 20px 14px;
}
.th-nav-left{display:flex;align-items:center;gap:12px}
.th-nav-logo{
    font-family:var(--mc-font-display);font-size:18px;font-weight:700;
    letter-spacing:0.1em;color:#fff;text-decoration:none;
    text-shadow:0 1px 4px rgba(0,0,0,0.4);
}
.th-nav-right{display:flex;align-items:center;gap:8px}
.th-nav-link{
    display:flex;align-items:center;gap:5px;height:42px;padding:0 14px;
    background:rgba(0,0,0,0.4);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
    border-radius:21px;color:#fff;font-size:13px;font-weight:600;
    text-decoration:none;white-space:nowrap;transition:background .15s;
    min-height:44px;
}
.th-nav-link:hover{background:rgba(0,0,0,0.6)}
.th-nav-link:active{transform:scale(0.95)}
.th-nav-link svg{width:16px;height:16px}
.th-nav-cta{
    background:var(--mc-gold) !important;color:var(--mc-black) !important;
    font-family:var(--mc-font-display);font-weight:700;letter-spacing:0.06em;
    text-transform:uppercase;font-size:11px;backdrop-filter:none;
}
.th-nav-cta:hover{background:var(--mc-gold-hover) !important}
@media(max-width:599px){
    .th-nav-link span{display:none}
    .th-nav-link{width:42px;padding:0;justify-content:center}
    .th-nav-cta{width:auto;padding:0 16px}
    .th-nav-cta span{display:inline !important}
}

/* Hero content — pinned to bottom */
.th-hero-body{
    position:relative;z-index:2;width:100%;max-width:800px;
    padding:0 20px 48px;
}
.th-hero-label{
    font-family:var(--mc-font-display);font-size:11px;font-weight:700;
    letter-spacing:3px;text-transform:uppercase;color:var(--mc-gold);
    margin-bottom:14px;
}
.th-hero-h1{
    font-family:var(--mc-font-serif);font-size:clamp(30px,7.5vw,58px);
    font-weight:500;color:#fff;line-height:1.08;letter-spacing:-0.02em;
    margin-bottom:16px;
}
.th-hero-h1 em{font-style:italic;color:var(--mc-gold)}
.th-hero-sub{
    font-size:clamp(14px,2.2vw,17px);color:rgba(255,255,255,.6);
    max-width:540px;line-height:1.7;margin-bottom:28px;
}
.th-hero-actions{display:flex;gap:12px;flex-wrap:wrap}
.th-hero-btn{
    display:inline-flex;align-items:center;gap:8px;
    font-family:var(--mc-font-display);font-size:14px;font-weight:700;
    letter-spacing:0.08em;text-transform:uppercase;text-decoration:none;
    padding:16px 32px;transition:var(--mc-transition);cursor:pointer;border:none;
}
.th-hero-btn svg{width:16px;height:16px}
.th-hero-primary{background:var(--mc-gold);color:var(--mc-black)}
.th-hero-primary:hover{background:var(--mc-gold-hover);transform:translateY(-2px);box-shadow:0 12px 40px var(--mc-gold-glow)}
.th-hero-secondary{background:transparent;color:#fff;border:2px solid rgba(255,255,255,.25);padding:14px 28px}
.th-hero-secondary:hover{border-color:var(--mc-gold);color:var(--mc-gold)}

@media(max-width:599px){
    .th-hero{min-height:85dvh}
    .th-hero-body{padding:0 16px 36px}
    .th-hero-actions{flex-direction:column}
    .th-hero-btn{justify-content:center;width:100%}
}

/* ============================================
   SOCIAL PROOF BAR
   ============================================ */
.th-proof{
    background:var(--mc-black);border-bottom:1px solid rgba(255,255,255,.06);
    padding:20px;
}
.th-proof-in{
    max-width:900px;margin:0 auto;
    display:flex;justify-content:center;align-items:center;gap:40px;flex-wrap:wrap;
}
.th-proof-item{display:flex;align-items:center;gap:10px}
.th-proof-ico{
    width:40px;height:40px;background:rgba(252,185,0,.1);border-radius:50%;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.th-proof-ico svg{width:18px;height:18px;fill:var(--mc-gold)}
.th-proof-val{font-family:var(--mc-font-display);font-size:16px;font-weight:700;color:#fff}
.th-proof-lbl{font-size:10px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:0.08em}
@media(max-width:599px){.th-proof-in{gap:20px}.th-proof-val{font-size:14px}}

/* ============================================
   HOW IT WORKS
   ============================================ */
.th-how{padding:var(--mc-space-3xl) var(--mc-space-lg);background:var(--mc-bg)}
.th-how-in{max-width:1000px;margin:0 auto}
.th-sec-head{text-align:center;max-width:600px;margin:0 auto var(--mc-space-xl)}
.th-sec-label{
    font-family:var(--mc-font-display);font-size:11px;font-weight:600;
    letter-spacing:0.15em;text-transform:uppercase;color:var(--mc-gold);margin-bottom:10px;
}
.th-sec-title{
    font-family:var(--mc-font-serif);font-size:clamp(24px,4.5vw,38px);
    font-weight:500;line-height:1.15;letter-spacing:-0.02em;color:var(--mc-text);
}
.th-sec-title em{font-style:italic;color:var(--mc-gold)}
.th-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:32px}
.th-step{text-align:center}
.th-step-num{
    width:52px;height:52px;background:var(--mc-gold);color:var(--mc-black);
    font-family:var(--mc-font-display);font-size:20px;font-weight:700;
    display:flex;align-items:center;justify-content:center;border-radius:50%;
    margin:0 auto 16px;
}
.th-step h3{
    font-family:var(--mc-font-display);font-size:15px;font-weight:700;
    text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;color:var(--mc-text);
}
.th-step p{font-size:14px;color:var(--mc-text-secondary);line-height:1.65}
@media(max-width:767px){
    .th-how{padding:var(--mc-space-2xl) var(--mc-space-md)}
    .th-steps{grid-template-columns:1fr;gap:28px}
}

/* ============================================
   TRAINERS GRID
   ============================================ */
.th-trainers{padding:var(--mc-space-3xl) var(--mc-space-lg);background:var(--mc-bg-alt)}
.th-trainers-in{max-width:1200px;margin:0 auto}
.th-tg{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:36px}
.th-tc{text-decoration:none;color:inherit;display:block;transition:transform .15s}
.th-tc:active{transform:scale(0.97)}
.th-tc-img{
    position:relative;aspect-ratio:3/4;overflow:hidden;border-radius:8px;
    background:var(--mc-bg);margin-bottom:10px;
}
.th-tc-img img{width:100%;height:100%;object-fit:cover;display:block}
.th-tc-tag{
    position:absolute;bottom:10px;left:10px;
    background:var(--mc-gold);color:var(--mc-black);
    font-family:var(--mc-font-display);font-size:9px;font-weight:700;
    letter-spacing:1.5px;text-transform:uppercase;padding:4px 10px;
}
.th-tc-feat .th-tc-img{border:2px solid var(--mc-gold)}
.th-tc-name{
    font-family:var(--mc-font-display);font-size:15px;font-weight:700;
    text-transform:uppercase;letter-spacing:0.02em;color:var(--mc-text);
}
.th-tc-loc{font-size:13px;color:var(--mc-text-secondary);margin:2px 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.th-tc-foot{display:flex;justify-content:space-between;align-items:center;margin-top:4px}
.th-tc-rating{display:flex;align-items:center;gap:3px;font-size:13px;font-weight:500;color:var(--mc-text)}
.th-tc-rating svg{width:12px;height:12px;fill:var(--mc-text)}
.th-tc-rating span{color:var(--mc-text-muted);font-weight:400;font-size:11px}
.th-tc-price{font-size:14px;color:var(--mc-text)}
.th-tc-price b{font-weight:600}
.th-tc-price span{color:var(--mc-text-secondary)}
.th-tc-new{color:var(--mc-gold);font-weight:600;font-size:12px}
.th-trainers-cta{text-align:center}
.th-all-btn{
    display:inline-flex;align-items:center;gap:8px;
    background:var(--mc-black);color:#fff;
    font-family:var(--mc-font-display);font-size:13px;font-weight:700;
    text-transform:uppercase;letter-spacing:0.08em;
    padding:16px 36px;text-decoration:none;transition:var(--mc-transition);
}
.th-all-btn:hover{background:#222;transform:translateY(-1px);box-shadow:0 8px 24px rgba(0,0,0,.15)}
.th-all-btn:active{transform:scale(0.98)}
.th-all-btn svg{width:16px;height:16px}
@media(max-width:1023px){.th-tg{grid-template-columns:repeat(3,1fr)}}
@media(max-width:767px){
    .th-trainers{padding:var(--mc-space-2xl) var(--mc-space-md)}
    .th-tg{grid-template-columns:repeat(2,1fr);gap:14px}
    .th-tc-img{aspect-ratio:1;border-radius:6px}
    .th-tc-name{font-size:13px}
    .th-tc-loc{font-size:12px}
    .th-tc-foot{font-size:12px}
}

/* ============================================
   WHY PTP — DARK SECTION
   ============================================ */
.th-why{background:var(--mc-black);padding:var(--mc-space-3xl) var(--mc-space-lg);color:#fff;position:relative;overflow:hidden}
.th-why::before{content:'';position:absolute;top:-60px;right:-60px;width:360px;height:360px;border:2px solid rgba(252,185,0,.05);border-radius:50%;pointer-events:none}
.th-why::after{content:'';position:absolute;bottom:-80px;left:-40px;width:280px;height:280px;border:2px solid rgba(252,185,0,.03);border-radius:50%;pointer-events:none}
.th-why-in{max-width:1100px;margin:0 auto;position:relative;z-index:1}
.th-why .th-sec-title{color:#fff}
.th-why-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.th-why-card{
    border:1px solid rgba(255,255,255,.1);padding:28px 24px;
    border-radius:8px;transition:border-color .2s;
}
.th-why-card:hover{border-color:var(--mc-gold)}
.th-why-ico{font-size:28px;margin-bottom:14px;display:block}
.th-why-card h3{
    font-family:var(--mc-font-display);font-size:14px;font-weight:700;
    text-transform:uppercase;letter-spacing:0.06em;color:var(--mc-gold);margin-bottom:8px;
}
.th-why-card p{font-size:13px;color:rgba(255,255,255,.5);line-height:1.7}
.th-why-card strong{color:var(--mc-gold);font-weight:600}
@media(max-width:767px){
    .th-why{padding:var(--mc-space-2xl) var(--mc-space-md)}
    .th-why-grid{grid-template-columns:1fr}
}

/* ============================================
   REVIEWS
   ============================================ */
.th-reviews{padding:var(--mc-space-3xl) var(--mc-space-lg);background:var(--mc-bg)}
.th-reviews-in{max-width:1100px;margin:0 auto}
.th-rg{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.th-rc{
    background:var(--mc-bg-card);border:1px solid var(--mc-border);
    padding:var(--mc-space-lg);border-radius:8px;transition:border-color .2s;
}
.th-rc:hover{border-color:var(--mc-gold)}
.th-rc-stars{color:var(--mc-gold);font-size:14px;letter-spacing:2px;margin-bottom:12px}
.th-rc-quote{
    font-family:var(--mc-font-serif);font-size:15px;font-style:italic;
    color:var(--mc-text-secondary);line-height:1.7;margin-bottom:16px;
}
.th-rc-author{display:flex;align-items:center;gap:12px}
.th-rc-avatar{
    width:40px;height:40px;border-radius:50%;background:rgba(252,185,0,.1);
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
    font-family:var(--mc-font-display);font-weight:700;color:var(--mc-gold);font-size:14px;
}
.th-rc-name{font-weight:600;font-size:14px;color:var(--mc-text)}
.th-rc-src{font-size:12px;color:var(--mc-text-muted)}
@media(max-width:767px){
    .th-reviews{padding:var(--mc-space-2xl) var(--mc-space-md)}
    .th-rg{grid-template-columns:1fr}
}

/* ============================================
   FINAL CTA
   ============================================ */
.th-final{
    text-align:center;padding:var(--mc-space-3xl) var(--mc-space-lg);
    background:var(--mc-black);color:#fff;position:relative;overflow:hidden;
}
.th-final::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at center,rgba(252,185,0,.04) 0%,transparent 70%);pointer-events:none}
.th-final-in{position:relative;z-index:1;max-width:600px;margin:0 auto}
.th-final h2{
    font-family:var(--mc-font-serif);font-size:clamp(26px,5vw,44px);
    font-weight:500;line-height:1.12;letter-spacing:-0.02em;margin-bottom:14px;
}
.th-final h2 em{font-style:italic;color:var(--mc-gold)}
.th-final p{font-size:15px;color:rgba(255,255,255,.45);line-height:1.7;margin-bottom:32px}
.th-final-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
@media(max-width:599px){
    .th-final{padding:var(--mc-space-2xl) var(--mc-space-md)}
    .th-final-actions{flex-direction:column;max-width:340px;margin:0 auto}
    .th-hero-btn{justify-content:center}
}

/* ============================================
   FOOTER
   ============================================ */
.th-foot{
    background:var(--mc-black);border-top:1px solid rgba(255,255,255,.06);
    padding:24px 20px;text-align:center;
}
.th-foot p{font-size:12px;color:rgba(255,255,255,.2)}
.th-foot a{color:rgba(255,255,255,.3);text-decoration:none}
.th-foot a:hover{color:var(--mc-gold)}

/* ============================================
   MOBILE STICKY CTA
   ============================================ */
.th-sticky{
    position:fixed;bottom:0;left:0;right:0;z-index:99;
    background:#fff;border-top:1px solid var(--mc-border);
    padding:10px 16px calc(10px + var(--mc-safe-bottom));
    transform:translateY(100%);transition:transform .25s ease;
    box-shadow:0 -4px 20px rgba(0,0,0,.08);display:none;
}
.th-sticky.show{transform:translateY(0)}
.th-sticky a{
    display:flex;align-items:center;justify-content:center;gap:8px;width:100%;
    padding:15px;background:var(--mc-gold);color:var(--mc-black);
    font-family:var(--mc-font-display);font-size:13px;font-weight:700;
    text-transform:uppercase;letter-spacing:0.08em;text-decoration:none;
    min-height:48px;
}
.th-sticky a:active{background:var(--mc-gold-hover);transform:scale(0.98)}
@media(max-width:767px){.th-sticky{display:block}}
@media(min-width:768px){.th-sticky{display:none !important}}

/* ============================================
   ANIMATIONS
   ============================================ */
@keyframes thFadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.th-hero-body{animation:thFadeUp .6s ease-out .2s both}
.th-step{animation:thFadeUp .5s ease-out both}
.th-step:nth-child(1){animation-delay:.1s}
.th-step:nth-child(2){animation-delay:.2s}
.th-step:nth-child(3){animation-delay:.3s}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms !important;transition-duration:.01ms !important}}

/* ============================================
   CHAT WIDGET HIDE
   ============================================ */
@media(max-width:1023px){
    #tidio-chat,#crisp-chatbox,.crisp-client,#intercom-container,#intercom-frame,
    .intercom-lightweight-app,#hubspot-messages-iframe-container,#tawk-bubble-container,
    #tawkchat-container,.tawk-min-container,#drift-widget-container,#fc_frame,#fc_widget,
    .fb_dialog,.fb_iframe_widget,#chat-widget-container,.joinchat,.wp-social-chat-container,
    #olark-wrapper,[id*="chat-widget"],[class*="chat-widget"],[class*="chat-bubble"],
    iframe[title*="chat" i],iframe[title*="messenger" i]{
        display:none !important;visibility:hidden !important;
    }
}
</style>

<script>document.body.classList.add('mc-light');document.body.classList.remove('mc-dark');</script>

<div class="th">

<!-- ============ HERO ============ -->
<section class="th-hero">
    <div class="th-hero-bg"></div>
    <div class="th-hero-grad"></div>
    
    <nav class="th-nav">
        <div class="th-nav-left">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="th-nav-logo">PTP</a>
        </div>
        <div class="th-nav-right">
            <a href="<?php echo esc_url($find_url); ?>" class="th-nav-link">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <span>Trainers</span>
            </a>
            <a href="<?php echo esc_url($camps_url); ?>" class="th-nav-link">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Camps</span>
            </a>
            <?php if ($is_logged_in): ?>
            <a href="<?php echo esc_url($dash_url); ?>" class="th-nav-link">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Dashboard</span>
            </a>
            <?php else: ?>
            <a href="<?php echo esc_url(home_url('/login/')); ?>" class="th-nav-link">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                <span>Log In</span>
            </a>
            <?php endif; ?>
            <a href="<?php echo esc_url($find_url); ?>" class="th-nav-link th-nav-cta">
                <span>Book Now</span>
            </a>
        </div>
    </nav>

    <div class="th-hero-body">
        <div class="th-hero-label">PLAYERS TEACHING PLAYERS</div>
        <?php if ($is_logged_in && $user_first_name): ?>
        <h1 class="th-hero-h1">Welcome back, <em><?php echo esc_html($user_first_name); ?></em></h1>
        <p class="th-hero-sub">Ready for your next session? Pick up where you left off or find a new trainer.</p>
        <?php else: ?>
        <h1 class="th-hero-h1">Private Training With <em>Real Athletes</em></h1>
        <p class="th-hero-sub">Our coaches are current D1 athletes and pro players who train alongside your kid — not from the sideline. That's the difference.</p>
        <?php endif; ?>
        <div class="th-hero-actions">
            <a href="<?php echo esc_url($find_url); ?>" class="th-hero-btn th-hero-primary">
                <?php echo $is_logged_in ? 'Book Next Session' : 'Find Your Trainer'; ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
            <a href="<?php echo esc_url(home_url("/find-trainers/")); ?>" class="th-hero-btn th-hero-secondary">Browse Trainers</a>
        </div>
    </div>
</section>

<!-- ============ SOCIAL PROOF BAR ============ -->
<section class="th-proof">
    <div class="th-proof-in">
        <div class="th-proof-item">
            <div class="th-proof-ico"><svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></div>
            <div>
                <div class="th-proof-val">4.9 Star Rating</div>
                <div class="th-proof-lbl">50+ Reviews</div>
            </div>
        </div>
        <div class="th-proof-item">
            <div class="th-proof-ico"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></div>
            <div>
                <div class="th-proof-val">500+ Families</div>
                <div class="th-proof-lbl">Trust PTP</div>
            </div>
        </div>
        <div class="th-proof-item">
            <div class="th-proof-ico"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div>
                <div class="th-proof-val"><?php echo number_format($total_sessions); ?>+ Sessions</div>
                <div class="th-proof-lbl">Completed</div>
            </div>
        </div>
    </div>
</section>

<!-- ============ HOW IT WORKS ============ -->
<section class="th-how">
    <div class="th-how-in">
        <div class="th-sec-head">
            <div class="th-sec-label">How It Works</div>
            <h2 class="th-sec-title">Book a Session in <em>Three Steps</em></h2>
        </div>
        <div class="th-steps">
            <div class="th-step">
                <div class="th-step-num">1</div>
                <h3>Pick Your Trainer</h3>
                <p>Browse by location, playing level, and specialty. Every coach is a verified current D1 athlete or pro — we don't hire retired coaches.</p>
            </div>
            <div class="th-step">
                <div class="th-step-num">2</div>
                <h3>Choose a Time</h3>
                <p>Pick from your trainer's live availability. Sessions happen at local fields near you — 1-on-1 or small groups, your call.</p>
            </div>
            <div class="th-step">
                <div class="th-step-num">3</div>
                <h3>Show Up &amp; Get Better</h3>
                <p>Your trainer builds a plan around your kid's goals and plays with them every session. Not drills from a cone — real reps, real competition.</p>
            </div>
        </div>
    </div>
</section>

<!-- ============ TRAINERS ============ -->
<?php if (!empty($trainers)): ?>
<section class="th-trainers" id="trainers">
    <div class="th-trainers-in">
        <div class="th-sec-head">
            <div class="th-sec-label">Meet the Team</div>
            <h2 class="th-sec-title">Current <em>D1 Athletes</em> &amp; <em>Pros</em></h2>
        </div>
        <div class="th-tg">
            <?php foreach ($trainers as $t):
                $slug = $t->slug ?: sanitize_title($t->display_name);
                $profile_url = home_url('/trainer/' . $slug . '/');
                $photo = $t->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($t->display_name) . '&size=400&background=FCB900&color=0A0A0A&bold=true&font-size=0.4';
                $level = $level_labels[$t->playing_level] ?? 'PRO';
                $loc = trim(($t->city ?: '') . ', ' . ($t->state ?: ''), ', ') ?: 'Philadelphia Area';
                $rate = intval($t->hourly_rate ?: 60);
                $rate_display = $rate > 0 ? '$' . $rate : 'Free Intro';
                $rate_unit = $rate > 0 ? ' <span>/ hr</span>' : '';
                $is_featured = !empty($t->is_featured);
            ?>
            <a href="<?php echo esc_url($profile_url); ?>" class="th-tc<?php echo $is_featured ? ' th-tc-feat' : ''; ?>">
                <div class="th-tc-img">
                    <img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($t->display_name); ?>" loading="lazy">
                    <span class="th-tc-tag"><?php echo esc_html($level); ?></span>
                </div>
                <div class="th-tc-name"><?php echo esc_html(strtoupper($t->display_name)); ?></div>
                <div class="th-tc-loc"><?php echo esc_html($loc); ?></div>
                <div class="th-tc-foot">
                    <div class="th-tc-rating">
                        <?php if ($t->reviews > 0): ?>
                        <svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        <?php echo number_format($t->avg_rating, 1); ?>
                        <span>(<?php echo intval($t->reviews); ?>)</span>
                        <?php else: ?>
                        <span class="th-tc-new">NEW</span>
                        <?php endif; ?>
                    </div>
                    <div class="th-tc-price"><b><?php echo $rate_display; ?></b><?php echo $rate_unit; ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="th-trainers-cta">
            <a href="<?php echo esc_url($find_url); ?>" class="th-all-btn">
                Browse All Trainers
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ============ WHY PTP ============ -->
<section class="th-why">
    <div class="th-why-in">
        <div class="th-sec-head">
            <div class="th-sec-label">The PTP Difference</div>
            <h2 class="th-sec-title">This Isn't <em>Your Average</em> Soccer Camp</h2>
        </div>
        <div class="th-why-grid">
            <div class="th-why-card">
                <span class="th-why-ico">⚽</span>
                <h3>They Play With Your Kid</h3>
                <p>Our trainers don't stand behind cones with a whistle. They <strong>lace up and compete</strong> — passing, shooting, going 1v1. That's how real players are made.</p>
            </div>
            <div class="th-why-card">
                <span class="th-why-ico">🎓</span>
                <h3>D1 Athletes &amp; Pros</h3>
                <p>Every trainer is a <strong>current D1 player or professional</strong>. Not someone who peaked 15 years ago — athletes who know what it takes right now.</p>
            </div>
            <div class="th-why-card">
                <span class="th-why-ico">📋</span>
                <h3>Your Kid's Plan, Not Ours</h3>
                <p>No cookie-cutter curriculum. Your trainer builds a <strong>personalized development plan</strong> around your kid's position, goals, and weaknesses.</p>
            </div>
            <div class="th-why-card">
                <span class="th-why-ico">👥</span>
                <h3>8:1 Max Ratio</h3>
                <p>Whether it's private training or small group, your kid gets <strong>real individual attention</strong>. More touches. More reps. More coaching that actually sticks.</p>
            </div>
            <div class="th-why-card">
                <span class="th-why-ico">📍</span>
                <h3>10 Locations Near You</h3>
                <p>We train at fields across <strong>Philadelphia, the Main Line, and South Jersey</strong>. Your trainer comes to your area — no hour-long drives to some academy.</p>
            </div>
            <div class="th-why-card">
                <span class="th-why-ico">🔥</span>
                <h3>Results That Show</h3>
                <p>Third team to first team ECNL. Making varsity as a freshman. Coming back from ACL surgery stronger. <strong>Our families see it happen.</strong></p>
            </div>
        </div>
    </div>
</section>

<!-- ============ REVIEWS ============ -->
<section class="th-reviews">
    <div class="th-reviews-in">
        <div class="th-sec-head">
            <div class="th-sec-label">Straight From Parents</div>
            <h2 class="th-sec-title">Don't Take <em>Our Word</em> for It</h2>
        </div>
        <div class="th-rg">
            <div class="th-rc">
                <div class="th-rc-stars">★★★★★</div>
                <p class="th-rc-quote">"My son had an absolute blast. He loved every minute and can't wait to go back. The trainer actually played with him — not just watched."</p>
                <div class="th-rc-author">
                    <div class="th-rc-avatar">C</div>
                    <div><div class="th-rc-name">Cindy Armstrong</div><div class="th-rc-src">Google Review</div></div>
                </div>
            </div>
            <div class="th-rc">
                <div class="th-rc-stars">★★★★★</div>
                <p class="th-rc-quote">"Third team to first team ECNL in 5 months. It's like having a mentor, not just a coach. The development has been unreal."</p>
                <div class="th-rc-author">
                    <div class="th-rc-avatar">D</div>
                    <div><div class="th-rc-name">Father of ECNL Player</div><div class="th-rc-src">Google Review</div></div>
                </div>
            </div>
            <div class="th-rc">
                <div class="th-rc-stars">★★★★★</div>
                <p class="th-rc-quote">"After ACL surgery, they got her back to her best. She came home excited every single session. Can't recommend PTP enough."</p>
                <div class="th-rc-author">
                    <div class="th-rc-avatar">M</div>
                    <div><div class="th-rc-name">Mother of 13-Year-Old</div><div class="th-rc-src">Google Review</div></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ FINAL CTA ============ -->
<section class="th-final">
    <div class="th-final-in">
        <h2>Ready to See <em>the Difference</em>?</h2>
        <p>Pick your trainer, book a time, and watch what happens when your kid trains with someone who actually plays the game.</p>
        <div class="th-final-actions">
            <a href="<?php echo esc_url($find_url); ?>" class="th-hero-btn th-hero-primary">
                Find Your Trainer
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </a>
            <a href="<?php echo esc_url(home_url("/find-trainers/")); ?>" class="th-hero-btn th-hero-secondary">Browse Trainers</a>
        </div>
    </div>
</section>

<footer class="th-foot">
    <p>&copy; <?php echo date('Y'); ?> PTP — Players Teaching Players &nbsp;·&nbsp; <a href="<?php echo esc_url(home_url('/privacy/')); ?>">Privacy</a> &nbsp;·&nbsp; <a href="<?php echo esc_url(home_url('/terms/')); ?>">Terms</a></p>
</footer>
</div>

<!-- Mobile Sticky -->
<div class="th-sticky" id="thStick">
    <a href="<?php echo esc_url($find_url); ?>">
        Book a Session
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
    </a>
</div>

<script>
(function(){
    var s=document.getElementById('thStick');
    if(!s)return;
    var t=false;
    window.addEventListener('scroll',function(){
        if(!t){requestAnimationFrame(function(){
            s.classList.toggle('show',window.scrollY>500);
            t=false;
        });t=true;}
    },{passive:true});
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
