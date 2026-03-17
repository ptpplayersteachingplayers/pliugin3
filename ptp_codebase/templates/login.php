<?php
/**
 * Login Template - PTP v221
 * Full-page standalone with wp_head/wp_footer for WP infrastructure
 * Theme CSS stripped via auth-page-setup.php
 */
defined('ABSPATH') || exit;

// Strip theme CSS while keeping WP infrastructure
include_once PTP_PLUGIN_DIR . 'templates/auth-page-setup.php';

// Redirect if already logged in
if (is_user_logged_in()) {
    $user = wp_get_current_user();
    $redirect_url = home_url('/parent-dashboard/');
    if (class_exists('PTP_Trainer')) {
        $trainer = PTP_Trainer::get_by_user_id($user->ID);
        if ($trainer) $redirect_url = home_url('/trainer-dashboard/');
    }
    if (isset($_GET['redirect_to'])) {
        $requested = esc_url($_GET['redirect_to']);
        if (wp_validate_redirect($requested, false)) $redirect_url = $requested;
    }
    wp_safe_redirect($redirect_url);
    exit;
}

// Handle redirect_to
$redirect = home_url('/parent-dashboard/');
if (!empty($_GET['redirect_to'])) {
    $requested = esc_url($_GET['redirect_to']);
    if (wp_validate_redirect($requested, false)) $redirect = $requested;
}

// Messages
$error = '';
$success = '';
if (isset($_GET['login']) && $_GET['login'] === 'failed') $error = 'Invalid email or password.';
if (isset($_GET['error'])) {
    $code = sanitize_text_field($_GET['error']);
    $msgs = array(
        'invalid_email' => 'Invalid email address.',
        'invalid_username' => 'No account found with that email.',
        'incorrect_password' => 'Incorrect password.',
        'invalid_password' => 'Incorrect password.',
        'empty_username' => 'Please enter your email.',
        'empty_password' => 'Please enter your password.',
        'authentication_failed' => 'Login failed. Please try again.',
        'expired_session' => 'Session expired. Please log in again.',
    );
    $error = $msgs[$code] ?? 'Login failed. Please try again.';
}
if (isset($_GET['checkemail']) && $_GET['checkemail'] === 'confirm') $success = 'Check your email for the password reset link.';
if (isset($_GET['password']) && $_GET['password'] === 'changed') $success = 'Password changed successfully. Sign in below.';
if (isset($_GET['registered'])) $success = 'Account created! Sign in below.';
if (isset($_GET['logged_out']) && $_GET['logged_out'] === '1') $success = 'You\'ve been logged out.';

$logo_url = get_option('ptp_email_logo_url', '');
$custom_logo_id = get_theme_mod('custom_logo');
if ($custom_logo_id) $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');

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
    <title>Sign In - PTP Training</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <?php wp_head(); ?>
    <style>
    <?php include PTP_PLUGIN_DIR . 'templates/auth-shared-styles.php'; ?>

    /* v221: Nuclear overrides — beat ANY surviving theme/Elementor CSS */
    html, body { margin: 0 !important; padding: 0 !important; background: #111113 !important;
        overflow-x: hidden !important; min-height: 100vh !important; min-height: 100dvh !important; }
    html { margin-top: 0 !important; }
    #wpadminbar { display: none !important; }
    .elementor-location-header, .elementor-location-footer,
    header, footer, nav.main-navigation, .site-header, .site-footer,
    .ekit-template-content-header, .ekit-template-content-footer,
    .sticky-header, .announcement-bar, #masthead, #colophon,
    [data-elementor-type="header"], [data-elementor-type="footer"],
    .ptp-floating-chatbot, .ptp-chatbot-widget { display: none !important; }
    .site-content, .page-content, .entry-content, main,
    .elementor, .elementor-inner, .elementor-section-wrap,
    article, .site-main, .content-area, #content, #primary {
        padding: 0 !important; margin: 0 !important; max-width: none !important;
        width: 100% !important; background: transparent !important; }
    </style>
</head>
<body style="margin:0;padding:0;background:#111113;">
<div class="ptp-auth">
    <!-- BRAND PANEL (desktop only) -->
    <div class="ptp-auth-brand">
        <div class="ptp-auth-brand-inner">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="ptp-auth-brand-logo">
                <img src="<?php echo esc_url($logo_url); ?>" alt="PTP">
            </a>
            <h1 class="ptp-auth-brand-title">WELCOME<br><span>BACK</span></h1>
            <p class="ptp-auth-brand-sub">Sign in to manage bookings, track your player's progress, and connect with your coaches.</p>
            <div class="ptp-auth-brand-stats">
                <div class="ptp-auth-stat"><span class="ptp-auth-stat-num">500+</span><span class="ptp-auth-stat-label">FAMILIES</span></div>
                <div class="ptp-auth-stat"><span class="ptp-auth-stat-num">4.9</span><span class="ptp-auth-stat-label">RATING</span></div>
                <div class="ptp-auth-stat"><span class="ptp-auth-stat-num">8:1</span><span class="ptp-auth-stat-label">RATIO</span></div>
            </div>
            <div class="ptp-auth-brand-quote">
                <p>"My son improved more in 4 days than an entire season of rec. The coaches actually play with the kids."</p>
                <cite>- Sarah M., Villanova</cite>
            </div>
        </div>
    </div>

    <!-- FORM PANEL -->
    <div class="ptp-auth-form-panel">
        <div class="ptp-auth-form-inner">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="ptp-auth-mobile-logo">
                <img src="<?php echo esc_url($logo_url); ?>" alt="PTP">
            </a>
            <div class="ptp-auth-form-header">
                <h2>SIGN IN</h2>
                <p>Enter your credentials to continue</p>
            </div>

            <div id="loginError" class="ptp-auth-msg ptp-auth-msg-error" style="<?php echo $error ? '' : 'display:none'; ?>">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-width="2" d="M12 8v4m0 4h.01"/></svg>
                <span id="loginErrorText"><?php echo esc_html($error); ?></span>
            </div>

            <?php if ($success): ?>
            <div class="ptp-auth-msg ptp-auth-msg-success">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-width="2" d="M9 12l2 2 4-4"/></svg>
                <?php echo esc_html($success); ?>
            </div>
            <?php endif; ?>

            <?php if ($has_google): ?>
            <a href="<?php echo esc_url(home_url('/login/google/')); ?>" class="ptp-auth-google">
                <svg width="18" height="18" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                Continue with Google
            </a>
            <div class="ptp-auth-divider"><span>or</span></div>
            <?php endif; ?>

            <form method="post" id="loginForm">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect); ?>">
                <input type="hidden" name="action" value="ptp_login">
                <?php wp_nonce_field('ptp_nonce', 'ptp_nonce'); ?>

                <div class="ptp-auth-field">
                    <label for="user_login">EMAIL</label>
                    <input type="email" name="log" id="user_login" placeholder="you@email.com" required autocomplete="email" autocapitalize="none" inputmode="email">
                </div>

                <div class="ptp-auth-field">
                    <label for="user_pass">PASSWORD <a href="<?php echo esc_url(home_url('/forgot-password/')); ?>" class="ptp-auth-field-link">Forgot?</a></label>
                    <div class="ptp-auth-pass-wrap">
                        <input type="password" name="pwd" id="user_pass" placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="ptp-auth-pass-toggle" onclick="ptpTogglePass(this)" aria-label="Show password">
                            <svg class="eye-open" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-closed" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                    </div>
                </div>

                <div class="ptp-auth-remember">
                    <label><input type="checkbox" name="rememberme" value="forever" checked> <span>Remember me</span></label>
                </div>

                <button type="submit" class="ptp-auth-submit" id="loginBtn"><span id="btnText">SIGN IN</span></button>
            </form>

            <div class="ptp-auth-footer-links">
                <p>Don't have an account? <a href="<?php echo esc_url(home_url('/register/')); ?>">Create one</a></p>
                <p class="ptp-auth-coach-link">Are you a coach? <a href="<?php echo esc_url(home_url('/apply/')); ?>">Apply here</a></p>
            </div>
        </div>
    </div>
</div>

<script>
function ptpTogglePass(btn){var i=btn.previousElementSibling,o=btn.querySelector('.eye-open'),c=btn.querySelector('.eye-closed');if(i.type==='password'){i.type='text';o.style.display='none';c.style.display='block'}else{i.type='password';o.style.display='block';c.style.display='none'}}
(function(){
    var f=document.getElementById('loginForm'),b=document.getElementById('loginBtn'),t=document.getElementById('btnText');
    var errBox=document.getElementById('loginError'),errText=document.getElementById('loginErrorText');
    var ajaxUrl='<?php echo admin_url("admin-ajax.php"); ?>';

    function showError(msg){errText.textContent=msg;errBox.style.display='flex'}
    function hideError(){errBox.style.display='none'}

    f.addEventListener('submit',function(e){
        e.preventDefault();
        var em=document.getElementById('user_login'),pw=document.getElementById('user_pass');
        if(!em.value.trim()||!pw.value){if(!em.value.trim())em.classList.add('error');if(!pw.value)pw.classList.add('error');return}
        hideError();
        b.disabled=true;t.innerHTML='<span class="ptp-auth-spinner"></span>SIGNING IN...';

        var fd=new FormData();
        fd.append('action','ptp_login');
        fd.append('nonce',f.querySelector('[name="ptp_nonce"]').value);
        fd.append('email',em.value.trim());
        fd.append('password',pw.value);
        fd.append('redirect_to',f.querySelector('[name="redirect_to"]').value);

        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){
            if(!r.ok) throw new Error('Server error '+r.status);
            return r.json();
        })
        .then(function(r){
            if(r.success&&r.data&&r.data.redirect){t.textContent='REDIRECTING...';window.location.href=r.data.redirect}
            else{b.disabled=false;t.textContent='SIGN IN';showError((r.data&&r.data.message)?r.data.message:'Invalid email or password.')}
        })
        .catch(function(err){
            b.disabled=false;t.textContent='SIGN IN';
            showError('Connection error. Please check your internet and try again.');
            console.error('PTP Login Error:',err);
        });
    });
    document.querySelectorAll('.ptp-auth-field input').forEach(function(el){el.addEventListener('input',function(){this.classList.remove('error');hideError()})});
    if(window.innerWidth>=768&&!document.getElementById('user_login').value)setTimeout(function(){document.getElementById('user_login').focus()},300);
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
