<?php
/**
 * Unified Player Card Component
 * Shows full player profile across all systems: camps, training, mentorship, goals, skills
 * 
 * Expected vars: $player (from PTP_Unified_Player::get())
 * Used in: Parent Dashboard players tab, Trainer dashboard mentee view, Admin
 * 
 * @since v137
 */
defined('ABSPATH') || exit;
if (!isset($player) || !$player) return;

$initials = strtoupper(substr($player->name ?? $player->first_name ?? '?', 0, 1));
$name = esc_html($player->name ?? $player->first_name ?? 'Player');
$age = intval($player->age ?? 0);
$position = esc_html($player->position ?? '');
$team = esc_html($player->team ?? '');

// Journey events
$journey = class_exists('PTP_Unified_Player') ? PTP_Unified_Player::get_journey($player->id) : array();

// Session notes
$all_notes = class_exists('PTP_Unified_Player') ? PTP_Unified_Player::get_all_session_notes($player->id) : array();

// Trainers
$trainers = class_exists('PTP_Unified_Player') ? PTP_Unified_Player::get_player_trainers($player->id) : array();

// Engagement
$engagement = $player->engagement_score ?? 0;
$eng_color = $engagement >= 70 ? '#22C55E' : ($engagement >= 40 ? '#F59E0B' : '#EF4444');
$eng_label = $engagement >= 70 ? 'Highly Engaged' : ($engagement >= 40 ? 'Building' : 'Needs Attention');
?>

<div class="upc" data-player-id="<?php echo intval($player->id); ?>">
    <!-- ── Header ── -->
    <div class="upc-header">
        <div class="upc-avatar"><?php echo $initials; ?></div>
        <div class="upc-info">
            <div class="upc-name"><?php echo $name; ?></div>
            <div class="upc-meta">
                <?php if ($age): ?>Age <?php echo $age; ?><?php endif; ?>
                <?php if ($position): ?> · <?php echo $position; ?><?php endif; ?>
                <?php if ($team): ?> · <?php echo $team; ?><?php endif; ?>
            </div>
        </div>
        <?php if ($engagement > 0): ?>
        <div class="upc-eng" title="Engagement Score">
            <svg viewBox="0 0 36 36" width="40" height="40">
                <path d="M18 2.0845a15.9155 15.9155 0 010 31.831 15.9155 15.9155 0 010-31.831" fill="none" stroke="#eee" stroke-width="3"/>
                <path d="M18 2.0845a15.9155 15.9155 0 010 31.831 15.9155 15.9155 0 010-31.831" fill="none" stroke="<?php echo $eng_color; ?>" stroke-width="3" stroke-dasharray="<?php echo $engagement; ?>, 100" stroke-linecap="round"/>
            </svg>
            <span class="upc-eng-num" style="color:<?php echo $eng_color; ?>"><?php echo $engagement; ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Stats Row ── -->
    <div class="upc-stats">
        <div class="upc-stat">
            <div class="upc-stat-num"><?php echo intval($player->camp_count); ?></div>
            <div class="upc-stat-lbl">Camps</div>
        </div>
        <div class="upc-stat">
            <div class="upc-stat-num"><?php echo intval($player->completed_sessions); ?></div>
            <div class="upc-stat-lbl">Sessions</div>
        </div>
        <div class="upc-stat">
            <div class="upc-stat-num"><?php echo count($player->goals); ?></div>
            <div class="upc-stat-lbl">Goals</div>
        </div>
        <div class="upc-stat">
            <div class="upc-stat-num"><?php echo count($player->assessments); ?></div>
            <div class="upc-stat-lbl">Assessments</div>
        </div>
    </div>

    <!-- ── Mentorship Status ── -->
    <?php if ($player->has_mentorship): 
        $pair = $player->active_mentorship;
        $m_trainer = $pair->trainer_name ?? 'Coach';
        $m_pct = $pair->sessions_total > 0 ? round(($pair->sessions_completed / $pair->sessions_total) * 100) : 0;
    ?>
    <div class="upc-mentorship">
        <div class="upc-mentorship-badge">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="#FCB900" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/></svg>
            Mentorship with <?php echo esc_html($m_trainer); ?>
        </div>
        <div class="upc-progress">
            <div class="upc-progress-bar" style="width:<?php echo $m_pct; ?>%"></div>
        </div>
        <div class="upc-progress-label">
            <?php echo intval($pair->sessions_completed); ?> / <?php echo intval($pair->sessions_total); ?> sessions
            · <?php echo intval($pair->video_reviews_remaining ?? 0); ?> video reviews left
            · <?php echo esc_html(ucfirst($pair->package_type ?? 'development')); ?>
        </div>
    </div>
    <?php elseif (!empty($trainers)): ?>
    <?php 
        // Find a trainer they've worked with who offers mentorship but they haven't enrolled
        $mentor_available = null;
        foreach ($trainers as $tr) {
            if ($tr->mentorship_enabled && !$tr->mentorship_pair_id) {
                $mentor_available = $tr;
                break;
            }
        }
        if ($mentor_available):
            $mu_url = add_query_arg(array('trainer_id' => $mentor_available->id, 'package' => 'development', 'source' => 'player_card'), home_url('/mentorship-signup/'));
    ?>
    <div class="upc-mentor-upsell">
        <div class="upc-mentor-upsell-text">
            <?php echo esc_html(explode(' ', $mentor_available->display_name)[0]); ?> offers weekly mentorship — film review, goals, accountability. From $49/session.
        </div>
        <a href="<?php echo esc_url($mu_url); ?>" class="upc-mentor-upsell-btn">Free Intro Call</a>
    </div>
    <?php endif; endif; ?>

    <!-- ── My Coaches ── -->
    <?php if (!empty($trainers)): ?>
    <div class="upc-section">
        <div class="upc-section-title">Coaches</div>
        <div class="upc-coaches">
            <?php foreach ($trainers as $tr):
                $tr_initial = strtoupper(substr($tr->display_name, 0, 1));
                $has_m = !empty($tr->mentorship_pair_id);
            ?>
            <a href="<?php echo esc_url(home_url('/trainer/' . ($tr->slug ?: $tr->id) . '/')); ?>" class="upc-coach">
                <?php if (!empty($tr->photo_url)): ?>
                <img src="<?php echo esc_url($tr->photo_url); ?>" class="upc-coach-img" alt="">
                <?php else: ?>
                <div class="upc-coach-initial"><?php echo $tr_initial; ?></div>
                <?php endif; ?>
                <div class="upc-coach-name"><?php echo esc_html(explode(' ', $tr->display_name)[0]); ?></div>
                <div class="upc-coach-meta">
                    <?php echo intval($tr->session_count); ?> sessions
                    <?php if ($has_m): ?><span class="upc-coach-mentor-dot" title="Mentorship active"></span><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Active Goals ── -->
    <?php $active_goals = array_filter($player->goals, function($g) { return $g->status === 'active'; }); ?>
    <?php if (!empty($active_goals)): ?>
    <div class="upc-section">
        <div class="upc-section-title">Active Goals</div>
        <?php 
        $goal_colors = array('game' => '#FCB900', 'mental' => '#60A5FA', 'identity' => '#EF4444', 'life' => '#22C55E');
        foreach ($active_goals as $g):
            $gc = $goal_colors[$g->goal_type ?? 'game'] ?? '#FCB900';
        ?>
        <div class="upc-goal">
            <div class="upc-goal-dot" style="background:<?php echo $gc; ?>"></div>
            <div class="upc-goal-text"><?php echo esc_html($g->title); ?></div>
            <?php if (!empty($g->target_date)): ?>
            <div class="upc-goal-date"><?php echo esc_html(date('M j', strtotime($g->target_date))); ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Latest Session Notes (Unified) ── -->
    <?php if (!empty($all_notes)): $shown = 0; ?>
    <div class="upc-section">
        <div class="upc-section-title">Recent Session Notes</div>
        <?php foreach (array_slice($all_notes, 0, 3) as $note): ?>
        <div class="upc-note">
            <div class="upc-note-header">
                <span class="upc-note-source <?php echo $note->source === 'mentorship' ? 'upc-note-mentorship' : 'upc-note-training'; ?>">
                    <?php echo $note->source === 'mentorship' ? 'Mentorship' : 'Training'; ?>
                </span>
                <span class="upc-note-trainer"><?php echo esc_html($note->trainer_name ?? ''); ?></span>
                <span class="upc-note-date"><?php echo $note->date ? esc_html(date('M j', strtotime($note->date))) : ''; ?></span>
            </div>
            <?php if ($note->focus): ?>
            <div class="upc-note-focus"><?php echo esc_html($note->focus); ?></div>
            <?php endif; ?>
            <?php if ($note->notes): ?>
            <div class="upc-note-body"><?php echo esc_html(wp_trim_words($note->notes, 30)); ?></div>
            <?php endif; ?>
            <?php if ($note->action_item): ?>
            <div class="upc-note-action">
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                <?php echo esc_html(wp_trim_words($note->action_item, 15)); ?>
            </div>
            <?php endif; ?>
            <?php if ($note->parent_summary): ?>
            <div class="upc-note-parent">
                <strong>Parent Summary:</strong> <?php echo esc_html(wp_trim_words($note->parent_summary, 20)); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Journey Timeline (last 5 events) ── -->
    <?php if (!empty($journey)): ?>
    <div class="upc-section">
        <div class="upc-section-title">Journey</div>
        <div class="upc-timeline">
            <?php foreach (array_slice(array_reverse($journey), 0, 5) as $evt):
                $evt_icon = array(
                    'camp' => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>',
                    'booking' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
                    'mentorship_start' => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/>',
                    'mentorship_session' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>',
                    'assessment' => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>',
                    'goal_completed' => '<path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
                )[$evt->event_type] ?? '<circle cx="12" cy="12" r="10"/>';
                $evt_color = array(
                    'camp' => '#FCB900',
                    'booking' => '#0A0A0A',
                    'mentorship_start' => '#FCB900',
                    'mentorship_session' => '#60A5FA',
                    'assessment' => '#A855F7',
                    'goal_completed' => '#22C55E',
                )[$evt->event_type] ?? '#737373';
            ?>
            <div class="upc-tl-item">
                <div class="upc-tl-dot" style="border-color:<?php echo $evt_color; ?>">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="<?php echo $evt_color; ?>" stroke-width="2"><?php echo $evt_icon; ?></svg>
                </div>
                <div class="upc-tl-content">
                    <div class="upc-tl-title"><?php echo esc_html($evt->event_title); ?></div>
                    <div class="upc-tl-date"><?php echo $evt->event_date ? esc_html(date('M j, Y', strtotime($evt->event_date))) : ''; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Milestones ── -->
    <?php if (!empty($player->milestones)): ?>
    <div class="upc-section">
        <div class="upc-section-title">Milestones</div>
        <div class="upc-milestones">
            <?php foreach ($player->milestones as $m): ?>
            <span class="upc-milestone"><?php echo esc_html($m->title ?? $m->name ?? 'Achievement'); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.upc{background:var(--bg-card,#fff);border:1px solid var(--border,#EAEAE6);border-radius:14px;padding:18px;margin-bottom:14px}
.upc-header{display:flex;gap:12px;align-items:center;margin-bottom:14px}
.upc-avatar{width:48px;height:48px;border-radius:50%;background:#0A0A0A;color:#FCB900;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;flex-shrink:0}
.upc-info{flex:1;min-width:0}
.upc-name{font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;text-transform:uppercase}
.upc-meta{font-size:12px;color:var(--g500,#737373);margin-top:2px}
.upc-eng{position:relative;width:40px;height:40px;flex-shrink:0}
.upc-eng-num{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-size:12px;font-weight:700}
.upc-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:14px}
.upc-stat{background:var(--bg,#F8F8F6);border-radius:8px;padding:8px 4px;text-align:center}
.upc-stat-num{font-family:'Oswald',sans-serif;font-size:16px;font-weight:700}
.upc-stat-lbl{font-size:9px;text-transform:uppercase;color:var(--g500,#737373);letter-spacing:0.5px;margin-top:1px}
.upc-mentorship{background:linear-gradient(135deg,#0A0A0A,#1A1A1A);border-radius:10px;padding:12px;margin-bottom:14px;color:#fff}
.upc-mentorship-badge{display:flex;align-items:center;gap:6px;font-family:'Oswald',sans-serif;font-size:12px;text-transform:uppercase;color:#FCB900;margin-bottom:8px}
.upc-progress{height:5px;background:rgba(255,255,255,0.1);border-radius:3px;overflow:hidden;margin-bottom:4px}
.upc-progress-bar{height:100%;background:#FCB900;border-radius:3px;transition:width 0.5s}
.upc-progress-label{font-size:10px;color:#A3A3A3}
.upc-mentor-upsell{background:rgba(252,185,0,0.06);border:1px solid rgba(252,185,0,0.2);border-radius:10px;padding:12px;margin-bottom:14px;display:flex;gap:10px;align-items:center}
.upc-mentor-upsell-text{font-size:11px;color:var(--g600,#525252);line-height:1.4;flex:1}
.upc-mentor-upsell-btn{flex-shrink:0;background:#FCB900;color:#0A0A0A;font-family:'Oswald',sans-serif;font-weight:700;font-size:10px;text-transform:uppercase;padding:7px 14px;border-radius:8px;text-decoration:none;white-space:nowrap}
.upc-section{margin-bottom:14px}
.upc-section-title{font-family:'Oswald',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--g500,#737373);margin-bottom:8px}
.upc-coaches{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px}
.upc-coach{text-align:center;text-decoration:none;color:inherit;flex-shrink:0;width:60px}
.upc-coach-img{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--border,#EAEAE6)}
.upc-coach-initial{width:40px;height:40px;border-radius:50%;background:#0A0A0A;color:#FCB900;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-weight:700;font-size:16px;margin:0 auto}
.upc-coach-name{font-size:10px;font-weight:600;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.upc-coach-meta{font-size:9px;color:var(--g500,#737373);display:flex;align-items:center;justify-content:center;gap:3px}
.upc-coach-mentor-dot{width:6px;height:6px;border-radius:50%;background:#FCB900;display:inline-block}
.upc-goal{display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border-light,#F0F0F0)}
.upc-goal-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.upc-goal-text{font-size:12px;flex:1}
.upc-goal-date{font-size:10px;color:var(--g500,#737373)}
.upc-note{background:var(--bg,#F8F8F6);border-radius:8px;padding:10px;margin-bottom:6px}
.upc-note-header{display:flex;gap:6px;align-items:center;margin-bottom:4px;flex-wrap:wrap}
.upc-note-source{font-size:9px;font-weight:700;text-transform:uppercase;padding:2px 6px;border-radius:4px}
.upc-note-training{background:#0A0A0A;color:#fff}
.upc-note-mentorship{background:#FCB900;color:#0A0A0A}
.upc-note-trainer{font-size:10px;font-weight:600}
.upc-note-date{font-size:10px;color:var(--g500,#737373);margin-left:auto}
.upc-note-focus{font-family:'Oswald',sans-serif;font-size:12px;font-weight:600;text-transform:uppercase;margin-bottom:2px}
.upc-note-body{font-size:11px;color:var(--g600,#525252);line-height:1.5}
.upc-note-action{font-size:11px;color:#22C55E;margin-top:4px;display:flex;align-items:center;gap:4px}
.upc-note-parent{font-size:11px;color:var(--g500,#737373);margin-top:4px;font-style:italic}
.upc-timeline{position:relative;padding-left:20px}
.upc-timeline::before{content:'';position:absolute;left:8px;top:4px;bottom:4px;width:2px;background:var(--border,#EAEAE6)}
.upc-tl-item{display:flex;gap:10px;align-items:flex-start;margin-bottom:10px;position:relative}
.upc-tl-dot{width:18px;height:18px;border-radius:50%;border:2px solid;background:var(--bg-card,#fff);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:absolute;left:-20px;z-index:1}
.upc-tl-content{padding-left:4px}
.upc-tl-title{font-size:12px;font-weight:600;line-height:1.3}
.upc-tl-date{font-size:10px;color:var(--g500,#737373)}
.upc-milestones{display:flex;flex-wrap:wrap;gap:4px}
.upc-milestone{display:inline-flex;align-items:center;gap:3px;background:var(--bg,#F8F8F6);border:1px solid var(--border,#EAEAE6);border-radius:16px;padding:4px 10px;font-size:10px;font-weight:600}
.upc-milestone::before{content:'';display:inline-block;width:10px;height:10px;background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23FCB900'%3E%3Cpath d='M12 2l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z'/%3E%3C/svg%3E")}
</style>
