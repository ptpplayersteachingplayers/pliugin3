<?php
/**
 * Mentorship Checkout — Stripe Card Payment
 *
 * Handles the final payment step in the mentorship funnel.
 * Parent arrives here after their intro call is marked complete by the trainer.
 *
 * URL patterns:
 *   /mentorship-checkout/?pair_id=X          — direct from trainer-sent email link
 *   /mentorship-checkout/?pair_id=X&package=elite  — package override
 *
 * Flow:
 *   1. Validate pair exists, belongs to this parent, status = intro_done
 *   2. Show selected package + allow switching
 *   3. Stripe Card Element → createPaymentMethod → POST to ptp_mentorship_purchase
 *   4. Handle requires_action (3DS) → confirm → reload with success
 */
defined('ABSPATH') || exit;

// ── Auth ────────────────────────────────────────────────────────────
if ( ! is_user_logged_in() ) {
    $redirect = home_url( '/login/?redirect_to=' . urlencode( $_SERVER['REQUEST_URI'] ) );
    wp_redirect( $redirect );
    exit;
}

global $wpdb;
$user      = wp_get_current_user();
$parent_id = get_current_user_id();

// ── Stripe keys ─────────────────────────────────────────────────────
$stripe_mode = 'live';
$settings    = get_option( 'ptp_settings', array() );
if ( ( $settings['stripe_mode'] ?? '' ) === 'test' || get_option( 'ptp_stripe_test_mode' ) ) {
    $stripe_mode = 'test';
}
$stripe_pk = get_option( 'ptp_stripe_' . $stripe_mode . '_publishable', '' );
if ( ! $stripe_pk ) $stripe_pk = $settings[ 'stripe_' . $stripe_mode . '_publishable_key' ] ?? '';
if ( ! $stripe_pk ) $stripe_pk = get_option( 'ptp_stripe_publishable_key', '' );

// ── Load pair ────────────────────────────────────────────────────────
$pair_id        = intval( $_GET['pair_id'] ?? 0 );
$package_switch = sanitize_text_field( $_GET['package'] ?? '' );

$pair = null;
$error_msg = '';

if ( $pair_id ) {
    $pair = $wpdb->get_row( $wpdb->prepare(
        "SELECT mp.*,
                t.display_name  AS trainer_name,
                t.photo_url     AS trainer_photo,
                t.slug          AS trainer_slug,
                t.stripe_account_id AS trainer_connect_id,
                pl.name         AS player_name
         FROM {$wpdb->prefix}ptp_mentorship_pairs mp
         LEFT JOIN {$wpdb->prefix}ptp_trainers t  ON mp.trainer_id = t.id
         LEFT JOIN {$wpdb->prefix}ptp_players  pl ON mp.player_id  = pl.id
         WHERE mp.id = %d",
        $pair_id
    ) );

    if ( ! $pair ) {
        $error_msg = 'Mentorship record not found.';
    } elseif ( (int) $pair->parent_id !== $parent_id ) {
        $error_msg = 'This link is for a different account.';
        $pair = null;
    } elseif ( $pair->status === 'active' ) {
        $error_msg = 'already_active';
    } elseif ( $pair->status === 'interest' || $pair->status === 'intro_scheduled' ) {
        // v228: Don't show checkout before intro call — redirect to dashboard
        $error_msg = 'Your free intro call hasn\'t happened yet. ' . esc_html( $pair->trainer_name ?? 'Your coach' ) . ' will reach out to schedule it.';
        $pair = null;
    } elseif ( ! in_array( $pair->status, array( 'intro_done', 'paused', 'completed' ), true ) ) {
        $error_msg = 'This mentorship is not ready for checkout. Current status: ' . esc_html( $pair->status );
        $pair = null;
    }
} else {
    $error_msg = 'No mentorship record specified.';
}

// ── Package ──────────────────────────────────────────────────────────
$packages     = class_exists( 'PTP_Mentorship' ) ? PTP_Mentorship::PACKAGES : array();
$current_pkg  = $pair ? ( $package_switch && isset( $packages[$package_switch] ) ? $package_switch : $pair->package_type ) : 'development';
if ( ! isset( $packages[$current_pkg] ) ) $current_pkg = 'development';
$pkg          = $packages[$current_pkg] ?? array();

$nonce = wp_create_nonce( 'ptp_nonce' );
$ajax_url = admin_url( 'admin-ajax.php' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Start Mentorship — PTP</title>
<meta name="robots" content="noindex, nofollow">
<?php wp_head(); ?>
<style>
footer,.site-footer,#footer,.elementor-location-footer,.footer-wrapper,
.elementor-location-header,header.elementor-element,#masthead,
.site-header,.theme-header,[data-elementor-type="header"]{display:none!important}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://js.stripe.com/v3/"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--gold:#FCB900;--black:#0A0A0A;--white:#fff;--surface:#F9F9F7;--border:#E5E5E3;--muted:#737373;--radius:12px;--shadow:0 4px 24px rgba(0,0,0,.08)}
body{font-family:'Inter',system-ui,sans-serif;background:var(--surface);color:var(--black);min-height:100vh;padding-bottom:40px}
footer,.site-footer,#footer,.elementor-location-footer,.footer-wrapper,
.elementor-location-header,header.elementor-element,#masthead,
.site-header,.theme-header,[data-elementor-type="header"],
header:not(.mc-nav){display:none!important}

/* Nav */
.mc-nav{display:flex;align-items:center;justify-content:space-between;padding:calc(14px + env(safe-area-inset-top,0px)) max(20px,env(safe-area-inset-right,0px)) 14px max(20px,env(safe-area-inset-left,0px));background:var(--black);position:sticky;top:0;z-index:100}
.mc-nav-logo{font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;color:var(--gold);text-decoration:none;letter-spacing:1px}
.mc-nav-back{color:#fff;font-size:13px;text-decoration:none;opacity:.7;display:flex;align-items:center;gap:6px}
.mc-nav-back svg{width:16px;height:16px}

/* Layout */
.mc-wrap{max-width:560px;margin:0 auto;padding:24px 16px}

/* Trainer pill */
.mc-trainer{display:flex;align-items:center;gap:12px;background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:20px}
.mc-trainer img{width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid var(--gold)}
.mc-trainer-initial{width:44px;height:44px;border-radius:50%;background:var(--gold);color:var(--black);display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;flex-shrink:0}
.mc-trainer-name{font-family:'Oswald',sans-serif;font-size:15px;font-weight:700;text-transform:uppercase}
.mc-trainer-sub{font-size:12px;color:var(--muted);margin-top:2px}
.mc-badge{margin-left:auto;flex-shrink:0;background:#22C55E20;color:#16A34A;font-size:10px;font-weight:700;font-family:'Oswald',sans-serif;text-transform:uppercase;padding:4px 8px;border-radius:20px;letter-spacing:.5px}

/* Package picker */
.mc-section-label{font-family:'Oswald',sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--muted);margin-bottom:10px}
.mc-pkgs{display:flex;gap:8px;margin-bottom:20px}
.mc-pkg{flex:1;padding:14px 8px;border:2px solid var(--border);border-radius:var(--radius);text-align:center;cursor:pointer;transition:all .18s;background:var(--white);position:relative}
.mc-pkg.active{border-color:var(--gold);background:#FCB90008}
.mc-pkg-pop{position:absolute;top:-9px;left:50%;transform:translateX(-50%);font-size:9px;background:var(--gold);color:var(--black);padding:2px 8px;border-radius:10px;font-weight:700;font-family:'Oswald',sans-serif;text-transform:uppercase;white-space:nowrap}
.mc-pkg-name{font-family:'Oswald',sans-serif;font-size:11px;text-transform:uppercase;font-weight:700;color:var(--muted);margin-bottom:4px}
.mc-pkg-price{font-family:'Oswald',sans-serif;font-size:22px;font-weight:700;line-height:1;color:var(--black)}
.mc-pkg-unit{font-size:10px;color:var(--muted);margin-top:2px}
.mc-pkg.active .mc-pkg-name{color:var(--gold)}
.mc-pkg.active .mc-pkg-price{color:var(--black)}

/* Package detail */
.mc-pkg-detail{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px;margin-bottom:20px}
.mc-pkg-detail-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f0f0ef;font-size:13px}
.mc-pkg-detail-row:last-child{border-bottom:none;padding-bottom:0}
.mc-pkg-detail-row span:first-child{color:var(--muted)}
.mc-pkg-detail-row span:last-child{font-weight:600}
.mc-pkg-includes{margin-top:12px;padding-top:12px;border-top:1px solid var(--border)}
.mc-pkg-inc-item{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#525252;padding:4px 0;line-height:1.4}
.mc-pkg-inc-dot{width:8px;height:8px;border-radius:50%;background:var(--gold);flex-shrink:0;margin-top:3px}

/* Summary */
.mc-summary{background:var(--black);color:var(--white);border-radius:var(--radius);padding:18px;margin-bottom:20px}
.mc-summary-row{display:flex;justify-content:space-between;font-size:13px;padding:5px 0}
.mc-summary-row.total{font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;padding-top:12px;margin-top:8px;border-top:1px solid #333}
.mc-summary-row.total span:last-child{color:var(--gold)}
.mc-summary-note{font-size:11px;color:#737373;margin-top:8px;line-height:1.5}

/* Payment form */
.mc-card-box{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px;margin-bottom:20px}
.mc-card-label{font-family:'Oswald',sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:10px}
#card-element{padding:14px;border:2px solid var(--border);border-radius:8px;background:#fff;transition:border-color .2s;font-size:16px}
#card-element.StripeElement--focus{border-color:var(--gold)}
#card-element.StripeElement--invalid{border-color:#EF4444}
.mc-card-error{color:#EF4444;font-size:12px;margin-top:8px;display:none;line-height:1.4}

/* Name field */
.mc-field{margin-bottom:14px}
.mc-field label{display:block;font-family:'Oswald',sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:6px}
.mc-field input{width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:8px;font-size:16px;font-family:'Inter',sans-serif;transition:border-color .2s;background:#fff}
.mc-field input:focus{outline:none;border-color:var(--gold)}

/* Submit */
.mc-submit{width:100%;padding:16px;background:var(--gold);color:var(--black);font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border:none;border-radius:var(--radius);cursor:pointer;transition:all .2s;min-height:56px}
.mc-submit:hover{background:#E5A800;transform:translateY(-1px)}
.mc-submit:disabled{opacity:.5;cursor:not-allowed;transform:none}
.mc-submit.loading::after{content:' ...'}

/* Trust row */
.mc-trust{display:flex;gap:16px;justify-content:center;margin-top:12px;flex-wrap:wrap}
.mc-trust span{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:5px}
.mc-trust svg{width:13px;height:13px;color:#22C55E}

/* Earn callout (unused) */
.mc-earn{background:rgba(252,185,0,0.06);border:1px solid rgba(252,185,0,0.2);border-radius:var(--radius);padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:20px}
.mc-earn-pct{width:36px;height:36px;border-radius:50%;background:var(--gold);color:var(--black);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-family:'Oswald',sans-serif;font-weight:700;font-size:13px}
.mc-earn-text{font-size:12px;color:#525252;line-height:1.4}
.mc-earn-text strong{color:var(--black)}

/* Success */
.mc-success{text-align:center;padding:40px 20px}
.mc-success-icon{font-size:56px;margin-bottom:16px}
.mc-success h2{font-family:'Oswald',sans-serif;font-size:26px;text-transform:uppercase;margin-bottom:8px}
.mc-success p{font-size:14px;color:var(--muted);line-height:1.6;margin-bottom:24px;max-width:360px;margin-left:auto;margin-right:auto}
.mc-success a{display:inline-block;padding:14px 28px;background:var(--gold);color:var(--black);font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;border-radius:var(--radius);text-decoration:none}

/* Error state */
.mc-error-state{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:32px 20px;text-align:center}
.mc-error-state h3{font-family:'Oswald',sans-serif;font-size:18px;text-transform:uppercase;margin-bottom:8px}
.mc-error-state p{font-size:13px;color:var(--muted);line-height:1.6;margin-bottom:20px}
.mc-error-state a{color:var(--black);font-weight:600;text-decoration:underline}

@media (max-width: 480px) {
    .mc-wrap { padding: 20px 12px; }
    .mc-pkg-price { font-size: 20px; }
    .mc-submit { font-size: 15px; padding: 14px; }
    .mc-trainer { padding: 12px; gap: 10px; }
    .mc-trainer img, .mc-trainer-initial { width: 38px; height: 38px; }
}
@media (max-width: 380px) {
    .mc-pkgs { flex-direction: column; gap: 10px; }
    .mc-pkg { padding: 16px; }
}
/* Safe area bottom padding */
body { padding-bottom: calc(40px + env(safe-area-inset-bottom, 0px)); }
/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
}
/* Landscape orientation */
@media (max-height: 500px) and (orientation: landscape) {
    .mc-nav { padding-top: 10px; padding-bottom: 10px; }
    .mc-wrap { padding: 16px 12px; }
    .mc-success { padding: 20px 16px; }
}
</style>
</head>
<body>

<nav class="mc-nav">
    <?php if ( $pair && $pair->trainer_slug ): ?>
    <a href="<?php echo esc_url( home_url( '/trainer/' . $pair->trainer_slug . '/' ) ); ?>" class="mc-nav-back">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 19l-7-7 7-7"/></svg>
        Back
    </a>
    <?php else: ?>
    <a href="<?php echo esc_url( home_url( '/find-trainers/' ) ); ?>" class="mc-nav-back">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M15 19l-7-7 7-7"/></svg>
        Back
    </a>
    <?php endif; ?>
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="mc-nav-logo">PTP</a>
    <div style="width:48px"></div>
</nav>

<div class="mc-wrap">
<h1 style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0">Start Mentorship — PTP Soccer</h1>

<?php if ( $error_msg === 'already_active' ): ?>
<!-- ── Already active ── -->
<div class="mc-success">
    <div class="mc-success-icon">✅</div>
    <h2>You're Already In</h2>
    <p>This mentorship is already active. Head to your dashboard to see your sessions, goals, and upcoming calls.</p>
    <a href="<?php echo esc_url( home_url( '/dashboard/#mentorship' ) ); ?>">Go to Dashboard</a>
</div>

<?php elseif ( $error_msg || ! $pair ): ?>
<!-- ── Error ── -->
<div class="mc-error-state">
    <h3>Can't Load Checkout</h3>
    <p><?php echo $error_msg ?: 'This checkout link may have expired or been used already.'; ?></p>
    <p>Questions? <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Contact us</a> or reply to your email from your coach.</p>
</div>

<?php else: ?>
<!-- ── Main Checkout ── -->

<div id="checkoutForm">

    <!-- Trainer pill -->
    <div class="mc-trainer">
        <?php if ( ! empty( $pair->trainer_photo ) ): ?>
        <img src="<?php echo esc_url( $pair->trainer_photo ); ?>" alt="<?php echo esc_attr( $pair->trainer_name ); ?>">
        <?php else: ?>
        <div class="mc-trainer-initial"><?php echo strtoupper( substr( $pair->trainer_name ?: 'C', 0, 1 ) ); ?></div>
        <?php endif; ?>
        <div>
            <div class="mc-trainer-name"><?php echo esc_html( $pair->trainer_name ); ?></div>
            <div class="mc-trainer-sub"><?php echo esc_html( $pair->player_name ); ?>'s Mentor &middot; Intro call complete</div>
        </div>
        <div class="mc-badge">Intro Done</div>
    </div>

    <!-- Package picker -->
    <div class="mc-section-label">Choose Your Package</div>
    <div class="mc-pkgs" id="pkgPicker">
        <?php foreach ( $packages as $pk => $p ): ?>
        <div class="mc-pkg <?php echo $pk === $current_pkg ? 'active' : ''; ?>" data-pkg="<?php echo esc_attr( $pk ); ?>" onclick="selectPackage('<?php echo esc_js( $pk ); ?>')">
            <?php if ( $pk === 'development' ): ?><div class="mc-pkg-pop">Most Popular</div><?php elseif ( $pk === 'single' ): ?><div class="mc-pkg-pop" style="background:#3B82F6;color:#fff">Try It</div><?php endif; ?>
            <div class="mc-pkg-name"><?php echo esc_html( $p['name'] ); ?></div>
            <div class="mc-pkg-price">$<?php echo $p['per_session_display']; ?></div>
            <div class="mc-pkg-unit">/session</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Package detail -->
    <div class="mc-pkg-detail" id="pkgDetail">
        <?php foreach ( $packages as $pk => $p ): ?>
        <div id="detail-<?php echo $pk; ?>" style="<?php echo $pk !== $current_pkg ? 'display:none' : ''; ?>">
            <div class="mc-pkg-detail-row">
                <span>Sessions</span>
                <span><?php echo $p['sessions']; ?> <?php echo ($p['billing'] ?? 'weekly') === 'one-time' ? 'session' : 'weekly sessions'; ?></span>
            </div>
            <div class="mc-pkg-detail-row">
                <span>Session length</span>
                <span><?php echo $p['session_length']; ?> minutes</span>
            </div>
            <div class="mc-pkg-detail-row">
                <span>Billed</span>
                <span><?php if (($p['billing'] ?? 'weekly') === 'one-time'): ?>$<?php echo $p['per_session_display']; ?> one-time<?php else: ?>$<?php echo $p['per_session_display']; ?>/week &middot; auto-cancels after <?php echo $p['sessions']; ?> weeks<?php endif; ?></span>
            </div>
            <?php if ( ! empty( $p['best_for'] ) ): ?>
            <div class="mc-pkg-includes">
                <?php foreach ( $p['best_for'] as $item ): ?>
                <div class="mc-pkg-inc-item"><div class="mc-pkg-inc-dot"></div><?php echo esc_html( $item ); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Order summary -->
    <div class="mc-summary">
        <div class="mc-summary-row">
            <span>Package</span>
            <span id="summaryPkg"><?php echo esc_html( $pkg['name'] ?? '' ); ?></span>
        </div>
        <div class="mc-summary-row">
            <span>Sessions</span>
            <span id="summaryCount"><?php echo intval( $pkg['sessions'] ?? 0 ); ?> session<?php echo ($pkg['sessions'] ?? 0) > 1 ? 's' : ''; ?></span>
        </div>
        <div class="mc-summary-row total">
            <span id="summaryLabel"><?php echo ($pkg['billing'] ?? 'weekly') === 'one-time' ? 'Total' : 'Per week'; ?></span>
            <span id="summaryPrice">$<?php echo intval( $pkg['per_session_display'] ?? 0 ); ?></span>
        </div>
        <div class="mc-summary-note" id="summaryNote">
            <?php echo ($pkg['billing'] ?? 'weekly') === 'one-time' ? 'One-time payment. No subscription. Book more sessions anytime.' : 'Billed weekly. Automatically stops after all sessions are scheduled. Cancel anytime before the next billing cycle.'; ?>
        </div>
    </div>

    <!-- Card form -->
    <div class="mc-card-box">
        <div class="mc-card-label">Payment Details</div>
        <div class="mc-field">
            <label for="cardholderName">Name on card</label>
            <input type="text" id="cardholderName" name="cardholderName"
                   value="<?php echo esc_attr( $user->display_name ); ?>"
                   autocomplete="cc-name" placeholder="Full name">
        </div>
        <div class="mc-card-label">Card number</div>
        <div id="card-element"></div>
        <div id="card-error" class="mc-card-error"></div>
    </div>

    <button class="mc-submit" id="submitBtn" onclick="submitCheckout()">
        <?php if (($pkg['billing'] ?? 'weekly') === 'one-time'): ?>
        Book Session — $<span id="btnPrice"><?php echo intval( $pkg['per_session_display'] ?? 0 ); ?></span>
        <?php else: ?>
        Start Mentorship — $<span id="btnPrice"><?php echo intval( $pkg['per_session_display'] ?? 0 ); ?></span>/week
        <?php endif; ?>
    </button>

    <div class="mc-trust">
        <span><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>Secured by Stripe</span>
        <span><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Cancel anytime</span>
        <span><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>No commitment after package ends</span>
    </div>

</div><!-- #checkoutForm -->

<!-- Success state (shown after payment) -->
<div id="successState" class="mc-success" style="display:none">
    <div class="mc-success-icon">🏆</div>
    <h2>You're In</h2>
    <p id="successMsg">Your mentorship with <?php echo esc_html( $pair->trainer_name ); ?> is live. Your first session will be scheduled shortly.</p>
    <a href="<?php echo esc_url( home_url( '/dashboard/#mentorship' ) ); ?>">Open Your Dashboard</a>
</div>

<?php endif; ?>
</div><!-- .mc-wrap -->

<?php if ( $pair && $stripe_pk ): ?>
<script>
var STRIPE_PK    = <?php echo json_encode( $stripe_pk ); ?>;
var PAIR_ID      = <?php echo intval( $pair_id ); ?>;
var NONCE        = <?php echo json_encode( $nonce ); ?>;
var AJAX_URL     = <?php echo json_encode( $ajax_url ); ?>;
var PKG_DATA     = <?php echo json_encode( $packages ); ?>;
var currentPkg   = <?php echo json_encode( $current_pkg ); ?>;

var stripe       = Stripe( STRIPE_PK );
var elements     = stripe.elements({ locale: 'en' });
var cardElement  = elements.create('card', {
    style: {
        base: {
            fontFamily: 'Inter, system-ui, sans-serif',
            fontSize:   '15px',
            color:      '#0A0A0A',
            '::placeholder': { color: '#9ca3af' }
        },
        invalid: { color: '#EF4444' }
    }
});
cardElement.mount('#card-element');
cardElement.on('change', function(e) {
    var el = document.getElementById('card-error');
    el.textContent  = e.error ? e.error.message : '';
    el.style.display = e.error ? 'block' : 'none';
});

// ── Package switching ────────────────────────────────────────────
function selectPackage(pkg) {
    currentPkg = pkg;
    // Picker active state
    document.querySelectorAll('.mc-pkg').forEach(function(el) {
        el.classList.toggle('active', el.dataset.pkg === pkg);
    });
    // Detail panels
    document.querySelectorAll('[id^="detail-"]').forEach(function(el) {
        el.style.display = el.id === 'detail-' + pkg ? '' : 'none';
    });
    // Summary
    var p = PKG_DATA[pkg];
    if (p) {
        var isOneTime = (p.billing || 'weekly') === 'one-time';
        document.getElementById('summaryPkg').textContent   = p.name;
        document.getElementById('summaryCount').textContent = p.sessions + (p.sessions > 1 ? ' sessions' : ' session');
        document.getElementById('summaryPrice').textContent = '$' + p.per_session_display;
        document.getElementById('summaryLabel').textContent = isOneTime ? 'Total' : 'Per week';
        document.getElementById('summaryNote').textContent = isOneTime
            ? 'One-time payment. No subscription. Book more sessions anytime.'
            : 'Billed weekly. Automatically stops after all sessions are scheduled. Cancel anytime before the next billing cycle.';
        document.getElementById('btnPrice').textContent = p.per_session_display;
        var btnEl = document.getElementById('submitBtn');
        btnEl.innerHTML = isOneTime
            ? 'Book Session — $<span id="btnPrice">' + p.per_session_display + '</span>'
            : 'Start Mentorship — $<span id="btnPrice">' + p.per_session_display + '</span>/week';
    }
}

// ── Submit ────────────────────────────────────────────────────────
async function submitCheckout() {
    var btn = document.getElementById('submitBtn');
    var errorEl = document.getElementById('card-error');
    var name = document.getElementById('cardholderName').value.trim();

    btn.disabled = true;
    btn.classList.add('loading');
    errorEl.style.display = 'none';

    // 1. Create PaymentMethod
    var result = await stripe.createPaymentMethod({
        type: 'card',
        card: cardElement,
        billing_details: { name: name || undefined }
    });

    if (result.error) {
        errorEl.textContent  = result.error.message;
        errorEl.style.display = 'block';
        btn.disabled = false;
        btn.classList.remove('loading');
        return;
    }

    var paymentMethodId = result.paymentMethod.id;

    // 2. Send to server → creates Stripe subscription
    var fd = new FormData();
    fd.append('action',            'ptp_mentorship_purchase');
    fd.append('nonce',             NONCE);
    fd.append('pair_id',           PAIR_ID);
    fd.append('payment_method_id', paymentMethodId);
    fd.append('package',           currentPkg);

    var res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
    var data = await res.json();

    if (!data.success) {
        errorEl.textContent  = data.data || 'Payment failed. Please try again.';
        errorEl.style.display = 'block';
        btn.disabled = false;
        btn.classList.remove('loading');
        return;
    }

    // 3. Handle 3DS / SCA
    if (data.data && data.data.status === 'requires_action') {
        var confirmResult = await stripe.confirmCardPayment(data.data.client_secret);
        if (confirmResult.error) {
            errorEl.textContent  = confirmResult.error.message;
            errorEl.style.display = 'block';
            btn.disabled = false;
            btn.classList.remove('loading');
            return;
        }
        // 3DS passed — server will activate via webhook, but we can confirm on client
    }

    // 4. Success
    document.getElementById('checkoutForm').style.display = 'none';
    var successEl = document.getElementById('successState');
    successEl.style.display = 'block';
    if (data.data && data.data.message) {
        document.getElementById('successMsg').textContent = data.data.message;
    }
    successEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
