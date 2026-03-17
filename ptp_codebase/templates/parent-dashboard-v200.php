<?php
/**
 * Parent Dashboard v200 — Command Center Integration
 * 
 * REWRITE: Pulls data from command center (free session apps, SMS, messaging),
 * tracks full booking journey from free session → conversion → repeat client.
 * 
 * Features:
 * - Home tab: Stats, upcoming sessions, free session app status
 * - Bookings tab: Full history + booking journey from free session funnel
 * - Messages tab: Pulls from PTP messaging + SMS logs
 * - Players tab: Player management with session history per player
 * - Account tab: Profile, referrals, package credits
 * - Tracks conversion: free session → paid booking pipeline
 * 
 * v200.0: Full rewrite with command center integration
 */
defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    wp_redirect(home_url('/login/?redirect_to=' . urlencode($_SERVER['REQUEST_URI'])));
    exit;
}

global $wpdb;
$user_id = get_current_user_id();
$user = wp_get_current_user();

// ── Smart name detection ──
$first_name = $user->first_name;
if (empty($first_name)) {
    $display_name = $user->display_name ?: $user->user_login;
    if (filter_var($display_name, FILTER_VALIDATE_EMAIL) || strpos($display_name, '@') !== false) {
        $email_name = explode('@', $display_name)[0];
        $email_name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $email_name);
        $email_name = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $email_name);
        $email_name = preg_replace('/[0-9]+/', '', $email_name);
        $email_name = str_replace(array('.', '_', '-'), ' ', $email_name);
        $email_name = ucwords(trim($email_name));
        $name_parts = array_filter(explode(' ', $email_name), function($p) { return strlen($p) > 1; });
        $first_name = !empty($name_parts) ? reset($name_parts) : $email_name;
    } else {
        $first_name = explode(' ', $display_name)[0];
    }
}
if (empty($first_name) || strlen($first_name) < 2) {
    $first_name = 'There';
}

// ── Get or create parent record ──
$parent = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d", $user_id
));
if (!$parent) {
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_parents'");
    if ($table_exists) {
        $wpdb->insert($wpdb->prefix . 'ptp_parents', array(
            'user_id' => $user_id,
            'display_name' => $user->display_name ?: $user->user_login,
            'phone' => '', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ));
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d", $user_id
        ));
    }
}
$parent_id = $parent ? $parent->id : 0;
$nonce = wp_create_nonce('ptp_nonce');

// ════════════════════════════════════════════════════════════
// DATA: Combined dashboard queries
// ════════════════════════════════════════════════════════════

// Stats
$stats = null;
if ($parent_id) {
    $stats = $wpdb->get_row($wpdb->prepare("
        SELECT 
            (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = %d AND status = 'completed') as completed_count,
            (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d) as player_count,
            (SELECT COALESCE(SUM(remaining), 0) FROM {$wpdb->prefix}ptp_package_credits WHERE parent_id = %d AND remaining > 0) as total_credits,
            (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = %d AND status IN ('confirmed','pending') AND session_date >= CURDATE()) as upcoming_count
    ", $parent_id, $parent_id, $parent_id, $parent_id));
}
$completed_count = intval($stats->completed_count ?? 0);
$player_count    = intval($stats->player_count ?? 0);
$total_credits   = intval($stats->total_credits ?? 0);
$upcoming_count  = intval($stats->upcoming_count ?? 0);

// Players
$players = $parent_id ? $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d ORDER BY name ASC", $parent_id
)) : array();

// Upcoming sessions
$upcoming = $parent_id ? $wpdb->get_results($wpdb->prepare("
    SELECT b.*, 
           t.display_name as trainer_name, t.photo_url as trainer_photo, 
           t.slug as trainer_slug, t.phone as trainer_phone,
           p.name as player_name
    FROM {$wpdb->prefix}ptp_bookings b
    LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
    LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
    WHERE b.parent_id = %d AND b.session_date >= CURDATE() AND b.status IN ('confirmed','pending')
    ORDER BY b.session_date ASC, b.start_time ASC LIMIT 10
", $parent_id)) : array();

// Past sessions
$past_sessions = $parent_id ? $wpdb->get_results($wpdb->prepare("
    SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
           t.slug as trainer_slug, p.name as player_name
    FROM {$wpdb->prefix}ptp_bookings b
    LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
    LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
    WHERE b.parent_id = %d AND b.status = 'completed'
    ORDER BY b.session_date DESC LIMIT 20
", $parent_id)) : array();

// Get session recaps for past sessions
$parent_session_recaps = array();
$past_ids = array_map(function($s) { return $s->id; }, $past_sessions);
if (!empty($past_ids) && class_exists('PTP_Training_Plans')) {
    $parent_session_recaps = PTP_Training_Plans::get_recaps_for_bookings($past_ids);
}

// Package credits
$package_credits = array();
$credits_table = $wpdb->prefix . 'ptp_package_credits';
if ($parent_id && $wpdb->get_var("SHOW TABLES LIKE '$credits_table'")) {
    $package_credits = $wpdb->get_results($wpdb->prepare("
        SELECT pc.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug,
               COALESCE(pc.any_trainer, 0) as any_trainer
        FROM {$credits_table} pc LEFT JOIN {$wpdb->prefix}ptp_trainers t ON pc.trainer_id = t.id
        WHERE pc.parent_id = %d AND pc.remaining > 0 ORDER BY pc.expires_at ASC
    ", $parent_id));
}

// Favorite trainers (trainers they've booked with)
$favorite_trainers = $parent_id ? $wpdb->get_results($wpdb->prepare("
    SELECT t.*, COUNT(b.id) as session_count, MAX(b.session_date) as last_session
    FROM {$wpdb->prefix}ptp_trainers t
    JOIN {$wpdb->prefix}ptp_bookings b ON t.id = b.trainer_id
    WHERE b.parent_id = %d AND b.status IN ('completed', 'confirmed')
    GROUP BY t.id ORDER BY session_count DESC, last_session DESC LIMIT 4
", $parent_id)) : array();

// Sessions needing review
$needs_review = $parent_id ? $wpdb->get_results($wpdb->prepare("
    SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug
    FROM {$wpdb->prefix}ptp_bookings b
    LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
    LEFT JOIN {$wpdb->prefix}ptp_reviews r ON r.booking_id = b.id
    WHERE b.parent_id = %d AND b.status = 'completed' AND r.id IS NULL
    AND b.session_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    ORDER BY b.session_date DESC LIMIT 3
", $parent_id)) : array();

// ════════════════════════════════════════════════════════════
// COMMAND CENTER: Free Session Application Tracking
// ════════════════════════════════════════════════════════════
$fsa_table = $wpdb->prefix . 'ptp_session_applications';
$fsa_apps = array();
$fsa_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $fsa_table));
if ($fsa_table_exists) {
    $fsa_apps = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$fsa_table} WHERE email = %s ORDER BY created_at DESC LIMIT 10",
        $user->user_email
    ));
}

// Determine funnel stage
$funnel_stage = 'new'; // new = never applied
$latest_app = !empty($fsa_apps) ? $fsa_apps[0] : null;
if ($latest_app) {
    if ($latest_app->status === 'pending') {
        $funnel_stage = 'applied'; // applied, waiting for callback
    } elseif ($latest_app->status === 'accepted' && $completed_count === 0) {
        $funnel_stage = 'matched'; // matched with trainer, hasn't completed session yet
    } elseif ($completed_count > 0 && $completed_count <= 1) {
        $funnel_stage = 'first_session_done'; // completed their free/first session
    } elseif ($completed_count > 1) {
        $funnel_stage = 'repeat_client'; // converted to paying client
    }
}

// Conversations (messaging system)
$conversations = array();
$unread_count = 0;
if (class_exists('PTP_Messaging_V71')) {
    $conversations = PTP_Messaging_V71::get_conversations_for_user($user_id);
    foreach ($conversations as $c) {
        if (!empty($c->unread)) $unread_count++;
    }
}

// Referral code
$referral_code = '';
if (class_exists('PTP_Referral_System')) {
    $referral_code = PTP_Referral_System::generate_code($user_id, 'parent');
}
if (!$referral_code) {
    $referral_code = get_user_meta($user_id, 'ptp_referral_code', true);
    if (!$referral_code) {
        $referral_code = strtoupper(substr(md5($user_id . 'ptp'), 0, 8));
        update_user_meta($user_id, 'ptp_referral_code', $referral_code);
    }
}
$referral_link = home_url('/?ref=' . $referral_code);

// Referral credits
$referral_credit_balance = 0;
$ref_credits_table = $wpdb->prefix . 'ptp_referral_credits';
if ($wpdb->get_var("SHOW TABLES LIKE '$ref_credits_table'")) {
    $referral_credit_balance = floatval($wpdb->get_var($wpdb->prepare(
        "SELECT SUM(amount) FROM $ref_credits_table WHERE user_id = %d AND used = 0 AND (expires_at IS NULL OR expires_at > NOW())",
        $user_id
    )));
}

// ── Next Session (Hero Card) ──
$next_session = null;
if (!empty($upcoming)) {
    $next_session = $upcoming[0];
}

// ── Training Streak ──
$streak = 0;
if ($parent_id) {
    $streak_sessions = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT DATE(session_date) as sd FROM {$wpdb->prefix}ptp_bookings 
         WHERE parent_id = %d AND status = 'completed' ORDER BY sd DESC LIMIT 52",
        $parent_id
    ));
    if (!empty($streak_sessions)) {
        $streak = 1;
        for ($i = 1; $i < count($streak_sessions); $i++) {
            $diff = (strtotime($streak_sessions[$i-1]) - strtotime($streak_sessions[$i])) / 86400;
            if ($diff <= 10) { $streak++; } else { break; }
        }
    }
}

// ── This Month Sessions ──
$month_sessions = $parent_id ? intval($wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = %d AND status IN ('completed','confirmed') 
     AND session_date >= %s AND session_date <= %s",
    $parent_id, date('Y-m-01'), date('Y-m-t')
))) : 0;

// ── Time-of-day greeting ──
$hour = intval(current_time('G'));
if ($hour < 12) { $time_greeting = 'Good Morning'; }
elseif ($hour < 17) { $time_greeting = 'Good Afternoon'; }
else { $time_greeting = 'Good Evening'; }

// ── Mentorship Data ──
$active_pair         = null;
$mentorship_goals    = array();
$mentorship_videos   = array();
$mentorship_sessions = array();
$mentorship_trainer  = null;
$mentorship_player   = null;
$mentorship_paused   = false; // v233 M4

if ($parent_id && (class_exists('PTP_Query_Cache') ? PTP_Query_Cache::table_exists('ptp_mentorship_pairs') : $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_mentorship_pairs'"))) {
    $active_pair = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE parent_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT 1",
        $parent_id
    ));

    if (!$active_pair) {
        // Also check interest / intro_scheduled / intro_done so they can see their pending state
        $active_pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE parent_id = %d AND status IN ('interest','intro_scheduled','intro_done') ORDER BY FIELD(status,'intro_done','intro_scheduled','interest'), created_at DESC LIMIT 1",
            $parent_id
        ));
    }

    // v233 M4: Check for paused (failed payment / pending 3DS)
    if (!$active_pair) {
        $active_pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE parent_id = %d AND status = 'paused' ORDER BY created_at DESC LIMIT 1",
            $parent_id
        ));
        if ($active_pair) $mentorship_paused = true;
    }

    // v228: Check for recently completed — show recap + renewal CTA (not generic upsell)
    $mentorship_completed = false;
    if (!$active_pair) {
        $active_pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE parent_id = %d AND status = 'completed' ORDER BY completed_at DESC LIMIT 1",
            $parent_id
        ));
        if ($active_pair) $mentorship_completed = true;
    }

    if ($active_pair) {
        $mentorship_trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $active_pair->trainer_id
        ));
        $mentorship_player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d", $active_pair->player_id
        ));
        // v233 M6: Parent has 1 pair — these 3 queries are ~5ms total, safe to keep server-side
        $mentorship_goals = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_goals WHERE pair_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT 10",
            $active_pair->id
        ));
        $mentorship_videos = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_videos WHERE pair_id = %d ORDER BY created_at DESC LIMIT 8",
            $active_pair->id
        ));
        $mentorship_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.* FROM {$wpdb->prefix}ptp_mentorship_sessions s
             JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
             WHERE a.pair_id = %d AND s.status = 'scheduled' AND s.scheduled_at >= NOW()
             ORDER BY s.scheduled_at ASC LIMIT 3",
            $active_pair->id
        ));
    }
}
$has_mentorship = !empty($active_pair);
$goal_type_labels = array('game' => '⚽ Game', 'mental' => '🧠 Mental', 'identity' => '🔥 Identity', 'life' => '🌱 Life');
$goal_type_colors = array('game' => '#FCB900', 'mental' => '#60A5FA', 'identity' => '#EF4444', 'life' => '#22C55E');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#0A0A0A">
<title>My Training - <?php echo esc_html($first_name); ?> | PTP</title>
<meta name="robots" content="noindex, nofollow">
<?php wp_head(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo esc_url(PTP_PLUGIN_URL . 'assets/css/parent-dashboard-v200.css'); ?>" />
</head>
<body>
<h1 style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0">My Training Dashboard — PTP</h1>

<!-- ═══ HEADER ═══ -->
<a href="#pd-main-content" class="pd-skip">Skip to content</a>
<div class="pd-hdr" id="pd-main-content" role="banner">
    <div class="pd-hdr-top">
        <div class="pd-avatar"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>
        <div class="pd-hdr-info">
            <div class="pd-greeting"><?php echo esc_html($time_greeting); ?>, <span><?php echo esc_html($first_name); ?></span></div>
            <div class="pd-sub">
                <?php if ($completed_count > 0): ?>
                    <?php echo $completed_count; ?> session<?php echo $completed_count != 1 ? 's' : ''; ?> completed
                    <?php if ($streak > 1): ?> · <?php echo $streak; ?> session streak<?php endif; ?>
                <?php else: ?>
                    Welcome to PTP Training
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="pd-strip">
        <div class="pd-strip-item">
            <div class="pd-strip-num gold"><?php echo $upcoming_count; ?></div>
            <div class="pd-strip-label">Upcoming</div>
        </div>
        <div class="pd-strip-item">
            <div class="pd-strip-num"><?php echo $completed_count; ?></div>
            <div class="pd-strip-label">Completed</div>
        </div>
        <div class="pd-strip-item">
            <div class="pd-strip-num"><?php echo $month_sessions; ?></div>
            <div class="pd-strip-label">This Month</div>
        </div>
        <div class="pd-strip-item">
            <div class="pd-strip-num"><?php echo $total_credits; ?></div>
            <div class="pd-strip-label">Credits</div>
        </div>
    </div>
</div>

<!-- ═══ NEXT SESSION HERO ═══ -->
<?php if ($next_session): 
    $ns_photo = $next_session->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($next_session->trainer_name) . '&size=112&background=FCB900&color=0A0A0A&bold=true';
    $ns_date = date('l, M j', strtotime($next_session->session_date));
    $ns_time = !empty($next_session->start_time) ? date('g:i A', strtotime($next_session->start_time)) : '';
    $ns_datetime = $next_session->session_date . ' ' . ($next_session->start_time ?: '00:00:00');
    $ns_diff = strtotime($ns_datetime) - current_time('timestamp');
    if ($ns_diff < 0) { $ns_countdown = 'NOW'; }
    elseif ($ns_diff < 3600) { $ns_countdown = ceil($ns_diff / 60) . ' min'; }
    elseif ($ns_diff < 86400) { $ns_countdown = floor($ns_diff / 3600) . 'h ' . floor(($ns_diff % 3600) / 60) . 'm'; }
    else { $ns_countdown = floor($ns_diff / 86400) . 'd ' . floor(($ns_diff % 86400) / 3600) . 'h'; }
    $ns_trainer_slug = $next_session->trainer_slug ?? '';
    $ns_trainer_phone = $next_session->trainer_phone ?? '';
?>
<div class="pd-hero">
    <div class="pd-hero-top">
        <img src="<?php echo esc_url($ns_photo); ?>" class="pd-hero-photo" alt="<?php echo esc_attr($next_session->trainer_name); ?>">
        <div class="pd-hero-info">
            <div class="pd-hero-label">Next Session</div>
            <div class="pd-hero-trainer"><?php echo esc_html($next_session->trainer_name); ?></div>
            <div class="pd-hero-detail">
                <?php echo esc_html($ns_date); ?><?php if ($ns_time): ?> @ <?php echo esc_html($ns_time); ?><?php endif; ?>
                <?php if ($next_session->player_name): ?> · <?php echo esc_html($next_session->player_name); ?><?php endif; ?>
            </div>
        </div>
        <div class="pd-hero-countdown"><?php echo esc_html($ns_countdown); ?></div>
    </div>
    <div class="pd-hero-bottom">
        <?php if ($ns_trainer_phone): ?>
        <a href="tel:<?php echo esc_attr($ns_trainer_phone); ?>" class="pd-hero-action contact">
            <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
            Call
        </a>
        <a href="sms:<?php echo esc_attr($ns_trainer_phone); ?>" class="pd-hero-action">
            <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            Text
        </a>
        <?php else: ?>
        <div class="pd-hero-action" style="grid-column: span 2;"></div>
        <?php endif; ?>
        <a href="<?php echo esc_url(home_url('/trainer/' . $ns_trainer_slug . '/')); ?>" class="pd-hero-action rebook">
            <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Book Again
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ═══ QUICK ACTIONS ═══ -->
<div class="pd-actions">
    <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="pd-act">
        <div class="pd-act-icon find"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
        <div class="pd-act-label">Find Coach</div>
    </a>
    <a href="<?php echo esc_url(home_url('/ptp-find-a-camp/')); ?>" class="pd-act">
        <div class="pd-act-icon camp"><svg viewBox="0 0 24 24"><path d="M12 2L2 22h20L12 2z"/><path d="M12 2v20"/></svg></div>
        <div class="pd-act-label">Camps</div>
    </a>
    <div class="pd-act" onclick="switchTab('messages')">
        <div class="pd-act-icon msg"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
        <div class="pd-act-label">Messages<?php if ($unread_count): ?> (<?php echo $unread_count; ?>)<?php endif; ?></div>
    </div>
    <div class="pd-act" onclick="switchTab('account')">
        <div class="pd-act-icon refer"><svg viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8V21"/><path d="M19 12v7a2 2 0 01-2 2H7a2 2 0 01-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 010-5C9 3 12 8 12 8"/><path d="M16.5 8a2.5 2.5 0 000-5C15 3 12 8 12 8"/></svg></div>
        <div class="pd-act-label">Refer</div>
    </div>
</div>

<!-- ═══ CONTENT ═══ -->
<div class="pd-content">

<!-- ━━━ HOME TAB ━━━ -->
<div class="pd-tab active" id="tab-home" role="tabpanel" aria-label="Home">

    <!-- Funnel Banner -->
    <?php if ($funnel_stage !== 'repeat_client' && $funnel_stage !== 'new'): ?>
    <div class="pd-funnel <?php echo esc_attr($funnel_stage === 'first_session_done' ? 'first' : $funnel_stage); ?>">
        <?php if ($funnel_stage === 'applied'): ?>
            <div class="pd-funnel-title">Application Received</div>
            <div class="pd-funnel-desc">
                We received <?php echo esc_html($latest_app->child_name ?: 'your'); ?>'s application
                (Code: <strong><?php echo esc_html($latest_app->app_code); ?></strong>).
                We'll call you within 24 hours to match them with the right D1 coach.
            </div>
            <div class="pd-funnel-progress">
                <div class="pd-funnel-dot on"></div><div class="pd-funnel-dot"></div><div class="pd-funnel-dot"></div><div class="pd-funnel-dot"></div>
            </div>
        <?php elseif ($funnel_stage === 'matched'): ?>
            <div class="pd-funnel-title">Matched with <?php echo esc_html($latest_app->trainer_name ?: 'a Coach'); ?></div>
            <div class="pd-funnel-desc"><?php echo esc_html($latest_app->child_name ?: 'Your player'); ?> is matched!
                <?php if ($latest_app->trainer_slug): ?>
                <a href="<?php echo esc_url(home_url('/trainer/' . $latest_app->trainer_slug . '/')); ?>" style="color:var(--gold-dark);font-weight:600;">View Coach Profile</a>
                <?php endif; ?>
            </div>
            <div class="pd-funnel-progress">
                <div class="pd-funnel-dot on"></div><div class="pd-funnel-dot on"></div><div class="pd-funnel-dot"></div><div class="pd-funnel-dot"></div>
            </div>
            <a href="<?php echo esc_url(home_url('/trainer/' . ($latest_app->trainer_slug ?: '') . '/')); ?>" class="pd-funnel-cta">Book Free Session</a>
        <?php elseif ($funnel_stage === 'first_session_done'): ?>
            <div class="pd-funnel-title">First Session Complete!</div>
            <div class="pd-funnel-desc">Great start! Grab a package to keep the momentum — save up to 15% on multi-session packs.</div>
            <div class="pd-funnel-progress">
                <div class="pd-funnel-dot on"></div><div class="pd-funnel-dot on"></div><div class="pd-funnel-dot on"></div><div class="pd-funnel-dot"></div>
            </div>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="pd-funnel-cta">Browse Packages</a>
        <?php endif; ?>
    </div>
    <?php elseif ($funnel_stage === 'new' && $completed_count === 0): ?>
    <div class="pd-funnel">
        <div class="pd-funnel-title">Book Your First Session</div>
        <div class="pd-funnel-desc">Train 1-on-1 with MLS players and NCAA D1 athletes. Browse trainers near you and book today.</div>
        <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="pd-funnel-cta">Find a Trainer</a>
    </div>
    <?php endif; ?>

    <!-- Review Prompts -->
    <?php if (!empty($needs_review)): ?>
    <div class="pd-sec">
        <div class="pd-sec-title">Leave a Review</div>
        <?php foreach ($needs_review as $nr):
            $nr_photo = $nr->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($nr->trainer_name) . '&size=72&background=FCB900&color=0A0A0A&bold=true';
        ?>
        <div class="pd-review">
            <img src="<?php echo esc_url($nr_photo); ?>" class="pd-review-photo">
            <div class="pd-review-info">
                <div class="pd-review-q">How was training with <?php echo esc_html($nr->trainer_name); ?>?</div>
                <div class="pd-review-date"><?php echo date('M j', strtotime($nr->session_date)); ?></div>
            </div>
            <div class="pd-stars" data-booking="<?php echo intval($nr->id); ?>" data-trainer="<?php echo esc_attr($nr->trainer_slug); ?>">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                <div class="pd-star" data-rating="<?php echo $s; ?>" onclick="previewRating(<?php echo intval($nr->id); ?>, <?php echo $s; ?>, '<?php echo esc_attr($nr->trainer_slug); ?>')">&#9734;</div>
                <?php endfor; ?>
            </div>
            <button class="pd-review-confirm" id="confirm-<?php echo intval($nr->id); ?>" style="display:none;margin-top:8px;padding:10px 20px;background:#FCB900;color:#0A0A0A;border:none;border-radius:8px;font-family:'Oswald',sans-serif;font-weight:700;font-size:13px;text-transform:uppercase;cursor:pointer;min-height:44px;touch-action:manipulation;-webkit-tap-highlight-color:transparent;width:100%" onclick="confirmReview(<?php echo intval($nr->id); ?>)">Submit <span id="confirm-stars-<?php echo intval($nr->id); ?>"></span>★ Review</button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Upcoming Sessions -->
    <div class="pd-sec">
        <div class="pd-sec-title">
            Upcoming Sessions
            <?php if (count($upcoming) > 3): ?>
            <span class="pd-sec-link" onclick="switchTab('bookings')">View All</span>
            <?php endif; ?>
        </div>
        <?php if (empty($upcoming)): ?>
        <div class="pd-empty">
            <div class="pd-empty-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <div class="pd-empty-text">No upcoming sessions</div>
            <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="pd-empty-cta">Book a Session</a>
        </div>
        <?php else: ?>
        <?php foreach (array_slice($upcoming, 0, 3) as $session):
            $photo = $session->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($session->trainer_name) . '&size=96&background=FCB900&color=0A0A0A&bold=true';
            $s_date = date('D, M j', strtotime($session->session_date));
            $s_time = !empty($session->start_time) ? date('g:i A', strtotime($session->start_time)) : '';
            $s_diff = strtotime($session->session_date . ' ' . ($session->start_time ?: '00:00:00')) - current_time('timestamp');
            if ($s_diff < 3600 && $s_diff > 0) { $s_cd = ceil($s_diff / 60) . ' min away'; }
            elseif ($s_diff < 86400 && $s_diff > 0) { $s_cd = floor($s_diff / 3600) . 'h ' . floor(($s_diff % 3600) / 60) . 'm away'; }
            elseif ($s_diff > 0) { $days = floor($s_diff / 86400); $s_cd = $days . ' day' . ($days > 1 ? 's' : '') . ' away'; }
            else { $s_cd = 'Starting soon'; }
            $s_phone = $session->trainer_phone ?? '';
        ?>
        <div class="pd-sess" style="flex-wrap:wrap">
            <img src="<?php echo esc_url($photo); ?>" class="pd-sess-photo" alt="<?php echo esc_attr($session->trainer_name); ?>">
            <div class="pd-sess-info">
                <div class="pd-sess-name"><?php echo esc_html($session->trainer_name); ?></div>
                <div class="pd-sess-meta">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?php echo esc_html($s_date . ($s_time ? ' @ ' . $s_time : '')); ?>
                </div>
                <?php if ($session->player_name): ?>
                <div class="pd-sess-meta">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?php echo esc_html($session->player_name); ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="pd-sess-right">
                <span class="pd-badge pd-badge-<?php echo esc_attr($session->status); ?>"><?php echo ucfirst($session->status); ?></span>
                <div class="pd-sess-countdown"><?php echo esc_html($s_cd); ?></div>
            </div>
            <?php if ($s_phone): ?>
            <div class="pd-sess-contacts">
                <a href="tel:<?php echo esc_attr($s_phone); ?>" class="pd-sess-contact-btn call">
                    <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                    Call
                </a>
                <a href="sms:<?php echo esc_attr($s_phone); ?>" class="pd-sess-contact-btn text">
                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    Text
                </a>
                <a href="<?php echo esc_url(home_url('/trainer/' . ($session->trainer_slug ?? '') . '/')); ?>" class="pd-sess-contact-btn rebook">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Rebook
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Mentorship Widget -->
    <?php if ($has_mentorship && $active_pair && $active_pair->status === 'active' && $mentorship_trainer): ?>
    <div class="pd-sec">
        <div class="pd-sec-title" style="display:flex;justify-content:space-between;align-items:center">
            Mentorship
            <span class="pd-sec-link" onclick="switchTab('mentorship')">View All</span>
        </div>
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:14px;display:flex;gap:12px;align-items:center;cursor:pointer" onclick="switchTab('mentorship')">
            <?php if (!empty($mentorship_trainer->photo_url)): ?>
            <img src="<?php echo esc_url($mentorship_trainer->photo_url); ?>" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid var(--gold)">
            <?php else: ?>
            <div style="width:44px;height:44px;border-radius:50%;background:#FCB900;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-family:'Oswald',sans-serif;font-weight:700;font-size:18px;color:#0A0A0A"><?php echo strtoupper(substr($mentorship_trainer->display_name, 0, 1)); ?></div>
            <?php endif; ?>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:13px;color:var(--text)"><?php echo esc_html($mentorship_trainer->display_name); ?></div>
                <div style="font-size:11px;color:var(--g500);margin-top:1px"><?php echo intval($active_pair->sessions_completed); ?>/<?php echo intval($active_pair->sessions_total); ?> sessions &middot; <?php echo ucfirst($active_pair->package_type); ?></div>
                <div style="height:3px;background:var(--border);border-radius:2px;overflow:hidden;margin-top:6px">
                    <div style="height:100%;width:<?php echo min(100, round(($active_pair->sessions_completed / max(1, $active_pair->sessions_total)) * 100)); ?>%;background:#22C55E;border-radius:2px"></div>
                </div>
            </div>
            <?php if (!empty($mentorship_sessions)): ?>
            <div style="text-align:right;flex-shrink:0">
                <div style="font-size:10px;color:var(--g500);text-transform:uppercase;letter-spacing:.5px">Next</div>
                <div style="font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;color:var(--gold)"><?php echo date('M j', strtotime($mentorship_sessions[0]->scheduled_at)); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Package Credits -->
    <?php if (!empty($package_credits)): ?>
    <div class="pd-sec">
        <div class="pd-sec-title">Your Credits</div>
        <?php foreach ($package_credits as $credit): ?>
        <div class="pd-credit" data-credit-id="<?php echo intval($credit->id); ?>" data-any-trainer="<?php echo intval($credit->any_trainer); ?>" data-original-trainer="<?php echo intval($credit->trainer_id); ?>">
            <div style="flex:1">
                <div style="font-weight:600;font-size:14px">
                    <?php if (intval($credit->any_trainer)): ?>
                        <span style="color:var(--gold,#FCB900)">Any Coach</span>
                        <?php if ($credit->trainer_name): ?>
                            <span style="font-size:11px;color:var(--g500);font-weight:400"> · purchased with <?php echo esc_html($credit->trainer_name); ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php echo esc_html($credit->trainer_name ?: 'Any Coach'); ?>
                    <?php endif; ?>
                </div>
                <div style="font-size:12px;color:var(--g500)">
                    <?php echo $credit->expires_at ? 'Expires ' . date('M j, Y', strtotime($credit->expires_at)) : 'No expiration'; ?>
                </div>
            </div>
            <div class="pd-credit-count"><?php echo intval($credit->remaining); ?> left</div>
            <a href="<?php echo esc_url(home_url('/trainers/')); ?>" class="pd-credit-book">Book</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Favorite Trainers -->
    <?php if (!empty($favorite_trainers)): ?>
    <div class="pd-sec">
        <div class="pd-sec-title">Your Coaches</div>
        <?php foreach ($favorite_trainers as $fav):
            $fav_photo = $fav->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($fav->display_name) . '&size=96&background=FCB900&color=0A0A0A&bold=true';
        ?>
        <a href="<?php echo esc_url(home_url('/trainer/' . $fav->slug . '/')); ?>" class="pd-coach">
            <img src="<?php echo esc_url($fav_photo); ?>" class="pd-coach-photo">
            <div class="pd-coach-info">
                <div class="pd-coach-name"><?php echo esc_html($fav->display_name); ?></div>
                <div class="pd-coach-meta"><?php echo intval($fav->session_count); ?> sessions · Last: <?php echo date('M j', strtotime($fav->last_session)); ?></div>
            </div>
            <div class="pd-coach-rebook">Rebook</div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ━━━ BOOKINGS TAB ━━━ -->
<div class="pd-tab" id="tab-bookings" role="tabpanel" aria-label="Bookings">

    <?php if (!empty($fsa_apps)): ?>
    <div class="pd-sec">
        <div class="pd-sec-title">Application History</div>
        <?php foreach ($fsa_apps as $app): ?>
        <div class="pd-app">
            <div class="pd-app-code"><?php echo esc_html($app->app_code); ?></div>
            <div class="pd-app-status">
                <span class="pd-app-dot <?php echo esc_attr($app->status); ?>"></span>
                <span style="font-size:13px;font-weight:600">
                    <?php echo $app->status === 'accepted' ? 'Matched' : ucfirst($app->status); ?>
                    <?php if ($app->trainer_name): ?> — <?php echo esc_html($app->trainer_name); ?><?php endif; ?>
                </span>
            </div>
            <div style="font-size:12px;color:var(--g500);margin-top:4px">
                <?php echo esc_html($app->child_name ?: 'Player'); ?> · Applied <?php echo date('M j, Y', strtotime($app->created_at)); ?>
                <?php if ($app->accepted_at): ?> · Accepted <?php echo date('M j', strtotime($app->accepted_at)); ?><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($upcoming)): ?>
    <div class="pd-sec">
        <div class="pd-sec-title">Upcoming</div>
        <?php foreach ($upcoming as $session):
            $photo = $session->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($session->trainer_name) . '&size=96&background=FCB900&color=0A0A0A&bold=true';
        ?>
        <div class="pd-sess">
            <img src="<?php echo esc_url($photo); ?>" class="pd-sess-photo">
            <div class="pd-sess-info">
                <div class="pd-sess-name"><?php echo esc_html($session->trainer_name); ?></div>
                <div class="pd-sess-meta"><?php echo date('D, M j', strtotime($session->session_date)); ?> @ <?php echo date('g:i A', strtotime($session->start_time)); ?></div>
                <?php if ($session->player_name): ?><div class="pd-sess-meta"><?php echo esc_html($session->player_name); ?></div><?php endif; ?>
            </div>
            <span class="pd-badge pd-badge-<?php echo esc_attr($session->status); ?>"><?php echo ucfirst($session->status); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php // v227: Mentorship upsell card
    do_action('ptp_parent_dashboard_after_bookings', $parent_id); ?>

    <div class="pd-sec">
        <div class="pd-sec-title">Completed Sessions</div>
        <?php if (empty($past_sessions)): ?>
        <div class="pd-empty">
            <div class="pd-empty-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 2a15 15 0 010 20 15 15 0 010-20"/><path d="M2 12h20"/></svg></div>
            <div class="pd-empty-text">No completed sessions yet</div>
        </div>
        <?php else: ?>
        <?php foreach ($past_sessions as $ps):
            $ps_photo = $ps->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($ps->trainer_name) . '&size=96&background=FCB900&color=0A0A0A&bold=true';
            $recap = isset($parent_session_recaps[$ps->id]) ? $parent_session_recaps[$ps->id] : null;
            $recap_id = 'recap-' . intval($ps->id);
            $effort_labels = array(1 => 'Needs Work', 2 => 'Fair', 3 => 'Good', 4 => 'Great', 5 => 'Elite');
        ?>
        <div class="pd-sess" style="flex-wrap:wrap">
            <img src="<?php echo esc_url($ps_photo); ?>" class="pd-sess-photo">
            <div class="pd-sess-info">
                <div class="pd-sess-name"><?php echo esc_html($ps->trainer_name); ?></div>
                <div class="pd-sess-meta"><?php echo date('M j, Y', strtotime($ps->session_date)); ?></div>
                <?php if ($ps->player_name): ?><div class="pd-sess-meta"><?php echo esc_html($ps->player_name); ?></div><?php endif; ?>
            </div>
            <?php if ($recap): ?>
            <span class="pd-recap-toggle" onclick="toggleRecap('<?php echo $recap_id; ?>', this)">
                View Recap <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
            <?php else: ?>
            <span class="pd-badge pd-badge-completed">Done</span>
            <?php endif; ?>

            <?php if ($recap): ?>
            <div class="pd-recap-detail" id="<?php echo $recap_id; ?>" style="width:100%">
                <?php if (!empty($recap->focus_worked_on)): ?>
                <div class="pd-recap-row">
                    <div class="pd-recap-label"><span class="pd-recap-label-dot" style="background:#FCB900"></span> What We Worked On</div>
                    <div class="pd-recap-text"><?php echo nl2br(esc_html($recap->focus_worked_on)); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($recap->achievements)): ?>
                <div class="pd-recap-row">
                    <div class="pd-recap-label" style="color:#16A34A"><span class="pd-recap-label-dot" style="background:#22C55E"></span> Wins</div>
                    <div class="pd-recap-text"><?php echo nl2br(esc_html($recap->achievements)); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($recap->areas_to_improve)): ?>
                <div class="pd-recap-row">
                    <div class="pd-recap-label" style="color:#D97706"><span class="pd-recap-label-dot" style="background:#F59E0B"></span> Keep Working On</div>
                    <div class="pd-recap-text"><?php echo nl2br(esc_html($recap->areas_to_improve)); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($recap->homework)): ?>
                <div class="pd-recap-row">
                    <div class="pd-recap-label" style="color:#2563EB"><span class="pd-recap-label-dot" style="background:#3B82F6"></span> Homework</div>
                    <div class="pd-recap-text"><?php echo nl2br(esc_html($recap->homework)); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($recap->player_effort) && $recap->player_effort > 0):
                    $eff = intval($recap->player_effort);
                    $eff_colors = array(1 => '#EF4444', 2 => '#F59E0B', 3 => '#3B82F6', 4 => '#22C55E', 5 => '#FCB900');
                ?>
                <div class="pd-recap-row">
                    <div class="pd-recap-label">Effort</div>
                    <div class="pd-recap-effort">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="pd-recap-dot<?php echo $i <= $eff ? ' on' : ''; ?>" <?php if ($i <= $eff): ?>style="background:<?php echo $eff_colors[$eff]; ?>"<?php endif; ?>></span>
                        <?php endfor; ?>
                        <span class="pd-recap-effort-label" style="color:<?php echo $eff_colors[$eff] ?? '#666'; ?>"><?php echo esc_html($effort_labels[$eff] ?? ''); ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ━━━ MESSAGES TAB ━━━ -->
<div class="pd-tab" id="tab-messages" role="tabpanel" aria-label="Messages">
    <div class="pd-sec">
        <div class="pd-sec-title">Conversations</div>
        <?php if (empty($conversations)): ?>
        <div class="pd-empty">
            <div class="pd-empty-icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
            <div class="pd-empty-text">No messages yet. Book a session to start chatting with your trainer!</div>
            <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="pd-empty-cta">Find a Coach</a>
        </div>
        <?php else: ?>
        <?php foreach ($conversations as $convo):
            $convo_name = $convo->other_name ?? 'Trainer';
            $initials = strtoupper(substr($convo_name, 0, 1));
            $preview = !empty($convo->last_message) ? wp_trim_words($convo->last_message, 12, '...') : 'No messages yet';
            $msg_url = home_url('/messages/?conversation=' . intval($convo->id ?? 0));
        ?>
        <a href="<?php echo esc_url($msg_url); ?>" class="pd-msg">
            <div class="pd-msg-avatar"><?php echo esc_html($initials); ?></div>
            <div class="pd-msg-info">
                <div class="pd-msg-name"><?php echo esc_html($convo_name); ?></div>
                <div class="pd-msg-preview"><?php echo esc_html($preview); ?></div>
            </div>
            <?php if (!empty($convo->unread)): ?>
            <div class="pd-unread"></div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($fsa_apps) && !empty($fsa_apps[0]->phone)): ?>
    <div class="pd-sec">
        <div class="pd-sec-title">SMS Updates</div>
        <div style="background:var(--white);border-radius:var(--r);padding:14px;box-shadow:var(--shadow)">
            <div style="font-size:13px;color:var(--g600);line-height:1.6">
                SMS notifications are sent to <strong><?php echo esc_html($fsa_apps[0]->phone); ?></strong> for booking confirmations, reminders, and updates.
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ━━━ PLAYERS TAB ━━━ -->
<div class="pd-tab" id="tab-players" role="tabpanel" aria-label="Players">
    <div class="pd-sec">
        <div class="pd-sec-title">
            Your Players
            <button onclick="showAddPlayerModal()" class="pd-btn pd-btn-sm">+ Add Player</button>
        </div>
        <?php if (empty($players)): ?>
        <div class="pd-empty">
            <div class="pd-empty-icon"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
            <div class="pd-empty-text">No players added yet</div>
            <button onclick="showAddPlayerModal()" class="pd-empty-cta">Add Your Player</button>
        </div>
        <?php else: ?>
        <?php foreach ($players as $p_raw):
            // Use unified player if available, otherwise fallback
            if (class_exists('PTP_Unified_Player')) {
                $player = PTP_Unified_Player::get($p_raw->id);
            } else {
                $player = $p_raw;
                $player->camp_count = 0;
                $player->completed_sessions = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE player_id = %d AND status = 'completed'", $p_raw->id
                )));
                $player->has_mentorship = false;
                $player->goals = array();
                $player->assessments = array();
                $player->milestones = array();
                $player->videos = array();
                $player->engagement_score = 0;
            }
            include PTP_PLUGIN_DIR . 'templates/components/unified-player-card.php';
        endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ━━━ ACCOUNT TAB ━━━ -->
<div class="pd-tab" id="tab-account" role="tabpanel" aria-label="Account">

    <div class="pd-refer">
        <div class="pd-refer-title">Give $25, Get $25</div>
        <div class="pd-refer-desc">Share your referral link. When a friend books, you both get $25 credit.</div>
        <div class="pd-refer-row">
            <input type="text" class="pd-refer-input" value="<?php echo esc_attr($referral_link); ?>" readonly id="refLink">
            <button class="pd-refer-btn" onclick="copyRef()">Copy</button>
        </div>
        <?php if ($referral_credit_balance > 0): ?>
        <div style="margin-top:10px;font-size:13px;color:var(--gold)">Credit Balance: $<?php echo number_format($referral_credit_balance, 0); ?></div>
        <?php endif; ?>
    </div>

    <div class="pd-sec">
        <div class="pd-sec-title">Account</div>
        <div class="pd-acct-card">
            <div>
                <div class="pd-acct-label">Name</div>
                <div class="pd-acct-val"><?php echo esc_html($user->display_name ?: $user->user_login); ?></div>
            </div>
            <div>
                <div class="pd-acct-label">Email</div>
                <div class="pd-acct-val"><?php echo esc_html($user->user_email); ?></div>
            </div>
            <?php if ($parent && $parent->phone): ?>
            <div>
                <div class="pd-acct-label">Phone</div>
                <div class="pd-acct-val"><?php echo esc_html($parent->phone); ?></div>
            </div>
            <?php endif; ?>
            <div>
                <div class="pd-acct-label">Member Since</div>
                <div class="pd-acct-val"><?php echo date('F Y', strtotime($user->user_registered)); ?></div>
            </div>
        </div>
    </div>

    <div class="pd-sec">
        <?php if ($has_mentorship): ?>
        <div class="pd-link" onclick="switchTab('mentorship')" style="cursor:pointer" role="button" tabindex="0">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            <span>Mentorship</span>
            <?php if (!empty($pending_feedback) && $pending_feedback > 0): ?>
            <span style="background:#FCB900;color:#0a0a0a;font-size:11px;font-weight:700;padding:2px 6px;border-radius:10px;margin-left:auto"><?php echo $pending_feedback; ?></span>
            <?php endif; ?>
            <span class="pd-link-arrow"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
        </div>
        <?php endif; ?>
        <a href="<?php echo esc_url(home_url('/')); ?>" class="pd-link">
            <svg viewBox="0 0 24 24"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg>
            <span>Browse Training</span>
            <span class="pd-link-arrow"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
        </a>
        <a href="<?php echo esc_url(home_url('/ptp-find-a-camp/')); ?>" class="pd-link">
            <svg viewBox="0 0 24 24"><path d="M12 2L2 22h20L12 2z"/><path d="M12 2v20"/></svg>
            <span>Summer Camps</span>
            <span class="pd-link-arrow"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
        </a>
        <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="pd-link">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <span>Find Trainers</span>
            <span class="pd-link-arrow"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
        </a>
        <a href="<?php echo wp_logout_url(home_url('/')); ?>" class="pd-link danger">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Log Out</span>
            <span class="pd-link-arrow"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
        </a>
    </div>
</div>
<!-- ━━━ MENTORSHIP TAB ━━━ -->
<div class="pd-tab" id="tab-mentorship" role="tabpanel" aria-label="Mentorship">

<?php if ($mentorship_paused && $active_pair): ?>
<!-- v233 M4: Paused mentorship — payment issue -->
<div style="text-align:center;padding:40px 20px">
    <div style="font-size:48px;margin-bottom:12px">⚠️</div>
    <h3 style="font-family:'Oswald',sans-serif;font-size:22px;text-transform:uppercase;margin:0 0 8px;color:#F97316">Payment Issue</h3>
    <p style="font-size:14px;color:var(--g500);line-height:1.6;margin:0 0 16px;max-width:340px;margin-left:auto;margin-right:auto">
        Your mentorship with <?php echo esc_html($mentorship_trainer ? $mentorship_trainer->display_name : 'your coach'); ?> is paused
        <?php if (!empty($active_pair->stripe_payment_intent) && empty($active_pair->stripe_subscription_id)): ?>
            — your payment may need to be completed.
        <?php else: ?>
            due to a payment issue. Please update your payment method to resume sessions.
        <?php endif; ?>
    </p>
    <p style="font-size:13px;color:var(--g400);margin:0 0 20px">
        <?php echo intval($active_pair->sessions_completed); ?> of <?php echo intval($active_pair->sessions_total); ?> sessions completed
    </p>
    <?php if (!empty($active_pair->stripe_payment_intent) && empty($active_pair->stripe_subscription_id)): ?>
        <button onclick="verifyMentorshipPayment(<?php echo intval($active_pair->id); ?>)" class="pd-btn" style="display:inline-block;max-width:260px">
            Verify Payment
        </button>
    <?php else: ?>
        <a href="<?php echo esc_url(add_query_arg('pair_id', intval($active_pair->id), home_url('/mentorship-checkout/'))); ?>" class="pd-btn" style="display:inline-block;max-width:260px">
            Update Payment
        </a>
    <?php endif; ?>
    <p style="font-size:12px;color:var(--g400);margin-top:16px">
        Questions? Contact us at <a href="tel:6106714778" style="color:var(--gold)">(610) 671-4778</a>
    </p>
</div>

<?php elseif ($mentorship_completed && $active_pair): ?>
<!-- v228: Package completed — renewal CTA -->
<div style="text-align:center;padding:32px 20px">
    <div style="width:64px;height:64px;border-radius:50%;background:#22C55E20;border:3px solid #22C55E;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <h3 style="font-family:'Oswald',sans-serif;font-size:22px;text-transform:uppercase;margin:0 0 6px">Package Complete!</h3>
    <p style="font-size:14px;color:var(--g500);line-height:1.6;margin:0 0 4px">
        <?php echo intval($active_pair->sessions_completed); ?> sessions with <?php echo esc_html($mentorship_trainer ? $mentorship_trainer->display_name : 'your coach'); ?>
    </p>
    <p style="font-size:12px;color:var(--g400);margin:0 0 20px"><?php echo ucfirst($active_pair->package_type); ?> package &middot; Completed <?php echo date('M j', strtotime($active_pair->completed_at)); ?></p>

    <a href="<?php echo esc_url(add_query_arg(array('pair_id' => $active_pair->id, 'package' => $active_pair->package_type), home_url('/mentorship-checkout/'))); ?>"
       class="pd-btn" style="display:block;max-width:280px;margin:0 auto 10px;text-decoration:none;background:#FCB900;color:#0A0A0A">
        Renew <?php echo ucfirst($active_pair->package_type); ?> Package
    </a>
    <a href="<?php echo esc_url(add_query_arg(array('pair_id' => $active_pair->id), home_url('/mentorship-checkout/'))); ?>"
       style="font-size:13px;color:var(--g500);text-decoration:underline">
        Or switch to a different package
    </a>
</div>

<?php elseif (!$has_mentorship): ?>
<!-- No active mentorship — upsell -->
<div style="text-align:center;padding:40px 20px">
    <div style="font-size:48px;margin-bottom:12px">🏆</div>
    <h3 style="font-family:'Oswald',sans-serif;font-size:22px;text-transform:uppercase;margin:0 0 8px">1-on-1 Mentorship</h3>
    <p style="font-size:14px;color:var(--g500);line-height:1.6;margin:0 0 24px;max-width:320px;margin-left:auto;margin-right:auto">
        Weekly sessions with a current MLS player or D1 athlete. Goals, video feedback, and a real relationship built over months — not just training.
    </p>
    <a href="<?php echo esc_url(home_url('/mentorship/')); ?>" class="pd-btn" style="display:inline-block;max-width:260px">
        Find a Mentor
    </a>
</div>

<?php else:
    $trainer_name = $mentorship_trainer ? $mentorship_trainer->display_name : 'Your Mentor';
    $player_name  = $mentorship_player  ? $mentorship_player->name           : 'Your Player';
    $status_label = array(
        'active'          => 'Active',
        'interest'        => 'Request Submitted',
        'intro_scheduled' => 'Intro Scheduled',
        'intro_done'      => 'Intro Complete — Purchase to Start',
    )[$active_pair->status] ?? ucfirst($active_pair->status);
    $status_color = $active_pair->status === 'active' ? '#22C55E' : ($active_pair->status === 'interest' ? '#8B5CF6' : '#F59E0B');
?>

<!-- Mentor header card -->
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:18px;margin-bottom:16px">
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">
        <?php if (!empty($mentorship_trainer->photo_url)): ?>
        <img src="<?php echo esc_url($mentorship_trainer->photo_url); ?>" alt="<?php echo esc_attr($trainer_name); ?>"
             style="width:52px;height:52px;border-radius:50%;object-fit:cover;flex-shrink:0">
        <?php else: ?>
        <div style="width:52px;height:52px;border-radius:50%;background:#FCB900;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;color:#0A0A0A">
            <?php echo strtoupper(substr($trainer_name, 0, 1)); ?>
        </div>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
            <div style="font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;color:var(--text)"><?php echo esc_html($trainer_name); ?></div>
            <div style="font-size:12px;color:var(--g500);margin-top:2px"><?php echo esc_html($player_name); ?>'s Mentor</div>
        </div>
        <span style="background:<?php echo $status_color; ?>20;color:<?php echo $status_color; ?>;font-size:10px;font-weight:700;font-family:'Oswald',sans-serif;text-transform:uppercase;padding:4px 8px;border-radius:20px;flex-shrink:0">
            <?php echo esc_html($status_label); ?>
        </span>
    </div>

    <?php if ($active_pair->status === 'active'): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(90px, 1fr));gap:8px">
        <?php foreach (array(
            array('Sessions', $active_pair->sessions_completed . ' / ' . $active_pair->sessions_total),
            array('Video Reviews', $active_pair->video_reviews_remaining . ' left'),
            array('Package', ucfirst($active_pair->package_type ?: '—')),
        ) as $stat): ?>
        <div style="background:var(--bg);border-radius:var(--r-sm);padding:10px;text-align:center">
            <div style="font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;color:var(--text)"><?php echo esc_html($stat[1]); ?></div>
            <div style="font-size:10px;color:var(--g500);margin-top:2px"><?php echo esc_html($stat[0]); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php elseif ($active_pair->status === 'interest'): ?>
    <div style="background:var(--bg);border-radius:var(--r-md);padding:14px;text-align:center">
        <p style="font-size:13px;color:var(--g500);line-height:1.5;margin:0"><?php echo esc_html(explode(' ', $trainer_name)[0]); ?> will reach out within 24-48 hours to schedule your free intro call. No payment required.</p>
    </div>
    <?php elseif ($active_pair->status === 'intro_scheduled'): ?>
    <div style="background:var(--bg);border-radius:var(--r-md);padding:14px;text-align:center">
        <p style="font-size:13px;color:var(--g500);line-height:1.5;margin:0">Your free intro call with <?php echo esc_html(explode(' ', $trainer_name)[0]); ?> is coming up. Check your email for the meeting link.</p>
    </div>
    <?php elseif ($active_pair->status === 'intro_done'): ?>
    <a href="<?php echo esc_url(add_query_arg('pair_id', intval($active_pair->id), home_url('/mentorship-checkout/'))); ?>"
       class="pd-btn" style="display:block;text-align:center;text-decoration:none;background:#FCB900;color:#0A0A0A">
        Choose Your Package &amp; Start &rarr;
    </a>
    <?php endif; ?>
</div>

<?php if ($active_pair->status === 'active'): ?>

<!-- v235.2: Onboarding (shows for first-time mentees) -->
<?php include(dirname(__FILE__) . '/components/mentorship-onboarding.php'); ?>

<!-- v235.2: Init pair chat -->
<script>if(typeof mcInit==='function') mcInit(<?php echo intval($active_pair->id); ?>);</script>

<!-- Next session -->
<?php if (!empty($mentorship_sessions)): $ms = $mentorship_sessions[0];
    $ms_dt    = new DateTime($ms->scheduled_at, new DateTimeZone('America/New_York'));
    $now      = new DateTime('now', new DateTimeZone('America/New_York'));
    $diff_min = ($ms_dt->getTimestamp() - $now->getTimestamp()) / 60;
    $is_live  = $diff_min >= -5 && $diff_min <= 60;
    $join_url = !empty($ms->host_url) ? $ms->meeting_url : $ms->meeting_url; // participant always gets meeting_url
?>
<div style="background:<?php echo $is_live ? '#FCB90015' : 'var(--bg-card)'; ?>;border:1px solid <?php echo $is_live ? '#FCB900' : 'var(--border)'; ?>;border-radius:var(--r-lg);padding:16px;margin-bottom:16px">
    <div style="font-family:'Oswald',sans-serif;font-size:10px;color:<?php echo $is_live ? '#FCB900' : 'var(--g500)'; ?>;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">
        <?php echo $is_live ? '● HAPPENING NOW' : 'NEXT SESSION'; ?>
    </div>
    <div style="font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;color:var(--text);margin-bottom:2px">
        <?php echo esc_html(ucfirst(str_replace('_', ' ', $ms->session_type))); ?>
    </div>
    <div style="font-size:12px;color:var(--g500);margin-bottom:12px">
        <?php echo esc_html($ms_dt->format('l, F j')); ?> &middot; <?php echo esc_html($ms_dt->format('g:i A')); ?> ET &middot; <?php echo esc_html($ms->duration_minutes); ?> min
    </div>
    <?php if (!empty($join_url)): ?>
    <a href="<?php echo esc_url($join_url); ?>" target="_blank"
       style="display:block;text-align:center;padding:11px;background:<?php echo $is_live ? '#FCB900' : 'var(--bg)'; ?>;color:<?php echo $is_live ? '#0A0A0A' : 'var(--text)'; ?>;border:1px solid <?php echo $is_live ? '#FCB900' : 'var(--border)'; ?>;border-radius:var(--r-md);font-family:'Oswald',sans-serif;font-weight:700;font-size:13px;text-transform:uppercase;text-decoration:none">
        <?php echo $is_live ? 'Join Zoom Now →' : 'Add to Calendar'; ?>
    </a>
    <?php endif; ?>
</div>
<?php else: ?>
<!-- No upcoming session — show request button -->
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px;margin-bottom:16px;text-align:center">
    <div style="font-family:'Oswald',sans-serif;font-size:10px;color:var(--g500);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">No Session Scheduled</div>
    <button onclick="requestMentorshipSession(<?php echo $active_pair->id; ?>, '<?php echo esc_js($trainer_name); ?>')"
            style="background:var(--gold);border:none;color:#0A0A0A;padding:13px 24px;border-radius:10px;font-family:'Oswald',sans-serif;font-weight:700;font-size:13px;text-transform:uppercase;cursor:pointer;width:100%;max-width:280px">
        Request Next Session
    </button>
    <div style="font-size:11px;color:var(--g400);margin-top:8px"><?php echo esc_html(explode(' ', $trainer_name)[0]); ?> will confirm and send you the meeting link</div>
</div>
<?php endif; ?>

<!-- Package progress -->
<?php
    $sessions_left = max(0, $active_pair->sessions_total - $active_pair->sessions_completed);
    $pct = round(($active_pair->sessions_completed / max(1, $active_pair->sessions_total)) * 100);
    $near_end = $sessions_left <= 2 && $sessions_left > 0;
?>
<div style="background:var(--bg-card);border:1px solid <?php echo $near_end ? '#FCB900' : 'var(--border)'; ?>;border-radius:var(--r-lg);padding:14px 16px;margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <span style="font-family:'Oswald',sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--g500)">Package Progress</span>
        <span style="font-size:12px;font-weight:600;color:<?php echo $near_end ? '#F59E0B' : 'var(--text)'; ?>"><?php echo $active_pair->sessions_completed; ?> / <?php echo $active_pair->sessions_total; ?> sessions</span>
    </div>
    <div style="height:6px;background:var(--bg);border-radius:3px;overflow:hidden">
        <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $near_end ? '#F59E0B' : '#22C55E'; ?>;border-radius:3px;transition:width .3s"></div>
    </div>
    <?php if ($near_end): ?>
    <div style="font-size:12px;color:#F59E0B;font-weight:600;margin-top:8px;text-align:center">
        <?php echo $sessions_left; ?> session<?php echo $sessions_left > 1 ? 's' : ''; ?> remaining — <a href="<?php echo esc_url(add_query_arg(array('pair_id' => $active_pair->id, 'package' => $active_pair->package_type), home_url('/mentorship-checkout/'))); ?>" style="color:#FCB900;text-decoration:underline">Renew Package</a>
    </div>
    <?php endif; ?>
</div>

<!-- v235.2: Chat thread container (mcThread moves here via JS) -->
<div id="mcChatAnchor"></div>
<script>
(function(){
    var anchor = document.getElementById('mcChatAnchor');
    var thread = document.getElementById('mcThread');
    if (anchor && thread) anchor.appendChild(thread);
})();
</script>

<!-- Goals -->
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px;margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <div style="font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;color:var(--text)">Goals</div>
        <?php if ($active_pair->status === 'active'): ?>
        <button onclick="openMentorshipGoalModal(<?php echo $active_pair->id; ?>)"
                style="background:transparent;border:1px solid var(--gold-dark);color:var(--gold-dark);padding:5px 12px;border-radius:6px;font-family:'Oswald',sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;cursor:pointer">
            + Add
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($mentorship_goals)): ?>
    <p style="font-size:13px;color:var(--g400);text-align:center;padding:12px 0">No goals set yet. Add your first goal above.</p>
    <?php else: foreach ($mentorship_goals as $g):
        $gtype = $g->goal_type ?? 'game';
        $gcolor = $goal_type_colors[$gtype] ?? '#FCB900';
        $glabel = $goal_type_labels[$gtype] ?? '⚽ Game';
    ?>
    <div style="display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border-light)">
        <div style="width:8px;height:8px;border-radius:50%;background:<?php echo $gcolor; ?>;flex-shrink:0;margin-top:5px"></div>
        <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;color:var(--text)"><?php echo esc_html($g->title); ?></div>
            <div style="font-size:10px;color:<?php echo $gcolor; ?>;margin-top:2px"><?php echo esc_html($glabel); ?></div>
        </div>
        <?php if ($active_pair->status === 'active'): ?>
        <button onclick="completeMentorshipGoal(<?php echo $g->id; ?>, this)"
                style="flex-shrink:0;background:transparent;border:1px solid var(--border);color:var(--g400);padding:4px 8px;border-radius:6px;font-size:10px;cursor:pointer">
            Done
        </button>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- Videos -->
<?php if ($active_pair->status === 'active'): ?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px;margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <div>
            <div style="font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;color:var(--text)">Videos</div>
            <div style="font-size:11px;color:var(--g500);margin-top:1px"><?php echo intval($active_pair->video_reviews_remaining); ?> review<?php echo $active_pair->video_reviews_remaining != 1 ? 's' : ''; ?> remaining</div>
        </div>
        <?php if (intval($active_pair->video_reviews_remaining) > 0): ?>
        <button onclick="openMentorshipVideoModal(<?php echo $active_pair->id; ?>)"
                style="background:var(--gold-dark);border:none;color:#0A0A0A;padding:7px 14px;border-radius:8px;font-family:'Oswald',sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;cursor:pointer">
            + Submit
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($mentorship_videos)): ?>
    <p style="font-size:13px;color:var(--g400);text-align:center;padding:12px 0">No videos submitted yet. Film a clip and submit for feedback.</p>
    <?php else: foreach ($mentorship_videos as $v):
        $v_status_color = $v->status === 'reviewed' ? '#22C55E' : '#F59E0B';
        $v_status_label = ucfirst($v->status);
    ?>
    <div style="padding:10px 0;border-bottom:1px solid var(--border-light)">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px">
            <div style="font-size:12px;font-weight:600;color:var(--text)"><?php echo esc_html(substr($v->player_note, 0, 60) ?: 'Video submission'); ?><?php echo strlen($v->player_note) > 60 ? '…' : ''; ?></div>
            <span style="font-size:10px;color:<?php echo $v_status_color; ?>;font-weight:600;flex-shrink:0;margin-left:8px"><?php echo esc_html($v_status_label); ?></span>
        </div>
        <div style="font-size:11px;color:var(--g500)"><?php echo date('M j', strtotime($v->created_at)); ?></div>
        <?php if ($v->status === 'reviewed' && $v->coach_feedback): ?>
        <div style="background:var(--bg);border-left:3px solid #22C55E;border-radius:0 6px 6px 0;padding:8px 10px;margin-top:8px;font-size:12px;color:var(--g500);line-height:1.5">
            <?php echo esc_html(substr($v->coach_feedback, 0, 160)); ?><?php echo strlen($v->coach_feedback) > 160 ? '…' : ''; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>
<?php endif; ?>

<?php
// ── Session Recaps ──
$completed_mentor_sessions = array();
if ($active_pair && $active_pair->status === 'active') {
    $completed_mentor_sessions = $wpdb->get_results($wpdb->prepare(
        "SELECT s.* FROM {$wpdb->prefix}ptp_mentorship_sessions s
         JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
         WHERE a.pair_id = %d AND s.status = 'completed' AND s.parent_summary IS NOT NULL AND s.parent_summary != ''
         ORDER BY s.completed_at DESC LIMIT 8",
        $active_pair->id
    ));
}
?>
<?php if (!empty($completed_mentor_sessions)): ?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px;margin-bottom:16px">
    <div style="font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--g500);margin-bottom:14px">Session Recaps</div>
    <?php foreach ($completed_mentor_sessions as $csi => $cs):
        $cs_dt   = new DateTime($cs->completed_at ?? $cs->scheduled_at, new DateTimeZone('America/New_York'));
        $cs_num  = $cs->session_number ?: ($csi + 1);
        $cs_open = $csi === 0; // newest expanded by default
    ?>
    <div style="border-bottom:1px solid var(--border-light);padding:12px 0<?php echo $csi === 0 ? ';padding-top:0' : ''; ?>">
        <button onclick="toggleMentorRecap(this)" style="width:100%;background:none;border:none;padding:0;cursor:pointer;text-align:left;display:flex;align-items:center;gap:10px">
            <div style="width:32px;height:32px;border-radius:50%;background:<?php echo $csi === 0 ? 'var(--gold)' : 'var(--surface)'; ?>;border:1px solid <?php echo $csi === 0 ? 'var(--gold)' : 'var(--border)'; ?>;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;color:<?php echo $csi === 0 ? 'var(--black)' : 'var(--g500)'; ?>;flex-shrink:0"><?php echo $cs_num; ?></div>
            <div style="flex:1;min-width:0;text-align:left">
                <div style="font-size:13px;font-weight:600;color:var(--text);line-height:1.2">Session #<?php echo $cs_num; ?></div>
                <div style="font-size:11px;color:var(--g500);margin-top:1px"><?php echo esc_html($cs_dt->format('M j, g:i A')); ?> ET
                    <?php if ($cs->energy_rating): ?>
                    &nbsp;<?php echo str_repeat('★', intval($cs->energy_rating)); ?><?php echo str_repeat('☆', 5 - intval($cs->energy_rating)); ?>
                    <?php endif; ?>
                </div>
            </div>
            <span class="recap-chev" style="font-size:18px;color:var(--g400);transition:transform .2s;flex-shrink:0"><?php echo $cs_open ? '&#8964;' : '&#8250;'; ?></span>
        </button>
        <div class="recap-body" style="<?php echo $cs_open ? '' : 'display:none;'; ?>margin-top:10px">
            <div style="font-size:13px;color:var(--g700);line-height:1.6;margin-bottom:10px"><?php echo esc_html($cs->parent_summary); ?></div>
            <?php if ($cs->action_item): ?>
            <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:10px 13px">
                <div style="font-family:'Oswald',sans-serif;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#92400E;margin-bottom:4px">This Week's Mission</div>
                <div style="font-size:13px;color:#78350F;font-weight:600;line-height:1.4"><?php echo esc_html($cs->action_item); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; // end active_pair->status === 'active' ?>

<?php endif; // end has_mentorship ?>
</div><!-- tab-mentorship -->

</div><!-- .pd-content -->

<!-- v228: PD_CONFIG must be defined before parent-dashboard-v200.js runs.
     Was previously only defined inside the paused-mentorship conditional,
     causing all video upload, goal add, and recap JS to crash for active mentorships. -->
<script>
var PD_CONFIG = {
    ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
    nonce: '<?php echo esc_js($nonce ?? wp_create_nonce('ptp_nonce')); ?>',
    mentorshipNonce: '<?php echo wp_create_nonce('ptp_ajax_nonce'); ?>'
};
</script>

<div class="pd-nav" role="tablist" aria-label="Dashboard navigation">
    <div class="pd-nav-item active" data-tab="home" role="tab" aria-selected="true" aria-label="Home" tabindex="0">
        <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    </div>
    <div class="pd-nav-item" data-tab="bookings" role="tab" aria-selected="false" aria-label="Bookings" tabindex="0">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <?php if ($upcoming_count > 0): ?>
        <div class="pd-nav-badge"><?php echo $upcoming_count; ?></div>
        <?php endif; ?>
    </div>
    <div class="pd-nav-item" data-tab="messages" role="tab" aria-selected="false" aria-label="Messages" tabindex="0">
        <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        <?php if ($unread_count > 0): ?>
        <div class="pd-nav-badge"><?php echo $unread_count; ?></div>
        <?php endif; ?>
    </div>
    <div class="pd-nav-item" data-tab="players" role="tab" aria-selected="false" aria-label="Players" tabindex="0">
        <svg viewBox="0 0 24 24"><path d="M12 2a5 5 0 015 5c0 2.76-2.24 5-5 5s-5-2.24-5-5a5 5 0 015-5z"/><path d="M20 21v-1a7 7 0 00-7-7h-2a7 7 0 00-7 7v1"/></svg>
    </div>
    <?php if ($has_mentorship): ?>
    <div class="pd-nav-item" data-tab="mentorship" role="tab" aria-selected="false" aria-label="Mentorship" tabindex="0">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
    </div>
    <?php endif; ?>
    <div class="pd-nav-item" data-tab="account" role="tab" aria-selected="false" aria-label="Account" tabindex="0">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
    </div>
</div>

<!-- ═══ Add Player Modal ═══ -->
<div id="addPlayerModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;padding:20px;">
    <div style="background:var(--white);border-radius:var(--r-lg);padding:24px;max-width:400px;width:100%;margin:auto;position:relative;top:50%;transform:translateY(-50%);">
        <h3 style="font-family:'Oswald',sans-serif;font-size:18px;font-weight:600;text-transform:uppercase;margin-bottom:16px;">Add Player</h3>
        <form id="addPlayerForm">
            <div style="display:grid;gap:12px;">
                <input type="text" name="player_name" placeholder="Player's Name" required style="padding:12px;border:1px solid var(--g200);border-radius:8px;font-size:16px;min-height:44px;">
                <input type="number" name="player_age" placeholder="Age" min="4" max="18" required style="padding:12px;border:1px solid var(--g200);border-radius:8px;font-size:16px;min-height:44px;">
                <select name="player_position" style="padding:12px;border:1px solid var(--g200);border-radius:8px;font-size:16px;min-height:44px;">
                    <option value="">Position (optional)</option>
                    <option>Forward</option><option>Midfielder</option><option>Defender</option><option>Goalkeeper</option>
                </select>
                <select name="skill_level" style="padding:12px;border:1px solid var(--g200);border-radius:8px;font-size:16px;min-height:44px;">
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                </select>
                <textarea name="goals" placeholder="Goals / what they want to work on" rows="2" style="padding:12px;border:1px solid var(--g200);border-radius:8px;font-size:16px;"></textarea>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px;">
                <button type="button" onclick="hideAddPlayerModal()" class="pd-btn pd-btn-ghost pd-btn-full">Cancel</button>
                <button type="submit" class="pd-btn pd-btn-full">Save</button>
            </div>
        </form>
    </div>
</div>



<?php
$dashboard_type = 'parent';
include(dirname(__FILE__) . '/components/mentorship-components.php');
include(dirname(__FILE__) . '/components/mentorship-pair-chat.php');
?>
<?php wp_footer(); ?>
</body>
</html>
