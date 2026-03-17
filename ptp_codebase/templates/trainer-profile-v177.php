<?php
/**
 * Trainer Profile v210 - Complete Rebuild
 * 
 * v210 Changes:
 * - Complete frontend redesign — clean, modern, premium
 * - Google Places Autocomplete for location input
 * - Streamlined booking panel
 * - Proper WordPress page integration (not homepage)
 * - Responsive grid layout with sticky booking panel
 * - Improved accessibility and touch targets
 */
defined('ABSPATH') || exit;

add_filter('ptp_skip_header', '__return_true');

global $wpdb;

global $ptp_current_trainer;
$trainer = $ptp_current_trainer;

if (!$trainer) {
    $slug = get_query_var('trainer_slug');
    if (!$slug) {
        $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        $parts = explode('/', $uri);
        $slug = end($parts);
    }
    $trainer = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s AND status = 'active'",
        sanitize_title($slug)
    ));
}

if (!$trainer) {
    wp_redirect(home_url('/find-trainers/'));
    exit;
}

// v227: Use cached availability
$availability = class_exists('PTP_Query_Cache') 
    ? PTP_Query_Cache::get_trainer_availability($trainer->id)
    : $wpdb->get_results($wpdb->prepare(
        "SELECT day_of_week, start_time, end_time FROM {$wpdb->prefix}ptp_availability WHERE trainer_id = %d AND is_active = 1",
        $trainer->id
    ));

$avail_by_day = [];
foreach ($availability as $a) {
    if (!isset($avail_by_day[$a->day_of_week])) $avail_by_day[$a->day_of_week] = [];
    $avail_by_day[$a->day_of_week][] = ['start' => $a->start_time, 'end' => $a->end_time];
}

$booked = $wpdb->get_col($wpdb->prepare(
    "SELECT CONCAT(session_date, '_', start_time) FROM {$wpdb->prefix}ptp_bookings 
     WHERE trainer_id = %d AND session_date >= CURDATE() AND session_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
     AND status NOT IN ('cancelled', 'refunded')",
    $trainer->id
));
$booked_map = array_flip($booked);

$locations = $trainer->training_locations ? json_decode($trainer->training_locations, true) : [];
// v213: Fallback for data corrupted by WP magic quotes (backslash-escaped JSON)
if (!is_array($locations) && !empty($trainer->training_locations)) {
    $locations = json_decode(wp_unslash($trainer->training_locations), true);
}
if (!is_array($locations)) $locations = [];
$locations = array_values(array_filter($locations, function($l) { return !empty($l['name']); }));

// v213: Gallery images
$gallery = !empty($trainer->gallery) ? json_decode($trainer->gallery, true) : [];
if (!is_array($gallery) && !empty($trainer->gallery)) {
    $gallery = json_decode(wp_unslash($trainer->gallery), true);
}
if (!is_array($gallery)) $gallery = [];
$gallery = array_values(array_filter($gallery));

$google_maps_key = get_option('ptp_google_maps_api_key', '') ?: get_option('ptp_google_maps_key', '');

$available_dates = [];
$today = new DateTime();

// v216: Use the GCal Bridge to get REAL available dates
// This checks weekly schedule + exceptions + bookings + Google Calendar blocks
// So admin schedule changes are IMMEDIATELY reflected on the frontend
if (class_exists('PTP_Availability_GCal_Bridge')) {
    $month1 = intval(date('n'));
    $year1 = intval(date('Y'));
    $real_dates = PTP_Availability_GCal_Bridge::get_real_available_dates($trainer->id, $month1, $year1);
    // Also check next month if we're within 30 days of month end
    $month2 = $month1 === 12 ? 1 : $month1 + 1;
    $year2 = $month1 === 12 ? $year1 + 1 : $year1;
    $real_dates_next = PTP_Availability_GCal_Bridge::get_real_available_dates($trainer->id, $month2, $year2);
    $all_real = array_merge(array_keys($real_dates), array_keys($real_dates_next));
    sort($all_real);
    // Only include next 30 days
    $cutoff = (clone $today)->modify('+30 days')->format('Y-m-d');
    $today_str = $today->format('Y-m-d');
    foreach ($all_real as $date_str) {
        if ($date_str <= $today_str) continue;
        if ($date_str > $cutoff) break;
        $available_dates[] = $date_str;
    }
} else {
    // Fallback: direct DB query (legacy)
    $blocked_exception_dates = [];
    $exceptions = $wpdb->get_results($wpdb->prepare(
        "SELECT exception_date FROM {$wpdb->prefix}ptp_availability_exceptions 
         WHERE trainer_id = %d AND is_available = 0 AND exception_date >= CURDATE() AND exception_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
        $trainer->id
    ));
    foreach ($exceptions as $exc) {
        $blocked_exception_dates[$exc->exception_date] = true;
    }
    for ($i = 1; $i <= 30; $i++) {
        $d = clone $today;
        $d->modify("+$i days");
        $date_str = $d->format('Y-m-d');
        if (!isset($avail_by_day[$d->format('w')])) continue;
        if (isset($blocked_exception_dates[$date_str])) continue;
        $available_dates[] = $date_str;
    }
}

// v216: Also include open_dates (trainer-added specific dates)
if (class_exists('PTP_Availability')) {
    $open_dates = PTP_Availability::get_open_dates($trainer->id);
    foreach ($open_dates as $od) {
        if (!in_array($od->date, $available_dates) && $od->date > $today->format('Y-m-d')) {
            $available_dates[] = $od->date;
        }
    }
    sort($available_dates);
}

// v211: Enhanced reviews with dates, verification, responses (v227: cached 10min)
$_reviews_cache_key = 'ptp_profile_reviews_' . $trainer->id;
$reviews = get_transient($_reviews_cache_key);
if ($reviews === false) {
    $reviews = $wpdb->get_results($wpdb->prepare(
        "SELECT r.rating, r.review_text, r.created_at, r.is_verified, r.trainer_response, r.trainer_responded_at,
                COALESCE(u.display_name, 'Parent') as name
         FROM {$wpdb->prefix}ptp_reviews r LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
         WHERE r.trainer_id = %d AND r.status = 'published' AND r.is_public = 1
         ORDER BY r.created_at DESC LIMIT 20",
        $trainer->id
    ));
    set_transient($_reviews_cache_key, $reviews ?: array(), 10 * MINUTE_IN_SECONDS);
}

// Rating breakdown for star bars
$rating_breakdown = array(5=>0, 4=>0, 3=>0, 2=>0, 1=>0);
foreach ($reviews as $r) {
    $s = max(1, min(5, intval($r->rating)));
    $rating_breakdown[$s]++;
}
$total_rated = array_sum($rating_breakdown);

$rate = intval($trainer->hourly_rate ?: 60);
$photo = $trainer->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($trainer->display_name) . '&size=800&background=FCB900&color=0A0A0A&bold=true&format=png';
$cover = $trainer->cover_photo_url ?: $photo;

$cover_srcset = '';
if ($cover && strpos($cover, home_url()) !== false) {
    // v235.8: Cache attachment ID lookup — attachment_url_to_postid is very slow (full wp_posts meta scan)
    $srcset_cache_key = 'ptp_cover_srcset_' . $trainer->id;
    $cover_srcset = get_transient($srcset_cache_key);
    if ($cover_srcset === false) {
        $att_id = attachment_url_to_postid($cover);
        if (!$att_id && $trainer->cover_photo_url) $att_id = attachment_url_to_postid($trainer->cover_photo_url);
        if (!$att_id && $trainer->photo_url) $att_id = attachment_url_to_postid($trainer->photo_url);
        $cover_srcset = $att_id ? (wp_get_attachment_image_srcset($att_id, 'full') ?: '') : '';
        set_transient($srcset_cache_key, $cover_srcset, 12 * HOUR_IN_SECONDS);
    }
}

$bio = $trainer->bio ?: 'Professional soccer trainer dedicated to player development.';

// v218: Featured video — trainer-specific or default PTP reel
$default_video_url = 'https://ptpsummercamps.com/wp-content/uploads/2026/01/PTP-VIDEO-2-1.mp4';
$trainer_video_url = !empty($trainer->intro_video_url) ? $trainer->intro_video_url : $default_video_url;
$specialties = $trainer->specialties ? array_filter(array_map('trim', explode(',', $trainer->specialties))) : [];

// v134: Additional onboarding fields for profile display
$coaching_why = !empty($trainer->coaching_why) ? trim($trainer->coaching_why) : '';
$training_philosophy = !empty($trainer->training_philosophy) ? trim($trainer->training_philosophy) : '';
$team = !empty($trainer->team) ? trim($trainer->team) : '';
$experience_years = intval($trainer->experience_years ?? 0);
$years_coaching = intval($trainer->years_coaching ?? 0);
$travel_radius = intval($trainer->travel_radius ?? 15);
$has_experience_details = ($team || $experience_years > 0 || $years_coaching > 0);

$loc_display = '';
if (!empty($locations)) {
    $loc_names = array_filter(array_column($locations, 'name'));
    if (count($loc_names) === 1) $loc_display = $loc_names[0];
    elseif (count($loc_names) === 2) $loc_display = $loc_names[0] . ' & ' . $loc_names[1];
    elseif (count($loc_names) > 2) $loc_display = $loc_names[0] . ' + ' . (count($loc_names) - 1) . ' more';
}
if (!$loc_display) $loc_display = 'Philadelphia Area';

$levels = ['pro'=>'PRO','college_d1'=>'NCAA D1','college_d2'=>'NCAA D2','college_d3'=>'NCAA D3','academy'=>'ACADEMY','semi_pro'=>'SEMI-PRO'];
$level = $levels[$trainer->playing_level] ?? 'PRO TRAINER';

$review_count = intval($trainer->review_count ?: count($reviews));
$has_reviews = $review_count > 0;
$rating = $has_reviews ? number_format(floatval($trainer->average_rating ?: 5), 1) : null;
$sessions = intval($trainer->total_sessions ?: 0);
$first_name = explode(' ', $trainer->display_name)[0];
$is_supercoach = ($rating >= 4.9 && $review_count >= 10);
$ptp_nonce = wp_create_nonce('ptp_nonce');
$is_logged_in = is_user_logged_in();
$current_user = $is_logged_in ? wp_get_current_user() : null;

$pkgs = class_exists('PTP_Packages') ? array_intersect_key(PTP_Packages::build_options($rate), array_flip(['single','pack5','pack10'])) : [
    'single' => ['name' => '1 Session', 'count' => 1, 'price' => $rate, 'per' => $rate, 'save' => 0],
    'pack5'  => ['name' => '5-Pack', 'count' => 5, 'price' => intval($rate * 5 * 0.85), 'per' => intval($rate * 0.85), 'save' => intval($rate * 5 * 0.15)],
    'pack10' => ['name' => '10-Pack', 'count' => 10, 'price' => intval($rate * 10 * 0.8), 'per' => intval($rate * 0.8), 'save' => intval($rate * 10 * 0.2)],
];

// v223: Output OG meta, JSON-LD, fonts, CSS in <head> via wp_head (not in <body>)
// Mobile Safari is unreliable with <link> stylesheets and <meta> tags in the body.
// v235.7: Resource hints for faster mobile loading
add_action('wp_head', function() {
    echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link rel="dns-prefetch" href="//maps.googleapis.com">' . "\n";
}, 0);

add_action('wp_head', function() use ($trainer, $photo, $level, $rate, $has_reviews, $rating, $review_count, $bio, $locations, $available_dates, $google_maps_key) {
    // JSON-LD Structured Data
    $schema_data = [
        '@context' => 'https://schema.org',
        '@type' => 'SportsActivityLocation',
        'name' => $trainer->display_name . ' - PTP Soccer Training',
        'description' => wp_trim_words($bio, 30, '...'),
        'url' => home_url('/trainer/' . $trainer->slug . '/'),
        'image' => $photo,
        'priceRange' => '$' . $rate . '/session',
        'provider' => [
            '@type' => 'Person',
            'name' => $trainer->display_name,
            'image' => $photo,
            'jobTitle' => 'Soccer Trainer',
        ],
        'offers' => [
            '@type' => 'Offer',
            'price' => $rate,
            'priceCurrency' => 'USD',
            'availability' => count($available_dates) > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        ],
    ];
    if ($has_reviews && $rating) {
        $schema_data['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => $rating,
            'reviewCount' => $review_count,
            'bestRating' => 5,
            'worstRating' => 1,
        ];
    }
    if (!empty($locations[0]['address'])) {
        $schema_data['address'] = [
            '@type' => 'PostalAddress',
            'addressLocality' => $locations[0]['name'] ?? '',
            'streetAddress' => $locations[0]['address'] ?? '',
        ];
    }
    echo '<script type="application/ld+json">' . wp_json_encode($schema_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "</script>\n";
    ?>
    <!-- OG Meta Tags for social sharing -->
    <meta property="og:title" content="<?php echo esc_attr($trainer->display_name); ?> — PTP Soccer Training">
    <meta property="og:description" content="Book private soccer training with <?php echo esc_attr($trainer->display_name); ?>. <?php echo esc_attr($level); ?> level coach. Starting at $<?php echo $rate; ?>/session.<?php echo $has_reviews ? ' ' . $rating . '★ rating.' : ''; ?>">
    <meta property="og:image" content="<?php echo esc_url($photo); ?>">
    <meta property="og:url" content="<?php echo esc_url(home_url('/trainer/' . $trainer->slug . '/')); ?>">
    <meta property="og:type" content="profile">
    <meta property="og:site_name" content="PTP Soccer Camps">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo esc_attr($trainer->display_name); ?> — PTP Soccer Training">
    <meta name="twitter:description" content="Book private soccer training. <?php echo esc_attr($level); ?> level. $<?php echo $rate; ?>/session.">
    <meta name="twitter:image" content="<?php echo esc_url($photo); ?>">
    <?php /* v235.7: Google Maps API deferred to IntersectionObserver - see footer JS */ ?>
    <?php
}, 5);

// v242: Add tp210 body class so CSS can target Astra overrides
add_filter('body_class', function($classes) {
    $classes[] = 'tp210';
    return $classes;
});

// v235.3: Simple Astra overrides — filters only, no remove_all_actions
add_filter('astra_single_post_navigation_enabled', '__return_false');
add_filter('astra_the_title_enabled', '__return_false');
add_filter('astra_page_layout', function() { return 'no-sidebar'; });

// Inline CSS at wp_head priority 1 — hides Astra before anything paints
add_action('wp_head', function() {
    echo '<style id="ptp-astra-kill">';
    echo '.site-header,.main-header-bar,#masthead,.ast-mobile-header-wrap,.ast-above-header-wrap,.ast-below-header-wrap,#ast-fixed-header,.ast-primary-header,.ast-mobile-popup-drawer,#ast-hfb-above-header-bar,#ast-hfb-below-header-bar,#ast-hfb-header{display:none!important;height:0!important;overflow:hidden!important;pointer-events:none!important}';
    echo '.post-navigation,.ast-single-post-navigation,.navigation.post-navigation,.ast-pagination,.ast-post-navigation-wrapper{display:none!important}';
    echo 'nav.navigation.post-navigation .nav-links{display:none!important}';
    echo '.site-footer,#colophon,.ast-small-footer,.ast-footer-overlay,.site-below-footer-wrap{display:none!important;pointer-events:none!important}';
    echo '.ast-breadcrumbs-wrapper,.ast-single-post-order{display:none!important}';
    echo '.ast-separate-container .ast-article-single,.ast-separate-container .ast-article-post{padding:0!important;margin:0!important;background:transparent!important}';
    echo '.ast-separate-container #primary,.site-content .ast-container{padding:0!important;max-width:100%!important}';
    echo 'header.ptp-header,#ptpHeader,.ptp-bottom-nav{display:none!important}';
    echo '</style>' . "\n";
    // v242: Force body class immediately — belt-and-suspenders for Astra overrides
    echo '<script>document.addEventListener("DOMContentLoaded",function(){document.body.classList.add("tp210")})</script>' . "\n";
    // v235.7: Critical CSS for instant above-fold paint on mobile
    echo '<style id="ptp-critical">';
    echo '.tp210-wrap{max-width:100%;min-height:100vh;background:#fff;color:#111}';
    echo '.tp210-hero{position:relative;height:320px;overflow:hidden;background:#f4f4f5;contain:layout style}';
    echo '.tp210-hero img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 15%}';
    echo '.tp210-hero-grad{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 0%,transparent 30%,rgba(0,0,0,.12)50%,rgba(0,0,0,.62)75%,rgba(0,0,0,.88)100%)}';
    echo '.tp210-nav{position:absolute;top:0;left:0;right:0;display:flex;justify-content:space-between;align-items:center;padding:max(12px,env(safe-area-inset-top,12px)) 16px 12px;z-index:10}';
    echo '.tp210-hero-info{position:absolute;bottom:0;left:0;right:0;padding:28px 5vw;z-index:5}';
    echo '.tp210-name{font-family:"DM Serif Display",Georgia,serif;font-size:32px;font-weight:400;color:#fff;line-height:1.05}';
    echo '.tp210-stats{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid #e4e4e7;contain:layout style}';
    echo '.tp210-stat{text-align:center;padding:16px 8px;border-right:1px solid #e4e4e7}';
    echo '.tp210-stat:last-child{border-right:none}';
    echo '.tp210-stat-v{font-family:"DM Serif Display",Georgia,serif;font-size:24px;color:#111}';
    echo '.gold{color:#FCB900}';
    echo '</style>' . "\n";
}, 1);

// v235.8: Strip WordPress default bloat — none of this is needed on trainer profile
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'rest_output_link_wp_head');
remove_action('wp_head', 'wp_oembed_add_discovery_links');
remove_action('wp_head', 'wp_oembed_add_host_js');
add_action('wp_enqueue_scripts', function() {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('wc-blocks-style');
    wp_dequeue_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
}, 100);

get_header();
?>

<script>
if (!document.body.classList.contains('tp210')) document.body.classList.add('tp210');
</script>

<div class="tp210-wrap">
    <!-- HERO -->
    <div class="tp210-hero">
        <img src="<?php echo esc_url($cover); ?>"<?php if ($cover_srcset): ?> srcset="<?php echo esc_attr($cover_srcset); ?>" sizes="(max-width: 767px) 100vw, (max-width: 1200px) 100vw, 1400px"<?php endif; ?> alt="<?php echo esc_attr($trainer->display_name); ?>" loading="eager" fetchpriority="high" decoding="async" width="1200" height="800">
        <div class="tp210-hero-grad"></div>

        <nav class="tp210-nav">
            <div class="tp210-nav-l">
                <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="tp210-nav-btn" aria-label="Back" id="tpBackBtn">
                    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 19l-7-7 7-7"/></svg>
                </a>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="tp210-nav-logo">PTP</a>
            </div>
            <div class="tp210-nav-r">
                <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="tp210-nav-link">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <span>Trainers</span>
                </a>
                <?php if (is_user_logged_in()):
                    $dash_url = home_url('/parent-dashboard/');
                    if (class_exists('PTP_Trainer') && PTP_Trainer::get_by_user_id(get_current_user_id())) {
                        $dash_url = home_url('/trainer-dashboard/');
                    }
                ?>
                <a href="<?php echo esc_url($dash_url); ?>" class="tp210-nav-link">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>Dashboard</span>
                </a>
                <?php else: ?>
                <a href="<?php echo esc_url(home_url('/login/')); ?>" class="tp210-nav-link">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                    <span>Log In</span>
                </a>
                <?php endif; ?>
            </div>
        </nav>

        <div class="tp210-hero-info">
            <div class="tp210-badge"><?php echo $is_supercoach ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-1px"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> SUPERCOACH' : esc_html($level); ?></div>
            <h1 class="tp210-name"><?php echo esc_html($trainer->display_name); ?></h1>
            <div class="tp210-meta">
                <div class="tp210-meta-i">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?php echo esc_html($loc_display); ?>
                </div>
                <?php if ($has_reviews): ?>
                <div class="tp210-rating">
                    <svg viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    <?php echo esc_html($rating); ?>
                    <span class="tp210-rating-c">(<?php echo esc_html($review_count); ?>)</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- STATS -->
    <div class="tp210-stats">
        <div class="tp210-stat"><div class="tp210-stat-v gold"><?php echo esc_html($rate > 0 ? '$' . $rate : 'Free Intro'); ?></div><div class="tp210-stat-l"><?php echo esc_html($rate > 0 ? 'Per Hour' : 'First Session'); ?></div></div>
        <?php if ($sessions > 0): ?>
        <div class="tp210-stat"><div class="tp210-stat-v"><?php echo esc_html($sessions); ?></div><div class="tp210-stat-l">Sessions</div></div>
        <?php else: ?>
        <div class="tp210-stat"><div class="tp210-stat-v gold"><?php echo esc_html($level); ?></div><div class="tp210-stat-l">Level</div></div>
        <?php endif; ?>
        <?php if ($has_reviews): ?>
        <div class="tp210-stat"><div class="tp210-stat-v"><?php echo esc_html($rating); ?><svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:var(--tp-gold);vertical-align:-1px;margin-left:2px"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></div><div class="tp210-stat-l">Rating</div></div>
        <?php else: ?>
        <div class="tp210-stat"><div class="tp210-stat-v" style="color:var(--tp-gold);font-size:16px">NEW</div><div class="tp210-stat-l">Trainer</div></div>
        <?php endif; ?>
        <div class="tp210-stat"><div class="tp210-stat-v"><?php echo count($available_dates); ?></div><div class="tp210-stat-l">Open Days</div></div>
    </div>

    <!-- MAIN LAYOUT -->
    <div class="tp210-layout">
        <div class="tp210-content">
            <div class="tp210-tabs" id="tpTabs">
                <button class="tp210-tab on" data-tab="about">About</button>
                <button class="tp210-tab" data-tab="train">Train</button>
                <?php if ($reviews): ?><button class="tp210-tab" data-tab="reviews">Reviews (<?php echo esc_html($review_count); ?>)</button><?php endif; ?>
                <button class="tp210-tab" data-tab="locations">Locations<?php if ($locations): ?> (<?php echo count($locations); ?>)<?php endif; ?></button>
            </div>

            <!-- About -->
            <div class="tp210-panel on" id="tab-about">
                <div class="tp210-block">
                    <div class="tp210-block-hdr">
                        <div class="tp210-block-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                        <div class="tp210-block-lbl">About <?php echo esc_html($first_name); ?></div>
                    </div>
                    <p class="tp210-bio"><?php echo nl2br(esc_html($bio)); ?></p>
                    <?php if ($specialties): ?>
                    <div class="tp210-tags">
                        <?php foreach ($specialties as $s): ?><span class="tp210-tag"><?php echo esc_html($s); ?></span><?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($has_experience_details || $training_philosophy || $coaching_why): ?>
                <!-- v134: Experience & Background -->
                <?php if ($has_experience_details): ?>
                <div class="tp210-block">
                    <div class="tp210-block-hdr">
                        <div class="tp210-block-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                        <div class="tp210-block-lbl">Experience</div>
                    </div>
                    <div class="tp210-exp-grid">
                        <?php if ($team): ?>
                        <div class="tp210-exp-item" style="grid-column:1/-1">
                            <div class="tp210-exp-val"><?php echo esc_html($team); ?></div>
                            <div class="tp210-exp-lbl">Team / Club</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($experience_years > 0): ?>
                        <div class="tp210-exp-item">
                            <div class="tp210-exp-val"><?php echo esc_html($experience_years); ?>+ Years</div>
                            <div class="tp210-exp-lbl">Playing</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($years_coaching > 0): ?>
                        <div class="tp210-exp-item">
                            <div class="tp210-exp-val"><?php echo esc_html($years_coaching); ?>+ Years</div>
                            <div class="tp210-exp-lbl">Coaching</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($travel_radius > 0): ?>
                    <div class="tp210-travel">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        Travels up to <?php echo esc_html($travel_radius); ?> miles
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($training_philosophy): ?>
                <div class="tp210-block">
                    <div class="tp210-block-hdr">
                        <div class="tp210-block-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
                        <div class="tp210-block-lbl">Training Philosophy</div>
                    </div>
                    <p class="tp210-philosophy"><?php echo nl2br(esc_html($training_philosophy)); ?></p>
                </div>
                <?php endif; ?>
                <?php if ($coaching_why): ?>
                <div class="tp210-block">
                    <div class="tp210-block-hdr">
                        <div class="tp210-block-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg></div>
                        <div class="tp210-block-lbl">Why I Coach</div>
                    </div>
                    <p class="tp210-philosophy"><?php echo nl2br(esc_html($coaching_why)); ?></p>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($gallery)): ?>
                <div class="tp210-block">
                    <div class="tp210-block-hdr">
                        <div class="tp210-block-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>
                        <div class="tp210-block-lbl">Gallery</div>
                    </div>
                    <div class="tp210-gallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-top:12px">
                        <?php foreach ($gallery as $gi => $img_url): ?>
                        <div class="tp210-gallery-img" onclick="ptpOpenLightbox(<?php echo intval($gi); ?>)" style="aspect-ratio:1;border-radius:8px;overflow:hidden;cursor:pointer;position:relative">
                            <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($trainer->display_name); ?> training" loading="lazy" style="width:100%;height:100%;object-fit:cover;transition:transform .3s" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="tp210-block">
                    <div class="tp210-contact" onclick="ptpOpenMsg()" role="button" tabindex="0">
                        <div class="tp210-contact-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
                        <div>
                            <div class="tp210-contact-txt">Message <?php echo esc_html($first_name); ?></div>
                            <div class="tp210-contact-sub">Ask about availability, training details, or introduce your player</div>
                        </div>
                    </div>
                </div>

                <!-- FEATURED VIDEO v218 -->
                <?php if ($trainer_video_url): ?>
                <div class="tp210-block">
                    <div class="tp210-block-hdr">
                        <div class="tp210-block-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg></div>
                        <div class="tp210-block-lbl">See <?php echo esc_html($first_name); ?> in Action</div>
                    </div>
                    <div class="tp210-vid" id="tpVid" onclick="tpVidToggle()">
                        <video id="tpVideo" playsinline preload="metadata" muted loop poster="<?php echo esc_url($cover); ?>">
                            <source src="<?php echo esc_url($trainer_video_url); ?>" type="video/mp4">
                        </video>
                        <div class="tp210-vid-overlay" id="tpVidOverlay">
                            <div class="tp210-vid-play"><svg viewBox="0 0 24 24"><polygon points="6,3 20,12 6,21"/></svg></div>
                        </div>
                        <button type="button" class="tp210-vid-mute" id="tpVidMute" onclick="event.stopPropagation();tpVidMuteToggle()" aria-label="Toggle sound">
                            <svg id="tpMuteIcon" viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" fill="#fff" stroke="none"/><path d="M15.54 8.46a5 5 0 010 7.07"/><path d="M19.07 4.93a10 10 0 010 14.14"/></svg>
                        </button>
                        <div class="tp210-vid-progress" id="tpVidProgress" style="width:0%"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ═══ TRAIN TAB — Services Hub ═══ -->
            <div class="tp210-panel" id="tab-train">
                <!-- PRIVATE TRAINING CARD -->
                <div class="tp210-svc-card tp210-svc-training" id="svcTraining">
                    <div class="tp210-svc-badge">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        IN-PERSON
                    </div>
                    <h3 class="tp210-svc-title">Private Training</h3>
                    <p class="tp210-svc-desc">1-on-1 or small group sessions at a local field. Work on exactly what your player needs — footwork, finishing, positioning, confidence.</p>

                    <div class="tp210-svc-stats">
                        <div class="tp210-svc-stat">
                            <div class="tp210-svc-stat-v">$<?php echo esc_html($rate); ?></div>
                            <div class="tp210-svc-stat-l">Per Session</div>
                        </div>
                        <div class="tp210-svc-stat">
                            <div class="tp210-svc-stat-v"><?php echo count($available_dates); ?></div>
                            <div class="tp210-svc-stat-l">Open Dates</div>
                        </div>
                        <div class="tp210-svc-stat">
                            <div class="tp210-svc-stat-v">1–3</div>
                            <div class="tp210-svc-stat-l">Players</div>
                        </div>
                    </div>

                    <!-- Package Quick-View -->
                    <div class="tp210-svc-pkgs">
                        <?php foreach ($pkgs as $k => $p): ?>
                        <div class="tp210-svc-pkg<?php echo $k === 'pack5' ? ' tp210-svc-pkg-pop' : ''; ?>">
                            <?php if ($k === 'pack5'): ?><div class="tp210-svc-pkg-flag">POPULAR</div><?php endif; ?>
                            <div class="tp210-svc-pkg-name"><?php echo esc_html($p['name']); ?></div>
                            <div class="tp210-svc-pkg-price">$<?php echo esc_html($p['price']); ?></div>
                            <div class="tp210-svc-pkg-per">$<?php echo esc_html($p['per']); ?>/session</div>
                            <?php if ($p['save']): ?><div class="tp210-svc-pkg-save">Save $<?php echo esc_html($p['save']); ?></div><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="tp210-svc-features">
                        <?php foreach (['Choose your schedule','Pick your location','Train with siblings or friends','Free 24hr cancellation'] as $f): ?>
                        <div class="tp210-svc-feat"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> <?php echo esc_html($f); ?></div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="tp210-svc-cta" id="svcBookBtn">
                        Book a Session
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </div>

<?php
// ── Mentorship data (shared with old card, now in Train tab) ──
$_svc_mentor_enabled = !empty($trainer->mentorship_enabled);
$_svc_is_own_profile = is_user_logged_in() && (int) get_current_user_id() === (int) ($trainer->user_id ?? 0);
if ($_svc_mentor_enabled || $_svc_is_own_profile):
    $_svc_mentor_pkgs = array_filter(explode(',', $trainer->mentorship_packages ?? 'single,kickstart,development,elite'));
    if (empty($_svc_mentor_pkgs)) $_svc_mentor_pkgs = array('single','kickstart','development','elite');
    $_mp_src = class_exists('PTP_Mentorship') && defined('PTP_Mentorship::PACKAGES') ? PTP_Mentorship::PACKAGES : array();
    $_svc_pkg_prices = []; $_svc_pkg_sessions = []; $_svc_pkg_labels = []; $_svc_pkg_lengths = [];
    foreach (array('single','kickstart','development','elite') as $_pk) {
        $_svc_pkg_prices[$_pk]   = $_mp_src[$_pk]['per_session_display'] ?? array('single'=>49,'kickstart'=>49,'development'=>69,'elite'=>89)[$_pk];
        $_svc_pkg_sessions[$_pk] = $_mp_src[$_pk]['sessions']            ?? array('single'=>1,'kickstart'=>12,'development'=>24,'elite'=>36)[$_pk];
        $_svc_pkg_labels[$_pk]   = $_mp_src[$_pk]['name']                ?? ucfirst($_pk);
        $_svc_pkg_lengths[$_pk]  = $_mp_src[$_pk]['session_length']      ?? array('single'=>30,'kickstart'=>30,'development'=>45,'elite'=>60)[$_pk];
    }
    $_svc_mentor_count = 0;
    $_svc_pair_exists  = false;
    $_svc_mentor_table = class_exists('PTP_Query_Cache') 
        ? PTP_Query_Cache::table_exists('ptp_mentorship_pairs')
        : $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_mentorship_pairs'");
    if ($_svc_mentor_table) {
        $_svc_mentor_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND status = 'active'", $trainer->id
        ));
        if (is_user_logged_in()) {
            $_svc_pair_exists = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND parent_id = %d AND status NOT IN ('cancelled') LIMIT 1",
                $trainer->id, get_current_user_id()
            ));
        }
    }
    $_svc_max_mentees = max(1, intval($trainer->mentorship_max_mentees ?? 20));
    $_svc_spots_left  = max(0, $_svc_max_mentees - $_svc_mentor_count);
    $_svc_default_pkg = in_array('development', $_svc_mentor_pkgs) ? 'development' : (in_array('kickstart', $_svc_mentor_pkgs) ? 'kickstart' : ($_svc_mentor_pkgs[0] ?? 'single'));
?>
                <!-- MENTORSHIP CARD -->
                <div class="tp210-svc-divider">
                    <span>OR</span>
                </div>
                <div class="tp210-svc-card tp210-svc-mentorship<?php echo (!$_svc_mentor_enabled && $_svc_is_own_profile ? ' tp210-svc-disabled' : ''); ?>">
                    <?php if ($_svc_is_own_profile && !$_svc_mentor_enabled): ?>
                    <div class="tp210-svc-notice">Mentorship is off — only you see this preview</div>
                    <?php endif; ?>
                    <div class="tp210-svc-badge tp210-svc-badge-gold">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        ONLINE MENTORSHIP
                    </div>
                    <h3 class="tp210-svc-title">Year-Round Mentorship</h3>
                    <?php if (!empty($trainer->mentorship_bio)): ?>
                    <p class="tp210-svc-desc"><?php echo esc_html($trainer->mentorship_bio); ?></p>
                    <?php else: ?>
                    <p class="tp210-svc-desc">Weekly video calls, film review, goal tracking, and a real relationship built over months — not just drills. <?php echo esc_html($first_name); ?> becomes your player's dedicated mentor.</p>
                    <?php endif; ?>

                    <!-- Mentorship Packages -->
                    <div class="tp210-svc-pkgs">
                        <?php foreach ($_svc_mentor_pkgs as $tk):
                            if (!isset($_svc_pkg_prices[$tk])) continue;
                            $is_dev = ($tk === 'development');
                        ?>
                        <div class="tp210-svc-pkg tp210-svc-pkg-dark<?php echo $is_dev ? ' tp210-svc-pkg-pop' : ''; ?>">
                            <?php if ($is_dev): ?><div class="tp210-svc-pkg-flag">POPULAR</div><?php elseif ($tk === 'single'): ?><div class="tp210-svc-pkg-flag" style="background:#3B82F6;color:#fff">TRY IT</div><?php endif; ?>
                            <div class="tp210-svc-pkg-name"><?php echo esc_html($_svc_pkg_labels[$tk]); ?></div>
                            <div class="tp210-svc-pkg-price">$<?php echo esc_html($_svc_pkg_prices[$tk]); ?><small>/session</small></div>
                            <div class="tp210-svc-pkg-per"><?php echo esc_html($_svc_pkg_sessions[$tk] ?? '–'); ?> session<?php echo ($_svc_pkg_sessions[$tk] ?? 1) > 1 ? 's' : ''; ?> &middot; <?php echo esc_html($_svc_pkg_lengths[$tk] ?? 30); ?> min</div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="tp210-svc-features">
                        <?php foreach (['Weekly video call','Film review & feedback','Goal tracking','1-on-1 mentorship'] as $f): ?>
                        <div class="tp210-svc-feat"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> <?php echo esc_html($f); ?></div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($_svc_is_own_profile): ?>
                    <a href="<?php echo esc_url(home_url('/trainer-dashboard/#mentorship')); ?>" class="tp210-svc-cta tp210-svc-cta-outline">
                        Manage Mentorship in Dashboard
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                    <?php elseif ($_svc_pair_exists): ?>
                    <a href="<?php echo esc_url(home_url('/parent-dashboard/#mentorship')); ?>" class="tp210-svc-cta">
                        View Your Mentorship
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                    <?php elseif ($_svc_mentor_enabled && $_svc_spots_left > 0): ?>
                    <a href="<?php echo esc_url(add_query_arg(array('trainer_id' => $trainer->id, 'package' => $_svc_default_pkg), home_url('/mentorship-signup/'))); ?>" class="tp210-svc-cta">
                        Start with a Free Intro Call
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                    <?php if ($_svc_spots_left <= 5): ?>
                    <div class="tp210-svc-urgency">Only <?php echo esc_html($_svc_spots_left); ?> spot<?php echo $_svc_spots_left !== 1 ? 's' : ''; ?> left</div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="tp210-svc-cta tp210-svc-cta-disabled">Mentorship Full</div>
                    <?php endif; ?>
                    <div class="tp210-svc-footnote">No payment until after your free intro call</div>
                </div>
<?php endif; // mentor_enabled || is_own_profile ?>

                <!-- Comparison helper -->
                <div class="tp210-svc-compare">
                    <div class="tp210-svc-compare-hdr">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        Which is right for my player?
                    </div>
                    <div class="tp210-svc-compare-row">
                        <div class="tp210-svc-compare-col">
                            <strong>Private Training</strong> is best for players who need targeted skill work before a tryout, want to sharpen specific areas, or train with a sibling or friend.
                        </div>
                        <div class="tp210-svc-compare-col">
                            <strong>Mentorship</strong> is best for players who want long-term development with a dedicated coach — someone who knows their game, tracks their growth, and builds real confidence.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reviews -->
            <?php if ($reviews): ?>
            <div class="tp210-panel" id="tab-reviews">
                <div class="tp210-block">
                    <div class="tp210-block-hdr">
                        <div class="tp210-block-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
                        <div class="tp210-block-lbl">Reviews</div>
                    </div>

                    <?php if ($total_rated >= 2): ?>
                    <div class="tp210-rev-summary">
                        <div class="tp210-rev-avg">
                            <div class="tp210-rev-avg-num"><?php echo esc_html($rating); ?></div>
                            <div class="tp210-rev-avg-stars"><?php echo str_repeat('★', round($rating)); ?></div>
                            <div class="tp210-rev-avg-ct"><?php echo esc_html($review_count); ?> review<?php echo $review_count !== 1 ? 's' : ''; ?></div>
                        </div>
                        <div class="tp210-rev-bars">
                            <?php for ($s = 5; $s >= 1; $s--):
                                $bar_ct = $rating_breakdown[$s];
                                $bar_pct = $total_rated > 0 ? round(($bar_ct / $total_rated) * 100) : 0;
                            ?>
                            <div class="tp210-rev-bar-row">
                                <div class="tp210-rev-bar-label"><?php echo intval($s); ?></div>
                                <div class="tp210-rev-bar-track"><div class="tp210-rev-bar-fill" style="width:<?php echo floatval($bar_pct); ?>%"></div></div>
                                <div class="tp210-rev-bar-ct"><?php echo esc_html($bar_ct); ?></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php foreach ($reviews as $r):
                        // Time ago
                        $diff = time() - strtotime($r->created_at);
                        if ($diff < 3600) $time_ago = 'Just now';
                        elseif ($diff < 86400) $time_ago = floor($diff/3600) . 'h ago';
                        elseif ($diff < 604800) $time_ago = floor($diff/86400) . 'd ago';
                        elseif ($diff < 2592000) $time_ago = floor($diff/604800) . 'w ago';
                        elseif ($diff < 31536000) $time_ago = floor($diff/2592000) . 'mo ago';
                        else $time_ago = floor($diff/31536000) . 'y ago';
                        // Initials
                        $words = explode(' ', $r->name);
                        $initials = strtoupper(substr($words[0],0,1) . (isset($words[1]) ? substr($words[1],0,1) : ''));
                    ?>
                    <div class="tp210-rev">
                        <div class="tp210-rev-hd">
                            <div class="tp210-rev-avatar"><?php echo esc_html($initials); ?></div>
                            <div class="tp210-rev-meta">
                                <div class="tp210-rev-name">
                                    <?php echo esc_html($r->name); ?>
                                    <?php if ($r->is_verified): ?>
                                    <span class="tp210-rev-verified"><svg fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Verified</span>
                                    <?php endif; ?>
                                </div>
                                <div class="tp210-rev-date">
                                    <span class="tp210-rev-stars"><?php echo str_repeat('★', intval($r->rating)); ?><?php echo str_repeat('☆', 5 - intval($r->rating)); ?></span>
                                    · <?php echo esc_html($time_ago); ?>
                                </div>
                            </div>
                        </div>
                        <?php if ($r->review_text): ?><div class="tp210-rev-txt">"<?php echo esc_html($r->review_text); ?>"</div><?php endif; ?>
                        <?php if ($r->trainer_response): ?>
                        <div class="tp210-rev-response">
                            <div class="tp210-rev-response-hd"><?php echo esc_html($first_name); ?>'s Response</div>
                            <div class="tp210-rev-response-txt"><?php echo esc_html($r->trainer_response); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Locations -->
            <div class="tp210-panel" id="tab-locations">
                <div class="tp210-block">
                    <div class="tp210-block-hdr">
                        <div class="tp210-block-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
                        <div class="tp210-block-lbl">Training Locations</div>
                    </div>
                    <?php if ($locations): ?>
                    <?php foreach ($locations as $li => $l): if (empty($l['name']) && empty($l['address'])) continue; ?>
                    <div class="tp210-loc">
                        <div class="tp210-loc-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
                        <div>
                            <div class="tp210-loc-name"><?php echo esc_html($l['name'] ?? 'Training Field'); ?></div>
                            <?php if (!empty($l['address'])): ?>
                            <div class="tp210-loc-addr"><a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($l['address']); ?>" target="_blank" rel="noopener"><?php echo esc_html($l['address']); ?> →</a></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                    if (!empty($l['lat']) && !empty($l['lng'])) { $map_query = $l['lat'] . ',' . $l['lng']; }
                    else { $map_query = !empty($l['address']) ? $l['address'] : ($l['name'] ?? ''); }
                    $maps_link = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($map_query);
                    $map_src = $google_maps_key
                        ? 'https://www.google.com/maps/embed/v1/place?key=' . esc_attr($google_maps_key) . '&q=' . urlencode($map_query) . '&zoom=14'
                        : '';
                    $static_map_src = $google_maps_key
                        ? 'https://maps.googleapis.com/maps/api/staticmap?center=' . urlencode($map_query) . '&zoom=14&size=600x280&scale=2&markers=color:0xFCB900|' . urlencode($map_query) . '&key=' . esc_attr($google_maps_key)
                        : '';
                    ?>
                    <?php if ($static_map_src): ?>
                    <!-- Mobile: static map image (no iframe = no WebKit crash) -->
                    <a href="<?php echo esc_url($maps_link); ?>" target="_blank" rel="noopener" class="tp210-map tp210-map-static" style="display:block;text-decoration:none">
                        <img src="<?php echo esc_url($static_map_src); ?>" alt="Map — <?php echo esc_attr($l['name'] ?? 'Location'); ?>" loading="lazy" width="600" height="280" style="width:100%;height:auto;display:block;border-radius:12px">
                        <span class="tp210-map-open-label">Open in Maps →</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($map_src): ?>
                    <!-- Desktop: lazy-loaded iframe (hidden on mobile) -->
                    <div class="tp210-map tp210-map-lazy tp210-map-desktop" data-src="<?php echo esc_attr($map_src); ?>" data-query="<?php echo esc_attr($map_query); ?>">
                        <div class="tp210-map-placeholder">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span>Click to load map</span>
                        </div>
                    </div>
                    <?php elseif (!$static_map_src): ?>
                    <!-- No API key: just link to Google Maps -->
                    <a href="<?php echo esc_url($maps_link); ?>" target="_blank" rel="noopener" class="tp210-map tp210-map-link" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;height:120px;background:var(--tp-g50,#FAFAFA);text-decoration:none;color:var(--tp-g400);border-radius:12px;border:1px solid var(--tp-g200)">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span style="font-family:var(--tp-sans);font-size:13px;font-weight:600">Open in Google Maps →</span>
                    </a>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div style="text-align:center;padding:40px 20px;color:var(--tp-g400);">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 12px;display:block;opacity:.4;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <p style="font-family:var(--tp-sans);font-size:14px;margin:0;">Training locations not yet added.</p>
                        <p style="font-family:var(--tp-sans);font-size:13px;margin:6px 0 0;opacity:.7;">This trainer will meet at a convenient location near you.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>


        </div>

        <!-- BOOKING PANEL -->
        <div class="tp210-overlay" id="bOverlay"></div>
        <div class="tp210-book" id="bPanel">
            <div class="tp210-book-handle"></div>
            <button type="button" class="tp210-book-close" id="bClose" aria-label="Close" onclick="if(window.ptpCloseSheet)ptpCloseSheet()"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg></button>

            <div class="tp210-bar" id="bBar">
                <div><span class="tp210-bar-price">$<?php echo esc_html($rate); ?></span><span class="tp210-bar-unit">/session</span></div>
                <button type="button" class="tp210-bar-btn">Book Now</button>
            </div>

            <div class="tp210-book-hdr">
                <div class="tp210-book-rate" id="bRate">$<?php echo esc_html($rate); ?><small>/session</small></div>
                <div class="tp210-book-save" id="bSave">Save $0</div>
            </div>

            <div class="tp210-book-body">
                <?php if (empty($available_dates)): ?>
                <!-- v236: No availability notice -->
                <div style="text-align:center;padding:24px 16px;margin-bottom:16px;background:rgba(252,185,0,0.06);border:1px solid rgba(252,185,0,0.2);border-radius:12px">
                    <div style="font-size:32px;margin-bottom:8px">📅</div>
                    <div style="font-weight:700;font-size:15px;color:#111;margin-bottom:6px;font-family:var(--tp-serif,'DM Serif Display',serif)">No Openings Right Now</div>
                    <div style="font-size:13px;color:#666;line-height:1.5;margin-bottom:12px"><?php echo esc_html($first_name); ?> doesn't have any available dates in the next 30 days. Send them a message to request a time.</div>
                    <button type="button" onclick="ptpOpenMsg()" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:var(--tp-gold,#FCB900);color:#0A0A0A;font-weight:700;border:none;border-radius:8px;font-size:14px;cursor:pointer;font-family:'Inter',sans-serif;text-transform:uppercase;letter-spacing:0.5px">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Message <?php echo esc_html($first_name); ?>
                    </button>
                </div>
                <?php endif; ?>

                <!-- Step 1: Package -->
                <div class="tp210-step">
                    <div class="tp210-step-t"><span class="tp210-step-n">1</span> Choose Package</div>
                    <div class="tp210-pkgs">
                        <?php foreach ($pkgs as $k => $p): ?>
                        <div class="tp210-pkg<?php echo $k === 'single' ? ' sel' : ''; ?>" data-pkg="<?php echo $k; ?>" data-price="<?php echo esc_html($p['price']); ?>" data-save="<?php echo esc_html($p['save']); ?>" data-count="<?php echo $p['count']; ?>" data-per="<?php echo esc_html($p['per']); ?>">
                            <div class="tp210-pkg-name"><?php echo esc_html($p['name']); ?></div>
                            <div class="tp210-pkg-price">$<?php echo esc_html($p['price']); ?></div>
                            <?php if ($p['save']): ?><div class="tp210-pkg-save">Save $<?php echo esc_html($p['save']); ?></div><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Step 2: Group -->
                <div class="tp210-step">
                    <div class="tp210-step-t"><span class="tp210-step-n">2</span> How Many Players?</div>
                    <div class="tp210-grps">
                        <div class="tp210-grp sel" data-group="1">
                            <div class="tp210-grp-ico"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 00-16 0"/></svg></div>
                            <div class="tp210-grp-lbl">1 Player</div><div class="tp210-grp-sub">1-on-1</div>
                        </div>
                        <div class="tp210-grp" data-group="2">
                            <div class="tp210-grp-ico"><svg width="28" height="22" viewBox="0 0 32 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10" cy="8" r="3.5"/><path d="M17 21a7 7 0 00-14 0"/><circle cx="22" cy="8" r="3.5"/><path d="M29 21a7 7 0 00-14 0"/></svg></div>
                            <div class="tp210-grp-lbl">2 Players</div><div class="tp210-grp-sub">Sibling / Friend</div><div class="tp210-grp-disc">Save 20%/kid</div>
                        </div>
                        <div class="tp210-grp" data-group="3">
                            <div class="tp210-grp-ico"><svg width="34" height="22" viewBox="0 0 40 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="3.2"/><path d="M14.5 21a6.5 6.5 0 00-13 0"/><circle cx="20" cy="8" r="3.2"/><path d="M26.5 21a6.5 6.5 0 00-13 0"/><circle cx="32" cy="8" r="3.2"/><path d="M38.5 21a6.5 6.5 0 00-13 0"/></svg></div>
                            <div class="tp210-grp-lbl">3 Players</div><div class="tp210-grp-sub">Small Group</div><div class="tp210-grp-disc">Save 33%/kid</div>
                        </div>
                    </div>
                    <div class="tp210-grp-note">Train with siblings or friends — everyone gets coached together</div>
                </div>

                <!-- Step 3: Location -->
                <div class="tp210-step">
                    <div class="tp210-step-t"><span class="tp210-step-n">3</span> Training Location</div>
                    <?php if (!empty($locations)): ?>
                    <div class="tp210-locs" id="locGrid">
                        <?php foreach ($locations as $i => $l): if (empty($l['name'])) continue; ?>
                        <div class="tp210-lopt" data-loc="<?php echo esc_attr($l['name']); ?>" data-addr="<?php echo esc_attr($l['address'] ?? ''); ?>" data-lat="<?php echo esc_attr($l['lat'] ?? ''); ?>" data-lng="<?php echo esc_attr($l['lng'] ?? ''); ?>">
                            <div class="tp210-lopt-pin"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
                            <div class="tp210-lopt-info">
                                <div class="tp210-lopt-name"><?php echo esc_html($l['name']); ?></div>
                                <?php if (!empty($l['address'])): ?><div class="tp210-lopt-addr"><?php echo esc_html($l['address']); ?></div><?php endif; ?>
                            </div>
                            <div class="tp210-lopt-chk"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="tp210-ac" id="locAC">
                        <div class="tp210-ac-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
                        <input type="text" id="locInput" class="tp210-ac-input" placeholder="Search for a field or park..." autocomplete="off">
                        <div class="tp210-ac-hint" id="locHint">Start typing to search Google Maps</div>
                        <div class="tp210-ac-sel" id="locSel">
                            <div class="tp210-lopt-pin" style="background:var(--tp-gold-soft);color:var(--tp-gold)"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
                            <div style="flex:1;min-width:0"><div class="tp210-ac-sel-name" id="locSelName"></div><div class="tp210-ac-sel-addr" id="locSelAddr"></div></div>
                            <button type="button" class="tp210-ac-change" id="locChange">Change</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Step 4: Date -->
                <div class="tp210-step">
                    <div class="tp210-step-t"><span class="tp210-step-n">4</span> Pick Date</div>
                    <div class="tp210-pack-note" id="packDateNote" style="display:none;background:rgba(252,185,0,0.08);border:1px solid rgba(252,185,0,0.25);border-radius:8px;padding:10px 12px;margin-bottom:10px;font-size:12px;line-height:1.5;color:#d4a017;font-family:'Inter',sans-serif">
                        <strong style="font-weight:600">Pick your first session date.</strong> You'll schedule the remaining sessions with your trainer after checkout.
                    </div>
                    <div class="tp210-dates" id="dateScroll">
                        <?php $shown = 0; foreach ($available_dates as $date): if ($shown >= 14) break; $d = new DateTime($date); ?>
                        <div class="tp210-dc" data-date="<?php echo esc_attr($date); ?>">
                            <div class="tp210-dc-dow"><?php echo esc_html($d->format('D')); ?></div>
                            <div class="tp210-dc-day"><?php echo esc_html($d->format('j')); ?></div>
                            <div class="tp210-dc-mon"><?php echo esc_html($d->format('M')); ?></div>
                        </div>
                        <?php $shown++; endforeach; ?>
                        <?php if ($shown === 0): ?>
                        <div style="width:100%;text-align:center;padding:16px 8px;color:var(--tp-g400);font-size:13px;line-height:1.5">
                            No available dates right now.<br>
                            <a href="#" onclick="event.preventDefault();ptpOpenMsg()" style="color:var(--tp-gold);font-weight:600;text-decoration:none">Message <?php echo esc_html($first_name); ?></a> to request a time.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 5: Time -->
                <div class="tp210-step">
                    <div class="tp210-step-t"><span class="tp210-step-n">5</span> Pick Time</div>
                    <div class="tp210-times" id="timeGrid"></div>
                </div>

                <button type="button" class="tp210-cta" id="ctaBtn" disabled>Select Location</button>
                <p class="tp210-policy">Free cancellation 24+ hrs before · 50% refund 2–24 hrs · <a href="#" onclick="event.preventDefault();alert('Cancellation Policy:\n\n• 24+ hours before: Full refund\n• 2-24 hours before: 50% refund\n• Under 2 hours: No refund\n• Trainer cancels: Full refund\n• No-show: No refund')">Full policy</a></p>
            </div>
        </div>
    </div>
</div>

<!-- MESSAGE MODAL -->
<div class="tp210-msg-overlay" id="msgOverlay"></div>
<div class="tp210-msg-modal" id="msgModal">
    <div class="tp210-msg-handle"></div>
    <div class="tp210-msg-hdr">
        <div class="tp210-msg-title">Send Message</div>
        <button type="button" class="tp210-msg-close" id="msgClose" aria-label="Close"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
    </div>
    <div class="tp210-msg-trainer">
        <img class="tp210-msg-avatar" src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($first_name); ?>">
        <div>
            <div class="tp210-msg-tname"><?php echo esc_html($trainer->display_name); ?></div>
            <div class="tp210-msg-tsub"><?php echo esc_html($level); ?> Coach · $<?php echo $rate; ?>/session</div>
        </div>
    </div>
    
    <!-- Form State -->
    <div class="tp210-msg-body" id="msgForm">
        <?php if (!$is_logged_in): ?>
        <div class="tp210-msg-row">
            <div class="tp210-msg-field">
                <label class="tp210-msg-label" for="msgName">Your Name *</label>
                <input type="text" id="msgName" class="tp210-msg-input" placeholder="First & Last" autocomplete="name">
            </div>
            <div class="tp210-msg-field">
                <label class="tp210-msg-label" for="msgPhone">Phone</label>
                <input type="tel" id="msgPhone" class="tp210-msg-input" placeholder="(555) 555-5555" autocomplete="tel">
            </div>
        </div>
        <div class="tp210-msg-field">
            <label class="tp210-msg-label" for="msgEmail">Email *</label>
            <input type="email" id="msgEmail" class="tp210-msg-input" placeholder="you@email.com" autocomplete="email">
        </div>
        <?php endif; ?>
        
        <div class="tp210-msg-field">
            <label class="tp210-msg-label" for="msgText">Message</label>
            <div class="tp210-msg-quick">
                <span class="tp210-msg-chip" data-msg="Hi <?php echo esc_attr($first_name); ?>, I'm interested in training for my child. What ages do you work with?">Ages & levels?</span>
                <span class="tp210-msg-chip" data-msg="Hi <?php echo esc_attr($first_name); ?>, I'd like to schedule a session. What times work best for you this week?">Availability?</span>
                <span class="tp210-msg-chip" data-msg="Hi <?php echo esc_attr($first_name); ?>, can you tell me more about what a typical training session looks like?">What's a session like?</span>
                <span class="tp210-msg-chip" data-msg="Hi <?php echo esc_attr($first_name); ?>, my child has a tryout coming up. Can you help prepare them?">Tryout prep</span>
            </div>
            <textarea id="msgText" class="tp210-msg-input" placeholder="Tell <?php echo esc_attr($first_name); ?> about your player, what you're looking for, or ask a question..." rows="4"></textarea>
        </div>
        
        <button type="button" class="tp210-msg-send" id="msgSend" onclick="ptpSendMsg()">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            Send Message
        </button>
        <div class="tp210-msg-error" id="msgError"></div>
    </div>
    
    <!-- Success State -->
    <div class="tp210-msg-success" id="msgSuccess" style="display:none">
        <div class="tp210-msg-success-ico"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
        <h3>Message Sent</h3>
        <p><?php echo esc_html($first_name); ?> will be notified and typically responds within a few hours.</p>
        <a href="<?php echo esc_url(home_url('/messages/')); ?>" id="msgSuccessLink" class="tp210-msg-success-btn">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            View Messages
        </a>
    </div>
</div>

<script>
var TP_CONFIG = {
    ajaxUrl: <?php echo json_encode(admin_url('admin-ajax.php')); ?>,
    trainerId: <?php echo intval($trainer->id); ?>,
    nonce: <?php echo json_encode($ptp_nonce); ?>,
    isLoggedIn: <?php echo $is_logged_in ? 'true' : 'false'; ?>,
    messagesUrl: <?php echo json_encode(home_url('/messages/')); ?>,
    availByDay: <?php echo json_encode($avail_by_day); ?>,
    bookedMap: <?php echo json_encode($booked_map); ?>,
    trainerRate: <?php echo intval($rate); ?>,
    trainerSlug: <?php echo json_encode($trainer->slug); ?>,
    cartUrl: <?php echo json_encode(home_url('/ptp-checkout/')); ?>,
    gallery: <?php echo wp_json_encode($gallery); ?>
};
</script>
<script src="<?php echo esc_url(PTP_PLUGIN_URL . 'assets/js/trainer-profile-v177.js?v=' . PTP_VERSION); ?>"></script>

<?php /* v235.7: Mobile performance optimizations */ ?>
<script>
(function(){
    var isMobile = window.innerWidth < 768;
    var isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

    // 1. Click-to-load map iframes (desktop only — mobile uses static maps)
    document.querySelectorAll('.tp210-map-lazy').forEach(function(el) {
        var placeholder = el.querySelector('.tp210-map-placeholder');
        if (!placeholder) return;
        placeholder.addEventListener('click', function() {
            var src = el.dataset.src;
            if (!src) return;
            // Show loading state
            var loading = document.createElement('div');
            loading.className = 'tp210-map-loading';
            el.textContent = '';
            el.appendChild(loading);
            // Create iframe — add to DOM BEFORE setting src to avoid detached pre-load crash
            var iframe = document.createElement('iframe');
            iframe.width = '100%';
            iframe.height = '280';
            iframe.style.cssText = 'border:0;border-radius:12px;display:block';
            iframe.setAttribute('loading', 'lazy');
            iframe.setAttribute('allowfullscreen', '');
            iframe.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
            iframe.onload = function() { if (loading.parentNode) loading.parentNode.removeChild(loading); };
            iframe.onerror = function() { if (loading.parentNode) loading.parentNode.removeChild(loading); };
            el.appendChild(iframe);
            // Set src AFTER iframe is in the DOM
            iframe.src = src;
            el.classList.remove('tp210-map-lazy');
        });
    });

    // 2. Lazy-load Google Maps JS API only when booking panel opens (for Places Autocomplete)
    <?php if ($google_maps_key): ?>
    var mapsLoaded = false;
    function loadMapsAPI() {
        if (mapsLoaded) return;
        mapsLoaded = true;
        var s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=<?php echo esc_js($google_maps_key); ?>&libraries=places&callback=Function.prototype';
        s.async = true;
        document.head.appendChild(s);
    }
    // Only load Maps API when booking panel is opened (autocomplete needs it)
    var bPanel = document.getElementById('bPanel');
    if (bPanel) {
        var panelObs = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                if (bPanel.classList.contains('open')) { loadMapsAPI(); panelObs.disconnect(); }
            });
        });
        panelObs.observe(bPanel, { attributes: true, attributeFilter: ['class'] });
        // Desktop: load after short delay since panel is always visible
        if (!isMobile) setTimeout(loadMapsAPI, 2000);
    }
    <?php endif; ?>

    // 2. Remove hover effects on touch devices (gallery images)
    if (isTouch) {
        document.querySelectorAll('[onmouseover]').forEach(function(el) {
            el.removeAttribute('onmouseover');
            el.removeAttribute('onmouseout');
        });
    }

    // 3. Optimize booking panel - reduce will-change when not interacting
    if (isMobile) {
        var bPanel = document.getElementById('bPanel');
        if (bPanel) {
            bPanel.style.willChange = 'auto';
            bPanel.addEventListener('touchstart', function() { this.style.willChange = 'transform'; }, {passive: true});
            bPanel.addEventListener('touchend', function() {
                var self = this;
                setTimeout(function() { if (!self.classList.contains('open')) self.style.willChange = 'auto'; }, 400);
            }, {passive: true});
        }
    }

    // 4. Lazy-load below-fold images with IntersectionObserver
    if ('IntersectionObserver' in window) {
        document.querySelectorAll('img[loading="lazy"]').forEach(function(img) {
            if (!img.dataset.src && img.src) {
                // Already has src, just ensure decode
                if (img.decode) img.decode().catch(function(){});
            }
        });
    }

    // 5. Preload booking panel font when user scrolls past hero
    if (isMobile && 'IntersectionObserver' in window) {
        var statsEl = document.querySelector('.tp210-stats');
        if (statsEl) {
            var fontObs = new IntersectionObserver(function(entries) {
                if (entries[0].isIntersecting) {
                    // User scrolled past hero - preload DM Serif for booking panel
                    var link = document.createElement('link');
                    link.rel = 'preload';
                    link.as = 'style';
                    link.href = 'https://fonts.googleapis.com/css2?family=DM+Serif+Display&display=swap';
                    document.head.appendChild(link);
                    fontObs.disconnect();
                }
            });
            fontObs.observe(statsEl);
        }
    }
})();
</script>

<?php get_footer(); ?>
