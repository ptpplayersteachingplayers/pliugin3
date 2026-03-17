<?php
/**
 * Mentorship Dashboard Template v2
 * Session-package model. Shown when parent has mentorship pairs.
 * Loaded by [ptp_mentorship_hub] shortcode.
 */
defined('ABSPATH') || exit;

$parent_id = get_current_user_id();
global $wpdb;

$pairs = $wpdb->get_results($wpdb->prepare(
    "SELECT p.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug,
            pl.first_name as player_name, pl.age as player_age
     FROM {$wpdb->prefix}ptp_mentorship_pairs p
     JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
     LEFT JOIN {$wpdb->prefix}ptp_players pl ON p.player_id = pl.id
     WHERE p.parent_id = %d AND p.status IN ('active','paused','interest','intro_scheduled','intro_done','completed')
     ORDER BY FIELD(p.status, 'active','paused','intro_done','intro_scheduled','interest','completed'), p.created_at DESC",
    $parent_id
));

$pair = $pairs[0] ?? null;
$pkg = $pair ? (PTP_Mentorship::PACKAGES[$pair->package_type] ?? null) : null;

if ($pair) {
    $goals = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_mentorship_goals WHERE pair_id = %d ORDER BY status ASC, created_at DESC LIMIT 10", $pair->id
    ));
    $videos = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_mentorship_videos WHERE pair_id = %d ORDER BY created_at DESC LIMIT 10", $pair->id
    ));
    $milestones = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_mentorship_milestones WHERE pair_id = %d ORDER BY awarded_at DESC", $pair->id
    ));
    $sessions = $wpdb->get_results($wpdb->prepare(
        "SELECT s.* FROM {$wpdb->prefix}ptp_mentorship_sessions s
         JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
         WHERE a.pair_id = %d AND s.scheduled_at > NOW() AND s.status = 'scheduled'
         ORDER BY s.scheduled_at ASC LIMIT 5", $pair->id
    ));
    $plan = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_mentorship_plans WHERE pair_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT 1", $pair->id
    ));
}

$pkg_name = $pkg['name'] ?? 'Mentorship';
$pkg_color = $pkg['color'] ?? '#525252';
$sessions_left = $pair ? max(0, $pair->sessions_total - $pair->sessions_completed) : 0;
$progress_pct = $pair ? round(($pair->sessions_completed / max(1, $pair->sessions_total)) * 100) : 0;
?>

<?php if (!$pair): ?>
<div style="text-align:center;padding:60px 20px;max-width:500px;margin:0 auto">
    <div style="font-size:48px;margin-bottom:16px">&#9917;</div>
    <h2 style="font-family:Oswald,sans-serif;text-transform:uppercase;font-size:24px;margin-bottom:12px">No Active Mentorship</h2>
    <p style="color:#737373;margin-bottom:24px">Find a coach from your camp experience and start a mentorship package.</p>
    <a href="/mentorship/" style="display:inline-block;background:#FCB900;color:#0A0A0A;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;padding:14px 32px;border-radius:10px;text-decoration:none;font-size:14px;letter-spacing:1px">Browse Coaches</a>
</div>
<?php return; endif; ?>

<?php
// ── PENDING STATES: interest / intro_scheduled / intro_done (before active dashboard) ──
$pending_statuses = array('interest', 'intro_scheduled', 'intro_done');
if (in_array($pair->status, $pending_statuses)):
    $trainer_first = explode(' ', $pair->trainer_name)[0];
?>
<style>
:root{--gold:#FCB900;--black:#0A0A0A;--white:#FFF;--surface:#F8F8F6;--border:#EAEAE6;--muted:#737373;--success:#22C55E}
*{box-sizing:border-box}
.md-pending-wrap{max-width:520px;margin:0 auto;padding:0 16px 40px;font-family:'Inter',system-ui,sans-serif}
.md-pending-card{background:var(--white);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:16px;text-align:center}
.md-pending-step{display:flex;align-items:flex-start;gap:14px;text-align:left;padding:14px 0;border-bottom:1px solid var(--border)}
.md-pending-step:last-child{border-bottom:0}
.md-step-num{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:Oswald,sans-serif;font-weight:700;font-size:14px;flex-shrink:0}
.md-step-done{background:var(--success);color:var(--white)}
.md-step-active{background:var(--gold);color:var(--black);box-shadow:0 0 0 4px rgba(252,185,0,0.2)}
.md-step-pending{background:var(--surface);color:var(--muted);border:2px solid var(--border)}
.md-step-text h4{font-family:Oswald,sans-serif;text-transform:uppercase;font-size:13px;margin:0 0 2px;letter-spacing:0.5px}
.md-step-text p{font-size:12px;color:var(--muted);margin:0;line-height:1.4}
</style>
<div class="md-pending-wrap">
    <!-- Trainer card -->
    <div style="background:var(--black);border-radius:16px;padding:24px;margin-bottom:16px;text-align:center;color:var(--white)">
        <?php if ($pair->trainer_photo): ?>
        <img src="<?php echo esc_url($pair->trainer_photo); ?>" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid var(--gold);margin-bottom:12px" alt="">
        <?php endif; ?>
        <div style="font-family:Oswald,sans-serif;font-size:10px;color:var(--gold);text-transform:uppercase;letter-spacing:2px;margin-bottom:4px">Mentorship Request</div>
        <h2 style="font-family:Oswald,sans-serif;font-size:22px;text-transform:uppercase;margin:0 0 4px;letter-spacing:0.5px">
            <?php echo esc_html($pair->player_name ?: 'Your Player'); ?> &times; <?php echo esc_html($pair->trainer_name); ?>
        </h2>
        <div style="font-size:13px;color:#A3A3A3;margin-top:6px">
            <?php if ($pair->status === 'interest'): ?>
                Your interest form has been submitted. <?php echo esc_html($trainer_first); ?> will reach out to schedule your free intro call.
            <?php elseif ($pair->status === 'intro_scheduled'): ?>
                Your intro call with <?php echo esc_html($trainer_first); ?> is coming up! Check your email for details.
            <?php else: ?>
                Your intro call is done! Choose a package to start weekly sessions.
            <?php endif; ?>
        </div>
    </div>

    <!-- Progress steps -->
    <div class="md-pending-card">
        <?php
        $step1_class = 'md-step-done';    // Interest submitted (always done if we're here)
        $step2_class = $pair->status === 'interest' ? 'md-step-active' : 'md-step-done';
        $step3_class = $pair->status === 'intro_scheduled' ? 'md-step-active' : ($pair->status === 'intro_done' ? 'md-step-done' : 'md-step-pending');
        $step4_class = $pair->status === 'intro_done' ? 'md-step-active' : 'md-step-pending';
        ?>
        <div class="md-pending-step">
            <div class="md-step-num md-step-done">&#10003;</div>
            <div class="md-step-text">
                <h4>Interest Submitted</h4>
                <p>We received your request. No commitment yet.</p>
            </div>
        </div>
        <div class="md-pending-step">
            <div class="md-step-num <?php echo $step2_class; ?>"><?php echo $pair->status === 'interest' ? '2' : '&#10003;'; ?></div>
            <div class="md-step-text">
                <h4><?php echo $pair->status === 'interest' ? 'Waiting for Coach' : 'Coach Contacted'; ?></h4>
                <p><?php echo $pair->status === 'interest' ? $trainer_first . ' will reach out within 24-48 hours to schedule a free intro call.' : 'Your coach is setting up the intro call.'; ?></p>
            </div>
        </div>
        <div class="md-pending-step">
            <div class="md-step-num <?php echo $step3_class; ?>"><?php echo in_array($pair->status, array('intro_done')) ? '&#10003;' : '3'; ?></div>
            <div class="md-step-text">
                <h4>Free Intro Call</h4>
                <p><?php echo $pair->status === 'intro_scheduled' ? 'Your call is scheduled — check your email for the link.' : ($pair->status === 'intro_done' ? 'Intro complete! You\'re ready to choose a package.' : '15-min call so your kid meets their mentor. No payment.'); ?></p>
            </div>
        </div>
        <div class="md-pending-step">
            <div class="md-step-num <?php echo $step4_class; ?>">4</div>
            <div class="md-step-text">
                <h4>Choose a Package & Start</h4>
                <p>Pick Kickstart, Development, or Elite. Weekly sessions begin.</p>
            </div>
        </div>
    </div>

    <?php if ($pair->status === 'intro_done'): ?>
    <!-- CTA: Choose package -->
    <a href="<?php echo esc_url(add_query_arg('pair_id', intval($pair->id), home_url('/mentorship-checkout/'))); ?>"
       style="display:block;text-align:center;padding:16px;background:#FCB900;color:#0A0A0A;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;font-size:15px;letter-spacing:0.5px;border-radius:12px;text-decoration:none;margin-bottom:16px">
        Choose Your Package &amp; Start &rarr;
    </a>
    <?php endif; ?>

    <!-- Help text -->
    <div style="text-align:center;padding:12px">
        <p style="font-size:12px;color:var(--muted);line-height:1.5">Questions? Email <a href="mailto:support@ptpsoccertraining.com" style="color:#FCB900">support@ptpsoccertraining.com</a></p>
    </div>
</div>
<?php return; endif; ?>

<style>
:root{--gold:#FCB900;--black:#0A0A0A;--white:#FFF;--surface:#F8F8F6;--border:#EAEAE6;--muted:#737373;--success:#22C55E;--pkg:<?php echo $pkg_color; ?>}
*{box-sizing:border-box}
.md-wrap{max-width:600px;margin:0 auto;padding:0 16px 40px;font-family:'Inter',system-ui,sans-serif}
.md-card{background:var(--white);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:16px}
.md-card h3{font-family:'Oswald',sans-serif;text-transform:uppercase;font-size:14px;letter-spacing:1px;margin:0 0 12px;color:var(--black)}
.md-head{background:var(--black);color:var(--white);border-radius:16px;padding:24px;margin-bottom:16px;position:relative}
.md-head-pkg{background:var(--pkg);color:var(--white);padding:3px 10px;border-radius:6px;font-family:'Oswald',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:1px}
.md-head h2{font-family:'Oswald',sans-serif;font-size:22px;text-transform:uppercase;margin:12px 0 4px;letter-spacing:0.5px}
.md-progress{background:rgba(255,255,255,0.1);height:8px;border-radius:4px;margin:16px 0 8px;overflow:hidden}
.md-progress-fill{height:100%;background:var(--gold);border-radius:4px;transition:width 0.3s}
.md-credits{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}
.md-credit{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:12px;text-align:center}
.md-credit-val{font-family:'Oswald',sans-serif;font-size:clamp(18px,6vw,24px);font-weight:700;color:var(--gold)}
.md-credit-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#A3A3A3;margin-top:2px}
.md-actions{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}
.md-actions button{flex:1;padding:10px;border-radius:8px;font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase;font-size:12px;cursor:pointer;letter-spacing:0.5px}
.md-btn-buy{background:var(--gold);color:var(--black);border:none}
.md-btn-cancel{background:transparent;border:1px solid rgba(255,255,255,0.2);color:#A3A3A3}
.md-session{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--border);align-items:center}
.md-session:last-child{border:0}
.md-session-time{font-family:'Oswald',sans-serif;font-size:12px;color:var(--gold);min-width:60px;text-align:center}
.md-session-title{font-weight:600;font-size:14px}
.md-session-meta{font-size:12px;color:var(--muted)}
.md-goal{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border)}
.md-goal:last-child{border:0}
.md-goal-check{width:22px;height:22px;border-radius:50%;border:2px solid var(--border);cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.md-goal-check.done{background:var(--success);border-color:var(--success);color:#fff}
.md-video{padding:12px 0;border-bottom:1px solid var(--border)}
.md-video:last-child{border:0}
.md-badge{display:inline-flex;align-items:center;gap:4px;background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:6px 12px;margin:4px;font-size:12px}
.md-empty{text-align:center;padding:24px;color:var(--muted);font-size:13px}
</style>

<div class="md-wrap">

    <!-- === HEADER === -->
    <div class="md-head">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
            <?php if ($pair->trainer_photo): ?>
            <img src="<?php echo esc_url($pair->trainer_photo); ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--gold)" alt="">
            <?php endif; ?>
            <div>
                <span class="md-head-pkg"><?php echo esc_html($pkg_name); ?></span>
                <h2><?php echo esc_html($pair->player_name); ?> &times; <?php echo esc_html($pair->trainer_name); ?></h2>
            </div>
        </div>

        <!-- Progress bar -->
        <div style="display:flex;justify-content:space-between;font-size:12px;color:#A3A3A3;margin-top:16px">
            <span><?php echo $pair->sessions_completed; ?> of <?php echo $pair->sessions_total; ?> sessions</span>
            <span><?php echo $sessions_left; ?> remaining</span>
        </div>
        <div class="md-progress"><div class="md-progress-fill" style="width:<?php echo $progress_pct; ?>%"></div></div>

        <!-- Credits -->
        <div class="md-credits">
            <div class="md-credit">
                <div class="md-credit-val"><?php echo $sessions_left; ?></div>
                <div class="md-credit-label">Sessions Left</div>
            </div>
            <div class="md-credit">
                <div class="md-credit-val"><?php echo intval($pair->video_reviews_remaining); ?></div>
                <div class="md-credit-label">Video Reviews</div>
            </div>
            <div class="md-credit">
                <div class="md-credit-val"><?php echo intval($pair->film_breakdowns_remaining); ?></div>
                <div class="md-credit-label">Film Sessions</div>
            </div>
        </div>

        <!-- Actions -->
        <div class="md-actions">
            <?php if ($pair->status === 'active' && $sessions_left <= 2): ?>
            <button class="md-btn-buy" onclick="reUpPackage()">Buy More Sessions</button>
            <?php endif; ?>
            <?php if ($pair->status === 'active'): ?>
            <button class="md-btn-cancel" onclick="cancelMentorship()">Manage</button>
            <?php endif; ?>
            <?php if ($pair->status === 'completed'): ?>
            <button class="md-btn-buy" onclick="reUpPackage()">Start New Package</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- === UPCOMING SESSIONS === -->
    <div class="md-card">
        <h3>Upcoming Sessions</h3>
        <?php if (!empty($sessions)): ?>
            <?php foreach ($sessions as $s):
                $sdt = strtotime($s->scheduled_at);
                $meeting = PTP_Mentorship::get_meeting_info($s->meeting_url);
            ?>
            <div class="md-session">
                <div class="md-session-time">
                    <div style="font-size:10px;text-transform:uppercase;color:var(--muted)"><?php echo date('M j', $sdt); ?></div>
                    <div style="font-weight:700"><?php echo date('g:i A', $sdt); ?></div>
                </div>
                <div style="flex:1">
                    <div class="md-session-title"><?php echo esc_html($s->title ?: '1:1 Session'); ?></div>
                    <div class="md-session-meta"><?php echo $s->duration_minutes; ?> min &middot; <?php echo $meeting['platform']; ?></div>
                </div>
                <?php if (!empty($s->meeting_url) && $sdt - time() < 900): ?>
                <a href="<?php echo esc_url($s->meeting_url); ?>" target="_blank" style="background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:11px;letter-spacing:0.5px">Join</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="md-empty">No upcoming sessions. Your coach will schedule your next call soon.</div>
        <?php endif; ?>
    </div>

    <!-- === GOALS === -->
    <div class="md-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="margin:0">Goals</h3>
            <button onclick="showGoalForm()" style="background:var(--gold);color:var(--black);border:none;border-radius:6px;padding:6px 12px;font-family:Oswald,sans-serif;font-weight:700;font-size:11px;text-transform:uppercase;cursor:pointer;letter-spacing:0.5px">+ Add</button>
        </div>
        <?php if (!empty($goals)):
            foreach ($goals as $g): ?>
            <div class="md-goal">
                <div style="display:flex;align-items:center;gap:10px;flex:1">
                    <div class="md-goal-check <?php echo $g->status === 'completed' ? 'done' : ''; ?>" onclick="<?php echo $g->status !== 'completed' ? "completeGoal({$g->id})" : ''; ?>">
                        <?php if ($g->status === 'completed'): ?>&#10003;<?php endif; ?>
                    </div>
                    <div>
                        <div style="font-weight:600;font-size:14px;<?php echo $g->status === 'completed' ? 'text-decoration:line-through;color:var(--muted)' : ''; ?>"><?php echo esc_html($g->title); ?></div>
                        <?php if ($g->target_date): ?><div style="font-size:11px;color:var(--muted)">Target: <?php echo date('M j, Y', strtotime($g->target_date)); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; else: ?>
            <div class="md-empty">No goals yet. Set your first goal with your coach.</div>
        <?php endif; ?>
    </div>

    <!-- === VIDEO REVIEWS === -->
    <?php if ($pair->video_reviews_remaining > 0 || !empty($videos)): ?>
    <div class="md-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <h3 style="margin:0">Video Reviews</h3>
            <span style="font-size:11px;color:var(--muted)"><?php echo intval($pair->video_reviews_remaining); ?> reviews left</span>
        </div>

        <?php if ($pair->video_reviews_remaining > 0): ?>
        <div style="border:2px dashed var(--border);border-radius:12px;padding:20px;text-align:center;margin-bottom:12px;cursor:pointer" onclick="document.getElementById('videoFile').click()">
            <div style="font-size:24px;margin-bottom:8px">&#127909;</div>
            <div style="font-size:13px;font-weight:600">Upload Game or Training Video</div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">MP4, MOV, or WebM &middot; Max 100MB</div>
            <input type="file" id="videoFile" accept="video/*" style="display:none" onchange="handleVideoUpload(this)">
        </div>
        <?php else: ?>
        <div style="background:var(--surface);border-radius:10px;padding:16px;text-align:center;margin-bottom:12px">
            <div style="font-size:13px;font-weight:600;margin-bottom:6px">Out of video reviews</div>
            <button onclick="buyAddon('video_review_5')" style="background:var(--gold);color:var(--black);border:none;border-radius:8px;padding:8px 20px;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;font-size:11px;cursor:pointer">Buy 5 Reviews — $99</button>
        </div>
        <?php endif; ?>

        <?php foreach ($videos as $v): ?>
        <div class="md-video">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <span style="font-size:11px;padding:2px 8px;border-radius:4px;font-weight:600;background:<?php echo $v->status === 'reviewed' ? '#DCFCE7;color:#166534' : '#FEF3C7;color:#92400E'; ?>"><?php echo $v->status === 'reviewed' ? 'Reviewed' : 'Pending'; ?></span>
                    <span style="font-size:11px;color:var(--muted);margin-left:8px"><?php echo human_time_diff(strtotime($v->created_at)); ?> ago</span>
                </div>
            </div>
            <?php if ($v->player_note): ?><div style="font-size:13px;margin-top:6px;color:var(--muted)"><?php echo esc_html($v->player_note); ?></div><?php endif; ?>
            <?php if ($v->status === 'reviewed' && $v->coach_feedback): ?>
            <div style="background:var(--surface);border-radius:8px;padding:12px;margin-top:8px;font-size:13px;line-height:1.5">
                <div style="font-weight:700;font-size:11px;text-transform:uppercase;color:var(--gold);margin-bottom:4px">Coach Feedback</div>
                <?php echo nl2br(esc_html($v->coach_feedback)); ?>
                <?php if ($v->coach_video_url): ?>
                <div style="margin-top:8px"><a href="<?php echo esc_url($v->coach_video_url); ?>" target="_blank" style="color:var(--gold);font-weight:600;font-size:12px">Watch Coach Video &rarr;</a></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- === TRAINING PLAN === -->
    <?php if ($plan): ?>
    <div class="md-card">
        <h3><?php echo esc_html($plan->title); ?></h3>
        <div style="font-size:13px;line-height:1.7"><?php echo wp_kses_post($plan->content); ?></div>
    </div>
    <?php endif; ?>

    <!-- === MILESTONES === -->
    <?php if (!empty($milestones)): ?>
    <div class="md-card">
        <h3>Milestones</h3>
        <div style="display:flex;flex-wrap:wrap;gap:4px">
            <?php foreach ($milestones as $m): ?>
            <div class="md-badge">&#127942; <?php echo esc_html($m->title); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- === ADD-ONS === -->
    <?php if ($pair->status === 'active'): ?>
    <div class="md-card" style="background:var(--surface)">
        <h3>Add-Ons</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px">
            <div style="background:var(--white);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center">
                <div style="font-family:Oswald,sans-serif;font-weight:700;font-size:18px">$99</div>
                <div style="font-size:12px;font-weight:600;margin:4px 0">Video Review Pack</div>
                <div style="font-size:11px;color:var(--muted);margin-bottom:8px">5 async video reviews</div>
                <button onclick="buyAddon('video_review_5')" style="background:var(--gold);color:var(--black);border:none;border-radius:6px;padding:6px 16px;font-family:Oswald,sans-serif;font-weight:700;font-size:10px;text-transform:uppercase;cursor:pointer">Buy</button>
            </div>
            <div style="background:var(--white);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center">
                <div style="font-family:Oswald,sans-serif;font-weight:700;font-size:18px">$129</div>
                <div style="font-size:12px;font-weight:600;margin:4px 0">Film Breakdown</div>
                <div style="font-size:11px;color:var(--muted);margin-bottom:8px">60-min deep-dive session</div>
                <button onclick="buyAddon('film_breakdown')" style="background:var(--gold);color:var(--black);border:none;border-radius:6px;padding:6px 16px;font-family:Oswald,sans-serif;font-weight:700;font-size:10px;text-transform:uppercase;cursor:pointer">Buy</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
// Stripe publishable key for addon purchases
$_stripe_mode = 'live';
$_settings    = get_option('ptp_settings', array());
if (($_settings['stripe_mode'] ?? '') === 'test' || get_option('ptp_stripe_test_mode')) $_stripe_mode = 'test';
$_stripe_pk   = get_option('ptp_stripe_' . $_stripe_mode . '_publishable', '');
if (!$_stripe_pk) $_stripe_pk = $_settings['stripe_' . $_stripe_mode . '_publishable_key'] ?? '';
if (!$_stripe_pk) $_stripe_pk = get_option('ptp_stripe_publishable_key', '');
?>
<?php if ($_stripe_pk): ?>
<script src="https://js.stripe.com/v3/"></script>
<?php endif; ?>

<!-- Addon purchase modal -->
<div id="addonModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);align-items:flex-end;justify-content:center">
    <div style="background:#fff;border-radius:20px 20px 0 0;width:100%;max-width:520px;padding:28px 24px calc(40px + env(safe-area-inset-bottom,0px));max-height:90vh;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
            <h3 id="addonModalTitle" style="font-family:'Oswald',sans-serif;font-size:18px;text-transform:uppercase">Add-on</h3>
            <button onclick="closeAddonModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#737373">&times;</button>
        </div>
        <p id="addonModalDesc" style="font-size:13px;color:#737373;margin-bottom:20px;line-height:1.5"></p>
        <div style="background:#F9F9F7;border-radius:10px;padding:14px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:13px;color:#525252">Total today</span>
            <span id="addonModalPrice" style="font-family:'Oswald',sans-serif;font-size:22px;font-weight:700"></span>
        </div>
        <div style="margin-bottom:16px">
            <label style="display:block;font-family:'Oswald',sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#737373;margin-bottom:6px">Card</label>
            <div id="addonCardElement" style="padding:13px;border:2px solid #E5E5E3;border-radius:8px;background:#fff"></div>
            <div id="addonCardError" style="color:#EF4444;font-size:12px;margin-top:6px;display:none"></div>
        </div>
        <button id="addonPayBtn" onclick="submitAddonPayment()" style="width:100%;padding:15px;background:#FCB900;color:#0A0A0A;font-family:'Oswald',sans-serif;font-size:15px;font-weight:700;text-transform:uppercase;border:none;border-radius:10px;cursor:pointer">
            Pay &amp; Add Credits
        </button>
        <p style="text-align:center;font-size:11px;color:#A3A3A3;margin-top:10px">Secured by Stripe</p>
    </div>
</div>

<script>
var pairId = <?php echo intval($pair->id); ?>;
var AJAX_URL = '<?php echo admin_url('admin-ajax.php'); ?>';
var NONCE = '<?php echo wp_create_nonce('ptp_ajax_nonce'); ?>';
var STRIPE_PK = <?php echo json_encode($_stripe_pk); ?>;
var ADDON_DEFS = <?php echo json_encode(class_exists('PTP_Mentorship') ? PTP_Mentorship::ADDONS : array()); ?>;

function showGoalForm() {
    var title = prompt('What goal do you want to set?');
    if (!title) return;
    fetch(AJAX_URL, {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=ptp_mentorship_set_goal&nonce='+NONCE+'&pair_id='+pairId+'&title='+encodeURIComponent(title)
    }).then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert(d.data||'Error'); });
}

function completeGoal(id) {
    if (!confirm('Mark this goal as complete?')) return;
    fetch(AJAX_URL, {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=ptp_mentorship_complete_goal&nonce='+NONCE+'&goal_id='+id
    }).then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert(d.data||'Error'); });
}

function handleVideoUpload(input) {
    if (!input.files.length) return;
    var fd = new FormData();
    fd.append('action','ptp_mentorship_upload_video');
    fd.append('nonce', NONCE);
    fd.append('pair_id', pairId);
    fd.append('video', input.files[0]);
    fd.append('player_note', prompt('Quick note for your coach (optional):') || '');
    fetch(AJAX_URL, {method:'POST', body:fd})
        .then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert(d.data||'Upload failed'); });
}

function buyAddon(addonKey) {
    var def = ADDON_DEFS[addonKey];
    if (!def) { alert('Unknown add-on'); return; }
    if (!STRIPE_PK) { alert('Payment not configured. Contact support.'); return; }
    // Populate modal
    document.getElementById('addonModalTitle').textContent = def.name;
    document.getElementById('addonModalDesc').textContent  = def.desc;
    document.getElementById('addonModalPrice').textContent = '$' + def.price_display;
    document.getElementById('addonPayBtn').dataset.addon   = addonKey;
    document.getElementById('addonCardError').style.display = 'none';
    // Mount card element
    if (!window._addonStripe) {
        window._addonStripe   = Stripe(STRIPE_PK);
        window._addonElements = window._addonStripe.elements({ locale: 'en' });
        window._addonCard     = window._addonElements.create('card', {
            style: { base: { fontFamily: 'Inter,system-ui,sans-serif', fontSize: '15px', color: '#0A0A0A', '::placeholder': { color: '#9ca3af' } }, invalid: { color: '#EF4444' } }
        });
        window._addonCard.mount('#addonCardElement');
        window._addonCard.on('change', function(e) {
            var el = document.getElementById('addonCardError');
            el.textContent = e.error ? e.error.message : '';
            el.style.display = e.error ? 'block' : 'none';
        });
    }
    var modal = document.getElementById('addonModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeAddonModal() {
    document.getElementById('addonModal').style.display = 'none';
    document.body.style.overflow = '';
}
async function submitAddonPayment() {
    var btn     = document.getElementById('addonPayBtn');
    var addonKey = btn.dataset.addon;
    var errEl   = document.getElementById('addonCardError');
    btn.disabled = true; btn.textContent = 'Processing...';
    errEl.style.display = 'none';
    var result = await window._addonStripe.createPaymentMethod({ type: 'card', card: window._addonCard });
    if (result.error) {
        errEl.textContent = result.error.message; errEl.style.display = 'block';
        btn.disabled = false; btn.textContent = 'Pay & Add Credits'; return;
    }
    var fd = new FormData();
    fd.append('action', 'ptp_mentorship_purchase_addon');
    fd.append('nonce', NONCE);
    fd.append('pair_id', pairId);
    fd.append('addon', addonKey);
    fd.append('payment_method_id', result.paymentMethod.id);
    var res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
    var data = await res.json();
    if (!data.success) {
        errEl.textContent = data.data || 'Payment failed'; errEl.style.display = 'block';
        btn.disabled = false; btn.textContent = 'Pay & Add Credits'; return;
    }
    closeAddonModal();
    alert(data.data.message || 'Credits added!');
    location.reload();
}

function cancelMentorship() {
    var confirmation = prompt('Type CANCEL to end your mentorship. Billing stops at end of current week.');
    if (confirmation !== 'CANCEL') return;
    var reason = prompt('Optional: Tell us why you\'re cancelling') || '';
    fetch(AJAX_URL, {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=ptp_mentorship_cancel&nonce='+NONCE+'&pair_id='+pairId+'&reason='+encodeURIComponent(reason)
    }).then(r=>r.json()).then(d=>{
        if(d.success){ alert(d.data.message); location.href='/mentorship/'; }
        else alert(d.data||'Error');
    });
}

function reUpPackage() {
    // Go directly to checkout for existing pair (skip interest → intro flow)
    window.location.href = '/mentorship-checkout/?pair_id=<?php echo esc_js($pair->id ?? ''); ?>&reup=1';
}
</script>
