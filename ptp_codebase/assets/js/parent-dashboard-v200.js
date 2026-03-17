// ── PTP Error Handling ──
function ptpFetch(url, opts) {
    return fetch(url, opts).then(function(r) {
        if (!r.ok) throw new Error('Network response ' + r.status);
        return r.json();
    }).catch(function(err) {
        console.error('PTP fetch error:', err);
        return { success: false, data: 'Something went wrong. Please try again.' };
    });
}

function ptpShowError(container, msg) {
    if (!container) return;
    container.innerHTML = '<div style="text-align:center;padding:20px;color:#EF4444;font-size:14px">' +
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin:0 auto 8px;display:block"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>' +
        (msg || 'Something went wrong. Please try again.') +
        '</div>';
}

// ── Toast Notifications ──
function pdToast(msg, type) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:700;padding:12px 20px;border-radius:10px;font-size:14px;font-weight:500;font-family:Inter,sans-serif;box-shadow:0 4px 12px rgba(0,0,0,.15);transition:opacity .3s;max-width:90vw;text-align:center;';
    t.style.background = type === 'error' ? '#FEE2E2' : type === 'success' ? '#D1FAE5' : '#FEF3C7';
    t.style.color = type === 'error' ? '#991B1B' : type === 'success' ? '#065F46' : '#92400E';
    t.setAttribute('role', 'alert');
    document.body.appendChild(t);
    setTimeout(function() { t.style.opacity = '0'; setTimeout(function() { t.remove(); }, 300); }, 3500);
}

function verifyMentorshipPayment(pairId) {
    var btn = event.target;
    btn.disabled = true; btn.textContent = 'Checking...';
    fetch(PD_CONFIG.ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=ptp_mentorship_verify_payment&pair_id=' + pairId + '&nonce=' + encodeURIComponent(PD_CONFIG.mentorshipNonce)
    }).then(r => r.json()).then(data => {
        if (data.success) { location.reload(); }
        else { btn.disabled = false; btn.textContent = 'Verify Payment'; alert(data.data || 'Payment still processing. Try again in a moment.'); }
    }).catch(() => { btn.disabled = false; btn.textContent = 'Verify Payment'; });
}

// ── Tab Navigation ──
function switchTab(tab) {
    document.querySelectorAll('.pd-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.pd-nav-item').forEach(n => n.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    var navItem = document.querySelector('[data-tab="' + tab + '"]');
    if (navItem) navItem.classList.add('active');
    window.scrollTo(0, 0);
}

// ── Mentorship ──
// v235: Goal, video, session request, recap toggle — all handled by
// mentorship-components.php (bottom sheets). Only verifyMentorshipPayment
// stays here since it's not in the component library.

document.querySelectorAll('.pd-nav-item').forEach(item => {
    item.addEventListener('click', function() { switchTab(this.dataset.tab); });
    item.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); switchTab(this.dataset.tab); }
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
            var items = Array.from(document.querySelectorAll('.pd-nav-item'));
            var idx = items.indexOf(this);
            var next = e.key === 'ArrowRight' ? (idx + 1) % items.length : (idx - 1 + items.length) % items.length;
            items[next].focus();
        }
    });
});

function copyRef() {
    var input = document.getElementById('refLink');
    input.select();
    navigator.clipboard.writeText(input.value).then(function() {
        var btn = input.nextElementSibling;
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
    });
}

// ── Add Player Modal ──
function showAddPlayerModal() { document.getElementById('addPlayerModal').style.display = 'block'; document.body.style.overflow = 'hidden'; }
function hideAddPlayerModal() { document.getElementById('addPlayerModal').style.display = 'none'; document.body.style.overflow = ''; }

document.getElementById('addPlayerForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData(this);
    fd.append('action', 'ptp_add_player');
    fd.append('nonce', PD_CONFIG.nonce);
    fetch(PD_CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
        if (d.success) { hideAddPlayerModal(); location.reload(); }
        else { pdToast(d.data?.message || 'Error adding player', 'error'); }
    }).catch(function() { pdToast('Connection error', 'error'); });
});

// ── Review Preview + Confirm ──
var pendingReviews = {};
function previewRating(bookingId, rating, trainerSlug) {
    pendingReviews[bookingId] = { rating: rating, trainerSlug: trainerSlug };
    var stars = document.querySelector('[data-booking="' + bookingId + '"]');
    if (stars) {
        stars.querySelectorAll('.pd-star').forEach(function(s, i) { s.textContent = i < rating ? '\u2605' : '\u2606'; });
    }
    var confirmBtn = document.getElementById('confirm-' + bookingId);
    if (confirmBtn) {
        confirmBtn.style.display = 'inline-block';
        document.getElementById('confirm-stars-' + bookingId).textContent = rating;
    }
}
function confirmReview(bookingId) {
    var pending = pendingReviews[bookingId];
    if (!pending) return;
    submitReview(bookingId, pending.rating, pending.trainerSlug);
}

// ── Submit Review ──
function submitReview(bookingId, rating, trainerSlug) {
    var stars = document.querySelector('[data-booking="' + bookingId + '"]');
    if (!stars) return;
    stars.querySelectorAll('.pd-star').forEach(function(s, i) { s.textContent = i < rating ? '\u2605' : '\u2606'; });

    var fd = new FormData();
    fd.append('action', 'ptp_submit_review');
    fd.append('nonce', PD_CONFIG.nonce);
    fd.append('booking_id', bookingId);
    fd.append('rating', rating);
    fd.append('trainer_slug', trainerSlug);

    fetch(PD_CONFIG.ajaxUrl, { credentials: 'same-origin', method: 'POST', body: fd })
    .then(r => r.json()).then(d => {
        if (d.success) {
            stars.closest('.pd-review').innerHTML = '<div style="padding:12px;text-align:center;color:var(--green);font-weight:600;width:100%">Thanks for the review!</div>';
        }
    });
}

// ── Session Recap toggle ──
function toggleRecap(id, btn) {
    var detail = document.getElementById(id);
    if (!detail) return;
    var isOpen = detail.classList.contains('open');
    detail.classList.toggle('open');
    btn.classList.toggle('open');
    btn.innerHTML = (isOpen ? 'View Recap' : 'Hide Recap') + ' <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="11" height="11"><polyline points="6 9 12 15 18 9"/></svg>';
}

// ── Deep link support ──
(function() {
    var hash = window.location.hash.replace('#', '');
    if (hash && document.getElementById('tab-' + hash)) { switchTab(hash); }
})();
