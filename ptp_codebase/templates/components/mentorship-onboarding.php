<?php
/**
 * Mentorship Parent Onboarding v1.0
 * 
 * Shows inside the parent dashboard mentorship tab when:
 *   - pair.status = 'active'
 *   - pair.sessions_completed = 0
 *   - User hasn't dismissed it (user_meta check)
 * 
 * Expected vars: $active_pair, $mentorship_trainer, $mentorship_player
 * 
 * Include with:
 *   include(PTP_PLUGIN_DIR . 'templates/components/mentorship-onboarding.php');
 */
defined('ABSPATH') || exit;

// Only show if active pair with 0 sessions completed
if (empty($active_pair) || $active_pair->status !== 'active') return;
if (intval($active_pair->sessions_completed) > 0) return;

// Check dismiss
$dismissed = get_user_meta(get_current_user_id(), 'ptp_mentor_onboarding_done_' . $active_pair->id, true);
if ($dismissed) return;

$trainer_first = $mentorship_trainer ? explode(' ', $mentorship_trainer->display_name)[0] : 'Your Coach';
$player_first  = $mentorship_player ? ($mentorship_player->first_name ?: $mentorship_player->name) : 'your player';
$pkg           = PTP_Mentorship::PACKAGES[$active_pair->package_type] ?? null;
$pkg_name      = $pkg['name'] ?? ucfirst($active_pair->package_type);
$session_len   = $pkg['session_length'] ?? 30;
$total_sessions = intval($active_pair->sessions_total);
$has_video     = intval($active_pair->video_reviews_remaining) > 0;

// Checklist items
$steps = array();
$steps[] = array(
    'key'   => 'meet',
    'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15.05 5A5 5 0 0119 8.95M15.05 1A9 9 0 0123 8.94"/><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 013.07 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72"/></svg>',
    'title' => 'Request Your First Session',
    'desc'  => 'Tap "Request Next Session" below to let ' . esc_html($trainer_first) . ' know you\'re ready. They\'ll pick a time and send you the Zoom link.',
    'cta'   => 'Request Session',
    'action'=> 'requestMentorshipSession(' . intval($active_pair->id) . ', \'' . esc_js($mentorship_trainer->display_name ?? '') . '\')',
);
$steps[] = array(
    'key'   => 'goal',
    'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
    'title' => 'Set Your First Goal',
    'desc'  => 'What does ' . esc_html($player_first) . ' want to improve? Pick a goal type — Game, Mental, Identity, or Life — and write it down. ' . esc_html($trainer_first) . ' will help build a plan around it.',
    'cta'   => 'Add a Goal',
    'action'=> 'openMentorshipGoalModal(' . intval($active_pair->id) . ')',
);
if ($has_video) {
    $steps[] = array(
        'key'   => 'video',
        'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>',
        'title' => 'Upload a Video Clip',
        'desc'  => 'Got game film or a training clip? Submit it for ' . esc_html($trainer_first) . ' to review with detailed feedback. You have ' . intval($active_pair->video_reviews_remaining) . ' reviews included.',
        'cta'   => 'Upload Video',
        'action'=> 'openMentorshipVideoModal(' . intval($active_pair->id) . ')',
    );
}
$steps[] = array(
    'key'   => 'show',
    'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    'title' => 'Show Up to Your First Call',
    'desc'  => 'Join the Zoom link when it\'s time. ' . esc_html($trainer_first) . ' will get to know ' . esc_html($player_first) . ', talk about goals, and map out the first few weeks together.',
    'cta'   => '',
    'action'=> '',
);
?>

<!-- Mentorship Onboarding -->
<div id="mtOnboarding" style="margin-bottom:20px">

    <!-- Welcome Card -->
    <div style="background:linear-gradient(135deg,#0A0A0A 0%,#1A1A1A 100%);border-radius:16px;padding:24px 20px;color:#fff;margin-bottom:16px;position:relative;overflow:hidden">
        <div style="position:absolute;top:-20px;right:-20px;width:120px;height:120px;background:radial-gradient(circle,rgba(252,185,0,0.15),transparent 70%);pointer-events:none"></div>
        
        <?php if (!empty($mentorship_trainer->photo_url)): ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
            <img src="<?php echo esc_url($mentorship_trainer->photo_url); ?>" alt="" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:3px solid #FCB900">
            <div>
                <div style="font-family:'Oswald',sans-serif;font-size:10px;color:#FCB900;text-transform:uppercase;letter-spacing:2px">You're In</div>
                <div style="font-family:'Oswald',sans-serif;font-size:20px;text-transform:uppercase;font-weight:700;letter-spacing:.5px"><?php echo esc_html($player_first); ?> &times; <?php echo esc_html($trainer_first); ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <div style="font-size:14px;line-height:1.7;color:#D4D4D4;margin-bottom:4px">
            <strong style="color:#fff"><?php echo esc_html($pkg_name); ?></strong> is active &mdash; 
            <?php echo $total_sessions; ?> sessions, <?php echo $session_len; ?> min each.
            <?php if ($active_pair->package_type !== 'single'): ?>
            Billed weekly until all sessions are used.
            <?php endif; ?>
        </div>
        <div style="font-size:12px;color:#737373;margin-top:8px">Here's how to get the most out of your first week.</div>
    </div>

    <!-- First Week Checklist -->
    <div style="background:#fff;border:2px solid #FCB900;border-radius:14px;padding:0;overflow:hidden">
        <div style="background:#FCB900;padding:12px 16px;display:flex;align-items:center;justify-content:space-between">
            <div style="font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#0A0A0A">Your First Week</div>
            <div style="font-size:11px;font-weight:700;color:#0A0A0A;opacity:.7" id="mtOnboardProgress">0 / <?php echo count($steps); ?></div>
        </div>
        
        <?php foreach ($steps as $i => $step): ?>
        <div class="mt-onboard-step" id="mtStep_<?php echo $step['key']; ?>" style="display:flex;gap:14px;padding:16px;border-bottom:<?php echo $i < count($steps) - 1 ? '1px solid #F0F0EE' : 'none'; ?>;align-items:flex-start;transition:opacity .3s">
            <div style="width:36px;height:36px;border-radius:50%;border:2px solid #E5E5E3;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .3s;color:#A3A3A3" class="mt-onboard-check">
                <?php echo $step['icon']; ?>
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:14px;color:#0A0A0A;margin-bottom:3px"><?php echo esc_html($step['title']); ?></div>
                <div style="font-size:12px;color:#737373;line-height:1.6;margin-bottom:<?php echo $step['cta'] ? '10px' : '0'; ?>"><?php echo $step['desc']; ?></div>
                <?php if ($step['cta']): ?>
                <button onclick="<?php echo esc_attr($step['action']); ?>" style="padding:8px 16px;background:#FCB900;color:#0A0A0A;border:none;border-radius:8px;font-family:'Oswald',sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;cursor:pointer;-webkit-tap-highlight-color:transparent;touch-action:manipulation"><?php echo esc_html($step['cta']); ?></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- What to Expect -->
    <div style="background:#fff;border:1px solid #EAEAE6;border-radius:14px;padding:16px;margin-top:12px">
        <div style="font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#737373;margin-bottom:12px">What to Expect Each Week</div>
        
        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
            <div style="width:24px;height:24px;border-radius:50%;background:#FCB90020;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12px">1</div>
            <div style="font-size:12px;color:#525252;line-height:1.5"><strong>Before the call</strong> — <?php echo esc_html($trainer_first); ?> might send a note or topic for the session. Check your dashboard.</div>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
            <div style="width:24px;height:24px;border-radius:50%;background:#22C55E20;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12px">2</div>
            <div style="font-size:12px;color:#525252;line-height:1.5"><strong>During</strong> — <?php echo $session_len; ?>-min Zoom call. Could be goal review, film breakdown, skill discussion, or just connecting.</div>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:12px">
            <div style="width:24px;height:24px;border-radius:50%;background:#3B82F620;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12px">3</div>
            <div style="font-size:12px;color:#525252;line-height:1.5"><strong>After</strong> — You'll get a recap with what was covered and a "This Week's Mission" action item to work on.</div>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start">
            <div style="width:24px;height:24px;border-radius:50%;background:#8B5CF620;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12px">4</div>
            <div style="font-size:12px;color:#525252;line-height:1.5"><strong>Between sessions</strong> — Upload game clips for video review, update goals, message <?php echo esc_html($trainer_first); ?>.</div>
        </div>
    </div>

    <!-- Dismiss -->
    <button onclick="mtDismissOnboarding(<?php echo intval($active_pair->id); ?>)" style="display:block;width:100%;padding:10px;margin-top:10px;background:transparent;border:none;color:#A3A3A3;font-size:11px;cursor:pointer;text-align:center">I've got it — hide this guide</button>
</div>

<script>
function mtDismissOnboarding(pairId) {
    document.getElementById('mtOnboarding').style.display = 'none';
    // Persist dismissal
    fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=ptp_mentorship_dismiss_onboarding&nonce=<?php echo wp_create_nonce("ptp_nonce"); ?>&pair_id=' + pairId
    });
}
</script>
