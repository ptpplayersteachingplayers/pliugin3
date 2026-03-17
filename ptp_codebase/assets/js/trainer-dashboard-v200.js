
var STATE = { currentTab: 'home', isRefreshing: false, pullDistance: 0 };

var MORE_TABS = ['insights', 'reviews', 'mentorship', 'profile'];
function switchTab(tab) {
    // v233 M6: Lazy-load mentorship data on first tab click
    if (tab === 'mentorship' && !window._mentorDataLoaded) {
        var url = new URL(window.location.href);
        if (url.searchParams.get('tab') !== 'mentorship') {
            url.searchParams.set('tab', 'mentorship');
            window.location.href = url.toString();
            return;
        }
    }
    STATE.currentTab = tab;
    document.querySelectorAll('.nav-item').forEach(function(el) {
        var isActive = el.dataset.tab === tab || (el.dataset.tab === 'more' && MORE_TABS.indexOf(tab) !== -1);
        el.classList.toggle('active', isActive);
        el.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    document.querySelectorAll('.tab-panel').forEach(function(el) { el.classList.toggle('active', el.dataset.tab === tab); });
    document.getElementById('appContent').scrollTop = 0;
    triggerHaptic('light');
}

function openMoreSheet() {
    document.getElementById('moreOverlay').classList.add('open');
    document.getElementById('moreSheet').classList.add('open');
    triggerHaptic('light');
}
function closeMoreSheet() {
    document.getElementById('moreOverlay').classList.remove('open');
    document.getElementById('moreSheet').classList.remove('open');
}

// Pull to refresh
(function() {
    var content = document.getElementById('appContent');
    var indicator = document.getElementById('ptrIndicator');
    var startY = 0, pulling = false;
    content.addEventListener('touchstart', function(e) { if (content.scrollTop === 0) { startY = e.touches[0].pageY; pulling = true; } }, { passive: true });
    content.addEventListener('touchmove', function(e) {
        if (!pulling || STATE.isRefreshing) return;
        var diff = e.touches[0].pageY - startY;
        if (diff > 0 && content.scrollTop === 0) {
            STATE.pullDistance = Math.min(diff * 0.4, 80);
            if (STATE.pullDistance > 20) { indicator.classList.add('visible'); indicator.style.top = (STATE.pullDistance - 30) + 'px'; }
        }
    }, { passive: true });
    content.addEventListener('touchend', function() {
        if (STATE.pullDistance > 60 && !STATE.isRefreshing) {
            STATE.isRefreshing = true;
            indicator.classList.add('refreshing');
            triggerHaptic('medium');
            setTimeout(function() { location.reload(); }, 800);
        } else {
            indicator.classList.remove('visible');
        }
        pulling = false;
        STATE.pullDistance = 0;
    });
})();

function triggerHaptic(style) {
    if ('vibrate' in navigator) {
        var p = { light: 10, medium: 20, heavy: 30, success: [10, 50, 10], error: [30, 50, 30] };
        navigator.vibrate(p[style] || 10);
    }
}

function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show' + (type ? ' ' + type : '');
    triggerHaptic(type === 'success' ? 'success' : type === 'error' ? 'error' : 'light');
    setTimeout(function() { t.classList.remove('show'); }, 3000);
}

function confirmSession(id, btn) {
    btn.disabled = true; btn.textContent = '...';
    // v216: Use escrow endpoint which properly handles payment release
    // Falls back gracefully for legacy bookings without escrow records
    fetch(CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=ptp_trainer_complete_session&booking_id=' + id + '&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            triggerHaptic('success'); showToast(res.data && res.data.message ? res.data.message : 'Session confirmed! Payment processing.', 'success');
            var item = btn.closest('.confirm-item');
            if (item) { item.style.transform = 'translateX(100%)'; item.style.opacity = '0'; setTimeout(function() { item.remove(); var banner = document.getElementById('confirmBanner'); if (banner && banner.querySelectorAll('.confirm-item').length === 0) banner.style.display = 'none'; }, 300); }
        } else {
            // Fallback: try the simple confirm endpoint for legacy bookings
            fetch(CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=ptp_confirm_session&booking_id=' + id + '&nonce=' + CONFIG.nonceGeneral })
            .then(function(r2) { return r2.json(); })
            .then(function(res2) {
                if (res2.success) {
                    triggerHaptic('success'); showToast('Session confirmed!', 'success');
                    var item = btn.closest('.confirm-item');
                    if (item) { item.style.transform = 'translateX(100%)'; item.style.opacity = '0'; setTimeout(function() { item.remove(); var banner = document.getElementById('confirmBanner'); if (banner && banner.querySelectorAll('.confirm-item').length === 0) banner.style.display = 'none'; }, 300); }
                } else {
                    showToast(res2.data && res2.data.message ? res2.data.message : 'Error confirming', 'error');
                    btn.disabled = false; btn.textContent = 'Confirm';
                }
            }).catch(function() { showToast('Connection error', 'error'); btn.disabled = false; btn.textContent = 'Confirm'; });
        }
    }).catch(function() { showToast('Connection error', 'error'); btn.disabled = false; btn.textContent = 'Confirm'; });
}

function toggleDay(toggle) {
    var day = toggle.dataset.day;
    var isOn = toggle.classList.toggle('on');
    document.getElementById('start_' + day).disabled = !isOn;
    document.getElementById('end_' + day).disabled = !isOn;
    triggerHaptic('light');
}

// v235.8: One-tap availability presets for dashboard
function dashAvailPreset(preset) {
    var presets = {
        weekday_eve: { days: ['monday','tuesday','wednesday','thursday','friday'], start: '16:00', end: '20:00' },
        weekends: { days: ['saturday','sunday'], start: '09:00', end: '17:00' },
        all: { days: ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'], start: '16:00', end: '20:00' }
    };
    var p = presets[preset];
    if (!p) return;
    ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'].forEach(function(day) {
        var toggle = document.querySelector('.avail-toggle[data-day="' + day + '"]');
        var startEl = document.getElementById('start_' + day);
        var endEl = document.getElementById('end_' + day);
        if (!toggle || !startEl || !endEl) return;
        var shouldEnable = p.days.indexOf(day) !== -1;
        if (shouldEnable) {
            toggle.classList.add('on');
            startEl.disabled = false; endEl.disabled = false;
            startEl.value = p.start; endEl.value = p.end;
        } else {
            toggle.classList.remove('on');
            startEl.disabled = true; endEl.disabled = true;
        }
    });
    triggerHaptic('light');
    showToast('Preset applied — hit Save', 'success');
}

function saveAvailability() {
    var btn = document.getElementById('saveAvailBtn'); btn.disabled = true; btn.textContent = 'Saving...';
    var dayMap = { monday: 1, tuesday: 2, wednesday: 3, thursday: 4, friday: 5, saturday: 6, sunday: 0 };
    var days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    var promises = days.map(function(day) {
        var toggle = document.querySelector('.avail-toggle[data-day="' + day + '"]');
        var enabled = toggle.classList.contains('on') ? '1' : '0';
        var start = document.getElementById('start_' + day).value || '09:00';
        var end = document.getElementById('end_' + day).value || '17:00';
        return fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=ptp_save_trainer_schedule&day=' + dayMap[day] + '&enabled=' + enabled + '&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end) + '&nonce=' + CONFIG.nonceGeneral });
    });
    Promise.all(promises).then(function(responses) { return Promise.all(responses.map(function(r) { return r.json(); })); })
    .then(function(results) {
        var failed = results.filter(function(r) { return !r.success; });
        if (failed.length === 0) { triggerHaptic('success'); showToast('Availability saved!', 'success'); }
        else { showToast('Some days failed to save', 'error'); }
        btn.disabled = false; btn.textContent = 'Save';
    }).catch(function() { showToast('Connection error', 'error'); btn.disabled = false; btn.textContent = 'Save'; });
}

var payoutSheetOpenedAt = 0;
function openPayoutSheet() {
    payoutSheetOpenedAt = Date.now();
    var overlay = document.getElementById('payoutSheetOverlay');
    var sheet = document.getElementById('payoutSheet');
    overlay.style.display = 'block';
    sheet.style.display = 'flex';
    sheet.offsetHeight; // force reflow before animation
    overlay.classList.add('open');
    sheet.classList.add('open');
    document.body.style.overflow = 'hidden'; // v225: prevent background scroll on mobile
    triggerHaptic('light');
}
function closePayoutSheet(force) {
    if (!force && Date.now() - payoutSheetOpenedAt < 400) return;
    var overlay = document.getElementById('payoutSheetOverlay');
    var sheet = document.getElementById('payoutSheet');
    overlay.classList.remove('open');
    sheet.classList.remove('open');
    document.body.style.overflow = ''; // v225: restore scroll
    setTimeout(function() { overlay.style.display = ''; sheet.style.display = ''; }, 350);
}
var blockDateSheetOpenedAt = 0;
function openBlockDateSheet() {
    blockDateSheetOpenedAt = Date.now();
    var overlay = document.getElementById('blockDateSheetOverlay');
    var sheet = document.getElementById('blockDateSheet');
    document.getElementById('blockDate').value = '';
    document.getElementById('blockReason').value = '';
    overlay.style.display = 'block';
    sheet.style.display = 'flex';
    sheet.offsetHeight;
    overlay.classList.add('open');
    sheet.classList.add('open');
    document.body.style.overflow = 'hidden'; // v225: prevent background scroll
    triggerHaptic('light');
}
function closeBlockDateSheet(force) {
    if (!force && Date.now() - blockDateSheetOpenedAt < 400) return;
    var overlay = document.getElementById('blockDateSheetOverlay');
    var sheet = document.getElementById('blockDateSheet');
    overlay.classList.remove('open');
    sheet.classList.remove('open');
    document.body.style.overflow = ''; // v225: restore scroll
    setTimeout(function() { overlay.style.display = ''; sheet.style.display = ''; }, 350);
}

function requestPayout() {
    var btn = document.getElementById('payoutBtn'); btn.disabled = true; btn.textContent = 'Processing...';
    fetch(CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=ptp_request_payout&trainer_id=' + CONFIG.trainerId + '&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) {
        // v225: Detect expired nonce (WP returns 403 or 0 for bad nonce)
        if (r.status === 403 || r.status === 401) {
            showToast('Session expired — refreshing page...', 'error');
            setTimeout(function() { location.reload(); }, 1500);
            return null;
        }
        return r.json();
    })
    .then(function(res) {
        if (!res) return; // handled above
        if (res.success) { triggerHaptic('success'); showToast('Payout requested!', 'success'); closePayoutSheet(true); setTimeout(function() { location.reload(); }, 1500); }
        else {
            var msg = res.data && res.data.message ? res.data.message : 'Error';
            // v225: Detect nonce failure in response body
            if (msg.indexOf('nonce') !== -1 || msg.indexOf('expired') !== -1 || res.data === '0' || res.data === '-1') {
                showToast('Session expired — refreshing...', 'error');
                setTimeout(function() { location.reload(); }, 1500);
                return;
            }
            showToast(msg, 'error'); btn.disabled = false; btn.textContent = 'Confirm Payout';
        }
    }).catch(function() {
        showToast('No connection — check your signal and try again.', 'error');
        btn.disabled = false; btn.textContent = 'Confirm Payout';
    });
}

function saveBlockedDate() {
    var date = document.getElementById('blockDate').value, reason = document.getElementById('blockReason').value;
    if (!date) { showToast('Please select a date', 'error'); return; }
    fetch(CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=ptp_block_date&date=' + date + '&reason=' + encodeURIComponent(reason) + '&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) { return r.json(); })
    .then(function(res) { if (res.success) { triggerHaptic('success'); showToast('Date blocked!', 'success'); closeBlockDateSheet(true); location.reload(); } else { showToast(res.data && res.data.message ? res.data.message : 'Error', 'error'); } })
    .catch(function() { showToast('Connection error', 'error'); });
}

function removeBlockedDate(date) {
    if (!confirm('Remove this blocked date?')) return;
    fetch(CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=ptp_unblock_date&date=' + date + '&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) { return r.json(); })
    .then(function(res) { if (res.success) { triggerHaptic('success'); showToast('Date unblocked', 'success'); location.reload(); } else { showToast('Error', 'error'); } });
}

function saveProfile() {
    var btn = event.target; btn.disabled = true; btn.textContent = 'Saving...';
    var params = 'action=ptp_save_trainer_profile&nonce=' + CONFIG.nonceGeneral
        + '&hourly_rate=' + encodeURIComponent(document.getElementById('hourlyRate').value)
        + '&headline=' + encodeURIComponent(document.getElementById('headline').value)
        + '&bio=' + encodeURIComponent(document.getElementById('bio').value)
        + '&training_philosophy=' + encodeURIComponent(document.getElementById('trainingPhilosophy').value)
        + '&coaching_why=' + encodeURIComponent(document.getElementById('coachingWhy').value)
        + '&team=' + encodeURIComponent(document.getElementById('team').value)
        + '&playing_level=' + encodeURIComponent(document.getElementById('playingLevel').value)
        + '&experience_years=' + encodeURIComponent(document.getElementById('experienceYears').value)
        + '&years_coaching=' + encodeURIComponent(document.getElementById('yearsCoaching').value)
        + '&specialties=' + encodeURIComponent(document.getElementById('specialties').value)
        + '&travel_radius=' + encodeURIComponent(document.getElementById('travelRadius').value)
        + '&city=' + encodeURIComponent(document.getElementById('city').value)
        + '&state=' + encodeURIComponent(document.getElementById('stateSelect').value);
    fetch(CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) { triggerHaptic('success'); showToast('Profile updated!', 'success'); }
        else { showToast(res.data && res.data.message ? res.data.message : 'Error', 'error'); }
        btn.disabled = false; btn.textContent = 'Save Changes';
    }).catch(function() { showToast('Connection error', 'error'); btn.disabled = false; btn.textContent = 'Save Changes'; });
}

function goToMentorshipSettings() {
    // Switch to Profile tab, then scroll to mentorship section
    var profileTab = document.querySelector('.tab-btn[data-tab="profile"]');
    if (profileTab) profileTab.click();
    setTimeout(function() {
        var mentorCard = document.getElementById('mentorshipEnabled');
        if (mentorCard) {
            mentorCard.closest('.card') && mentorCard.closest('.card').scrollIntoView({behavior:'smooth', block:'start'});
        }
    }, 200);
}

function ptpCopyMentorLink(btn) {
    var url = btn.getAttribute('data-url');
    if (!url) return;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            btn.style.background = '#22C55E';
            setTimeout(function(){ btn.textContent = orig; btn.style.background = ''; }, 2000);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        btn.textContent = 'Copied!';
        setTimeout(function(){ btn.textContent = 'Copy'; }, 2000);
    }
}

// ═══ Mentorship Incoming Request Management ═══
function mentorshipMarkIntroScheduled(pairId, btn) {
    if (!confirm('Mark this request as intro scheduled? Make sure you\'ve reached out to the parent.')) return;
    btn.disabled = true; btn.textContent = 'UPDATING...';
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin',
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_mentorship_schedule_intro&nonce=' + CONFIG.nonceGeneral + '&pair_id=' + pairId
    }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.success) { showToast('Intro scheduled!', 'success'); setTimeout(function(){ location.reload(); }, 800); }
        else { showToast(res.data || 'Error', 'error'); btn.disabled = false; btn.textContent = 'SCHEDULE INTRO'; }
    }).catch(function() { showToast('Connection error', 'error'); btn.disabled = false; btn.textContent = 'SCHEDULE INTRO'; });
}

var completeIntroPairId = 0;
function mentorshipCompleteIntro(pairId) {
    completeIntroPairId = pairId;
    document.getElementById('introNotes').value = '';
    document.getElementById('introRecPkg').value = 'development';
    var overlay = document.getElementById('introCompleteOverlay');
    var sheet = document.getElementById('introCompleteSheet');
    overlay.style.display = 'block'; sheet.style.display = 'flex';
    sheet.offsetHeight;
    overlay.classList.add('open'); sheet.classList.add('open');
    triggerHaptic('light');
    setTimeout(function(){ document.getElementById('introNotes').focus(); }, 300);
}
function closeIntroCompleteSheet(force) {
    var overlay = document.getElementById('introCompleteOverlay');
    var sheet = document.getElementById('introCompleteSheet');
    overlay.classList.remove('open'); sheet.classList.remove('open');
    setTimeout(function(){ overlay.style.display = ''; sheet.style.display = ''; }, 350);
}
function submitIntroComplete() {
    var notes = document.getElementById('introNotes').value.trim();
    var pkg = document.getElementById('introRecPkg').value;
    var btn = document.getElementById('introCompleteBtn');
    btn.disabled = true; btn.textContent = 'SUBMITTING...';
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin',
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_mentorship_complete_intro&nonce=' + CONFIG.nonceGeneral
            + '&pair_id=' + completeIntroPairId
            + '&notes=' + encodeURIComponent(notes)
            + '&recommended_package=' + encodeURIComponent(pkg)
    }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.success) {
            showToast('Intro completed! Parent will receive checkout link.', 'success');
            closeIntroCompleteSheet(true);
            setTimeout(function(){ location.reload(); }, 1000);
        } else { showToast(res.data || 'Error', 'error'); btn.disabled = false; btn.textContent = 'COMPLETE INTRO'; }
    }).catch(function() { showToast('Connection error', 'error'); btn.disabled = false; btn.textContent = 'COMPLETE INTRO'; });
}

function saveMentorshipSettings() {
    var btn = document.getElementById('saveMentorBtn');
    btn.disabled = true; btn.textContent = 'Saving...';
    var enabled = document.getElementById('mentorshipEnabled').checked ? 1 : 0;
    var bio     = document.getElementById('mentorshipBio').value;
    var maxM    = document.getElementById('mentorshipMaxMentees').value;
    var zoom    = document.getElementById('mentorshipZoomEmail').value;
    var pkgs    = Array.from(document.querySelectorAll('input[name="mentorPkg"]:checked')).map(function(cb) { return cb.value; }).join(',');
    if (!pkgs) { showToast('Select at least one package', 'error'); btn.disabled = false; btn.textContent = 'Save Mentorship Settings'; return; }
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin',
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_save_mentorship_settings&nonce=' + CONFIG.nonceGeneral
            + '&mentorship_enabled=' + enabled
            + '&mentorship_bio=' + encodeURIComponent(bio)
            + '&mentorship_max_mentees=' + encodeURIComponent(maxM)
            + '&mentorship_packages=' + encodeURIComponent(pkgs)
            + '&zoom_email=' + encodeURIComponent(zoom)
    }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.success) { showToast('Mentorship settings saved!', 'success'); }
        else { showToast(res.data || 'Error saving', 'error'); }
        btn.disabled = false; btn.textContent = 'Save Mentorship Settings';
    }).catch(function() { showToast('Connection error', 'error'); btn.disabled = false; btn.textContent = 'Save Mentorship Settings'; });
}

function connectStripe() {
    showToast('Connecting to Stripe...');
    fetch(CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=ptp_stripe_connect_start&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) {
        if (r.status === 403 || r.status === 401) {
            showToast('Session expired — refreshing...', 'error');
            setTimeout(function() { location.reload(); }, 1500);
            return null;
        }
        if (!r.ok) throw new Error('Server error');
        return r.json();
    })
    .then(function(data) {
        if (!data) return;
        if (data.success && data.data && (data.data.connect_url || data.data.url)) { showToast('Redirecting to Stripe...'); window.location.href = data.data.connect_url || data.data.url; }
        else { showToast(data.data && data.data.message ? data.data.message : 'Could not connect to Stripe. Try refreshing the page.', 'error'); }
    }).catch(function() { showToast('No connection — check your signal and try again.', 'error'); });
}

function handleProfileAction(action) {
    switch (action) {
        case 'photo': openPhotoUpload(); break;
        case 'bio': switchTab('profile'); setTimeout(function() { document.getElementById('bio').focus(); }, 300); break;
        case 'stripe': connectStripe(); break;
        case 'availability': switchTab('schedule'); break;
        case 'locations': switchTab('profile'); setTimeout(function() { var el = document.getElementById('dashLocInput'); if (el) { el.scrollIntoView({behavior:'smooth',block:'center'}); el.focus(); } }, 400); break;
        case 'experience': window.location.href = CONFIG.onboardingUrl; break;
    }
}

function openPhotoUpload() { var input = document.createElement('input'); input.type = 'file'; input.accept = 'image/*'; input.onchange = function(e) { var file = e.target.files[0]; if (file) uploadPhoto(file); }; input.click(); }

function uploadPhoto(file) {
    showToast('Uploading photo...');
    var formData = new FormData(); formData.append('action', 'ptp_upload_trainer_photo'); formData.append('nonce', CONFIG.noncePhoto); formData.append('trainer_id', CONFIG.trainerId); formData.append('photo', file);
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(res) { if (res.success) { triggerHaptic('success'); showToast('Photo updated!', 'success'); location.reload(); } else { showToast(res.data && res.data.message ? res.data.message : 'Upload failed', 'error'); } })
    .catch(function() { showToast('Upload error', 'error'); });
}

function openConversation(id) { window.location.href = CONFIG.messagesUrl + '?conversation=' + id; }

function copyProfileLink() {
    var input = document.getElementById('shareUrl'); input.select(); input.setSelectionRange(0, 99999);
    if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(function() { triggerHaptic('success'); showToast('Link copied!', 'success'); }); }
    else { document.execCommand('copy'); triggerHaptic('success'); showToast('Link copied!', 'success'); }
}

function logout() { if (confirm('Are you sure you want to log out?')) { window.location.href = CONFIG.logoutUrl; } }

/* ═══ REVIEWS ═══ */
var currentReplyId = null;

var replySheetOpenedAt = 0;
function openReplySheet(reviewId, parentName, rating) {
    replySheetOpenedAt = Date.now();
    currentReplyId = reviewId;
    document.getElementById('replyReviewId').value = reviewId;
    document.getElementById('replyName').textContent = parentName;
    var words = parentName.split(' ');
    document.getElementById('replyAvatar').textContent = (words[0][0] + (words[1] ? words[1][0] : '')).toUpperCase();
    document.getElementById('replyStars').textContent = '★'.repeat(rating) + '☆'.repeat(5 - rating);
    document.getElementById('replyText').value = '';
    document.getElementById('replyCharCount').textContent = '0';
    var overlay = document.getElementById('replyOverlay');
    var sheet = document.getElementById('replySheet');
    overlay.style.display = 'block';
    sheet.style.display = 'flex';
    sheet.offsetHeight;
    overlay.classList.add('open');
    sheet.classList.add('open');
    triggerHaptic('light');
    setTimeout(function() { document.getElementById('replyText').focus(); }, 300);
}

function closeReplySheet(force) {
    if (!force && Date.now() - replySheetOpenedAt < 400) return;
    var overlay = document.getElementById('replyOverlay');
    var sheet = document.getElementById('replySheet');
    overlay.classList.remove('open');
    sheet.classList.remove('open');
    currentReplyId = null;
    setTimeout(function() { overlay.style.display = ''; sheet.style.display = ''; }, 350);
}

// Fix #15: Resize reply sheet when iOS keyboard opens
if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', function() {
        var sheet = document.getElementById('replySheet');
        if (sheet && sheet.classList.contains('open')) {
            sheet.style.maxHeight = window.visualViewport.height + 'px';
        }
    });
    window.visualViewport.addEventListener('resize', function() {
        var recapSheet = document.getElementById('recapSheet');
        if (recapSheet && recapSheet.classList.contains('open')) {
            recapSheet.style.maxHeight = window.visualViewport.height + 'px';
        }
    });
}

document.getElementById('replyText').addEventListener('input', function() {
    document.getElementById('replyCharCount').textContent = this.value.length;
});

function submitReply() {
    var text = document.getElementById('replyText').value.trim();
    if (!text) { showToast('Please enter a reply', 'error'); return; }
    if (!currentReplyId) return;

    var btn = document.getElementById('replySubmitBtn');
    btn.disabled = true; btn.textContent = 'SUBMITTING...';

    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin',
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_trainer_reply_review&review_id=' + currentReplyId + '&reply=' + encodeURIComponent(text) + '&nonce=' + CONFIG.nonceGeneral
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false; btn.textContent = 'SUBMIT REPLY';
        if (res.success) {
            var savedId = currentReplyId; // save before close nulls it
            triggerHaptic('success');
            showToast('Reply posted!', 'success');
            closeReplySheet(true);
            // Update the card inline
            var card = document.getElementById('rev-' + savedId);
            if (card) {
                card.setAttribute('data-replied', '1');
                card.classList.remove('rev-new');
                var replyBtn = card.querySelector('.rev-reply-btn');
                if (replyBtn) {
                    var replyDiv = document.createElement('div');
                    replyDiv.className = 'rev-reply';
                    replyDiv.innerHTML = '<div class="rev-reply-hd">Your Response</div><div class="rev-reply-txt">' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
                    replyBtn.replaceWith(replyDiv);
                }
            }
        } else {
            showToast(res.data || 'Failed to post reply', 'error');
        }
    })
    .catch(function() { btn.disabled = false; btn.textContent = 'SUBMIT REPLY'; showToast('Connection error', 'error'); });
}

function filterReviews(filter) {
    document.querySelectorAll('.rev-filter').forEach(function(el) {
        el.classList.toggle('active', el.dataset.filter === filter);
    });
    var visibleCount = 0;
    document.querySelectorAll('.rev-card').forEach(function(card) {
        var rating = card.dataset.rating;
        var replied = card.dataset.replied;
        var show = true;
        if (filter === 'unreplied') show = replied === '0';
        else if (filter === '5') show = rating === '5';
        else if (filter === '4') show = rating === '4';
        else if (filter === 'low') show = parseInt(rating) <= 3;
        card.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    // Show/hide empty state for filtered results
    var emptyEl = document.getElementById('rev-filter-empty');
    if (emptyEl) {
        emptyEl.style.display = visibleCount === 0 ? 'block' : 'none';
        if (visibleCount === 0 && filter === 'unreplied') {
            emptyEl.innerHTML = '<div style="text-align:center;padding:32px 16px;color:var(--text-muted)"><div style="font-size:32px;margin-bottom:8px">🎉</div><div style="font-weight:600;font-size:14px">All caught up!</div><div style="font-size:13px;margin-top:4px">You\'ve replied to all reviews.</div></div>';
        } else if (visibleCount === 0) {
            emptyEl.innerHTML = '<div style="text-align:center;padding:32px 16px;color:var(--text-muted)"><div style="font-size:14px">No reviews match this filter.</div></div>';
        }
    }
    triggerHaptic('light');
}

// ═══ MENTORSHIP FUNCTIONS ═══

var videoReviewOpenedAt = 0;
function openVideoReview(videoId, videoUrl) {
    videoReviewOpenedAt = Date.now();
    document.getElementById('videoReviewId').value = videoId;
    document.getElementById('videoFeedbackText').value = '';
    document.getElementById('videoReviewRating').value = '4';
    setVideoEffort(4);
    var overlay = document.getElementById('videoReviewOverlay');
    var sheet = document.getElementById('videoReviewSheet');
    overlay.style.display = 'block';
    sheet.style.display = 'flex';
    sheet.offsetHeight;
    overlay.classList.add('open');
    sheet.classList.add('open');
    triggerHaptic('light');
    setTimeout(function() { document.getElementById('videoFeedbackText').focus(); }, 300);
}
function closeVideoReviewSheet(force) {
    if (!force && Date.now() - videoReviewOpenedAt < 400) return;
    var overlay = document.getElementById('videoReviewOverlay');
    var sheet = document.getElementById('videoReviewSheet');
    overlay.classList.remove('open');
    sheet.classList.remove('open');
    setTimeout(function() { overlay.style.display = ''; sheet.style.display = ''; }, 350);
}
function setVideoEffort(val) {
    document.getElementById('videoReviewRating').value = val;
    document.querySelectorAll('#videoEffortStars .recap-star').forEach(function(s) {
        s.classList.toggle('active', parseInt(s.dataset.val) <= val);
    });
    triggerHaptic('light');
}
function submitVideoReviewSheet() {
    var feedback = document.getElementById('videoFeedbackText').value.trim();
    if (!feedback) { showToast('Please enter feedback', 'error'); return; }
    var videoId = document.getElementById('videoReviewId').value;
    var rating = document.getElementById('videoReviewRating').value;
    var btn = document.getElementById('videoReviewSubmitBtn');
    btn.disabled = true; btn.textContent = 'SUBMITTING...';
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_mentorship_review_video&nonce=' + CONFIG.nonceGeneral +
              '&video_id=' + videoId + '&feedback=' + encodeURIComponent(feedback) +
              '&rating=' + (parseInt(rating) || 4) })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false; btn.textContent = 'Submit Review';
        if (res.success) { triggerHaptic('success'); showToast('Review sent!', 'success'); closeVideoReviewSheet(true); setTimeout(function() { location.reload(); }, 1000); }
        else { showToast(res.data || 'Error', 'error'); }
    }).catch(function() { btn.disabled = false; btn.textContent = 'Submit Review'; showToast('Connection error', 'error'); });
}

var challengeSheetOpenedAt = 0;
function openChallengeSheet() {
    challengeSheetOpenedAt = Date.now();
    document.getElementById('challengeTitleInput').value = '';
    document.getElementById('challengeDescInput').value = '';
    document.getElementById('challengeTypeInput').value = 'skill';
    document.querySelectorAll('#challengeSheet [data-ctype]').forEach(function(b) {
        b.classList.toggle('active', b.dataset.ctype === 'skill');
    });
    var overlay = document.getElementById('challengeOverlay');
    var sheet = document.getElementById('challengeSheet');
    overlay.style.display = 'block';
    sheet.style.display = 'flex';
    sheet.offsetHeight;
    overlay.classList.add('open');
    sheet.classList.add('open');
    triggerHaptic('light');
    setTimeout(function() { document.getElementById('challengeTitleInput').focus(); }, 300);
}
function closeChallengeSheetModal(force) {
    if (!force && Date.now() - challengeSheetOpenedAt < 400) return;
    var overlay = document.getElementById('challengeOverlay');
    var sheet = document.getElementById('challengeSheet');
    overlay.classList.remove('open');
    sheet.classList.remove('open');
    setTimeout(function() { overlay.style.display = ''; sheet.style.display = ''; }, 350);
}
function pickChallengeType(btn, type) {
    document.getElementById('challengeTypeInput').value = type;
    document.querySelectorAll('#challengeSheet [data-ctype]').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    triggerHaptic('light');
}
function submitChallengeSheet() {
    var title = document.getElementById('challengeTitleInput').value.trim();
    if (!title) { showToast('Please enter a title', 'error'); return; }
    var desc = document.getElementById('challengeDescInput').value.trim();
    var type = document.getElementById('challengeTypeInput').value;
    var btn = document.getElementById('challengeSubmitBtn');
    btn.disabled = true; btn.textContent = 'POSTING...';
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_mentorship_post_challenge&nonce=' + CONFIG.nonceGeneral +
              '&title=' + encodeURIComponent(title) + '&description=' + encodeURIComponent(desc) +
              '&challenge_type=' + encodeURIComponent(type) })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false; btn.textContent = 'Post Challenge';
        if (res.success) { triggerHaptic('success'); showToast('Challenge posted!', 'success'); closeChallengeSheetModal(true); setTimeout(function() { location.reload(); }, 1000); }
        else { showToast(res.data || 'Error', 'error'); }
    }).catch(function() { btn.disabled = false; btn.textContent = 'Post Challenge'; showToast('Connection error', 'error'); });
}

function openSessionSheet() {
    document.getElementById('scheduleSessionModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeScheduleModal() {
    document.getElementById('scheduleSessionModal').style.display = 'none';
    document.body.style.overflow = '';
}
function submitScheduleSession() {
    var type  = document.getElementById('sched-type').value;
    var date  = document.getElementById('sched-date').value;
    var time  = document.getElementById('sched-time').value;
    var dur   = document.querySelector('input[name="sched-dur-radio"]:checked');
    var durVal= dur ? dur.value : '45';

    if (!date || !time) { showToast('Pick a date and time', 'error'); return; }
    var datetime = date + ' ' + time + ':00';

    var btn = document.getElementById('scheduleSubmitBtn');
    btn.disabled = true; btn.textContent = 'Scheduling...';

    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_mentorship_schedule_session&nonce=' + CONFIG.nonceGeneral
            + '&session_type=' + encodeURIComponent(type)
            + '&scheduled_at=' + encodeURIComponent(datetime)
            + '&duration_minutes=' + durVal
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            closeScheduleModal();
            var msg = 'Session scheduled!';
            if (res.data && res.data.meeting_url) {
                msg += res.data.meeting_url.indexOf('zoom.us') !== -1 ? ' Zoom room created.' : ' Video room ready.';
            }
            showToast(msg, 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            btn.disabled = false; btn.textContent = 'Schedule Session';
            showToast(res.data || 'Error scheduling', 'error');
        }
    }).catch(() => { btn.disabled = false; btn.textContent = 'Schedule Session'; showToast('Connection error', 'error'); });
}

function toggleArcTip(e, label) {
    e.preventDefault();
    var wrap  = label.parentElement;
    var tip   = wrap.querySelector('.arc-tip');
    var chev  = label.querySelector('.arc-chevron');
    var open  = tip.style.display !== 'none';
    tip.style.display  = open ? 'none' : 'block';
    chev.style.transform = open ? '' : 'rotate(90deg)';
}
function arcCheckChange(sessionId, total) {
    var checks = document.querySelectorAll('.arc-check[data-session="' + sessionId + '"]');
    var done   = Array.from(checks).filter(function(c) { return c.checked; }).length;
    // Strike through label
    checks.forEach(function(c) {
        var txt = c.closest('label').querySelector('.arc-step-text');
        if (txt) txt.style.textDecoration = c.checked ? 'line-through' : '';
    });
    var btn  = document.getElementById('endSessionBtn_'  + sessionId);
    var hint = document.getElementById('endSessionHint_' + sessionId);
    if (done >= total) {
        if (btn)  { btn.style.display  = 'block'; }
        if (hint) { hint.style.display = 'none';  }
    } else {
        if (btn)  { btn.style.display  = 'none';  }
        if (hint) {
            hint.style.display = 'block';
            hint.textContent   = done + ' of ' + total + ' steps done';
        }
    }
}

// ─── End Session Modal ───
var _endSessionId = null;
var _endPlayerName = '';
function openEndSession(sessionId, playerName) {
    _endSessionId  = sessionId;
    _endPlayerName = playerName || 'the player';
    document.getElementById('endSessionOverlay').style.display = 'flex';
    document.getElementById('endSessionPlayerName').textContent = playerName || 'the player';
    document.body.style.overflow = 'hidden';
}
function closeEndSession() {
    document.getElementById('endSessionOverlay').style.display = 'none';
    document.body.style.overflow = '';
}
function setEnergyRating(n) {
    document.getElementById('energyRatingVal').value = n;
    var stars = document.querySelectorAll('.energy-star');
    stars.forEach(function(s, i) { s.textContent = i < n ? '\u2605' : '\u2606'; s.style.color = i < n ? 'var(--gold)' : '#ccc'; });
}
async function submitEndSession() {
    var actionItem    = document.getElementById('endActionItem').value.trim();
    var parentSummary = document.getElementById('endParentSummary').value.trim();
    var privateNotes  = document.getElementById('endPrivateNotes').value.trim();
    var energyRating  = document.getElementById('energyRatingVal').value;
    var errEl         = document.getElementById('endSessionError');

    errEl.style.display = 'none';
    if (!actionItem)    { errEl.textContent = "Action item required — what does " + _endPlayerName + " do this week?"; errEl.style.display = 'block'; return; }
    if (!parentSummary) { errEl.textContent = "Parent summary required — they need to know what happened."; errEl.style.display = 'block'; return; }

    var btn = document.getElementById('endSessionSubmitBtn');
    btn.disabled = true; btn.textContent = 'Saving...';

    var body = 'action=ptp_mentorship_mark_session_complete'
        + '&nonce='          + CONFIG.nonceMentorship
        + '&session_id='     + _endSessionId
        + '&action_item='    + encodeURIComponent(actionItem)
        + '&parent_summary=' + encodeURIComponent(parentSummary)
        + '&notes='          + encodeURIComponent(privateNotes)
        + '&energy_rating='  + encodeURIComponent(energyRating);

    try {
        var res  = await fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body });
        var data = await res.json();
        if (data.success) {
            closeEndSession();
            showToast(data.data.message || 'Session complete. Recap sent to parent.', 'success');
            setTimeout(function() { location.reload(); }, 1400);
        } else {
            errEl.textContent = data.data || 'Error saving session.';
            errEl.style.display = 'block';
            btn.disabled = false; btn.textContent = 'Complete Session';
        }
    } catch(e) {
        errEl.textContent = 'Connection error. Please try again.';
        errEl.style.display = 'block';
        btn.disabled = false; btn.textContent = 'Complete Session';
    }
}

function openPlaybook() {
    document.getElementById('playbookOverlay').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closePlaybook() {
    document.getElementById('playbookOverlay').style.display = 'none';
    document.body.style.overflow = '';
}

// ═══════════════════════════════════════════
//  AI ASSISTANT FUNCTIONS
// ═══════════════════════════════════════════

function aiDraftRecap() {
    if (!CONFIG.aiEnabled) { showToast('AI assistant not configured', 'error'); return; }
    var btn = document.getElementById('recapAiBtn');
    var bookingId = document.getElementById('recapBookingId').value;
    if (!bookingId) { showToast('No booking selected', 'error'); return; }

    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid #8B5CF6;border-top-color:transparent;border-radius:50%;animation:aispin .6s linear infinite"></span> Drafting...';

    var body = 'action=ptp_ai_draft_recap&nonce=' + CONFIG.nonceGeneral
        + '&booking_id=' + bookingId
        + '&partial_focus=' + encodeURIComponent(document.getElementById('recapFocus').value)
        + '&partial_wins=' + encodeURIComponent(document.getElementById('recapWins').value)
        + '&partial_improve=' + encodeURIComponent(document.getElementById('recapImprove').value)
        + '&partial_homework=' + encodeURIComponent(document.getElementById('recapHomework').value);

    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.data) {
            if (data.data.focus)    document.getElementById('recapFocus').value = data.data.focus;
            if (data.data.wins)     document.getElementById('recapWins').value = data.data.wins;
            if (data.data.improve)  document.getElementById('recapImprove').value = data.data.improve;
            if (data.data.homework) document.getElementById('recapHomework').value = data.data.homework;
            showToast('Draft ready — review and edit before sending', 'success');
        } else {
            showToast(data.data || 'AI draft failed', 'error');
        }
    })
    .catch(function() { showToast('Connection error', 'error'); })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg> Draft with AI';
    });
}

function aiDraftMentorship() {
    if (!CONFIG.aiEnabled) { showToast('AI assistant not configured', 'error'); return; }
    var btn = document.getElementById('endSessionAiBtn');
    if (!_endSessionId) { showToast('No session selected', 'error'); return; }

    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid #8B5CF6;border-top-color:transparent;border-radius:50%;animation:aispin .6s linear infinite"></span> Drafting...';

    var body = 'action=ptp_ai_draft_mentorship&nonce=' + CONFIG.nonceGeneral
        + '&session_id=' + _endSessionId
        + '&partial_action=' + encodeURIComponent(document.getElementById('endActionItem').value)
        + '&partial_summary=' + encodeURIComponent(document.getElementById('endParentSummary').value)
        + '&partial_notes=' + encodeURIComponent(document.getElementById('endPrivateNotes').value);

    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.data) {
            if (data.data.parent_summary) document.getElementById('endParentSummary').value = data.data.parent_summary;
            if (data.data.action_item)    document.getElementById('endActionItem').value = data.data.action_item;
            showToast('Draft ready — review and personalize', 'success');
        } else {
            showToast(data.data || 'AI draft failed', 'error');
        }
    })
    .catch(function() { showToast('Connection error', 'error'); })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg> Draft with AI';
    });
}

function aiSessionPrep(sessionId, btn) {
    if (!CONFIG.aiEnabled) { showToast('AI assistant not configured', 'error'); return; }
    var briefEl = document.getElementById('aiPrepBrief_' + sessionId);
    if (!briefEl) return;

    // Toggle if already loaded
    if (briefEl.style.display === 'block' && briefEl.textContent) {
        briefEl.style.display = 'none';
        return;
    }

    btn.disabled = true;
    var origHTML = btn.innerHTML;
    btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid #8B5CF6;border-top-color:transparent;border-radius:50%;animation:aispin .6s linear infinite"></span> Preparing...';

    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=ptp_ai_session_prep&nonce=' + CONFIG.nonceGeneral + '&session_id=' + sessionId })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.data && data.data.prep) {
            briefEl.innerHTML = data.data.prep.replace(/\n/g, '<br>');
            briefEl.style.display = 'block';
            showToast('Prep brief loaded', 'success');
        } else {
            showToast(data.data || 'Could not generate prep brief', 'error');
        }
    })
    .catch(function() { showToast('Connection error', 'error'); })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = origHTML;
    });
}

function aiDraftVideoFeedback() {
    if (!CONFIG.aiEnabled) { showToast('AI assistant not configured', 'error'); return; }
    var btn = document.getElementById('videoAiBtn');
    var videoId = document.getElementById('videoReviewId').value;
    if (!videoId) { showToast('No video selected', 'error'); return; }

    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid #8B5CF6;border-top-color:transparent;border-radius:50%;animation:aispin .6s linear infinite"></span> Drafting...';

    var body = 'action=ptp_ai_video_feedback&nonce=' + CONFIG.nonceGeneral
        + '&video_id=' + videoId
        + '&partial_feedback=' + encodeURIComponent(document.getElementById('videoFeedbackText').value);

    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.data && data.data.feedback) {
            document.getElementById('videoFeedbackText').value = data.data.feedback;
            showToast('Feedback draft ready — personalize it', 'success');
        } else {
            showToast(data.data || 'AI draft failed', 'error');
        }
    })
    .catch(function() { showToast('Connection error', 'error'); })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg> Suggest Feedback';
    });
}

var cancelSheetOpenedAt = 0;
function cancelSession(id) {
    cancelSheetOpenedAt = Date.now();
    document.getElementById('cancelSessionId').value = id;
    document.getElementById('cancelReasonText').value = '';
    var overlay = document.getElementById('cancelOverlay');
    var sheet = document.getElementById('cancelSheet');
    overlay.style.display = 'block';
    sheet.style.display = 'flex';
    sheet.offsetHeight;
    overlay.classList.add('open');
    sheet.classList.add('open');
    triggerHaptic('light');
}
function closeCancelSheet(force) {
    if (!force && Date.now() - cancelSheetOpenedAt < 400) return;
    var overlay = document.getElementById('cancelOverlay');
    var sheet = document.getElementById('cancelSheet');
    overlay.classList.remove('open');
    sheet.classList.remove('open');
    setTimeout(function() { overlay.style.display = ''; sheet.style.display = ''; }, 350);
}
function submitCancelSheet() {
    var id = document.getElementById('cancelSessionId').value;
    var reason = document.getElementById('cancelReasonText').value.trim();
    var btn = document.getElementById('cancelSubmitBtn');
    btn.disabled = true; btn.textContent = 'Cancelling...';
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_trainer_cancel_session&booking_id=' + id + '&reason=' + encodeURIComponent(reason) + '&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false; btn.textContent = 'Confirm Cancellation';
        if (res.success) { triggerHaptic('success'); showToast(res.data.message, 'success'); closeCancelSheet(true); setTimeout(function() { location.reload(); }, 1200); }
        else { showToast(res.data && res.data.message ? res.data.message : 'Error', 'error'); }
    }).catch(function() { btn.disabled = false; btn.textContent = 'Confirm Cancellation'; showToast('Connection error', 'error'); });
}

/* v135: Reschedule session */
var rescheduleBookingId = null;
function rescheduleSession(id) {
    rescheduleBookingId = id;
    var modal = document.getElementById('reschedule-modal');
    var body = document.getElementById('reschedule-body');
    modal.classList.add('active');
    body.innerHTML = '<div style="text-align:center;padding:40px"><div style="animation:spin 1s linear infinite;display:inline-block;width:24px;height:24px;border:3px solid #e5e7eb;border-top-color:#FCB900;border-radius:50%"></div><p style="margin-top:12px;color:#6b7280;font-size:14px">Loading available dates...</p></div>';
    
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_get_reschedule_dates&booking_id=' + id + '&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) { body.innerHTML = '<p style="color:#dc2626;padding:20px;text-align:center">' + (res.data.message||'Error') + '</p>'; return; }
        var d = res.data;
        var html = '<div style="margin-bottom:16px;padding:12px 16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb">';
        html += '<div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Current Session</div>';
        html += '<div style="font-size:15px;font-weight:600;color:#0A0A0A">' + d.booking.current_display + '</div>';
        if (d.booking.reschedule_count > 0) html += '<div style="font-size:11px;color:#F59E0B;margin-top:4px">Rescheduled ' + d.booking.reschedule_count + '/3 times</div>';
        html += '</div>';
        html += '<div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:8px">Pick a new date:</div>';
        html += '<div id="resched-dates" style="display:flex;flex-wrap:wrap;gap:6px;max-height:200px;overflow-y:auto;padding:2px">';
        d.dates.forEach(function(dt) {
            var cls = dt.is_current ? 'background:#fef9c3;border-color:#fde047' : 'background:#fff;border-color:#e5e7eb';
            html += '<button type="button" onclick="loadReschedSlots(\'' + dt.date + '\')" class="resched-date-btn" style="padding:8px 14px;border:1px solid;border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;' + cls + ';transition:all .15s" onmouseover="this.style.borderColor=\'#FCB900\'" onmouseout="this.style.borderColor=\'' + (dt.is_current?'#fde047':'#e5e7eb') + '\'">';
            html += '<div>' + dt.display + '</div>';
            html += '<div style="font-size:11px;color:#9ca3af">' + dt.slot_count + ' slot' + (dt.slot_count>1?'s':'') + '</div>';
            html += '</button>';
        });
        html += '</div>';
        html += '<div id="resched-slots" style="margin-top:16px"></div>';
        html += '<div id="resched-confirm" style="margin-top:16px;display:none">';
        html += '<button type="button" onclick="confirmReschedule()" style="width:100%;padding:14px;background:#FCB900;color:#0A0A0A;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer">Confirm Reschedule</button>';
        html += '</div>';
        body.innerHTML = html;
    }).catch(function() { body.innerHTML = '<p style="color:#dc2626;padding:20px;text-align:center">Connection error</p>'; });
}

var selectedReschedDate = null;
var selectedReschedTime = null;

function loadReschedSlots(date) {
    selectedReschedDate = date;
    selectedReschedTime = null;
    document.getElementById('resched-confirm').style.display = 'none';
    
    // Highlight selected date
    document.querySelectorAll('.resched-date-btn').forEach(function(b) { b.style.borderColor = '#e5e7eb'; b.style.background = '#fff'; });
    event.target.closest('.resched-date-btn').style.borderColor = '#FCB900';
    event.target.closest('.resched-date-btn').style.background = '#FFF9E6';
    
    var slotsDiv = document.getElementById('resched-slots');
    slotsDiv.innerHTML = '<div style="text-align:center;padding:16px;color:#6b7280;font-size:13px">Loading times...</div>';
    
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_get_reschedule_slots&booking_id=' + rescheduleBookingId + '&date=' + date + '&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success || !res.data.slots.length) { slotsDiv.innerHTML = '<p style="color:#6b7280;font-size:13px;text-align:center;padding:12px">No available times on this date</p>'; return; }
        var html = '<div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:8px">Pick a time:</div>';
        html += '<div style="display:flex;flex-wrap:wrap;gap:6px">';
        res.data.slots.forEach(function(s) {
            var style = s.is_current ? 'background:#fef9c3;border-color:#fde047' : 'background:#fff;border-color:#e5e7eb';
            html += '<button type="button" onclick="selectReschedTime(this,\'' + s.time + '\')" style="padding:10px 16px;border:1px solid;border-radius:8px;cursor:pointer;font-size:14px;font-weight:500;min-height:44px;touch-action:manipulation;' + style + ';transition:all .15s" onmouseover="this.style.borderColor=\'#FCB900\'" onmouseout="if(!this.classList.contains(\'selected\'))this.style.borderColor=\'' + (s.is_current?'#fde047':'#e5e7eb') + '\'">';
            html += s.display;
            if (s.is_current) html += ' <span style="font-size:10px;color:#92400e">(current)</span>';
            html += '</button>';
        });
        html += '</div>';
        slotsDiv.innerHTML = html;
    }).catch(function() { slotsDiv.innerHTML = '<p style="color:#dc2626;font-size:13px">Failed to load times</p>'; });
}

function selectReschedTime(btn, time) {
    selectedReschedTime = time;
    document.querySelectorAll('#resched-slots button').forEach(function(b) { b.classList.remove('selected'); b.style.borderColor = '#e5e7eb'; b.style.background = '#fff'; });
    btn.classList.add('selected');
    btn.style.borderColor = '#FCB900';
    btn.style.background = '#FFF9E6';
    document.getElementById('resched-confirm').style.display = 'block';
}

function confirmReschedule() {
    if (!selectedReschedDate || !selectedReschedTime) return;
    var btn = document.querySelector('#resched-confirm button');
    btn.disabled = true;
    btn.textContent = 'Rescheduling...';
    
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_reschedule_booking&booking_id=' + rescheduleBookingId + '&new_date=' + selectedReschedDate + '&new_time=' + selectedReschedTime + '&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            triggerHaptic('success');
            showToast(res.data.message, 'success');
            closeRescheduleModal();
            setTimeout(function() { location.reload(); }, 1200);
        } else {
            btn.disabled = false;
            btn.textContent = 'Confirm Reschedule';
            showToast(res.data.message || 'Error', 'error');
        }
    }).catch(function() { btn.disabled = false; btn.textContent = 'Confirm Reschedule'; showToast('Connection error', 'error'); });
}

function closeRescheduleModal() {
    document.getElementById('reschedule-modal').classList.remove('active');
    rescheduleBookingId = null;
    selectedReschedDate = null;
    selectedReschedTime = null;
}

function markNoShow(id) {
    if (!confirm('Mark this session as a no-show? The parent will not receive a refund.')) return;
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_mark_no_show&booking_id=' + id + '&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) { triggerHaptic('success'); showToast(res.data.message, 'success'); setTimeout(function() { location.reload(); }, 1200); }
        else { showToast(res.data && res.data.message ? res.data.message : 'Error', 'error'); }
    }).catch(function() { showToast('Connection error', 'error'); });
}

var insightsLoaded = false;
function loadInsights(period) {
    period = period || 30;
    var container = document.getElementById('insightsContent');
    if (!container) return;
    container.innerHTML = '<div class="text-center text-muted" style="padding:40px"><div style="animation:spin 1s linear infinite;display:inline-block;width:24px;height:24px;border:3px solid var(--border);border-top-color:var(--gold);border-radius:50%"></div><p class="mt-3 text-sm">Loading insights...</p></div>';
    
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_get_trainer_insights&period=' + period + '&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) { container.innerHTML = '<p class="text-muted text-center" style="padding:40px">Could not load insights</p>'; return; }
        var d = res.data;
        var e = d.earnings, b = d.bookings, p = d.profile;
        
        var changeIcon = function(val) { return val > 0 ? '↑' : val < 0 ? '↓' : '→'; };
        var changeColor = function(val) { return val > 0 ? 'var(--green)' : val < 0 ? 'var(--red)' : 'var(--text-muted)'; };
        
        var html = '';
        
        // Earnings summary
        html += '<div class="card">';
        html += '<div class="card-head"><h3 class="card-title">Earnings — Last ' + period + ' Days</h3></div>';
        html += '<div class="earn-grid" style="border:none;padding:0;margin:0">';
        html += '<div class="earn-cell"><div class="earn-cell-val">$' + Math.round(e.period_total).toLocaleString() + '</div><div class="earn-cell-lbl">Period</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val" style="color:' + changeColor(e.earnings_change) + '">' + changeIcon(e.earnings_change) + ' ' + Math.abs(e.earnings_change) + '%</div><div class="earn-cell-lbl">vs Prev</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val">$' + Math.round(e.avg_per_session) + '</div><div class="earn-cell-lbl">Avg/Session</div></div>';
        html += '</div>';
        html += '<div class="earn-grid">';
        html += '<div class="earn-cell"><div class="earn-cell-val">$' + Math.round(e.all_time).toLocaleString() + '</div><div class="earn-cell-lbl">All Time</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val">' + e.period_sessions + '</div><div class="earn-cell-lbl">Sessions</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val" style="color:var(--green)">$' + Math.round(e.pending_payout) + '</div><div class="earn-cell-lbl">Pending</div></div>';
        html += '</div></div>';
        
        // Booking stats
        html += '<div class="card">';
        html += '<div class="card-head"><h3 class="card-title">Bookings</h3></div>';
        html += '<div class="earn-grid" style="border:none;padding:0;margin:0">';
        html += '<div class="earn-cell"><div class="earn-cell-val">' + b.completion_rate + '%</div><div class="earn-cell-lbl">Completion</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val">' + b.unique_clients + '</div><div class="earn-cell-lbl">Clients</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val">' + b.repeat_clients + '</div><div class="earn-cell-lbl">Repeats</div></div>';
        html += '</div>';
        html += '<div class="earn-grid">';
        html += '<div class="earn-cell"><div class="earn-cell-val">' + b.completed + '</div><div class="earn-cell-lbl">Completed</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val" style="color:var(--amber)">' + b.cancelled + '</div><div class="earn-cell-lbl">Cancelled</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val" style="color:var(--red)">' + b.no_shows + '</div><div class="earn-cell-lbl">No-Shows</div></div>';
        html += '</div></div>';
        
        // Profile performance
        html += '<div class="card">';
        html += '<div class="card-head"><h3 class="card-title">Profile Performance</h3></div>';
        html += '<div class="earn-grid" style="border:none;padding:0;margin:0">';
        html += '<div class="earn-cell"><div class="earn-cell-val">' + p.views.toLocaleString() + '</div><div class="earn-cell-lbl">Views</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val" style="color:' + changeColor(p.views_change) + '">' + changeIcon(p.views_change) + ' ' + Math.abs(p.views_change) + '%</div><div class="earn-cell-lbl">vs Prev</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val">' + p.conversion_rate + '%</div><div class="earn-cell-lbl">Conversion</div></div>';
        html += '</div>';
        html += '<div class="earn-grid">';
        html += '<div class="earn-cell"><div class="earn-cell-val">' + p.avg_rating + '★</div><div class="earn-cell-lbl">Rating</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val">' + p.review_count + '</div><div class="earn-cell-lbl">Reviews</div></div>';
        html += '<div class="earn-cell"><div class="earn-cell-val">' + b.upcoming + '</div><div class="earn-cell-lbl">Upcoming</div></div>';
        html += '</div></div>';

        // Top clients
        if (d.top_clients && d.top_clients.length > 0) {
            html += '<div class="card">';
            html += '<div class="card-head"><h3 class="card-title">Top Clients</h3></div>';
            d.top_clients.forEach(function(c) {
                html += '<div class="scard static" style="margin-bottom:6px">';
                html += '<div class="scard-info"><div class="scard-name">' + (c.name || 'Client') + '</div>';
                html += '<div class="scard-meta">' + c.sessions + ' sessions · Last: ' + c.last_session + '</div></div>';
                html += '<div class="scard-right"><div class="scard-amt">$' + Math.round(parseFloat(c.total_earned)).toLocaleString() + '</div></div>';
                html += '</div>';
            });
            html += '</div>';
        }

        // Weekly trends
        if (d.trends && d.trends.length > 1) {
            html += '<div class="card">';
            html += '<div class="card-head"><h3 class="card-title">Weekly Trend</h3></div>';
            var maxEarn = Math.max.apply(null, d.trends.map(function(t) { return t.earnings; })) || 1;
            d.trends.forEach(function(t) {
                var pct = Math.round((t.earnings / maxEarn) * 100);
                html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">';
                html += '<div style="width:50px;font-size:11px;font-weight:600;color:var(--text-muted);flex-shrink:0">' + t.label + '</div>';
                html += '<div style="flex:1;height:24px;background:var(--surface);border-radius:6px;overflow:hidden">';
                html += '<div style="height:100%;width:' + pct + '%;background:linear-gradient(90deg,var(--gold),var(--gold-hover));border-radius:6px;min-width:2px"></div>';
                html += '</div>';
                html += '<div style="width:60px;text-align:right;font-family:Oswald,sans-serif;font-weight:700;font-size:14px">$' + Math.round(t.earnings) + '</div>';
                html += '</div>';
            });
            html += '</div>';
        }
        
        container.innerHTML = html;
        insightsLoaded = true;
        
        // v222.1: Auto-load AI Coach after insights
        loadAICoach();
    }).catch(function() { container.innerHTML = '<p class="text-muted text-center" style="padding:40px">Error loading insights</p>'; });
}

/* ═══════ AI COACH (Claude-powered) ═══════ */
var aiCoachLoaded = false;
function loadAICoach() {
    if (aiCoachLoaded) return;
    var container = document.getElementById('insightsContent');
    if (!container) return;
    
    // Append AI Coach card skeleton
    var coachDiv = document.createElement('div');
    coachDiv.id = 'aiCoachCard';
    coachDiv.className = 'card';
    coachDiv.innerHTML = '<div class="card-head"><h3 class="card-title" style="display:flex;align-items:center;gap:8px"><span style="font-size:18px">&#x1f9e0;</span> AI Coach</h3><span class="text-muted text-sm">Powered by Claude</span></div>' +
        '<div id="aiCoachBody" style="padding:4px 0">' +
        '<div class="text-center" style="padding:24px">' +
        '<p class="text-sm text-muted" style="margin:0 0 12px">Get personalized coaching based on your performance data</p>' +
        '<button class="btn btn-sm" onclick="fetchAICoach()" id="aiCoachBtn">Get Coaching Tips</button>' +
        '</div></div>';
    container.appendChild(coachDiv);
}

function fetchAICoach() {
    var btn = document.getElementById('aiCoachBtn');
    var body = document.getElementById('aiCoachBody');
    if (!body) return;
    
    body.innerHTML = '<div class="text-center" style="padding:32px"><div style="animation:spin 1s linear infinite;display:inline-block;width:24px;height:24px;border:3px solid var(--border);border-top-color:var(--gold);border-radius:50%"></div><p class="mt-3 text-sm text-muted">Analyzing your performance...</p></div>';
    
    fetch(CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_ai_coach_insights&nonce=' + CONFIG.nonceGeneral })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) {
            body.innerHTML = '<div class="text-center" style="padding:24px"><p class="text-sm text-muted">' + (res.data || 'Could not load coaching tips') + '</p><button class="btn btn-sm mt-3" onclick="fetchAICoach()">Retry</button></div>';
            return;
        }
        
        var tips = res.data.insights;
        if (!tips || !tips.length) {
            body.innerHTML = '<div class="text-center text-muted" style="padding:24px"><p class="text-sm">No coaching tips available right now.</p></div>';
            return;
        }
        
        var html = '';
        var prioColors = { high: 'var(--gold)', medium: 'var(--text)', low: 'var(--text-muted)' };
        var prioBg = { high: 'rgba(252,185,0,0.08)', medium: 'var(--surface)', low: 'var(--surface)' };
        
        tips.forEach(function(tip) {
            var prio = tip.priority || 'medium';
            var icon = tip.icon || '💡';
            html += '<div style="padding:14px 16px;border-bottom:1px solid var(--border);background:' + (prioBg[prio] || prioBg.medium) + '">';
            html += '<div style="display:flex;align-items:flex-start;gap:10px">';
            html += '<div style="font-size:18px;line-height:1;flex-shrink:0;padding-top:2px">' + icon + '</div>';
            html += '<div>';
            html += '<div style="font-weight:700;font-size:14px;color:' + (prioColors[prio] || prioColors.medium) + ';margin-bottom:4px">' + (tip.title || 'Tip') + '</div>';
            html += '<div style="font-size:13px;color:var(--text-secondary);line-height:1.5">' + (tip.body || '') + '</div>';
            html += '</div></div></div>';
        });
        
        html += '<div style="padding:12px 16px;text-align:center"><button class="text-sm" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:12px" onclick="fetchAICoach()">&#x21bb; Refresh tips</button></div>';
        
        body.innerHTML = html;
        aiCoachLoaded = true;
    }).catch(function() {
        body.innerHTML = '<div class="text-center" style="padding:24px"><p class="text-sm text-muted">Error connecting to AI Coach</p><button class="btn btn-sm mt-3" onclick="fetchAICoach()">Retry</button></div>';
    });
}

// v211: Dashboard Location Management
var dashLocPending = null;

function removeDashLoc(btn) {
    btn.closest('.dash-loc-item').remove();
    var empty = document.getElementById('dashLocEmpty');
    if (empty) empty.remove();
    saveDashLocations();
    showToast('Location removed');
}

function addDashLoc() {
    var input = document.getElementById('dashLocInput');
    var raw = input.value.trim();
    
    if (dashLocPending && dashLocPending.name) {
        // From Google Places autocomplete
        doAddDashLoc(dashLocPending);
        dashLocPending = null;
        input.value = '';
        return;
    }
    
    if (!raw) { showToast('Enter a location name', 'error'); return; }
    doAddDashLoc({ name: raw, address: raw, lat: null, lng: null, place_id: '' });
    input.value = '';
}

function doAddDashLoc(loc) {
    var list = document.getElementById('dashLocList');
    // Remove empty state
    var empty = document.getElementById('dashLocEmpty');
    if (empty) empty.remove();
    
    // Duplicate check
    var items = list.querySelectorAll('.dash-loc-item');
    for (var i = 0; i < items.length; i++) {
        try {
            var ex = JSON.parse(items[i].getAttribute('data-loc'));
            if (ex.name && ex.name.toLowerCase() === loc.name.toLowerCase()) {
                showToast('Already added', 'error');
                return;
            }
        } catch(e) {}
    }
    
    var div = document.createElement('div');
    div.className = 'dash-loc-item';
    div.setAttribute('data-loc', JSON.stringify(loc));
    var addrHtml = (loc.address && loc.address !== loc.name)
        ? '<div style="font-size:12px;color:var(--muted);margin-top:1px;word-break:break-word">' + escHtml(loc.address) + '</div>' : '';
    div.innerHTML =
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" style="flex-shrink:0"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>' +
        '<div style="flex:1;min-width:0"><div style="font-size:14px;font-weight:600;word-break:break-word">' + escHtml(loc.name) + '</div>' + addrHtml + '</div>' +
        '<button type="button" onclick="removeDashLoc(this)" class="btn-remove-circle">' +
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
    list.appendChild(div);
    showToast('Location added!', 'success');
    saveDashLocations();
}

function escHtml(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

function saveDashLocations() {
    var items = document.querySelectorAll('#dashLocList .dash-loc-item');
    var locs = [];
    items.forEach(function(el) {
        try { locs.push(JSON.parse(el.getAttribute('data-loc'))); } catch(e) {}
    });
    
    var body = 'action=ptp_save_trainer_profile&nonce=' + CONFIG.nonceGeneral
        + '&training_locations_json=' + encodeURIComponent(JSON.stringify(locs));
    fetch(CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) showToast('Save error', 'error');
    }).catch(function() { showToast('Connection error', 'error'); });
}

// Load Google Maps Places on the profile tab
function initDashLocAutocomplete() {
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) return;
    var input = document.getElementById('dashLocInput');
    if (!input || input.getAttribute('data-ac-init')) return;
    input.setAttribute('data-ac-init', '1');
    
    var ac = new google.maps.places.Autocomplete(input, {
        types: ['establishment', 'park', 'geocode'],
        componentRestrictions: { country: 'us' },
        fields: ['name', 'formatted_address', 'geometry', 'place_id']
    });
    
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); setTimeout(addDashLoc, 150); }
    });
    
    ac.addListener('place_changed', function() {
        var place = ac.getPlace();
        if (place && place.geometry) {
            dashLocPending = {
                name: place.name || input.value,
                address: place.formatted_address || input.value,
                lat: place.geometry.location.lat(),
                lng: place.geometry.location.lng(),
                place_id: place.place_id || ''
            };
            addDashLoc();
        }
    });
}

// v233 M6: Flag whether mentorship data was preloaded by PHP
window._mentorDataLoaded = CONFIG.mentorTabActive;

document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    var tab = params.get('tab'); if (tab) switchTab(tab);
    if (params.get('stripe_connected') || params.get('connected')) showToast('Stripe connected!', 'success');
    
    // Keyboard navigation for bottom nav
    document.querySelectorAll('.nav-item').forEach(function(item) {
        item.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); item.click(); }
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                var items = Array.from(document.querySelectorAll('.nav-item'));
                var idx = items.indexOf(item);
                var next = e.key === 'ArrowRight' ? (idx + 1) % items.length : (idx - 1 + items.length) % items.length;
                items[next].focus();
            }
        });
    });
    
    // v211: Load Google Maps Places API for location editing
    if (CONFIG.googleMapsKey) {
    var gmScript = document.createElement('script');
    gmScript.src = 'https://maps.googleapis.com/maps/api/js?key=' + CONFIG.googleMapsKey + '&libraries=places&loading=async';
    gmScript.async = true;
    gmScript.defer = true;
    gmScript.onload = function() { setTimeout(initDashLocAutocomplete, 200); };
    document.head.appendChild(gmScript);
    // Also init if profile tab is opened later
    var origSwitchTab = window.switchTab;
    window.switchTab = function(t) { origSwitchTab(t); if (t === 'profile') setTimeout(initDashLocAutocomplete, 300); };
    }

    // ═══ SESSION RECAP ═══
    var recapEffortVal = 0;
    var recapSkillRatings = {};
    
    window.setSkillRating = function(skill, val, el) {
        recapSkillRatings[skill] = val;
        var dots = el.parentElement.querySelectorAll('.recap-skill-dot');
        dots.forEach(function(d) {
            d.classList.toggle('active', parseInt(d.dataset.val) <= val);
        });
        triggerHaptic('light');
    };
    
    window.openRecapSheet = function(bookingId, playerName, dateStr) {
        document.getElementById('recapBookingId').value = bookingId;
        document.getElementById('recapTitle').textContent = 'Training Plan';
        document.getElementById('recapSub').textContent = playerName + ' · ' + dateStr;
        document.getElementById('recapFocus').value = '';
        document.getElementById('recapWins').value = '';
        document.getElementById('recapImprove').value = '';
        document.getElementById('recapHomework').value = '';
        document.getElementById('recapNextFocus').value = '';
        document.getElementById('recapPrivateNotes').value = '';
        recapEffortVal = 0;
        recapSkillRatings = {};
        document.querySelectorAll('.recap-star').forEach(function(s) { s.classList.remove('active'); });
        document.querySelectorAll('.recap-skill-dot').forEach(function(d) { d.classList.remove('active'); });
        document.getElementById('recapSendBtn').disabled = false;
        document.getElementById('recapSendBtn').textContent = 'SEND TRAINING PLAN';
        
        document.getElementById('recapOverlay').classList.add('on');
        setTimeout(function() { document.getElementById('recapSheet').classList.add('on'); }, 10);
        document.body.style.overflow = 'hidden';
    };
    
    window.closeRecapSheet = function() {
        document.getElementById('recapSheet').classList.remove('on');
        setTimeout(function() {
            document.getElementById('recapOverlay').classList.remove('on');
            document.body.style.overflow = '';
        }, 300);
    };
    
    window.setEffort = function(val) {
        recapEffortVal = val;
        document.querySelectorAll('.recap-star').forEach(function(s) {
            s.classList.toggle('active', parseInt(s.dataset.val) <= val);
        });
    };
    
    window.sendRecap = function() {
        var focus = document.getElementById('recapFocus').value.trim();
        if (!focus) {
            document.getElementById('recapFocus').style.borderColor = '#EF4444';
            document.getElementById('recapFocus').focus();
            setTimeout(function() { document.getElementById('recapFocus').style.borderColor = ''; }, 2000);
            return;
        }
        
        var btn = document.getElementById('recapSendBtn');
        btn.disabled = true;
        btn.textContent = 'SENDING...';
        
        var bookingId = document.getElementById('recapBookingId').value;
        
        // Use enhanced v2 endpoint if skill ratings present, otherwise fallback to v1
        var useV2 = Object.keys(recapSkillRatings).length > 0 || document.getElementById('recapPrivateNotes').value.trim();
        var action = useV2 ? 'ptp_send_session_recap_v2' : 'ptp_send_session_recap';
        
        var body = 'action=' + action
            + '&nonce=' + CONFIG.nonceGeneral
            + '&booking_id=' + bookingId
            + '&focus_worked_on=' + encodeURIComponent(focus)
            + '&achievements=' + encodeURIComponent(document.getElementById('recapWins').value.trim())
            + '&areas_to_improve=' + encodeURIComponent(document.getElementById('recapImprove').value.trim())
            + '&homework=' + encodeURIComponent(document.getElementById('recapHomework').value.trim())
            + '&next_session_focus=' + encodeURIComponent(document.getElementById('recapNextFocus').value.trim())
            + '&player_effort=' + recapEffortVal
            + '&is_visible_to_parent=1';
        
        if (useV2) {
            body += '&skill_ratings=' + encodeURIComponent(JSON.stringify(recapSkillRatings));
            body += '&private_notes=' + encodeURIComponent(document.getElementById('recapPrivateNotes').value.trim());
        }
        
        fetch(CONFIG.ajaxUrl, { credentials: 'same-origin',
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                btn.textContent = 'SENT!';
                showToast(d.data.message || 'Training plan sent!', 'success');
                
                var cards = document.querySelectorAll('.scard');
                cards.forEach(function(card) {
                    var recapBtn = card.querySelector('.btn-recap');
                    if (recapBtn && recapBtn.getAttribute('onclick').indexOf('(' + bookingId + ',') !== -1) {
                        var right = recapBtn.parentElement;
                        right.innerHTML = '<span class="recap-sent-badge">Plan Sent</span>';
                    }
                });
                
                setTimeout(closeRecapSheet, 800);
            } else {
                btn.disabled = false;
                btn.textContent = 'SEND TRAINING PLAN';
                showToast(d.data.message || 'Failed to send', 'error');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'SEND TRAINING PLAN';
            showToast('Network error', 'error');
        });
    };

    // ═══ GOOGLE CALENDAR ═══
    function loadGCalStatus() {
        var container = document.getElementById('gcalContent');
        if (!container) return;
        
        fetch(CONFIG.ajaxUrl, { credentials: 'same-origin',
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=ptp_gcal_get_status&nonce=' + CONFIG.nonceGeneral
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                container.innerHTML = '<p class="text-muted text-sm">Could not load calendar status</p>';
                return;
            }
            var d = res.data;
            
            if (!d.configured) {
                container.innerHTML = '<div style="text-align:center;padding:8px"><p class="text-muted text-sm">Google Calendar integration is not yet configured.</p><p class="text-muted text-sm" style="margin-top:4px">Contact Luke to set it up.</p></div>';
                return;
            }
            
            if (d.connected) {
                var html = '<div class="gcal-status">';
                html += '<div class="gcal-dot on"></div>';
                html += '<div class="gcal-info">';
                html += '<div class="gcal-email">' + (d.email || 'Connected') + '</div>';
                html += '<div class="gcal-sync-time">Last synced: ' + d.last_sync_display + '</div>';
                html += '</div></div>';
                
                html += '<div class="gcal-toggle-row">';
                html += '<div><div class="gcal-toggle-label">Auto-sync bookings</div>';
                html += '<div style="font-size:11px;color:var(--text-muted)">New bookings appear on your Google Calendar</div></div>';
                html += '<div class="avail-toggle ' + (d.sync_enabled ? 'on' : '') + '" onclick="toggleGCalSync(this)" style="flex-shrink:0"></div>';
                html += '</div>';
                
                html += '<div class="gcal-actions">';
                html += '<button class="gcal-btn" onclick="syncGCalNow(this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg> Sync Now</button>';
                html += '<button class="gcal-btn danger" onclick="disconnectGCal()">Disconnect</button>';
                html += '</div>';
                
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div style="text-align:center;padding:8px">'
                    + '<p class="text-sm" style="color:var(--black);font-weight:600;margin-bottom:8px">Sync with Google Calendar</p>'
                    + '<p class="text-muted text-sm" style="margin-bottom:12px">Your bookings will automatically appear on your calendar. Busy times from your calendar will block booking slots.</p>'
                    + '<button class="gcal-btn primary" onclick="connectGCal(this)" style="margin:0 auto">'
                    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'
                    + ' Connect Google Calendar</button></div>';
            }
        })
        .catch(function() {
            container.innerHTML = '<p class="text-muted text-sm text-center">Connection error</p>';
        });
    }
    
    window.connectGCal = function(btn) {
        btn.disabled = true;
        btn.textContent = 'Connecting...';
        fetch(CONFIG.ajaxUrl, { credentials: 'same-origin',
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=ptp_gcal_connect&nonce=' + CONFIG.nonceGeneral
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success && res.data.auth_url) {
                window.location.href = res.data.auth_url;
            } else {
                showToast(res.data && res.data.message ? res.data.message : 'Could not connect', 'error');
                btn.disabled = false;
                btn.textContent = 'Connect Google Calendar';
            }
        })
        .catch(function() { showToast('Connection error', 'error'); btn.disabled = false; });
    };
    
    window.disconnectGCal = function() {
        if (!confirm('Disconnect Google Calendar? Existing synced events will not be removed from Google.')) return;
        fetch(CONFIG.ajaxUrl, { credentials: 'same-origin',
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=ptp_gcal_disconnect&nonce=' + CONFIG.nonceGeneral
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) { showToast('Disconnected', 'success'); loadGCalStatus(); }
            else { showToast('Error', 'error'); }
        });
    };
    
    window.syncGCalNow = function(btn) {
        btn.disabled = true;
        var orig = btn.innerHTML;
        btn.innerHTML = '<span style="display:inline-block;animation:spin 0.6s linear infinite">&#8635;</span> Syncing...';
        fetch(CONFIG.ajaxUrl, { credentials: 'same-origin',
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=ptp_gcal_sync_now&nonce=' + CONFIG.nonceGeneral
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) { showToast('Calendar synced!', 'success'); loadGCalStatus(); }
            else { showToast(res.data && res.data.message ? res.data.message : 'Sync failed', 'error'); }
            btn.disabled = false;
            btn.innerHTML = orig;
        })
        .catch(function() { showToast('Error', 'error'); btn.disabled = false; btn.innerHTML = orig; });
    };
    
    window.toggleGCalSync = function(toggle) {
        var isOn = toggle.classList.toggle('on');
        fetch(CONFIG.ajaxUrl, { credentials: 'same-origin',
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=ptp_gcal_toggle_sync&nonce=' + CONFIG.nonceGeneral + '&enabled=' + (isOn ? 1 : 0)
        });
    };
    
    // Load Google Calendar status when schedule tab is opened
    var gcalLoaded = false;
    var origSwitchTabGcal = window.switchTab;
    window.switchTab = function(t) {
        origSwitchTabGcal(t);
        if (t === 'schedule' && !gcalLoaded) {
            gcalLoaded = true;
            loadGCalStatus();
        }
        // v228: Auto-load insights when tab opens (was manual button click)
        if (t === 'insights' && !insightsLoaded) {
            loadInsights(30);
        }
    };
    
    // Also load if already on schedule tab
    if (new URLSearchParams(window.location.search).get('tab') === 'schedule') {
        gcalLoaded = true;
        loadGCalStatus();
    }
    
    // Check for gcal_connected param from OAuth callback
    var params216 = new URLSearchParams(window.location.search);
    if (params216.get('gcal_connected')) {
        showToast('Google Calendar connected!', 'success');
        switchTab('schedule');
        gcalLoaded = true;
        setTimeout(loadGCalStatus, 500);
    }
    if (params216.get('gcal_error')) {
        var errMap = { denied: 'Calendar access was denied', invalid: 'Invalid session - try again', token: 'Could not get access token' };
        showToast(errMap[params216.get('gcal_error')] || 'Calendar connection failed', 'error');
    }

});


// ── Schedule Duration Radio ──
document.querySelectorAll('input[name="sched-dur-radio"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('input[name="sched-dur-radio"]').forEach(function(r) {
            var label = r.closest('label');
            label.style.border = '1px solid #333'; label.style.color = '#888'; label.style.background = 'transparent';
        });
        var sel = this.closest('label');
        sel.style.border = '2px solid #FCB900'; sel.style.color = '#FCB900'; sel.style.background = 'rgba(252,185,0,0.1)';
    });
});
// Set default selected state
var defaultRadio = document.querySelector('input[name="sched-dur-radio"]:checked');
if (defaultRadio) {
    var defaultLabel = defaultRadio.closest('label');
    defaultLabel.style.border = '2px solid #FCB900'; defaultLabel.style.color = '#FCB900'; defaultLabel.style.background = 'rgba(252,185,0,0.1)';
}


// ── Tour Steps ──
(function(){
var tourSteps = [
    {
        tab: 'home',
        title: 'Home — Your Command Center',
        desc: 'See upcoming sessions, confirm completed ones to release payment, and track your earnings at a glance. This is where you\'ll start every day.',
        target: function(){ return document.querySelector('.nav-item[data-tab="home"]'); }
    },
    {
        tab: 'schedule',
        title: 'Calendar — Set Your Availability',
        desc: 'Toggle which days and times you\'re available for training. Block off specific dates when you have games or plans. Parents can only book during your open slots.',
        target: function(){ return document.querySelector('.nav-item[data-tab="schedule"]'); }
    },
    {
        tab: 'earnings',
        title: 'Earnings — Track & Cash Out',
        desc: 'You earn 80% of every session — training and mentorship. See what you\'ve earned today, this week, and this month. When your balance is ready, hit "Cash Out" to transfer to your bank via Stripe.',
        target: function(){ return document.querySelector('.nav-item[data-tab="earnings"]'); }
    },
    {
        tab: 'messages',
        title: 'Messages — Talk to Parents',
        desc: 'Direct message thread with every parent who\'s booked with you. Coordinate session details, share updates, answer questions — all in one place.',
        target: function(){ return document.querySelector('.nav-item[data-tab="messages"]'); }
    },
    {
        tab: 'reviews',
        title: 'Reviews — Build Your Reputation',
        desc: 'Parents leave star ratings after sessions. Reply to reviews to show you care — parents read these before booking. Find Reviews in the More menu.',
        target: function(){ return document.querySelector('.nav-item[data-tab="more"]'); }
    },
    {
        tab: 'mentorship',
        title: 'Mentorship — Year-Round Coaching',
        desc: 'Parents request mentorship here. Schedule a free intro call, mark it complete, and they get a checkout link for a single session or package. You earn 80% of every session. Find Mentorship in the More menu.',
        target: function(){ return document.querySelector('.nav-item[data-tab="more"]'); }
    },
    {
        tab: 'profile',
        title: 'Profile — Make a Great First Impression',
        desc: 'Your photo, bio, headline, position, and Stripe connection all live here. A complete profile gets way more bookings. Find Profile in the More menu.',
        target: function(){ return document.querySelector('.nav-item[data-tab="more"]'); }
    },
    {
        tab: 'home',
        title: 'Share Your Profile Link',
        desc: 'Scroll down on the Home tab to copy your personal profile link. Share it with families via text, Instagram, or email — anyone who clicks it can book you directly.',
        target: function(){ return document.querySelector('.share-card') || document.querySelector('.ptp-share-section') || document.querySelector('[onclick*="copyProfileUrl"]') || document.querySelector('.nav-item[data-tab="home"]'); }
    }
];
var tourIdx = 0;
var tourActive = false;

window.startDashTour = function() {
    tourIdx = 0;
    tourActive = true;
    document.getElementById('tourOverlay').style.display = 'block';
    document.getElementById('tourBackdrop').style.display = 'none'; /* we use spotlight box-shadow instead */
    renderTourStep();
};
window.endDashTour = function() {
    tourActive = false;
    document.getElementById('tourOverlay').style.display = 'none';
    switchTab('home');
    try { localStorage.setItem('ptp_tour_done','1'); } catch(e){}
    var b = document.getElementById('tourBanner');
    if (b) b.style.display = 'none';
};
window.tourNext = function() {
    if (tourIdx < tourSteps.length - 1) { tourIdx++; renderTourStep(); }
    else { endDashTour(); }
};
window.tourPrev = function() {
    if (tourIdx > 0) { tourIdx--; renderTourStep(); }
};

function renderTourStep() {
    var s = tourSteps[tourIdx];
    var total = tourSteps.length;
    /* Switch to the tab */
    if (typeof switchTab === 'function') switchTab(s.tab);
    /* Update card content */
    document.getElementById('tourStep').textContent = 'Step ' + (tourIdx + 1) + ' of ' + total;
    document.getElementById('tourTitle').textContent = s.title;
    document.getElementById('tourDesc').textContent = s.desc;
    /* Dots */
    var dotsHtml = '';
    for (var i = 0; i < total; i++) {
        dotsHtml += '<div style="width:' + (i===tourIdx?'16px':'6px') + ';height:6px;border-radius:3px;background:' + (i===tourIdx?'var(--gold)':'#333') + ';transition:all .2s"></div>';
    }
    document.getElementById('tourDots').innerHTML = dotsHtml;
    /* Back button visibility */
    document.getElementById('tourBack').style.visibility = tourIdx === 0 ? 'hidden' : 'visible';
    /* Next button text */
    document.getElementById('tourNext').textContent = tourIdx === total - 1 ? "Let's Go!" : 'Next';
    /* Position spotlight on target */
    setTimeout(function(){
        var el = s.target();
        if (!el) { positionCardCenter(); return; }
        var r = el.getBoundingClientRect();
        var pad = 6;
        var spot = document.getElementById('tourSpotlight');
        spot.style.left = (r.left - pad) + 'px';
        spot.style.top = (r.top - pad) + 'px';
        spot.style.width = (r.width + pad*2) + 'px';
        spot.style.height = (r.height + pad*2) + 'px';
        /* Position card above or below target */
        var card = document.getElementById('tourCard');
        var cw = Math.min(320, window.innerWidth * 0.88);
        card.style.width = cw + 'px';
        /* If target is in the bottom nav, put card above */
        if (r.top > window.innerHeight * 0.6) {
            card.style.bottom = (window.innerHeight - r.top + 16) + 'px';
            card.style.top = 'auto';
        } else {
            card.style.top = (r.bottom + 16) + 'px';
            card.style.bottom = 'auto';
        }
        /* Center horizontally */
        var cardLeft = Math.max(12, Math.min(window.innerWidth - cw - 12, r.left + r.width/2 - cw/2));
        card.style.left = cardLeft + 'px';
        card.style.right = 'auto';
    }, 80);
}

function positionCardCenter() {
    var card = document.getElementById('tourCard');
    var spot = document.getElementById('tourSpotlight');
    spot.style.width = '0'; spot.style.height = '0';
    card.style.top = '50%'; card.style.left = '50%';
    card.style.transform = 'translate(-50%, -50%)';
    card.style.bottom = 'auto'; card.style.right = 'auto';
}
})();
