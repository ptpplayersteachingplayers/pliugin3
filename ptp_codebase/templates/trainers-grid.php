<?php
/**
 * Find Trainers - Airbnb-Style Layout
 * Split view: trainer cards (left) + sticky map (right)
 * Search, filter, and browse PTP trainers with map view
 * Clean white background, PTP gold/black accents
 * 
 * @version 192.0
 */
defined('ABSPATH') || exit;

add_filter('ptp_skip_header', '__return_true');

global $wpdb;
$google_maps_key = get_option('ptp_google_maps_api_key', '') ?: get_option('ptp_google_maps_key', '');
$level_labels = array(
    'pro' => 'PRO',
    'college_d1' => 'NCAA D1',
    'college_d2' => 'NCAA D2',
    'college_d3' => 'NCAA D3',
    'academy' => 'ACADEMY',
    'semi_pro' => 'SEMI-PRO'
);

$location_slug = get_query_var('trainer_location', '');
$seo_location = '';
$seo_state = '';
if ($location_slug) {
    $location_map = array(
        'philadelphia' => array('Philadelphia', 'PA'),
        'cherry-hill' => array('Cherry Hill', 'NJ'),
        'wilmington' => array('Wilmington', 'DE'),
        'baltimore' => array('Baltimore', 'MD'),
        'new-york' => array('New York', 'NY'),
        'princeton' => array('Princeton', 'NJ'),
        'wayne' => array('Wayne', 'PA'),
        'media' => array('Media', 'PA'),
        'newtown' => array('Newtown', 'PA'),
        'doylestown' => array('Doylestown', 'PA'),
        'king-of-prussia' => array('King of Prussia', 'PA'),
        'west-chester' => array('West Chester', 'PA'),
        'malvern' => array('Malvern', 'PA'),
        'radnor' => array('Radnor', 'PA'),
        'villanova' => array('Villanova', 'PA'),
        'ardmore' => array('Ardmore', 'PA'),
        'bryn-mawr' => array('Bryn Mawr', 'PA'),
        'haverford' => array('Haverford', 'PA'),
        'conshohocken' => array('Conshohocken', 'PA'),
    );
    if (isset($location_map[$location_slug])) {
        $seo_location = $location_map[$location_slug][0];
        $seo_state = $location_map[$location_slug][1];
    }
}

$cache_key = 'ptp_active_trainers';
$trainers = get_transient($cache_key);
if ($trainers === false || empty($trainers)) {
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_trainers'");
    if ($table_exists) {
        $trainers = $wpdb->get_results("
            SELECT t.*, 
                   COALESCE(t.average_rating, 5.0) as avg_rating,
                   COALESCE(t.review_count, 0) as reviews,
                   COALESCE(t.total_sessions, 0) as sessions
            FROM {$wpdb->prefix}ptp_trainers t
            WHERE t.status = 'active'
            ORDER BY t.is_featured DESC, t.sort_order ASC, t.average_rating DESC
        ");
        if (!empty($trainers)) {
            set_transient($cache_key, $trainers, 5 * MINUTE_IN_SECONDS);
        }
    } else {
        $trainers = array();
    }
}
$count = count($trainers);

$page_title = $seo_location 
    ? "Soccer Trainers in {$seo_location}, {$seo_state} | PTP Soccer" 
    : "Find Soccer Trainers Near You | PTP Soccer";
$page_desc = $seo_location
    ? "Book private soccer training sessions with verified coaches in {$seo_location}, {$seo_state}."
    : "Find and book private soccer training with {$count}+ verified coaches across PA, NJ, DE, MD & NY.";
?>
<link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-masterclass.css">
<style>
/* ═══ PTP Find Trainers Grid — Inline CSS (restored v228) ═══ */
/* =============================================
   WORDPRESS GAP OVERRIDES (required)
   ============================================= */
html,body{scrollbar-width:none;-ms-overflow-style:none}
html::-webkit-scrollbar,body::-webkit-scrollbar{display:none;width:0}
@media(min-width:1024px){html,body{overflow-y:scroll !important;overflow-x:hidden !important;height:auto !important;min-height:100% !important;position:static !important}}
html.menu-open,html.modal-open,html.ptp-drawer-open,html.no-scroll,
body.menu-open,body.modal-open,body.ptp-drawer-open,body.no-scroll{overflow:visible !important;position:static !important;height:auto !important}
/* Hide PTP custom header/nav on this page (has its own search bar) */
.ptp-header,header.ptp-header,#ptpHeader,.ptp-bottom-nav,.ptp-mobile-nav,.ptp-mobile-nav-overlay{display:none !important}
body.has-ptp-header{padding-top:0 !important}
body:has(.ptp-header){padding-top:0 !important}
*{box-sizing:border-box}
/* Reset spacing on WP content wrappers — scoped to avoid touching theme header/footer */
#page,#content,#primary,.site,.site-content,.content-area,
main,main.site-main,article,.hentry,.entry-content,.post-content,
.page-content,.ast-container,.ast-row,.container,.site-main,#main,
.wp-block-post-content,.is-layout-constrained,.is-layout-flow,
.elementor-widget-container,.ast-article-single,.ast-article-post,
.ast-separate-container,.ast-plain-container,.ast-page-builder-template{
    margin:0 !important;padding:0 !important;border:none !important;background:transparent !important;
    overflow:visible !important;
}
/* DO NOT use 'all:revert!important' — it nukes WP theme header/footer styling */
.is-layout-constrained > * + *,.is-layout-flow > * + *{margin-block-start:0 !important}

/* =============================================
   AIRBNB-STYLE LAYOUT — MOBILE-FIRST
   ============================================= */
.ft{
    --gold:#FCB900;--gold-light:rgba(252,185,0,0.08);--gold-hover:#e5a800;
    --black:#0A0A0A;--bg:#fff;--card-bg:#fff;
    --border:#EBEBEB;--border-hover:var(--gold);
    --text:#222;--text-sub:#717171;--text-light:#B0B0B0;
    --r:12px;--r-lg:16px;
    --shadow-card:0 1px 2px rgba(0,0,0,0.04),0 4px 12px rgba(0,0,0,0.04);
    --shadow-card-hover:0 6px 20px rgba(0,0,0,0.1);
    /* Safe area insets for notched phones */
    --safe-t:env(safe-area-inset-top,0px);
    --safe-b:env(safe-area-inset-bottom,0px);
    --safe-l:env(safe-area-inset-left,0px);
    --safe-r:env(safe-area-inset-right,0px);
    font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
    background:var(--bg);min-height:100vh;min-height:100dvh;
    display:flex;flex-direction:column;
    -webkit-tap-highlight-color:transparent;
    -webkit-text-size-adjust:100%;
    margin-top:0 !important;padding-top:0 !important;
}
.ft h1,.ft h2,.ft h3{font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;margin:0}

/* iOS input fixes */
.ft input,.ft select,.ft button,.ft textarea{
    -webkit-appearance:none;-moz-appearance:none;appearance:none;
    border-radius:0;font-family:inherit;
}
.ft input[type="text"],.ft input[type="email"],.ft select{
    font-size:16px; /* Prevents iOS zoom on focus */
}

/* =============================================
   STICKY SEARCH BAR
   ============================================= */
.ft-top{
    border-bottom:1px solid var(--border);
    padding:12px calc(16px + var(--safe-l)) 12px calc(16px + var(--safe-r));
    background:#fff;
    position:sticky;top:0;z-index:50;
}

.ft-top-inner{
    max-width:1800px;margin:0 auto;
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;
}

/* Search — full-width on mobile, flex on desktop */
.ft-search{
    display:flex;align-items:center;
    background:#F7F7F7;border:1px solid var(--border);border-radius:40px;
    overflow:hidden;flex:1;min-width:0;
    transition:border-color 0.2s,box-shadow 0.2s;
}
.ft-search:focus-within{border-color:var(--gold);box-shadow:0 0 0 2px rgba(252,185,0,0.2)}

.ft-locate{
    background:none;border:none;padding:12px 14px;cursor:pointer;color:var(--text-sub);
    display:flex;align-items:center;justify-content:center;
    min-width:48px;min-height:48px; /* 48px touch target */
    transition:color 0.2s;flex-shrink:0;
}
.ft-locate:active{color:var(--gold);transform:scale(0.92)}
.ft-locate.loading{animation:ftPulse 1s infinite}
.ft-locate svg{width:20px;height:20px}
@keyframes ftPulse{0%,100%{opacity:1}50%{opacity:.3}}

.ft-search input{
    flex:1;border:none;background:none;
    padding:14px 4px 14px 0;
    font-size:16px; /* 16px prevents iOS zoom */
    color:var(--text);min-width:0;min-height:48px;
    autocomplete:off;
}
.ft-search input::placeholder{color:var(--text-light)}
.ft-search input:focus{outline:none}

.ft-search-btn{
    background:var(--gold);border:none;padding:0;margin:6px;
    width:40px;height:40px;border-radius:50%;cursor:pointer;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
    transition:background 0.15s,transform 0.1s;
}
.ft-search-btn:active{transform:scale(0.88);background:var(--gold-hover)}
.ft-search-btn svg{width:16px;height:16px;stroke:var(--black);stroke-width:2.5}

/* Filter pills — horizontal scroll on mobile */
.ft-filters{
    display:flex;gap:8px;align-items:center;
    overflow-x:auto;-webkit-overflow-scrolling:touch;
    scrollbar-width:none;flex-shrink:0;
    padding:2px 0; /* Prevents cut-off on active scale */
}
.ft-filters::-webkit-scrollbar{display:none}

.ft-pill{
    padding:10px 16px;border:1px solid var(--border);border-radius:30px;
    background:#fff;color:var(--text);font-size:13px;font-weight:500;
    cursor:pointer;white-space:nowrap;transition:all 0.15s;
    min-height:44px; /* 44px touch target (Apple minimum) */
    display:inline-flex;align-items:center;
}
.ft-pill:active{transform:scale(0.93)}
.ft-pill.on{background:var(--black);border-color:var(--black);color:#fff}

/* Sort */
.ft-sort select{
    padding:10px 14px;border:1px solid var(--border);border-radius:30px;
    font-size:14px;background:#fff;color:var(--text);
    min-height:44px;cursor:pointer;
}

/* On very small screens: search takes full row, pills + sort wrap below */
@media(max-width:519px){
    .ft-top-inner{gap:8px}
    .ft-search{order:0;flex:1 1 100%;max-width:none}
    .ft-filters{order:1;flex:1 1 auto}
    .ft-sort{order:2;flex-shrink:0}
}
@media(min-width:520px){
    .ft-search{max-width:380px}
}
@media(min-width:768px){
    .ft-search{max-width:420px}
    .ft-top{padding:14px 24px}
}
@media(min-width:1024px){
    .ft-top{padding:16px 32px}
    .ft-search{max-width:480px}
}

/* =============================================
   COUNT BAR
   ============================================= */
.ft-bar{
    display:flex;justify-content:space-between;align-items:center;
    padding:10px calc(16px + var(--safe-l)) 10px calc(16px + var(--safe-r));
    border-bottom:1px solid var(--border);
    flex-wrap:wrap;gap:8px;
}
.ft-count{font-size:13px;color:var(--text-sub)}
.ft-count b{color:var(--text);font-weight:600}

.ft-loc-notice{
    background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:20px;
    padding:6px 12px;color:var(--text);font-size:12px;display:inline-flex;align-items:center;gap:5px;
}
.ft-loc-notice svg{width:14px;height:14px;stroke:#22C55E}

@media(min-width:768px){.ft-bar{padding:12px 24px}}

/* =============================================
   SPLIT LAYOUT — cards left, map right
   ============================================= */
.ft-main{display:flex;flex:1;min-height:auto;position:relative}

.ft-list{
    flex:1;
    padding:0 calc(16px + var(--safe-l)) calc(140px + var(--safe-b)) calc(16px + var(--safe-r));
    /* 140px bottom = room for map toggle (48px) + FAB (52px) + gaps */
    overflow:visible;
    -webkit-overflow-scrolling:touch;
}

/* Grid — mobile-first: always 2 columns for compact cards */
.ft-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;padding-top:12px}
@media(min-width:420px){.ft-grid{gap:14px}}
@media(min-width:520px){.ft-grid{gap:20px}}

/* =============================================
   TRAINER CARDS — Airbnb-style
   ============================================= */
.ft-card{
    display:block;text-decoration:none;color:inherit;
    border-radius:var(--r-lg);overflow:visible;
    transition:transform 0.15s;
    position:relative;
    /* Entire card is tap target — comfortably above 48px */
}
.ft-card:active{transform:scale(0.97)}

/* Card Image */
.ft-card-img{
    aspect-ratio:1; /* Square cards for compact mobile grid */
    border-radius:var(--r-lg);overflow:hidden;
    position:relative;background:#F0F0F0;
}
.ft-card-img img{
    width:100%;height:100%;object-fit:cover;object-position:center top;
    transition:transform 0.4s ease;
}

/* Level tag */
.ft-card-tag{
    position:absolute;top:8px;left:8px;
    padding:4px 8px;background:#fff;color:var(--text);
    font-size:10px;font-weight:600;border-radius:5px;
    box-shadow:0 1px 4px rgba(0,0,0,0.12);
    font-family:'Oswald',sans-serif;letter-spacing:0.03em;text-transform:uppercase;
}

/* Badges */
.ft-card-badges{position:absolute;top:8px;right:8px;display:flex;gap:4px}
.ft-badge{
    width:26px;height:26px;background:#fff;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 1px 4px rgba(0,0,0,0.12);
}
.ft-badge svg{width:11px;height:11px}
.ft-badge.v svg{stroke:#22C55E}
.ft-badge.f svg{fill:var(--gold);stroke:var(--gold)}
.ft-badge.m{background:var(--gold);border:none}
.ft-badge.m svg{stroke:#0A0A0A;fill:none}
.ft-pill-mentor{border-color:var(--gold) !important;color:var(--gold) !important}
.ft-pill-mentor.on{background:var(--gold) !important;border-color:var(--gold) !important;color:#0A0A0A !important}
.ft-card-mentor{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;color:var(--gold);margin-top:2px}
.ft-card-mentor svg{width:10px;height:10px;stroke:var(--gold);fill:none}

/* Supercoach */
.ft-supercoach .ft-card-img{border:2px solid var(--gold)}
.ft-supercoach-ribbon{
    position:absolute;top:0;left:0;right:0;
    background:var(--gold);color:var(--black);
    font-family:'Oswald',sans-serif;font-size:9px;font-weight:700;
    text-align:center;padding:4px 8px;letter-spacing:0.12em;text-transform:uppercase;
    z-index:10;border-radius:var(--r-lg) var(--r-lg) 0 0;
}
.ft-supercoach .ft-card-tag{top:32px}
.ft-supercoach .ft-card-badges{top:32px}

/* Card Body */
.ft-card-body{padding:6px 2px 0}
.ft-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:6px;margin-bottom:2px}
.ft-card-name{
    font-family:'Oswald',sans-serif;font-size:13px;font-weight:600;
    text-transform:uppercase;letter-spacing:0.02em;color:var(--text);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.ft-card-rating{
    display:flex;align-items:center;gap:3px;font-size:12px;font-weight:500;color:var(--text);
    flex-shrink:0;
}
.ft-card-rating svg{width:11px;height:11px;fill:var(--text);stroke:none}
.ft-card-rating span{color:var(--text-sub);font-weight:400;font-size:11px}
.ft-card-loc{font-size:12px;color:var(--text-sub);margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ft-card-cred{font-size:11px;color:var(--text-light);margin-bottom:4px}
.ft-card-price{font-size:13px;color:var(--text)}
.ft-card-price b{font-weight:600}
.ft-card-price span{color:var(--text-sub);font-weight:400}

/* Larger phones: scale up slightly */
@media(min-width:420px){
    .ft-card-name{font-size:14px}
    .ft-card-loc{font-size:12px;margin-bottom:2px}
    .ft-card-rating{font-size:13px}
    .ft-card-rating svg{width:12px;height:12px}
    .ft-card-price{font-size:14px}
    .ft-card-body{padding:8px 2px 0}
    .ft-card-tag{padding:5px 10px;font-size:11px;top:10px;left:10px}
    .ft-card-badges{top:10px;right:10px;gap:5px}
    .ft-badge{width:30px;height:30px}
    .ft-badge svg{width:13px;height:13px}
    .ft-supercoach-ribbon{font-size:10px;padding:5px 10px}
    .ft-supercoach .ft-card-tag{top:38px}
    .ft-supercoach .ft-card-badges{top:38px}
    .ft-card-cred{font-size:12px}
}
@media(min-width:768px){
    .ft-card-name{font-size:15px}
    .ft-card-loc{font-size:13px}
}

/* Skeleton */
@keyframes ftShimmer{0%{background-position:-200% 0}100%{background-position:200% 0}}
.ft-skel{background:linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);background-size:200% 100%;animation:ftShimmer 1.5s infinite;border-radius:var(--r)}
.ft-skel-card{border-radius:var(--r-lg);overflow:hidden}
.ft-skel-img{aspect-ratio:1;border-radius:var(--r-lg)}
.ft-skel-body{padding:10px 2px}
.ft-skel-line{height:14px;margin-bottom:8px;border-radius:4px}
.ft-skel-line.short{width:60%}
.ft-skel-line.med{width:80%}

/* Empty state */
.ft-empty{text-align:center;padding:60px 20px;grid-column:1/-1}
.ft-empty h3{font-size:18px;margin-bottom:8px;color:var(--text)}
.ft-empty p{color:var(--text-sub);font-size:14px}

/* =============================================
   MAP
   ============================================= */
.ft-map-wrap{display:none}
#map{width:100%;height:100%}

/* Mobile map overlay */
@media(max-width:1023px){
    .ft-map-wrap.on{display:block;position:fixed;top:0;left:0;right:0;bottom:0;z-index:200}
    #map{min-height:100vh;min-height:100dvh}
    .ft-map-close{
        position:absolute;top:calc(16px + var(--safe-t));right:16px;z-index:201;
        background:#fff;border:none;width:44px;height:44px;border-radius:50%;
        box-shadow:0 2px 12px rgba(0,0,0,.15);cursor:pointer;font-size:18px;
        display:none;align-items:center;justify-content:center;color:var(--text);
    }
    .ft-map-close:active{transform:scale(0.9);background:#f0f0f0}
    .ft-map-wrap.on .ft-map-close{display:flex}
}

/* Desktop: side-by-side — list scrolls independently, map stays fixed */
@media(min-width:1024px){
    .ft{min-height:100vh;display:block !important;overflow:visible !important}
    .ft-top{position:sticky;top:0;z-index:50}
    .ft-main{display:flex !important;flex-direction:row;max-width:1800px;margin:0 auto;height:calc(100vh - 73px);align-items:stretch;min-height:0}
    .ft-list{width:55%;padding:0 24px 40px;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;overscroll-behavior-y:contain}
    .ft-grid{grid-template-columns:repeat(2,1fr);gap:22px}
    .ft-card-img{aspect-ratio:4/5}
    .ft-card-name{font-size:16px}
    .ft-card-loc{font-size:13px}
    .ft-map-wrap{display:block !important;width:45%;height:100%;border-left:1px solid var(--border)}
    #map{min-height:100%;height:100%}
    .ft-map-toggle{display:none}
    .ft-map-close{display:none !important}
}
/* Mobile: ensure page-level scrolling, no trapped scroll */
@media(max-width:1023px){
    .ft{display:flex !important;flex-direction:column;overflow:visible !important;height:auto !important;min-height:100vh;min-height:100dvh}
    .ft-main{display:block !important;flex:none;min-height:auto;overflow:visible !important}
    .ft-list{overflow:visible !important;height:auto !important;min-height:auto}
}
@media(min-width:1280px){.ft-list{width:50%}.ft-map-wrap{width:50%}}
@media(min-width:1536px){.ft-grid{grid-template-columns:repeat(3,1fr)}.ft-card-img{aspect-ratio:1}}

/* Thin scrollbar for trainer list on desktop */
@media(min-width:1024px){
    .ft-list{scrollbar-width:thin;scrollbar-color:var(--border) transparent}
    .ft-list::-webkit-scrollbar{width:6px}
    .ft-list::-webkit-scrollbar-track{background:transparent}
    .ft-list::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
    .ft-list::-webkit-scrollbar-thumb:hover{background:#ccc}
}

/* Map toggle — fixed bottom on mobile */
.ft-map-toggle{
    position:fixed;
    bottom:calc(20px + var(--safe-b));left:50%;transform:translateX(-50%);
    z-index:100;background:var(--black);color:#fff;
    padding:12px 24px;border-radius:24px;
    font-family:'Oswald',sans-serif;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;
    border:none;box-shadow:0 4px 16px rgba(0,0,0,.25);
    display:flex;align-items:center;gap:8px;cursor:pointer;
    min-height:48px; /* Touch target */
    transition:all 0.15s;
}
.ft-map-toggle:active{transform:translateX(-50%) scale(0.93)}
.ft-map-toggle svg{width:16px;height:16px}

/* =============================================
   FLOATING MATCHER BUTTON + MODAL
   ============================================= */
/* =============================================
   PROMO BANNER
   ============================================= */
.ft-promo{
    display:flex;align-items:center;gap:14px;
    background:linear-gradient(135deg,#FFFBEB 0%,#FFF 100%);
    border:1px solid rgba(252,185,0,0.3);border-radius:var(--r-lg);
    padding:14px 16px;margin:16px 0 0;flex-wrap:wrap;
}
.ft-promo-badge{
    background:var(--gold);color:var(--black);font-size:10px;font-weight:700;
    text-transform:uppercase;letter-spacing:0.1em;padding:4px 12px;border-radius:20px;
    white-space:nowrap;font-family:'Oswald',sans-serif;
}
.ft-promo-content{flex:1;min-width:160px}
.ft-promo-title{font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;color:var(--text);line-height:1.2}
.ft-promo-title em{color:var(--gold);font-style:normal}
.ft-promo-desc{font-size:12px;color:var(--text-sub);margin-top:1px}
.ft-promo-btn{
    background:var(--gold);color:var(--black);font-weight:700;font-size:13px;
    text-decoration:none;padding:12px 20px;border-radius:8px;white-space:nowrap;
    font-family:'Oswald',sans-serif;text-transform:uppercase;letter-spacing:0.03em;
    transition:background 0.15s;min-height:44px;display:inline-flex;align-items:center;
}
.ft-promo-btn:active{background:var(--gold-hover);transform:scale(0.97)}
@media(max-width:520px){
    .ft-promo{flex-direction:column;text-align:center;gap:10px}
    .ft-promo-btn{width:100%;justify-content:center}
}

/* =============================================
   HOVER GUARD — only on real hover devices
   ============================================= */
@media(hover:hover){
    .ft-locate:hover{color:var(--gold)}
    .ft-search-btn:hover{background:var(--gold-hover)}
    .ft-pill:hover{border-color:var(--black)}
    .ft-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-card-hover)}
    .ft-card:hover img{transform:scale(1.03)}
    .ft-match-fab:hover{background:var(--gold-hover);box-shadow:0 6px 28px rgba(252,185,0,0.5)}
    .ft-match-close:hover{background:rgba(255,255,255,0.2)}
    .ft-promo-btn:hover{background:var(--gold-hover)}
}

/* Touch device — kill sticky hover */
@media(hover:none)and(pointer:coarse){
    .ft-card:hover{transform:none;box-shadow:none}
    .ft-card:hover img{transform:none}
}

/* =============================================
   ULTRA SMALL (< 380px)
   ============================================= */
@media(max-width:380px){
    .ft-top{padding:10px calc(12px + var(--safe-l)) 10px calc(12px + var(--safe-r))}
    .ft-top-inner{gap:6px}
    .ft-pill{padding:8px 12px;font-size:12px;min-height:40px}
    .ft-card-name{font-size:12px}
    .ft-card-loc{font-size:11px}
    .ft-card-price{font-size:12px}
    .ft-card-cred{font-size:10px}
    .ft-bar{padding:8px calc(12px + var(--safe-l))}
    .ft-list{padding-left:calc(12px + var(--safe-l));padding-right:calc(12px + var(--safe-r))}
    .ft-match-fab{padding:12px 16px;font-size:12px;right:calc(12px + var(--safe-r))}
    .ft-match-fab span.ft-match-label{display:none} /* Icon only on tiny screens */
    .ft-grid{gap:8px}
    .ft-promo{padding:12px}
    .ft-card-tag{padding:4px 8px;font-size:9px;top:6px;left:6px}
    .ft-card-badges{top:6px;right:6px}
    .ft-badge{width:24px;height:24px}
    .ft-badge svg{width:10px;height:10px}
    .ft-supercoach .ft-card-tag{top:32px}
    .ft-supercoach .ft-card-badges{top:32px}
    .ft-supercoach-ribbon{font-size:8px;padding:4px 8px}
}
</style>

<script>document.body.classList.add('ft-body');
(function(){['header.ptp-header','#ptpHeader','.ptp-bottom-nav','.ptp-mobile-nav','.ptp-mobile-nav-overlay'].forEach(function(s){document.querySelectorAll(s).forEach(function(el){el.style.display='none';});});})();
</script>

<div class="ft">

<!-- SEARCH BAR — Airbnb-style sticky top -->
<div class="ft-top">
    <div class="ft-top-inner">
        <div class="ft-search">
            <button type="button" class="ft-locate" id="locateBtn" title="Use my location">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>
            </button>
            <input type="text" id="locInput" placeholder="<?php echo $seo_location ? esc_attr($seo_location) : 'City or zip code...'; ?>" value="<?php echo esc_attr($seo_location); ?>" autocomplete="address-level2" inputmode="text">
            <button class="ft-search-btn" id="searchBtn">
                <svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            </button>
        </div>
        <div class="ft-filters">
            <span class="ft-pill on" data-lv="">All</span>
            <span class="ft-pill" data-lv="pro">Pro</span>
            <span class="ft-pill" data-lv="college_d1">NCAA D1</span>
            <span class="ft-pill" data-lv="college_d2">D2/D3</span>
            <span class="ft-pill" data-lv="academy">Academy</span>
            <span class="ft-pill ft-pill-mentor" data-lv="mentorship">Mentorship</span>
        </div>
        <div class="ft-sort">
            <select id="sortSel">
                <option value="featured">Featured</option>
                <option value="distance">Nearest</option>
                <option value="rating">Highest Rated</option>
                <option value="price_low">Price: Low-High</option>
                <option value="price_high">Price: High-Low</option>
            </select>
        </div>
    </div>
</div>

<!-- COUNT BAR -->
<div class="ft-bar">
    <div class="ft-count">
        <b id="cnt"><?php echo $count; ?></b> trainers
        <?php if ($seo_location): ?> in <?php echo esc_html($seo_location); ?><?php endif; ?>
    </div>
    <div class="ft-loc-notice" id="locNotice" style="display:none">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <span id="locText">Near you</span>
    </div>
</div>

<!-- SPLIT LAYOUT -->
<main class="ft-main">
    <div class="ft-list">
        <div class="ft-grid" id="grid"></div>
    </div>
    
    <?php if($google_maps_key): ?>
    <div class="ft-map-wrap" id="mapWrap">
        <button class="ft-map-close" id="mapClose">&times;</button>
        <div id="map"></div>
    </div>
    <?php endif; ?>
</main>

<!-- Mobile map toggle -->
<button class="ft-map-toggle" id="mapToggle">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
    Map
</button>

</div><!-- .ft -->

<?php if($google_maps_key): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr($google_maps_key); ?>&libraries=places&callback=initMap" async defer></script>
<?php endif; ?>

<script>
(function(){
var data=<?php echo json_encode(array_map(function($t) use ($level_labels) {
    $training_locs = array();
    $map_lat = floatval($t->latitude ?: 0);
    $map_lng = floatval($t->longitude ?: 0);
    $primary_training_location = '';
    
    if (!empty($t->training_locations)) {
        $locs = json_decode($t->training_locations, true);
        // v213: Fallback for WP magic quotes corrupted JSON
        if (!is_array($locs)) $locs = json_decode(wp_unslash($t->training_locations), true);
        if (is_array($locs)) {
            $training_locs = $locs;
            foreach ($locs as $loc) {
                if (empty($primary_training_location) && !empty($loc['name'])) {
                    $primary_training_location = $loc['name'];
                }
                if (!empty($loc['address'])) {
                    if (empty($primary_training_location)) {
                        $primary_training_location = $loc['address'];
                    }
                }
                if (!empty($loc['lat']) && !empty($loc['lng'])) {
                    $map_lat = floatval($loc['lat']);
                    $map_lng = floatval($loc['lng']);
                    break;
                }
            }
        }
    }
    
    return array(
        'id'=>$t->id,'name'=>$t->display_name,
        'slug'=>$t->slug?:sanitize_title($t->display_name),
        'photo'=>$t->photo_url?:'https://ui-avatars.com/api/?name='.urlencode($t->display_name).'&size=400&background=FCB900&color=0A0A0A&bold=true',
        'level'=>$t->playing_level,
        'tag'=>$level_labels[$t->playing_level]??strtoupper($t->playing_level?:'PRO'),
        'rate'=>intval($t->hourly_rate?:60),
        'rating'=>floatval($t->avg_rating?:5),
        'reviews'=>intval($t->reviews),
        'city'=>$t->city?:'','state'=>$t->state?:'',
        'lat'=>$map_lat,'lng'=>$map_lng,
        'home_lat'=>floatval($t->latitude?:0),'home_lng'=>floatval($t->longitude?:0),
        'training_locations'=>$training_locs,
        'primary_location'=>$primary_training_location,
        'featured'=>intval($t->is_featured?:0),'verified'=>intval($t->is_verified?:0),
        'supercoach'=>(floatval($t->avg_rating?:0) >= 4.9 && intval($t->reviews) >= 10) ? 1 : 0,
        'mentorship'=>intval($t->mentorship_enabled?:0),
        'mentorship_price'=>intval($t->mentorship_enabled ? 49 : 0),
        'distance'=>null
    );
}, $trainers)); ?>;

var cityCoords = {
    'philadelphia,pa':{lat:39.9526,lng:-75.1652},'philadelphia area,pa':{lat:39.9526,lng:-75.1652},
    'cherry hill,nj':{lat:39.9346,lng:-74.9981},'wilmington,de':{lat:39.7391,lng:-75.5398},
    'baltimore,md':{lat:39.2904,lng:-76.6122},'new york,ny':{lat:40.7128,lng:-74.0060},
    'princeton,nj':{lat:40.3573,lng:-74.6672},'wayne,pa':{lat:40.0440,lng:-75.3877},
    'media,pa':{lat:39.9168,lng:-75.3877},'newtown,pa':{lat:40.2293,lng:-74.9368},
    'doylestown,pa':{lat:40.3101,lng:-75.1299},'king of prussia,pa':{lat:40.0893,lng:-75.3963},
    'west chester,pa':{lat:39.9607,lng:-75.6055},'malvern,pa':{lat:40.0362,lng:-75.5138},
    'radnor,pa':{lat:40.0462,lng:-75.3599},'villanova,pa':{lat:40.0388,lng:-75.3463},
    'ardmore,pa':{lat:40.0065,lng:-75.2913},'bryn mawr,pa':{lat:40.0220,lng:-75.3163},
    'haverford,pa':{lat:40.0093,lng:-75.3049},'conshohocken,pa':{lat:40.0793,lng:-75.3016},
    'trenton,nj':{lat:40.2206,lng:-74.7597},'camden,nj':{lat:39.9259,lng:-75.1196},
    'newark,de':{lat:39.6837,lng:-75.7497},'kinzers,pa':{lat:39.9990,lng:-76.0551},
    'lancaster,pa':{lat:40.0379,lng:-76.3055},'exton,pa':{lat:40.0290,lng:-75.6213},
    'devon,pa':{lat:40.0454,lng:-75.4238},'berwyn,pa':{lat:40.0454,lng:-75.4385},
    'paoli,pa':{lat:40.0426,lng:-75.4813},'downingtown,pa':{lat:40.0065,lng:-75.7035},
    'coatesville,pa':{lat:39.9835,lng:-75.8238},'kennett square,pa':{lat:39.8468,lng:-75.7113},
    'springfield,pa':{lat:39.9301,lng:-75.3202},'swarthmore,pa':{lat:39.9018,lng:-75.3499},
    'upper darby,pa':{lat:39.9593,lng:-75.2602},'drexel hill,pa':{lat:39.9468,lng:-75.2924},
    'norristown,pa':{lat:40.1218,lng:-75.3399},'blue bell,pa':{lat:40.1526,lng:-75.2660},
    'plymouth meeting,pa':{lat:40.1026,lng:-75.2749},'voorhees,nj':{lat:39.8460,lng:-74.9529},
    'haddonfield,nj':{lat:39.8912,lng:-75.0368},'moorestown,nj':{lat:39.9690,lng:-74.9490},
    'mount laurel,nj':{lat:39.9340,lng:-74.8913}
};

data.forEach(function(t){
    if(!t.lat||!t.lng||(t.lat===0&&t.lng===0)){
        var key=((t.city||'philadelphia')+','+(t.state||'pa')).toLowerCase().trim();
        var coords=cityCoords[key];
        if(!coords){coords=cityCoords[(t.city||'philadelphia').toLowerCase().trim()+',pa'];}
        if(!coords){coords={lat:39.9526+(Math.random()-0.5)*0.15,lng:-75.1652+(Math.random()-0.5)*0.15};}
        else{coords={lat:coords.lat+(Math.random()-0.5)*0.03,lng:coords.lng+(Math.random()-0.5)*0.03};}
        t.lat=coords.lat;t.lng=coords.lng;
    }
});

var grid=document.getElementById('grid'),cnt=document.getElementById('cnt'),
    pills=document.querySelectorAll('.ft-pill'),sortSel=document.getElementById('sortSel'),
    mapWrap=document.getElementById('mapWrap'),mapToggle=document.getElementById('mapToggle'),
    mapClose=document.getElementById('mapClose'),locateBtn=document.getElementById('locateBtn'),
    locNotice=document.getElementById('locNotice'),locText=document.getElementById('locText'),
    locInput=document.getElementById('locInput'),
    lv='',sort='featured',userLat=null,userLng=null,gmap=null,markers=[],
    base='<?php echo home_url('/trainer/'); ?>';

function calcDist(lat1,lng1,lat2,lng2){
    var R=3959,dLat=(lat2-lat1)*Math.PI/180,dLng=(lng2-lng1)*Math.PI/180;
    var a=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLng/2)*Math.sin(dLng/2);
    return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

function updateDist(){
    if(!userLat||!userLng)return;
    data.forEach(function(t){if(t.lat&&t.lng)t.distance=calcDist(userLat,userLng,t.lat,t.lng);});
}

function showSkeletons(n){
    var s='';for(var i=0;i<(n||6);i++){
        s+='<div class="ft-skel-card"><div class="ft-skel ft-skel-img"></div><div class="ft-skel-body"><div class="ft-skel ft-skel-line med"></div><div class="ft-skel ft-skel-line short"></div><div class="ft-skel ft-skel-line" style="width:80px;height:16px;margin-top:4px"></div></div></div>';
    }
    grid.innerHTML=s;
}

function render(showLoading){
    if(showLoading){showSkeletons(6);setTimeout(renderCards,200);}
    else renderCards();
}

function renderCards(){
    var list=data.slice();
    if(lv==='mentorship')list=list.filter(function(t){return t.mentorship;});
    else if(lv)list=list.filter(function(t){return lv==='college_d2'?(t.level==='college_d2'||t.level==='college_d3'):t.level===lv;});
    if(sort==='distance'&&userLat)list.sort(function(a,b){return(a.distance||999)-(b.distance||999);});
    else if(sort==='rating')list.sort(function(a,b){return b.rating-a.rating;});
    else if(sort==='price_low')list.sort(function(a,b){return a.rate-b.rate;});
    else if(sort==='price_high')list.sort(function(a,b){return b.rate-a.rate;});
    else list.sort(function(a,b){
        if(b.supercoach!==a.supercoach)return b.supercoach-a.supercoach;
        if(b.featured!==a.featured)return b.featured-a.featured;
        return b.rating-a.rating;
    });
    cnt.textContent=list.length;
    if(!list.length){grid.innerHTML='<div class="ft-empty"><h3>No trainers found</h3><p>Try adjusting your filters or location</p></div>';return;}
    
    grid.innerHTML=list.map(function(t){
        var loc=t.primary_location||(t.city&&t.state?t.city+', '+t.state:(t.city||t.state||'Philadelphia Area'));
        var dist=t.distance!==null&&t.distance<100?' · '+t.distance.toFixed(1)+' mi':'';
        var badges='';
        if(t.verified)badges+='<span class="ft-badge v"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></span>';
        if(t.featured)badges+='<span class="ft-badge f"><svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>';
        if(t.mentorship)badges+='<span class="ft-badge m" title="Offers Mentorship"><svg viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></span>';
        var mentorLine=t.mentorship?'<div class="ft-card-mentor"><svg viewBox="0 0 24 24" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/></svg>Mentorship from $49/s</div>':'';
        var ratingDisplay=t.reviews>0?'<svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> '+t.rating.toFixed(1)+' <span>('+t.reviews+')</span>':'<span style="color:var(--gold)">New</span>';
        var cardClass='ft-card'+(t.supercoach?' ft-supercoach':'');
        var scRibbon=t.supercoach?'<div class="ft-supercoach-ribbon">SUPERCOACH</div>':'';
        
        return '<a href="'+base+t.slug+'/'+(lv==='mentorship'?'?mentorship=1':'')+'" class="'+cardClass+'">'+scRibbon+
            '<div class="ft-card-img"><img src="'+t.photo+'" alt="'+t.name+'" loading="lazy">'+
            '<span class="ft-card-tag">'+t.tag+'</span>'+
            (badges?'<div class="ft-card-badges">'+badges+'</div>':'')+
            '</div>'+
            '<div class="ft-card-body">'+
                '<div class="ft-card-head"><div class="ft-card-name">'+t.name.toUpperCase()+'</div><div class="ft-card-rating">'+ratingDisplay+'</div></div>'+
                '<div class="ft-card-loc">'+loc+dist+'</div>'+
                '<div class="ft-card-price"><b>'+(t.rate>0?'$'+t.rate:'Free Intro')+'</b> '+(t.rate>0?'<span>/ session</span>':'')+'</div>'+
                mentorLine+
            '</div></a>';
    }).join('');
    if(gmap)updateMarkers(list);
}

pills.forEach(function(p){p.onclick=function(){pills.forEach(function(x){x.classList.remove('on');});p.classList.add('on');lv=p.dataset.lv;render(true);};});
sortSel.onchange=function(){sort=sortSel.value;render(true);};

if(mapToggle)mapToggle.onclick=function(){mapWrap.classList.add('on');if(!gmap&&typeof google!=='undefined')initMap();};
if(mapClose)mapClose.onclick=function(){mapWrap.classList.remove('on');};

function autoLocate(){
    if(!('geolocation' in navigator))return;
    locateBtn.classList.add('loading');
    navigator.geolocation.getCurrentPosition(function(pos){
        userLat=pos.coords.latitude;userLng=pos.coords.longitude;
        updateDist();locNotice.style.display='inline-flex';
        locText.textContent='Near your location';
        sortSel.value='distance';sort='distance';render();
        locateBtn.classList.remove('loading');
        if(gmap){gmap.setCenter({lat:userLat,lng:userLng});gmap.setZoom(11);}
        if(typeof google!=='undefined'&&google.maps){
            new google.maps.Geocoder().geocode({location:{lat:userLat,lng:userLng}},function(r,s){
                if(s==='OK'&&r[0]){var city='';r[0].address_components.forEach(function(c){if(c.types.includes('locality'))city=c.long_name;});
                if(city){locText.textContent='Near '+city;locInput.value=city;}}
            });
        }
    },function(){locateBtn.classList.remove('loading');},{enableHighAccuracy:false,timeout:10000,maximumAge:300000});
}
locateBtn.onclick=autoLocate;

document.getElementById('searchBtn').onclick=function(){
    var q=locInput.value.trim();if(!q||typeof google==='undefined')return;
    new google.maps.Geocoder().geocode({address:q+', USA'},function(r,s){
        if(s==='OK'&&r[0]){
            userLat=r[0].geometry.location.lat();userLng=r[0].geometry.location.lng();
            updateDist();locNotice.style.display='inline-flex';locText.textContent='Near '+q;
            sortSel.value='distance';sort='distance';render();
            if(gmap){gmap.setCenter({lat:userLat,lng:userLng});gmap.setZoom(11);}
        }
    });
};

// MAP
window.initMap=function(){
    gmap=new google.maps.Map(document.getElementById('map'),{
        zoom:9,center:{lat:39.95,lng:-75.17},
        disableDefaultUI:true,zoomControl:true,
        styles:[
            {elementType:'geometry',stylers:[{color:'#f5f5f5'}]},
            {elementType:'labels.text.fill',stylers:[{color:'#616161'}]},
            {elementType:'labels.text.stroke',stylers:[{color:'#f5f5f5'}]},
            {featureType:'administrative',elementType:'geometry',stylers:[{visibility:'off'}]},
            {featureType:'administrative.locality',elementType:'labels.text.fill',stylers:[{color:'#333'}]},
            {featureType:'poi',stylers:[{visibility:'off'}]},
            {featureType:'road',elementType:'geometry',stylers:[{color:'#ffffff'}]},
            {featureType:'road',elementType:'geometry.stroke',stylers:[{color:'#e5e5e5'}]},
            {featureType:'road',elementType:'labels.text.fill',stylers:[{color:'#9e9e9e'}]},
            {featureType:'road.highway',elementType:'geometry',stylers:[{color:'#dadada'}]},
            {featureType:'transit',stylers:[{visibility:'off'}]},
            {featureType:'water',elementType:'geometry',stylers:[{color:'#c9c9c9'}]}
        ]
    });
    updateMarkers(data);
    setTimeout(autoLocate,800);
};

function updateMarkers(list){
    markers.forEach(function(m){m.setMap(null);});markers=[];
    var bounds=new google.maps.LatLngBounds(),has=false;
    var geocoder=new google.maps.Geocoder();
    var pendingGeocodes=0;
    if(!window.geocodeCache)window.geocodeCache={};

    list.forEach(function(t){
        if(t.training_locations&&t.training_locations.length>0){
            t.training_locations.forEach(function(loc){
                if(loc.lat&&loc.lng&&loc.lat!==0&&loc.lng!==0){
                    createPriceMarker(t,parseFloat(loc.lat),parseFloat(loc.lng),bounds);has=true;return;
                }
                if(!loc.address)return;
                var ck=loc.address.toLowerCase().trim();
                if(window.geocodeCache[ck]){
                    var c=window.geocodeCache[ck];createPriceMarker(t,c.lat,c.lng,bounds);has=true;
                }else{
                    pendingGeocodes++;
                    geocoder.geocode({address:loc.address+', USA'},function(results,status){
                        pendingGeocodes--;
                        if(status==='OK'&&results[0]){
                            var lat=results[0].geometry.location.lat(),lng=results[0].geometry.location.lng();
                            window.geocodeCache[ck]={lat:lat,lng:lng};
                            createPriceMarker(t,lat,lng,bounds);has=true;
                            if(pendingGeocodes===0&&markers.length>0)fitMapBounds(bounds);
                        }
                    });
                }
            });
        }else if(t.lat&&t.lng&&(t.lat!==0||t.lng!==0)){
            has=true;createPriceMarker(t,t.lat,t.lng,bounds);
        }
    });

    if(userLat&&userLng){
        bounds.extend({lat:userLat,lng:userLng});
        new google.maps.Marker({position:{lat:userLat,lng:userLng},map:gmap,title:'You',
            icon:{path:google.maps.SymbolPath.CIRCLE,fillColor:'#3B82F6',fillOpacity:1,strokeColor:'#fff',strokeWeight:3,scale:8}
        });
    }
    if(pendingGeocodes===0&&has&&markers.length>0)fitMapBounds(bounds);
}

// Price bubble markers (Airbnb-style)
function createPriceMarker(t,lat,lng,bounds){
    var m=new google.maps.Marker({
        position:{lat:lat,lng:lng},map:gmap,title:t.name,
        icon:{url:'data:image/svg+xml,'+encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="60" height="28"><rect width="60" height="28" rx="14" fill="#0A0A0A"/><text x="30" y="18" text-anchor="middle" font-family="sans-serif" font-size="12" font-weight="700" fill="#FCB900">$'+t.rate+'</text></svg>'
        ),scaledSize:new google.maps.Size(60,28),anchor:new google.maps.Point(30,14)}
    });
    var rH=t.reviews>0?'<span style="color:#FCB900">'+t.rating.toFixed(1)+'</span>':'<span style="color:#FCB900">New</span>';
    var info=new google.maps.InfoWindow({
        content:'<div style="padding:10px;font-family:Inter;min-width:180px"><b style="font-family:Oswald;font-size:14px">'+t.name.toUpperCase()+'</b><br><span style="color:#717171;font-size:12px">'+t.tag+'</span><br>'+rH+' &middot; <b>$'+t.rate+'</b>/hr'+(t.distance!==null?'<br><span style="color:#717171;font-size:11px">'+t.distance.toFixed(1)+' mi</span>':'')+'<br><a href="'+base+t.slug+'/" style="color:#FCB900;font-weight:600;text-decoration:none">View Profile &rarr;</a></div>'
    });
    m.addListener('click',function(){info.open(gmap,m);});
    markers.push(m);bounds.extend({lat:lat,lng:lng});
}

function fitMapBounds(bounds){
    if(markers.length===1){gmap.setCenter(markers[0].getPosition());gmap.setZoom(12);}
    else if(markers.length>1){gmap.fitBounds(bounds);
        var listener=google.maps.event.addListener(gmap,'idle',function(){if(gmap.getZoom()>15)gmap.setZoom(15);google.maps.event.removeListener(listener);});
    }
}

// Initial render
showSkeletons(6);
setTimeout(function(){render();},300);

locInput.addEventListener('keypress',function(e){if(e.key==='Enter')document.getElementById('searchBtn').click();});

// Layout fixes
(function cleanPageLayout(){
    var ptpElements=document.querySelectorAll('.ptp-header,#ptpHeader,.ptp-bottom-nav,.ptp-mobile-nav,.ptp-mobile-nav-overlay');
    ptpElements.forEach(function(el){el.style.display='none';});
    var ft=document.querySelector('.ft');if(!ft)return;
    var el=ft;while(el&&el!==document.body){el.style.marginTop='0';el.style.paddingTop='0';el=el.parentElement;}
    var containers=['#page','#content','#primary','.site-content','.entry-content','article','main','.ast-container','.hentry'];
    containers.forEach(function(sel){var c=document.querySelector(sel);if(c){c.style.marginTop='0';c.style.paddingTop='0';}});
    var header=document.querySelector('header:not(.ptp-header),.site-header:not(.ptp-header),#masthead');
    if(header&&ft){var gap=ft.getBoundingClientRect().top-header.getBoundingClientRect().bottom;if(gap>2)ft.style.marginTop='-'+gap+'px';}
})();

window.addEventListener('load',function(){
    var ft=document.querySelector('.ft'),header=document.querySelector('header:not(.ptp-header),.site-header:not(.ptp-header),#masthead');
    if(header&&ft){var gap=ft.getBoundingClientRect().top-header.getBoundingClientRect().bottom;if(gap>2)ft.style.marginTop='-'+gap+'px';}
});
})();
</script>

<script>
(function(){
    function cleanup(){
        document.body.classList.remove('menu-open','modal-open','ptp-drawer-open','no-scroll','overflow-hidden');
        document.documentElement.classList.remove('menu-open','modal-open','ptp-drawer-open','no-scroll','overflow-hidden');
        if(document.body.style.overflow==='hidden'&&!document.querySelector('.ft-match-modal.on'))document.body.style.overflow='';
        if(document.body.style.position==='fixed'){document.body.style.position='';document.body.style.top='';document.body.style.width='';}
    }
    cleanup();
    window.addEventListener('load',cleanup);
    setTimeout(cleanup,500);setTimeout(cleanup,1000);setTimeout(cleanup,2000);
})();
</script>

<script type="application/ld+json">
{"@context":"https://schema.org","@type":"LocalBusiness","name":"<?php echo esc_attr(function_exists('ptp_email_brand') ? ptp_email_brand('company') : 'PTP'); ?> Training<?php echo $seo_location ? ' - '.$seo_location : ''; ?>","description":"<?php echo esc_attr($page_desc); ?>","url":"<?php echo esc_url(home_url('/find-trainers/')); ?>","telephone":"<?php echo esc_attr(function_exists('ptp_email_brand') ? ptp_email_brand('support_phone') : ''); ?>","priceRange":"$60-$120/hr","aggregateRating":{"@type":"AggregateRating","ratingValue":"4.9","reviewCount":"<?php echo $count * 5; ?>"}}
</script>
