<?php
/**
 * Mentorship Interest Form [ptp_mentorship_interest]
 * 
 * Embeddable anywhere: camp thank-you page, trainer profile, standalone page.
 * No login required. Parent fills in basics → trainer gets notified → free intro call.
 * 
 * Shortcode params:
 *   trainer_id - pre-fill trainer (e.g. from camp order)
 *   source - tracking source (camp_upsell, camp_followup, training_upsell, direct)
 *   source_detail - extra context (camp name, order id)
 *   style - "card" (default) or "inline"
 */
if (!defined('ABSPATH')) exit;

$atts = shortcode_atts(array(
    'trainer_id'    => intval($_GET['trainer_id'] ?? 0),
    'source'        => sanitize_text_field($_GET['source'] ?? 'direct'),
    'source_detail' => sanitize_text_field($_GET['source_detail'] ?? ''),
    'camp_order_id' => intval($_GET['order_id'] ?? 0),
    'style'         => 'card',
), $atts ?? array());

global $wpdb;
$trainer = null;
if ($atts['trainer_id']) {
    $trainer = $wpdb->get_row($wpdb->prepare(
        "SELECT id, display_name, photo_url, slug, mentorship_enabled FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $atts['trainer_id']
    ));
}
$form_id = 'ptpInterest' . uniqid();
?>

<div id="<?php echo $form_id; ?>Wrap" style="max-width:480px;margin:0 auto;font-family:Inter,system-ui,sans-serif">
    <?php if ($atts['style'] === 'card'): ?>
    <div style="background:#0A0A0A;border-radius:16px;padding:24px;color:#fff;margin-bottom:20px">
        <?php if ($trainer && $trainer->photo_url): ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
            <img src="<?php echo esc_url($trainer->photo_url); ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #FCB900" alt="">
            <div>
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:2px;color:#FCB900;font-family:Oswald,sans-serif">Mentorship</div>
                <div style="font-family:Oswald,sans-serif;font-size:18px;text-transform:uppercase;font-weight:700">Keep Training With <?php echo esc_html(explode(' ', $trainer->display_name)[0]); ?></div>
            </div>
        </div>
        <?php else: ?>
        <div style="font-size:10px;text-transform:uppercase;letter-spacing:2px;color:#FCB900;font-family:Oswald,sans-serif;margin-bottom:4px">Mentorship</div>
        <div style="font-family:Oswald,sans-serif;font-size:20px;text-transform:uppercase;font-weight:700;margin-bottom:8px">Keep The Connection Going</div>
        <?php endif; ?>
        <p style="font-size:13px;color:#A3A3A3;line-height:1.5;margin:0">Your kid already knows this coach from camp. Try a single session or start weekly 1:1 calls — goal setting, film review, and real accountability. Free intro call first. No commitment.</p>
    </div>
    <?php endif; ?>

    <div id="<?php echo $form_id; ?>Form">
        <!-- Honeypot -->
        <div style="position:absolute;left:-9999px"><input type="text" name="website_url" id="<?php echo $form_id; ?>Honey" tabindex="-1" autocomplete="off"></div>
        
        <div style="margin-bottom:12px">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#737373;margin-bottom:4px">Your Name</label>
            <input type="text" id="<?php echo $form_id; ?>ParentName" placeholder="Parent name" style="width:100%;padding:12px 14px;border:2px solid #EAEAE6;border-radius:10px;font-size:16px;font-family:inherit" required>
        </div>
        <div style="margin-bottom:12px">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#737373;margin-bottom:4px">Email</label>
            <input type="email" id="<?php echo $form_id; ?>Email" placeholder="your@email.com" style="width:100%;padding:12px 14px;border:2px solid #EAEAE6;border-radius:10px;font-size:16px;font-family:inherit" required>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
            <div>
                <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#737373;margin-bottom:4px">Player Name</label>
                <input type="text" id="<?php echo $form_id; ?>PlayerName" placeholder="Kid's first name" style="width:100%;padding:12px 14px;border:2px solid #EAEAE6;border-radius:10px;font-size:16px;font-family:inherit" required>
            </div>
            <div>
                <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#737373;margin-bottom:4px">Age</label>
                <input type="number" id="<?php echo $form_id; ?>PlayerAge" placeholder="10" min="5" max="18" style="width:100%;padding:12px 14px;border:2px solid #EAEAE6;border-radius:10px;font-size:16px;font-family:inherit">
            </div>
        </div>
        <div style="margin-bottom:12px">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#737373;margin-bottom:4px">Position</label>
            <input type="text" id="<?php echo $form_id; ?>Position" placeholder="e.g. Center Mid, Striker" style="width:100%;padding:12px 14px;border:2px solid #EAEAE6;border-radius:10px;font-size:16px;font-family:inherit">
        </div>
        <div style="margin-bottom:16px">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#737373;margin-bottom:4px">What do you want your kid to work on?</label>
            <textarea id="<?php echo $form_id; ?>Goals" placeholder="e.g. confidence, first touch, game awareness..." rows="3" style="width:100%;padding:12px 14px;border:2px solid #EAEAE6;border-radius:10px;font-size:16px;font-family:inherit;resize:vertical"></textarea>
        </div>

        <button id="<?php echo $form_id; ?>Btn" onclick="submitInterest_<?php echo $form_id; ?>()" style="width:100%;padding:16px;background:#FCB900;color:#0A0A0A;font-family:Oswald,sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border:none;border-radius:10px;cursor:pointer;transition:all 0.2s">
            Request Free Intro Call
        </button>
        <div style="text-align:center;margin-top:10px;font-size:11px;color:#A3A3A3">No payment required. No commitment.</div>
    </div>

    <div id="<?php echo $form_id; ?>Success" style="display:none;text-align:center;padding:32px 16px">
        <div style="font-size:48px;margin-bottom:12px">&#9989;</div>
        <h3 id="<?php echo $form_id; ?>SuccessMsg" style="font-family:Oswald,sans-serif;text-transform:uppercase;font-size:20px;margin-bottom:8px">You're In!</h3>
        <p style="color:#737373;font-size:14px;line-height:1.5">We'll be in touch to schedule your free intro call. No payment until you and your kid are ready.</p>
    </div>
</div>

<script>
function submitInterest_<?php echo $form_id; ?>() {
    var btn = document.getElementById('<?php echo $form_id; ?>Btn');
    var honey = document.getElementById('<?php echo $form_id; ?>Honey');
    if (honey && honey.value) return;

    var parentName = document.getElementById('<?php echo $form_id; ?>ParentName').value.trim();
    var email = document.getElementById('<?php echo $form_id; ?>Email').value.trim();
    var playerName = document.getElementById('<?php echo $form_id; ?>PlayerName').value.trim();

    if (!parentName || !email || !playerName) {
        alert('Please fill out your name, email, and player name.');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'SUBMITTING...';

    var fd = new FormData();
    fd.append('action', 'ptp_mentorship_interest');
    fd.append('trainer_id', '<?php echo intval($atts['trainer_id']); ?>');
    fd.append('parent_name', parentName);
    fd.append('parent_email', email);
    fd.append('player_name', playerName);
    fd.append('player_age', document.getElementById('<?php echo $form_id; ?>PlayerAge').value || '');
    fd.append('player_position', document.getElementById('<?php echo $form_id; ?>Position').value || '');
    fd.append('goals', document.getElementById('<?php echo $form_id; ?>Goals').value || '');
    fd.append('source', '<?php echo esc_js($atts['source']); ?>');
    fd.append('source_detail', '<?php echo esc_js($atts['source_detail']); ?>');
    fd.append('camp_order_id', '<?php echo intval($atts['camp_order_id']); ?>');

    fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                document.getElementById('<?php echo $form_id; ?>Form').style.display = 'none';
                var msg = document.getElementById('<?php echo $form_id; ?>SuccessMsg');
                msg.textContent = d.data.message || "You're in!";
                document.getElementById('<?php echo $form_id; ?>Success').style.display = 'block';
            } else {
                alert(d.data || 'Something went wrong. Please try again.');
                btn.disabled = false;
                btn.textContent = 'REQUEST FREE INTRO CALL';
            }
        })
        .catch(() => {
            alert('Network error. Please try again.');
            btn.disabled = false;
            btn.textContent = 'REQUEST FREE INTRO CALL';
        });
}
</script>
