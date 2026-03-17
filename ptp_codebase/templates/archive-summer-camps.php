<?php
/**
 * Template Name: PTP Summer Camps Archive
 * Template Post Type: page
 * 
 * Archive template for summer-camps tag - displays all camps from ptp_stripe_products
 * Uses the PTP mobile-first design system
 * 
 * @version 146.0.0
 * v165: Added ptp_skip_header filter
 */

defined('ABSPATH') || exit;

// v165: Skip PTP header (uses theme header)
add_filter('ptp_skip_header', '__return_true');

// Get camps from database
global $wpdb;
$table = $wpdb->prefix . 'ptp_stripe_products';
$camps = $wpdb->get_results("
    SELECT * FROM $table 
    WHERE product_type IN ('camp', 'clinic') 
    AND active = 1 
    ORDER BY sort_order ASC, camp_dates ASC
");

// Group camps by location
$camps_by_location = array();
foreach ($camps as $camp) {
    $location = $camp->camp_location ?: 'Other Locations';
    $location_key = sanitize_title(explode(',', $location)[0]);
    if (!isset($camps_by_location[$location_key])) {
        $camps_by_location[$location_key] = array(
            'name' => $location,
            'camps' => array(),
        );
    }
    $camps_by_location[$location_key]['camps'][] = $camp;
}

get_header();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#0A0A0A">
    <title>PTP Summer Soccer Camps 2026 | Train With D1 Athletes</title>
    <meta name="description" content="Elite soccer camps for ages 6-14. NCAA D1 coaches play alongside your kid. 8:1 ratio. Locations across PA & NJ. Early bird pricing available.">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <link rel="canonical" href="<?php echo esc_url(home_url('/summer-camps/')); ?>">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="PTP Summer Soccer Camps 2026 | Train With D1 Athletes">
    <meta property="og:description" content="Elite soccer camps for ages 6-14. NCAA D1 athletes and MLS players train alongside your kid. 8:1 ratio across PA & NJ.">
    <meta property="og:url" content="<?php echo esc_url(home_url('/summer-camps/')); ?>">
    <meta property="og:site_name" content="PTP Soccer">
    <meta property="og:locale" content="en_US">
    <meta property="og:image" content="https://ptpsummercamps.com/wp-content/uploads/2026/02/group-photo.jpg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Kids playing soccer at PTP Summer Camp with D1 athlete coaches">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="PTP Summer Soccer Camps 2026">
    <meta name="twitter:description" content="Elite soccer camps for ages 6-14. D1 athletes train alongside your kid.">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
    
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        
        :root {
            --gold: #FCB900;
            --gold-hover: #e5a800;
            --black: #0A0A0A;
            --white: #FFFFFF;
            --gray-50: #FAFAFA;
            --gray-100: #F5F5F5;
            --gray-200: #E5E5E5;
            --gray-400: #A3A3AF;
            --gray-500: #737373;
            --gray-600: #525252;
            --gray-800: #262626;
            --gray-900: #171717;
            --green: #22C55E;
            --red: #EF4444;
            --font-display: 'Oswald', sans-serif;
            --font-body: 'Inter', sans-serif;
        }
        
        html { scroll-behavior: smooth; }
        
        body {
            font-family: var(--font-body);
            background: var(--black);
            color: var(--white);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
        }
        
        img { max-width: 100%; height: auto; display: block; }
        a { text-decoration: none; color: inherit; }
        button { font-family: inherit; cursor: pointer; border: none; background: none; }
        
        /* Labels */
        .label {
            font-family: var(--font-display);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--gold);
        }
        
        .headline {
            font-family: var(--font-display);
            font-size: 24px;
            font-weight: 700;
            line-height: 1;
            text-transform: uppercase;
            margin-top: 6px;
            color: var(--white);
        }
        
        .headline span { color: var(--gold); }
        
        /* Hero */
        .camps-hero {
            position: relative;
            min-height: 70vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 80px 20px;
            background: linear-gradient(180deg, var(--black) 0%, var(--gray-900) 100%);
            overflow: hidden;
        }
        
        .hero-bg-video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0.3;
        }
        
        .hero-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(0,0,0,0.5) 0%, rgba(0,0,0,0.8) 100%);
        }
        
        .hero-content {
            position: relative;
            z-index: 10;
            max-width: 800px;
        }
        
        .hero-badge {
            display: inline-block;
            background: var(--gold);
            color: var(--black);
            font-family: var(--font-display);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            padding: 8px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .hero-title {
            font-family: var(--font-display);
            font-size: 42px;
            font-weight: 700;
            line-height: 0.95;
            text-transform: uppercase;
            color: var(--white);
            margin-bottom: 16px;
        }
        
        .hero-title span { color: var(--gold); }
        
        .hero-sub {
            font-size: 18px;
            color: rgba(255,255,255,0.85);
            margin-bottom: 32px;
            line-height: 1.5;
        }
        
        .hero-sub strong { color: var(--gold); }
        
        .hero-cta {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--gold);
            color: var(--black);
            font-family: var(--font-display);
            font-size: 16px;
            font-weight: 700;
            padding: 16px 32px;
            border-radius: 12px;
            transition: all 0.2s;
        }
        
        .hero-cta:hover {
            background: var(--gold-hover);
            transform: translateY(-2px);
        }
        
        /* Stats */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            padding: 32px 16px;
            background: var(--gold);
        }
        
        .stat { text-align: center; }
        
        .stat-value {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 700;
            color: var(--black);
            line-height: 1;
        }
        
        .stat-label {
            font-size: 9px;
            font-weight: 600;
            color: var(--black);
            opacity: 0.7;
            text-transform: uppercase;
            margin-top: 4px;
        }
        
        /* Differentiators */
        .diff-section {
            padding: 48px 16px;
            background: var(--black);
        }
        
        .diff-section .section-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .diff-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .diff-card {
            background: var(--gray-800);
            border-left: 4px solid var(--gold);
            border-radius: 0 12px 12px 0;
            padding: 20px;
        }
        
        .diff-eyebrow {
            font-family: var(--font-display);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
            color: var(--gold);
            margin-bottom: 6px;
        }
        
        .diff-title {
            font-family: var(--font-display);
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--white);
            margin-bottom: 8px;
        }
        
        .diff-desc {
            color: var(--gray-400);
            font-size: 14px;
            line-height: 1.5;
        }
        
        .diff-desc strong { color: var(--gold); }
        
        /* Camps Section */
        .camps-section {
            padding: 48px 16px;
            background: var(--gray-900);
        }
        
        .camps-section .section-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .location-group {
            margin-bottom: 48px;
        }
        
        .location-title {
            font-family: var(--font-display);
            font-size: 20px;
            font-weight: 700;
            color: var(--gold);
            text-transform: uppercase;
            margin-bottom: 20px;
            padding-left: 12px;
            border-left: 4px solid var(--gold);
        }
        
        .camps-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .camp-card {
            background: var(--gray-800);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .camp-card:hover {
            border-color: var(--gold);
            transform: translateY(-4px);
        }
        
        .camp-card.featured {
            border-color: var(--gold);
        }
        
        .camp-image {
            position: relative;
            height: 200px;
            background-size: cover;
            background-position: center;
            background-color: var(--gray-900);
        }
        
        .camp-badges {
            position: absolute;
            top: 12px;
            left: 12px;
            display: flex;
            gap: 8px;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            color: var(--white);
            font-size: 11px;
            font-weight: 600;
            padding: 6px 10px;
            border-radius: 100px;
        }
        
        .badge.early-bird {
            background: var(--gold);
            color: var(--black);
        }
        
        .badge.low-spots {
            background: var(--red);
        }
        
        .camp-content {
            padding: 20px;
        }
        
        .camp-name {
            font-family: var(--font-display);
            font-size: 20px;
            font-weight: 700;
            color: var(--white);
            text-transform: uppercase;
            line-height: 1.1;
            margin-bottom: 16px;
        }
        
        .camp-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--gray-400);
            font-size: 13px;
        }
        
        .meta-item svg {
            width: 14px;
            height: 14px;
            fill: var(--gold);
        }
        
        .camp-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .camp-price {
            display: flex;
            flex-direction: column;
        }
        
        .price-current {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 700;
            color: var(--white);
        }
        
        .price-original {
            font-size: 14px;
            color: var(--gray-500);
            text-decoration: line-through;
        }
        
        .btn-register {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--gold);
            color: var(--black);
            font-family: var(--font-display);
            font-size: 14px;
            font-weight: 700;
            padding: 14px 24px;
            border-radius: 10px;
            transition: all 0.2s;
        }
        
        .btn-register:hover {
            background: var(--gold-hover);
        }
        
        .btn-register.sold-out {
            background: var(--gray-600);
            color: var(--gray-400);
            cursor: not-allowed;
        }
        
        /* FAQ */
        .faq-section {
            padding: 48px 16px;
            background: var(--white);
        }
        
        .faq-section .section-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .faq-section .headline {
            color: var(--gray-900);
        }
        
        .faq-list {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .faq-item {
            border-bottom: 1px solid var(--gray-200);
        }
        
        .faq-q {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-900);
            text-align: left;
            background: none;
            border: none;
            cursor: pointer;
        }
        
        .faq-q svg {
            width: 20px;
            height: 20px;
            fill: var(--gray-400);
            transition: transform 0.2s;
        }
        
        .faq-item.open .faq-q svg {
            transform: rotate(180deg);
        }
        
        .faq-a {
            display: none;
            padding-bottom: 20px;
            color: var(--gray-600);
            font-size: 15px;
            line-height: 1.6;
        }
        
        .faq-item.open .faq-a {
            display: block;
        }
        
        /* CTA */
        .final-cta {
            padding: 64px 16px;
            text-align: center;
            background: var(--black);
        }
        
        .final-headline {
            font-family: var(--font-display);
            font-size: 32px;
            font-weight: 700;
            color: var(--white);
            text-transform: uppercase;
            line-height: 0.95;
            margin-bottom: 12px;
        }
        
        .final-headline span { color: var(--gold); }
        
        .final-sub {
            color: var(--gray-400);
            font-size: 15px;
            margin-bottom: 24px;
        }
        
        .final-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--gold);
            color: var(--black);
            font-family: var(--font-display);
            font-size: 16px;
            font-weight: 700;
            padding: 18px 36px;
            border-radius: 12px;
            transition: all 0.2s;
        }
        
        .final-btn:hover {
            background: var(--gold-hover);
            transform: translateY(-2px);
        }
        
        .final-guarantee {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: var(--gray-500);
            font-size: 12px;
            margin-top: 16px;
        }
        
        .final-guarantee svg {
            width: 16px;
            height: 16px;
            fill: var(--green);
        }
        
        /* Footer */
        .site-footer {
            background: var(--gray-900);
            padding: 48px 16px;
            text-align: center;
        }
        
        .footer-logo {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 700;
            color: var(--gold);
            margin-bottom: 20px;
        }
        
        .footer-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .footer-links a {
            color: var(--gray-400);
            font-size: 14px;
        }
        
        .footer-copy {
            color: var(--gray-500);
            font-size: 12px;
        }
        
        /* Desktop */
        @media (min-width: 768px) {
            .hero-title { font-size: 64px; }
            .hero-sub { font-size: 20px; }
            
            .stats-bar { gap: 48px; padding: 48px 64px; }
            .stat-value { font-size: 48px; }
            .stat-label { font-size: 12px; }
            
            .diff-grid { grid-template-columns: repeat(2, 1fr); }
            .camps-grid { grid-template-columns: repeat(2, 1fr); }
            
            .headline { font-size: 36px; }
            .final-headline { font-size: 48px; }
        }
        
        @media (min-width: 1024px) {
            .camps-grid { grid-template-columns: repeat(3, 1fr); }
            .diff-grid { grid-template-columns: repeat(4, 1fr); }
        }
    </style>
</head>
<body>

<!-- Hero -->
<section class="camps-hero">
    <video class="hero-bg-video" autoplay muted loop playsinline>
        <source src="https://ptpsummercamps.com/wp-content/uploads/2026/01/PRODUCT-VIDEO.mp4" type="video/mp4">
    </video>
    <div class="hero-overlay"></div>
    
    <div class="hero-content">
        <span class="hero-badge">⚽ SUMMER 2026</span>
        <h1 class="hero-title">Train With <span>D1 Athletes</span></h1>
        <p class="hero-sub">
            NCAA D1 coaches <strong>play alongside</strong> your kid—not from the sideline.<br>
            <?php echo count($camps); ?> camp weeks across PA & NJ. Ages 6-14.
        </p>
        <a href="#camps" class="hero-cta">View All Camps →</a>
    </div>
</section>

<!-- Stats -->
<div class="stats-bar">
    <div class="stat">
        <div class="stat-value">500+</div>
        <div class="stat-label">Families</div>
    </div>
    <div class="stat">
        <div class="stat-value">8:1</div>
        <div class="stat-label">Ratio</div>
    </div>
    <div class="stat">
        <div class="stat-value">D1</div>
        <div class="stat-label">Coaches</div>
    </div>
    <div class="stat">
        <div class="stat-value">5★</div>
        <div class="stat-label">Rating</div>
    </div>
</div>

<!-- Differentiators -->
<section class="diff-section">
    <div class="section-header">
        <span class="label">Why PTP?</span>
        <h2 class="headline">What Makes Us <span>Different</span></h2>
    </div>
    
    <div class="diff-grid">
        <div class="diff-card">
            <div class="diff-eyebrow">COACHES WHO PLAY</div>
            <h3 class="diff-title">We Play With Kids</h3>
            <p class="diff-desc">Our coaches don't stand on the sidelines. They play <strong>3v3 and 4v4</strong> alongside your child—teaching through real gameplay.</p>
        </div>
        <div class="diff-card">
            <div class="diff-eyebrow">ELITE ATHLETES</div>
            <h3 class="diff-title">NCAA D1 & College Athletes</h3>
            <p class="diff-desc">Current <strong>NCAA Division 1 athletes</strong> and professional players who've been where your child wants to go.</p>
        </div>
        <div class="diff-card">
            <div class="diff-eyebrow">SMALL GROUPS</div>
            <h3 class="diff-title">8:1 Maximum Ratio</h3>
            <p class="diff-desc">No warehouse camps. <strong>Maximum 8 campers per coach</strong> ensures real attention and skill development.</p>
        </div>
        <div class="diff-card">
            <div class="diff-eyebrow">WORLD CUP 2026</div>
            <h3 class="diff-title">Special WC Sessions</h3>
            <p class="diff-desc">Philadelphia hosts <strong>6 World Cup matches</strong>. Experience the excitement with special themed sessions.</p>
        </div>
    </div>
</section>

<!-- Camps Grid -->
<section class="camps-section" id="camps">
    <div class="section-header">
        <span class="label">Summer 2026</span>
        <h2 class="headline">Choose Your <span>Camp</span></h2>
    </div>
    
    <?php foreach ($camps_by_location as $location): ?>
        <div class="location-group">
            <h3 class="location-title"><?php echo esc_html($location['name']); ?></h3>
            
            <div class="camps-grid">
                <?php foreach ($location['camps'] as $camp): 
                    $price = $camp->price_cents / 100;
                    $spots_remaining = $camp->camp_capacity ? ($camp->camp_capacity - $camp->camp_registered) : null;
                    $sold_out = $spots_remaining !== null && $spots_remaining <= 0;
                    $low_spots = $spots_remaining !== null && $spots_remaining <= 10 && $spots_remaining > 0;
                ?>
                    <div class="camp-card <?php echo $camp->is_featured ? 'featured' : ''; ?>">
                        <div class="camp-image" style="background-image: url('<?php echo esc_url($camp->image_url ?: 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg'); ?>');">
                            <div class="camp-badges">
                                <?php if ($sold_out): ?>
                                    <span class="badge">SOLD OUT</span>
                                <?php else: ?>
                                    <?php if ($low_spots): ?>
                                        <span class="badge low-spots"><?php echo $spots_remaining; ?> spots left</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="camp-content">
                            <h4 class="camp-name"><?php echo esc_html($camp->name); ?></h4>
                            
                            <div class="camp-meta">
                                <?php if ($camp->camp_dates): ?>
                                    <div class="meta-item">
                                        <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
                                        <?php echo esc_html($camp->camp_dates); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($camp->camp_time): ?>
                                    <div class="meta-item">
                                        <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                                        <?php echo esc_html($camp->camp_time); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($camp->camp_age_min && $camp->camp_age_max): ?>
                                    <div class="meta-item">
                                        <svg viewBox="0 0 20 20"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
                                        Ages <?php echo esc_html($camp->camp_age_min); ?>-<?php echo esc_html($camp->camp_age_max); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="camp-footer">
                                <div class="camp-price">
                                    <span class="price-current">$<?php echo number_format($price, 0); ?></span>
                                    <?php 
                                    $original_price = $price * 1.25; // Show ~25% "savings"
                                    if ($original_price > $price): 
                                    ?>
                                        <span class="price-original">$<?php echo number_format($original_price, 0); ?></span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($sold_out): ?>
                                    <span class="btn-register sold-out">Sold Out</span>
                                <?php else: ?>
                                    <a href="<?php echo home_url('/ptp-checkout/?camp=' . urlencode($camp->stripe_product_id)); ?>" class="btn-register">
                                        Register →
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<!-- FAQ -->
<section class="faq-section">
    <div class="section-header">
        <span class="label" style="color: var(--gold);">Questions?</span>
        <h2 class="headline">Frequently Asked <span>Questions</span></h2>
    </div>
    
    <div class="faq-list" id="faqList">
        <div class="faq-item">
            <button class="faq-q" type="button">
                <span>What should my child bring?</span>
                <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
            <div class="faq-a">
                Cleats, shin guards, a water bottle, sunscreen (applied before arrival), and snacks. We provide all training equipment and balls. A change of shirt is recommended.
            </div>
        </div>
        <div class="faq-item">
            <button class="faq-q" type="button">
                <span>What's the coach-to-camper ratio?</span>
                <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
            <div class="faq-a">
                We maintain a maximum <strong>8:1 camper-to-coach ratio</strong>. This ensures every child gets real attention and meaningful touches on the ball.
            </div>
        </div>
        <div class="faq-item">
            <button class="faq-q" type="button">
                <span>Is there before/after care available?</span>
                <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
            <div class="faq-a">
                Yes! Our <strong>Before + After Care Bundle</strong> extends hours from 8:00 AM to 4:30 PM for just $60/week. Add it during checkout.
            </div>
        </div>
        <div class="faq-item">
            <button class="faq-q" type="button">
                <span>Do you offer sibling discounts?</span>
                <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
            <div class="faq-a">
                Absolutely! Siblings automatically receive <strong>10% off</strong>. Team groups of 5+ get additional discounts (10-20% based on size). Multi-week registration also saves 10-20%.
            </div>
        </div>
        <div class="faq-item">
            <button class="faq-q" type="button">
                <span>What's your refund policy?</span>
                <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
            <div class="faq-a">
                <strong>Full refund</strong> up to 14 days before camp starts, no questions asked. After that, we offer camp credit for future sessions. We're flexible—just text Luke.
            </div>
        </div>
        <div class="faq-item">
            <button class="faq-q" type="button">
                <span>Questions? Need help?</span>
                <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
            <div class="faq-a">
                Text or call Luke directly: <strong><?php echo esc_html(function_exists('ptp_email_brand') ? ptp_email_brand('support_phone') : ''); ?></strong>. Happy to answer anything or help you find the right camp for your child.
            </div>
        </div>
    </div>
</section>

<!-- Final CTA -->
<section class="final-cta">
    <h2 class="final-headline">Ready to <span>Level Up?</span></h2>
    <p class="final-sub">500+ families trust PTP for elite soccer development.</p>
    <a href="#camps" class="final-btn">Find Your Camp →</a>
    <p class="final-guarantee">
        <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
        14-Day Full Refund Guarantee
    </p>
</section>

<!-- Footer -->
<footer class="site-footer">
    <div class="footer-logo">PTP</div>
    <div class="footer-links">
        <a href="/about/">About</a>
        <a href="/">Private Training</a>
        <a href="/contact/">Contact</a>
        <a href="/privacy/">Privacy</a>
    </div>
    <p class="footer-copy">© 2026 PTP Soccer Camps. All rights reserved.</p>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // FAQ Accordion
    var faqList = document.getElementById('faqList');
    if (faqList) {
        faqList.addEventListener('click', function(e) {
            var btn = e.target.closest('.faq-q');
            if (!btn) return;
            
            var item = btn.closest('.faq-item');
            var wasOpen = item.classList.contains('open');
            
            // Close all
            faqList.querySelectorAll('.faq-item').forEach(function(i) {
                i.classList.remove('open');
            });
            
            // Open clicked if it wasn't open
            if (!wasOpen) {
                item.classList.add('open');
            }
        });
    }
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                var offset = 80;
                var targetPosition = target.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top: targetPosition, behavior: 'smooth' });
            }
        });
    });
});
</script>

</body>
</html>
<?php
get_footer();
