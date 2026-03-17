<?php
/**
 * Confirm Session Page — Standalone (SMS link target)
 * 
 * URL: /confirm-session/?booking=X&token=Y
 * 
 * Parents click this link from SMS after trainer marks session complete.
 * Works WITHOUT WordPress login — verified by secure token tied to booking + phone.
 * 
 * Flow:
 * 1. Validate booking ID + token
 * 2. Show session details (trainer, player, date, amount)
 * 3. Confirm button → REST API → releases escrow funds
 * 4. Optional: rate trainer + leave feedback
 */
defined('ABSPATH') || exit;

global $wpdb;

$booking_id = intval($_GET['booking'] ?? 0);
$token      = sanitize_text_field($_GET['token'] ?? '');

// ── Load booking data ──
$booking = null;
$escrow  = null;
$valid   = false;
$already_confirmed = false;
$error   = '';

if (!$booking_id) {
    $error = 'Missing booking ID.';
} else {
    $booking = $wpdb->get_row($wpdb->prepare("
        SELECT b.*,
               t.display_name as trainer_name, t.photo_url as trainer_photo,
               t.slug as trainer_slug, t.playing_level,
               pl.first_name as player_first, pl.last_name as player_last,
               pa.display_name as parent_name, pa.phone as parent_phone, pa.email as parent_email
        FROM {$wpdb->prefix}ptp_bookings b
        LEFT JOIN {$wpdb->prefix}ptp_trainers t  ON b.trainer_id = t.id
        LEFT JOIN {$wpdb->prefix}ptp_players  pl ON b.player_id  = pl.id
        LEFT JOIN {$wpdb->prefix}ptp_parents  pa ON b.parent_id  = pa.id
        WHERE b.id = %d
    ", $booking_id));

    if (!$booking) {
        $error = 'Session not found.';
    } else {
        // Token verification: hash of booking_id + parent_phone + wp salt
        $expected_token = PTP_Session_Confirm_Page::generate_token($booking_id, $booking->parent_phone ?? '');
        
        // Token must match — no bypass
        $valid = !empty($token) && hash_equals($expected_token, $token);
        
        if (!$valid) {
            $error = 'Invalid confirmation link.';
        } else {
            // Check escrow status
            $escrow_table = $wpdb->prefix . 'ptp_escrow';
            if ($wpdb->get_var("SHOW TABLES LIKE '$escrow_table'") === $escrow_table) {
                $escrow = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$escrow_table} WHERE booking_id = %d ORDER BY id DESC LIMIT 1",
                    $booking_id
                ));
            }

            if ($escrow && in_array($escrow->status, ['confirmed', 'released'])) {
                $already_confirmed = true;
            } elseif ($booking->status === 'completed') {
                $already_confirmed = true;
            }
        }
    }
}

$player_name = trim(($booking->player_first ?? '') . ' ' . ($booking->player_last ?? ''));
if (!$player_name && $booking) $player_name = $booking->player_name ?? 'Your Player';
$trainer_name = $booking->trainer_name ?? 'Your Trainer';
$trainer_photo = $booking->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($trainer_name) . '&size=120&background=FCB900&color=0A0A0A&bold=true';
$session_date = '';
if ($booking && !empty($booking->session_date) && !preg_match('/^0000/', $booking->session_date)) {
    $ts = strtotime($booking->session_date);
    if ($ts && $ts > 0 && intval(date('Y', $ts)) >= 2020) {
        $session_date = date('l, F j, Y', $ts);
    }
}
$session_time = $booking && $booking->start_time ? date('g:i A', strtotime($booking->start_time)) : '';
$location = $booking->location ?? '';
$amount = $booking ? floatval($booking->total_amount ?? $booking->amount_paid ?? 0) : 0;

// REST endpoint for confirmation (nopriv)
$confirm_endpoint = rest_url('ptp/v1/confirm-session');
$nonce = wp_create_nonce('wp_rest');

get_header();
?>

<style>
.ptp-cs {
    --gold: #FCB900;
    --gold-hover: #E5A800;
    --black: #0A0A0A;
    --g100: #F5F5F5;
    --g200: #E5E5E5;
    --g400: #A3A3A3;
    --g600: #525252;
    --g800: #262626;
    --success: #10B981;
    --success-bg: #D1FAE5;
    --warn: #F59E0B;
    --warn-bg: #FEF3C7;
    --error: #EF4444;
    --error-bg: #FEE2E2;
    --radius: 16px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    max-width: 480px;
    margin: 0 auto;
    padding: 24px 16px 60px;
    min-height: 80vh;
}
.ptp-cs * { box-sizing: border-box; margin: 0; }
.ptp-cs-logo { text-align: center; margin-bottom: 24px; }
.ptp-cs-logo img { height: 40px; }
.ptp-cs-logo span { font-family: 'Oswald', sans-serif; font-size: 20px; font-weight: 700; color: var(--black); text-transform: uppercase; letter-spacing: 1px; }
.ptp-cs-logo span b { color: var(--gold); }

/* Error state */
.ptp-cs-error {
    text-align: center; padding: 40px 20px;
    background: var(--error-bg); border-radius: var(--radius); color: #991B1B;
}
.ptp-cs-error svg { display: block; margin: 0 auto 12px; }
.ptp-cs-error h2 { font-size: 18px; margin-bottom: 8px; }
.ptp-cs-error p { font-size: 14px; color: #B91C1C; }

/* Card */
.ptp-cs-card {
    background: #fff; border-radius: var(--radius); border: 2px solid var(--g200);
    overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.06);
}
.ptp-cs-hdr {
    background: linear-gradient(135deg, var(--black) 0%, #1a1a1a 100%);
    padding: 24px 20px; text-align: center; color: #fff;
}
.ptp-cs-hdr-label {
    font-family: 'Oswald', sans-serif; font-size: 10px; letter-spacing: 2px;
    text-transform: uppercase; color: var(--gold); margin-bottom: 8px;
}
.ptp-cs-hdr h2 {
    font-family: 'Oswald', sans-serif; font-size: 22px; font-weight: 700;
    text-transform: uppercase; line-height: 1.2; color: #fff;
}

/* Trainer info */
.ptp-cs-trainer {
    display: flex; align-items: center; gap: 14px; padding: 20px;
    border-bottom: 1px solid var(--g100);
}
.ptp-cs-trainer img {
    width: 60px; height: 60px; border-radius: 50%; object-fit: cover;
    border: 3px solid var(--gold);
}
.ptp-cs-trainer-name { font-weight: 700; font-size: 16px; color: var(--g800); }
.ptp-cs-trainer-sub { font-size: 13px; color: var(--g400); margin-top: 2px; }

/* Session details */
.ptp-cs-details { padding: 20px; }
.ptp-cs-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 0; border-bottom: 1px solid var(--g100);
}
.ptp-cs-row:last-child { border-bottom: none; }
.ptp-cs-row-label { font-size: 13px; color: var(--g400); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
.ptp-cs-row-value { font-size: 15px; font-weight: 600; color: var(--g800); text-align: right; }
.ptp-cs-row-amount { font-size: 18px; font-weight: 800; color: var(--success); }

/* Rating */
.ptp-cs-rating { padding: 0 20px 20px; text-align: center; }
.ptp-cs-rating-label { font-size: 14px; color: var(--g600); margin-bottom: 10px; }
.ptp-cs-stars { display: flex; justify-content: center; gap: 8px; margin-bottom: 12px; }
.ptp-cs-star {
    width: 40px; height: 40px; cursor: pointer; fill: var(--g200); transition: all 0.15s;
}
.ptp-cs-star.on, .ptp-cs-star:hover { fill: var(--gold); transform: scale(1.15); }
.ptp-cs-feedback {
    width: 100%; padding: 12px; border: 2px solid var(--g200); border-radius: 10px;
    font-size: 14px; font-family: inherit; resize: vertical; min-height: 60px;
    transition: border-color 0.2s;
}
.ptp-cs-feedback:focus { outline: none; border-color: var(--gold); }

/* Actions */
.ptp-cs-actions { padding: 0 20px 24px; }
.ptp-cs-btn {
    display: block; width: 100%; padding: 16px; border: none; border-radius: 12px;
    font-family: 'Oswald', sans-serif; font-size: 16px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px; cursor: pointer;
    transition: all 0.2s; text-align: center;
}
.ptp-cs-btn-confirm {
    background: var(--gold); color: var(--black); margin-bottom: 10px;
}
.ptp-cs-btn-confirm:hover { background: var(--gold-hover); transform: translateY(-1px); }
.ptp-cs-btn-confirm:disabled { opacity: 0.5; pointer-events: none; }
.ptp-cs-btn-issue {
    background: transparent; color: var(--g400); font-size: 13px; border: 1px solid var(--g200);
}
.ptp-cs-btn-issue:hover { border-color: var(--error); color: var(--error); }

/* Timer */
.ptp-cs-timer {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 10px; margin: 0 20px 16px; background: var(--warn-bg); border-radius: 10px;
    font-size: 12px; color: #92400E; font-weight: 600;
}

/* Success state */
.ptp-cs-success {
    text-align: center; padding: 40px 20px;
    background: var(--success-bg); border-radius: var(--radius);
}
.ptp-cs-success svg { display: block; margin: 0 auto 12px; color: var(--success); }
.ptp-cs-success h2 { font-size: 20px; color: #065F46; margin-bottom: 8px; font-family: 'Oswald', sans-serif; text-transform: uppercase; }
.ptp-cs-success p { font-size: 14px; color: #047857; line-height: 1.5; }
.ptp-cs-success-link {
    display: inline-block; margin-top: 16px; padding: 12px 24px;
    background: var(--gold); color: var(--black); border-radius: 10px;
    font-family: 'Oswald', sans-serif; font-weight: 700; text-transform: uppercase;
    text-decoration: none; font-size: 14px; letter-spacing: 0.5px;
}

/* Dispute form */
.ptp-cs-dispute { display: none; padding: 0 20px 20px; }
.ptp-cs-dispute.show { display: block; }
.ptp-cs-dispute textarea {
    width: 100%; padding: 12px; border: 2px solid var(--error); border-radius: 10px;
    font-size: 14px; font-family: inherit; resize: vertical; min-height: 80px;
    margin-bottom: 10px;
}
.ptp-cs-dispute-submit {
    display: block; width: 100%; padding: 14px; background: var(--error); color: #fff;
    border: none; border-radius: 10px; font-family: 'Oswald', sans-serif;
    font-size: 14px; font-weight: 700; text-transform: uppercase; cursor: pointer;
}

@media (max-width: 480px) {
    .ptp-cs { padding: 16px 12px 40px; }
}
@media (max-width: 768px) {
    .ptp-cs { padding: 20px 16px 60px; }
    .ptp-cs-card { border-radius: 12px; }
    .ptp-cs-actions { flex-direction: column; gap: 10px; }
    .ptp-cs-actions button,
    .ptp-cs-actions a { width: 100%; text-align: center; }
    .ptp-cs-dispute-submit { min-height: 50px; }
}
</style>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<div class="ptp-cs">
    <!-- Logo -->
    <div class="ptp-cs-logo">
        <span><b>PTP</b> SOCCER</span>
    </div>

    <?php if ($error): ?>
    <!-- ERROR STATE -->
    <div class="ptp-cs-error">
        <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <h2>Can't Load Session</h2>
        <p><?php echo esc_html($error); ?></p>
        <p style="margin-top:16px"><a href="<?php echo esc_url(home_url('/parent-dashboard/')); ?>" style="color:#FCB900;font-weight:600;text-decoration:none">Go to Dashboard &rarr;</a></p>
    </div>

    <?php elseif ($already_confirmed): ?>
    <!-- ALREADY CONFIRMED -->
    <div class="ptp-cs-success">
        <svg width="56" height="56" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="16 10 11 16 8 13"/></svg>
        <h2>Already Confirmed</h2>
        <p>This session has already been confirmed. <?php echo esc_html($trainer_name); ?>'s payment has been released.</p>
        <a href="<?php echo esc_url(home_url('/parent-dashboard/')); ?>" class="ptp-cs-success-link">View Dashboard</a>
    </div>

    <?php else: ?>
    <!-- CONFIRMATION CARD -->
    <div class="ptp-cs-card" id="confirmCard">
        <div class="ptp-cs-hdr">
            <div class="ptp-cs-hdr-label">Session Confirmation</div>
            <h2>Confirm <?php echo esc_html($player_name ?: 'Your Player'); ?>'s Session</h2>
        </div>

        <div class="ptp-cs-trainer">
            <img src="<?php echo esc_url($trainer_photo); ?>" alt="<?php echo esc_attr($trainer_name); ?>">
            <div>
                <div class="ptp-cs-trainer-name"><?php echo esc_html($trainer_name); ?></div>
                <div class="ptp-cs-trainer-sub"><?php echo esc_html($booking->playing_level ?? 'PTP Coach'); ?></div>
            </div>
        </div>

        <div class="ptp-cs-details">
            <?php if ($session_date): ?>
            <div class="ptp-cs-row">
                <span class="ptp-cs-row-label">Date</span>
                <span class="ptp-cs-row-value"><?php echo esc_html($session_date); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($session_time): ?>
            <div class="ptp-cs-row">
                <span class="ptp-cs-row-label">Time</span>
                <span class="ptp-cs-row-value"><?php echo esc_html($session_time); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($location): ?>
            <div class="ptp-cs-row">
                <span class="ptp-cs-row-label">Location</span>
                <span class="ptp-cs-row-value"><?php echo esc_html($location); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($amount > 0): ?>
            <div class="ptp-cs-row">
                <span class="ptp-cs-row-label">Amount</span>
                <span class="ptp-cs-row-value ptp-cs-row-amount">$<?php echo number_format($amount, 2); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($escrow && !empty($escrow->release_eligible_at)):
            $hours_left = max(0, round((strtotime($escrow->release_eligible_at) - time()) / 3600, 1));
        ?>
        <div class="ptp-cs-timer">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Auto-confirms in <?php echo $hours_left; ?> hours if no action taken
        </div>
        <?php endif; ?>

        <!-- Rating (optional) -->
        <div class="ptp-cs-rating">
            <div class="ptp-cs-rating-label">How was the session?</div>
            <div class="ptp-cs-stars" id="stars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <svg class="ptp-cs-star" data-val="<?php echo $i; ?>" viewBox="0 0 24 24" onclick="setRating(<?php echo $i; ?>)">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
                <?php endfor; ?>
            </div>
            <textarea class="ptp-cs-feedback" id="feedback" placeholder="Optional: Leave a note for <?php echo esc_attr(explode(' ', $trainer_name)[0]); ?>..."></textarea>
        </div>

        <!-- Actions -->
        <div class="ptp-cs-actions">
            <button type="button" class="ptp-cs-btn ptp-cs-btn-confirm" id="confirmBtn" onclick="confirmSession()">
                Confirm Session Complete
            </button>
            <button type="button" class="ptp-cs-btn ptp-cs-btn-issue" onclick="toggleDispute()">
                Something wasn't right
            </button>
        </div>

        <!-- Dispute form (hidden) -->
        <div class="ptp-cs-dispute" id="disputeForm">
            <textarea id="disputeReason" placeholder="Please describe what went wrong..."></textarea>
            <button type="button" class="ptp-cs-dispute-submit" onclick="submitDispute()">Submit Issue Report</button>
        </div>
    </div>

    <!-- SUCCESS STATE (hidden, shown after confirm) -->
    <div class="ptp-cs-success" id="successState" style="display:none">
        <svg width="56" height="56" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="16 10 11 16 8 13"/></svg>
        <h2>Session Confirmed!</h2>
        <p>Thanks for confirming. <?php echo esc_html(explode(' ', $trainer_name)[0]); ?>'s payment has been released.</p>
        <a href="<?php echo esc_url(home_url('/parent-dashboard/')); ?>" class="ptp-cs-success-link">View Dashboard</a>
    </div>
    <?php endif; ?>
</div>

<script>
(function(){
    var rating = 0;
    var bookingId = <?php echo intval($booking_id); ?>;
    var token = <?php echo json_encode($token ?: PTP_Session_Confirm_Page::generate_token($booking_id, $booking->parent_phone ?? '')); ?>;
    var endpoint = <?php echo json_encode($confirm_endpoint); ?>;
    var restNonce = <?php echo json_encode($nonce); ?>;

    window.setRating = function(val) {
        rating = val;
        document.querySelectorAll('.ptp-cs-star').forEach(function(s) {
            s.classList.toggle('on', parseInt(s.dataset.val) <= val);
        });
    };

    window.confirmSession = function() {
        var btn = document.getElementById('confirmBtn');
        btn.disabled = true;
        btn.textContent = 'Confirming...';

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
            credentials: 'same-origin',
            body: JSON.stringify({
                booking_id: bookingId,
                token: token,
                rating: rating,
                feedback: (document.getElementById('feedback').value || '').trim()
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('confirmCard').style.display = 'none';
                document.getElementById('successState').style.display = '';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                alert(data.message || 'Error confirming session. Please try again.');
                btn.disabled = false;
                btn.textContent = 'Confirm Session Complete';
            }
        })
        .catch(function() {
            alert('Network error. Please check your connection and try again.');
            btn.disabled = false;
            btn.textContent = 'Confirm Session Complete';
        });
    };

    window.toggleDispute = function() {
        var form = document.getElementById('disputeForm');
        form.classList.toggle('show');
        if (form.classList.contains('show')) {
            document.getElementById('disputeReason').focus();
        }
    };

    window.submitDispute = function() {
        var reason = (document.getElementById('disputeReason').value || '').trim();
        if (!reason) { alert('Please describe the issue.'); return; }

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
            credentials: 'same-origin',
            body: JSON.stringify({
                booking_id: bookingId,
                token: token,
                action: 'dispute',
                reason: reason
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                document.getElementById('confirmCard').innerHTML =
                    '<div style="text-align:center;padding:40px 20px">' +
                    '<h2 style="font-family:Oswald,sans-serif;font-size:20px;color:#0A0A0A;text-transform:uppercase;margin-bottom:8px">Issue Reported</h2>' +
                    '<p style="font-size:14px;color:#525252;line-height:1.5">We\'ve received your report and will review it within 24 hours. You\'ll receive a text with the resolution.</p>' +
                    '<a href="' + <?php echo json_encode(home_url('/parent-dashboard/')); ?> + '" style="display:inline-block;margin-top:16px;padding:12px 24px;background:#FCB900;color:#0A0A0A;border-radius:10px;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;text-decoration:none;font-size:14px">View Dashboard</a>' +
                    '</div>';
            } else {
                alert(data.message || 'Error submitting report.');
            }
        })
        .catch(function() { alert('Network error.'); });
    };
})();
</script>

<?php
get_footer();
