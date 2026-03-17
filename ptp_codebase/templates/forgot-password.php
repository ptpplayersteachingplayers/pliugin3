<?php
/**
 * Forgot Password Template - PTP v221
 * Masterclass-style dark immersive auth
 */
defined('ABSPATH') || exit;

include_once PTP_PLUGIN_DIR . 'templates/auth-page-setup.php';

if (is_user_logged_in()) { wp_safe_redirect(home_url('/parent-dashboard/')); exit; }

$logo_url = get_option('ptp_email_logo_url', '');
$cid = get_theme_mod('custom_logo'); if ($cid) $logo_url = wp_get_attachment_image_url($cid, 'medium');
$email_sent = isset($_GET['checkemail']) && $_GET['checkemail'] === 'confirm';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#0A0A0A">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Reset Password - PTP Training</title>
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
            <h1 class="ptp-auth-brand-title">NO<br><span>WORRIES</span></h1>
            <p class="ptp-auth-brand-sub">It happens to the best of us. Enter your email and we'll send you a link to reset your password.</p>
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
            <a href="<?php echo esc_url(home_url('/login/')); ?>" class="ptp-auth-back">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 12H5m7-7l-7 7 7 7"/></svg>
                Back to sign in
            </a>

            <!-- EMAIL SENT STATE -->
            <div id="sentState" style="<?php echo $email_sent ? '' : 'display:none'; ?>">
                <div class="ptp-auth-form-header"><h2>CHECK YOUR EMAIL</h2><p>We sent a password reset link to your inbox</p></div>
                <div class="ptp-auth-info"><p>Click the link in the email to create a new password. If you don't see it, check your spam folder. The link expires in 24 hours.</p></div>
                <a href="<?php echo esc_url(home_url('/login/')); ?>" class="ptp-auth-submit" style="display:block;text-align:center;text-decoration:none;line-height:48px;">BACK TO SIGN IN</a>
                <p style="text-align:center;margin-top:16px;font-size:13px;color:#6B7280">Didn't get it? <a href="<?php echo esc_url(home_url('/forgot-password/')); ?>" style="color:#FCB900;text-decoration:none">Try again</a></p>
            </div>

            <!-- REQUEST FORM STATE -->
            <div id="formState" style="<?php echo $email_sent ? 'display:none' : ''; ?>">
                <div class="ptp-auth-form-header"><h2>FORGOT PASSWORD</h2><p>Enter your email and we'll send a reset link</p></div>
                <div class="ptp-auth-msg ptp-auth-msg-error" id="errorMsg" style="display:none"></div>
                <form id="forgotForm">
                    <?php wp_nonce_field('ptp_forgot_password', 'ptp_forgot_nonce'); ?>
                    <div class="ptp-auth-field">
                        <label for="user_email">EMAIL ADDRESS</label>
                        <input type="email" id="user_email" placeholder="you@email.com" required autocomplete="email" inputmode="email">
                    </div>
                    <button type="submit" class="ptp-auth-submit" id="submitBtn">SEND RESET LINK</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
    var form=document.getElementById('forgotForm');if(!form)return;
    var btn=document.getElementById('submitBtn'),err=document.getElementById('errorMsg');
    form.addEventListener('submit',function(e){
        e.preventDefault();err.style.display='none';
        var em=document.getElementById('user_email').value.trim();
        if(!em||!em.includes('@')){err.textContent='Please enter a valid email address';err.style.display='flex';document.getElementById('user_email').classList.add('error');return}
        btn.disabled=true;btn.innerHTML='<span class="ptp-auth-spinner"></span>SENDING...';
        var fd=new FormData();fd.append('action','ptp_forgot_password');fd.append('email',em);fd.append('nonce',document.querySelector('[name="ptp_forgot_nonce"]').value);
        fetch('<?php echo admin_url("admin-ajax.php"); ?>',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(){
            document.getElementById('formState').style.display='none';document.getElementById('sentState').style.display='block';
        }).catch(function(){
            document.getElementById('formState').style.display='none';document.getElementById('sentState').style.display='block';
        });
    });
    document.getElementById('user_email').addEventListener('input',function(){this.classList.remove('error')});
    if(window.innerWidth>=768)setTimeout(function(){document.getElementById('user_email').focus()},300);
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
