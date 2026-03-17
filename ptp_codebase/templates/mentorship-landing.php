<?php
/**
 * Mentorship Landing Page Template v227
 * Served standalone via PTP_Mentorship::serve_standalone_page()
 * or via [ptp_mentorship_hub] shortcode for non-logged-in users.
 */
defined('ABSPATH') || exit;

$tiers = PTP_Mentorship::TIERS;

// ── Fetch mentorship-enabled trainers ──
global $wpdb;
$mentors = $wpdb->get_results(
    "SELECT t.*, 
            (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs mp 
             WHERE mp.trainer_id = t.id AND mp.status = 'active') AS active_mentees
     FROM {$wpdb->prefix}ptp_trainers t 
     WHERE t.status = 'active' AND t.mentorship_enabled = 1
     ORDER BY t.is_featured DESC, t.average_rating DESC, RAND()
     LIMIT 12"
);
$has_mentors = !empty($mentors);

// Fallback: if no trainers have mentorship enabled yet, show top featured trainers
if (!$has_mentors) {
    $mentors = $wpdb->get_results(
        "SELECT t.*, 0 AS active_mentees
         FROM {$wpdb->prefix}ptp_trainers t 
         WHERE t.status = 'active' AND t.is_featured = 1
         ORDER BY t.average_rating DESC
         LIMIT 6"
    );
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Online Mentorship — PTP Soccer</title>
<meta name="description" content="Weekly 1-on-1 video mentorship with current D1 and MLS athletes. Film review, goal tracking, and confidence building for players ages 8-18.">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<link rel="canonical" href="<?php echo esc_url(home_url('/mentorship/')); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="Online Soccer Mentorship | D1 & MLS Athletes — PTP">
<meta property="og:description" content="Weekly 1-on-1 video mentorship with current D1 and MLS athletes. Film review, goal tracking, and confidence building.">
<meta property="og:url" content="<?php echo esc_url(home_url('/mentorship/')); ?>">
<meta property="og:site_name" content="PTP Soccer">
<meta property="og:locale" content="en_US">
<meta property="og:image" content="https://ptpsummercamps.com/wp-content/uploads/2026/02/group-photo.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Online Soccer Mentorship — PTP">
<meta name="twitter:description" content="Weekly 1-on-1 video mentorship with D1 and MLS athletes.">
<?php wp_head(); ?>
<style>
footer,.site-footer,#footer,.elementor-location-footer,.footer-wrapper,
.elementor-location-header,header.elementor-element,#masthead,
.site-header,.theme-header,[data-elementor-type="header"]{display:none!important}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--gold:#FCB900;--black:#0A0A0A;--white:#FFF;--surface:#F8F8F6;--border:#EAEAE6;--muted:#737373;--display:'Oswald',sans-serif;--body:'Inter',-apple-system,sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--body);-webkit-font-smoothing:antialiased;background:var(--surface);color:#1A1A1A}
h1,h2,h3,h4{font-family:var(--display);font-weight:700;text-transform:uppercase;line-height:1.1}
img{max-width:100%;display:block}

/* Hero */
.mh-hero{background:var(--black);color:var(--white);text-align:center;padding:72px 20px 56px;position:relative;overflow:hidden}
.mh-hero::before{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:radial-gradient(ellipse at 50% %,rgba(252,185,0,0.12),transparent 65%)}
.mh-hero-label{font-family:var(--display);text-transform:uppercase;font-size:12px;letter-spacing:3px;color:var(--gold);margin-bottom:14px;position:relative}
.mh-hero h1{font-size:clamp(36px,7vw,64px);margin-bottom:18px;position:relative;letter-spacing:-0.5px}
.mh-hero h1 span{color:var(--gold)}
.mh-hero-sub{font-size:17px;color:#A3A3A3;max-width:560px;margin:0 auto 28px;line-height:1.65;position:relative}
.mh-hero-cta{display:inline-block;background:var(--gold);color:var(--black);font-family:var(--display);font-weight:700;text-transform:uppercase;padding:16px 40px;border-radius:10px;font-size:16px;text-decoration:none;border:2px solid var(--gold);transition:all 0.2s;letter-spacing:.5px;position:relative}
.mh-hero-cta:hover{background:transparent;color:var(--gold)}
.mh-hero-trust{display:flex;justify-content:center;gap:24px;margin-top:28px;position:relative}
.mh-hero-trust span{font-size:13px;color:#525252;font-weight:500}
.mh-hero-trust strong{color:var(--gold)}

/* Sections */
.mh-section{padding:56px 20px;max-width:960px;margin:0 auto}
.mh-section-label{font-family:var(--display);text-transform:uppercase;font-size:11px;letter-spacing:3px;color:var(--gold);margin-bottom:8px;text-align:center}
.mh-section h2{text-align:center;font-size:clamp(24px,4vw,32px);margin-bottom:36px}

/* Steps */
.mh-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}
.mh-step{background:var(--white);border-radius:14px;padding:24px 20px;border:2px solid var(--border);text-align:center;transition:border-color .2s}
.mh-step:hover{border-color:var(--gold)}
.mh-step-num{width:40px;height:40px;border-radius:50%;background:var(--gold);color:var(--black);display:inline-flex;align-items:center;justify-content:center;font-family:var(--display);font-weight:700;font-size:18px;margin-bottom:12px}
.mh-step h4{font-size:15px;margin-bottom:8px}
.mh-step p{font-size:13px;color:var(--muted);line-height:1.55}

/* Mentors Grid */
.mh-mentors{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
.mh-mentor{background:var(--white);border-radius:14px;border:2px solid var(--border);overflow:hidden;transition:border-color .2s,transform .2s}
.mh-mentor:hover{border-color:var(--gold);transform:translateY(-2px)}
.mh-mentor-photo{width:100%;aspect-ratio:4/3;object-fit:cover;background:#E5E5E5}
.mh-mentor-body{padding:16px 18px 20px}
.mh-mentor-name{font-family:var(--display);font-size:17px;font-weight:700;text-transform:uppercase;margin-bottom:3px}
.mh-mentor-tag{font-size:12px;color:var(--muted);margin-bottom:10px;line-height:1.4}
.mh-mentor-bio{font-size:13px;color:#525252;line-height:1.5;margin-bottom:14px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.mh-mentor-meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.mh-mentor-badge{font-size:11px;padding:4px 10px;border-radius:6px;font-weight:600}
.mh-mentor-badge.rating{background:rgba(252,185,0,0.12);color:#B8860B}
.mh-mentor-badge.spots{background:rgba(34,197,94,0.1);color:#16A34A}
.mh-mentor-badge.full{background:rgba(239,68,68,0.1);color:#DC2626}
.mh-mentor-btn{display:block;width:100%;padding:12px;background:var(--black);color:var(--gold);font-family:var(--display);font-weight:700;text-transform:uppercase;font-size:13px;border-radius:8px;text-align:center;text-decoration:none;border:2px solid var(--black);transition:all .2s;letter-spacing:.5px}
.mh-mentor-btn:hover{background:var(--gold);color:var(--black);border-color:var(--gold)}
.mh-mentor-btn.disabled{opacity:.4;pointer-events:none}

/* Case Study */
.mh-case{background:var(--black);color:var(--white);padding:56px 20px}
.mh-case-inner{max-width:720px;margin:0 auto}
.mh-case-quote{font-size:17px;font-style:italic;line-height:1.7;color:#D4D4D4;margin-bottom:24px;border-left:3px solid var(--gold);padding-left:20px}
.mh-case-flow{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}
.mh-case-tag{background:rgba(252,185,0,0.15);color:var(--gold);padding:6px 14px;border-radius:8px;font-size:12px;font-weight:600}
.mh-case-arrow{color:#525252;display:flex;align-items:center;font-size:16px}

/* Tiers */
.mh-tiers{display:grid;gap:16px;max-width:960px;margin:0 auto}
@media(min-width:768px){.mh-tiers{grid-template-columns:repeat(4,1fr)}}
.mh-tier{background:var(--white);border-radius:14px;padding:28px 24px;border:2px solid var(--border);position:relative;transition:border-color 0.2s}
.mh-tier.popular{border-color:var(--gold)}
.mh-tier.premium{border-color:var(--black);background:var(--black);color:var(--white)}
.mh-tier-badge{position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--gold);color:var(--black);font-family:var(--display);font-weight:700;font-size:11px;text-transform:uppercase;padding:4px 14px;border-radius:20px;letter-spacing:1px;white-space:nowrap}
.mh-tier-name{font-family:var(--display);font-weight:700;font-size:22px;text-transform:uppercase;margin-bottom:4px}
.mh-tier-price{font-size:40px;font-weight:800;margin-bottom:4px}
.mh-tier-price span{font-size:16px;font-weight:400;color:var(--muted)}
.premium .mh-tier-price span{color:#A3A3A3}
.mh-tier-desc{font-size:13px;color:var(--muted);margin-bottom:20px;line-height:1.5}
.premium .mh-tier-desc{color:#A3A3A3}
.mh-tier-features{list-style:none;padding:0;margin-bottom:24px}
.mh-tier-features li{font-size:13px;padding:5px 0;display:flex;align-items:flex-start;gap:8px;line-height:1.4}
.mh-tier-features li .ck{flex-shrink:0;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px}
.mh-tier-features li.inc .ck{background:rgba(34,197,94,0.15);color:#22C55E}
.mh-tier-features li.exc{color:var(--muted);opacity:0.5}
.mh-tier-features li.exc .ck{background:rgba(0,0,0,0.05);color:#A3A3A3}
.premium .mh-tier-features li.exc{color:#525252}
.mh-tier-btn{display:block;width:100%;padding:14px;border:2px solid var(--gold);background:var(--gold);color:var(--black);font-family:var(--display);font-weight:700;text-transform:uppercase;font-size:15px;border-radius:10px;cursor:pointer;text-align:center;text-decoration:none;transition:all 0.2s;letter-spacing:.5px}
.mh-tier-btn:hover{background:transparent;color:var(--gold)}
.premium .mh-tier-btn:hover{color:var(--gold)}

/* Stats */
.mh-a2a{background:var(--white);padding:56px 20px}
.mh-a2a-inner{max-width:720px;margin:0 auto;text-align:center}
.mh-a2a-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:28px 0}
.mh-a2a-stat strong{display:block;font-size:32px;font-family:var(--display);color:var(--gold)}
.mh-a2a-stat span{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}

/* FAQ */
.mh-faq{max-width:640px;margin:0 auto}
.mh-faq-item{border-bottom:1px solid var(--border);padding:16px 0;cursor:pointer}
.mh-faq-q{font-family:var(--display);font-weight:700;font-size:15px;text-transform:uppercase;display:flex;justify-content:space-between;align-items:center;gap:12px}
.mh-faq-a{font-size:14px;line-height:1.7;color:var(--muted);padding-top:12px;display:none}
.mh-faq-item.open .mh-faq-a{display:block}
.mh-faq-q::after{content:'+';font-size:20px;color:var(--gold);transition:transform 0.2s;flex-shrink:0}
.mh-faq-item.open .mh-faq-q::after{content:'\2212'}

/* CTA */
.mh-cta{background:var(--gold);padding:56px 20px;text-align:center}
.mh-cta h2{color:var(--black);font-size:clamp(28px,5vw,36px);margin-bottom:12px}
.mh-cta p{color:rgba(0,0,0,0.65);font-size:16px;margin-bottom:28px;max-width:480px;margin-left:auto;margin-right:auto;line-height:1.6}
.mh-cta-btn{display:inline-block;background:var(--black);color:var(--white);font-family:var(--display);font-weight:700;text-transform:uppercase;padding:16px 44px;border-radius:10px;font-size:16px;text-decoration:none;border:2px solid var(--black);transition:all 0.2s;letter-spacing:.5px}
.mh-cta-btn:hover{background:transparent;color:var(--black)}

@media(max-width:640px){
    .mh-hero{padding:52px 16px 40px}
    .mh-section{padding:40px 16px}
    .mh-tier{padding:24px 20px}
    .mh-a2a-stats{grid-template-columns:1fr}
    .mh-hero-trust{flex-direction:column;gap:8px}
    .mh-mentors{grid-template-columns:1fr}
    .mh-case{padding:40px 16px}
}
</style>
</head>
<body <?php body_class(); ?>>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
<style>
footer,.site-footer,#footer,.elementor-location-footer,.footer-wrapper,
.elementor-location-header,header.elementor-element,#masthead,
.site-header,.theme-header,[data-elementor-type="header"]{display:none!important}
</style>

<!-- HERO -->
<section class="mh-hero">
    <div class="mh-hero-label">PTP Online Mentorship</div>
    <h1>YOUR <span>MENTOR</span>.<br>YOUR GAME.</h1>
    <p class="mh-hero-sub">A D1 or MLS athlete becomes your kid's personal mentor. Weekly video calls, film review, and real accountability. Not a course. A relationship.</p>
    <a href="#mentors" class="mh-hero-cta">Meet the Mentors</a>
    <div class="mh-hero-trust">
        <span>✓ Try a <strong>single session</strong></span>
        <span>✓ <strong>D1 &amp; MLS</strong> athletes</span>
        <span>✓ <strong>Cancel</strong> anytime</span>
    </div>
</section>

<!-- HOW IT WORKS -->
<section class="mh-section">
    <div class="mh-section-label">How It Works</div>
    <h2>4 Steps to a Real Mentor</h2>
    <div class="mh-steps">
        <div class="mh-step">
            <div class="mh-step-num">1</div>
            <h4>Pick Your Mentor</h4>
            <p>Choose from current MLS players and NCAA D1 athletes. Many are coaches your kid already knows from camp.</p>
        </div>
        <div class="mh-step">
            <div class="mh-step-num">2</div>
            <h4>Free Intro Call</h4>
            <p>15-minute video call so your player and mentor can meet. No commitment, no payment until you're ready.</p>
        </div>
        <div class="mh-step">
            <div class="mh-step-num">3</div>
            <h4>Choose a Package</h4>
            <p>Kickstart (12 sessions), Development (24), or Elite (36). Weekly billing. Cancel anytime.</p>
        </div>
        <div class="mh-step">
            <div class="mh-step-num">4</div>
            <h4>Watch Them Grow</h4>
            <p>Weekly calls, film review, goal tracking, and action items. You get a parent summary after every session.</p>
        </div>
    </div>
</section>

<!-- MEET YOUR MENTORS -->
<section class="mh-section" id="mentors" style="scroll-margin-top:20px">
    <div class="mh-section-label"><?php echo $has_mentors ? 'Available Now' : 'Our Coaches'; ?></div>
    <h2><?php echo $has_mentors ? 'Meet Your Mentors' : 'PTP Coaches'; ?></h2>
    <?php if (!empty($mentors)): ?>
    <div class="mh-mentors">
        <?php foreach ($mentors as $m):
            $max_m = max(1, intval($m->mentorship_max_mentees ?? 20));
            $spots = max(0, $max_m - intval($m->active_mentees));
            $photo = $m->photo_url ?: '';
            $headline_parts = array_filter([$m->college ?? '', $m->playing_level ?? '']);
            $mentor_bio = !empty($m->mentorship_bio) ? $m->mentorship_bio : (!empty($m->bio) ? substr($m->bio, 0, 140) : '');
            $profile_url = home_url('/trainer/' . $m->slug . '/');
            $signup_url = add_query_arg(['trainer_id' => $m->id, 'package' => 'development'], home_url('/mentorship-signup/'));
        ?>
        <div class="mh-mentor">
            <?php if ($photo): ?>
            <img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($m->display_name); ?>" class="mh-mentor-photo" loading="lazy">
            <?php else: ?>
            <div class="mh-mentor-photo" style="display:flex;align-items:center;justify-content:center;background:var(--black);color:var(--gold);font-family:var(--display);font-size:36px;font-weight:700"><?php echo esc_html(strtoupper(substr($m->display_name ?? 'C', 0, 1))); ?></div>
            <?php endif; ?>
            <div class="mh-mentor-body">
                <div class="mh-mentor-name"><?php echo esc_html($m->display_name); ?></div>
                <div class="mh-mentor-tag">
                    <?php echo esc_html(implode(' · ', $headline_parts)); ?>
                    <?php if (!empty($m->position)): ?> · <?php echo esc_html($m->position); ?><?php endif; ?>
                </div>
                <?php if ($mentor_bio): ?>
                <div class="mh-mentor-bio"><?php echo esc_html($mentor_bio); ?></div>
                <?php endif; ?>
                <div class="mh-mentor-meta">
                    <?php if (floatval($m->average_rating) >= 4.0): ?>
                    <span class="mh-mentor-badge rating">&#9733; <?php echo number_format(floatval($m->average_rating), 1); ?></span>
                    <?php endif; ?>
                    <?php if ($has_mentors): ?>
                        <?php if ($spots > 0 && $spots <= 5): ?>
                        <span class="mh-mentor-badge spots"><?php echo $spots; ?> spot<?php echo $spots !== 1 ? 's' : ''; ?> left</span>
                        <?php elseif ($spots <= 0): ?>
                        <span class="mh-mentor-badge full">Full</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if ($has_mentors && $spots > 0): ?>
                <a href="<?php echo esc_url($signup_url); ?>" class="mh-mentor-btn">Free Intro Call</a>
                <?php elseif ($has_mentors && $spots <= 0): ?>
                <span class="mh-mentor-btn disabled">Waitlist</span>
                <?php else: ?>
                <a href="<?php echo esc_url($profile_url); ?>" class="mh-mentor-btn">View Profile</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (!$has_mentors): ?>
    <p style="text-align:center;color:var(--muted);font-size:14px;margin-top:20px;line-height:1.6">Mentorship launching soon with select coaches. Check individual trainer profiles for availability.</p>
    <?php endif; ?>
    <?php else: ?>
    <p style="text-align:center;color:var(--muted);font-size:14px;line-height:1.6">Mentorship spots opening soon. <a href="<?php echo esc_url(home_url('/coaches/')); ?>" style="color:var(--gold);font-weight:600">Browse our coaches</a> in the meantime.</p>
    <?php endif; ?>
</section>

<!-- CASE STUDY -->
<section class="mh-case">
    <div class="mh-case-inner">
        <div class="mh-section-label" style="color:var(--gold)">The Pipeline</div>
        <h2 style="color:var(--white);font-size:clamp(24px,4vw,30px);margin-bottom:24px">FROM CAMP TO MENTOR TO CONFIDENT</h2>
        <div class="mh-case-quote">
            "My son came to PTP camp last summer and connected with his coach. When mentorship launched, signing up was a no-brainer. He films himself doing moves, sends it to his mentor, and gets feedback back. He watches that 45-second clip 30 times. Shows his friends. His confidence has completely changed."
        </div>
        <p style="color:var(--gold);font-weight:600;font-size:14px">- PTP Parent</p>
        <div class="mh-case-flow">
            <span class="mh-case-tag">Camp Week</span>
            <span class="mh-case-arrow">&rarr;</span>
            <span class="mh-case-tag">Bond with Coach</span>
            <span class="mh-case-arrow">&rarr;</span>
            <span class="mh-case-tag">Free Intro Call</span>
            <span class="mh-case-arrow">&rarr;</span>
            <span class="mh-case-tag">Weekly Sessions</span>
            <span class="mh-case-arrow">&rarr;</span>
            <span class="mh-case-tag">Real Growth</span>
        </div>
    </div>
</section>

<!-- TIERS -->
<section class="mh-section">
    <div class="mh-section-label">Session Packages</div>
    <h2>Try One or Commit</h2>
    <p style="text-align:center;font-size:14px;color:var(--muted);margin-top:-24px;margin-bottom:28px;max-width:460px;margin-left:auto;margin-right:auto;line-height:1.6">Start with a single session to see if the fit is right, or go all-in with a weekly package. Cancel anytime.</p>
    <div class="mh-tiers">
        <?php foreach ($tiers as $key => $tier):
            $class = $tier['class'] ?? '';
        ?>
        <div class="mh-tier <?php echo $class; ?>">
            <?php if (!empty($tier['badge'])): ?>
                <div class="mh-tier-badge"><?php echo esc_html($tier['badge']); ?></div>
            <?php endif; ?>
            <div class="mh-tier-name"><?php echo esc_html($tier['name']); ?></div>
            <div class="mh-tier-price">$<?php echo $tier['price_display']; ?><span>/session</span></div>
            <div class="mh-tier-desc"><?php echo esc_html($tier['desc']); ?></div>
            <ul class="mh-tier-features">
                <?php if ($key === 'single'): ?>
                <li class="inc"><span class="ck">&#10003;</span> One <?php echo $tier['session_length']; ?> video call</li>
                <li class="inc"><span class="ck">&#10003;</span> Meet your coach, no commitment</li>
                <li class="inc"><span class="ck">&#10003;</span> Personalized feedback &amp; next steps</li>
                <li class="inc"><span class="ck">&#10003;</span> Upgrade to a package anytime</li>
                <li class="exc"><span class="ck">&times;</span> Direct message access</li>
                <li class="exc"><span class="ck">&times;</span> Video reviews</li>
                <li class="exc"><span class="ck">&times;</span> Custom training plan</li>
                <li class="exc"><span class="ck">&times;</span> Tryout prep</li>
                <?php else: ?>
                <li class="inc"><span class="ck">&#10003;</span> <?php echo $tier['sessions']; ?> weekly <?php echo $tier['session_length']; ?> sessions</li>
                <li class="inc"><span class="ck">&#10003;</span> Video calls with your coach</li>
                <li class="<?php echo $tier['has_dm_access'] ? 'inc' : 'exc'; ?>"><span class="ck"><?php echo $tier['has_dm_access'] ? '&#10003;' : '&times;'; ?></span> Direct message access</li>
                <li class="<?php echo $tier['video_reviews'] > 0 ? 'inc' : 'exc'; ?>"><span class="ck"><?php echo $tier['video_reviews'] > 0 ? '&#10003;' : '&times;'; ?></span> <?php echo $tier['video_reviews'] ?: 'No'; ?> video review<?php echo $tier['video_reviews'] !== 1 ? 's' : ''; ?>/month</li>
                <li class="<?php echo $tier['has_training_plan'] ? 'inc' : 'exc'; ?>"><span class="ck"><?php echo $tier['has_training_plan'] ? '&#10003;' : '&times;'; ?></span> Custom training plan</li>
                <li class="inc"><span class="ck">&#10003;</span> Weekly action items &amp; goals</li>
                <li class="<?php echo $tier['has_tryout_prep'] ? 'inc' : 'exc'; ?>"><span class="ck"><?php echo $tier['has_tryout_prep'] ? '&#10003;' : '&times;'; ?></span> Tryout &amp; showcase prep</li>
                <li class="<?php echo $tier['has_recruiting'] ? 'inc' : 'exc'; ?>"><span class="ck"><?php echo $tier['has_recruiting'] ? '&#10003;' : '&times;'; ?></span> College recruiting guidance</li>
                <?php endif; ?>
            </ul>
            <a href="#mentors" class="mh-tier-btn">Find a Mentor</a>
        </div>
        <?php endforeach; ?>
    </div>
    <p style="text-align:center;font-size:13px;color:var(--muted);margin-top:16px">Single session is one-time. Packages billed weekly. No payment until after your free intro call. Cancel anytime.</p>
</section>

<!-- WHY IT WORKS -->
<section class="mh-a2a">
    <div class="mh-a2a-inner">
        <div class="mh-section-label">Why It Works</div>
        <h2>THEY'RE 20. THEY REMEMBER BEING 12.</h2>
        <p style="color:var(--muted);font-size:15px;line-height:1.65;margin-bottom:8px">
            Your kid's mentor isn't a 50-year-old reading from a curriculum. They're a current college or professional athlete who was in your kid's shoes 8 years ago. That's why the relationship is different.
        </p>
        <div class="mh-a2a-stats">
            <div class="mh-a2a-stat"><strong>500+</strong><span>Families Served</span></div>
            <div class="mh-a2a-stat"><strong>D1 &amp; MLS</strong><span>Current Athletes Only</span></div>
            <div class="mh-a2a-stat"><strong>48hr</strong><span>Video Review Turnaround</span></div>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="mh-section">
    <div class="mh-section-label">Questions</div>
    <h2>FAQ</h2>
    <div class="mh-faq">
        <div class="mh-faq-item" onclick="this.classList.toggle('open')">
            <div class="mh-faq-q">Can I just try one session first?</div>
            <div class="mh-faq-a">Yes. Every mentor offers a single 30-minute session for $49, no commitment. If the fit is right, you can upgrade to a weekly package. If not, no hard feelings.</div>
        </div>
        <div class="mh-faq-item" onclick="this.classList.toggle('open')">
            <div class="mh-faq-q">What ages is mentorship for?</div>
            <div class="mh-faq-a">Players ages 8-18. Mentors tailor their approach based on age and skill. Younger players focus on confidence and fundamentals. Older players get tactical, film-based, and recruiting-focused guidance.</div>
        </div>
        <div class="mh-faq-item" onclick="this.classList.toggle('open')">
            <div class="mh-faq-q">How do the video calls work?</div>
            <div class="mh-faq-a">Sessions happen over Zoom (or a free video link if your mentor doesn't use Zoom). Your mentor schedules the call, you and your player join from any device. The mentor leads the session, sets goals, reviews film if applicable, and sends a parent summary after every call.</div>
        </div>
        <div class="mh-faq-item" onclick="this.classList.toggle('open')">
            <div class="mh-faq-q">How do video reviews work?</div>
            <div class="mh-faq-a">Your player films themselves practicing or playing in a game. Upload through the platform. Your mentor watches and sends back a personalized video or written response with corrections and next steps. Most reviews come back within 48 hours.</div>
        </div>
        <div class="mh-faq-item" onclick="this.classList.toggle('open')">
            <div class="mh-faq-q">Does my kid's camp coach become their mentor?</div>
            <div class="mh-faq-a">If your player attended a PTP camp or training session, you can request the same coach. That existing relationship accelerates everything. If they haven't been to a camp yet, we match based on position, goals, and personality.</div>
        </div>
        <div class="mh-faq-item" onclick="this.classList.toggle('open')">
            <div class="mh-faq-q">What's the free intro call?</div>
            <div class="mh-faq-a">A 15-minute video call where your player meets their potential mentor. No payment info needed. No commitment. It's a vibe check to make sure the fit is right before you invest.</div>
        </div>
        <div class="mh-faq-item" onclick="this.classList.toggle('open')">
            <div class="mh-faq-q">Can I cancel anytime?</div>
            <div class="mh-faq-a">Yes. Cancel anytime and your mentorship continues through the end of the current billing week. No long-term contracts. No cancellation fees.</div>
        </div>
        <div class="mh-faq-item" onclick="this.classList.toggle('open')">
            <div class="mh-faq-q">What do parents get?</div>
            <div class="mh-faq-a">After every session you receive a parent summary: what they worked on, how your kid did, and the action item for the week. You also see goal progress and milestones in your dashboard.</div>
        </div>
    </div>
</section>

<!-- FINAL CTA -->
<section class="mh-cta">
    <h2>MENTORSHIP > INSTRUCTION</h2>
    <p>Your kid deserves a mentor who gets it. Start with a free intro call.</p>
    <a href="#mentors" class="mh-cta-btn">Find a Mentor</a>
</section>

<?php wp_footer(); ?>
</body>
</html>
