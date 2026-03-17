<?php
/**
 * Reset Password Template - PTP v221
 * Handles the password reset link from email
 */
defined('ABSPATH') || exit;

include_once PTP_PLUGIN_DIR . 'templates/auth-page-setup.php';

if (is_user_logged_in()) { wp_safe_redirect(home_url('/parent-dashboard/')); exit; }

$logo_url = get_option('ptp_email_logo_url', '');
$cid = get_theme_mod('custom_logo'); if ($cid) $logo_url = wp_get_attachment_image_url($cid, 'medium');

// Get reset key and login from URL
$rp_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
$rp_login = isset($_GET['login']) ? sanitize_text_field($_GET['login']) : '';
$error = '';
$valid_key = false;

if ($rp_key && $rp_login) {
    $user = check_password_reset_key($rp_key, $rp_login);
    if (!is_wp_error($user)) {
        $valid_key = true;
    } else {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    }
} else {
    $error = 'Invalid reset link. Please request a new one.';
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#0A0A0A">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Set New Password - PTP Training</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <?php wp_head(); ?>
    <style>
    <?php include PTP_PLUGIN_DIR . 'templates/auth-shared-styles.php'; ?>

    html, body { margin: 0 !important; padding: 0 !important; background: #111113 !important; overflow-x: hidden !important; }
    html { margin-top: 0 !important; }
    #wpadminbar { display: none !important; }
    .elementor-location-header, .elementor-location-footer, header, footer,
    nav.main-navigation, .site-header, .site-footer, .ekit-template-content-header,
    .ekit-template-content-footer, .sticky-header, .announcement-bar, #masthead, #colophon,
    [data-elementor-type="header"], [data-elementor-type="footer"],
    .ptp-floating-chatbot, .ptp-chatbot-widget { display: none !important; }
    .site-content, .page-content, .entry-content, main, .elementor, article,
    .site-main, .content-area, #content, #primary {
        padding: 0 !important; margin: 0 !important; max-width: none !important;
        width: 100% !important; background: transparent !important; }
    </style>
</head>
<body style="margin:0;padding:0;background:#111113;">
<div class="ptp-auth">
    <div class="ptp-auth-brand">
        <div class="ptp-auth-brand-inner">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="ptp-auth-brand-logo"><img src="<?php echo esc_url($logo_url); ?>" alt="PTP"></a>
            <h1 class="ptp-auth-brand-title">SET A<br><span>NEW PASSWORD</span></h1>
            <p class="ptp-auth-brand-sub">Choose a strong password you'll remember. You'll be signed in immediately after resetting.</p>
            <div class="ptp-auth-brand-stats">
                <div class="ptp-auth-stat"><span class="ptp-auth-stat-num">500+</span><span class="ptp-auth-stat-label">FAMILIES</span></div>
                <div class="ptp-auth-stat"><span class="ptp-auth-stat-num">4.9</span><span class="ptp-auth-stat-label">RATING</span></div>
                <div class="ptp-auth-stat"><span class="ptp-auth-stat-num">8:1</span><span class="ptp-auth-stat-label">RATIO</span></div>
            </div>
        </div>
    </div>

    <div class="ptp-auth-form-panel">
        <div class="ptp-auth-form-inner">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="ptp-auth-mobile-logo"><img src="<?php echo esc_url($logo_url); ?>" alt="PTP"></a>

            <?php if (!$valid_key): ?>
                <!-- INVALID/EXPIRED KEY -->
                <div class="ptp-auth-form-header"><h2>LINK EXPIRED</h2><p>This password reset link is no longer valid</p></div>
                <?php if ($error): ?>
                <div class="ptp-auth-msg ptp-auth-msg-error">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-width="2" d="M12 8v4m0 4h.01"/></svg>
                    <?php echo esc_html($error); ?>
                </div>
                <?php endif; ?>
                <a href="<?php echo esc_url(home_url('/forgot-password/')); ?>" class="ptp-auth-submit" style="display:block;text-align:center;text-decoration:none;line-height:48px;">REQUEST NEW LINK</a>
                <div class="ptp-auth-footer-links"><p><a href="<?php echo esc_url(home_url('/login/')); ?>">Back to sign in</a></p></div>
            <?php else: ?>
                <!-- VALID KEY - SHOW FORM -->
                <div class="ptp-auth-form-header"><h2>NEW PASSWORD</h2><p>Choose a strong password for your account</p></div>
                <div class="ptp-auth-msg ptp-auth-msg-error" id="errorMsg" style="display:none"></div>
                <div class="ptp-auth-msg ptp-auth-msg-success" id="successMsg" style="display:none"></div>
                <form id="resetForm">
                    <?php wp_nonce_field('ptp_reset_password', 'ptp_reset_nonce'); ?>
                    <input type="hidden" name="rp_key" value="<?php echo esc_attr($rp_key); ?>">
                    <input type="hidden" name="rp_login" value="<?php echo esc_attr($rp_login); ?>">
                    <div class="ptp-auth-field">
                        <label for="pass1">NEW PASSWORD</label>
                        <div class="ptp-auth-pass-wrap">
                            <input type="password" id="pass1" placeholder="Enter new password" minlength="8" required autocomplete="new-password">
                            <button type="button" class="ptp-auth-pass-toggle" onclick="ptpTogglePass(this)" aria-label="Show password">
                                <svg class="eye-open" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                        <div class="ptp-auth-hint">At least 8 characters</div>
                    </div>
                    <div class="ptp-auth-field">
                        <label for="pass2">CONFIRM PASSWORD</label>
                        <div class="ptp-auth-pass-wrap">
                            <input type="password" id="pass2" placeholder="Confirm new password" minlength="8" required autocomplete="new-password">
                            <button type="button" class="ptp-auth-pass-toggle" onclick="ptpTogglePass(this)" aria-label="Show password">
                                <svg class="eye-open" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="eye-closed" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="ptp-auth-submit" id="submitBtn">RESET PASSWORD</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
function ptpTogglePass(btn){var i=btn.previousElementSibling,o=btn.querySelector('.eye-open'),c=btn.querySelector('.eye-closed');if(i.type==='password'){i.type='text';o.style.display='none';c.style.display='block'}else{i.type='password';o.style.display='block';c.style.display='none'}}
(function(){
    var form=document.getElementById('resetForm');if(!form)return;
    var btn=document.getElementById('submitBtn'),err=document.getElementById('errorMsg'),ok=document.getElementById('successMsg');
    form.addEventListener('submit',function(e){
        e.preventDefault();err.style.display='none';ok.style.display='none';
        var p1=document.getElementById('pass1').value,p2=document.getElementById('pass2').value;
        if(p1.length<8){show('Password must be at least 8 characters');document.getElementById('pass1').classList.add('error');return}
        if(p1!==p2){show('Passwords do not match');document.getElementById('pass2').classList.add('error');return}
        btn.disabled=true;btn.innerHTML='<span class="ptp-auth-spinner"></span>RESETTING...';
        var fd=new FormData();fd.append('action','ptp_reset_password');fd.append('pass',p1);fd.append('key',form.querySelector('[name="rp_key"]').value);fd.append('login',form.querySelector('[name="rp_login"]').value);fd.append('nonce',document.querySelector('[name="ptp_reset_nonce"]').value);
        fetch('<?php echo admin_url("admin-ajax.php"); ?>',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){
            if(d.success){ok.textContent='Password reset! Redirecting to sign in...';ok.style.display='flex';form.style.display='none';setTimeout(function(){window.location.href='<?php echo home_url("/login/?password=changed"); ?>'},1500)}
            else{show(d.data||'Reset failed. Please try again.');btn.disabled=false;btn.textContent='RESET PASSWORD'}
        }).catch(function(){show('Connection error. Please try again.');btn.disabled=false;btn.textContent='RESET PASSWORD'});
        function show(m){err.textContent=m;err.style.display='flex'}
    });
    document.querySelectorAll('.ptp-auth-field input').forEach(function(el){el.addEventListener('input',function(){this.classList.remove('error')})});
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
