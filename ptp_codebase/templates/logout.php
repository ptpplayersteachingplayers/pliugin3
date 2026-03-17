<?php
/**
 * Logout Template - PTP v117.2.14
 * Robust logout with session cleanup and proper redirects
 */
defined('ABSPATH') || exit;

// Get redirect URL before logout - SECURITY: Validate redirect is local
$redirect_to = home_url('/login/?logged_out=1');
if (isset($_GET['redirect_to'])) {
    $requested_redirect = esc_url($_GET['redirect_to']);
    // Only allow redirects to same domain
    if (wp_validate_redirect($requested_redirect, false)) {
        $redirect_to = $requested_redirect;
    }
}

// Check if user is logged in
if (!is_user_logged_in()) {
    // Already logged out, redirect to login
    wp_redirect(home_url('/login/'));
    exit;
}

// Get user info before logout for any cleanup
$user = wp_get_current_user();
$user_id = $user->ID;

// Check if this is a confirmation request
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';

// If confirmed with valid nonce, process logout
if ($confirm && wp_verify_nonce($nonce, 'ptp_logout_' . $user_id)) {
    // Clear any PTP-specific session data
    if (isset($_SESSION)) {
        unset($_SESSION['ptp_checkout_data']);
        unset($_SESSION['ptp_cart']);
        unset($_SESSION['ptp_trainer_view']);
    }
    
    // Clear any transients for this user
    delete_transient('ptp_user_' . $user_id . '_dashboard_data');
    delete_transient('ptp_user_' . $user_id . '_notifications');
    
    // Log the logout event
    ptp_log('[PTP Logout] User ' . $user_id . ' (' . $user->user_email . ') logged out');
    
    // Perform WordPress logout
    wp_logout();
    
    // Clear auth cookies
    wp_clear_auth_cookie();
    
    // Redirect to login page with success message
    wp_redirect($redirect_to);
    exit;
}

// Show confirmation page if direct access without confirmation
get_header();
?>
<style>
:root{--gold:#FCB900;--black:#0A0A0A;--white:#fff;--gray:#666}

/* v133.2: Hide scrollbar */
html, body { scrollbar-width: none; -ms-overflow-style: none; }
html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; width: 0; }

/* Reset theme styles */
body .site-content,
body .content-area,
body main,
body article,
body .entry-content {
    max-width: 100% !important;
    width: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
}

.ptp-logout-page{
    font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,var(--black) 0%,#1a1a1a 100%);
    padding:20px;
}

.ptp-logout-card{
    background:var(--white);
    border-radius:16px;
    padding:48px 40px;
    max-width:420px;
    width:100%;
    text-align:center;
    box-shadow:0 20px 40px rgba(0,0,0,0.3);
}

.ptp-logout-icon{
    width:80px;
    height:80px;
    background:rgba(252,185,0,0.1);
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    margin:0 auto 24px;
    font-size:36px;
}

.ptp-logout-card h1{
    font-family:Oswald,sans-serif;
    font-size:28px;
    font-weight:700;
    text-transform:uppercase;
    margin:0 0 12px;
    color:var(--black);
}

.ptp-logout-card p{
    color:var(--gray);
    font-size:15px;
    line-height:1.6;
    margin:0 0 32px;
}

.ptp-logout-user{
    background:#f5f5f5;
    border-radius:10px;
    padding:16px;
    margin-bottom:28px;
    display:flex;
    align-items:center;
    gap:14px;
}

.ptp-logout-avatar{
    width:48px;
    height:48px;
    border-radius:50%;
    background:var(--gold);
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:Oswald,sans-serif;
    font-size:20px;
    font-weight:700;
    color:var(--black);
    flex-shrink:0;
}

.ptp-logout-info{
    text-align:left;
}

.ptp-logout-name{
    font-weight:600;
    color:var(--black);
    font-size:15px;
    margin:0 0 2px;
}

.ptp-logout-email{
    font-size:13px;
    color:var(--gray);
    margin:0;
}

.ptp-logout-actions{
    display:flex;
    gap:12px;
}

.ptp-logout-btn{
    flex:1;
    padding:16px 24px;
    border-radius:10px;
    font-family:Oswald,sans-serif;
    font-size:14px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.5px;
    cursor:pointer;
    transition:all .2s;
    text-decoration:none;
    display:inline-block;
    text-align:center;
    border:none;
}

.ptp-logout-btn-primary{
    background:var(--black);
    color:var(--white);
}

.ptp-logout-btn-primary:hover{
    background:#333;
    transform:translateY(-1px);
}

.ptp-logout-btn-secondary{
    background:transparent;
    color:var(--black);
    border:2px solid #e5e5e5;
}

.ptp-logout-btn-secondary:hover{
    border-color:#ccc;
    background:#f9f9f9;
}

.ptp-logout-footer{
    margin-top:28px;
    padding-top:20px;
    border-top:1px solid #eee;
}

.ptp-logout-footer a{
    font-size:13px;
    color:var(--gold);
    text-decoration:none;
}

.ptp-logout-footer a:hover{
    text-decoration:underline;
}

/* Loading state */
.ptp-logout-btn.loading{
    opacity:.7;
    pointer-events:none;
}

@media(max-width:480px){
    .ptp-logout-card{
        padding:32px 24px;
    }
    .ptp-logout-actions{
        flex-direction:column;
    }
}
</style>

<div class="ptp-logout-page">
    <div class="ptp-logout-card">
        <div class="ptp-logout-icon">👋</div>
        
        <h1>Sign Out?</h1>
        <p>Are you sure you want to sign out of your PTP account?</p>
        
        <div class="ptp-logout-user">
            <div class="ptp-logout-avatar">
                <?php echo esc_html(strtoupper(substr($user->display_name, 0, 1))); ?>
            </div>
            <div class="ptp-logout-info">
                <p class="ptp-logout-name"><?php echo esc_html($user->display_name); ?></p>
                <p class="ptp-logout-email"><?php echo esc_html($user->user_email); ?></p>
            </div>
        </div>
        
        <div class="ptp-logout-actions">
            <?php
            // Generate secure logout URL
            $logout_url = add_query_arg(array(
                'confirm' => '1',
                '_wpnonce' => wp_create_nonce('ptp_logout_' . $user_id),
            ), home_url('/logout/'));
            
            // Determine cancel redirect based on user type
            $cancel_url = home_url('/parent-dashboard/');
            if (class_exists('PTP_Trainer')) {
                $trainer = PTP_Trainer::get_by_user_id($user_id);
                if ($trainer) {
                    $cancel_url = home_url('/trainer-dashboard/');
                }
            }
            ?>
            <a href="<?php echo esc_url($cancel_url); ?>" class="ptp-logout-btn ptp-logout-btn-secondary">
                Cancel
            </a>
            <a href="<?php echo esc_url($logout_url); ?>" class="ptp-logout-btn ptp-logout-btn-primary" id="logoutBtn" onclick="this.classList.add('loading'); this.textContent='Signing out...';">
                Sign Out
            </a>
        </div>
        
        <div class="ptp-logout-footer">
            <a href="<?php echo esc_url(home_url('/')); ?>">← Back to PTP Home</a>
        </div>
    </div>
</div>

<?php get_footer(); ?>
