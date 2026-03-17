<?php
/**
 * Register Template - PTP v221
 * Masterclass-style dark immersive auth
 */
defined('ABSPATH') || exit;

// Strip theme CSS while keeping WP infrastructure
include_once PTP_PLUGIN_DIR . 'templates/auth-page-setup.php';

if (is_user_logged_in()) {
    $user = wp_get_current_user();
    $r = home_url('/parent-dashboard/');
    if (class_exists('PTP_Trainer')) { $t = PTP_Trainer::get_by_user_id($user->ID); if ($t) $r = home_url('/trainer-dashboard/'); }
    wp_safe_redirect($r); exit;
}

$logo_url = get_option('ptp_email_logo_url', '');
$cid = get_theme_mod('custom_logo'); if ($cid) $logo_url = wp_get_attachment_image_url($cid, 'medium');
$has_google = class_exists('PTP_Google_Web_Login') && PTP_Google_Web_Login::is_configured();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#0A0A0A">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Create Account - PTP Training</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <?php wp_head(); ?>
    <style>
    <?php include PTP_PLUGIN_DIR . 'templates/auth-shared-styles.php'; ?>

    /* v221: Nuclear overrides */
    html, body { margin: 0 !important; padding: 0 !important; background: #111113 !important;
        overflow-x: hidden !important; min-height: 100vh !important; }
    html { margin-top: 0 !important; }
    #wpadminbar { display: none !important; }
    .elementor-location-header, .elementor-location-footer,
    header, footer, nav.main-navigation, .site-header, .site-footer,
    .ekit-template-content-header, .ekit-template-content-footer,
    .sticky-header, .announcement-bar, #masthead, #colophon,
    [data-elementor-type="header"], [data-elementor-type="footer"],
    .ptp-floating-chatbot, .ptp-chatbot-widget { display: none !important; }
    .site-content, .page-content, .entry-content, main,
    .elementor, .elementor-inner, article, .site-main, .content-area, #content, #primary {
        padding: 0 !important; margin: 0 !important; max-width: none !important;
        width: 100% !important; background: transparent !important; }
    </style>
</head>
<body style="margin:0;padding:0;background:#111113;">
<div class="ptp-auth">
    <div class="ptp-auth-brand">
        <div class="ptp-auth-brand-inner">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="ptp-auth-brand-logo"><img src="<?php echo esc_url($logo_url); ?>" alt="PTP"></a>
            <h1 class="ptp-auth-brand-title">JOIN THE<br><span>PTP FAMILY</span></h1>
            <p class="ptp-auth-brand-sub">Create your account to book camps, schedule private training, and give your player the edge they deserve.</p>
            <div class="ptp-auth-brand-stats">
                <div class="ptp-auth-stat"><span class="ptp-auth-stat-num">D1</span><span class="ptp-auth-stat-label">COACHES</span></div>
                <div class="ptp-auth-stat"><span class="ptp-auth-stat-num">0</span><span class="ptp-auth-stat-label">DRILLS</span></div>
                <div class="ptp-auth-stat"><span class="ptp-auth-stat-num">100%</span><span class="ptp-auth-stat-label">GAMEPLAY</span></div>
            </div>
            <div class="ptp-auth-brand-quote">
                <p>"The difference is night and day. My daughter went from sitting on the bench to starting. PTP coaches don't just instruct -- they play."</p>
                <cite>- Mike D., Bryn Mawr</cite>
            </div>
        </div>
    </div>

    <div class="ptp-auth-form-panel">
        <div class="ptp-auth-form-inner">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="ptp-auth-mobile-logo"><img src="<?php echo esc_url($logo_url); ?>" alt="PTP"></a>
            <div class="ptp-auth-form-header"><h2>CREATE ACCOUNT</h2><p>Set up your parent account in 30 seconds</p></div>

            <div class="ptp-auth-msg ptp-auth-msg-error" id="errorMsg" style="display:none"></div>
            <div class="ptp-auth-msg ptp-auth-msg-success" id="successMsg" style="display:none"></div>

            <?php if ($has_google): ?>
            <a href="<?php echo esc_url(home_url('/register/google/')); ?>" class="ptp-auth-google">
                <svg width="18" height="18" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                Sign up with Google
            </a>
            <div class="ptp-auth-divider"><span>or</span></div>
            <?php endif; ?>

            <form id="registerForm">
                <?php wp_nonce_field('ptp_nonce', 'ptp_nonce'); ?>
                <div class="ptp-auth-row">
                    <div class="ptp-auth-field"><label for="firstName">FIRST NAME</label><input type="text" id="firstName" placeholder="John" required autocomplete="given-name"></div>
                    <div class="ptp-auth-field"><label for="lastName">LAST NAME</label><input type="text" id="lastName" placeholder="Smith" required autocomplete="family-name"></div>
                </div>
                <div class="ptp-auth-field"><label for="email">EMAIL</label><input type="email" id="email" placeholder="you@email.com" required autocomplete="email" inputmode="email"></div>
                <div class="ptp-auth-field"><label for="phone">PHONE</label><input type="tel" id="phone" placeholder="(555) 123-4567" required autocomplete="tel" inputmode="tel"></div>
                <div class="ptp-auth-field">
                    <label for="password">PASSWORD</label>
                    <div class="ptp-auth-pass-wrap">
                        <input type="password" id="password" placeholder="Create a password" minlength="8" required autocomplete="new-password">
                        <button type="button" class="ptp-auth-pass-toggle" onclick="ptpTogglePass(this)" aria-label="Show password">
                            <svg class="eye-open" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-closed" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                    <div class="ptp-auth-hint">At least 8 characters</div>
                </div>
                <label class="ptp-auth-terms"><input type="checkbox" id="terms" required><span>I agree to the <a href="<?php echo esc_url(home_url('/terms/')); ?>" target="_blank">Terms</a> and <a href="<?php echo esc_url(home_url('/privacy/')); ?>" target="_blank">Privacy Policy</a></span></label>
                <button type="submit" class="ptp-auth-submit" id="submitBtn">CREATE ACCOUNT</button>
            </form>
            <div class="ptp-auth-footer-links">
                <p>Already have an account? <a href="<?php echo esc_url(home_url('/login/')); ?>">Sign in</a></p>
                <p class="ptp-auth-coach-link">Are you a coach? <a href="<?php echo esc_url(home_url('/apply/')); ?>">Apply here</a></p>
            </div>
        </div>
    </div>
</div>
<script>
function ptpTogglePass(btn){var i=btn.previousElementSibling,o=btn.querySelector('.eye-open'),c=btn.querySelector('.eye-closed');if(i.type==='password'){i.type='text';o.style.display='none';c.style.display='block'}else{i.type='password';o.style.display='block';c.style.display='none'}}
(function(){
    var form=document.getElementById('registerForm'),btn=document.getElementById('submitBtn'),err=document.getElementById('errorMsg'),ok=document.getElementById('successMsg');
    document.getElementById('phone').addEventListener('input',function(e){var x=e.target.value.replace(/\D/g,'').substring(0,10);if(x.length>6)e.target.value='('+x.substring(0,3)+') '+x.substring(3,6)+'-'+x.substring(6);else if(x.length>3)e.target.value='('+x.substring(0,3)+') '+x.substring(3);else if(x.length>0)e.target.value='('+x});
    form.addEventListener('submit',function(e){
        e.preventDefault();err.style.display='none';ok.style.display='none';
        document.querySelectorAll('.ptp-auth-field input').forEach(function(el){el.classList.remove('error')});
        var fn=document.getElementById('firstName').value.trim(),ln=document.getElementById('lastName').value.trim(),em=document.getElementById('email').value.trim(),ph=document.getElementById('phone').value.trim(),pw=document.getElementById('password').value,tm=document.getElementById('terms').checked;
        if(!fn||!ln){show('Please enter your full name');return}
        if(!em||!em.includes('@')){show('Please enter a valid email');document.getElementById('email').classList.add('error');return}
        if(!ph){show('Please enter your phone number');document.getElementById('phone').classList.add('error');return}
        if(pw.length<8){show('Password must be at least 8 characters');document.getElementById('password').classList.add('error');return}
        if(!tm){show('Please agree to the Terms and Privacy Policy');return}
        btn.disabled=true;btn.innerHTML='<span class="ptp-auth-spinner"></span>CREATING ACCOUNT...';
        var fd=new FormData();fd.append('action','ptp_register');fd.append('name',fn+' '+ln);fd.append('email',em);fd.append('phone',ph);fd.append('password',pw);fd.append('user_type','parent');fd.append('nonce',document.querySelector('[name="ptp_nonce"]').value);
        fetch('<?php echo admin_url("admin-ajax.php"); ?>',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){
            if(d.success){ok.textContent='Account created! Redirecting...';ok.style.display='flex';setTimeout(function(){window.location.href=d.data.redirect||'<?php echo home_url("/parent-dashboard/"); ?>'},1000)}
            else{show(d.data.message||'Registration failed.');btn.disabled=false;btn.textContent='CREATE ACCOUNT'}
        }).catch(function(){show('Connection error. Please try again.');btn.disabled=false;btn.textContent='CREATE ACCOUNT'});
        function show(m){err.textContent=m;err.style.display='flex'}
    });
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
