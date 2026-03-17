<?php
/**
 * Trainer Application Page (apply.php)
 * 
 * Renders the /apply form for new trainers.
 * Submits via AJAX to ptp_submit_application.
 * v216.1: Password confirmation + strength indicator. Password IS their permanent login.
 */
defined('ABSPATH') || exit;

$css = PTP_Shortcodes::embed_css();
echo $css;
?>

<div class="ptp-wrap" style="max-width: 640px; margin: 0 auto; padding: 60px 24px;">

    <!-- Header -->
    <div style="text-align: center; margin-bottom: 40px;">
        <div class="ptp-hero-badge" style="margin-bottom: 16px;">JOIN OUR TEAM</div>
        <h1 style="font-size: 36px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 12px !important;">Become a PTP Trainer</h1>
        <p style="color: #6B7280; font-size: 16px; line-height: 1.6;">
            Share your skills. Build your schedule. Earn $50-$100+/hr training youth soccer players in your area.
        </p>
    </div>

    <!-- Success state (hidden by default) -->
    <div id="ptp-apply-success" style="display: none; text-align: center; padding: 60px 0;">
        <div style="width:64px;height:64px;border-radius:50%;background:#22C55E;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2 style="font-size: 28px; font-weight: 800; margin-bottom: 12px !important;">Application Submitted!</h2>
        <p style="color: #6B7280; font-size: 16px; margin-bottom: 8px !important;">
            We'll review your application within 24-48 hours and email you next steps.
        </p>
        <p style="color: #9CA3AF; font-size: 14px; margin-bottom: 24px !important;">
            When you're approved, log in with the email and password you just created. No temporary passwords.
        </p>
        <a href="<?php echo esc_url(home_url('/')); ?>" class="ptp-btn ptp-btn-primary">Back to Home</a>
    </div>

    <!-- Application form -->
    <div id="ptp-apply-form-wrap">
        <div id="ptp-apply-error" class="ptp-alert ptp-alert-error" style="display: none;"></div>

        <form id="ptp-apply-form" style="display: flex; flex-direction: column; gap: 20px;">

            <!-- Name -->
            <div>
                <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px;">Full Name <span style="color: #EF4444;">*</span></label>
                <input type="text" name="name" required
                       style="width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 12px; font-size: 16px; font-family: Inter, sans-serif; outline: none; transition: border-color 0.2s;"
                       onfocus="this.style.borderColor='#FCB900'" onblur="this.style.borderColor='#E5E7EB'"
                       placeholder="Your full name">
            </div>

            <!-- Email -->
            <div>
                <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px;">Email <span style="color: #EF4444;">*</span></label>
                <input type="email" name="email" required
                       style="width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 12px; font-size: 16px; font-family: Inter, sans-serif; outline: none; transition: border-color 0.2s;"
                       onfocus="this.style.borderColor='#FCB900'" onblur="this.style.borderColor='#E5E7EB'"
                       placeholder="your@email.com">
            </div>

            <!-- Phone -->
            <div>
                <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px;">Phone</label>
                <input type="tel" name="phone"
                       style="width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 12px; font-size: 16px; font-family: Inter, sans-serif; outline: none; transition: border-color 0.2s;"
                       onfocus="this.style.borderColor='#FCB900'" onblur="this.style.borderColor='#E5E7EB'"
                       placeholder="(555) 123-4567">
            </div>

            <!-- Playing Level -->
            <div>
                <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px;">Highest Playing Level <span style="color: #EF4444;">*</span></label>
                <select name="playing_level" required
                        style="width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 12px; font-size: 16px; font-family: Inter, sans-serif; background: #fff; outline: none; appearance: auto;">
                    <option value="">Select level...</option>
                    <option value="pro">Professional / MLS</option>
                    <option value="semi_pro">Semi-Pro / USL / NISA</option>
                    <option value="college_d1">NCAA Division I</option>
                    <option value="college_d2">NCAA Division II</option>
                    <option value="college_d3">NCAA Division III / NAIA</option>
                    <option value="academy">Academy / Development Academy</option>
                    <option value="club">Club / High School Varsity</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <!-- College / City -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div>
                    <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px;">College / University</label>
                    <input type="text" name="college"
                           style="width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 12px; font-size: 16px; font-family: Inter, sans-serif; outline: none; transition: border-color 0.2s;"
                           onfocus="this.style.borderColor='#FCB900'" onblur="this.style.borderColor='#E5E7EB'"
                           placeholder="e.g. Penn State">
                </div>
                <div>
                    <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px;">City</label>
                    <input type="text" name="city"
                           style="width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 12px; font-size: 16px; font-family: Inter, sans-serif; outline: none; transition: border-color 0.2s;"
                           onfocus="this.style.borderColor='#FCB900'" onblur="this.style.borderColor='#E5E7EB'"
                           placeholder="Your city">
                </div>
            </div>

            <!-- State -->
            <div>
                <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px;">State <span style="color: #EF4444;">*</span></label>
                <select name="state" required
                        style="width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 12px; font-size: 16px; font-family: Inter, sans-serif; background: #fff; outline: none; appearance: auto;">
                    <option value="">Select state...</option>
                    <option value="PA">Pennsylvania</option>
                    <option value="NJ">New Jersey</option>
                    <option value="DE">Delaware</option>
                    <option value="MD">Maryland</option>
                    <option value="NY">New York</option>
                    <option value="CT">Connecticut</option>
                    <option value="VA">Virginia</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <!-- Experience -->
            <div>
                <label style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px;">Tell us about your coaching / playing experience</label>
                <textarea name="experience" rows="4"
                          style="width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 12px; font-size: 16px; font-family: Inter, sans-serif; outline: none; resize: vertical; transition: border-color 0.2s;"
                          onfocus="this.style.borderColor='#FCB900'" onblur="this.style.borderColor='#E5E7EB'"
                          placeholder="Years playing, teams, coaching experience, certifications..."></textarea>
            </div>

            <?php if (!is_user_logged_in()): ?>
            <!-- Password Section -->
            <div style="background:#F9FAFB;border:2px solid #E5E7EB;border-radius:16px;padding:20px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                    <div style="width:32px;height:32px;border-radius:8px;background:#0A0A0A;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <div>
                        <p style="margin:0;font-weight:700;font-size:15px;color:#0A0A0A;">Create Your Login Password</p>
                        <p style="margin:0;font-size:12px;color:#6B7280;">This is your permanent password. No temporary passwords will be sent.</p>
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">Password <span style="color: #EF4444;">*</span></label>
                    <input type="password" name="password" id="ptp-pw" required minlength="8"
                           style="width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 12px; font-size: 16px; font-family: Inter, sans-serif; outline: none; transition: border-color 0.2s;"
                           onfocus="this.style.borderColor='#FCB900'" onblur="this.style.borderColor='#E5E7EB'"
                           placeholder="At least 8 characters">
                    <div id="ptp-pw-strength" style="margin-top:8px;display:none;">
                        <div style="display:flex;gap:4px;margin-bottom:4px;">
                            <div id="pw-bar-1" style="flex:1;height:4px;border-radius:2px;background:#E5E7EB;transition:background 0.3s;"></div>
                            <div id="pw-bar-2" style="flex:1;height:4px;border-radius:2px;background:#E5E7EB;transition:background 0.3s;"></div>
                            <div id="pw-bar-3" style="flex:1;height:4px;border-radius:2px;background:#E5E7EB;transition:background 0.3s;"></div>
                            <div id="pw-bar-4" style="flex:1;height:4px;border-radius:2px;background:#E5E7EB;transition:background 0.3s;"></div>
                        </div>
                        <p id="pw-label" style="margin:0;font-size:11px;color:#9CA3AF;"></p>
                    </div>
                </div>

                <div>
                    <label style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px;">Confirm Password <span style="color: #EF4444;">*</span></label>
                    <input type="password" name="password_confirm" id="ptp-pw-confirm" required minlength="8"
                           style="width: 100%; padding: 14px 16px; border: 2px solid #E5E7EB; border-radius: 12px; font-size: 16px; font-family: Inter, sans-serif; outline: none; transition: border-color 0.2s;"
                           onfocus="this.style.borderColor='#FCB900'" onblur="this.style.borderColor='#E5E7EB'"
                           placeholder="Re-enter password">
                    <p id="ptp-pw-match" style="font-size:12px;margin-top:6px !important;display:none;"></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Submit -->
            <button type="submit" id="ptp-apply-submit" class="ptp-btn ptp-btn-primary ptp-btn-lg" style="width: 100%; margin-top: 8px;">
                Submit Application
            </button>

            <p style="text-align: center; font-size: 13px; color: #9CA3AF;">
                Already applied? <a href="<?php echo esc_url(home_url('/login/')); ?>" style="color: #FCB900 !important; font-weight: 600;">Log in here</a>
            </p>
        </form>
    </div>
</div>

<script>
(function() {
    var form = document.getElementById('ptp-apply-form');
    if (!form) return;

    var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
    var currentNonce = '<?php echo wp_create_nonce("ptp_nonce"); ?>';

    // Password strength meter
    var pwInput = document.getElementById('ptp-pw');
    var pwConfirm = document.getElementById('ptp-pw-confirm');
    if (pwInput) {
        pwInput.addEventListener('input', function() {
            var pw = this.value;
            var meter = document.getElementById('ptp-pw-strength');
            meter.style.display = pw.length > 0 ? 'block' : 'none';
            var score = 0;
            if (pw.length >= 8) score++;
            if (pw.length >= 12) score++;
            if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
            if (/\d/.test(pw)) score++;
            if (/[^A-Za-z0-9]/.test(pw)) score++;
            var level = Math.min(4, Math.max(1, Math.ceil(score * 4 / 5)));
            var colors = ['#EF4444','#F59E0B','#FCB900','#22C55E'];
            var labels = ['Weak','Fair','Good','Strong'];
            for (var i = 1; i <= 4; i++) {
                document.getElementById('pw-bar-' + i).style.background = i <= level ? colors[level-1] : '#E5E7EB';
            }
            document.getElementById('pw-label').textContent = labels[level-1];
            document.getElementById('pw-label').style.color = colors[level-1];
        });
    }
    if (pwConfirm) {
        pwConfirm.addEventListener('input', function() {
            var match = document.getElementById('ptp-pw-match');
            var pw = document.getElementById('ptp-pw').value;
            if (this.value.length === 0) { match.style.display = 'none'; return; }
            match.style.display = 'block';
            if (this.value === pw) {
                match.textContent = 'Passwords match';
                match.style.color = '#22C55E';
                this.style.borderColor = '#22C55E';
            } else {
                match.textContent = 'Passwords do not match';
                match.style.color = '#EF4444';
                this.style.borderColor = '#EF4444';
            }
        });
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var btn = document.getElementById('ptp-apply-submit');
        var errorEl = document.getElementById('ptp-apply-error');
        errorEl.style.display = 'none';

        // Validate password match
        var pw = form.querySelector('[name="password"]');
        var pwc = form.querySelector('[name="password_confirm"]');
        if (pw && pwc && pw.value !== pwc.value) {
            errorEl.innerHTML = 'Passwords do not match.';
            errorEl.style.display = 'block';
            pwc.focus();
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Submitting...';
        submitForm(currentNonce);
    });

    function submitForm(nonce) {
        var btn = document.getElementById('ptp-apply-submit');
        var errorEl = document.getElementById('ptp-apply-error');
        var data = new FormData(form);
        data.append('action', 'ptp_submit_application');
        data.append('nonce', nonce);
        data.delete('password_confirm');

        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
        .then(function(r) {
            if (!r.ok && r.status === 403) return refreshNonceAndRetry();
            return r.json();
        })
        .then(function(res) {
            if (!res) return;
            if (res.success) {
                document.getElementById('ptp-apply-form-wrap').style.display = 'none';
                document.getElementById('ptp-apply-success').style.display = 'block';
                window.scrollTo({ top: 0, behavior: 'smooth' });
                if (res.data && res.data.redirect) {
                    setTimeout(function() { window.location.href = res.data.redirect; }, 3000);
                }
            } else {
                errorEl.innerHTML = res.data?.message || 'Something went wrong. Please try again.';
                errorEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Submit Application';
            }
        })
        .catch(function(err) {
            errorEl.textContent = 'Something went wrong. Please refresh the page and try again.';
            errorEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Submit Application';
        });
    }

    function refreshNonceAndRetry() {
        var nd = new FormData();
        nd.append('action', 'ptp_refresh_nonce');
        return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: nd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.data.nonce) {
                    currentNonce = res.data.nonce;
                    submitForm(currentNonce);
                    return null;
                } else { throw new Error('Could not refresh session'); }
            });
    }
})();
</script>
