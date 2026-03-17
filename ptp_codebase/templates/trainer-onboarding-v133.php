<?php
/**
 * Trainer Onboarding Template v133
 * 
 * MAJOR IMPROVEMENTS:
 * - Mobile-first responsive design
 * - Better touch targets (48px minimum)
 * - Improved availability grid for mobile
 * - Progress stepper with horizontal scroll
 * - Sticky submit button on mobile
 * - Better photo upload UX
 * - Improved form validation feedback
 * - Safe area insets for notched devices
 * - Smoother animations
 * - Better contract section readability
 * 
 * @since 133.0.0
 */

defined('ABSPATH') || exit;

// $trainer is passed from the shortcode
if (!isset($trainer) || !$trainer) {
    wp_redirect(home_url('/apply/'));
    exit;
}

$trainer_id = $trainer->id;
$is_edit = isset($_GET['edit']) && $_GET['edit'] == '1';

// Get existing training locations (normalized to structured objects)
$training_locations = array();
if (!empty($trainer->training_locations)) {
    $decoded = json_decode($trainer->training_locations, true);
    if (is_array($decoded)) {
        // Normalize: if entries are plain strings, convert to objects
        foreach ($decoded as $loc) {
            if (is_string($loc)) {
                $training_locations[] = array('name' => $loc, 'address' => $loc, 'lat' => null, 'lng' => null, 'place_id' => '');
            } elseif (is_array($loc) && !empty($loc['name'])) {
                $training_locations[] = $loc;
            }
        }
    } else {
        // Legacy: newline-separated string
        $lines = array_filter(array_map('trim', explode("\n", $trainer->training_locations)));
        foreach ($lines as $line) {
            $training_locations[] = array('name' => $line, 'address' => $line, 'lat' => null, 'lng' => null, 'place_id' => '');
        }
    }
}

// Get existing availability data
$availability = array();
if (class_exists('PTP_Availability') && method_exists('PTP_Availability', 'get_weekly')) {
    $raw_availability = PTP_Availability::get_weekly($trainer_id);
    $day_names = array(0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday');
    
    if (!empty($raw_availability)) {
        foreach ($raw_availability as $slot) {
            $day_num = intval($slot->day_of_week);
            if (isset($day_names[$day_num])) {
                $day_name = $day_names[$day_num];
                $availability[$day_name] = array(
                    'enabled' => !empty($slot->is_active),
                    'start' => substr($slot->start_time, 0, 5),
                    'end' => substr($slot->end_time, 0, 5),
                );
            }
        }
    }
}

// Check Stripe status
$has_stripe = !empty($trainer->stripe_account_id);
$stripe_complete = false;
if ($has_stripe && class_exists('PTP_Stripe')) {
    $stripe_complete = PTP_Stripe::is_account_complete($trainer->stripe_account_id);
}

// Calculate completion
$completion = array(
    'photo' => !empty($trainer->photo_url),
    'bio' => !empty($trainer->bio) && strlen($trainer->bio) > 50,
    'experience' => !empty($trainer->playing_level),
    'rate' => !empty($trainer->hourly_rate) && $trainer->hourly_rate > 0,
    'location' => !empty($trainer->city) && !empty($trainer->state),
    'training_locations' => !empty($training_locations),
    'availability' => !empty($availability),
    'contract' => !empty($trainer->contractor_agreement_signed),
    'stripe' => $stripe_complete,
    // mentorship is optional — not counted in completion percentage
);
$completed = array_filter($completion);
$percentage = count($completed) / count($completion) * 100;
$steps_done = count($completed);
$steps_total = count($completion);

// v177: Grouped steps for streamlined progress display
$grouped_steps = array(
    'profile' => array(
        'label' => 'Profile',
        'items' => array('photo', 'bio', 'experience'),
        'done' => !empty($completion['photo']) && !empty($completion['bio']) && !empty($completion['experience']),
    ),
    'pricing' => array(
        'label' => 'Pricing & Location',
        'items' => array('rate', 'location', 'training_locations'),
        'done' => !empty($completion['rate']) && !empty($completion['location']),
    ),
    'schedule' => array(
        'label' => 'Schedule',
        'items' => array('availability'),
        'done' => !empty($completion['availability']),
    ),
    'agreement' => array(
        'label' => 'Agreement',
        'items' => array('contract'),
        'done' => !empty($completion['contract']),
    ),
    'payouts' => array(
        'label' => 'Payouts',
        'items' => array('stripe'),
        'done' => $stripe_complete,
    ),
    'mentorship' => array(
        'label' => 'Mentorship',
        'items' => array('mentorship'),
        'done' => !empty($trainer->mentorship_enabled),
    ),
);
$grouped_done = count(array_filter(array_column($grouped_steps, 'done')));
$grouped_total = count($grouped_steps);

// Google Maps API key
$google_maps_key = get_option('ptp_google_maps_api_key', '') ?: get_option('ptp_google_maps_key', '');

// First name for personalization (prefer DB columns, fall back to splitting display_name)
if (!empty($trainer->first_name)) {
    $first_name = $trainer->first_name;
    $last_name = $trainer->last_name ?? '';
} else {
    $name_parts = explode(' ', $trainer->display_name, 2);
    $first_name = $name_parts[0] ?? '';
    $last_name = $name_parts[1] ?? '';
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5, viewport-fit=cover">
    <meta name="theme-color" content="#0A0A0A">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo $is_edit ? 'Edit Profile' : 'Complete Your Profile'; ?> - PTP</title>
    <meta name="robots" content="noindex, nofollow">
    <?php wp_head(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php if ($google_maps_key): ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr($google_maps_key); ?>&libraries=places&loading=async" async defer></script>
    <?php endif; ?>
<!-- v228: CSS extracted to external cacheable file -->
    <link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-tokens.css">
    <link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-onboarding.css">
</head>
<body class="ptp-custom-template ptp-trainer-onboarding">
<script>
// v178: Hide duplicate headers
(function(){
    document.body.classList.add('ptp-custom-template');
})();
</script>
<style>
/* v178: Hide duplicate Elementor/theme headers and footers */
.ptp-custom-template .elementor-location-header,
.ptp-custom-template header.elementor-element,
.ptp-custom-template #masthead,
.ptp-custom-template .site-header,
.ptp-custom-template .theme-header,
.ptp-custom-template [data-elementor-type="header"],
.ptp-custom-template header:not(.ptp-header) {
    display: none !important;
}
footer, .site-footer, #footer, .elementor-location-footer, .footer-wrapper,
.ast-footer-overlay-wrap, #colophon { display: none !important; }
/* v178: Hide top dash-nav on mobile */
@media (max-width: 767px) {
    .ptp-dash-nav { display: none !important; }
}
</style>

<!-- Header -->
<header class="ptp-header">
    <a href="<?php echo home_url('/'); ?>">
        <img src="<?php echo esc_url(get_option('ptp_logo_url', get_option('ptp_email_logo_url', ''))); ?>" alt="<?php echo esc_attr(function_exists('ptp_email_brand') ? ptp_email_brand('company') : 'PTP'); ?>" class="ptp-header-logo">
    </a>
    <a href="mailto:<?php echo esc_attr(function_exists('ptp_email_brand') ? ptp_email_brand('support_email') : get_option('admin_email')); ?>" class="ptp-header-help">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Help
    </a>
</header>

<main class="ptp-onboard">
    <!-- Welcome -->
    <div class="ptp-welcome">
        <div class="ptp-welcome-emoji">👋</div>
        <h1><?php echo $is_edit ? 'EDIT YOUR PROFILE' : "WELCOME, <span>$first_name</span>!"; ?></h1>
        <p><?php echo $is_edit ? 'Update your profile information below' : 'Just 3 things to start: <strong>photo, rate, and schedule</strong>. Takes under 5 minutes. Everything else is optional.'; ?></p>
    </div>
    
    <!-- Progress -->
    <div class="ptp-progress">
        <div class="ptp-progress-header">
            <div class="ptp-progress-count"><span><?php echo $grouped_done; ?></span> of <?php echo $grouped_total; ?> steps</div>
            <div class="ptp-progress-percent"><?php echo round($percentage); ?>%</div>
        </div>
        <div class="ptp-progress-bar">
            <div class="ptp-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
        </div>
        <div class="ptp-progress-steps">
            <?php 
            $step_targets = array(
                'profile' => 'photo',
                'pricing' => 'pricing',
                'schedule' => 'availability',
                'agreement' => 'contract',
                'payouts' => 'stripe',
                'mentorship' => 'mentorship',
            );
            foreach ($grouped_steps as $key => $group): 
                $target = $step_targets[$key] ?? '';
            ?>
            <div class="ptp-step <?php echo $group['done'] ? 'done' : ''; ?>" data-target="<?php echo esc_attr($target); ?>" onclick="jumpToSection('<?php echo esc_attr($target); ?>')" style="cursor:pointer;-webkit-tap-highlight-color:rgba(252,185,0,.25)">
                <span class="ptp-step-icon">
                    <?php if ($group['done']): ?>
                    <svg viewBox="0 0 24 24" fill="none"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php endif; ?>
                </span>
                <?php echo $group['label']; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Form -->
    <form id="onboardingForm" method="post" enctype="multipart/form-data">
        <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('ptp_nonce'); ?>">
        <input type="hidden" name="action" value="ptp_save_onboarding">
        <input type="hidden" name="trainer_id" value="<?php echo $trainer_id; ?>">
        
        <!-- SECTION 1: PHOTO -->
        <div class="ptp-section <?php echo $completion['photo'] ? 'complete' : ''; ?> open" data-section="photo">
            <div class="ptp-section-header">
                <div class="ptp-section-num">1</div>
                <div class="ptp-section-title">
                    <h2>Profile Photo</h2>
                    <p>First impressions matter</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <div class="ptp-photo-upload">
                    <div class="ptp-photo-preview" id="photoPreview" onclick="document.getElementById('photoInput').click()">
                        <?php if (!empty($trainer->photo_url)): ?>
                            <img src="<?php echo esc_url($trainer->photo_url); ?>" alt="Profile">
                        <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                        <?php endif; ?>
                        <div class="ptp-photo-overlay">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                                <circle cx="12" cy="13" r="4"/>
                            </svg>
                        </div>
                    </div>
                    <div class="ptp-photo-actions">
                        <input type="file" name="photo" id="photoInput" accept="image/*" style="display:none">
                        <button type="button" class="ptp-photo-btn" onclick="document.getElementById('photoInput').click()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            Upload Photo
                        </button>
                        <ul class="ptp-photo-tips">
                            <li>Square photos work best</li>
                            <li>Show your face clearly</li>
                            <li>Wear your training gear</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- SECTION 2: ABOUT -->
        <div class="ptp-section <?php echo $completion['bio'] ? 'complete' : ''; ?>" data-section="bio">
            <div class="ptp-section-header">
                <div class="ptp-section-num">2</div>
                <div class="ptp-section-title">
                    <h2>About You</h2>
                    <p>Tell parents about yourself</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <div class="ptp-grid ptp-grid-2">
                    <div class="ptp-field">
                        <label class="ptp-label">First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" class="ptp-input" value="<?php echo esc_attr($first_name); ?>" required>
                    </div>
                    <div class="ptp-field">
                        <label class="ptp-label">Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" class="ptp-input" value="<?php echo esc_attr($last_name); ?>" required>
                    </div>
                </div>
                
                <div class="ptp-field">
                    <label class="ptp-label">Bio / About Me <span class="required">*</span></label>
                    <textarea name="bio" class="ptp-textarea" placeholder="Tell parents about yourself, your coaching style, and what makes you unique..." required><?php echo esc_textarea($trainer->bio ?? ''); ?></textarea>
                    <p class="ptp-hint">Min 50 characters. This appears on your public profile.</p>
                </div>
                
                <div class="ptp-field">
                    <label class="ptp-label">Why Do You Coach?</label>
                    <textarea name="coaching_why" class="ptp-textarea" rows="3" placeholder="Share your story - what drives you to train young players?"><?php echo esc_textarea($trainer->coaching_why ?? ''); ?></textarea>
                    <p class="ptp-hint">Parents love hearing what motivates you!</p>
                </div>
                
                <div class="ptp-field">
                    <label class="ptp-label">Training Philosophy</label>
                    <textarea name="training_philosophy" class="ptp-textarea" rows="3" placeholder="What makes your sessions different? Technical skills? Game IQ? Confidence?"><?php echo esc_textarea($trainer->training_philosophy ?? ''); ?></textarea>
                </div>
            </div>
        </div>
        
        <!-- SECTION 3: EXPERIENCE -->
        <div class="ptp-section <?php echo $completion['experience'] ? 'complete' : ''; ?>" data-section="experience">
            <div class="ptp-section-header">
                <div class="ptp-section-num">3</div>
                <div class="ptp-section-title">
                    <h2>Playing Experience</h2>
                    <p>Your soccer background</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <div class="ptp-field">
                    <label class="ptp-label">Highest Level Played <span class="required">*</span></label>
                    <select name="playing_level" class="ptp-select" required>
                        <option value="">Select your level</option>
                        <option value="pro" <?php selected($trainer->playing_level ?? '', 'pro'); ?>>MLS / Professional</option>
                        <option value="college_d1" <?php selected($trainer->playing_level ?? '', 'college_d1'); ?>>NCAA Division 1</option>
                        <option value="college_d2" <?php selected($trainer->playing_level ?? '', 'college_d2'); ?>>NCAA Division 2/3</option>
                        <option value="academy" <?php selected($trainer->playing_level ?? '', 'academy'); ?>>Academy / ECNL / MLS Next</option>
                        <option value="semi_pro" <?php selected($trainer->playing_level ?? '', 'semi_pro'); ?>>Semi-Professional / USL</option>
                    </select>
                </div>
                
                <div class="ptp-field">
                    <label class="ptp-label">Teams / Clubs Played For</label>
                    <input type="text" name="team" class="ptp-input" value="<?php echo esc_attr($trainer->team ?? ''); ?>" placeholder="e.g., Philadelphia Union, Villanova University">
                </div>
                
                <div class="ptp-grid ptp-grid-2">
                    <div class="ptp-field">
                        <label class="ptp-label">Years Playing</label>
                        <input type="number" name="experience_years" class="ptp-input" value="<?php echo esc_attr($trainer->experience_years ?? ''); ?>" placeholder="e.g., 15">
                    </div>
                    <div class="ptp-field">
                        <label class="ptp-label">Years Coaching</label>
                        <input type="number" name="years_coaching" class="ptp-input" value="<?php echo esc_attr($trainer->years_coaching ?? ''); ?>" placeholder="e.g., 5">
                    </div>
                </div>
                
                <div class="ptp-field">
                    <label class="ptp-label">Certifications</label>
                    <input type="text" name="specialties" class="ptp-input" value="<?php echo esc_attr($trainer->specialties ?? ''); ?>" placeholder="e.g., USSF D License, CPR Certified">
                </div>
            </div>
        </div>
        
        <!-- SECTION 4: PRICING -->
        <div class="ptp-section <?php echo ($completion['rate'] && $completion['location']) ? 'complete' : ''; ?>" data-section="pricing">
            <div class="ptp-section-header">
                <div class="ptp-section-num">4</div>
                <div class="ptp-section-title">
                    <h2>Pricing & Location</h2>
                    <p>Set your rate and area</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <div class="ptp-grid ptp-grid-2">
                    <div class="ptp-field">
                        <label class="ptp-label">Hourly Rate ($) <span class="required">*</span></label>
                        <input type="number" name="hourly_rate" id="ptp-ob-rate" class="ptp-input" value="<?php echo esc_attr($trainer->hourly_rate ?? '75'); ?>" min="25" max="300" required>
                        <p class="ptp-hint">Most trainers charge $50-100/hour</p>
                    </div>
                    <div class="ptp-field">
                        <label class="ptp-label">Travel Radius (miles)</label>
                        <input type="number" name="travel_radius" class="ptp-input" value="<?php echo esc_attr($trainer->travel_radius ?? '15'); ?>" min="1" max="50">
                    </div>
                </div>
                
                <!-- v216.1: Interactive Earnings Breakdown -->
                <div id="ptp-earnings-calc" style="margin-top:20px;background:#F9FAFB;border:2px solid #E5E7EB;border-radius:12px;overflow:hidden;">
                    <div style="background:#0A0A0A;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;">
                        <span style="color:#FCB900;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;">Your Earnings Per Session</span>
                        <span style="color:#9CA3AF;font-size:11px;">Updates with your rate</span>
                    </div>
                    <div style="padding:16px 20px;">
                        <p style="margin:0 0 12px;font-size:13px;color:#6B7280;line-height:1.5;">PTP uses a graduated fee that decreases per family. The more sessions you do with the same family, the more you keep.</p>
                        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
                        <table style="width:100%;border-collapse:collapse;font-size:13px;">
                            <thead>
                                <tr style="border-bottom:2px solid #FCB900;">
                                    <th style="text-align:left;padding:8px 10px;font-weight:700;color:#0A0A0A;">Session</th>
                                    <th style="text-align:center;padding:8px 10px;font-weight:700;color:#0A0A0A;">PTP Fee</th>
                                    <th style="text-align:center;padding:8px 10px;font-weight:700;color:#22C55E;">You Earn</th>
                                    <th style="text-align:right;padding:8px 10px;font-weight:700;color:#22C55E;">$ Per Session</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr style="border-bottom:1px solid #e5e7eb;">
                                    <td style="padding:10px;">Sessions 1 & 2</td>
                                    <td style="text-align:center;padding:10px;color:#6B7280;">50%</td>
                                    <td style="text-align:center;padding:10px;font-weight:600;">50%</td>
                                    <td style="text-align:right;padding:10px;font-weight:700;font-size:15px;" id="earn-tier1">$37.50</td>
                                </tr>
                                <tr style="border-bottom:1px solid #e5e7eb;">
                                    <td style="padding:10px;">Sessions 3 & 4</td>
                                    <td style="text-align:center;padding:10px;color:#6B7280;">25%</td>
                                    <td style="text-align:center;padding:10px;font-weight:600;">75%</td>
                                    <td style="text-align:right;padding:10px;font-weight:700;font-size:15px;" id="earn-tier2">$56.25</td>
                                </tr>
                                <tr style="background:#F0FDF4;">
                                    <td style="padding:10px;">Session 5+</td>
                                    <td style="text-align:center;padding:10px;color:#6B7280;">15%</td>
                                    <td style="text-align:center;padding:10px;font-weight:700;color:#22C55E;">85%</td>
                                    <td style="text-align:right;padding:10px;font-weight:800;font-size:16px;color:#22C55E;" id="earn-tier3">$63.75</td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                        <div style="margin-top:12px;padding:12px;background:#DBEAFE;border-radius:8px;border:1px solid #93C5FD;">
                            <p style="margin:0;font-size:12px;color:#1E40AF;line-height:1.5;"><strong>How it works:</strong> Session count is tracked per family. New family = count resets to 1. Most recurring families hit the 85% tier within a few weeks. Payouts processed weekly via Stripe.</p>
                        </div>
                    </div>
                </div>
                
                <div class="ptp-grid ptp-grid-2">
                    <div class="ptp-field">
                        <label class="ptp-label">City <span class="required">*</span></label>
                        <input type="text" name="city" class="ptp-input" value="<?php echo esc_attr($trainer->city ?? ''); ?>" required>
                    </div>
                    <div class="ptp-field">
                        <label class="ptp-label">State <span class="required">*</span></label>
                        <select name="state" class="ptp-select" required>
                            <option value="">Select</option>
                            <option value="PA" <?php selected($trainer->state ?? '', 'PA'); ?>>Pennsylvania</option>
                            <option value="NJ" <?php selected($trainer->state ?? '', 'NJ'); ?>>New Jersey</option>
                            <option value="DE" <?php selected($trainer->state ?? '', 'DE'); ?>>Delaware</option>
                            <option value="MD" <?php selected($trainer->state ?? '', 'MD'); ?>>Maryland</option>
                            <option value="NY" <?php selected($trainer->state ?? '', 'NY'); ?>>New York</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- SECTION 5: TRAINING LOCATIONS -->
        <div class="ptp-section <?php echo $completion['training_locations'] ? 'complete' : ''; ?>" data-section="locations">
            <div class="ptp-section-header">
                <div class="ptp-section-num">5</div>
                <div class="ptp-section-title">
                    <h2>Training Locations</h2>
                    <p>Where you can train <span class="required">*</span></p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <p class="ptp-hint" style="margin-bottom:16px;">Add at least 1 park, field, or facility where you can run sessions. Parents see these when booking you.</p>
                
                <div class="ptp-locations-list" id="locationsList">
                    <?php foreach ($training_locations as $loc): 
                        $loc_name = is_array($loc) ? ($loc['name'] ?? '') : $loc;
                        $loc_addr = is_array($loc) ? ($loc['address'] ?? $loc_name) : $loc;
                        $loc_lat  = is_array($loc) ? ($loc['lat'] ?? '') : '';
                        $loc_lng  = is_array($loc) ? ($loc['lng'] ?? '') : '';
                        $loc_pid  = is_array($loc) ? ($loc['place_id'] ?? '') : '';
                        if (empty($loc_name)) continue;
                    ?>
                    <div class="ptp-location-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
                            <circle cx="12" cy="10" r="3"/>
                        </svg>
                        <div class="ptp-location-info">
                            <span class="ptp-location-name"><?php echo esc_html($loc_name); ?></span>
                            <?php if ($loc_addr && $loc_addr !== $loc_name): ?>
                            <span class="ptp-location-addr"><?php echo esc_html($loc_addr); ?></span>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="training_locations_data[]" value="<?php echo esc_attr(wp_json_encode(array('name' => $loc_name, 'address' => $loc_addr, 'lat' => $loc_lat, 'lng' => $loc_lng, 'place_id' => $loc_pid))); ?>">
                        <button type="button" class="ptp-location-remove" onclick="removeLocation(this)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="ptp-location-add">
                    <input type="text" id="locationInput" class="ptp-input" placeholder="Search for a park, field, or facility...">
                    <button type="button" class="ptp-location-btn" onclick="addLocation()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Add
                    </button>
                </div>
                <p class="ptp-hint" style="margin-top:8px;">
                    <?php if ($google_maps_key): ?>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2" style="display:inline;vertical-align:middle;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Google Maps powered — start typing and select from suggestions for accurate pin placement.
                    <?php else: ?>
                    Enter the full name and address of each training location.
                    <?php endif; ?>
                </p>
                
                <?php if ($google_maps_key): ?>
                <div class="ptp-map-container" id="locationsMap"></div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- SECTION 6: AVAILABILITY -->
        <div class="ptp-section <?php echo $completion['availability'] ? 'complete' : ''; ?>" data-section="availability">
            <div class="ptp-section-header">
                <div class="ptp-section-num">6</div>
                <div class="ptp-section-title">
                    <h2>Weekly Availability</h2>
                    <p>Set your typical schedule <span class="required">*</span></p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <p class="ptp-hint" style="margin-bottom:16px;">Toggle on at least 1 day and set your hours. This is what parents see when booking. You can block specific dates later from your dashboard.</p>
                
                <!-- v235.8: Quick presets — one tap to fill common schedules -->
                <div style="display:flex;gap:8px;margin-bottom:16px;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:4px">
                    <button type="button" onclick="applyAvailPreset('weekday_eve')" style="flex-shrink:0;padding:10px 16px;background:rgba(252,185,0,0.08);border:2px solid rgba(252,185,0,0.3);border-radius:10px;font-family:Oswald,sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;color:#0A0A0A;cursor:pointer;white-space:nowrap">Weekday Evenings</button>
                    <button type="button" onclick="applyAvailPreset('weekends')" style="flex-shrink:0;padding:10px 16px;background:rgba(252,185,0,0.08);border:2px solid rgba(252,185,0,0.3);border-radius:10px;font-family:Oswald,sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;color:#0A0A0A;cursor:pointer;white-space:nowrap">Weekends Only</button>
                    <button type="button" onclick="applyAvailPreset('all')" style="flex-shrink:0;padding:10px 16px;background:rgba(252,185,0,0.08);border:2px solid rgba(252,185,0,0.3);border-radius:10px;font-family:Oswald,sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;color:#0A0A0A;cursor:pointer;white-space:nowrap">Every Day 4-8pm</button>
                </div>
                
                <div class="ptp-availability">
                    <?php 
                    $days = array('monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed', 'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun');
                    $day_full = array('monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday');
                    foreach ($days as $key => $label):
                        $day_data = $availability[$key] ?? array();
                        $enabled = !empty($day_data['enabled']);
                        $start = $day_data['start'] ?? '16:00';
                        $end = $day_data['end'] ?? '20:00';
                    ?>
                    <div class="ptp-avail-day <?php echo $enabled ? 'active' : ''; ?>">
                        <div class="ptp-avail-header">
                            <span class="ptp-avail-name"><?php echo $day_full[$key]; ?></span>
                            <div class="ptp-toggle <?php echo $enabled ? 'active' : ''; ?>" data-day="<?php echo $key; ?>" onclick="toggleDay(this)"></div>
                            <input type="hidden" name="availability[<?php echo $key; ?>][enabled]" value="<?php echo $enabled ? '1' : '0'; ?>">
                        </div>
                        <div class="ptp-avail-times">
                            <input type="time" name="availability[<?php echo $key; ?>][start]" class="ptp-time-input" value="<?php echo esc_attr($start); ?>">
                            <span>to</span>
                            <input type="time" name="availability[<?php echo $key; ?>][end]" class="ptp-time-input" value="<?php echo esc_attr($end); ?>">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- SECTION 7: CONTRACT -->
        <div class="ptp-section <?php echo $completion['contract'] ? 'complete' : ''; ?>" data-section="contract">
            <div class="ptp-section-header">
                <div class="ptp-section-num">7</div>
                <div class="ptp-section-title">
                    <h2>Trainer Agreement</h2>
                    <p>Required to join PTP</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <?php if ($trainer->contractor_agreement_signed): ?>
                <div class="ptp-contract-status">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <div>
                        <strong>Agreement Signed</strong>
                        <p>Signed on <?php echo date('F j, Y', strtotime($trainer->contractor_agreement_signed_at)); ?></p>
                    </div>
                </div>
                <?php else: ?>
                <div class="ptp-contract-box">
                    <div class="ptp-contract-scroll" id="contractContent">
                        <h3>Independent Contractor Agreement</h3>
                        <p style="font-size:12px;color:#666;">Between PTP - Players Teaching Players, LLC ("PTP") and You ("Trainer")</p>
                        
                        <h4>1. RELATIONSHIP</h4>
                        <p>Trainer agrees to provide private soccer training services as an <strong>independent contractor</strong>, not as an employee of PTP.</p>
                        
                        <h4>2. PLATFORM SERVICES</h4>
                        <p>PTP provides: online platform, booking system, payment processing, marketing support, and insurance coverage during sessions.</p>
                        
                        <h4>3. TRAINER RESPONSIBILITIES</h4>
                        <ul>
                            <li>Provide professional, safe, age-appropriate training</li>
                            <li>Arrive on time and prepared for all sessions</li>
                            <li>Communicate professionally with families</li>
                            <li>Cancel with 24+ hours notice except emergencies</li>
                            <li>Never solicit clients for off-platform bookings</li>
                        </ul>
                        
                        <h4>4. COMPENSATION</h4>
                        <p>PTP uses a graduated fee schedule per parent-trainer relationship. As you build trust with each family, your earnings increase:</p>
                        <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
                        <table style="width:100%;border-collapse:collapse;margin:12px 0;font-size:13px;">
                            <thead>
                                <tr style="border-bottom:2px solid #FCB900;">
                                    <th style="text-align:left;padding:8px 12px;font-weight:700;">Session #</th>
                                    <th style="text-align:center;padding:8px 12px;font-weight:700;">PTP Fee</th>
                                    <th style="text-align:center;padding:8px 12px;font-weight:700;">You Earn</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr style="border-bottom:1px solid #e5e7eb;">
                                    <td style="padding:8px 12px;">Sessions 1 &amp; 2</td>
                                    <td style="text-align:center;padding:8px 12px;">50%</td>
                                    <td style="text-align:center;padding:8px 12px;font-weight:600;">50%</td>
                                </tr>
                                <tr style="border-bottom:1px solid #e5e7eb;">
                                    <td style="padding:8px 12px;">Sessions 3 &amp; 4</td>
                                    <td style="text-align:center;padding:8px 12px;">25%</td>
                                    <td style="text-align:center;padding:8px 12px;font-weight:600;">75%</td>
                                </tr>
                                <tr style="background:#f9fafb;">
                                    <td style="padding:8px 12px;">Session 5+</td>
                                    <td style="text-align:center;padding:8px 12px;">15%</td>
                                    <td style="text-align:center;padding:8px 12px;font-weight:700;color:#22C55E;">85%</td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                        <p style="font-size:12px;color:#666;">Session count is tracked <strong>per parent</strong>. If you train a new family, the count resets. Payouts processed weekly via Stripe Connect.</p>
                        
                        <h4>5. CANCELLATION POLICY</h4>
                        <p>Trainer must provide 24+ hours notice. Repeated last-minute cancellations may result in suspension.</p>
                        
                        <h4>6. CONDUCT & SAFETY</h4>
                        <ul>
                            <li>Never use inappropriate language with minors</li>
                            <li>Train in open, visible areas only</li>
                            <li>Report safety concerns immediately</li>
                        </ul>
                        
                        <h4>7. NON-SOLICITATION</h4>
                        <p>For 12 months after your last session, you agree not to solicit PTP clients outside the platform.</p>
                        
                        <p style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;"><strong>By checking below, you agree to be bound by this Independent Contractor Agreement.</strong></p>
                    </div>
                    
                    <div class="ptp-contract-signature">
                        <label class="ptp-checkbox">
                            <input type="checkbox" name="agree_contract" id="agreeContract" value="1" required>
                            <span>I, <strong><?php echo esc_html($trainer->display_name); ?></strong>, agree to the PTP Trainer Agreement.</span>
                        </label>
                        <p class="ptp-contract-ip">IP: <?php echo esc_html($_SERVER['REMOTE_ADDR']); ?> • Timestamp recorded on submission</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- SECTION 8: MENTORSHIP (Optional) -->
        <div class="ptp-section <?php echo !empty($trainer->mentorship_enabled) ? 'complete' : ''; ?>" data-section="mentorship">
            <div class="ptp-section-header">
                <div class="ptp-section-num">8</div>
                <div class="ptp-section-title">
                    <h2>Mentorship <span style="font-size:12px;font-weight:400;color:#737373;text-transform:none">(Optional)</span></h2>
                    <p>Offer online mentorship to earn recurring revenue</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;padding:16px;margin-bottom:20px">
                    <p style="margin:0;font-size:13px;color:#92400E;line-height:1.5"><strong>What is mentorship?</strong> Parents buy a session package for 1:1 calls with you — the coach their kid already knows from camp. They pay weekly. No auto-renew. When the package ends, you reach out to re-up. Video reviews and film breakdowns are sold as add-ons.</p>
                </div>

                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px">
                    <div style="background:#F8F8F6;border-radius:10px;padding:14px;text-align:center">
                        <div style="font-family:Oswald,sans-serif;font-weight:700;font-size:18px;color:#0A0A0A">$49<span style="font-size:11px;font-weight:400;color:#737373">/session</span></div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;margin-top:2px">Kickstart</div>
                        <div style="font-size:10px;color:#737373;margin-top:4px">12 sessions x 30 min</div>
                        <div style="font-size:10px;color:#22C55E;font-weight:600;margin-top:4px">$39 net/session</div>
                    </div>
                    <div style="background:#F8F8F6;border:2px solid #FCB900;border-radius:10px;padding:14px;text-align:center">
                        <div style="font-family:Oswald,sans-serif;font-weight:700;font-size:18px;color:#0A0A0A">$69<span style="font-size:11px;font-weight:400;color:#737373">/session</span></div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;margin-top:2px;color:#B8860B">Development</div>
                        <div style="font-size:10px;color:#737373;margin-top:4px">24 sessions x 45 min</div>
                        <div style="font-size:10px;color:#22C55E;font-weight:600;margin-top:4px">$55 net/session</div>
                    </div>
                    <div style="background:#F8F8F6;border-radius:10px;padding:14px;text-align:center">
                        <div style="font-family:Oswald,sans-serif;font-weight:700;font-size:18px;color:#0A0A0A">$89<span style="font-size:11px;font-weight:400;color:#737373">/session</span></div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;margin-top:2px">Elite</div>
                        <div style="font-size:10px;color:#737373;margin-top:4px">36 sessions x 60 min</div>
                        <div style="font-size:10px;color:#22C55E;font-weight:600;margin-top:4px">$71 net/session</div>
                    </div>
                </div>

                <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:14px;margin-bottom:20px">
                    <p style="margin:0;font-size:12px;color:#166534;line-height:1.5"><strong>Your earnings:</strong> You keep 80%. PTP takes 20% for payments, platform, and marketing. Example: 8 Development kids = $55 x 24 sessions x 8 kids = <strong>$10,560</strong> over 6 months. Parents pay weekly so it feels like $69/week, not a big commitment. Video Review Packs ($99 for 5) and Film Breakdowns ($129) are extra revenue on top.</p>
                </div>

                <!-- Toggle -->
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:16px;background:#F8F8F6;border-radius:10px">
                    <label class="ptp-toggle-switch" style="position:relative;display:inline-block;width:48px;height:26px;flex-shrink:0">
                        <input type="checkbox" name="mentorship_enabled" id="mentorshipEnabled" value="1" <?php echo !empty($trainer->mentorship_enabled) ? 'checked' : ''; ?> style="opacity:0;width:0;height:0">
                        <span style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:<?php echo !empty($trainer->mentorship_enabled) ? '#FCB900' : '#D4D4D4'; ?>;border-radius:26px;transition:0.3s" id="mentorshipSlider"></span>
                        <span style="position:absolute;content:'';height:20px;width:20px;left:<?php echo !empty($trainer->mentorship_enabled) ? '24px' : '3px'; ?>;bottom:3px;background:white;border-radius:50%;transition:0.3s;box-shadow:0 1px 3px rgba(0,0,0,0.2)" id="mentorshipDot"></span>
                    </label>
                    <div>
                        <div style="font-weight:700;font-size:14px" id="mentorshipStatusText"><?php echo !empty($trainer->mentorship_enabled) ? 'Mentorship Active' : 'Enable Mentorship'; ?></div>
                        <div style="font-size:12px;color:#737373">Parents can subscribe for ongoing coaching from you</div>
                    </div>
                </div>

                <!-- Tiers selection -->
                <div id="mentorshipSettings" style="<?php echo empty($trainer->mentorship_enabled) ? 'display:none' : ''; ?>">
                    <label style="display:block;font-weight:700;font-size:13px;text-transform:uppercase;margin-bottom:8px;font-family:Oswald,sans-serif">Tiers You Offer</label>
                    <?php 
                    $active_pkgs = explode(',', $trainer->mentorship_packages ?? 'single,kickstart,development,elite');
                    $pkg_options = array('single' => 'Single Session ($49 one-time)', 'kickstart' => 'Kickstart ($49/session)', 'development' => 'Development ($69/session)', 'elite' => 'Elite ($89/session)');
                    foreach ($pkg_options as $tk => $tl): ?>
                    <label class="ptp-checkbox" style="display:flex;align-items:center;gap:8px;padding:8px 0;font-size:14px">
                        <input type="checkbox" name="mentorship_packages[]" value="<?php echo $tk; ?>" <?php echo in_array($tk, $active_pkgs) ? 'checked' : ''; ?>>
                        <span><?php echo $tl; ?></span>
                    </label>
                    <?php endforeach; ?>

                    <label style="display:block;font-weight:700;font-size:13px;text-transform:uppercase;margin:16px 0 8px;font-family:Oswald,sans-serif">Mentorship Bio <span style="font-weight:400;text-transform:none;color:#737373">(Optional)</span></label>
                    <textarea name="mentorship_bio" id="mentorshipBio" rows="3" placeholder="What makes you a great mentor? What will kids get from working with you long-term?" style="width:100%;padding:12px;border:2px solid #EAEAE6;border-radius:10px;font-size:14px;font-family:Inter,sans-serif;resize:vertical"><?php echo esc_textarea($trainer->mentorship_bio ?? ''); ?></textarea>

                    <label style="display:block;font-weight:700;font-size:13px;text-transform:uppercase;margin:16px 0 8px;font-family:Oswald,sans-serif">Max Mentees</label>
                    <input type="number" name="mentorship_max_mentees" id="mentorshipMaxMentees" value="<?php echo intval($trainer->mentorship_max_mentees ?: 20); ?>" min="1" max="50" style="width:100px;padding:12px;border:2px solid #EAEAE6;border-radius:10px;font-size:14px">
                    <span style="font-size:12px;color:#737373;margin-left:8px">We recommend starting with 10-15</span>
                </div>
            </div>
        </div>
        
        <!-- SECTION 9: STRIPE -->
        <div class="ptp-section <?php echo $completion['stripe'] ? 'complete' : ''; ?>" data-section="stripe">
            <div class="ptp-section-header">
                <div class="ptp-section-num">9</div>
                <div class="ptp-section-title">
                    <h2>Get Paid</h2>
                    <p>Connect your bank account</p>
                </div>
                <div class="ptp-section-toggle">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>
            <div class="ptp-section-body">
                <?php if ($stripe_complete): ?>
                <div class="ptp-stripe-status complete">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <div>
                        <strong>Stripe Connected!</strong>
                        <p>You're all set to receive payouts.</p>
                    </div>
                </div>
                <?php elseif ($has_stripe): ?>
                <div class="ptp-stripe-status pending">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <div>
                        <strong>Almost There!</strong>
                        <p>Stripe needs a few more details to verify your account. Tap below to finish — it only takes 2 minutes.</p>
                    </div>
                </div>
                <button type="button" class="ptp-stripe-btn" onclick="connectStripe(event)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                    Complete Stripe Setup
                </button>
                <p class="ptp-hint" style="margin-top:12px;font-size:11px;color:#999;">Having trouble? Try a different browser or clear your cache. If it still doesn't work, text Luke directly.</p>
                <?php else: ?>
                <div style="padding:20px;background:#F8F5FF;border:2px solid #E8E0FF;border-radius:12px;margin-bottom:16px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                        <div style="width:40px;height:40px;border-radius:10px;background:#635BFF;display:flex;align-items:center;justify-content:center;">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        </div>
                        <div>
                            <strong style="font-size:15px;color:#1a1a1a;">Get Paid After Every Session</strong>
                            <p style="font-size:12px;color:#666;margin:2px 0 0;">Payouts hit your bank in 2 business days</p>
                        </div>
                    </div>
                    <ul style="margin:0 0 16px;padding:0 0 0 20px;font-size:13px;color:#444;line-height:2;">
                        <li>Takes <strong>under 2 minutes</strong> to set up</li>
                        <li>Just need your <strong>SSN last 4</strong> + bank info</li>
                        <li>Stripe is used by <strong>millions of businesses</strong> worldwide</li>
                        <li>You do <strong>NOT</strong> need a business — personal accounts work</li>
                    </ul>
                </div>
                <button type="button" id="stripeConnectBtn" class="ptp-stripe-btn" onclick="connectStripe(event)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                    Connect with Stripe
                </button>
                <p class="ptp-hint" style="margin-top:10px;font-size:11px;color:#999;">You'll be redirected to Stripe's secure site to enter your info, then sent right back here.</p>
                <?php endif; ?>
            </div>
        </div>
    </form>
</main>

<!-- Sticky Submit Footer -->
<div class="ptp-submit-footer">
    <div class="ptp-submit-footer-inner">
        <?php if (!$is_edit): ?>
        <a href="<?php echo home_url('/trainer-dashboard/?skip_onboarding=1'); ?>" class="ptp-skip-link">Skip</a>
        <?php endif; ?>
        <button type="submit" form="onboardingForm" class="ptp-submit-btn" id="submitBtn">
            <?php echo $is_edit ? 'Save Changes' : 'Complete Profile'; ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="5" y1="12" x2="19" y2="12"/>
                <polyline points="12 5 19 12 12 19"/>
            </svg>
        </button>
    </div>
</div>

<!-- Toast -->
<div class="ptp-toast" id="toast"></div>

<script>
// Section toggle
function toggleSection(header) {
    var section = header.closest('.ptp-section');
    var wasOpen = section.classList.contains('open');
    
    // Close all sections
    document.querySelectorAll('.ptp-section.open').forEach(function(s) {
        if (s !== section) s.classList.remove('open');
    });
    
    // Toggle clicked section
    if (wasOpen) {
        section.classList.remove('open');
    } else {
        section.classList.add('open');
    }
    
    // Scroll into view
    if (!wasOpen) {
        setTimeout(function() {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
        
        // v211: Trigger Google Maps resize when locations section opens
        if (section.dataset.section === 'locations' && typeof map !== 'undefined' && map) {
            setTimeout(function() {
                google.maps.event.trigger(map, 'resize');
                if (markers.length === 1) {
                    map.setCenter(markers[0].getPosition());
                    map.setZoom(13);
                } else if (markers.length > 1 && mapBounds) {
                    map.fitBounds(mapBounds, 40);
                }
            }, 200);
        }
    }
}

// Jump to section from progress steps
function jumpToSection(sectionKey) {
    if (!sectionKey) return;
    var section = document.querySelector('.ptp-section[data-section="' + sectionKey + '"]');
    if (!section) return;
    
    // Close all, open target
    document.querySelectorAll('.ptp-section.open').forEach(function(s) { s.classList.remove('open'); });
    section.classList.add('open');
    
    setTimeout(function() {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
    
    // Trigger Google Maps resize if locations
    if (sectionKey === 'locations' && typeof map !== 'undefined' && map) {
        setTimeout(function() {
            google.maps.event.trigger(map, 'resize');
            if (markers.length === 1) { map.setCenter(markers[0].getPosition()); map.setZoom(13); }
            else if (markers.length > 1 && mapBounds) { map.fitBounds(mapBounds, 40); }
        }, 200);
    }
}

// v134: Unified click handler for section headers (works on both desktop and mobile)
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.ptp-section-header').forEach(function(header) {
        header.addEventListener('click', function(e) {
            // Don't toggle if user clicked an input/select/button inside header
            if (e.target.closest('input, select, button, a')) return;
            toggleSection(header);
        });
    });
});

// v177: Auto-open first incomplete section on load
(function() {
    var sections = document.querySelectorAll('.ptp-section');
    var openedFirst = false;
    sections.forEach(function(s) {
        if (!openedFirst && !s.classList.contains('complete')) {
            s.classList.add('open');
            openedFirst = true;
        } else if (s.classList.contains('open') && openedFirst) {
            s.classList.remove('open');
        }
    });
    // If all complete, open the last one
    if (!openedFirst && sections.length > 0) {
        sections[sections.length - 1].classList.add('open');
    }
})();

// Day toggle
function toggleDay(el) {
    el.classList.toggle('active');
    const day = el.closest('.ptp-avail-day');
    day.classList.toggle('active', el.classList.contains('active'));
    const input = el.nextElementSibling;
    input.value = el.classList.contains('active') ? '1' : '0';
}

// v235.8: One-tap availability presets
function applyAvailPreset(preset) {
    var presets = {
        weekday_eve: { days: ['monday','tuesday','wednesday','thursday','friday'], start: '16:00', end: '20:00' },
        weekends:    { days: ['saturday','sunday'], start: '09:00', end: '17:00' },
        all:         { days: ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'], start: '16:00', end: '20:00' }
    };
    var p = presets[preset];
    if (!p) return;
    document.querySelectorAll('.ptp-avail-day').forEach(function(row) {
        var toggle = row.querySelector('.ptp-toggle');
        var dayKey = toggle ? toggle.dataset.day : '';
        var shouldEnable = p.days.indexOf(dayKey) !== -1;
        var hiddenInput = row.querySelector('input[type="hidden"]');
        var startInput = row.querySelector('input[name*="[start]"]');
        var endInput = row.querySelector('input[name*="[end]"]');
        if (shouldEnable) {
            toggle.classList.add('active');
            row.classList.add('active');
            if (hiddenInput) hiddenInput.value = '1';
            if (startInput) startInput.value = p.start;
            if (endInput) endInput.value = p.end;
        } else {
            toggle.classList.remove('active');
            row.classList.remove('active');
            if (hiddenInput) hiddenInput.value = '0';
        }
    });
    showToast('Schedule applied!', 'success');
}

// Photo preview
document.getElementById('photoInput').addEventListener('change', function(e) {
    if (e.target.files && e.target.files[0]) {
        const reader = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('photoPreview').innerHTML = 
                '<img src="' + ev.target.result + '" alt="Preview">' +
                '<div class="ptp-photo-overlay"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg></div>';
            showToast('Photo selected!', 'success');
        };
        reader.readAsDataURL(e.target.files[0]);
    }
});

// Toast
function showToast(message, type = '') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'ptp-toast show ' + type;
    setTimeout(() => toast.classList.remove('show'), 3000);
}

// Training locations — structured data with Google Places
var pendingPlace = null; // Holds the last Google Places Autocomplete selection

function addLocation() {
    const input = document.getElementById('locationInput');
    const rawValue = input.value.trim();
    if (!rawValue) {
        showToast('Please enter a location', 'error');
        return;
    }
    
    const list = document.getElementById('locationsList');
    
    // Build structured location object
    var locObj;
    if (pendingPlace && pendingPlace.name) {
        locObj = {
            name: pendingPlace.name || rawValue,
            address: pendingPlace.formatted_address || rawValue,
            lat: pendingPlace.geometry ? pendingPlace.geometry.location.lat() : null,
            lng: pendingPlace.geometry ? pendingPlace.geometry.location.lng() : null,
            place_id: pendingPlace.place_id || ''
        };
    } else {
        // Manual entry (no autocomplete match)
        locObj = { name: rawValue, address: rawValue, lat: null, lng: null, place_id: '' };
    }
    
    // Check duplicates by name
    const existing = list.querySelectorAll('input[name="training_locations_data[]"]');
    for (let i = 0; i < existing.length; i++) {
        try {
            var ex = JSON.parse(existing[i].value);
            if (ex.name && ex.name.toLowerCase() === locObj.name.toLowerCase()) {
                showToast('Location already added', 'error');
                return;
            }
        } catch(e) {}
    }
    
    const item = document.createElement('div');
    item.className = 'ptp-location-item';
    var addrHtml = (locObj.address && locObj.address !== locObj.name)
        ? '<span class="ptp-location-addr">' + escapeHtml(locObj.address) + '</span>' : '';
    item.innerHTML = 
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2">' +
        '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>' +
        '<div class="ptp-location-info"><span class="ptp-location-name">' + escapeHtml(locObj.name) + '</span>' + addrHtml + '</div>' +
        '<input type="hidden" name="training_locations_data[]" value="' + escapeHtml(JSON.stringify(locObj)) + '">' +
        '<button type="button" class="ptp-location-remove" onclick="removeLocation(this)">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
        '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
    list.appendChild(item);
    input.value = '';
    pendingPlace = null;
    showToast('Location added!', 'success');
    
    // Update map
    if (locObj.lat && locObj.lng) {
        addMarkerDirect(locObj.lat, locObj.lng, locObj.name);
    } else if (locObj.address) {
        geocodeAndAddMarker(locObj.address);
    }
}

function removeLocation(btn) {
    btn.closest('.ptp-location-item').remove();
    showToast('Location removed');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// v216.3: Mentorship toggle
(function() {
    var cb = document.getElementById('mentorshipEnabled');
    var settings = document.getElementById('mentorshipSettings');
    var slider = document.getElementById('mentorshipSlider');
    var dot = document.getElementById('mentorshipDot');
    var statusText = document.getElementById('mentorshipStatusText');
    if (cb) {
        cb.addEventListener('change', function() {
            var on = this.checked;
            settings.style.display = on ? '' : 'none';
            slider.style.background = on ? '#FCB900' : '#D4D4D4';
            dot.style.left = on ? '24px' : '3px';
            statusText.textContent = on ? 'Mentorship Active' : 'Enable Mentorship';
        });
    }
})();

// Stripe Connect
function connectStripe(e) {
    e.preventDefault();
    const btn = document.getElementById('stripeConnectBtn') || e.target.closest('.ptp-stripe-btn') || e.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="ptp-loading"></span> Connecting to Stripe...';
    btn.style.pointerEvents = 'none';
    btn.style.opacity = '0.7';
    
    const formData = new FormData();
    formData.append('action', 'ptp_stripe_connect_start');
    formData.append('nonce', '<?php echo wp_create_nonce('ptp_nonce'); ?>');
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => {
        if (!r.ok) throw new Error('Server error (' + r.status + ')');
        return r.json();
    })
    .then(data => {
        if (data.success && (data.data.connect_url || data.data.url)) {
            btn.innerHTML = '<span class="ptp-loading"></span> Redirecting to Stripe...';
            window.location.href = data.data.connect_url || data.data.url;
        } else {
            var msg = (data.data && data.data.message) ? data.data.message : 'Could not connect to Stripe. Please try again.';
            showToast(msg, 'error');
            btn.innerHTML = originalText;
            btn.style.pointerEvents = 'auto';
            btn.style.opacity = '1';
        }
    })
    .catch(err => {
        showToast('Connection error — check your internet and try again. If this keeps happening, text Luke.', 'error');
        btn.innerHTML = originalText;
        btn.style.pointerEvents = 'auto';
        btn.style.opacity = '1';
    });
}

// Form submit
document.getElementById('onboardingForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // v193: Comprehensive field validation
    var errors = [];
    
    // 1. Name
    var fn = (document.querySelector('input[name="first_name"]') || {}).value || '';
    var ln = (document.querySelector('input[name="last_name"]') || {}).value || '';
    if (!fn.trim() || !ln.trim()) {
        errors.push({ msg: 'First and last name are required.', section: 'bio' });
    }
    
    // 2. Bio (min 50 chars)
    var bio = (document.querySelector('textarea[name="bio"]') || {}).value || '';
    if (bio.trim().length < 50) {
        errors.push({ msg: 'Bio needs to be at least 50 characters. Tell parents what makes you different!', section: 'bio' });
    }
    
    // 3. Playing level
    var level = (document.querySelector('select[name="playing_level"]') || {}).value || '';
    if (!level) {
        errors.push({ msg: 'Select your highest level played.', section: 'experience' });
    }
    
    // 4. Hourly rate
    var rate = parseFloat((document.querySelector('input[name="hourly_rate"]') || {}).value) || 0;
    if (rate < 25 || rate > 300) {
        errors.push({ msg: 'Set an hourly rate between $25 - $300.', section: 'pricing' });
    }
    
    // 5. City + State
    var city = (document.querySelector('input[name="city"]') || {}).value || '';
    var state = (document.querySelector('select[name="state"]') || {}).value || '';
    if (!city.trim()) {
        errors.push({ msg: 'City is required so parents can find you.', section: 'pricing' });
    }
    if (!state) {
        errors.push({ msg: 'Select your state.', section: 'pricing' });
    }
    
    // 6. Training locations (at least 1 required)
    var locationInputs = document.querySelectorAll('input[name="training_locations_data[]"]');
    var locationCount = 0;
    locationInputs.forEach(function(inp) { if (inp.value.trim()) locationCount++; });
    if (locationCount === 0) {
        errors.push({ msg: 'Add at least 1 training location (park, field, or facility where you can train).', section: 'locations' });
    }
    
    // 7. Availability (at least 1 day enabled)
    var availDays = document.querySelectorAll('input[name^="availability"][name$="[enabled]"]');
    var hasAvailability = false;
    availDays.forEach(function(inp) { if (inp.value === '1') hasAvailability = true; });
    if (!hasAvailability) {
        errors.push({ msg: 'Turn on at least 1 day of availability so parents can book you.', section: 'availability' });
    }
    
    // 8. Contract
    var contractCheckbox = document.getElementById('agreeContract');
    if (contractCheckbox && !contractCheckbox.checked) {
        errors.push({ msg: 'Please agree to the Trainer Agreement.', section: 'contract' });
    }
    
    // Show first error
    if (errors.length > 0) {
        var first = errors[0];
        showToast(first.msg, 'error');
        var section = document.querySelector('[data-section="' + first.section + '"]');
        if (section) {
            // Close others, open this one
            document.querySelectorAll('.ptp-section.open').forEach(function(s) { s.classList.remove('open'); });
            section.classList.add('open');
            setTimeout(function() {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }
        return;
    }
    
    // Show all-fields-complete count if multiple errors (happens on re-check)
    
    const btn = document.getElementById('submitBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="ptp-loading"></span> Saving...';
    
    const formData = new FormData(this);
    
    // v190: Build display_name from first_name + last_name
    const firstName = formData.get('first_name') || '';
    const lastName = formData.get('last_name') || '';
    if (firstName || lastName) {
        formData.set('display_name', (firstName + ' ' + lastName).trim());
    }
    
    // v190: Serialize availability into JSON for handler
    const availabilityData = [];
    const dayMap = {'monday':1,'tuesday':2,'wednesday':3,'thursday':4,'friday':5,'saturday':6,'sunday':0};
    Object.keys(dayMap).forEach(function(day) {
        const enabled = formData.get('availability[' + day + '][enabled]');
        if (enabled === '1') {
            const start = formData.get('availability[' + day + '][start]') || '09:00';
            const end = formData.get('availability[' + day + '][end]') || '17:00';
            availabilityData.push({ day: dayMap[day], start: start, end: end });
        }
    });
    if (availabilityData.length > 0) {
        formData.set('availability_json', JSON.stringify(availabilityData));
    }
    
    // v200.4: Serialize structured training locations into JSON
    const locationInputs = document.querySelectorAll('input[name="training_locations_data[]"]');
    const locations = [];
    locationInputs.forEach(function(inp) {
        if (inp.value) {
            try { locations.push(JSON.parse(inp.value)); } catch(e) { locations.push({name: inp.value, address: inp.value}); }
        }
    });
    if (locations.length > 0) {
        formData.set('training_locations_json', JSON.stringify(locations));
    }
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Profile saved!', 'success');
            setTimeout(() => {
                window.location.href = '<?php echo $is_edit ? home_url('/trainer-dashboard/') : home_url('/trainer-dashboard/?welcome=1'); ?>';
            }, 500);
        } else {
            showToast(data.data.message || 'Error saving profile', 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(() => {
        showToast('Connection error. Please try again.', 'error');
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
});

// Auto-open first incomplete section
document.addEventListener('DOMContentLoaded', function() {
    const incomplete = document.querySelector('.ptp-section:not(.complete)');
    if (incomplete && !incomplete.classList.contains('open')) {
        // First section is already open, so find next incomplete
        const firstOpen = document.querySelector('.ptp-section.open');
        if (firstOpen && firstOpen.classList.contains('complete') && incomplete) {
            firstOpen.classList.remove('open');
            incomplete.classList.add('open');
        }
    }
});

// Google Maps with Places Autocomplete (if key exists)
<?php if ($google_maps_key): ?>
var map = null;
var markers = [];
var mapBounds = null;

function initLocationsMap() {
    if (typeof google === 'undefined' || !google.maps) return;
    
    const mapElement = document.getElementById('locationsMap');
    if (!mapElement) return;
    
    map = new google.maps.Map(mapElement, {
        center: { lat: 39.95, lng: -75.16 },
        zoom: 10,
        styles: [
            { featureType: 'poi', stylers: [{ visibility: 'off' }] }
        ],
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false
    });
    mapBounds = new google.maps.LatLngBounds();
    
    // Init Google Places Autocomplete on location input
    const input = document.getElementById('locationInput');
    const autocomplete = new google.maps.places.Autocomplete(input, {
        types: ['establishment', 'park', 'geocode'],
        componentRestrictions: { country: 'us' },
        fields: ['name', 'formatted_address', 'geometry', 'place_id']
    });
    
    // Prevent form submit on Enter when autocomplete is open
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            // Small delay to let autocomplete fire first
            setTimeout(function() { addLocation(); }, 150);
        }
    });
    
    autocomplete.addListener('place_changed', function() {
        const place = autocomplete.getPlace();
        if (place && place.geometry) {
            pendingPlace = place;
            // Auto-add when a place is selected from the dropdown
            addLocation();
        }
    });
    
    // Add existing location markers from structured data
    <?php foreach ($training_locations as $loc): 
        $lat = is_array($loc) ? ($loc['lat'] ?? null) : null;
        $lng = is_array($loc) ? ($loc['lng'] ?? null) : null;
        $addr = is_array($loc) ? ($loc['address'] ?? ($loc['name'] ?? '')) : $loc;
        $name = is_array($loc) ? ($loc['name'] ?? '') : $loc;
    ?>
    <?php if ($lat && $lng): ?>
    addMarkerDirect(<?php echo floatval($lat); ?>, <?php echo floatval($lng); ?>, '<?php echo esc_js($name); ?>');
    <?php else: ?>
    geocodeAndAddMarker('<?php echo esc_js($addr); ?>');
    <?php endif; ?>
    <?php endforeach; ?>
}

function addMarkerDirect(lat, lng, title) {
    if (!map) return;
    var pos = new google.maps.LatLng(lat, lng);
    var marker = new google.maps.Marker({
        position: pos,
        map: map,
        title: title || '',
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 10,
            fillColor: '#FCB900',
            fillOpacity: 1,
            strokeColor: '#0A0A0A',
            strokeWeight: 2
        }
    });
    markers.push(marker);
    mapBounds.extend(pos);
    if (markers.length === 1) {
        map.setCenter(pos);
        map.setZoom(13);
    } else {
        map.fitBounds(mapBounds, 40);
    }
}

function geocodeAndAddMarker(address) {
    if (!map) return;
    const geocoder = new google.maps.Geocoder();
    geocoder.geocode({ address: address + ', USA' }, function(results, status) {
        if (status === 'OK' && results[0]) {
            addMarkerDirect(
                results[0].geometry.location.lat(),
                results[0].geometry.location.lng(),
                address
            );
        }
    });
}

if (typeof google !== 'undefined' && google.maps) {
    initLocationsMap();
} else {
    window.addEventListener('load', () => setTimeout(initLocationsMap, 500));
}
<?php else: ?>
// No Google Maps key — Enter key for location input
document.getElementById('locationInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        addLocation();
    }
});
<?php endif; ?>

// v216.1: Interactive earnings calculator — updates when rate field changes
(function() {
    var rateInput = document.getElementById('ptp-ob-rate');
    if (!rateInput) return;
    
    function updateEarnings() {
        var rate = parseFloat(rateInput.value) || 75;
        var t1 = document.getElementById('earn-tier1');
        var t2 = document.getElementById('earn-tier2');
        var t3 = document.getElementById('earn-tier3');
        if (t1) t1.textContent = '$' + (rate * 0.50).toFixed(2);
        if (t2) t2.textContent = '$' + (rate * 0.75).toFixed(2);
        if (t3) t3.textContent = '$' + (rate * 0.85).toFixed(2);
    }
    
    rateInput.addEventListener('input', updateEarnings);
    rateInput.addEventListener('change', updateEarnings);
    updateEarnings(); // Initial calc on page load
})();

// v191: Detect return from Stripe Connect
if (new URLSearchParams(window.location.search).get('stripe_connected') === '1') {
    showToast('Stripe connected successfully! Payment setup complete.', 'success');
    // Update the Stripe section UI
    const stripeSection = document.querySelector('[data-section="payments"]');
    if (stripeSection) {
        stripeSection.classList.add('complete');
        const btn = document.getElementById('stripeConnectBtn');
        if (btn) {
            btn.style.background = '#22C55E';
            btn.innerHTML = 'Connected';
            btn.style.pointerEvents = 'none';
        }
    }
    // Clean URL
    const url = new URL(window.location);
    url.searchParams.delete('stripe_connected');
    window.history.replaceState({}, '', url);
}
</script>

<?php wp_footer(); ?>
</body>
</html>
