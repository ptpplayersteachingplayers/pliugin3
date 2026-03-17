<?php
/**
 * Mentorship Signup — Session Package Model
 * 
 * Flow: Parent sees packages → fills interest form → free intro call → then pays
 * URL: /mentorship-signup/?trainer_id=X or /mentorship-signup/?trainer_id=X&package=development
 */

if (!defined('ABSPATH')) exit;

global $wpdb;
$trainer_id = intval($_GET['trainer_id'] ?? 0);
$selected_pkg = sanitize_text_field($_GET['package'] ?? 'development');

if (!$trainer_id) {
    echo '<div style="text-align:center;padding:80px 20px;font-family:Inter,sans-serif"><h2 style="font-family:Oswald,sans-serif;text-transform:uppercase">Find Your Mentor</h2><p style="color:#737373;margin:12px 0 24px">Browse coaches your kid already knows from camp.</p><a href="/find-trainers/?mentorship=1" style="background:#FCB900;color:#0A0A0A;padding:14px 28px;border-radius:10px;font-weight:700;text-decoration:none;font-family:Oswald,sans-serif;text-transform:uppercase">BROWSE COACHES</a></div>';
    return;
}

$trainer = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'", $trainer_id
));
if (!$trainer) {
    echo '<div style="text-align:center;padding:80px 20px"><p>Trainer not found.</p></div>';
    return;
}

if (empty($trainer->mentorship_enabled)) {
    $first = explode(' ', $trainer->display_name)[0];
    echo '<div style="text-align:center;padding:80px 20px;font-family:Inter,sans-serif;max-width:480px;margin:0 auto">';
    echo '<h2 style="font-family:Oswald,sans-serif;text-transform:uppercase;margin-bottom:12px">Mentorship Not Available Yet</h2>';
    echo '<p style="color:#737373;margin:12px 0 24px;line-height:1.6">' . esc_html($first) . ' hasn\'t opened mentorship spots yet. In the meantime, you can book a 1-on-1 training session.</p>';
    echo '<a href="' . esc_url(home_url('/find-trainers/' . ($trainer->slug ?? '') . '/')) . '" style="background:#FCB900;color:#0A0A0A;padding:14px 28px;border-radius:10px;font-weight:700;text-decoration:none;font-family:Oswald,sans-serif;text-transform:uppercase;display:inline-block">BOOK A SESSION WITH ' . esc_html(strtoupper($first)) . '</a>';
    echo '</div>';
    return;
}

$packages = PTP_Mentorship::PACKAGES;
if (!isset($packages[$selected_pkg])) $selected_pkg = 'development';
$available_pkgs = explode(',', $trainer->mentorship_packages ?? 'single,kickstart,development,elite');
$source = sanitize_text_field($_GET['source'] ?? 'direct');
$camp_order_id = intval($_GET['camp_order_id'] ?? 0);

$current_user = wp_get_current_user();
$logged_in = is_user_logged_in();
$parent_name = $logged_in ? $current_user->display_name : '';
$parent_email = $logged_in ? $current_user->user_email : '';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Mentorship with <?php echo esc_attr($trainer->display_name); ?> — PTP</title>
<meta name="robots" content="noindex, nofollow">
<?php wp_head(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
footer,.site-footer,#footer,.elementor-location-footer,.footer-wrapper,
.elementor-location-header,header.elementor-element,#masthead,
.site-header,.theme-header,[data-elementor-type="header"],
header:not(.ms-nav){display:none!important}
:root{--gold:#FCB900;--black:#0A0A0A;--white:#FFF;--surface:#F8F8F6;--border:#EAEAE6;--muted:#737373}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;background:var(--surface);color:var(--black);-webkit-font-smoothing:antialiased}
.ms-wrap{max-width:640px;margin:0 auto;padding:24px 16px 60px;font-family:'Inter',system-ui,sans-serif;color:var(--black)}
.ms-head{text-align:center;margin-bottom:32px}
.ms-trainer-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--gold);margin-bottom:12px}
.ms-h1{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px}
.ms-sub{font-size:14px;color:var(--muted);margin-top:6px;line-height:1.5}
.ms-packages{display:flex;gap:8px;margin:24px 0}
.ms-pkg{flex:1;padding:16px 10px;border:2px solid var(--border);border-radius:12px;text-align:center;cursor:pointer;transition:all 0.2s;position:relative}
.ms-pkg.selected{border-color:var(--gold);background:rgba(252,185,0,0.05)}
.ms-pkg-pop{position:absolute;top:-8px;left:50%;transform:translateX(-50%);font-size:9px;background:var(--gold);color:var(--black);padding:2px 8px;border-radius:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px}
.ms-pkg-name{font-family:'Oswald',sans-serif;font-size:13px;text-transform:uppercase;font-weight:700}
.ms-pkg-price{font-size:24px;font-weight:800;margin:4px 0 2px}
.ms-pkg-price span{font-size:11px;font-weight:400;color:var(--muted)}
.ms-pkg-meta{font-size:11px;color:var(--muted);line-height:1.4}
.ms-pkg-total{font-size:10px;color:var(--gold);font-weight:600;margin-top:6px}
.ms-detail{background:var(--surface);border-radius:12px;padding:20px;margin:0 0 24px}
.ms-detail h3{font-family:'Oswald',sans-serif;font-size:15px;text-transform:uppercase;margin-bottom:10px}
.ms-detail-list{list-style:none;padding:0}
.ms-detail-list li{font-size:13px;color:#525252;padding:4px 0 4px 20px;position:relative;line-height:1.4}
.ms-detail-list li::before{content:'';position:absolute;left:0;top:10px;width:8px;height:8px;background:var(--gold);border-radius:50%}
.ms-how{background:var(--surface);border-radius:12px;padding:20px;margin:0 0 24px}
.ms-how h3{font-family:'Oswald',sans-serif;font-size:15px;text-transform:uppercase;margin-bottom:12px}
.ms-step{display:flex;gap:12px;align-items:flex-start;margin-bottom:14px}
.ms-step:last-child{margin-bottom:0}
.ms-step-num{width:28px;height:28px;flex-shrink:0;background:var(--gold);color:var(--black);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-weight:700;font-size:13px}
.ms-step-text{font-size:13px;line-height:1.5;color:#525252}
.ms-step-text strong{color:var(--black)}
.ms-form{margin-top:0}
.ms-form-title{font-family:'Oswald',sans-serif;font-size:17px;text-transform:uppercase;margin-bottom:16px;text-align:center}
.ms-field{margin-bottom:14px}
.ms-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);margin-bottom:4px}
.ms-field input,.ms-field textarea,.ms-field select{width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:10px;font-size:16px;font-family:inherit;transition:border-color 0.2s;background:var(--white)}
.ms-field input:focus,.ms-field textarea:focus,.ms-field select:focus{outline:none;border-color:var(--gold)}
.ms-field textarea{resize:vertical;min-height:80px}
.ms-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.ms-btn{width:100%;padding:16px;background:var(--gold);color:var(--black);font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:none;border-radius:10px;cursor:pointer;transition:all 0.2s;margin-top:8px}
.ms-btn:hover{background:#E5A800;transform:translateY(-1px)}
.ms-btn:disabled{opacity:0.6;cursor:not-allowed;transform:none}
.ms-trust{text-align:center;margin-top:16px;font-size:12px;color:var(--muted)}
.ms-success{text-align:center;padding:40px 20px}
.ms-success h2{font-family:'Oswald',sans-serif;font-size:24px;text-transform:uppercase;margin-bottom:12px}
.ms-success p{font-size:15px;color:var(--muted);line-height:1.6;max-width:400px;margin:0 auto}
.ms-success .ms-check{width:64px;height:64px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
.ms-no-pay{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:10px;padding:12px 16px;text-align:center;margin-bottom:16px;font-size:13px;color:#166534}
/* Nav bar */
.ms-nav{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--black);position:sticky;top:0;z-index:100}
.ms-nav-logo{font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;color:var(--gold);text-decoration:none;letter-spacing:1px}
.ms-nav-back{color:#fff;font-size:13px;text-decoration:none;opacity:.7;display:flex;align-items:center;gap:6px}
.ms-nav-back svg{width:16px;height:16px}
@media(max-width:480px){.ms-packages{flex-direction:column}.ms-row{grid-template-columns:1fr}}
/* Nav */
.ms-nav{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--black);position:sticky;top:0;z-index:100}
.ms-nav-logo{font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;color:var(--gold);text-decoration:none;letter-spacing:1px}
.ms-nav-back{color:#fff;font-size:13px;text-decoration:none;opacity:.7;display:flex;align-items:center;gap:6px}
.ms-nav-back svg{width:16px;height:16px}
.ms-earn{background:rgba(252,185,0,0.08);border:1px solid rgba(252,185,0,0.2);border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;margin-bottom:16px}
.ms-earn-icon{width:32px;height:32px;border-radius:50%;background:var(--gold);color:var(--black);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-family:'Oswald',sans-serif;font-weight:700;font-size:14px}
.ms-earn-text{font-size:12px;color:#525252;line-height:1.5}
.ms-earn-text strong{color:var(--black)}
</style>
</head>
<body>

<nav class="ms-nav">
    <?php if (!empty($trainer->slug)): ?>
    <a href="<?php echo esc_url(home_url('/trainer/' . $trainer->slug . '/')); ?>" class="ms-nav-back">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 19l-7-7 7-7"/></svg>
        Back
    </a>
    <?php else: ?>
    <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="ms-nav-back">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 19l-7-7 7-7"/></svg>
        Back
    </a>
    <?php endif; ?>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="ms-nav-logo">PTP</a>
</nav>

<div class="ms-wrap" id="signupWrap">
    <!-- HEADER -->
    <div class="ms-head">
        <?php if (!empty($trainer->photo_url)): ?>
        <img src="<?php echo esc_url($trainer->photo_url); ?>" alt="<?php echo esc_attr($trainer->display_name); ?>" class="ms-trainer-photo">
        <?php endif; ?>
        <h1 class="ms-h1">Mentorship with <?php echo esc_html($trainer->display_name); ?></h1>
        <p class="ms-sub"><?php echo esc_html($trainer->mentorship_bio ?: $trainer->bio ?: 'Year-round mentorship with your camp coach.'); ?></p>
    </div>

    <!-- PACKAGES -->
    <div class="ms-packages">
        <?php foreach ($packages as $key => $pkg): if (!in_array($key, $available_pkgs)) continue; ?>
        <div class="ms-pkg <?php echo $key === $selected_pkg ? 'selected' : ''; ?>"
             onclick="selectPkg('<?php echo $key; ?>')" data-pkg="<?php echo $key; ?>">
            <?php if ($key === 'development'): ?><div class="ms-pkg-pop">Most Popular</div><?php elseif ($key === 'single'): ?><div class="ms-pkg-pop" style="background:#3B82F6;color:#fff">Try It</div><?php endif; ?>
            <div class="ms-pkg-name"><?php echo esc_html($pkg['name']); ?></div>
            <div class="ms-pkg-price">$<?php echo $pkg['per_session_display']; ?><span>/session</span></div>
            <div class="ms-pkg-meta"><?php echo $pkg['sessions']; ?> session<?php echo $pkg['sessions'] > 1 ? 's' : ''; ?> &middot; <?php echo $pkg['session_length']; ?> min</div>
            <div class="ms-pkg-total">$<?php echo $pkg['total_display']; ?> total<?php echo ($pkg['billing'] ?? 'weekly') !== 'one-time' ? ' &middot; billed weekly' : ''; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- PACKAGE DETAIL -->
    <div class="ms-detail" id="pkgDetail">
        <h3 id="pkgDetailTitle">What's Included</h3>
        <ul class="ms-detail-list" id="pkgDetailList"></ul>
    </div>

    <!-- HOW IT WORKS -->
    <div class="ms-how">
        <h3>How It Works</h3>
        <div class="ms-step">
            <div class="ms-step-num">1</div>
            <div class="ms-step-text"><strong>Tell us about your kid</strong> — Fill out the form below. Takes 60 seconds.</div>
        </div>
        <div class="ms-step">
            <div class="ms-step-num">2</div>
            <div class="ms-step-text"><strong>Free intro call</strong> — <?php echo esc_html($trainer->display_name); ?> reaches out to schedule a 15-min video call. Meet, vibe check, make a plan. No commitment.</div>
        </div>
        <div class="ms-step">
            <div class="ms-step-num">3</div>
            <div class="ms-step-text"><strong>Start your sessions</strong> — If it's a fit, pick a package or try a single session first. Weekly billing for packages. Cancel anytime.</div>
        </div>
    </div>

    <!-- INTEREST FORM -->
    <div class="ms-form" id="interestForm">
        <div class="ms-no-pay">No payment needed right now. Start with a free intro call.</div>
        <div class="ms-form-title">Get Started</div>

        <!-- honeypot -->
        <div style="position:absolute;left:-9999px"><input type="text" name="website_url" id="hpField" tabindex="-1" autocomplete="off"></div>

        <div class="ms-field">
            <label>Parent Name</label>
            <input type="text" id="parentName" value="<?php echo esc_attr($parent_name); ?>" placeholder="Your name" required>
        </div>
        <div class="ms-field">
            <label>Email</label>
            <input type="email" id="parentEmail" value="<?php echo esc_attr($parent_email); ?>" placeholder="your@email.com" required>
        </div>
        <div class="ms-row">
            <div class="ms-field">
                <label>Player Name</label>
                <input type="text" id="playerName" placeholder="Your kid's first name" required>
            </div>
            <div class="ms-field">
                <label>Age</label>
                <input type="number" id="playerAge" placeholder="10" min="5" max="18">
            </div>
        </div>
        <div class="ms-field">
            <label>Position (optional)</label>
            <input type="text" id="playerPosition" placeholder="e.g. Center Mid, Striker, GK">
        </div>
        <div class="ms-field">
            <label>What do you want your kid to get out of this?</label>
            <textarea id="playerGoals" placeholder="More confidence, better ball control, help preparing for tryouts..."><?php echo ($source === 'camp_upsell' || $source === 'camp_followup') ? 'Continue working with coach after camp' : ''; ?></textarea>
        </div>

        <!-- v235.6: Safety guardrails -->
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:16px">
            <div style="font-size:12px;font-weight:700;color:#166534;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.3px">Parent Safety Commitment</div>
            <label style="display:flex;gap:10px;cursor:pointer;align-items:flex-start;margin-bottom:8px">
                <input type="checkbox" id="consentParticipate" style="margin-top:3px;width:18px;height:18px;flex-shrink:0;accent-color:#22C55E">
                <span style="font-size:13px;color:#374151;line-height:1.5">I am the parent/legal guardian and I consent to my child participating in online video mentorship sessions with this PTP coach.</span>
            </label>
            <label style="display:flex;gap:10px;cursor:pointer;align-items:flex-start;margin-bottom:8px">
                <input type="checkbox" id="consentSupervise" style="margin-top:3px;width:18px;height:18px;flex-shrink:0;accent-color:#22C55E">
                <span style="font-size:13px;color:#374151;line-height:1.5">I understand that all sessions will be recorded and I will receive a recording after each session. I agree to be present or available during sessions for players under 13.</span>
            </label>
            <label style="display:flex;gap:10px;cursor:pointer;align-items:flex-start">
                <input type="checkbox" id="consentTerms" style="margin-top:3px;width:18px;height:18px;flex-shrink:0;accent-color:#22C55E">
                <span style="font-size:13px;color:#374151;line-height:1.5">I agree to PTP's <a href="/terms-of-service/" target="_blank" style="color:#22C55E;font-weight:600">Terms of Service</a> and <a href="/code-of-conduct/" target="_blank" style="color:#22C55E;font-weight:600">Code of Conduct</a>.</span>
            </label>
        </div>

        <button class="ms-btn" id="submitBtn" onclick="submitInterest()">REQUEST FREE INTRO CALL</button>
        <p class="ms-trust">No payment required. No auto-billing. Your kid meets their coach first.</p>
        <p style="text-align:center;font-size:11px;color:var(--muted);margin-top:8px;line-height:1.5">All sessions are recorded for safety. Coaches are background-checked PTP-verified athletes. Parents receive session summaries and recordings.</p>
    </div>

    <!-- SUCCESS STATE -->
    <div class="ms-success" id="successState" style="display:none">
        <div class="ms-check">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#0A0A0A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <h2>You're In!</h2>
        <p id="successMsg"></p>
    </div>
</div>

<script>
const PACKAGES = <?php echo json_encode($packages); ?>;
const TRAINER_ID = <?php echo $trainer_id; ?>;
const AJAX_URL = '<?php echo admin_url("admin-ajax.php"); ?>';
const SOURCE = '<?php echo esc_js($source); ?>';
const CAMP_ORDER = <?php echo $camp_order_id; ?>;
let selectedPkg = '<?php echo esc_js($selected_pkg); ?>';

function selectPkg(key) {
    selectedPkg = key;
    document.querySelectorAll('.ms-pkg').forEach(p => {
        p.classList.toggle('selected', p.dataset.pkg === key);
    });
    renderDetail(key);
}

function renderDetail(key) {
    const pkg = PACKAGES[key];
    if (!pkg) return;
    document.getElementById('pkgDetailTitle').textContent = pkg.name + ' — What\'s Included';
    const list = document.getElementById('pkgDetailList');
    list.innerHTML = '';
    (pkg.best_for || []).forEach(item => {
        const li = document.createElement('li');
        li.textContent = item;
        list.appendChild(li);
    });
    // Add items based on package type
    if (key === 'single') {
        ['30-min video call with your coach', 'Personalized feedback & next steps', 'No commitment — book more if you want'].forEach(item => {
            const li = document.createElement('li');
            li.textContent = item;
            list.appendChild(li);
        });
    } else {
        ['Weekly calls on your schedule', 'Goal setting & progress tracking', 'Challenges from your coach', 'Video review available as add-on'].forEach(item => {
            const li = document.createElement('li');
            li.textContent = item;
            list.appendChild(li);
        });
    }
}

async function submitInterest() {
    const btn = document.getElementById('submitBtn');
    const name = document.getElementById('parentName').value.trim();
    const email = document.getElementById('parentEmail').value.trim();
    const player = document.getElementById('playerName').value.trim();
    const age = document.getElementById('playerAge').value;
    const position = document.getElementById('playerPosition').value.trim();
    const goals = document.getElementById('playerGoals').value.trim();
    const hp = document.getElementById('hpField').value;

    if (!name || !email || !player) {
        alert('Please fill out your name, email, and player name.');
        return;
    }
    
    // v235.6: Age guardrails
    const ageNum = parseInt(age);
    if (!age || isNaN(ageNum) || ageNum < 6 || ageNum > 18) {
        alert('Mentorship is available for players ages 6-18. Please enter your player\'s age.');
        document.getElementById('playerAge').focus();
        return;
    }
    
    // v235.6: Parent consent validation
    const c1 = document.getElementById('consentParticipate').checked;
    const c2 = document.getElementById('consentSupervise').checked;
    const c3 = document.getElementById('consentTerms').checked;
    if (!c1 || !c2 || !c3) {
        alert('Please review and check all three parent safety consent boxes before continuing.');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'SUBMITTING...';

    const fd = new FormData();
    fd.append('action', 'ptp_mentorship_interest');
    fd.append('trainer_id', TRAINER_ID);
    fd.append('package', selectedPkg);
    fd.append('parent_name', name);
    fd.append('parent_email', email);
    fd.append('player_name', player);
    fd.append('player_age', age);
    fd.append('player_position', position);
    fd.append('goals', goals);
    fd.append('source', SOURCE);
    fd.append('camp_order_id', CAMP_ORDER);
    fd.append('website_url', hp);
    fd.append('parent_consent', '1');
    fd.append('consent_supervise', ageNum < 13 ? '1' : '0');

    try {
        const res = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            document.getElementById('interestForm').style.display = 'none';
            document.getElementById('successState').style.display = 'block';
            document.getElementById('successMsg').textContent =
                data.data.message || (data.data.trainer_name + ' will reach out to schedule your free intro call. No commitment until you\'re ready.');
        } else {
            alert(data.data || 'Something went wrong. Please try again.');
            btn.disabled = false;
            btn.textContent = 'REQUEST FREE INTRO CALL';
        }
    } catch (e) {
        alert('Connection error. Please try again.');
        btn.disabled = false;
        btn.textContent = 'REQUEST FREE INTRO CALL';
    }
}

// Init
renderDetail(selectedPkg);
</script>

<?php wp_footer(); ?>
</body>
</html>
