<?php
/**
 * Account Settings v85.4 - Uses site header
 */
defined('ABSPATH') || exit;
if (!is_user_logged_in()) { wp_redirect(home_url('/login/')); exit; }

global $wpdb;
$user = wp_get_current_user();
$parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d", get_current_user_id()));
$players = $parent ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d", $parent->id)) : array();

get_header();
?>
<style>
/* PTP styles are scoped under .ptp-account — no need to reset theme header */

.ptp-account{--gold:#FCB900;--black:#0A0A0A;--gray:#F5F5F5;--gray-dark:#525252;--red:#EF4444;--radius:16px;font-family:Inter,-apple-system,sans-serif;background:var(--gray);min-height:80vh;padding:40px 0 120px}
@media(min-width:768px){.ptp-account{padding-bottom:60px}}
.ptp-account h1,.ptp-account h2,.ptp-account h3{font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;line-height:1.1;margin:0}
.ptp-account a{color:inherit;text-decoration:none}
.ptp-account-wrap{max-width:600px;margin:0 auto;padding:0 20px}
.ptp-profile{background:#fff;border-radius:var(--radius);padding:32px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.06);margin-bottom:20px}
.ptp-profile-avatar{width:80px;height:80px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:var(--black)}
.ptp-profile-name{font-size:22px;margin-bottom:4px}
.ptp-profile-email{font-size:14px;color:var(--gray-dark)}
.ptp-card{background:#fff;border-radius:var(--radius);padding:24px;box-shadow:0 4px 20px rgba(0,0,0,.06);margin-bottom:20px}
.ptp-card-title{font-size:14px;margin-bottom:18px;padding-bottom:12px;border-bottom:2px solid var(--gray)}
.ptp-form-row{margin-bottom:16px}
.ptp-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ptp-label{display:block;font-family:Oswald,sans-serif;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-dark);margin-bottom:6px}
.ptp-input,.ptp-select{width:100%;padding:14px;font-size:16px;border:2px solid #e5e5e5;border-radius:10px;transition:.2s;box-sizing:border-box}
.ptp-input:focus,.ptp-select:focus{outline:none;border-color:var(--gold)}
.ptp-save-btn{width:100%;padding:14px;background:var(--gold);color:var(--black);border:none;font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;cursor:pointer;border-radius:10px;margin-top:8px}
.ptp-player{display:flex;align-items:center;gap:12px;padding:12px;background:var(--gray);border-radius:10px;margin-bottom:10px}
.ptp-player-avatar{width:40px;height:40px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:Oswald,sans-serif;font-size:16px;font-weight:700;color:var(--gray-dark)}
.ptp-player-info{flex:1}
.ptp-player-name{font-family:Oswald,sans-serif;font-size:13px;font-weight:600;text-transform:uppercase}
.ptp-player-meta{font-size:11px;color:var(--gray-dark)}
.ptp-add-player{display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;border:2px dashed #ddd;border-radius:10px;color:var(--gray-dark);font-size:13px;font-weight:500;cursor:pointer;transition:.2s}
.ptp-add-player:hover{border-color:var(--gold);color:var(--gold)}
.ptp-logout{display:block;width:100%;padding:14px;background:transparent;border:2px solid var(--red);color:var(--red);font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;text-align:center;border-radius:10px;margin-top:24px;transition:.2s}
.ptp-logout:hover{background:var(--red);color:#fff}
.ptp-mobile-nav{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #e5e5e5;padding:10px 20px;padding-bottom:calc(10px + env(safe-area-inset-bottom,0px));display:flex;justify-content:space-around;z-index:999}
@media(min-width:768px){.ptp-mobile-nav{display:none}}
.ptp-mobile-nav a{display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 12px;color:var(--gray-dark);font-size:10px;font-weight:600;text-transform:uppercase}
.ptp-mobile-nav a.active{color:var(--gold)}
.ptp-mobile-nav a svg{width:22px;height:22px}
</style>

<div class="ptp-account">
<div class="ptp-account-wrap">
    <div class="ptp-profile">
        <div class="ptp-profile-avatar"><?php echo strtoupper(substr($user->first_name ?: $user->display_name, 0, 1) . substr($user->last_name, 0, 1)); ?></div>
        <h2 class="ptp-profile-name"><?php echo esc_html($user->display_name); ?></h2>
        <p class="ptp-profile-email"><?php echo esc_html($user->user_email); ?></p>
    </div>
    
    <div class="ptp-card">
        <h3 class="ptp-card-title">PERSONAL INFO</h3>
        <form id="profileForm" method="post">
            <input type="hidden" name="action" value="ptp_update_profile">
            <div class="ptp-form-row ptp-form-grid">
                <div><label class="ptp-label">First Name</label><input type="text" name="first_name" class="ptp-input" value="<?php echo esc_attr($user->first_name); ?>"></div>
                <div><label class="ptp-label">Last Name</label><input type="text" name="last_name" class="ptp-input" value="<?php echo esc_attr($user->last_name); ?>"></div>
            </div>
            <div class="ptp-form-row"><label class="ptp-label">Email</label><input type="email" name="email" class="ptp-input" value="<?php echo esc_attr($user->user_email); ?>"></div>
            <div class="ptp-form-row"><label class="ptp-label">Phone</label><input type="tel" name="phone" class="ptp-input" value="<?php echo esc_attr(get_user_meta(get_current_user_id(), 'phone', true)); ?>"></div>
            <button type="submit" class="ptp-save-btn">Save Changes</button>
        </form>
    </div>
    
    <div class="ptp-card" id="players">
        <h3 class="ptp-card-title">YOUR PLAYERS</h3>
        <?php foreach($players as $p): ?>
        <div class="ptp-player">
            <div class="ptp-player-avatar"><?php echo strtoupper(substr($p->name, 0, 1)); ?></div>
            <div class="ptp-player-info">
                <div class="ptp-player-name"><?php echo esc_html($p->name); ?></div>
                <div class="ptp-player-meta">Age <?php echo intval($p->age ?: 0); ?> · <?php echo esc_html(ucfirst($p->skill_level ?: 'Beginner')); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="ptp-add-player" onclick="document.getElementById('addPlayerForm').style.display='block';this.style.display='none';">+ Add Player</div>
        <form id="addPlayerForm" method="post" action="<?php echo admin_url('admin-ajax.php'); ?>" style="display:none;margin-top:16px;">
            <input type="hidden" name="action" value="ptp_add_player">
            <?php wp_nonce_field('ptp_ajax_nonce', 'nonce'); ?>
            <div class="ptp-form-row">
                <label class="ptp-label">Player Name</label>
                <input type="text" name="player_name" class="ptp-input" placeholder="e.g. Johnny" required>
            </div>
            <div class="ptp-form-row ptp-form-grid">
                <div><label class="ptp-label">Age</label><input type="number" name="player_age" class="ptp-input" min="4" max="18" required></div>
                <div><label class="ptp-label">Skill Level</label><select name="player_skill" class="ptp-select"><option value="beginner">Beginner</option><option value="intermediate">Intermediate</option><option value="advanced">Advanced</option></select></div>
            </div>
            <button type="submit" class="ptp-save-btn">Add Player</button>
        </form>
    </div>
    
    <a href="<?php echo wp_logout_url(home_url('/')); ?>" class="ptp-logout">Sign Out</a>
</div>

<nav class="ptp-mobile-nav">
    <a href="<?php echo home_url('/my-training/'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="<?php echo home_url('/find-trainers/'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>Find</a>
    <a href="<?php echo home_url('/messaging/'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Chat</a>
    <a href="<?php echo home_url('/account/'); ?>" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Account</a>
</nav>
</div>

<script>
var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
var nonce = '<?php echo wp_create_nonce('ptp_nonce'); ?>';

function showToast(msg) {
    var toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:100px;left:50%;transform:translateX(-50%);background:#0A0A0A;color:#fff;padding:12px 24px;border-radius:8px;font-size:14px;z-index:9999;animation:fadeIn .2s';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 3000);
}

// Profile form AJAX
document.getElementById('profileForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = this.querySelector('button[type="submit"]');
    btn.textContent = 'Saving...';
    btn.disabled = true;
    
    var formData = new FormData(this);
    formData.append('action', 'ptp_update_profile');
    formData.append('nonce', nonce);
    
    fetch(ajaxUrl, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('Profile saved!');
        } else {
            showToast(data.data?.message || 'Error saving profile');
        }
    })
    .catch(function() {
        showToast('Connection error');
    })
    .finally(function() {
        btn.textContent = 'Save Changes';
        btn.disabled = false;
    });
});

// Player form toggle
function togglePlayerForm() {
    var form = document.getElementById('playerForm');
    form.classList.toggle('show');
    if (form.classList.contains('show')) {
        document.querySelector('#addPlayerForm input[name="player_name"]')?.focus();
    }
}

// Add player AJAX
document.getElementById('addPlayerForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Saving...';
    
    var formData = new FormData(this);
    formData.append('nonce', nonce);
    
    fetch(ajaxUrl, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showToast('Player added!');
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            showToast(data.data?.message || 'Error adding player');
            btn.textContent = 'Add Player';
            btn.disabled = false;
        }
    })
    .catch(function() {
        showToast('Connection error');
        btn.textContent = 'Add Player';
        btn.disabled = false;
    });
});

// Phone formatting
document.querySelector('input[name="phone"]')?.addEventListener('input', function(e) {
    var x = e.target.value.replace(/\D/g, '').match(/(\d{0,3})(\d{0,3})(\d{0,4})/);
    e.target.value = !x[2] ? x[1] : '(' + x[1] + ') ' + x[2] + (x[3] ? '-' + x[3] : '');
});
</script>
<?php get_footer(); ?>
