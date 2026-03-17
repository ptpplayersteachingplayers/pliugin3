<?php
/**
 * PTP Mentorship Shared Components v2.0
 * 
 * Reusable bottom sheets, modals, and UI patterns for mentorship
 * across both trainer and parent dashboards.
 * 
 * Include with: include(PTP_PLUGIN_DIR . 'templates/components/mentorship-components.php');
 * Requires: $dashboard_type ('trainer' or 'parent')
 */
defined('ABSPATH') || exit;
$_nonce = wp_create_nonce('ptp_ajax_nonce');
$_ajax  = admin_url('admin-ajax.php');
?>

<!-- ═══════════════════════════════════════════════════════════════
     MENTORSHIP BOTTOM SHEETS & MODALS
     ═══════════════════════════════════════════════════════════════ -->

<style>
/* ── Mentorship Sheet System ── */
.mt-sheet-overlay{position:fixed;inset:0;background:rgba(0,0,0,0);z-index:9998;pointer-events:none;transition:background .3s ease}
.mt-sheet-overlay.open{background:rgba(0,0,0,.55);pointer-events:auto}
.mt-sheet{position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#fff;border-radius:20px 20px 0 0;transform:translateY(100%);transition:transform .35s cubic-bezier(.32,.72,0,1);max-height:92vh;display:flex;flex-direction:column;box-shadow:0 -8px 40px rgba(0,0,0,.12);will-change:transform}
.mt-sheet.open{transform:translateY(0)}
@media(min-width:640px){.mt-sheet{max-width:480px;left:50%;transform:translateX(-50%) translateY(100%)}.mt-sheet.open{transform:translateX(-50%) translateY(0)}}
.mt-sheet-handle{width:36px;height:4px;background:#D4D4D4;border-radius:2px;margin:10px auto 0;flex-shrink:0}
.mt-sheet-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px 12px;flex-shrink:0;border-bottom:1px solid #F0F0EE}
.mt-sheet-title{font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#0A0A0A}
.mt-sheet-close{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border:none;background:#F5F5F3;border-radius:50%;cursor:pointer;color:#737373;flex-shrink:0;-webkit-tap-highlight-color:transparent;transition:background .15s}
.mt-sheet-close:active{background:#E5E5E3}
.mt-sheet-close svg{width:16px;height:16px}
.mt-sheet-body{padding:16px 20px;overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
.mt-sheet-foot{padding:12px 20px calc(12px + env(safe-area-inset-bottom,0px));flex-shrink:0;border-top:1px solid #F0F0EE}

/* ── Form Elements ── */
.mt-label{display:block;font-family:'Oswald',sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#737373;margin-bottom:6px}
.mt-input{width:100%;padding:12px 14px;border:2px solid #E5E5E3;border-radius:10px;font-family:'Inter',system-ui,sans-serif;font-size:16px;color:#0A0A0A;outline:none;transition:border-color .2s;background:#fff;-webkit-appearance:none}
.mt-input:focus{border-color:#FCB900}
.mt-textarea{min-height:80px;resize:vertical;line-height:1.5}
.mt-input::placeholder{color:#A3A3A3}
.mt-field{margin-bottom:16px}
.mt-field:last-child{margin-bottom:0}

/* ── CTA Button ── */
.mt-btn-primary{width:100%;padding:14px;background:#FCB900;color:#0A0A0A;font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border:none;border-radius:10px;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:opacity .15s,transform .1s;min-height:48px;touch-action:manipulation}
.mt-btn-primary:active{transform:scale(.98);opacity:.9}
.mt-btn-primary:disabled{opacity:.5;cursor:not-allowed;transform:none}
.mt-btn-secondary{width:100%;padding:12px;background:transparent;color:#525252;font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border:1.5px solid #E5E5E3;border-radius:10px;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:border-color .15s;min-height:44px;touch-action:manipulation}
.mt-btn-secondary:active{border-color:#0A0A0A}

/* ── Goal Type Chips ── */
.mt-goal-chips{display:flex;gap:6px;flex-wrap:wrap}
.mt-goal-chip{padding:6px 14px;border-radius:20px;font-family:'Oswald',sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;border:2px solid #E5E5E3;background:#fff;color:#737373;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:all .15s;touch-action:manipulation}
.mt-goal-chip.active{border-color:var(--chip-color,#FCB900);color:var(--chip-color,#FCB900);background:var(--chip-bg,#FCB90012)}
.mt-goal-chip[data-type="game"]{--chip-color:#FCB900;--chip-bg:#FCB90012}
.mt-goal-chip[data-type="mental"]{--chip-color:#60A5FA;--chip-bg:#60A5FA12}
.mt-goal-chip[data-type="identity"]{--chip-color:#EF4444;--chip-bg:#EF444412}
.mt-goal-chip[data-type="life"]{--chip-color:#22C55E;--chip-bg:#22C55E12}

/* ── Energy/Effort Rating ── */
.mt-energy{display:flex;gap:6px}
.mt-energy-dot{width:40px;height:40px;border-radius:50%;border:2px solid #E5E5E3;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;color:#A3A3A3;cursor:pointer;transition:all .15s;-webkit-tap-highlight-color:transparent;touch-action:manipulation}
.mt-energy-dot.active{background:#FCB900;border-color:#FCB900;color:#0A0A0A;transform:scale(1.1)}
.mt-energy-dot:active{transform:scale(.95)}

/* ── Timeline / Journey ── */
.mt-timeline{position:relative;padding-left:28px}
.mt-timeline::before{content:'';position:absolute;left:9px;top:6px;bottom:6px;width:2px;background:#E5E5E3}
.mt-timeline-item{position:relative;padding-bottom:20px}
.mt-timeline-item:last-child{padding-bottom:0}
.mt-timeline-dot{position:absolute;left:-28px;top:2px;width:20px;height:20px;border-radius:50%;border:2px solid #E5E5E3;background:#fff;display:flex;align-items:center;justify-content:center}
.mt-timeline-dot.done{background:#22C55E;border-color:#22C55E}
.mt-timeline-dot.done svg{stroke:#fff}
.mt-timeline-dot.active{background:#FCB900;border-color:#FCB900;box-shadow:0 0 0 4px rgba(252,185,0,.2)}
.mt-timeline-dot.active svg{stroke:#0A0A0A}
.mt-timeline-title{font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#0A0A0A}
.mt-timeline-desc{font-size:12px;color:#737373;line-height:1.5;margin-top:2px}
.mt-timeline-date{font-size:10px;color:#A3A3A3;margin-top:2px}

/* ── Session Recap Card (for completed sessions) ── */
.mt-recap-card{background:#F9F9F7;border:1px solid #EAEAE6;border-radius:12px;padding:14px;margin-bottom:10px}
.mt-recap-section{margin-bottom:10px}
.mt-recap-section:last-child{margin-bottom:0}
.mt-recap-dot{width:6px;height:6px;border-radius:50%;display:inline-block;margin-right:6px;flex-shrink:0}
.mt-recap-heading{font-family:'Oswald',sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#737373;margin-bottom:4px;display:flex;align-items:center;gap:4px}
.mt-recap-text{font-size:13px;color:#525252;line-height:1.5}

/* ── Mentorship Stats Row ── */
.mt-stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(80px,1fr));gap:8px;margin-bottom:16px}
.mt-stat-card{background:#fff;border:1px solid #EAEAE6;border-radius:10px;padding:12px 8px;text-align:center}
.mt-stat-num{font-family:'Oswald',sans-serif;font-size:clamp(20px,5vw,26px);font-weight:700;line-height:1}
.mt-stat-lbl{font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#737373;margin-top:3px;font-weight:600}

/* ── Mentee Card ── */
.mt-mentee-card{background:#fff;border:1px solid #EAEAE6;border-radius:12px;padding:14px;margin-bottom:10px;transition:border-color .2s}
.mt-mentee-card:active{border-color:#FCB900}
.mt-mentee-avatar{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-weight:700;font-size:18px;flex-shrink:0}
.mt-pkg-badge{font-family:'Oswald',sans-serif;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:3px 8px;border-radius:10px}
.mt-pkg-single{background:#3B82F612;color:#3B82F6}
.mt-pkg-kickstart{background:#52525212;color:#525252}
.mt-pkg-development{background:#FCB90012;color:#B8860B}
.mt-pkg-elite{background:#0A0A0A;color:#FCB900}

/* ── Progress Ring ── */
.mt-progress-mini{height:4px;background:#EAEAE6;border-radius:2px;overflow:hidden;flex:1}
.mt-progress-mini-fill{height:100%;border-radius:2px;transition:width .4s ease}

/* ── Quick Action Pill ── */
.mt-pill{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:8px;font-family:'Oswald',sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;border:none;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:opacity .15s;text-decoration:none;touch-action:manipulation}
.mt-pill:active{opacity:.8}
.mt-pill-gold{background:#FCB900;color:#0A0A0A}
.mt-pill-green{background:#22C55E;color:#fff}
.mt-pill-ghost{background:transparent;border:1px solid #E5E5E3;color:#737373}
</style>

<!-- ═══ ADD GOAL SHEET ═══ -->
<div class="mt-sheet-overlay" id="mtGoalOverlay" onclick="mtCloseSheet('mtGoal')"></div>
<div class="mt-sheet" id="mtGoalSheet">
    <div class="mt-sheet-handle"></div>
    <div class="mt-sheet-head">
        <div class="mt-sheet-title">Set a Goal</div>
        <button class="mt-sheet-close" onclick="mtCloseSheet('mtGoal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mt-sheet-body">
        <div class="mt-field">
            <label class="mt-label">Goal Type</label>
            <div class="mt-goal-chips">
                <div class="mt-goal-chip active" data-type="game" onclick="mtPickGoalType(this)">Game</div>
                <div class="mt-goal-chip" data-type="mental" onclick="mtPickGoalType(this)">Mental</div>
                <div class="mt-goal-chip" data-type="identity" onclick="mtPickGoalType(this)">Identity</div>
                <div class="mt-goal-chip" data-type="life" onclick="mtPickGoalType(this)">Life</div>
            </div>
        </div>
        <div class="mt-field">
            <label class="mt-label">What's the Goal?</label>
            <input class="mt-input" id="mtGoalTitle" placeholder='e.g. "Score with my weak foot in a game"'>
        </div>
        <div class="mt-field">
            <label class="mt-label">Details (optional)</label>
            <textarea class="mt-input mt-textarea" id="mtGoalDesc" placeholder="Break it down — what does success look like?" style="min-height:60px"></textarea>
        </div>
        <div class="mt-field">
            <label class="mt-label">Target Date (optional)</label>
            <input class="mt-input" type="date" id="mtGoalDate" min="<?php echo date('Y-m-d'); ?>">
        </div>
    </div>
    <div class="mt-sheet-foot">
        <button class="mt-btn-primary" id="mtGoalSubmit" onclick="mtSubmitGoal()">Set Goal</button>
    </div>
    <input type="hidden" id="mtGoalPairId" value="">
    <input type="hidden" id="mtGoalType" value="game">
</div>

<!-- ═══ VIDEO UPLOAD SHEET ═══ -->
<div class="mt-sheet-overlay" id="mtVideoOverlay" onclick="mtCloseSheet('mtVideo')"></div>
<div class="mt-sheet" id="mtVideoSheet">
    <div class="mt-sheet-handle"></div>
    <div class="mt-sheet-head">
        <div class="mt-sheet-title">Submit Video</div>
        <button class="mt-sheet-close" onclick="mtCloseSheet('mtVideo')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mt-sheet-body">
        <div class="mt-field">
            <label class="mt-label">Video File</label>
            <div id="mtVideoDropzone" style="border:2px dashed #E5E5E3;border-radius:12px;padding:24px;text-align:center;cursor:pointer;transition:border-color .2s" onclick="document.getElementById('mtVideoFile').click()">
                <div id="mtVideoPreview" style="display:none;margin-bottom:12px">
                    <video id="mtVideoPreviewPlayer" style="width:100%;max-height:180px;border-radius:8px;background:#000" controls></video>
                </div>
                <div id="mtVideoPrompt">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#A3A3A3" stroke-width="1.5" style="margin-bottom:8px"><rect x="2" y="3" width="20" height="14" rx="2"/><polygon points="22 11 27 8 27 16 22 13" style="display:none"/><line x1="12" y1="8" x2="12" y2="14"/><line x1="9" y1="11" x2="15" y2="11"/></svg>
                    <div style="font-size:13px;font-weight:600;color:#525252">Tap to select video</div>
                    <div style="font-size:11px;color:#A3A3A3;margin-top:4px">MP4, MOV, or WebM &middot; Max 100MB</div>
                </div>
            </div>
            <input type="file" id="mtVideoFile" accept="video/*" style="display:none" onchange="mtPreviewVideo(this)">
        </div>
        <div class="mt-field">
            <label class="mt-label">Note for Your Coach</label>
            <textarea class="mt-input mt-textarea" id="mtVideoNote" placeholder="What should they look for? e.g. 'Watch my first touch at 0:32 — am I turning the right way?'" style="min-height:60px"></textarea>
        </div>
        <div id="mtVideoProgress" style="display:none;margin-top:8px">
            <div style="height:4px;background:#EAEAE6;border-radius:2px;overflow:hidden">
                <div id="mtVideoProgressBar" style="height:100%;width:0%;background:#FCB900;border-radius:2px;transition:width .3s"></div>
            </div>
            <div style="font-size:11px;color:#737373;margin-top:4px;text-align:center" id="mtVideoProgressText">Uploading...</div>
        </div>
    </div>
    <div class="mt-sheet-foot">
        <button class="mt-btn-primary" id="mtVideoSubmit" onclick="mtSubmitVideo()">Upload & Submit for Review</button>
    </div>
    <input type="hidden" id="mtVideoPairId" value="">
</div>

<!-- ═══ SESSION COMPLETION SHEET (Trainer) ═══ -->
<?php if ($dashboard_type === 'trainer'): ?>
<div class="mt-sheet-overlay" id="mtSessionCompleteOverlay" onclick="mtCloseSheet('mtSessionComplete')"></div>
<div class="mt-sheet" id="mtSessionCompleteSheet">
    <div class="mt-sheet-handle"></div>
    <div class="mt-sheet-head">
        <div class="mt-sheet-title">Complete Session</div>
        <button class="mt-sheet-close" onclick="mtCloseSheet('mtSessionComplete')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mt-sheet-body">
        <div style="background:#22C55E10;border:1px solid #22C55E40;border-radius:10px;padding:12px;margin-bottom:16px;display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;border-radius:50%;background:#22C55E;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
                <div style="font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;color:#166534" id="mtCompletePlayerName">Session with Player</div>
                <div style="font-size:11px;color:#22C55E" id="mtCompleteSessionInfo">Session #1 &middot; 30 min</div>
            </div>
        </div>

        <div class="mt-field">
            <label class="mt-label">Session Notes (private)</label>
            <textarea class="mt-input mt-textarea" id="mtCompleteNotes" placeholder="What did you cover? Any breakthroughs or concerns?"></textarea>
        </div>

        <div class="mt-field">
            <label class="mt-label">Parent Summary (sent to parent)</label>
            <textarea class="mt-input mt-textarea" id="mtCompleteParentSummary" placeholder="Quick recap the parent can read: what their kid worked on, what went well, what to focus on..."></textarea>
        </div>

        <div class="mt-field">
            <label class="mt-label">This Week's Mission</label>
            <input class="mt-input" id="mtCompleteAction" placeholder='e.g. "Practice Cruyff turns 10x each foot before next session"'>
        </div>

        <div class="mt-field">
            <label class="mt-label">Energy Level</label>
            <div class="mt-energy" id="mtCompleteEnergy">
                <div class="mt-energy-dot" data-val="1" onclick="mtSetEnergy(this,1)">1</div>
                <div class="mt-energy-dot" data-val="2" onclick="mtSetEnergy(this,2)">2</div>
                <div class="mt-energy-dot" data-val="3" onclick="mtSetEnergy(this,3)">3</div>
                <div class="mt-energy-dot active" data-val="4" onclick="mtSetEnergy(this,4)">4</div>
                <div class="mt-energy-dot" data-val="5" onclick="mtSetEnergy(this,5)">5</div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:10px;color:#A3A3A3;margin-top:4px;padding:0 4px">
                <span>Low energy</span><span>On fire</span>
            </div>
        </div>
    </div>
    <div class="mt-sheet-foot" style="display:flex;flex-direction:column;gap:8px">
        <button class="mt-btn-primary" id="mtCompleteSubmit" onclick="mtSubmitSessionComplete()">Complete & Send to Parent</button>
        <button class="mt-btn-secondary" onclick="mtCloseSheet('mtSessionComplete')">Save as Draft</button>
    </div>
    <input type="hidden" id="mtCompleteSessionId" value="">
    <input type="hidden" id="mtCompletePairId" value="">
    <input type="hidden" id="mtCompleteEnergyVal" value="4">
</div>

<!-- ═══ SCHEDULE MENTORSHIP SESSION SHEET (Trainer) ═══ -->
<div class="mt-sheet-overlay" id="mtScheduleOverlay" onclick="mtCloseSheet('mtSchedule')"></div>
<div class="mt-sheet" id="mtScheduleSheet">
    <div class="mt-sheet-handle"></div>
    <div class="mt-sheet-head">
        <div class="mt-sheet-title">Schedule Session</div>
        <button class="mt-sheet-close" onclick="mtCloseSheet('mtSchedule')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mt-sheet-body">
        <div class="mt-field">
            <label class="mt-label">Mentee</label>
            <select class="mt-input" id="mtScheduleMentee" style="font-size:16px">
                <option value="">Select mentee...</option>
            </select>
        </div>
        <div class="mt-field">
            <label class="mt-label">Date</label>
            <input class="mt-input" type="date" id="mtScheduleDate" min="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="mt-field">
            <label class="mt-label">Time (ET)</label>
            <input class="mt-input" type="time" id="mtScheduleTime" value="16:00">
        </div>
        <div class="mt-field">
            <label class="mt-label">Duration</label>
            <div style="display:flex;gap:8px">
                <button type="button" class="mt-goal-chip" data-dur="30" onclick="mtPickDuration(this,30)" style="--chip-color:#FCB900;--chip-bg:#FCB90012;flex:1">30 min</button>
                <button type="button" class="mt-goal-chip active" data-dur="45" onclick="mtPickDuration(this,45)" style="--chip-color:#FCB900;--chip-bg:#FCB90012;flex:1">45 min</button>
                <button type="button" class="mt-goal-chip" data-dur="60" onclick="mtPickDuration(this,60)" style="--chip-color:#FCB900;--chip-bg:#FCB90012;flex:1">60 min</button>
            </div>
        </div>
        <div class="mt-field">
            <label class="mt-label">Session Type</label>
            <select class="mt-input" id="mtScheduleType" style="font-size:16px">
                <option value="one_on_one">1:1 Call</option>
                <option value="film_breakdown">Film Breakdown</option>
                <option value="group">Group Session</option>
            </select>
        </div>
        <div class="mt-field">
            <label class="mt-label">Pre-session Note (optional)</label>
            <textarea class="mt-input mt-textarea" id="mtScheduleNote" placeholder="What to focus on, any prep the player should do..." style="min-height:50px"></textarea>
        </div>
    </div>
    <div class="mt-sheet-foot">
        <button class="mt-btn-primary" id="mtScheduleSubmit" onclick="mtSubmitSchedule()">Schedule Session</button>
    </div>
    <input type="hidden" id="mtScheduleDuration" value="45">
</div>
<?php endif; ?>

<!-- ═══ REQUEST SESSION SHEET (Parent) ═══ -->
<?php if ($dashboard_type === 'parent'): ?>
<div class="mt-sheet-overlay" id="mtRequestOverlay" onclick="mtCloseSheet('mtRequest')"></div>
<div class="mt-sheet" id="mtRequestSheet">
    <div class="mt-sheet-handle"></div>
    <div class="mt-sheet-head">
        <div class="mt-sheet-title">Request Session</div>
        <button class="mt-sheet-close" onclick="mtCloseSheet('mtRequest')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="mt-sheet-body">
        <p style="font-size:13px;color:#737373;line-height:1.5;margin-bottom:16px">Your coach will get notified and confirm the session time. You'll receive a Zoom link before the call.</p>
        <div class="mt-field">
            <label class="mt-label">Preferred Day</label>
            <input class="mt-input" type="date" id="mtRequestDate" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
        </div>
        <div class="mt-field">
            <label class="mt-label">Preferred Time</label>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach(['Morning (9-12)', 'Afternoon (12-4)', 'Evening (4-8)'] as $i => $t): ?>
                <div class="mt-goal-chip<?php echo $i===2?' active':''; ?>" data-time="<?php echo $i; ?>" onclick="mtPickTime(this)" style="--chip-color:#FCB900;--chip-bg:#FCB90012"><?php echo $t; ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="mt-field">
            <label class="mt-label">What do you want to work on?</label>
            <textarea class="mt-input mt-textarea" id="mtRequestNote" placeholder="e.g. Film review from Saturday's game, or working on confidence before tryouts..." style="min-height:60px"></textarea>
        </div>
    </div>
    <div class="mt-sheet-foot">
        <button class="mt-btn-primary" onclick="mtSubmitRequest()">Send Request</button>
    </div>
    <input type="hidden" id="mtRequestPairId" value="">
    <input type="hidden" id="mtRequestTimeSlot" value="2">
</div>
<?php endif; ?>

<!-- ═══ MENTORSHIP JS ═══ -->
<script>
(function(){
    var AJAX='<?php echo $_ajax; ?>', NONCE='<?php echo $_nonce; ?>';

    // ── Sheet System ──
    window.mtOpenSheet=function(name){
        var o=document.getElementById(name+'Overlay'),s=document.getElementById(name+'Sheet');
        if(o)o.classList.add('open');if(s)s.classList.add('open');
        document.body.style.overflow='hidden';
    };
    window.mtCloseSheet=function(name){
        var o=document.getElementById(name+'Overlay'),s=document.getElementById(name+'Sheet');
        if(s)s.classList.remove('open');
        setTimeout(function(){if(o)o.classList.remove('open');document.body.style.overflow=''},350);
    };

    // ── Goal Sheet ──
    window.openMentorshipGoalModal=function(pairId){
        document.getElementById('mtGoalPairId').value=pairId;
        document.getElementById('mtGoalTitle').value='';
        document.getElementById('mtGoalDesc').value='';
        document.getElementById('mtGoalDate').value='';
        document.getElementById('mtGoalType').value='game';
        document.querySelectorAll('#mtGoalSheet .mt-goal-chip').forEach(function(c){c.classList.remove('active')});
        document.querySelector('#mtGoalSheet .mt-goal-chip[data-type="game"]').classList.add('active');
        mtOpenSheet('mtGoal');
    };
    window.mtPickGoalType=function(el){
        el.parentElement.querySelectorAll('.mt-goal-chip').forEach(function(c){c.classList.remove('active')});
        el.classList.add('active');
        document.getElementById('mtGoalType').value=el.dataset.type;
    };
    window.mtSubmitGoal=function(){
        var title=document.getElementById('mtGoalTitle').value.trim();
        if(!title){document.getElementById('mtGoalTitle').focus();return}
        var btn=document.getElementById('mtGoalSubmit');
        btn.disabled=true;btn.textContent='Saving...';
        var fd=new FormData();
        fd.append('action','ptp_mentorship_set_goal');
        fd.append('nonce',NONCE);
        fd.append('pair_id',document.getElementById('mtGoalPairId').value);
        fd.append('title',title);
        fd.append('description',document.getElementById('mtGoalDesc').value.trim());
        fd.append('goal_type',document.getElementById('mtGoalType').value);
        fd.append('target_date',document.getElementById('mtGoalDate').value);
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            if(d.success){mtCloseSheet('mtGoal');showToast('Goal set!','success');setTimeout(function(){location.reload()},800)}
            else{showToast(d.data||'Error','error');btn.disabled=false;btn.textContent='Set Goal'}
        }).catch(function(){btn.disabled=false;btn.textContent='Set Goal'});
    };

    // ── Video Upload ──
    window.openMentorshipVideoModal=function(pairId){
        document.getElementById('mtVideoPairId').value=pairId;
        document.getElementById('mtVideoNote').value='';
        document.getElementById('mtVideoFile').value='';
        document.getElementById('mtVideoPreview').style.display='none';
        document.getElementById('mtVideoPrompt').style.display='';
        document.getElementById('mtVideoProgress').style.display='none';
        mtOpenSheet('mtVideo');
    };
    window.mtPreviewVideo=function(input){
        if(!input.files.length)return;
        var file=input.files[0];
        if(file.size>104857600){showToast('File too large. Max 100MB.','error');return}
        var url=URL.createObjectURL(file);
        document.getElementById('mtVideoPreviewPlayer').src=url;
        document.getElementById('mtVideoPreview').style.display='';
        document.getElementById('mtVideoPrompt').style.display='none';
        document.getElementById('mtVideoDropzone').style.borderColor='#FCB900';
    };
    window.mtSubmitVideo=function(){
        var fileInput=document.getElementById('mtVideoFile');
        if(!fileInput.files.length){showToast('Select a video first','error');return}
        var btn=document.getElementById('mtVideoSubmit');
        btn.disabled=true;btn.textContent='Uploading...';
        document.getElementById('mtVideoProgress').style.display='';
        var fd=new FormData();
        fd.append('action','ptp_mentorship_upload_video');
        fd.append('nonce',NONCE);
        fd.append('pair_id',document.getElementById('mtVideoPairId').value);
        fd.append('video',fileInput.files[0]);
        fd.append('player_note',document.getElementById('mtVideoNote').value.trim());
        var xhr=new XMLHttpRequest();
        xhr.upload.onprogress=function(e){
            if(e.lengthComputable){
                var pct=Math.round((e.loaded/e.total)*100);
                document.getElementById('mtVideoProgressBar').style.width=pct+'%';
                document.getElementById('mtVideoProgressText').textContent=pct+'% uploaded';
            }
        };
        xhr.onload=function(){
            try{
                var d=JSON.parse(xhr.responseText);
                if(d.success){mtCloseSheet('mtVideo');showToast('Video submitted!','success');setTimeout(function(){location.reload()},800)}
                else{showToast(d.data||'Upload failed','error');btn.disabled=false;btn.textContent='Upload & Submit for Review'}
            }catch(e){showToast('Upload failed','error');btn.disabled=false;btn.textContent='Upload & Submit for Review'}
        };
        xhr.onerror=function(){showToast('Upload failed','error');btn.disabled=false;btn.textContent='Upload & Submit for Review'};
        xhr.open('POST',AJAX);
        xhr.send(fd);
    };

    // ── Session Complete (Trainer) ──
    window.mtOpenSessionComplete=function(sessionId,pairId,playerName,sessionNum,duration){
        document.getElementById('mtCompleteSessionId').value=sessionId;
        document.getElementById('mtCompletePairId').value=pairId;
        document.getElementById('mtCompletePlayerName').textContent='Session with '+playerName;
        document.getElementById('mtCompleteSessionInfo').textContent='Session #'+(sessionNum||'?')+' \u00b7 '+(duration||30)+' min';
        document.getElementById('mtCompleteNotes').value='';
        document.getElementById('mtCompleteParentSummary').value='';
        document.getElementById('mtCompleteAction').value='';
        document.getElementById('mtCompleteEnergyVal').value='4';
        document.querySelectorAll('#mtCompleteEnergy .mt-energy-dot').forEach(function(d){d.classList.toggle('active',d.dataset.val==='4')});
        mtOpenSheet('mtSessionComplete');
    };
    window.mtSetEnergy=function(el,val){
        el.parentElement.querySelectorAll('.mt-energy-dot').forEach(function(d){d.classList.toggle('active',parseInt(d.dataset.val)<=val)});
        document.getElementById('mtCompleteEnergyVal').value=val;
    };
    window.mtSubmitSessionComplete=function(){
        var btn=document.getElementById('mtCompleteSubmit');
        btn.disabled=true;btn.textContent='Completing...';
        var fd=new FormData();
        fd.append('action','ptp_mentorship_complete_session');
        fd.append('nonce',NONCE);
        fd.append('session_id',document.getElementById('mtCompleteSessionId').value);
        fd.append('pair_id',document.getElementById('mtCompletePairId').value);
        fd.append('trainer_notes',document.getElementById('mtCompleteNotes').value.trim());
        fd.append('parent_summary',document.getElementById('mtCompleteParentSummary').value.trim());
        fd.append('action_item',document.getElementById('mtCompleteAction').value.trim());
        fd.append('energy_rating',document.getElementById('mtCompleteEnergyVal').value);
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            if(d.success){mtCloseSheet('mtSessionComplete');showToast('Session completed! Recap sent to parent.','success');setTimeout(function(){location.reload()},1000)}
            else{showToast(d.data||'Error','error');btn.disabled=false;btn.textContent='Complete & Send to Parent'}
        }).catch(function(){btn.disabled=false;btn.textContent='Complete & Send to Parent'});
    };

    // ── Schedule Session (Trainer) ──
    window.mtOpenSchedule=function(mentees){
        var sel=document.getElementById('mtScheduleMentee');
        sel.innerHTML='<option value="">Select mentee...</option>';
        if(mentees&&mentees.length){
            mentees.forEach(function(m){
                var opt=document.createElement('option');
                opt.value=m.id;opt.textContent=m.name+' ('+m.package+')';
                sel.appendChild(opt);
            });
        }
        document.getElementById('mtScheduleDate').value='';
        document.getElementById('mtScheduleNote').value='';
        mtOpenSheet('mtSchedule');
    };
    window.mtPickDuration=function(el,dur){
        el.parentElement.querySelectorAll('.mt-goal-chip').forEach(function(c){c.classList.remove('active')});
        el.classList.add('active');
        document.getElementById('mtScheduleDuration').value=dur;
    };
    window.mtSubmitSchedule=function(){
        var menteeId=document.getElementById('mtScheduleMentee').value;
        var date=document.getElementById('mtScheduleDate').value;
        var time=document.getElementById('mtScheduleTime').value;
        if(!menteeId||!date||!time){showToast('Fill in all fields','error');return}
        var btn=document.getElementById('mtScheduleSubmit');
        btn.disabled=true;btn.textContent='Scheduling...';
        var fd=new FormData();
        fd.append('action','ptp_mentorship_schedule_session');
        fd.append('nonce',NONCE);
        fd.append('pair_id',menteeId);
        fd.append('date',date);
        fd.append('time',time);
        fd.append('duration',document.getElementById('mtScheduleDuration').value);
        fd.append('session_type',document.getElementById('mtScheduleType').value);
        fd.append('pre_session_note',document.getElementById('mtScheduleNote').value.trim());
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            if(d.success){mtCloseSheet('mtSchedule');showToast('Session scheduled!','success');setTimeout(function(){location.reload()},1000)}
            else{showToast(d.data||'Error','error');btn.disabled=false;btn.textContent='Schedule Session'}
        }).catch(function(){btn.disabled=false;btn.textContent='Schedule Session'});
    };

    // ── Request Session (Parent) ──
    window.requestMentorshipSession=function(pairId,trainerName){
        document.getElementById('mtRequestPairId').value=pairId;
        document.getElementById('mtRequestDate').value='';
        document.getElementById('mtRequestNote').value='';
        mtOpenSheet('mtRequest');
    };
    window.mtPickTime=function(el){
        el.parentElement.querySelectorAll('.mt-goal-chip').forEach(function(c){c.classList.remove('active')});
        el.classList.add('active');
        document.getElementById('mtRequestTimeSlot').value=el.dataset.time;
    };
    window.mtSubmitRequest=function(){
        var fd=new FormData();
        fd.append('action','ptp_mentorship_request_session');
        fd.append('nonce',NONCE);
        fd.append('pair_id',document.getElementById('mtRequestPairId').value);
        fd.append('preferred_date',document.getElementById('mtRequestDate').value);
        fd.append('preferred_time',document.getElementById('mtRequestTimeSlot').value);
        fd.append('note',document.getElementById('mtRequestNote').value.trim());
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            if(d.success){mtCloseSheet('mtRequest');showToast('Request sent! Your coach will confirm.','success')}
            else showToast(d.data||'Error','error');
        });
    };

    // ── Goal Complete (both dashboards) ──
    window.completeMentorshipGoal=function(goalId,btn){
        if(btn)btn.textContent='...';
        var fd=new FormData();
        fd.append('action','ptp_mentorship_complete_goal');
        fd.append('nonce',NONCE);
        fd.append('goal_id',goalId);
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            if(d.success){showToast('Goal completed!','success');setTimeout(function(){location.reload()},800)}
            else{showToast(d.data||'Error','error');if(btn)btn.textContent='Done'}
        });
    };

    // ── Toast (safe fallback if dashboard's showToast isn't loaded yet) ──
    if(typeof window.showToast!=='function'){
        window.showToast=function(msg,type){
            var t=document.getElementById('toast');
            if(!t){t=document.createElement('div');t.id='toast';t.style.cssText='position:fixed;bottom:80px;left:50%;transform:translateX(-50%);padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:99999;opacity:0;transition:opacity .3s;pointer-events:none';document.body.appendChild(t)}
            t.textContent=msg;
            t.style.background=type==='error'?'#DC2626':'#0A0A0A';
            t.style.color=type==='error'?'#fff':'#FCB900';
            t.style.opacity='1';
            setTimeout(function(){t.style.opacity='0'},2500);
        };
    }

    // ── Recap toggle (parent dashboard) ──
    window.toggleMentorRecap=function(btn){
        var body=btn.parentElement.querySelector('.recap-body');
        var chev=btn.querySelector('.recap-chev');
        if(body){
            var show=body.style.display==='none';
            body.style.display=show?'':'none';
            if(chev)chev.innerHTML=show?'&#8964;':'&#8250;';
        }
    };
})();
</script>
