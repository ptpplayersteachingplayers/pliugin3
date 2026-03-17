(function(){
    'use strict';
    if (window.__tp210_init) return;
    window.__tp210_init = true;

    /* ── MESSAGING MODAL ── */
    var msgOverlay = document.getElementById('msgOverlay');
    var msgModal = document.getElementById('msgModal');
    var msgClose = document.getElementById('msgClose');
    var msgForm = document.getElementById('msgForm');
    var msgSuccess = document.getElementById('msgSuccess');
    var msgError = document.getElementById('msgError');
    var msgSendBtn = document.getElementById('msgSend');
    var msgAjaxUrl = TP_CONFIG.ajaxUrl;
    var msgTrainerId = TP_CONFIG.trainerId;
    var msgNonce = TP_CONFIG.nonce;
    var msgIsLoggedIn = TP_CONFIG.isLoggedIn;
    var msgMessagesUrl = TP_CONFIG.messagesUrl;

    function ptpOpenMsg() {
        if (msgOverlay) msgOverlay.classList.add('on');
        if (msgModal) msgModal.classList.add('on');
        // v242: Use robust scroll lock to prevent iOS viewport jump
        lockScroll();
        // Focus the textarea (or name field for guests)
        setTimeout(function() {
            var firstInput = msgIsLoggedIn ? document.getElementById('msgText') : document.getElementById('msgName');
            if (firstInput) firstInput.focus();
        }, 350);
    }
    window.ptpOpenMsg = ptpOpenMsg;

    function ptpCloseMsg() {
        if (msgOverlay) msgOverlay.classList.remove('on');
        if (msgModal) msgModal.classList.remove('on');
        // v242: Restore scroll position properly
        unlockScroll();
    }
    window.ptpCloseMsg = ptpCloseMsg;

    if (msgOverlay) msgOverlay.addEventListener('click', ptpCloseMsg);
    if (msgClose) msgClose.addEventListener('click', ptpCloseMsg);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && msgModal && msgModal.classList.contains('on')) ptpCloseMsg();
    });

    // Quick-fill chips
    document.querySelectorAll('.tp210-msg-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            var ta = document.getElementById('msgText');
            if (!ta) return;
            ta.value = this.dataset.msg;
            ta.focus();
            // Highlight selected chip
            document.querySelectorAll('.tp210-msg-chip').forEach(function(c) { c.style.borderColor = ''; c.style.background = ''; });
            this.style.borderColor = 'var(--tp-gold)';
            this.style.background = 'var(--tp-gold-soft)';
        });
    });

    function ptpSendMsg() {
        var message = (document.getElementById('msgText').value || '').trim();
        if (!message) {
            ptpShowMsgError('Please enter a message.');
            return;
        }

        var formData = new FormData();
        formData.append('message', message);
        formData.append('trainer_id', msgTrainerId);

        if (msgIsLoggedIn) {
            formData.append('action', 'ptp_send_public_message');
            formData.append('nonce', msgNonce);
        } else {
            var name = (document.getElementById('msgName').value || '').trim();
            var email = (document.getElementById('msgEmail').value || '').trim();
            var phone = (document.getElementById('msgPhone').value || '').trim();

            if (!name) { ptpShowMsgError('Please enter your name.'); return; }
            if (!email || email.indexOf('@') === -1) { ptpShowMsgError('Please enter a valid email.'); return; }

            formData.append('action', 'ptp_send_public_message');
            formData.append('name', name);
            formData.append('email', email);
            formData.append('phone', phone);
        }

        // Disable button & show loading
        msgSendBtn.disabled = true;
        msgSendBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin .6s linear infinite;display:inline-block;vertical-align:-3px;margin-right:6px"><circle cx="12" cy="12" r="10" stroke-dasharray="50" stroke-dashoffset="15"/></svg>Sending...';
        msgError.style.display = 'none';

        fetch(msgAjaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    msgForm.style.display = 'none';
                    msgSuccess.style.display = 'block';
                    // Wire success link to conversation
                    var convId = data.data && data.data.conversation_id ? data.data.conversation_id : '';
                    var successLink = document.getElementById('msgSuccessLink');
                    if (successLink && convId) {
                        successLink.href = msgMessagesUrl + '?conversation=' + convId;
                    }
                } else {
                    ptpShowMsgError(data.data && data.data.message ? data.data.message : 'Something went wrong. Please try again.');
                    ptpResetMsgBtn();
                }
            })
            .catch(function() {
                ptpShowMsgError('Network error. Please check your connection and try again.');
                ptpResetMsgBtn();
            });
    }
    window.ptpSendMsg = ptpSendMsg;

    function ptpShowMsgError(msg) {
        msgError.textContent = msg;
        msgError.style.display = 'block';
    }

    function ptpResetMsgBtn() {
        msgSendBtn.disabled = false;
        msgSendBtn.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline-block;vertical-align:-3px;margin-right:6px"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send Message';
    }

    // Allow Enter key to send (Shift+Enter for newline)
    var msgTextArea = document.getElementById('msgText');
    if (msgTextArea) {
        msgTextArea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                ptpSendMsg();
            }
        });
    }

    /* ── AVAILABILITY DATA ── */
    var availByDay = TP_CONFIG.availByDay;
    var bookedMap = TP_CONFIG.bookedMap;
    var trainerRate = TP_CONFIG.trainerRate;
    var trainerSlug = TP_CONFIG.trainerSlug;
    var trainerId = TP_CONFIG.trainerId;
    var cartUrl = TP_CONFIG.cartUrl;

    /* ── STATE ── */
    var selPkg = 'single';
    var selGroup = 1;
    var selLoc = '', selLocAddr = '', selLocLat = '', selLocLng = '';
    var selDate = '', selTime = '';
    var pkgData = {};

    /* ── INIT PACKAGES ── */
    document.querySelectorAll('.tp210-pkg').forEach(function(el){
        pkgData[el.dataset.pkg] = {
            price: parseInt(el.dataset.price),
            save: parseInt(el.dataset.save),
            count: parseInt(el.dataset.count),
            per: parseInt(el.dataset.per)
        };
    });

    /* ── TABS ── */
    var tabContainer = document.getElementById('tpTabs');
    var _isMobileTab = window.innerWidth < 768;
    // v242: Update mobile detection on resize/orientation change
    window.addEventListener('resize', function() {
        _isMobileTab = window.innerWidth < 768;
        isMobile = window.innerWidth < 768;
    });
    function switchTab(name) {
        document.querySelectorAll('.tp210-tab').forEach(function(b){ b.classList.remove('on'); });
        document.querySelectorAll('.tp210-panel').forEach(function(p){
            p.classList.remove('on');
            p.style.display = 'none';
        });
        var btn = document.querySelector('.tp210-tab[data-tab="'+name+'"]');
        var panel = document.getElementById('tab-' + name);
        if (btn) btn.classList.add('on');
        if (panel) {
            panel.style.display = '';
            panel.classList.add('on');
            if (_isMobileTab) {
                void panel.offsetHeight;
                setTimeout(function(){
                    (tabContainer || panel).scrollIntoView({behavior:'smooth',block:'start'});
                }, 60);
            }
        }
    }
    document.querySelectorAll('.tp210-tab').forEach(function(btn){
        btn.addEventListener('click', function(e){ e.preventDefault(); switchTab(btn.dataset.tab); });
    });
    (function(){
        var hash = window.location.hash.replace('#','');
        var urlTab = new URLSearchParams(window.location.search).get('tab');
        var target = hash || urlTab;
        if (target && document.getElementById('tab-' + target)) {
            switchTab(target);
        }
    })();

    /* ── BOOK SESSION BUTTON ── */
    var svcBookBtn = document.getElementById('svcBookBtn');
    if (svcBookBtn) {
        svcBookBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var bp = document.getElementById('bPanel');
            if (bp && window.innerWidth >= 768) {
                bp.style.boxShadow = '0 0 0 3px var(--tp-gold), 0 4px 40px rgba(252,185,0,0.15)';
                bp.scrollIntoView({behavior:'smooth',block:'start'});
                setTimeout(function(){ bp.style.boxShadow = ''; }, 1500);
            } else {
                if (window.ptpOpenSheet) window.ptpOpenSheet();
            }
        });
    }

    /* ── BOOKING SELECTIONS — per-element handlers ── */
    var packDateNote = document.getElementById('packDateNote');

    document.querySelectorAll('.tp210-pkg').forEach(function(el){
        el.addEventListener('click', function(){
            document.querySelectorAll('.tp210-pkg').forEach(function(x){ x.classList.remove('sel'); });
            el.classList.add('sel');
            selPkg = el.dataset.pkg;
            updatePrice(); updateCTA();
            if (packDateNote) packDateNote.style.display = (parseInt(el.dataset.count)||1) > 1 ? 'block' : 'none';
            if (window._ptpActivateStep) setTimeout(function(){ window._ptpActivateStep(1); }, 200);
        });
    });

    document.querySelectorAll('.tp210-grp').forEach(function(el){
        el.addEventListener('click', function(){
            document.querySelectorAll('.tp210-grp').forEach(function(x){ x.classList.remove('sel'); });
            el.classList.add('sel');
            selGroup = parseInt(el.dataset.group);
            updatePrice(); updateCTA();
            if (window._ptpActivateStep) setTimeout(function(){ window._ptpActivateStep(2); }, 200);
        });
    });

    document.querySelectorAll('.tp210-lopt').forEach(function(el){
        el.addEventListener('click', function(){
            document.querySelectorAll('.tp210-lopt').forEach(function(x){ x.classList.remove('sel'); });
            el.classList.add('sel');
            selLoc = el.dataset.loc;
            selLocAddr = el.dataset.addr || '';
            selLocLat = el.dataset.lat || '';
            selLocLng = el.dataset.lng || '';
            updateCTA();
            if (window._ptpActivateStep) setTimeout(function(){ window._ptpActivateStep(3); }, 200);
        });
    });

    document.querySelectorAll('.tp210-dc').forEach(function(el){
        el.addEventListener('click', function(){
            document.querySelectorAll('.tp210-dc').forEach(function(x){ x.classList.remove('sel'); });
            el.classList.add('sel');
            selDate = el.dataset.date;
            selTime = '';
            renderTimes(); updateCTA();
            if (window._ptpActivateStep) setTimeout(function(){ window._ptpActivateStep(4); }, 200);
        });
    });

    /* ── GOOGLE PLACES AUTOCOMPLETE ── */
    var locInput = document.getElementById('locInput');
    var locSel = document.getElementById('locSel');
    var locSelName = document.getElementById('locSelName');
    var locSelAddr = document.getElementById('locSelAddr');
    var locChange = document.getElementById('locChange');
    var locHint = document.getElementById('locHint');

    function initAutocomplete() {
        if (!locInput) return;
        try {
            var ac = new google.maps.places.Autocomplete(locInput, {
                types: ['establishment', 'geocode'],
                componentRestrictions: { country: 'us' },
                fields: ['name', 'formatted_address', 'geometry', 'place_id']
            });
            ac.addListener('place_changed', function(){
                var place = ac.getPlace();
                if (!place || !place.geometry) return;
                selLoc = place.name || place.formatted_address;
                selLocAddr = place.formatted_address || '';
                selLocLat = place.geometry.location.lat();
                selLocLng = place.geometry.location.lng();
                if (locSelName) locSelName.textContent = selLoc;
                if (locSelAddr) locSelAddr.textContent = selLocAddr;
                if (locSel) locSel.style.display = 'flex';
                if (locInput) locInput.style.display = 'none';
                if (locHint) locHint.style.display = 'none';
                updateCTA();
                // Advance accordion to dates step
                if (window._ptpActivateStep) setTimeout(function(){ window._ptpActivateStep(3); }, 200);
            });
            if (locHint) locHint.textContent = 'Powered by Google Maps';
        } catch(e) {
            console.warn('Google Places init failed:', e);
            if (locHint) locHint.textContent = 'Type a location name';
        }
    }

    if (locChange) {
        locChange.addEventListener('click', function(){
            selLoc = ''; selLocAddr = ''; selLocLat = ''; selLocLng = '';
            if (locSel) locSel.style.display = 'none';
            if (locInput) { locInput.style.display = ''; locInput.value = ''; locInput.focus(); }
            if (locHint) { locHint.style.display = ''; locHint.textContent = 'Start typing to search Google Maps'; }
            updateCTA();
        });
    }

    /* fallback: if user types without autocomplete and presses Enter */
    if (locInput) {
        locInput.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                if (locInput.value.trim()) {
                    selLoc = locInput.value.trim();
                    selLocAddr = '';
                    if (locSelName) locSelName.textContent = selLoc;
                    if (locSelAddr) locSelAddr.textContent = 'Custom location';
                    if (locSel) locSel.style.display = 'flex';
                    locInput.style.display = 'none';
                    if (locHint) locHint.style.display = 'none';
                    updateCTA();
                    // Advance accordion to dates step
                    if (window._ptpActivateStep) setTimeout(function(){ window._ptpActivateStep(3); }, 200);
                }
            }
        });
    }

    /* poll for Google Maps API */
    if (locInput) {
        var gChecks = 0;
        var gTimer = setInterval(function(){
            gChecks++;
            if (window.google && google.maps && google.maps.places) {
                clearInterval(gTimer);
                initAutocomplete();
            } else if (gChecks > 50) {
                clearInterval(gTimer);
                if (locHint) locHint.textContent = 'Type a location name';
            }
        }, 200);
    }

    /* ── DATES — handled by delegation above ── */

    /* ── TIME RENDERING ── */
    var timeGrid = document.getElementById('timeGrid');
    var slotsLoading = false;
    function renderTimes() {
        if (!timeGrid) return;
        if (!selDate) { timeGrid.innerHTML = ''; timeGrid.classList.remove('vis'); return; }
        
        // Use AJAX to get REAL available slots (checks bookings + blocked dates + Google Calendar)
        timeGrid.innerHTML = '<div style="text-align:center;padding:16px"><div style="display:inline-block;width:20px;height:20px;border:2px solid #E5E7EB;border-top-color:#FCB900;border-radius:50%;animation:spin 0.6s linear infinite"></div><style>@keyframes spin{to{transform:rotate(360deg)}}</style></div>';
        timeGrid.classList.add('vis');
        slotsLoading = true;
        
        var params = 'action=ptp_get_real_available_slots&trainer_id=' + trainerId + '&date=' + selDate;
        fetch(ajaxUrl + '?' + params)
        .then(function(r) { return r.json(); })
        .then(function(res) {
            slotsLoading = false;
            if (!res.success || !res.data.slots || res.data.slots.length === 0) {
                timeGrid.innerHTML = '<div style="color:#999;font-size:14px;padding:8px 0">No available times for this date</div>';
                return;
            }
            var html = '';
            res.data.slots.forEach(function(slot) {
                html += '<div class="tp210-tc" data-time="' + slot.time + '">' + slot.display + '</div>';
            });
            timeGrid.innerHTML = html;
        })
        .catch(function() {
            slotsLoading = false;
            // Fallback to client-side rendering if AJAX fails
            renderTimesFallback();
        });
    }
    
    // Fallback: client-side rendering (original logic)
    var ajaxUrl = TP_CONFIG.ajaxUrl;
    function renderTimesFallback() {
        if (!selDate) return;
        var dayOfWeek = new Date(selDate + 'T12:00:00').getDay().toString();
        var slots = availByDay[dayOfWeek] || [];
        var html = '';
        slots.forEach(function(slot) {
            var startH = parseInt(slot.start.split(':')[0]);
            var startM = parseInt(slot.start.split(':')[1]) || 0;
            var endH = parseInt(slot.end.split(':')[0]);
            var endM = parseInt(slot.end.split(':')[1]) || 0;
            for (var h = startH; h < endH || (h === endH && 0 < endM); h++) {
                for (var m = (h === startH ? startM : 0); m < 60; m += 60) {
                    if (h > endH || (h === endH && m >= endM)) break;
                    var timeStr = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':00';
                    var key = selDate + '_' + timeStr;
                    var isBooked = bookedMap.hasOwnProperty(key);
                    var hr12 = h === 0 ? 12 : (h > 12 ? h - 12 : h);
                    var ampm = h < 12 ? 'AM' : 'PM';
                    var label = hr12 + ':' + String(m).padStart(2,'0') + ' ' + ampm;
                    html += '<div class="tp210-tc' + (isBooked ? ' off' : '') + '" data-time="' + timeStr + '">' + label + '</div>';
                }
            }
        });
        timeGrid.innerHTML = html || '<div style="color:#999;font-size:14px;padding:8px 0">No available times for this date</div>';
        timeGrid.classList.add('vis');
        // v242: time slot clicks handled by timeGrid delegation below
    }

    // v242: One-time delegation on timeGrid for dynamically rendered time slots
    if (timeGrid) {
        timeGrid.addEventListener('click', function(e) {
            var el = e.target.closest('.tp210-tc');
            if (el && el.dataset.time && !el.classList.contains('off')) {
                timeGrid.querySelectorAll('.tp210-tc').forEach(function(x){ x.classList.remove('sel'); });
                el.classList.add('sel');
                selTime = el.dataset.time;
                updateCTA();
            }
        });
    }

    /* ── PRICE UPDATE ── */
    var bRate = document.getElementById('bRate');
    var bSave = document.getElementById('bSave');
    function updatePrice() {
        var pkg = pkgData[selPkg] || pkgData.single;
        var multiplier = selGroup === 2 ? 1.6 : (selGroup === 3 ? 2 : 1);
        var total = Math.round(pkg.price * multiplier);
        var perSession = Math.round(total / pkg.count);
        var baseSave = pkg.save;
        if (bRate) bRate.innerHTML = '$' + perSession + '<small>/session</small>';
        if (bSave) {
            bSave.textContent = baseSave > 0 ? 'Save $' + baseSave : '';
            bSave.style.display = baseSave > 0 ? '' : 'none';
        }
    }

    /* ── CTA UPDATE ── */
    var ctaBtn = document.getElementById('ctaBtn');
    function updateCTA() {
        if (!ctaBtn) return;
        if (!selLoc) {
            ctaBtn.textContent = 'Select Location';
            ctaBtn.disabled = true;
        } else if (!selDate) {
            ctaBtn.textContent = 'Select Date';
            ctaBtn.disabled = true;
        } else if (!selTime) {
            ctaBtn.textContent = 'Select Time';
            ctaBtn.disabled = true;
        } else {
            var pkg = pkgData[selPkg] || pkgData.single;
            var multiplier = selGroup === 2 ? 1.6 : (selGroup === 3 ? 2 : 1);
            var total = Math.round(pkg.price * multiplier);
            ctaBtn.textContent = 'Book Now — $' + total;
            ctaBtn.disabled = false;
            // Scroll CTA into view on mobile so parent sees it immediately
            if (window.innerWidth < 768) {
                setTimeout(function(){ ctaBtn.scrollIntoView({behavior:'smooth',block:'nearest'}); }, 150);
            }
        }
    }

    if (ctaBtn) {
        var isSubmitting = false;
        ctaBtn.addEventListener('click', function(){
            if (ctaBtn.disabled || isSubmitting) return;
            isSubmitting = true;
            var originalText = ctaBtn.textContent;
            ctaBtn.textContent = 'Redirecting...';
            ctaBtn.style.opacity = '0.7';
            ctaBtn.style.pointerEvents = 'none';
            try {
                var bookingData = {
                    trainer_id: trainerId,
                    package: selPkg,
                    date: selDate,
                    time: selTime,
                    location: selLoc,
                    location_address: selLocAddr,
                    location_lat: selLocLat,
                    location_lng: selLocLng,
                    group_size: selGroup
                };
                // v134: Pass through free session code from URL
                var urlCode = new URLSearchParams(window.location.search).get('code') || new URLSearchParams(window.location.search).get('free_code');
                if (urlCode) bookingData.free_code = urlCode.toUpperCase();

                // Store in sessionStorage FIRST — this is the authoritative backup
                // URL params can get truncated on mobile Safari (2000 char limit)
                try { sessionStorage.setItem('ptp_booking', JSON.stringify(bookingData)); } catch(e) {}

                // Trim empty values and cap location_address to prevent URL truncation
                var urlData = {};
                for (var k in bookingData) {
                    if (bookingData[k] !== '' && bookingData[k] !== null && bookingData[k] !== undefined) {
                        urlData[k] = bookingData[k];
                    }
                }
                // Truncate long addresses to keep URL under browser limits
                if (urlData.location_address && urlData.location_address.length > 120) {
                    urlData.location_address = urlData.location_address.substring(0, 120);
                }
                var params = new URLSearchParams(urlData);
                window.location.href = cartUrl + '?' + params.toString();
            } catch(err) {
                console.error('Booking redirect error:', err);
                ctaBtn.textContent = originalText;
                ctaBtn.style.opacity = '1';
                ctaBtn.style.pointerEvents = '';
                isSubmitting = false;
                alert('Something went wrong. Please try again.');
            }
            /* Safety reset after 5s in case redirect stalls */
            setTimeout(function() {
                if (isSubmitting) {
                    ctaBtn.textContent = originalText;
                    ctaBtn.style.opacity = '1';
                    ctaBtn.style.pointerEvents = '';
                    isSubmitting = false;
                }
            }, 5000);
        });
    }

    /* ── MOBILE BOTTOM SHEET ── */
    var bPanel = document.getElementById('bPanel');
    var bOverlay = document.getElementById('bOverlay');
    var bClose = document.getElementById('bClose');
    var bBar = document.getElementById('bBar');
    var bBarBtn = bBar ? bBar.querySelector('.tp210-bar-btn') : null;
    var isMobile = window.innerWidth < 768;

    function openSheet() {
        if (!bPanel) return;
        // v238: Clear any lingering inline transform from drag, set willChange before animation
        bPanel.style.willChange = 'transform';
        bPanel.style.transform = '';
        bPanel.classList.add('open');
        if (bOverlay) bOverlay.classList.add('on');
        // v242: Use robust scroll lock to prevent iOS viewport jump
        if (isMobile) lockScroll(); else document.body.style.overflow = 'hidden';
    }
    function closeSheet() {
        if (!bPanel) return;
        bPanel.classList.remove('open');
        if (bOverlay) bOverlay.classList.remove('on');
        // v242: Restore scroll position properly
        unlockScroll();
        // v238: Reset willChange after transition completes
        setTimeout(function(){ if (bPanel && !bPanel.classList.contains('open')) bPanel.style.willChange = 'auto'; }, 400);
    }
    // Expose globally so inline onclick can use it
    window.ptpOpenSheet = openSheet;
    window.ptpCloseSheet = closeSheet;

    // v242: Direct handlers for bar/btn/close/overlay
    if (bClose) bClose.addEventListener('click', closeSheet);
    if (bOverlay) bOverlay.addEventListener('click', closeSheet);
    if (bBar) bBar.addEventListener('click', function(){ openSheet(); });
    if (bBarBtn) bBarBtn.addEventListener('click', function(e){ e.stopPropagation(); openSheet(); });

    /* Touch drag to dismiss */
    if (bPanel) {
        var startY = 0, currentY = 0, isDragging = false;
        var handle = bPanel.querySelector('.tp210-book-handle');
        if (handle) {
            handle.addEventListener('touchstart', function(e){
                startY = e.touches[0].clientY;
                isDragging = true;
                bPanel.style.transition = 'none';
            });
            handle.addEventListener('touchmove', function(e){
                if (!isDragging) return;
                currentY = e.touches[0].clientY - startY;
                if (currentY > 0) {
                    bPanel.style.transform = 'translateY(' + currentY + 'px)';
                }
            });
            handle.addEventListener('touchend', function(){
                isDragging = false;
                bPanel.style.transition = '';
                if (currentY > 100) {
                    // Drag down far enough — dismiss
                    bPanel.style.transform = '';
                    closeSheet();
                } else if (Math.abs(currentY) < 8) {
                    // v238: Tap (no real drag) — toggle the sheet
                    bPanel.style.transform = '';
                    if (bPanel.classList.contains('open')) {
                        closeSheet();
                    } else {
                        openSheet();
                    }
                } else {
                    // Small drag — snap back
                    bPanel.style.transform = '';
                }
                currentY = 0;
            });
            // v238: touchcancel — restore transition + transform so panel doesn't get stuck
            handle.addEventListener('touchcancel', function(){
                isDragging = false;
                bPanel.style.transition = '';
                bPanel.style.transform = '';
                currentY = 0;
            });
        }
    }

    /* ── SCROLL LOCK SAFETY NET ── */
    var _scrollLockY = 0;
    function lockScroll() {
        _scrollLockY = window.scrollY;
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.top = '-' + _scrollLockY + 'px';
        document.body.style.width = '100%';
    }
    function unlockScroll() {
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.width = '';
        if (_scrollLockY) window.scrollTo(0, _scrollLockY);
        _scrollLockY = 0;
    }
    window.addEventListener('pagehide', unlockScroll);
    window.addEventListener('popstate', unlockScroll);
    // iOS bfcache restore (e.g. user taps back after target="_blank" link)
    window.addEventListener('pageshow', function(e) { if (e.persisted) unlockScroll(); });
    // Tab switch (e.g. user returns from Google Maps opened via target="_blank")
    document.addEventListener('visibilitychange', function() { if (!document.hidden) unlockScroll(); });

    /* ── HORIZONTAL DATE SCROLL ── */
    var dateScroll = document.getElementById('dateScroll');
    if (dateScroll) {
        var isDown = false, scrollStartX, scrollLeft;
        dateScroll.addEventListener('mousedown', function(e){
            isDown = true; dateScroll.style.cursor = 'grabbing';
            scrollStartX = e.pageX - dateScroll.offsetLeft;
            scrollLeft = dateScroll.scrollLeft;
        });
        dateScroll.addEventListener('mouseleave', function(){ isDown = false; dateScroll.style.cursor = 'grab'; });
        dateScroll.addEventListener('mouseup', function(){ isDown = false; dateScroll.style.cursor = 'grab'; });
        dateScroll.addEventListener('mousemove', function(e){
            if (!isDown) return;
            e.preventDefault();
            var x = e.pageX - dateScroll.offsetLeft;
            dateScroll.scrollLeft = scrollLeft - (x - scrollStartX);
        });
    }

    /* ── BACK BUTTON ── */
    var backBtn = document.getElementById('tpBackBtn');
    if (backBtn) {
        backBtn.addEventListener('click', function(e){
            if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) {
                e.preventDefault();
                history.back();
            }
        });
    }

    /* ── PAC CONTAINER Z-INDEX ── */
    var styleEl = document.createElement('style');
    styleEl.textContent = '.pac-container{z-index:10000!important;border-radius:12px;border:1px solid #e5e5e5;box-shadow:0 8px 30px rgba(0,0,0,.12);margin-top:4px;font-family:"DM Sans",sans-serif}.pac-item{padding:10px 14px;cursor:pointer;font-size:14px;border-bottom:1px solid #f3f3f3}.pac-item:hover{background:#FFF9E5}.pac-icon{display:none}.pac-item-query{font-weight:600;color:#111}';
    document.head.appendChild(styleEl);

    /* v213: Gallery lightbox */
    var galleryUrls = TP_CONFIG.gallery;
    var lbIdx = 0;
    window.ptpOpenLightbox = function(i) {
        lbIdx = i;
        var overlay = document.getElementById('tp210-lightbox');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'tp210-lightbox';
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:500;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .25s;cursor:zoom-out';
            overlay.innerHTML = '<button id="lb-prev" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:28px;width:44px;height:44px;border-radius:50%;cursor:pointer;backdrop-filter:blur(4px);z-index:2">&#8249;</button>'
                + '<img id="lb-img" style="max-width:90vw;max-height:88vh;border-radius:8px;object-fit:contain;transition:opacity .2s">'
                + '<button id="lb-next" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:28px;width:44px;height:44px;border-radius:50%;cursor:pointer;backdrop-filter:blur(4px);z-index:2">&#8250;</button>'
                + '<button id="lb-close" style="position:absolute;top:16px;right:16px;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:22px;width:36px;height:36px;border-radius:50%;cursor:pointer;backdrop-filter:blur(4px);z-index:2">&times;</button>'
                + '<div id="lb-count" style="position:absolute;bottom:20px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,.6);font-size:13px"></div>';
            document.body.appendChild(overlay);
            overlay.addEventListener('click', function(e) { if (e.target === overlay) ptpCloseLightbox(); });
            document.getElementById('lb-close').addEventListener('click', ptpCloseLightbox);
            document.getElementById('lb-prev').addEventListener('click', function(e) { e.stopPropagation(); lbIdx = (lbIdx - 1 + galleryUrls.length) % galleryUrls.length; lbShow(); });
            document.getElementById('lb-next').addEventListener('click', function(e) { e.stopPropagation(); lbIdx = (lbIdx + 1) % galleryUrls.length; lbShow(); });
            document.addEventListener('keydown', function(e) {
                if (!overlay.style.display || overlay.style.display === 'none') return;
                if (e.key === 'Escape') ptpCloseLightbox();
                if (e.key === 'ArrowLeft') { lbIdx = (lbIdx - 1 + galleryUrls.length) % galleryUrls.length; lbShow(); }
                if (e.key === 'ArrowRight') { lbIdx = (lbIdx + 1) % galleryUrls.length; lbShow(); }
            });
        }
        overlay.style.display = 'flex';
        requestAnimationFrame(function() { overlay.style.opacity = '1'; });
        // v242: Use robust scroll lock
        lockScroll();
        lbShow();
    };
    function lbShow() {
        document.getElementById('lb-img').src = galleryUrls[lbIdx] || '';
        document.getElementById('lb-count').textContent = (lbIdx + 1) + ' / ' + galleryUrls.length;
        document.getElementById('lb-prev').style.display = galleryUrls.length > 1 ? '' : 'none';
        document.getElementById('lb-next').style.display = galleryUrls.length > 1 ? '' : 'none';
    }
    window.ptpCloseLightbox = function() {
        var overlay = document.getElementById('tp210-lightbox');
        if (overlay) { overlay.style.opacity = '0'; setTimeout(function() { overlay.style.display = 'none'; }, 250); }
        // v242: Restore scroll position properly
        unlockScroll();
    };

    /* ── v216: MOBILE STEP-BY-STEP ACCORDION ── */
    (function() {
        if (window.innerWidth >= 768) return; // Desktop: no accordion
        
        var steps = document.querySelectorAll('.tp210-book .tp210-step');
        if (!steps.length) return;
        
        var stepMap = {};
        steps.forEach(function(step, i) { stepMap[i] = step; });
        
        function activateStep(n) {
            steps.forEach(function(step, i) {
                step.classList.remove('step-active');
                step.classList.remove('step-done');
                // Update selection summary
                var sel = step.querySelector('.tp210-step-sel');
                if (i < n && !sel) {
                    sel = document.createElement('span');
                    sel.className = 'tp210-step-sel';
                    step.querySelector('.tp210-step-t').appendChild(sel);
                }
                if (i < n) {
                    step.classList.add('step-done');
                    // Populate summary
                    if (sel) {
                        if (i === 0 && selPkg) {
                            var pkgNames = {'single':'1 Session','pack5':'5-Pack','pack10':'10-Pack'};
                            sel.textContent = pkgNames[selPkg] || selPkg;
                        }
                        if (i === 1 && selGroup) sel.textContent = selGroup + ' player' + (selGroup > 1 ? 's' : '');
                        if (i === 2 && selLoc) sel.textContent = selLoc;
                        if (i === 3 && selDate) {
                            var dd = new Date(selDate + 'T12:00:00');
                            sel.textContent = dd.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                        }
                    }
                }
            });
            if (stepMap[n]) stepMap[n].classList.add('step-active');
        }
        // Expose globally so autocomplete + single-loc logic can advance steps
        window._ptpActivateStep = activateStep;

        // Auto-select if trainer has only 1 location
        var locOpts = document.querySelectorAll('.tp210-lopt');
        var autoLocSelected = false;
        if (locOpts.length === 1) {
            var el = locOpts[0];
            el.classList.add('sel');
            selLoc = el.dataset.loc;
            selLocAddr = el.dataset.addr || '';
            selLocLat = el.dataset.lat || '';
            selLocLng = el.dataset.lng || '';
            autoLocSelected = true;
            updateCTA();
        }

        // Start at location step (2) — or dates step (3) if single location auto-selected
        // Package=single and group=1 are already defaulted, skip past them
        activateStep(autoLocSelected ? 3 : 2);
        
        // v241: Step advancement handled by document-level delegation above
        // Package->step1, Group->step2, Location->step3, Date->step4
        
        // Allow tapping step title to expand that step
        steps.forEach(function(step, i) {
            step.querySelector('.tp210-step-t').addEventListener('click', function(e) {
                if (!step.classList.contains('step-active')) {
                    activateStep(i);
                }
            });
        });
    })();

    /* ── v216: DATE SCROLL END DETECTION ── */
    (function() {
        var dateScroll = document.getElementById('dateScroll');
        if (!dateScroll) return;
        dateScroll.addEventListener('scroll', function() {
            var atEnd = dateScroll.scrollLeft + dateScroll.clientWidth >= dateScroll.scrollWidth - 10;
            dateScroll.classList.toggle('scrolled-end', atEnd);
        });
    })();

    /* ── v218: FEATURED VIDEO PLAYER ── */
    (function(){
        var vid = document.getElementById('tpVideo');
        var wrap = document.getElementById('tpVid');
        var overlay = document.getElementById('tpVidOverlay');
        var muteBtn = document.getElementById('tpVidMute');
        var muteIcon = document.getElementById('tpMuteIcon');
        var progress = document.getElementById('tpVidProgress');
        if (!vid || !wrap) return;

        var userStarted = false;
        var flashTimer = null;

        /* Autoplay muted when scrolled into view */
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function(entries) {
                entries.forEach(function(e) {
                    if (e.isIntersecting && !userStarted) {
                        vid.play().then(function() {
                            wrap.classList.add('playing');
                            wrap.classList.remove('paused');
                        }).catch(function() {
                            /* iOS low-power or autoplay blocked — show play button */
                            wrap.classList.remove('playing');
                            wrap.classList.add('paused');
                        });
                    } else if (!e.isIntersecting && !userStarted) {
                        vid.pause();
                        wrap.classList.remove('playing');
                    }
                });
            }, { threshold: 0.4 });
            io.observe(wrap);
        }

        /* Progress bar */
        vid.addEventListener('timeupdate', function() {
            if (vid.duration && progress) {
                progress.style.width = ((vid.currentTime / vid.duration) * 100) + '%';
            }
        });

        /* Brief overlay flash for touch feedback */
        function flashOverlay() {
            if (flashTimer) clearTimeout(flashTimer);
            wrap.classList.add('flash');
            flashTimer = setTimeout(function() { wrap.classList.remove('flash'); }, 400);
        }

        /* Click/tap to play/pause + unmute on first tap */
        window.tpVidToggle = function() {
            if (!userStarted) {
                userStarted = true;
                vid.muted = false;
                vid.currentTime = 0;
                vid.play().then(function() {
                    wrap.classList.add('playing');
                    wrap.classList.remove('paused');
                }).catch(function() {
                    /* Fallback: keep muted if unmuted autoplay blocked */
                    vid.muted = true;
                    vid.play().catch(function(){});
                    wrap.classList.add('playing');
                    wrap.classList.remove('paused');
                });
                updateMuteIcon();
                return;
            }
            if (vid.paused) {
                vid.play().catch(function(){});
                wrap.classList.add('playing');
                wrap.classList.remove('paused');
                flashOverlay();
            } else {
                vid.pause();
                wrap.classList.remove('playing');
                wrap.classList.add('paused');
            }
        };

        /* Mute toggle */
        window.tpVidMuteToggle = function() {
            vid.muted = !vid.muted;
            if (!userStarted) userStarted = true;
            updateMuteIcon();
        };

        function updateMuteIcon() {
            if (!muteIcon) return;
            if (vid.muted) {
                muteIcon.innerHTML = '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" fill="#fff" stroke="none"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/>';
            } else {
                muteIcon.innerHTML = '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" fill="#fff" stroke="none"/><path d="M15.54 8.46a5 5 0 010 7.07"/><path d="M19.07 4.93a10 10 0 010 14.14"/>';
            }
        }

        /* iOS: if video gets paused externally (phone call, etc), update state */
        vid.addEventListener('pause', function() {
            if (userStarted) {
                wrap.classList.remove('playing');
                wrap.classList.add('paused');
            }
        });
        vid.addEventListener('play', function() {
            wrap.classList.add('playing');
            wrap.classList.remove('paused');
        });
    })();

})();
