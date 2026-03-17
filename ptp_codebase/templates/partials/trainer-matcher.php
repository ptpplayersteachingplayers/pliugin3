<?php
/**
 * Trainer Matcher Partial - Hybrid Guided Widget
 * Step 1: State + Goals → Step 2: Matched Trainers
 * 
 * Expects $trainers array and $count to be set by parent template.
 * PTP design: #FCB900 gold, #0A0A0A black, Oswald/Inter, sharp edges.
 * Mobile-first: 48px+ touch targets, safe area insets, full-width CTAs.
 * No emojis — SVG icons only.
 * 
 * @since v191
 */
defined('ABSPATH') || exit;

// Build trainer data for JS matching
$matcher_trainers = [];
foreach ($trainers as $t) {
    $training_locs = [];
    if (!empty($t->training_locations)) {
        $decoded = json_decode($t->training_locations, true);
        if (is_array($decoded)) $training_locs = $decoded;
    }
    
    $matcher_trainers[] = [
        'id'           => (int) $t->id,
        'name'         => $t->display_name,
        'slug'         => $t->slug,
        'photo'        => $t->photo_url ?? '',
        'headline'     => $t->headline ?? '',
        'bio'          => substr($t->bio ?? '', 0, 300),
        'rate'         => (float) $t->hourly_rate,
        'state'        => strtoupper($t->state ?? ''),
        'city'         => $t->city ?? '',
        'level'        => $t->playing_level ?? '',
        'college'      => $t->college ?? '',
        'team'         => $t->team ?? '',
        'specialties'  => $t->specialties ?? '',
        'coaching_why' => substr($t->coaching_why ?? '', 0, 200),
        'philosophy'   => substr($t->training_philosophy ?? '', 0, 200),
        'rating'       => (float) ($t->average_rating ?? $t->avg_rating ?? 5.0),
        'reviews'      => (int) ($t->review_count ?? $t->reviews ?? 0),
        'sessions'     => (int) ($t->total_sessions ?? $t->sessions ?? 0),
        'featured'     => !empty($t->is_featured),
        'locations'    => $training_locs,
    ];
}
$base_url = home_url('/trainer/');
?>

<style>
/* ======= TRAINER MATCHER — MOBILE-FIRST ======= */
.tm{
    --gold:#FCB900;--black:#0A0A0A;--dark:#1a1a1a;--gray3:#6B7280;--gray2:#E5E7EB;
    --safe-b:env(safe-area-inset-bottom,0px);
    --safe-l:env(safe-area-inset-left,0px);
    --safe-r:env(safe-area-inset-right,0px);
    background:#0A0A0A;
    padding:0;
    font-family:'Inter',-apple-system,sans-serif;
    position:relative;
    overflow:hidden;
    -webkit-tap-highlight-color:transparent;
}
.tm *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
.tm::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 30% 20%,rgba(252,185,0,0.08) 0%,transparent 60%);pointer-events:none}

.tm-inner{
    max-width:800px;
    margin:0 auto;
    padding:32px 16px 36px;
    padding-left:calc(16px + var(--safe-l));
    padding-right:calc(16px + var(--safe-r));
    position:relative;
    z-index:1;
}
@media(min-width:600px){.tm-inner{padding:40px 24px 44px}}

.tm-eye{
    display:inline-block;
    font-family:'Oswald',sans-serif;
    font-size:11px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:0.15em;
    color:var(--gold);
    border:2px solid var(--gold);
    padding:5px 14px;
    margin-bottom:14px;
}

.tm h2{
    font-family:'Oswald',sans-serif;
    font-size:28px;
    font-weight:700;
    text-transform:uppercase;
    color:#fff;
    margin:0 0 6px;
    line-height:1.1;
}
.tm h2 span{color:var(--gold)}
@media(min-width:480px){.tm h2{font-size:36px}}
@media(min-width:768px){.tm h2{font-size:42px}}

.tm-sub{
    color:rgba(255,255,255,0.7);
    font-size:14px;
    margin:0 0 24px;
    line-height:1.5;
}
@media(min-width:600px){.tm-sub{font-size:15px;margin-bottom:28px}}

/* Progress dots */
.tm-prog{display:flex;gap:8px;margin-bottom:20px;justify-content:center}
@media(min-width:600px){.tm-prog{margin-bottom:24px}}
.tm-dot{width:10px;height:10px;border:2px solid rgba(255,255,255,0.3);background:transparent;transition:all 0.3s}
.tm-dot.on{background:var(--gold);border-color:var(--gold)}
.tm-dot.done{background:var(--gold);border-color:var(--gold)}

/* Steps */
.tm-step{display:none;animation:tmFadeIn 0.3s ease}
.tm-step.on{display:block}
@keyframes tmFadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* Step 1: State selector — large tap targets */
.tm-states{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:20px}
@media(min-width:600px){.tm-states{gap:10px;margin-bottom:24px}}
.tm-state{
    padding:0;
    width:56px;height:48px;
    background:rgba(255,255,255,0.06);
    border:2px solid rgba(255,255,255,0.15);
    color:rgba(255,255,255,0.8);
    font-family:'Oswald',sans-serif;
    font-size:15px;
    font-weight:600;
    letter-spacing:0.05em;
    cursor:pointer;
    transition:all 0.15s;
    display:flex;align-items:center;justify-content:center;
}
@media(min-width:600px){.tm-state{width:64px;height:50px;font-size:16px}}
.tm-state:active{transform:scale(0.95)}
@media(hover:hover){.tm-state:hover{border-color:var(--gold);color:#fff;background:rgba(252,185,0,0.1)}}
.tm-state.on{background:var(--gold);border-color:var(--gold);color:var(--black)}

/* Goals grid — 2-col mobile, 4-col desktop */
.tm-goals-label{
    font-family:'Oswald',sans-serif;
    font-size:12px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:0.1em;
    color:rgba(255,255,255,0.5);
    margin-bottom:10px;
}
.tm-goals-label span{color:rgba(255,255,255,0.3)}
@media(min-width:600px){.tm-goals-label{font-size:13px;margin-bottom:12px}}

.tm-goals{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:8px;
    margin-bottom:24px;
}
@media(min-width:420px){.tm-goals{gap:10px}}
@media(min-width:600px){.tm-goals{grid-template-columns:repeat(4,1fr);margin-bottom:28px}}

.tm-goal{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px 14px;
    min-height:48px;
    background:rgba(255,255,255,0.04);
    border:2px solid rgba(255,255,255,0.1);
    color:rgba(255,255,255,0.7);
    cursor:pointer;
    transition:all 0.15s;
}
@media(min-width:600px){
    .tm-goal{
        flex-direction:column;
        gap:6px;
        padding:14px 8px;
        text-align:center;
        justify-content:center;
    }
}
.tm-goal:active{transform:scale(0.97)}
@media(hover:hover){.tm-goal:hover{border-color:rgba(252,185,0,0.5);color:#fff;background:rgba(252,185,0,0.06)}}
.tm-goal.on{border-color:var(--gold);color:var(--gold);background:rgba(252,185,0,0.1)}

.tm-goal-ico{width:20px;height:20px;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.tm-goal-ico svg{width:20px;height:20px;stroke:currentColor}
@media(min-width:600px){.tm-goal-ico{width:22px;height:22px}.tm-goal-ico svg{width:22px;height:22px}}

.tm-goal-txt{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em}
@media(min-width:600px){.tm-goal-txt{font-size:11px}}

/* CTA button — full width on mobile */
.tm-cta-wrap{text-align:center}
.tm-cta{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    width:100%;
    max-width:320px;
    padding:16px 36px;
    min-height:52px;
    background:var(--gold);
    color:var(--black);
    font-family:'Oswald',sans-serif;
    font-size:16px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:0.06em;
    border:2px solid var(--gold);
    cursor:pointer;
    transition:all 0.15s;
}
@media(min-width:600px){.tm-cta{width:auto;font-size:15px;padding:14px 36px;min-height:48px}}
.tm-cta:active{transform:scale(0.97)}
@media(hover:hover){.tm-cta:hover{background:#e5a800;border-color:#e5a800}}
.tm-cta:disabled{opacity:0.4;cursor:not-allowed;transform:none}
.tm-cta svg{width:16px;height:16px}

.tm-skip{
    display:block;
    margin:16px auto 0;
    padding:10px;
    min-height:44px;
    background:none;
    border:none;
    color:rgba(255,255,255,0.4);
    font-size:13px;
    cursor:pointer;
    text-decoration:underline;
    text-underline-offset:3px;
}
.tm-skip:hover{color:rgba(255,255,255,0.7)}

/* Step 2: Results */
.tm-results{max-width:700px;margin:0 auto}
.tm-results-hdr{margin-bottom:16px}
@media(min-width:600px){.tm-results-hdr{margin-bottom:20px}}
.tm-results-hdr h3{
    font-family:'Oswald',sans-serif;
    font-size:18px;
    color:#fff;
    text-transform:uppercase;
    margin:0 0 4px;
}
.tm-results-hdr p{color:rgba(255,255,255,0.5);font-size:13px;margin:0}

/* Matched trainer card */
.tm-match{
    display:flex;
    flex-direction:row;
    gap:14px;
    background:rgba(255,255,255,0.05);
    border:2px solid rgba(255,255,255,0.12);
    padding:16px;
    margin-bottom:10px;
    transition:all 0.15s;
    animation:tmFadeIn 0.35s ease;
}
@media(min-width:600px){.tm-match{gap:16px;padding:18px;margin-bottom:12px}}
.tm-match:active{border-color:var(--gold);background:rgba(252,185,0,0.04)}
@media(hover:hover){.tm-match:hover{border-color:var(--gold);background:rgba(252,185,0,0.04)}}

.tm-match-photo{
    width:72px;height:72px;
    flex-shrink:0;
    overflow:hidden;
    border:2px solid var(--gold);
    background:var(--dark);
}
@media(min-width:600px){.tm-match-photo{width:80px;height:80px}}
.tm-match-photo img{width:100%;height:100%;object-fit:cover}
.tm-match-photo .tm-no-photo{
    width:100%;height:100%;display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,#1a1a1a,#2a2a2a);
    font-family:'Oswald',sans-serif;font-size:24px;color:var(--gold);font-weight:700;
}
@media(min-width:600px){.tm-match-photo .tm-no-photo{font-size:28px}}

.tm-match-info{flex:1;min-width:0}
.tm-match-name{
    font-family:'Oswald',sans-serif;
    font-size:15px;
    font-weight:700;
    text-transform:uppercase;
    color:#fff;
    margin:0 0 2px;
    letter-spacing:0.03em;
}
@media(min-width:600px){.tm-match-name{font-size:16px}}
.tm-match-creds{
    font-size:11px;
    color:var(--gold);
    font-weight:600;
    margin-bottom:3px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
@media(min-width:600px){.tm-match-creds{font-size:12px;margin-bottom:4px}}
.tm-match-fit{
    font-size:12px;
    color:rgba(255,255,255,0.6);
    margin-bottom:10px;
    line-height:1.4;
}
@media(min-width:600px){.tm-match-fit{font-size:13px}}

/* Meta row */
.tm-match-meta{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}
@media(min-width:600px){.tm-match-meta{gap:12px}}

.tm-match-rate{
    font-family:'Oswald',sans-serif;
    font-size:16px;
    font-weight:700;
    color:#fff;
}
@media(min-width:600px){.tm-match-rate{font-size:18px}}
.tm-match-rate small{font-size:11px;color:var(--gray3);font-weight:400;font-family:'Inter',sans-serif}

.tm-match-stars{font-size:12px;color:var(--gold);display:flex;align-items:center;gap:3px}
.tm-match-stars span{color:var(--gray3);font-size:11px}

.tm-book{
    padding:10px 16px;
    min-height:40px;
    background:var(--gold);
    color:var(--black);
    font-family:'Oswald',sans-serif;
    font-size:12px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:0.05em;
    text-decoration:none;
    border:2px solid var(--gold);
    transition:all 0.15s;
    display:inline-flex;
    align-items:center;
    gap:5px;
    white-space:nowrap;
    margin-left:auto;
}
@media(min-width:600px){.tm-book{font-size:13px;padding:8px 20px;gap:6px}}
.tm-book:active{transform:scale(0.97)}
@media(hover:hover){.tm-book:hover{background:#e5a800;border-color:#e5a800;color:var(--black);text-decoration:none}}
.tm-book svg{width:14px;height:14px}

/* Full-width book button on very small screens */
@media(max-width:399px){
    .tm-match-meta{flex-direction:column;align-items:flex-start;gap:8px}
    .tm-book{width:100%;justify-content:center;margin-left:0;min-height:44px;font-size:13px}
}

/* Bottom actions — stack on mobile */
.tm-bottom{display:flex;gap:10px;justify-content:center;margin-top:16px;flex-wrap:wrap}
@media(max-width:479px){.tm-bottom{flex-direction:column;gap:8px}}
@media(min-width:600px){.tm-bottom{gap:12px;margin-top:20px}}

.tm-chat-btn,.tm-browse{
    padding:12px 20px;
    min-height:48px;
    background:transparent;
    border:2px solid rgba(255,255,255,0.2);
    color:rgba(255,255,255,0.7);
    font-family:'Oswald',sans-serif;
    font-size:13px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:0.05em;
    cursor:pointer;
    transition:all 0.15s;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    width:100%;
    text-decoration:none;
}
@media(min-width:480px){.tm-chat-btn,.tm-browse{width:auto}}
.tm-chat-btn:active{border-color:var(--gold);color:var(--gold)}
.tm-browse:active{border-color:#fff;color:#fff}
@media(hover:hover){
    .tm-chat-btn:hover{border-color:var(--gold);color:var(--gold)}
    .tm-browse:hover{border-color:#fff;color:#fff;text-decoration:none}
}
.tm-chat-btn svg,.tm-browse svg{width:16px;height:16px}

.tm-again{
    display:block;
    margin:16px auto 0;
    padding:10px;
    min-height:44px;
    background:none;
    border:none;
    color:rgba(255,255,255,0.35);
    font-size:12px;
    cursor:pointer;
    text-decoration:underline;
    text-underline-offset:3px;
}
.tm-again:hover{color:rgba(255,255,255,0.6)}

/* Email capture — stack on mobile */
.tm-capture{
    margin-top:16px;
    padding:16px;
    background:rgba(252,185,0,0.06);
    border:1px solid rgba(252,185,0,0.2);
    text-align:center;
}
@media(min-width:600px){.tm-capture{margin-top:20px}}
.tm-capture p{color:rgba(255,255,255,0.6);font-size:13px;margin:0 0 10px}
.tm-capture-row{display:flex;flex-direction:column;gap:8px;max-width:420px;margin:0 auto}
@media(min-width:480px){.tm-capture-row{flex-direction:row}}
.tm-capture input{
    flex:1;
    padding:12px 14px;
    min-height:48px;
    background:rgba(255,255,255,0.08);
    border:2px solid rgba(255,255,255,0.15);
    color:#fff;
    font-size:14px;
    -webkit-appearance:none;
    border-radius:0;
}
.tm-capture input::placeholder{color:rgba(255,255,255,0.3)}
.tm-capture input:focus{outline:none;border-color:var(--gold)}
.tm-capture-btn{
    padding:12px 18px;
    min-height:48px;
    background:var(--gold);
    color:var(--black);
    border:none;
    font-family:'Oswald',sans-serif;
    font-size:14px;
    font-weight:700;
    text-transform:uppercase;
    cursor:pointer;
    white-space:nowrap;
}
.tm-capture-btn:active{transform:scale(0.97)}
@media(hover:hover){.tm-capture-btn:hover{background:#e5a800}}
.tm-capture-skip{
    display:block;margin:10px auto 0;padding:8px;min-height:36px;background:none;border:none;
    color:rgba(255,255,255,0.3);font-size:12px;cursor:pointer;text-decoration:underline
}

/* Loading state */
.tm-loading{display:none;text-align:center;padding:30px}
.tm-loading.on{display:block}
.tm-spin{width:32px;height:32px;border:3px solid rgba(255,255,255,0.1);border-top-color:var(--gold);border-radius:50%;animation:tmSpin 0.6s linear infinite;margin:0 auto 12px}
@keyframes tmSpin{to{transform:rotate(360deg)}}
.tm-loading p{color:rgba(255,255,255,0.5);font-size:13px}
</style>

<section class="tm" id="trainerMatcher">
<div class="tm-inner">
    <span class="tm-eye">Find Your Trainer</span>
    <h2>30 SECONDS TO YOUR <span>PERFECT MATCH</span></h2>
    <p class="tm-sub">Pick your state, choose what you want to improve, and we'll match you instantly.</p>
    
    <div class="tm-prog">
        <div class="tm-dot on" data-s="1"></div>
        <div class="tm-dot" data-s="2"></div>
    </div>
    
    <!-- Step 1: State + Goals -->
    <div class="tm-step on" data-s="1" id="tmStep1">
        <div class="tm-states" id="tmStates">
            <button class="tm-state" data-v="PA">PA</button>
            <button class="tm-state" data-v="NJ">NJ</button>
            <button class="tm-state" data-v="DE">DE</button>
            <button class="tm-state" data-v="MD">MD</button>
            <button class="tm-state" data-v="NY">NY</button>
        </div>
        
        <div class="tm-goals-label">What does your player want to improve? <span>(pick 1-2)</span></div>
        <div class="tm-goals" id="tmGoals">
            <button class="tm-goal" data-g="weak_foot">
                <span class="tm-goal-ico"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><path d="M4 16s1-2 4-2 5 4 8 4 4-2 4-2"/><path d="M2 12s2-4 6-4 6 4 10 4 4-2 6-4"/></svg></span>
                <span class="tm-goal-txt">Weak Foot</span>
            </button>
            <button class="tm-goal" data-g="speed">
                <span class="tm-goal-ico"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></span>
                <span class="tm-goal-txt">Speed</span>
            </button>
            <button class="tm-goal" data-g="one_v_one">
                <span class="tm-goal-ico"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></span>
                <span class="tm-goal-txt">1v1 Moves</span>
            </button>
            <button class="tm-goal" data-g="confidence">
                <span class="tm-goal-ico"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
                <span class="tm-goal-txt">Confidence</span>
            </button>
            <button class="tm-goal" data-g="first_touch">
                <span class="tm-goal-ico"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg></span>
                <span class="tm-goal-txt">First Touch</span>
            </button>
            <button class="tm-goal" data-g="shooting">
                <span class="tm-goal-ico"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg></span>
                <span class="tm-goal-txt">Shooting</span>
            </button>
            <button class="tm-goal" data-g="game_iq">
                <span class="tm-goal-ico"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><path d="M12 2a7 7 0 017 7c0 2.38-1.19 4.47-3 5.74V17a1 1 0 01-1 1h-6a1 1 0 01-1-1v-2.26C6.19 13.47 5 11.38 5 9a7 7 0 017-7z"/><path d="M9 21h6M10 17v4M14 17v4"/></svg></span>
                <span class="tm-goal-txt">Game IQ</span>
            </button>
            <button class="tm-goal" data-g="passing">
                <span class="tm-goal-ico"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg></span>
                <span class="tm-goal-txt">Passing</span>
            </button>
        </div>
        
        <div class="tm-cta-wrap">
            <button class="tm-cta" id="tmFind" disabled>
                Find My Match
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
            </button>
        </div>
        
        <button class="tm-skip" id="tmSkip">Skip &mdash; browse all trainers</button>
    </div>
    
    <!-- Loading -->
    <div class="tm-loading" id="tmLoading">
        <div class="tm-spin"></div>
        <p>Matching you with the best trainers...</p>
    </div>
    
    <!-- Step 2: Results -->
    <div class="tm-step" data-s="2" id="tmStep2">
        <div class="tm-results" id="tmResults">
            <div class="tm-results-hdr">
                <h3>Your Top Matches</h3>
                <p id="tmResultsSub">Based on your goals</p>
            </div>
            <div id="tmCards"></div>
            
            <div class="tm-capture" id="tmCapture">
                <p>Get matched trainers + new trainer alerts in your area</p>
                <div class="tm-capture-row">
                    <input type="text" id="tmCapName" placeholder="Your name" autocomplete="name">
                    <input type="email" id="tmCapEmail" placeholder="Email address" autocomplete="email">
                    <button class="tm-capture-btn" id="tmCapBtn">Send</button>
                </div>
                <button class="tm-capture-skip" id="tmCapSkip">Skip</button>
            </div>
            
            <div class="tm-bottom">
                <button class="tm-chat-btn" id="tmChatBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    Not sure? Chat with us
                </button>
                <a class="tm-browse" href="#ft-grid-top">
                    Browse All Trainers
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                </a>
            </div>
            
            <button class="tm-again" id="tmAgain">Start over</button>
        </div>
    </div>
</div>
</section>

<script>
(function(){
'use strict';

var trainers = <?php echo wp_json_encode($matcher_trainers); ?>;
var baseUrl = <?php echo wp_json_encode(rtrim($base_url, '/') . '/'); ?>;
var apiUrl = <?php echo wp_json_encode(rest_url('ptp-cc/v1/matcher/')); ?>;

var goalKeywords = {
    weak_foot:   ['weak foot','both feet','two-footed','ambidextrous','non-dominant'],
    speed:       ['speed','agility','quick','fast','explosive','acceleration','footwork'],
    one_v_one:   ['1v1','dribbling','moves','skill moves','take on','beat defender','flair'],
    confidence:  ['confidence','mental','mindset','leadership','mentorship','mentoring'],
    first_touch: ['first touch','ball control','receiving','trapping','control'],
    shooting:    ['shooting','finishing','striking','goals','accuracy','power shot'],
    game_iq:     ['tactical','game intelligence','positioning','vision','decision','awareness','iq','reading'],
    passing:     ['passing','distribution','through ball','playmaking','crossing','delivery']
};

var goalLabels = {
    weak_foot:'Weak Foot', speed:'Speed & Agility', one_v_one:'1v1 Moves',
    confidence:'Confidence', first_touch:'First Touch', shooting:'Shooting',
    game_iq:'Game IQ', passing:'Passing'
};

var levelLabels = {pro:'PRO', college_d1:'NCAA D1', college_d2:'NCAA D2', college_d3:'NCAA D3', academy:'ACADEMY', semi_pro:'SEMI-PRO'};

var selectedState = '';
var selectedGoals = [];

// Auto-detect location on load
(function autoLocate(){
    if(!('geolocation' in navigator)) return;
    navigator.geolocation.getCurrentPosition(function(pos){
        var st = coordsToState(pos.coords.latitude, pos.coords.longitude);
        if(st){
            selectedState = st;
            var btn = document.querySelector('.tm-state[data-v="'+st+'"]');
            if(btn){
                document.querySelectorAll('.tm-state').forEach(function(b){b.classList.remove('on')});
                btn.classList.add('on');
            }
            document.getElementById('tmFind').disabled = false;
        }
    }, function(){/* denied or error — user picks manually */}, {enableHighAccuracy:false, timeout:8000, maximumAge:600000});
})();

// Lat/lng to PTP state — covers PA/NJ/DE/MD/NY service area
function coordsToState(lat, lng){
    // Delaware is small and specific — check first
    if(lat >= 38.45 && lat <= 39.84 && lng >= -75.79 && lng <= -75.04) return 'DE';
    // New Jersey — east of PA, below NY
    if(lat >= 38.93 && lat <= 41.36 && lng >= -75.56 && lng <= -73.89) return 'NJ';
    // Maryland — south/west of PA and DE
    if(lat >= 37.91 && lat <= 39.72 && lng >= -79.49 && lng <= -75.05) return 'MD';
    // Pennsylvania — large box
    if(lat >= 39.72 && lat <= 42.27 && lng >= -80.52 && lng <= -74.69) return 'PA';
    // New York — north and east
    if(lat >= 40.50 && lat <= 45.02 && lng >= -79.76 && lng <= -71.86) return 'NY';
    // Fallback: closest state centroid
    var centroids = {PA:[40.0,-75.5],NJ:[40.2,-74.7],DE:[39.15,-75.5],MD:[39.3,-76.6],NY:[40.7,-74.0]};
    var best = '', bestD = Infinity;
    for(var s in centroids){
        var d = Math.pow(lat-centroids[s][0],2) + Math.pow(lng-centroids[s][1],2);
        if(d < bestD){bestD = d; best = s;}
    }
    return bestD < 25 ? best : ''; // Only match if within ~5 degrees
}

// State buttons
document.querySelectorAll('.tm-state').forEach(function(btn){
    btn.addEventListener('click', function(){
        document.querySelectorAll('.tm-state').forEach(function(b){b.classList.remove('on')});
        this.classList.add('on');
        selectedState = this.dataset.v;
        checkReady();
    });
});

// Goal buttons (max 2)
document.querySelectorAll('.tm-goal').forEach(function(btn){
    btn.addEventListener('click', function(){
        if(this.classList.contains('on')){
            this.classList.remove('on');
            selectedGoals = selectedGoals.filter(function(g){return g !== btn.dataset.g});
        } else {
            if(selectedGoals.length >= 2){
                var oldest = selectedGoals.shift();
                var oldEl = document.querySelector('.tm-goal[data-g="'+oldest+'"]');
                if(oldEl) oldEl.classList.remove('on');
            }
            this.classList.add('on');
            selectedGoals.push(this.dataset.g);
        }
        checkReady();
    });
});

function checkReady(){
    document.getElementById('tmFind').disabled = !selectedState;
}

// Find button
document.getElementById('tmFind').addEventListener('click', function(){
    if(!selectedState) return;
    showLoading();
    setTimeout(function(){ showResults(matchTrainers(selectedState, selectedGoals)); }, 600);
    logInteraction('match', {state: selectedState, goals: selectedGoals});
});

// Skip — scroll to grid
document.getElementById('tmSkip').addEventListener('click', function(){
    var grid = document.getElementById('ft-grid-top') || document.querySelector('.ft-main');
    if(grid) grid.scrollIntoView({behavior:'smooth'});
    logInteraction('skip', {});
});

// Start over
document.getElementById('tmAgain').addEventListener('click', function(){
    selectedState = '';
    selectedGoals = [];
    document.querySelectorAll('.tm-state.on,.tm-goal.on').forEach(function(b){b.classList.remove('on')});
    document.getElementById('tmFind').disabled = true;
    document.getElementById('tmCapture').style.display = '';
    showStep(1);
    var tm = document.getElementById('trainerMatcher');
    if(tm) tm.scrollIntoView({behavior:'smooth'});
});

// Chat fallback — open CC chat widget
document.getElementById('tmChatBtn').addEventListener('click', function(){
    var cw = document.getElementById('ptp-cw');
    if(cw){
        cw.classList.add('open');
        var chatTab = cw.querySelector('[data-tab="chat"]');
        if(chatTab) chatTab.click();
    }
    logInteraction('chat_fallback', {state: selectedState, goals: selectedGoals});
});

// Email capture
document.getElementById('tmCapBtn').addEventListener('click', function(){
    var name = document.getElementById('tmCapName').value.trim();
    var email = document.getElementById('tmCapEmail').value.trim();
    if(!email || email.indexOf('@') === -1){
        document.getElementById('tmCapEmail').style.borderColor = '#EF4444';
        return;
    }
    this.textContent = 'Sent!';
    this.disabled = true;
    
    var matchedNames = [];
    document.querySelectorAll('.tm-match-name').forEach(function(n){matchedNames.push(n.textContent)});
    
    fetch(apiUrl + 'lead', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            name: name, email: email,
            state: selectedState,
            goals: selectedGoals.join(', '),
            matched_trainer: matchedNames.join(', ')
        })
    }).catch(function(){});
    
    setTimeout(function(){
        document.getElementById('tmCapture').style.display = 'none';
    }, 1500);
});

document.getElementById('tmCapSkip').addEventListener('click', function(){
    document.getElementById('tmCapture').style.display = 'none';
});

function showStep(n){
    document.querySelectorAll('.tm-step').forEach(function(s){s.classList.remove('on')});
    document.querySelectorAll('.tm-dot').forEach(function(d){
        var ds = parseInt(d.dataset.s);
        d.classList.toggle('on', ds === n);
        d.classList.toggle('done', ds < n);
    });
    var step = document.querySelector('.tm-step[data-s="'+n+'"]');
    if(step) step.classList.add('on');
    document.getElementById('tmLoading').classList.remove('on');
}

function showLoading(){
    document.querySelectorAll('.tm-step').forEach(function(s){s.classList.remove('on')});
    document.getElementById('tmLoading').classList.add('on');
}

function showResults(matches){
    var cards = document.getElementById('tmCards');
    cards.innerHTML = '';
    
    if(matches.length === 0){
        cards.innerHTML = '<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.5)"><p>No exact matches yet in ' + selectedState + ', but we are growing fast.</p><p style="margin-top:8px"><a href="#ft-grid-top" style="color:#FCB900;text-decoration:underline">Browse all trainers</a></p></div>';
        showStep(2);
        return;
    }
    
    var goalNames = selectedGoals.map(function(g){return goalLabels[g] || g}).join(' & ');
    document.getElementById('tmResultsSub').textContent = goalNames ? 'Best match for ' + goalNames + ' in ' + selectedState : 'Top trainers in ' + selectedState;
    
    matches.forEach(function(m, i){
        var card = document.createElement('div');
        card.className = 'tm-match';
        card.style.animationDelay = (i * 0.1) + 's';
        
        var levelStr = levelLabels[m.level] || '';
        var creds = [levelStr, m.college, m.team].filter(Boolean).join(' \u00B7 ');
        var fitReason = getFitReason(m, selectedGoals);
        var ratingHtml = m.reviews > 0 ? '\u2605 ' + m.rating.toFixed(1) + ' <span>(' + m.reviews + ')</span>' : '\u2605 New';
        var initials = m.name.split(' ').map(function(w){return w[0]}).join('').substring(0,2);
        var firstName = m.name.split(' ')[0];
        
        card.innerHTML = 
            '<div class="tm-match-photo">' +
                (m.photo ? '<img src="'+m.photo+'" alt="'+m.name+'" loading="lazy">' : '<div class="tm-no-photo">'+initials+'</div>') +
            '</div>' +
            '<div class="tm-match-info">' +
                '<div class="tm-match-name">'+m.name+'</div>' +
                (creds ? '<div class="tm-match-creds">'+creds+'</div>' : '') +
                '<div class="tm-match-fit">'+fitReason+'</div>' +
                '<div class="tm-match-meta">' +
                    '<span class="tm-match-rate">$'+m.rate+'<small>/hr</small></span>' +
                    '<span class="tm-match-stars">'+ratingHtml+'</span>' +
                    '<a href="'+baseUrl+m.slug+'/" class="tm-book">Book '+firstName+' <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>' +
                '</div>' +
            '</div>';
        
        cards.appendChild(card);
    });
    
    showStep(2);
    var tm = document.getElementById('trainerMatcher');
    if(tm && window.innerWidth < 600) tm.scrollIntoView({behavior:'smooth'});
}

function matchTrainers(state, goals){
    var scored = trainers.map(function(t){
        var score = 0;
        
        if(t.state === state) score += 50;
        if(t.locations && t.locations.length){
            for(var i=0;i<t.locations.length;i++){
                if(t.locations[i].toUpperCase().indexOf(state) !== -1){score += 30;break}
            }
        }
        
        var searchable = (t.specialties+' '+t.bio+' '+t.coaching_why+' '+t.headline+' '+t.philosophy).toLowerCase();
        goals.forEach(function(g){
            var kws = goalKeywords[g] || [];
            for(var i=0;i<kws.length;i++){
                if(searchable.indexOf(kws[i]) !== -1){score += 20;break}
            }
        });
        
        if(t.featured) score += 10;
        if(t.photo) score += 5;
        if(t.rating >= 4.5) score += 5;
        
        return {trainer:t, score:score};
    });
    
    scored.sort(function(a,b){return b.score - a.score});
    var relevant = scored.filter(function(s){return s.score >= 30});
    if(relevant.length === 0) relevant = scored.slice(0,2);
    
    return relevant.slice(0,3).map(function(s){return s.trainer});
}

function getFitReason(trainer, goals){
    var searchable = (trainer.specialties+' '+trainer.bio+' '+trainer.headline).toLowerCase();
    var matched = [];
    
    goals.forEach(function(g){
        var kws = goalKeywords[g] || [];
        for(var i=0;i<kws.length;i++){
            if(searchable.indexOf(kws[i]) !== -1){
                matched.push(goalLabels[g]);
                break;
            }
        }
    });
    
    if(matched.length > 0) return 'Specializes in ' + matched.join(' & ');
    
    var level = levelLabels[trainer.level] || '';
    if(level) return level + ' experience with personalized training approach';
    if(trainer.headline) return trainer.headline;
    return 'Top-rated trainer in your area';
}

function logInteraction(type, data){
    try{
        fetch(apiUrl + 'interaction', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action_type:type, data:data})
        }).catch(function(){});
    }catch(e){}
}

})();
</script>
