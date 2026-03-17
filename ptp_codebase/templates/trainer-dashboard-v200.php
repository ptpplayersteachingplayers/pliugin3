<?php
/**
 * Trainer Dashboard v200 — Premium Redesign
 * 
 * Complete visual overhaul with PTP gold/black premium brand identity.
 * All backend queries and AJAX functions preserved from v138.
 * 
 * v200 Changes:
 * - Modern card-based layout with depth and layering
 * - Redesigned header with gradient mesh background
 * - Better information hierarchy and visual weight
 * - Premium earnings display with animated counters
 * - Improved session cards with location + parent info
 * - Cleaner schedule editor with visual day indicators
 * - Redesigned profile tab with completion progress ring
 * - Smoother animations and micro-interactions
 * - Better empty states with contextual illustrations
 * - Full mobile-first responsive (320px–1200px+)
 */
defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    wp_redirect(home_url('/login/?redirect_to=' . urlencode($_SERVER['REQUEST_URI'])));
    exit;
}

global $wpdb;
$user_id = get_current_user_id();

// Get trainer
$trainer = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", 
    $user_id
));

if (!$trainer) {
    wp_redirect(home_url('/apply/'));
    exit;
}

if ($trainer->status === 'pending') {
    wp_redirect(home_url('/trainer-pending/'));
    exit;
}

if ($trainer->status === 'rejected') {
    wp_redirect(home_url('/apply/?status=rejected'));
    exit;
}

// Safe property access
$trainer_id = intval($trainer->id);
$trainer_slug = !empty($trainer->slug) ? $trainer->slug : 'trainer-' . $trainer_id;
$display_name = !empty($trainer->display_name) ? $trainer->display_name : 'Trainer';
$first_name = explode(' ', $display_name);
$first_name = $first_name[0];

// Stripe status
$has_stripe = !empty($trainer->stripe_account_id);
$stripe_complete = false;
if ($has_stripe && class_exists('PTP_Stripe')) {
    $stripe_complete = PTP_Stripe::is_account_complete($trainer->stripe_account_id);
    // v222.1: If live check says complete but DB is stale, sync now
    if ($stripe_complete && (!$trainer->stripe_payouts_enabled || !$trainer->stripe_charges_enabled)) {
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('stripe_charges_enabled' => 1, 'stripe_payouts_enabled' => 1),
            array('id' => $trainer->id)
        );
        $trainer->stripe_payouts_enabled = 1;
        $trainer->stripe_charges_enabled = 1;
    }
}

$profile_url = home_url('/trainer/' . $trainer_slug . '/');
$nonce = wp_create_nonce('ptp_trainer_nonce');
$nonce_general = wp_create_nonce('ptp_nonce');

// Date ranges
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$week_start = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-01');
$min_payout = floatval(get_option('ptp_min_payout', 25));

// v230: Latest booking notification — most recent booking for this trainer
$latest_booking = $wpdb->get_row($wpdb->prepare("
    SELECT b.*, pl.name as player_name, pl.age as player_age, pl.skill_level as player_skill,
           pa.display_name as parent_name, pa.phone as parent_phone, pa.email as parent_email,
           t.training_locations as trainer_training_locations,
           t.location as trainer_location, t.city as trainer_city, t.state as trainer_state
    FROM {$wpdb->prefix}ptp_bookings b
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
    LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
    LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
    WHERE b.trainer_id = %d AND b.status IN ('confirmed','pending')
    ORDER BY b.created_at DESC LIMIT 1
", $trainer_id));

// Resolve location for latest booking
$latest_booking_location = '';
if ($latest_booking) {
    if (!empty($latest_booking->location)) {
        $latest_booking_location = $latest_booking->location;
    } elseif (!empty($latest_booking->trainer_location)) {
        $latest_booking_location = $latest_booking->trainer_location;
    } elseif (!empty($latest_booking->trainer_city)) {
        $latest_booking_location = $latest_booking->trainer_city . (!empty($latest_booking->trainer_state) ? ', ' . $latest_booking->trainer_state : '');
    } elseif (!empty($latest_booking->trainer_training_locations)) {
        $tl = json_decode($latest_booking->trainer_training_locations, true);
        if (!is_array($tl)) $tl = json_decode(wp_unslash($latest_booking->trainer_training_locations), true);
        if (is_array($tl)) {
            foreach ($tl as $loc) {
                if (is_array($loc) && !empty($loc['name'])) { $latest_booking_location = $loc['name']; break; }
            }
        }
    }
    if (empty($latest_booking_location)) $latest_booking_location = 'TBD';
    
    // How fresh is this booking?
    $latest_booking_age = time() - strtotime($latest_booking->created_at);
    $latest_booking_is_new = $latest_booking_age < (48 * 3600); // Show as "new" for 48 hours
}

// Upcoming sessions
$upcoming_raw = $wpdb->get_results($wpdb->prepare("
    SELECT b.*, pl.name as player_name, pa.display_name as parent_name, pa.phone as parent_phone
    FROM {$wpdb->prefix}ptp_bookings b 
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id 
    LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
    WHERE b.trainer_id = %d AND b.session_date >= CURDATE() AND b.status IN ('confirmed','pending') 
    ORDER BY b.session_date ASC, b.start_time ASC LIMIT 20
", $trainer_id));

if (!$upcoming_raw) {
    $upcoming_raw = array();
}

// Group sessions by date
$sessions_grouped = array(
    'today' => array(),
    'tomorrow' => array(),
    'this_week' => array(),
    'later' => array()
);

foreach ($upcoming_raw as $s) {
    if ($s->session_date === $today) {
        $sessions_grouped['today'][] = $s;
    } elseif ($s->session_date === $tomorrow) {
        $sessions_grouped['tomorrow'][] = $s;
    } elseif ($s->session_date <= $week_end) {
        $sessions_grouped['this_week'][] = $s;
    } else {
        $sessions_grouped['later'][] = $s;
    }
}
$total_upcoming = count($upcoming_raw);

// ── Merge Mentorship Sessions into Upcoming ──
$mentorship_upcoming = array();
if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ptp_mentorship_sessions'")) {
    $mentorship_upcoming = $wpdb->get_results($wpdb->prepare("
        SELECT s.id, s.scheduled_at, s.duration_minutes, s.session_type, s.status as m_status,
               s.meeting_url, s.host_url, s.title as session_title,
               mp.player_id, mp.package_type, mp.per_session_price,
               pl.name as player_name, pl.first_name as player_first,
               pa.display_name as parent_name, pa.email as parent_email
        FROM {$wpdb->prefix}ptp_mentorship_sessions s
        JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
        JOIN {$wpdb->prefix}ptp_mentorship_pairs mp ON a.pair_id = mp.id
        LEFT JOIN {$wpdb->prefix}ptp_players pl ON mp.player_id = pl.id
        LEFT JOIN {$wpdb->prefix}ptp_parents pa ON mp.parent_id = pa.id
        WHERE s.trainer_id = %d AND s.scheduled_at >= NOW() AND s.status IN ('scheduled','confirmed')
        ORDER BY s.scheduled_at ASC LIMIT 10
    ", $trainer_id)) ?: array();

    foreach ($mentorship_upcoming as $ms) {
        $ms_date = date('Y-m-d', strtotime($ms->scheduled_at));
        $fake_session = (object) array(
            'id'              => 'ms_' . $ms->id,
            'session_date'    => $ms_date,
            'start_time'      => date('H:i:s', strtotime($ms->scheduled_at)),
            'player_name'     => ($ms->player_name ?: $ms->player_first ?: 'Mentee'),
            'parent_name'     => $ms->parent_name,
            'location'        => ucfirst(str_replace('_', ' ', $ms->session_type ?: 'mentorship')),
            'trainer_payout'  => floatval($ms->per_session_price ?: 0),
            'status'          => 'mentorship',
            'is_mentorship'   => true,
            'package_type'    => $ms->package_type,
            'meeting_url'     => $ms->meeting_url,
            'host_url'        => $ms->host_url,
            'duration'        => $ms->duration_minutes,
        );

        if ($ms_date === $today) $sessions_grouped['today'][] = $fake_session;
        elseif ($ms_date === $tomorrow) $sessions_grouped['tomorrow'][] = $fake_session;
        elseif ($ms_date <= $week_end) $sessions_grouped['this_week'][] = $fake_session;
        else $sessions_grouped['later'][] = $fake_session;
        $total_upcoming++;
    }

    // Re-sort each group by time
    foreach ($sessions_grouped as &$group) {
        usort($group, function($a, $b) {
            return strcmp($a->start_time ?? '', $b->start_time ?? '');
        });
    }
    unset($group);
}

// Needs confirmation
$needs_confirmation = $wpdb->get_results($wpdb->prepare("
    SELECT b.*, pl.name as player_name, pa.display_name as parent_name
    FROM {$wpdb->prefix}ptp_bookings b 
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id 
    LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
    WHERE b.trainer_id = %d AND b.session_date < CURDATE() AND b.status = 'confirmed'
    ORDER BY b.session_date DESC LIMIT 10
", $trainer_id));

if (!$needs_confirmation) {
    $needs_confirmation = array();
}

// Combined earnings query
$earnings_data = $wpdb->get_row($wpdb->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN session_date = CURDATE() AND status = 'completed' THEN trainer_payout ELSE 0 END), 0) as today,
        COALESCE(SUM(CASE WHEN session_date >= %s AND status = 'completed' THEN trainer_payout ELSE 0 END), 0) as week,
        COALESCE(SUM(CASE WHEN session_date >= %s AND status = 'completed' THEN trainer_payout ELSE 0 END), 0) as month,
        COALESCE(SUM(CASE WHEN status = 'completed' AND payout_status = 'pending' THEN trainer_payout ELSE 0 END), 0) as pending,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN trainer_payout ELSE 0 END), 0) as total
    FROM {$wpdb->prefix}ptp_bookings 
    WHERE trainer_id = %d
", $week_start, $month_start, $trainer_id));

$earnings = array(
    'today' => floatval($earnings_data->today ?? 0),
    'week' => floatval($earnings_data->week ?? 0),
    'month' => floatval($earnings_data->month ?? 0),
    'pending' => floatval($earnings_data->pending ?? 0),
    'total' => floatval($earnings_data->total ?? 0)
);

// Stats
$stats = array(
    'sessions' => intval(isset($trainer->total_sessions) ? $trainer->total_sessions : 0),
    'rating' => floatval(isset($trainer->average_rating) ? $trainer->average_rating : 5.0),
    'reviews' => intval(isset($trainer->review_count) ? $trainer->review_count : 0),
    'rate' => intval(isset($trainer->hourly_rate) ? $trainer->hourly_rate : 60)
);

// v216.2: Reviews data for Reviews tab
$reviews_data = $wpdb->get_results($wpdb->prepare(
    "SELECT r.*, COALESCE(u.display_name, 'Parent') as parent_name
     FROM {$wpdb->prefix}ptp_reviews r
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
     WHERE r.trainer_id = %d AND r.is_published = 1
     ORDER BY r.created_at DESC LIMIT 50",
    $trainer_id
));
$rating_breakdown = array(5=>0, 4=>0, 3=>0, 2=>0, 1=>0);
foreach ($reviews_data as $rv) {
    $s = max(1, min(5, intval($rv->rating)));
    $rating_breakdown[$s]++;
}
$total_rated = array_sum($rating_breakdown);
$unreplied_count = 0;
foreach ($reviews_data as $rv) {
    if (empty($rv->trainer_reply) && empty($rv->trainer_response)) $unreplied_count++;
}

// v216.3: Mentorship data
$mentor_db_enabled = !empty($trainer->mentorship_enabled);

// v221: Pull package pricing from PTP_Mentorship::PACKAGES (single source of truth)
$mentor_pkgs = class_exists('PTP_Mentorship') && defined('PTP_Mentorship::PACKAGES') ? PTP_Mentorship::PACKAGES : array(
    'single'      => array('name'=>'Single Session','per_session_display'=>49, 'sessions'=>1, 'billing'=>'one-time'),
    'kickstart'   => array('name'=>'Kickstart',   'per_session_display'=>49, 'sessions'=>12),
    'development' => array('name'=>'Development',  'per_session_display'=>69, 'sessions'=>24),
    'elite'       => array('name'=>'Elite',        'per_session_display'=>89, 'sessions'=>36),
);

// v233 M6: Only load mentorship data when tab is active (via ?tab=mentorship or AJAX)
// Stats are lightweight (single aggregate query), always load
$mentor_stats = class_exists('PTP_Mentorship') ? PTP_Mentorship::get_trainer_stats($trainer_id) : array(
    'active_mentees' => 0, 'by_package' => array('single'=>0,'kickstart'=>0,'development'=>0,'elite'=>0),
    'pending_videos' => 0, 'total_reviewed' => 0, 'active_goals' => 0,
    'goals_completed' => 0, 'monthly_revenue' => 0, 'upcoming_sessions' => 0,
    'active_challenge' => null,
);

// v233 M6: Heavy queries deferred — only run when mentorship tab is requested
$mentor_tab_active = (isset($_GET['tab']) && $_GET['tab'] === 'mentorship');
$mentees = array();
$pending_videos = array();
$mentor_sessions = array();
$mentor_incoming = array();

if ($mentor_tab_active || wp_doing_ajax()) {
    if ($mentor_stats['active_mentees'] > 0) {
        $mentees = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*,
                    COALESCE(NULLIF(pl.first_name,''), NULLIF(p.player_name,''), 'Player') as display_player_name,
                    pl.age as display_player_age,
                    pl.position,
                    COALESCE(NULLIF(u.display_name,''), NULLIF(p.parent_name,''), 'Parent') as display_parent_name,
                    COALESCE(NULLIF(u.user_email,''), NULLIF(p.parent_email,''), '') as display_parent_email,
                    p.sessions_completed, p.sessions_total, p.package_type
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON p.player_id = pl.id
             LEFT JOIN {$wpdb->users} u ON p.parent_id = u.ID
             WHERE p.trainer_id = %d AND p.status = 'active'
             ORDER BY p.package_type DESC, p.created_at ASC",
            $trainer_id
        ));
    }
    if ($mentor_stats['pending_videos'] > 0) {
        $pending_videos = $wpdb->get_results($wpdb->prepare(
            "SELECT v.*, pl.first_name as player_name, mp.tier
             FROM {$wpdb->prefix}ptp_mentorship_videos v
             JOIN {$wpdb->prefix}ptp_mentorship_pairs mp ON v.pair_id = mp.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON v.player_id = pl.id
             WHERE v.trainer_id = %d AND v.status = 'pending'
             ORDER BY v.created_at ASC LIMIT 10",
            $trainer_id
        ));
    }
    if (class_exists('PTP_Mentorship')) {
        $mentor_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions
             WHERE trainer_id = %d AND status = 'scheduled' AND scheduled_at >= NOW()
             ORDER BY scheduled_at ASC LIMIT 5",
            $trainer_id
        ));
    }
    if ($mentor_db_enabled && class_exists('PTP_Mentorship')) {
        $mentor_incoming = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*,
                    COALESCE(NULLIF(pl.first_name,''), NULLIF(p.player_name,''), 'Player') as display_player_name,
                    pl.age as display_player_age,
                    COALESCE(NULLIF(u.display_name,''), NULLIF(p.parent_name,''), 'Parent') as display_parent_name,
                    COALESCE(NULLIF(u.user_email,''), NULLIF(p.parent_email,''), '') as display_parent_email,
                    p.player_goals as goals
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON p.player_id = pl.id
             LEFT JOIN {$wpdb->users} u ON p.parent_id = u.ID
             WHERE p.trainer_id = %d AND p.status IN ('interest','intro_scheduled','intro_done')
             ORDER BY FIELD(p.status,'interest','intro_scheduled','intro_done'), p.created_at ASC",
            $trainer_id
        ));
    }
}
$has_mentor_activity = ($mentor_stats['active_mentees'] > 0 || !empty($mentor_incoming));

// Profile completion
$photo_url = isset($trainer->photo_url) ? $trainer->photo_url : '';
$bio = isset($trainer->bio) ? $trainer->bio : '';
$headline = isset($trainer->headline) ? $trainer->headline : '';

// v134: Additional profile fields for dashboard editing
$coaching_why = isset($trainer->coaching_why) ? $trainer->coaching_why : '';
$training_philosophy = isset($trainer->training_philosophy) ? $trainer->training_philosophy : '';
$team = isset($trainer->team) ? $trainer->team : '';
$playing_level = isset($trainer->playing_level) ? $trainer->playing_level : '';
$specialties = isset($trainer->specialties) ? $trainer->specialties : '';
$experience_years = intval($trainer->experience_years ?? 0);
$years_coaching = intval($trainer->years_coaching ?? 0);
$travel_radius = intval($trainer->travel_radius ?? 15);
$city = isset($trainer->city) ? $trainer->city : '';
$state = isset($trainer->state) ? $trainer->state : '';

$profile_checks = array(
    'photo' => !empty($photo_url) && strpos($photo_url, 'ui-avatars') === false,
    'bio' => !empty($bio) && strlen($bio) > 50,
    'headline' => !empty($headline),
    'locations' => !empty($trainer->training_locations),
    'experience' => !empty($trainer->playing_level),
    'stripe' => $stripe_complete,
    'availability' => false
);

// Decode training locations for dashboard display
$training_locations = array();
if (!empty($trainer->training_locations)) {
    $decoded = json_decode($trainer->training_locations, true);
    if (is_array($decoded)) $training_locations = $decoded;
}
$google_maps_key = get_option('ptp_google_maps_key', '');

// Check availability table
$avail_table = $wpdb->prefix . 'ptp_availability';
$avail_table_exists = class_exists('PTP_Query_Cache') ? PTP_Query_Cache::table_exists($avail_table) : ($wpdb->get_var("SHOW TABLES LIKE '$avail_table'") === $avail_table);
if ($avail_table_exists) {
    $avail_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$avail_table} WHERE trainer_id=%d AND is_active=1", 
        $trainer_id
    ));
    $profile_checks['availability'] = intval($avail_count) > 0;
}

$profile_complete_count = 0;
foreach ($profile_checks as $check) {
    if ($check) $profile_complete_count++;
}
$profile_total = count($profile_checks);
$profile_percent = round(($profile_complete_count / $profile_total) * 100);

$incomplete_items = array();
if (!$profile_checks['photo']) $incomplete_items[] = array('key' => 'photo', 'label' => 'Add profile photo', 'icon' => 'camera');
if (!$profile_checks['bio']) $incomplete_items[] = array('key' => 'bio', 'label' => 'Write your bio', 'icon' => 'edit');
if (!$profile_checks['stripe']) $incomplete_items[] = array('key' => 'stripe', 'label' => 'Connect payments', 'icon' => 'credit-card');
if (!$profile_checks['availability']) $incomplete_items[] = array('key' => 'availability', 'label' => 'Set your hours', 'icon' => 'clock');
if (!$profile_checks['locations']) $incomplete_items[] = array('key' => 'locations', 'label' => 'Add training locations', 'icon' => 'map-pin');
if (!$profile_checks['experience']) $incomplete_items[] = array('key' => 'experience', 'label' => 'Set experience level', 'icon' => 'award');

// Get availability
$availability = array();
if ($avail_table_exists) {
    $raw_avail = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$avail_table} WHERE trainer_id=%d", 
        $trainer_id
    ));
    if ($raw_avail) {
        $day_names = array(
            0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
            4 => 'thursday', 5 => 'friday', 6 => 'saturday'
        );
        foreach ($raw_avail as $slot) {
            $d = intval($slot->day_of_week);
            if (isset($day_names[$d])) {
                $availability[$day_names[$d]] = array(
                    'enabled' => !empty($slot->is_active),
                    'start' => substr($slot->start_time, 0, 5),
                    'end' => substr($slot->end_time, 0, 5)
                );
            }
        }
    }
}

// Messages
$unread_count = 0;
$conversations = array();
if (class_exists('PTP_Messaging_V71')) {
    $conversations = PTP_Messaging_V71::get_conversations_for_user($user_id);
    if ($conversations && is_array($conversations)) {
        foreach ($conversations as $c) {
            if (!empty($c->unread)) $unread_count++;
        }
    }
}

// Completed sessions
$completed_sessions = $wpdb->get_results($wpdb->prepare("
    SELECT b.*, pl.name as player_name FROM {$wpdb->prefix}ptp_bookings b 
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id=pl.id 
    WHERE b.trainer_id=%d AND b.status='completed' ORDER BY b.session_date DESC LIMIT 20
", $trainer_id));

if (!$completed_sessions) {
    $completed_sessions = array();
}

// Get recaps for completed sessions
$completed_ids = array_map(function($s) { return $s->id; }, $completed_sessions);
$session_recaps = array();
if (!empty($completed_ids) && class_exists('PTP_Training_Plans')) {
    $session_recaps = PTP_Training_Plans::get_recaps_for_bookings($completed_ids);
}

// v235.8: Count recent sessions without recaps (for nudge banner)
$sessions_needing_recap = 0;
foreach (array_slice($completed_sessions, 0, 5) as $_cs) {
    if (!isset($session_recaps[$_cs->id])) $sessions_needing_recap++;
}

// Blocked dates
$blocked_dates = array();
$exceptions_table = $wpdb->prefix . 'ptp_availability_exceptions';
$exceptions_table_exists = class_exists('PTP_Query_Cache') ? PTP_Query_Cache::table_exists($exceptions_table) : ($wpdb->get_var("SHOW TABLES LIKE '$exceptions_table'") === $exceptions_table);
if ($exceptions_table_exists) {
    $blocked_dates = $wpdb->get_results($wpdb->prepare(
        "SELECT id, exception_date as date, reason FROM $exceptions_table 
         WHERE trainer_id=%d AND is_available=0 AND exception_date>=CURDATE() 
         ORDER BY exception_date ASC LIMIT 20", 
        $trainer_id
    ));
    if (!$blocked_dates) {
        $blocked_dates = array();
    }
}

// Avatar
$avatar_url = !empty($photo_url) ? $photo_url : 'https://ui-avatars.com/api/?name=' . urlencode($display_name) . '&size=112&background=FCB900&color=0A0A0A&bold=true';

// Time-based greeting
$hour = intval(date('G'));
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

// Next session for hero
$next_session = !empty($upcoming_raw) ? $upcoming_raw[0] : null;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0A0A0A">
<title>Dashboard | PTP</title>
<meta name="robots" content="noindex, nofollow">
<?php wp_head(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo esc_url(PTP_PLUGIN_URL . 'assets/css/trainer-dashboard-v200.css'); ?>" />
</head>
<body class="ptp-custom-template ptp-trainer-dashboard">
<a href="#appContent" class="ptp-skip-link">Skip to content</a>
<style>
.ptp-custom-template .elementor-location-header,
.ptp-custom-template header.elementor-element,
.ptp-custom-template #masthead,
.ptp-custom-template .site-header,
.ptp-custom-template .theme-header,
.ptp-custom-template [data-elementor-type="header"],
.ptp-custom-template header:not(.dash-header) { display: none !important; }
</style>
<?php 
$dashboard_type = 'trainer';
include(dirname(__FILE__) . '/components/dashboard-nav.php');
?>

<div class="dash" id="app">
    <!-- ═══ HEADER ═══ -->
    <header class="dash-header">
        <div class="dash-top">
            <div>
                <p class="dash-greeting"><?php echo esc_html($greeting); ?></p>
                <h1 class="dash-name"><span><?php echo esc_html(strtoupper($first_name)); ?></span></h1>
            </div>
            <?php if ($has_mentor_activity): ?>
            <!-- v235.8: More menu accessible via header when mentorship is in bottom nav -->
            <button onclick="openMoreSheet()" style="width:36px;height:36px;border-radius:50%;background:var(--surface);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;margin-right:8px" aria-label="More options">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
            </button>
            <?php endif; ?>
            <img src="<?php echo esc_url($avatar_url); ?>" alt="" class="dash-avatar" onclick="switchTab('profile')">
        </div>
        <div class="dash-stats">
            <div class="dash-stat">
                <div class="dash-stat-val gold">$<?php echo esc_html($stats['rate']); ?></div>
                <div class="dash-stat-lbl">Rate</div>
            </div>
            <div class="dash-stat">
                <div class="dash-stat-val green">$<?php echo esc_html(number_format($earnings['week'])); ?></div>
                <div class="dash-stat-lbl">This Week</div>
            </div>
            <div class="dash-stat">
                <div class="dash-stat-val"><?php echo esc_html($total_upcoming); ?></div>
                <div class="dash-stat-lbl">Upcoming</div>
            </div>
            <div class="dash-stat">
                <div class="dash-stat-val"><?php echo esc_html($stats['sessions']); ?></div>
                <div class="dash-stat-lbl">Sessions</div>
            </div>
            <div class="dash-stat" onclick="switchTab('reviews')" style="cursor:pointer">
                <div class="dash-stat-val"><?php echo esc_html(number_format($stats['rating'], 1)); ?>★</div>
                <div class="dash-stat-lbl"><?php echo esc_html($stats['reviews']); ?> Reviews</div>
            </div>
        </div>
    </header>

    <!-- ═══ BODY ═══ -->
    <main class="dash-body" id="appContent">
        <div class="ptr-indicator" id="ptrIndicator">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        </div>
        <div class="dash-content">

            <!-- ════════ HOME TAB ════════ -->
            <div class="tab-panel active" id="tab-home" data-tab="home" role="tabpanel" aria-label="Home">

                <?php if (!empty($needs_confirmation)): ?>
                <div class="confirm-banner" id="confirmBanner">
                    <div class="confirm-head">
                        <div class="confirm-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div>
                            <div class="confirm-title"><?php echo count($needs_confirmation); ?> Session<?php echo count($needs_confirmation) > 1 ? 's' : ''; ?> Need Confirmation</div>
                            <div class="confirm-sub">Confirm to start payment release</div>
                        </div>
                    </div>
                    <?php foreach ($needs_confirmation as $nc): ?>
                    <div class="confirm-item" data-id="<?php echo intval($nc->id); ?>">
                        <div class="confirm-item-body">
                            <div class="confirm-item-name"><?php echo esc_html(!empty($nc->player_name) ? $nc->player_name : 'Player'); ?></div>
                            <div class="confirm-item-meta"><?php echo esc_html(date('D, M j', strtotime($nc->session_date))); ?><?php if (!empty($nc->parent_name)): ?> · <?php echo esc_html($nc->parent_name); ?><?php endif; ?></div>
                        </div>
                        <div style="display:flex;gap:6px;flex-shrink:0">
                            <button class="btn btn-sm btn-green" onclick="confirmSession(<?php echo intval($nc->id); ?>,this)">Confirm</button>
                            <button class="btn btn-sm btn-danger" onclick="markNoShow(<?php echo intval($nc->id); ?>)">No-Show</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($sessions_needing_recap > 0): ?>
                <!-- v235.8: Session recap nudge -->
                <div class="card" style="border:1px solid rgba(252,185,0,0.4);margin-bottom:12px;cursor:pointer" onclick="switchTab('earnings')">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div style="width:36px;height:36px;border-radius:50%;background:rgba(252,185,0,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        </div>
                        <div style="flex:1">
                            <div style="font-weight:700;font-size:13px;color:var(--text)"><?php echo $sessions_needing_recap; ?> Session<?php echo $sessions_needing_recap > 1 ? 's' : ''; ?> Need a Training Plan</div>
                            <div style="font-size:11px;color:var(--text-muted)">Parents love getting a recap. Takes 30 seconds with AI.</div>
                        </div>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($profile_percent < 100): ?>
                <div class="completion-card">
                    <div class="completion-top">
                        <div class="completion-label">Complete Your Profile</div>
                        <div class="completion-pct"><?php echo esc_html($profile_percent); ?>%</div>
                    </div>
                    <div class="completion-bar"><div class="completion-fill" style="width:<?php echo intval($profile_percent); ?>%"></div></div>
                    <?php if (!empty($incomplete_items)): ?>
                    <div class="completion-items">
                        <?php 
                        $icons = array(
                            'camera' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>',
                            'edit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
                            'credit-card' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
                            'clock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                            'map-pin' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
                            'award' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>'
                        );
                        $show_items = array_slice($incomplete_items, 0, 3);
                        foreach ($show_items as $item): ?>
                        <div class="completion-item" onclick="handleProfileAction('<?php echo esc_attr($item['key']); ?>')">
                            <?php echo $icons[$item['icon']] ?? ''; ?>
                            <?php echo esc_html($item['label']); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- v226: Dashboard Tour trigger -->
                <div id="tourBanner" class="card" style="margin-bottom:12px;border:1px solid var(--gold);cursor:pointer;display:none" onclick="startDashTour()">
                    <div style="display:flex;align-items:center;gap:12px;padding:2px 0">
                        <div style="width:36px;height:36px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--black)" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </div>
                        <div style="flex:1">
                            <div style="font-weight:700;font-size:14px;color:var(--text)">New here? Take a quick tour</div>
                            <div style="font-size:12px;color:var(--text-muted)">Learn how each tab works in 60 seconds</div>
                        </div>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </div>
                </div>
                <script>try{if(!localStorage.getItem('ptp_tour_done'))document.getElementById('tourBanner').style.display=''}catch(e){document.getElementById('tourBanner').style.display=''}</script>

                <?php if ($earnings['pending'] >= $min_payout && $stripe_complete): ?>
                <div class="payout-banner" onclick="openPayoutSheet()">
                    <div>
                        <div class="payout-lbl">Available for Payout</div>
                        <div class="payout-amt">$<?php echo esc_html(number_format($earnings['pending'], 2)); ?></div>
                    </div>
                    <button type="button" class="btn btn-sm btn-green" onclick="event.stopPropagation();openPayoutSheet()">Cash Out</button>
                </div>
                <?php elseif ($earnings['pending'] >= $min_payout && !$stripe_complete): ?>
                <div class="payout-banner" style="border-color:var(--gold);background:var(--amber-10)" onclick="connectStripe()">
                    <div>
                        <div class="payout-lbl">$<?php echo esc_html(number_format($earnings['pending'], 2)); ?> Ready to Cash Out</div>
                        <div style="font-size:13px;color:var(--text-secondary);margin-top:2px">Connect Stripe to receive your earnings</div>
                    </div>
                    <button type="button" class="btn btn-sm" onclick="event.stopPropagation();connectStripe()">Connect</button>
                </div>
                <?php endif; ?>

                <?php if ($latest_booking && $latest_booking_is_new): ?>
                <!-- v230: Latest Booking Card -->
                <div class="card" style="border:1px solid var(--gold);margin-bottom:12px">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="width:32px;height:32px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--black)" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:14px;color:var(--text)">New Booking</div>
                                <div style="font-size:11px;color:var(--text-muted)"><?php echo esc_html(human_time_diff(strtotime($latest_booking->created_at), current_time('timestamp'))); ?> ago</div>
                            </div>
                        </div>
                        <?php if ($latest_booking->payment_status === 'free_session'): ?>
                        <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:3px 8px;border-radius:4px;background:rgba(34,197,94,.1);color:#16A34A">Free Session</span>
                        <?php else: ?>
                        <span style="font-size:16px;font-weight:700;color:var(--gold)">$<?php echo esc_html(number_format(floatval($latest_booking->trainer_payout), 0)); ?></span>
                        <?php endif; ?>
                    </div>

                    <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:10px">
                        <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px"><?php echo esc_html(!empty($latest_booking->player_name) ? $latest_booking->player_name : 'Player'); ?><?php if ($latest_booking->player_age): ?> <span style="font-weight:400;font-size:12px;color:var(--text-muted)">age <?php echo intval($latest_booking->player_age); ?></span><?php endif; ?></div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                            <div style="font-size:12px;color:var(--text-muted)">
                                <span style="font-weight:600;color:var(--text)">Date</span><br>
                                <?php echo (!empty($latest_booking->session_date) && $latest_booking->session_date !== '0000-00-00') ? esc_html(date('D, M j', strtotime($latest_booking->session_date))) : 'TBD'; ?>
                            </div>
                            <div style="font-size:12px;color:var(--text-muted)">
                                <span style="font-weight:600;color:var(--text)">Time</span><br>
                                <?php echo (!empty($latest_booking->start_time) && $latest_booking->start_time !== '00:00:00') ? esc_html(date('g:i A', strtotime($latest_booking->start_time))) : 'TBD'; ?>
                            </div>
                            <div style="font-size:12px;color:var(--text-muted)">
                                <span style="font-weight:600;color:var(--text)">Location</span><br>
                                <?php echo esc_html($latest_booking_location); ?>
                            </div>
                            <div style="font-size:12px;color:var(--text-muted)">
                                <span style="font-weight:600;color:var(--text)">Parent</span><br>
                                <?php echo esc_html($latest_booking->parent_name ?: '-'); ?>
                            </div>
                        </div>
                        <?php if (!empty($latest_booking->player_skill)): ?>
                        <div style="margin-top:8px;font-size:11px;color:var(--text-muted)">
                            Skill: <span style="color:var(--text);font-weight:600"><?php echo esc_html(ucfirst($latest_booking->player_skill)); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;gap:6px">
                        <?php if (!empty($latest_booking->parent_phone)): ?>
                        <a href="sms:<?php echo esc_attr($latest_booking->parent_phone); ?>" style="flex:1;display:block;text-align:center;padding:10px;font-size:12px;font-weight:700;border-radius:6px;text-decoration:none;background:var(--gold);color:var(--black);text-transform:uppercase;letter-spacing:.5px;font-family:'Oswald',sans-serif">Text Parent</a>
                        <?php endif; ?>
                        <?php if (!empty($latest_booking->parent_phone)): ?>
                        <a href="tel:<?php echo esc_attr($latest_booking->parent_phone); ?>" style="flex:1;display:block;text-align:center;padding:10px;font-size:12px;font-weight:700;border-radius:6px;text-decoration:none;background:transparent;color:var(--text);border:1px solid var(--border);text-transform:uppercase;letter-spacing:.5px;font-family:'Oswald',sans-serif">Call</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">Upcoming Sessions</h3>
                        <a href="<?php echo esc_url($profile_url); ?>" target="_blank" class="card-action">View Profile →</a>
                    </div>
                    <?php if ($total_upcoming > 0): ?>
                        <?php 
                        $group_labels = array('today' => 'Today', 'tomorrow' => 'Tomorrow', 'this_week' => 'This Week', 'later' => 'Later');
                        foreach ($group_labels as $gk => $gl): 
                            if (!empty($sessions_grouped[$gk])): 
                        ?>
                        <div class="session-group">
                            <div class="session-group-head">
                                <span class="session-group-title"><?php echo esc_html($gl); ?></span>
                                <span class="session-group-count"><?php echo count($sessions_grouped[$gk]); ?></span>
                            </div>
                            <?php foreach ($sessions_grouped[$gk] as $session): 
                                $is_m = !empty($session->is_mentorship);
                            ?>
                            <div class="scard<?php echo $is_m ? ' scard-mentorship' : ''; ?>">
                                <div class="scard-date <?php echo $gk === 'today' ? 'today' : ''; ?>">
                                    <div class="scard-dow"><?php echo esc_html(date('D', strtotime($session->session_date))); ?></div>
                                    <div class="scard-day"><?php echo esc_html(date('j', strtotime($session->session_date))); ?></div>
                                </div>
                                <div class="scard-info">
                                    <div class="scard-name">
                                        <?php echo esc_html(!empty($session->player_name) ? $session->player_name : 'Player'); ?>
                                        <?php if ($is_m): ?>
                                        <span style="display:inline-flex;align-items:center;gap:2px;font-size:9px;font-weight:700;background:#FCB900;color:#0A0A0A;padding:1px 6px;border-radius:4px;text-transform:uppercase;margin-left:4px;vertical-align:middle">
                                            <svg viewBox="0 0 24 24" width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15.05 5A5 5 0 0119 8.95M15.05 1A9 9 0 0123 8.94M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 3h3a2 2 0 012 1.72"/></svg>
                                            <?php echo esc_html(ucfirst($session->package_type ?? 'mentorship')); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="scard-meta">
                                        <?php echo !empty($session->start_time) ? esc_html(date('g:i A', strtotime($session->start_time))) : ''; ?>
                                        <?php if ($is_m && !empty($session->duration)): ?> · <?php echo intval($session->duration); ?>min<?php endif; ?>
                                        <?php if (!empty($session->location)): ?> · <?php echo esc_html($session->location); ?><?php endif; ?>
                                        <?php if (!empty($session->parent_name)): ?> · <?php echo esc_html($session->parent_name); ?><?php endif; ?>
                                    </div>
                                </div>
                                <div class="scard-right">
                                    <div class="scard-amt">$<?php echo esc_html(number_format(floatval($session->trainer_payout))); ?></div>
                                    <div style="display:flex;gap:4px;margin-top:4px;justify-content:flex-end">
                                        <?php if ($is_m): ?>
                                        <div class="scard-status" style="background:rgba(252,185,0,0.1);color:#B8860B">Mentorship</div>
                                        <?php if (!empty($session->host_url)): ?>
                                        <a href="<?php echo esc_url($session->host_url); ?>" target="_blank" class="scard-cancel-btn" style="color:#FCB900;border-color:#FCB900;text-decoration:none">Join</a>
                                        <?php endif; ?>
                                        <?php else: ?>
                                        <div class="scard-status <?php echo esc_attr($session->status); ?>"><?php echo esc_html(ucfirst($session->status)); ?></div>
                                        <button onclick="event.stopPropagation();rescheduleSession(<?php echo intval($session->id); ?>)" class="scard-cancel-btn" style="color:#7c3aed;border-color:#c4b5fd">Reschedule</button>
                                        <button onclick="event.stopPropagation();cancelSession(<?php echo intval($session->id); ?>)" class="scard-cancel-btn">Cancel</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; endforeach; ?>
                    <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <h3 class="empty-title">No Upcoming Sessions</h3>
                        <p class="empty-text">Share your profile link below to get booked!</p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="share-card">
                    <h4 class="share-title">Share Your Profile</h4>
                    <div class="share-row">
                        <input type="text" value="<?php echo esc_attr($profile_url); ?>" class="share-input" id="shareUrl" readonly>
                        <button class="share-btn" onclick="copyProfileLink()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                            Copy
                        </button>
                    </div>
                </div>

                <?php if ($has_mentor_activity || $mentor_stats['active_mentees'] > 0): ?>
                <!-- v235: Mentorship Quick View on Home -->
                <div class="card" style="border:1px solid rgba(139,92,246,0.25);cursor:pointer" onclick="switchTab('mentorship')">
                    <div style="display:flex;align-items:center;justify-content:space-between">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div style="width:36px;height:36px;border-radius:50%;background:#8B5CF620;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8B5CF6" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:13px;color:var(--text)">Mentorship</div>
                                <div style="font-size:11px;color:var(--text-muted)">
                                    <?php if ($mentor_stats['active_mentees'] > 0): ?>
                                        <?php echo $mentor_stats['active_mentees']; ?> active mentee<?php echo $mentor_stats['active_mentees'] > 1 ? 's' : ''; ?>
                                        <?php if ($mentor_stats['pending_videos'] > 0): ?> &middot; <span style="color:#EF4444"><?php echo $mentor_stats['pending_videos']; ?> videos pending</span><?php endif; ?>
                                    <?php elseif (!empty($mentor_incoming)): ?>
                                        <?php echo count($mentor_incoming); ?> in pipeline
                                    <?php else: ?>
                                        View your mentorship dashboard
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ════════ SCHEDULE TAB ════════ -->
            <div class="tab-panel" id="tab-schedule" data-tab="schedule" role="tabpanel" aria-label="Schedule">
                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">Weekly Availability</h3>
                        <button class="btn btn-sm" id="saveAvailBtn" onclick="saveAvailability()">Save</button>
                    </div>
                    <!-- v235.8: Quick presets -->
                    <div style="display:flex;gap:6px;padding:0 16px 12px;overflow-x:auto;-webkit-overflow-scrolling:touch">
                        <button type="button" onclick="dashAvailPreset('weekday_eve')" style="flex-shrink:0;padding:7px 12px;background:var(--surface);border:1px solid var(--border);border-radius:6px;font-family:Oswald,sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;cursor:pointer;color:var(--text)">Weekday Eves</button>
                        <button type="button" onclick="dashAvailPreset('weekends')" style="flex-shrink:0;padding:7px 12px;background:var(--surface);border:1px solid var(--border);border-radius:6px;font-family:Oswald,sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;cursor:pointer;color:var(--text)">Weekends</button>
                        <button type="button" onclick="dashAvailPreset('all')" style="flex-shrink:0;padding:7px 12px;background:var(--surface);border:1px solid var(--border);border-radius:6px;font-family:Oswald,sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;cursor:pointer;color:var(--text)">Every Day</button>
                    </div>
                    <?php 
                    $day_list = array(
                        'monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed',
                        'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun'
                    );
                    foreach ($day_list as $day_key => $day_label):
                        $day_avail = isset($availability[$day_key]) ? $availability[$day_key] : array('enabled' => false, 'start' => '09:00', 'end' => '17:00');
                    ?>
                    <div class="avail-row">
                        <div class="avail-day-name"><?php echo esc_html($day_label); ?></div>
                        <div class="avail-toggle <?php echo $day_avail['enabled'] ? 'on' : ''; ?>" data-day="<?php echo esc_attr($day_key); ?>" onclick="toggleDay(this)"></div>
                        <div class="avail-times">
                            <input type="time" class="avail-time" id="start_<?php echo esc_attr($day_key); ?>" value="<?php echo esc_attr($day_avail['start']); ?>" <?php echo !$day_avail['enabled'] ? 'disabled' : ''; ?>>
                            <span class="avail-sep">to</span>
                            <input type="time" class="avail-time" id="end_<?php echo esc_attr($day_key); ?>" value="<?php echo esc_attr($day_avail['end']); ?>" <?php echo !$day_avail['enabled'] ? 'disabled' : ''; ?>>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">Blocked Dates</h3>
                        <button class="btn btn-sm btn-outline" onclick="openBlockDateSheet()">+ Block Date</button>
                    </div>
                    <?php if (empty($blocked_dates)): ?>
                    <p class="text-muted text-center text-sm" style="padding:16px 0">No blocked dates — all available days are open</p>
                    <?php else: ?>
                    <?php foreach ($blocked_dates as $bd): ?>
                    <div class="scard static">
                        <div class="scard-date">
                            <div class="scard-dow"><?php echo esc_html(date('D', strtotime($bd->date))); ?></div>
                            <div class="scard-day"><?php echo esc_html(date('j', strtotime($bd->date))); ?></div>
                        </div>
                        <div class="scard-info">
                            <div class="scard-name"><?php echo esc_html(date('F j, Y', strtotime($bd->date))); ?></div>
                            <div class="scard-meta"><?php echo esc_html(!empty($bd->reason) ? $bd->reason : 'Blocked'); ?></div>
                        </div>
                        <button class="btn btn-sm btn-danger" onclick="removeBlockedDate('<?php echo esc_attr($bd->date); ?>')">Remove</button>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- ── Google Calendar Integration ── -->
                <div class="card" id="gcalCard">
                    <div class="card-head">
                        <h3 class="card-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Google Calendar
                        </h3>
                    </div>
                    <div id="gcalContent" style="padding:12px 16px">
                        <div style="text-align:center;padding:12px">
                            <div style="width:20px;height:20px;border:2px solid var(--border);border-top-color:var(--gold);border-radius:50%;animation:spin 0.6s linear infinite;display:inline-block"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-panel" id="tab-earnings" data-tab="earnings" role="tabpanel" aria-label="Earnings">
                <div class="card">
                    <div class="earn-hero">
                        <div class="earn-label">Available Balance</div>
                        <div class="earn-amount">$<?php echo esc_html(number_format($earnings['pending'], 2)); ?></div>
                        <?php if ($earnings['pending'] >= $min_payout && $stripe_complete): ?>
                        <button type="button" class="btn btn-green btn-full mt-4" onclick="openPayoutSheet()">Request Payout</button>
                        <?php elseif ($earnings['pending'] >= $min_payout && !$stripe_complete): ?>
                        <p class="text-muted text-sm" style="margin-bottom:12px">You have funds ready — connect Stripe to cash out.</p>
                        <button class="btn btn-green btn-full mt-3" onclick="connectStripe()">Connect Stripe to Cash Out</button>
                        <?php elseif (!$stripe_complete): ?>
                        <p class="text-muted text-sm" style="margin-bottom:12px">Connect Stripe to start getting paid. Takes under 2 minutes.</p>
                        <button class="btn btn-full mt-3" onclick="connectStripe()">Connect Stripe to Get Paid</button>
                        <?php elseif ($earnings['pending'] > 0): ?>
                        <p class="text-muted text-sm" style="margin-top:8px">Minimum payout is $<?php echo esc_html(number_format($min_payout, 2)); ?>. You're $<?php echo esc_html(number_format($min_payout - $earnings['pending'], 2)); ?> away.</p>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:center;padding:6px 0 2px">
                        <span style="font-size:11px;color:var(--text-muted)">You earn <strong style="color:var(--green)">50–85%</strong> of every session — rate grows as families rebook</span>
                    </div>
                    <div class="earn-grid">
                        <div class="earn-cell"><div class="earn-cell-val">$<?php echo esc_html(number_format($earnings['today'])); ?></div><div class="earn-cell-lbl">Today</div></div>
                        <div class="earn-cell"><div class="earn-cell-val">$<?php echo esc_html(number_format($earnings['week'])); ?></div><div class="earn-cell-lbl">This Week</div></div>
                        <div class="earn-cell"><div class="earn-cell-val">$<?php echo esc_html(number_format($earnings['month'])); ?></div><div class="earn-cell-lbl">This Month</div></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><h3 class="card-title">Recent Sessions</h3></div>
                    <?php if (!empty($completed_sessions)): ?>
                        <?php foreach (array_slice($completed_sessions, 0, 10) as $cs): 
                            $has_recap = isset($session_recaps[$cs->id]);
                        ?>
                        <div class="scard static">
                            <div class="scard-date">
                                <div class="scard-dow"><?php echo esc_html(date('D', strtotime($cs->session_date))); ?></div>
                                <div class="scard-day"><?php echo esc_html(date('j', strtotime($cs->session_date))); ?></div>
                            </div>
                            <div class="scard-info">
                                <div class="scard-name"><?php echo esc_html(!empty($cs->player_name) ? $cs->player_name : 'Player'); ?></div>
                                <div class="scard-meta"><?php echo esc_html(date('M j, Y', strtotime($cs->session_date))); ?></div>
                            </div>
                            <div class="scard-right" style="display:flex;align-items:center;gap:8px">
                                <?php if ($has_recap): ?>
                                    <span class="recap-sent-badge">Plan Sent</span>
                                <?php else: ?>
                                    <button class="btn-recap" onclick="openRecapSheet(<?php echo intval($cs->id); ?>, '<?php echo esc_js(!empty($cs->player_name) ? $cs->player_name : 'Player'); ?>', '<?php echo esc_js(date('M j', strtotime($cs->session_date))); ?>')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                        Send Plan
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
                        <h3 class="empty-title">No Sessions Yet</h3>
                        <p class="empty-text">Completed sessions and earnings will appear here.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ════════ MESSAGES TAB ════════ -->
            <div class="tab-panel" id="tab-messages" data-tab="messages" role="tabpanel" aria-label="Messages">
                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">Messages</h3>
                        <?php if ($unread_count > 0): ?><span class="text-gold" style="font-size:13px;font-weight:700"><?php echo esc_html($unread_count); ?> unread</span><?php endif; ?>
                    </div>
                    <?php if (!empty($conversations)): ?>
                        <?php foreach ($conversations as $conv): 
                            $conv_name = isset($conv->other_user_name) ? $conv->other_user_name : 'Parent';
                            $conv_photo = isset($conv->other_user_photo) ? $conv->other_user_photo : '';
                            if (empty($conv_photo)) {
                                $conv_photo = 'https://ui-avatars.com/api/?name=' . urlencode($conv_name) . '&size=96&background=E5E5E5&color=6B7280';
                            }
                            $conv_preview = isset($conv->last_message) ? $conv->last_message : '';
                            $conv_time = isset($conv->last_message_time) ? $conv->last_message_time : '';
                        ?>
                        <a href="<?php echo esc_url(home_url('/messages/?conversation=' . intval($conv->id))); ?>" class="msg-item <?php echo !empty($conv->unread) ? 'unread' : ''; ?>" style="text-decoration:none;color:inherit">
                            <img src="<?php echo esc_url($conv_photo); ?>" alt="" class="msg-avatar">
                            <div class="msg-body">
                                <div class="msg-name"><?php echo esc_html($conv_name); ?></div>
                                <div class="msg-preview"><?php echo esc_html(wp_trim_words($conv_preview, 8, '...')); ?></div>
                            </div>
                            <?php if (!empty($conv_time)): ?><div class="msg-time"><?php echo esc_html(human_time_diff(strtotime($conv_time))); ?></div><?php endif; ?>
                            <?php if (!empty($conv->unread)): ?><div class="msg-dot"></div><?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
                        <h3 class="empty-title">No Messages</h3>
                        <p class="empty-text">Messages from parents will appear here.</p>
                        <button class="btn" onclick="navigator.clipboard.writeText(window.location.origin + '/trainer/<?php echo esc_attr($trainer->slug); ?>');showToast('Profile link copied!','success');triggerHaptic('success');" style="margin-top:12px">Share Your Profile</button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ════════ INSIGHTS TAB ════════ -->
            <div class="tab-panel" id="tab-insights" data-tab="insights" role="tabpanel" aria-label="Insights">
                <div id="insightsContent">
                    <div class="text-center text-muted" style="padding:60px 20px">
                        <div class="empty-icon" style="margin:0 auto 16px">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 12V7H5a2 2 0 010-4h14v4"/><path d="M3 5v14a2 2 0 002 2h16v-5"/><path d="M18 12a2 2 0 000 4h4v-4h-4z"/></svg>
                        </div>
                        <p class="text-sm">Tap to load your performance insights</p>
                        <button class="btn btn-sm mt-3" onclick="loadInsights(30)">Load Insights</button>
                    </div>
                </div>
            </div>

            <!-- ════════ REVIEWS TAB ════════ -->
            <div class="tab-panel" id="tab-reviews" data-tab="reviews" role="tabpanel" aria-label="Reviews">
                <?php if (!empty($reviews_data)): ?>
                <div class="card">
                    <div class="rev-summary">
                        <div class="rev-avg">
                            <div class="rev-avg-num"><?php echo number_format($stats['rating'], 1); ?></div>
                            <div class="rev-avg-stars"><?php echo str_repeat('★', round($stats['rating'])); ?><?php echo str_repeat('☆', 5 - round($stats['rating'])); ?></div>
                            <div class="rev-avg-ct"><?php echo intval($total_rated); ?> review<?php echo $total_rated !== 1 ? 's' : ''; ?></div>
                        </div>
                        <div class="rev-bars">
                            <?php for ($s = 5; $s >= 1; $s--):
                                $bar_ct = $rating_breakdown[$s];
                                $bar_pct = $total_rated > 0 ? round(($bar_ct / $total_rated) * 100) : 0;
                            ?>
                            <div class="rev-bar-row">
                                <div class="rev-bar-label"><?php echo $s; ?></div>
                                <div class="rev-bar-track"><div class="rev-bar-fill" style="width:<?php echo $bar_pct; ?>%"></div></div>
                                <div class="rev-bar-ct"><?php echo $bar_ct; ?></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">All Reviews</h3>
                        <?php if ($unreplied_count > 0): ?><span class="text-gold" style="font-size:13px;font-weight:700"><?php echo $unreplied_count; ?> unreplied</span><?php endif; ?>
                    </div>

                    <div class="rev-filter-row">
                        <button class="rev-filter active" data-filter="all" onclick="filterReviews('all')">All</button>
                        <button class="rev-filter" data-filter="unreplied" onclick="filterReviews('unreplied')">Needs Reply</button>
                        <button class="rev-filter" data-filter="5" onclick="filterReviews('5')">5★</button>
                        <button class="rev-filter" data-filter="4" onclick="filterReviews('4')">4★</button>
                        <button class="rev-filter" data-filter="low" onclick="filterReviews('low')">1-3★</button>
                    </div>
                    <div id="rev-filter-empty" style="display:none"></div>

                    <?php foreach ($reviews_data as $ri => $rv):
                        $rv_diff = time() - strtotime($rv->created_at);
                        if ($rv_diff < 3600) $rv_ago = 'Just now';
                        elseif ($rv_diff < 86400) $rv_ago = floor($rv_diff/3600) . 'h ago';
                        elseif ($rv_diff < 604800) $rv_ago = floor($rv_diff/86400) . 'd ago';
                        elseif ($rv_diff < 2592000) $rv_ago = floor($rv_diff/604800) . 'w ago';
                        elseif ($rv_diff < 31536000) $rv_ago = floor($rv_diff/2592000) . 'mo ago';
                        else $rv_ago = floor($rv_diff/31536000) . 'y ago';

                        $rv_words = explode(' ', $rv->parent_name);
                        $rv_initials = strtoupper(substr($rv_words[0],0,1) . (isset($rv_words[1]) ? substr($rv_words[1],0,1) : ''));
                        $rv_rating = max(1, min(5, intval($rv->rating)));
                        $has_reply = !empty($rv->trainer_reply) || !empty($rv->trainer_response);
                        $reply_text = !empty($rv->trainer_reply) ? $rv->trainer_reply : (!empty($rv->trainer_response) ? $rv->trainer_response : '');
                        $is_new = $rv_diff < 604800 && !$has_reply;
                    ?>
                    <div class="rev-card <?php echo $is_new ? 'rev-new' : ''; ?>" data-rating="<?php echo $rv_rating; ?>" data-replied="<?php echo $has_reply ? '1' : '0'; ?>" id="rev-<?php echo intval($rv->id); ?>">
                        <div class="rev-hd">
                            <div class="rev-avatar"><?php echo $rv_initials; ?></div>
                            <div class="rev-meta">
                                <div class="rev-name">
                                    <?php echo esc_html($rv->parent_name); ?>
                                    <?php if (!empty($rv->is_verified)): ?>
                                    <span class="rev-verified"><svg fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Verified</span>
                                    <?php endif; ?>
                                </div>
                                <div class="rev-date">
                                    <span class="rev-stars"><?php echo str_repeat('★', $rv_rating); ?><?php echo str_repeat('☆', 5 - $rv_rating); ?></span>
                                    · <?php echo $rv_ago; ?>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($rv->review_text)): ?><div class="rev-txt">"<?php echo esc_html($rv->review_text); ?>"</div><?php endif; ?>
                        <?php if ($has_reply): ?>
                        <div class="rev-reply">
                            <div class="rev-reply-hd">Your Response</div>
                            <div class="rev-reply-txt"><?php echo esc_html($reply_text); ?></div>
                        </div>
                        <?php else: ?>
                        <button class="rev-reply-btn" onclick="openReplySheet(<?php echo intval($rv->id); ?>, '<?php echo esc_js($rv->parent_name); ?>', <?php echo $rv_rating; ?>)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 00-4-4H4"/></svg>
                            Reply
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php else: ?>
                <div class="card">
                    <div class="empty">
                        <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
                        <h3 class="empty-title">No Reviews Yet</h3>
                        <p class="empty-text">Reviews from parents will appear here after completed sessions.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ════════ MENTORSHIP TAB ════════ -->
            <div class="tab-panel" id="tab-mentorship" data-tab="mentorship" role="tabpanel" aria-label="Mentorship">

                <?php if ($has_mentor_activity): ?>

                <!-- Pipeline Summary -->
                <div class="mt-stats">
                    <div class="mt-stat">
                        <div class="mt-stat-val" style="color:#8B5CF6"><?php echo count($mentor_incoming); ?></div>
                        <div class="mt-stat-label">Pipeline</div>
                    </div>
                    <div class="mt-stat">
                        <div class="mt-stat-val gold"><?php echo $mentor_stats['active_mentees']; ?></div>
                        <div class="mt-stat-label">Active</div>
                    </div>
                    <div class="mt-stat">
                        <div class="mt-stat-val"><?php echo $mentor_stats['upcoming_sessions']; ?></div>
                        <div class="mt-stat-label">Upcoming</div>
                    </div>
                    <div class="mt-stat">
                        <div class="mt-stat-val <?php echo $mentor_stats['pending_videos'] > 0 ? 'red' : ''; ?>"><?php echo $mentor_stats['pending_videos']; ?></div>
                        <div class="mt-stat-label">Videos</div>
                    </div>
                    <div class="mt-stat" style="border:1px solid var(--green)">
                        <div class="mt-stat-val" style="color:var(--green)">80%</div>
                        <div class="mt-stat-label">You Earn</div>
                    </div>
                </div>

                <!-- Earnings explainer -->
                <div style="background:var(--green-10);border:1px solid rgba(29,185,84,0.25);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px">
                    <span style="font-size:18px;flex-shrink:0">💰</span>
                    <div style="font-size:12px;color:var(--text-secondary);line-height:1.4"><strong style="color:var(--green)">You keep 80% of every session.</strong> $49 session = you earn $39.20. $89 session = you earn $71.20. Paid out weekly via Stripe.</div>
                </div>

                <!-- ═══ How It Works (collapsible, first-time) ═══ -->
                <div class="card" id="mtHowItWorks" style="margin-bottom:12px;border:1px solid #8B5CF6;display:none">
                    <div class="card-head" style="cursor:pointer" onclick="var b=this.nextElementSibling;b.style.display=b.style.display==='none'?'block':'none'">
                        <div style="display:flex;align-items:center;gap:8px">
                            <span style="font-size:16px">📋</span>
                            <h3 class="card-title" style="margin:0">Mentorship Pipeline — How It Works</h3>
                        </div>
                        <span style="font-size:12px;color:var(--text-muted)">▼</span>
                    </div>
                    <div>
                        <div style="padding:0 16px 12px">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                                <div style="width:24px;height:24px;border-radius:50%;background:#8B5CF6;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">1</div>
                                <div style="font-size:12px;color:var(--text-secondary);line-height:1.4"><strong>New Request</strong> — Parent submitted interest. Reach out within 48hrs to schedule a free 15-min intro call.</div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                                <div style="width:24px;height:24px;border-radius:50%;background:#3B82F6;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">2</div>
                                <div style="font-size:12px;color:var(--text-secondary);line-height:1.4"><strong>Intro Scheduled</strong> — You've reached out. Do the intro call, then click "Complete Intro" below.</div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                                <div style="width:24px;height:24px;border-radius:50%;background:#F59E0B;color:#000;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">3</div>
                                <div style="font-size:12px;color:var(--text-secondary);line-height:1.4"><strong>Intro Done</strong> — Parent automatically gets a checkout link. They choose a package and pay. <strong style="color:var(--green)">You earn 80% of every session.</strong></div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="width:24px;height:24px;border-radius:50%;background:#22C55E;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">4</div>
                                <div style="font-size:12px;color:var(--text-secondary);line-height:1.4"><strong>Active</strong> — Mentee appears below. Schedule weekly sessions, review videos, track goals.</div>
                            </div>
                        </div>
                        <div style="padding:0 16px 12px;text-align:center">
                            <button onclick="document.getElementById('mtHowItWorks').style.display='none';try{localStorage.setItem('ptp_mt_how_hidden','1')}catch(e){}" style="font-size:11px;color:var(--text-muted);background:none;border:1px solid var(--border);border-radius:6px;padding:4px 12px;cursor:pointer">Got it, hide this</button>
                        </div>
                    </div>
                </div>
                <script>try{if(!localStorage.getItem('ptp_mt_how_hidden'))document.getElementById('mtHowItWorks').style.display=''}catch(e){document.getElementById('mtHowItWorks').style.display=''}</script>

                <!-- ═══ INCOMING REQUESTS (Pipeline) ═══ -->
                <?php if (!empty($mentor_incoming)): ?>
                <div class="card" style="margin-bottom:12px">
                    <div class="card-head">
                        <h3 class="card-title" style="display:flex;align-items:center;gap:6px">
                            <span style="width:8px;height:8px;border-radius:50%;background:#8B5CF6;display:inline-block"></span>
                            Pipeline
                        </h3>
                        <span class="text-muted text-sm"><?php echo count($mentor_incoming); ?></span>
                    </div>
                    <?php foreach ($mentor_incoming as $req):
                        $req_name = $req->display_player_name;
                        $req_parent = $req->display_parent_name;
                        $req_email = $req->display_parent_email;
                        $req_phone = '';
                        // Try to get parent phone
                        if ($req->parent_id) {
                            $req_phone_row = $wpdb->get_var($wpdb->prepare("SELECT phone FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d", $req->parent_id));
                            $req_phone = $req_phone_row ?: '';
                        }
                        $req_age = $req->display_player_age ? 'Age ' . intval($req->display_player_age) : '';
                        $req_pkg = ucfirst($req->package_type ?: 'development');
                        $req_price = '$' . intval($req->per_session_price ?: 0) . '/wk';
                        $req_days_ago = floor((time() - strtotime($req->created_at)) / 86400);
                        $req_urgent = $req->status === 'interest' && $req_days_ago >= 2;
                        $step_colors = array('interest'=>'#8B5CF6','intro_scheduled'=>'#3B82F6','intro_done'=>'#F59E0B');
                        $step_labels = array('interest'=>'New — Reach Out','intro_scheduled'=>'Intro Scheduled','intro_done'=>'Intro Done — Awaiting Purchase');
                        $step_color = $step_colors[$req->status] ?? '#737373';
                        $step_label = $step_labels[$req->status] ?? ucfirst($req->status);
                    ?>
                    <div style="padding:14px 0;border-bottom:1px solid var(--border)<?php echo $req_urgent ? ';background:rgba(239,68,68,0.04);margin:0 -16px;padding-left:16px;padding-right:16px' : ''; ?>">
                        <!-- Status badge -->
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                            <span style="font-size:10px;font-weight:700;font-family:Oswald,sans-serif;text-transform:uppercase;color:<?php echo $step_color; ?>;background:<?php echo $step_color; ?>12;padding:3px 10px;border-radius:10px;letter-spacing:.3px"><?php echo esc_html($step_label); ?></span>
                            <?php if ($req_urgent): ?>
                            <span style="font-size:10px;font-weight:700;color:#EF4444">⚠ <?php echo $req_days_ago; ?>d waiting</span>
                            <?php else: ?>
                            <span style="font-size:10px;color:var(--text-muted)"><?php echo $req_days_ago; ?>d ago</span>
                            <?php endif; ?>
                        </div>
                        <!-- Player + parent info -->
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                            <div style="width:36px;height:36px;border-radius:50%;background:<?php echo $step_color; ?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-family:Oswald,sans-serif;font-weight:700;font-size:15px;color:<?php echo $step_color; ?>">
                                <?php echo strtoupper(substr($req_name, 0, 1)); ?>
                            </div>
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:700;font-size:14px;color:var(--text)"><?php echo esc_html($req_name); ?></div>
                                <div style="font-size:12px;color:var(--text-muted)">
                                    <?php echo esc_html($req_parent); ?>
                                    <?php if ($req_age): ?> · <?php echo esc_html($req_age); ?><?php endif; ?>
                                    · <?php echo esc_html($req_pkg); ?> <?php echo esc_html($req_price); ?>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($req->goals)): ?>
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;padding-left:46px;font-style:italic;line-height:1.4">"<?php echo esc_html(substr($req->goals, 0, 120)); ?><?php echo strlen($req->goals) > 120 ? '...' : ''; ?>"</div>
                        <?php endif; ?>
                        <!-- Action buttons -->
                        <div style="display:flex;gap:6px;padding-left:46px;flex-wrap:wrap">
                            <?php if ($req->status === 'interest'): ?>
                            <button onclick="mentorshipMarkIntroScheduled(<?php echo $req->id; ?>, this)" style="padding:7px 14px;background:var(--gold);color:var(--black);border:none;border-radius:6px;font-family:Oswald,sans-serif;font-weight:700;font-size:11px;text-transform:uppercase;cursor:pointer">✓ Mark Intro Scheduled</button>
                            <?php elseif ($req->status === 'intro_scheduled'): ?>
                            <button onclick="mentorshipCompleteIntro(<?php echo $req->id; ?>)" style="padding:7px 14px;background:#22C55E;color:#fff;border:none;border-radius:6px;font-family:Oswald,sans-serif;font-weight:700;font-size:11px;text-transform:uppercase;cursor:pointer">✓ Complete Intro</button>
                            <?php elseif ($req->status === 'intro_done'): ?>
                            <span style="font-size:11px;color:#F59E0B;font-weight:600;padding:7px 0">📧 Checkout link sent to parent</span>
                            <?php endif; ?>
                            <!-- Contact buttons -->
                            <?php if ($req_email): ?>
                            <a href="mailto:<?php echo esc_attr($req_email); ?>" style="padding:7px 12px;background:transparent;border:1px solid var(--border);color:var(--text-muted);border-radius:6px;font-family:Oswald,sans-serif;font-weight:700;font-size:11px;text-transform:uppercase;text-decoration:none;cursor:pointer">📧 Email</a>
                            <?php endif; ?>
                            <?php if ($req_phone): ?>
                            <a href="sms:<?php echo esc_attr($req_phone); ?>" style="padding:7px 12px;background:transparent;border:1px solid var(--border);color:var(--text-muted);border-radius:6px;font-family:Oswald,sans-serif;font-weight:700;font-size:11px;text-transform:uppercase;text-decoration:none;cursor:pointer">💬 Text</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- ═══ ACTIVE MENTEES ═══ -->
                <?php if (!empty($mentees)): ?>
                <div class="card" style="margin-bottom:12px">
                    <div class="card-head">
                        <h3 class="card-title" style="display:flex;align-items:center;gap:6px">
                            <span style="width:8px;height:8px;border-radius:50%;background:#22C55E;display:inline-block"></span>
                            Active Mentees
                        </h3>
                        <button onclick="mtOpenSchedule(<?php echo htmlspecialchars(json_encode(array_map(function($m) { return array('id'=>$m->id,'name'=>$m->display_player_name,'package'=>ucfirst($m->package_type ?: 'dev')); }, $mentees)), ENT_QUOTES); ?>)" style="padding:5px 12px;background:var(--gold);color:var(--black);border:none;border-radius:6px;font-family:Oswald,sans-serif;font-weight:700;font-size:10px;text-transform:uppercase;cursor:pointer">+ Schedule</button>
                    </div>
                    <?php foreach ($mentees as $mentee):
                        $m_name = $mentee->display_player_name;
                        $m_parent = $mentee->display_parent_name;
                        $m_email = $mentee->display_parent_email;
                        $m_done = intval($mentee->sessions_completed ?? 0);
                        $m_total = intval($mentee->sessions_total ?? 0);
                        $m_left = max(0, $m_total - $m_done);
                        $m_pct = $m_total > 0 ? min(100, round(($m_done / $m_total) * 100)) : 0;
                        $m_pkg = ucfirst($mentee->package_type ?: 'dev');
                        $m_tier = $mentee->package_type ?? 'development';
                        $m_since = !empty($mentee->started_at) ? date('M j', strtotime($mentee->started_at)) : date('M j', strtotime($mentee->created_at));
                        $m_low = $m_total > 0 && $m_left <= 2;
                        // Next session
                        $m_next_row = $wpdb->get_row($wpdb->prepare(
                            "SELECT s.id as session_id, s.scheduled_at, s.duration_minutes, s.session_type
                             FROM {$wpdb->prefix}ptp_mentorship_sessions s
                             JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
                             WHERE a.pair_id = %d AND s.status = 'scheduled' AND s.scheduled_at >= NOW()
                             ORDER BY s.scheduled_at ASC LIMIT 1", $mentee->id
                        ));
                        $m_next = $m_next_row ? $m_next_row->scheduled_at : null;
                        // Past sessions needing completion
                        $m_needs_complete = $wpdb->get_row($wpdb->prepare(
                            "SELECT s.id as session_id, s.scheduled_at, s.duration_minutes, s.session_number
                             FROM {$wpdb->prefix}ptp_mentorship_sessions s
                             JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
                             WHERE a.pair_id = %d AND s.status = 'scheduled' AND s.scheduled_at < NOW()
                             ORDER BY s.scheduled_at DESC LIMIT 1", $mentee->id
                        ));
                    ?>
                    <div class="mt-mentee-card" onclick="mdOpen(<?php echo intval($mentee->id); ?>)" style="cursor:pointer">
                        <div style="display:flex;gap:10px;align-items:flex-start">
                            <div class="mt-mentee-avatar" style="background:var(--gold);color:var(--black)">
                                <?php echo strtoupper(substr($m_name, 0, 1)); ?>
                            </div>
                            <div style="flex:1;min-width:0">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px">
                                    <div style="font-weight:700;font-size:14px;color:var(--text)"><?php echo esc_html($m_name); ?></div>
                                    <span class="mt-pkg-badge mt-pkg-<?php echo esc_attr($m_tier); ?>"><?php echo esc_html($m_pkg); ?></span>
                                </div>
                                <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">
                                    <?php echo esc_html($m_parent); ?>
                                    <?php if ($mentee->display_player_age): ?> &middot; Age <?php echo intval($mentee->display_player_age); ?><?php endif; ?>
                                    &middot; Since <?php echo esc_html($m_since); ?>
                                </div>
                                <!-- Progress bar -->
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                    <div class="mt-progress-mini">
                                        <div class="mt-progress-mini-fill" style="width:<?php echo $m_pct; ?>%;background:<?php echo $m_low ? '#EF4444' : 'var(--gold)'; ?>"></div>
                                    </div>
                                    <div style="font-size:11px;font-weight:700;color:<?php echo $m_low ? '#EF4444' : 'var(--text-muted)'; ?>;white-space:nowrap;font-family:Oswald,sans-serif"><?php echo $m_done; ?>/<?php echo $m_total; ?></div>
                                </div>
                                <!-- Status + Actions -->
                                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px">
                                    <div style="font-size:11px;color:var(--text-muted)">
                                        <?php if ($m_needs_complete): ?>
                                            <span style="color:#EF4444;font-weight:600">Session needs completion</span>
                                        <?php elseif ($m_next): ?>
                                            Next: <?php echo date('D M j, g:ia', strtotime($m_next)); ?>
                                        <?php elseif ($m_left > 0): ?>
                                            <span style="color:#F59E0B">No session scheduled</span>
                                        <?php else: ?>
                                            <span style="color:#EF4444">Package complete</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display:flex;gap:4px">
                                        <?php if ($m_needs_complete): ?>
                                        <button class="mt-pill mt-pill-green" onclick="mtOpenSessionComplete(<?php echo intval($m_needs_complete->session_id); ?>,<?php echo intval($mentee->id); ?>,'<?php echo esc_js($m_name); ?>',<?php echo intval($m_done + 1); ?>,<?php echo intval($m_needs_complete->duration_minutes ?: 30); ?>)">Complete</button>
                                        <?php elseif ($m_next && $m_next_row): ?>
                                            <?php 
                                            $mins_until = (strtotime($m_next) - time()) / 60;
                                            if ($mins_until < 15 && $mins_until > -60): ?>
                                            <button class="mt-pill mt-pill-gold" onclick="mtOpenSessionComplete(<?php echo intval($m_next_row->session_id); ?>,<?php echo intval($mentee->id); ?>,'<?php echo esc_js($m_name); ?>',<?php echo intval($m_done + 1); ?>,<?php echo intval($m_next_row->duration_minutes ?: 30); ?>)">Complete</button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($m_email): ?>
                                        <a href="mailto:<?php echo esc_attr($m_email); ?>" class="mt-pill mt-pill-ghost" title="Email parent" style="text-decoration:none">Email</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- ═══ PENDING VIDEOS ═══ -->
                <?php if ($pending_videos): ?>
                <div class="card" style="margin-bottom:12px">
                    <div class="card-head">
                        <h3 class="card-title" style="display:flex;align-items:center;gap:6px">
                            <span style="width:8px;height:8px;border-radius:50%;background:#EF4444;display:inline-block"></span>
                            Video Reviews
                        </h3>
                        <span style="font-size:11px;font-weight:700;color:#EF4444"><?php echo count($pending_videos); ?> pending</span>
                    </div>
                    <?php foreach ($pending_videos as $pv): ?>
                    <div class="mt-video-q">
                        <div class="mt-video-thumb">▶</div>
                        <div class="mt-video-info">
                            <div class="mt-video-player"><?php echo esc_html($pv->player_name ?: 'Player'); ?></div>
                            <div class="mt-video-time"><?php echo human_time_diff(strtotime($pv->created_at), time()); ?> ago</div>
                        </div>
                        <button class="mt-review-btn" onclick="openVideoReview(<?php echo $pv->id; ?>, '<?php echo esc_js($pv->video_url); ?>')">Review</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Share Mentorship Link -->
                <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:12px">
                    <div style="font-family:Oswald,sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:8px">Share Your Mentorship Link</div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <div style="flex:1;min-width:0;font-size:12px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:var(--bg-secondary);padding:8px 10px;border-radius:6px;font-family:monospace"><?php echo esc_html($profile_url); ?></div>
                        <button onclick="ptpCopyMentorLink(this)" data-url="<?php echo esc_attr($profile_url); ?>" style="flex-shrink:0;padding:8px 14px;background:var(--gold);color:var(--black);border:none;border-radius:6px;font-family:Oswald,sans-serif;font-weight:700;font-size:11px;text-transform:uppercase;cursor:pointer">Copy</button>
                    </div>
                </div>

                <!-- Settings link -->
                <button onclick="goToMentorshipSettings()" style="width:100%;padding:12px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--text-muted);font-family:Oswald,sans-serif;font-weight:700;font-size:12px;text-transform:uppercase;cursor:pointer;letter-spacing:.3px">
                    ⚙ Mentorship Settings
                </button>

                <?php else: ?>

                <!-- ── Empty State: Smart two-state (disabled vs live-but-waiting) ── -->
                <?php if (!$mentor_db_enabled): ?>
                <!-- STATE A: Mentorship not yet enabled -->
                <div style="padding:8px 0">
                    <div style="background:linear-gradient(135deg,#0A0A0A 0%,#141414 100%);border:2px solid rgba(252,185,0,0.3);border-radius:16px;padding:28px 20px;text-align:center;margin-bottom:16px">
                        <div style="font-size:36px;margin-bottom:16px;opacity:0.6">🔒</div>
                        <div style="font-family:Oswald,sans-serif;font-size:10px;color:var(--gold);letter-spacing:2px;text-transform:uppercase;margin-bottom:8px">Mentorship Off</div>
                        <h4 style="font-family:Oswald,sans-serif;font-size:18px;color:#fff;text-transform:uppercase;margin-bottom:10px">Turn On Year-Round Mentorship</h4>
                        <p style="font-size:13px;color:#A3A3A3;line-height:1.6;margin-bottom:20px">Weekly video calls with kids you already coached at camp. Film review, goal tracking, and a real relationship — not just more sessions. <strong style="color:var(--green)">You keep 80% of every session.</strong></p>
                        <button onclick="goToMentorshipSettings()" style="width:100%;padding:14px;background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.5px;border:none;border-radius:10px;cursor:pointer">
                            Enable Mentorship in Settings →
                        </button>
                        <p style="font-size:11px;color:#525252;margin-top:10px">Go to Profile tab → Mentorship section to turn it on</p>
                    </div>
                </div>

                <?php else: ?>
                <!-- STATE B: Mentorship is LIVE — waiting for first mentee -->
                <div style="padding:8px 0">
                    <div style="background:linear-gradient(135deg,#0A0A0A 0%,#141414 100%);border:2px solid var(--gold);border-radius:16px;padding:24px 20px;margin-bottom:16px">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
                            <div style="width:10px;height:10px;border-radius:50%;background:#22C55E;flex-shrink:0;box-shadow:0 0 8px rgba(34,197,94,0.5)"></div>
                            <div style="font-family:Oswald,sans-serif;font-size:10px;color:#22C55E;letter-spacing:2px;text-transform:uppercase">Mentorship is Live</div>
                        </div>
                        <p style="font-size:13px;color:#A3A3A3;line-height:1.6;margin-bottom:16px">Your mentorship card is showing on your profile. Share your link with camp families to get your first mentee. <strong style="color:var(--green)">You keep 80% of every session — paid out weekly.</strong></p>
                        <div style="background:rgba(255,255,255,0.05);border:1px solid rgba(252,185,0,0.25);border-radius:10px;padding:12px;display:flex;gap:8px;align-items:center">
                            <div style="flex:1;min-width:0;font-size:12px;color:#A3A3A3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:monospace"><?php echo esc_html($profile_url); ?></div>
                            <button onclick="ptpCopyMentorLink(this)" data-url="<?php echo esc_attr($profile_url); ?>" style="flex-shrink:0;padding:7px 14px;background:var(--gold);color:var(--black);border:none;border-radius:7px;font-family:Oswald,sans-serif;font-weight:700;font-size:11px;text-transform:uppercase;cursor:pointer">Copy</button>
                        </div>
                    </div>
                    <div class="card" style="margin-bottom:0">
                        <div class="card-head"><h3 class="card-title">What Happens When a Parent Requests</h3></div>
                        <?php foreach(array(
                            array('1','You get an email + notification here. Reach out within 48 hours to schedule a free 15-min intro call.'),
                            array('2','After the call, click "Complete Intro" — parent automatically gets a checkout link.'),
                            array('3','They pick a single session or a package, pay, and show up as an active mentee. You earn 80% of every session.'),
                        ) as $step): ?>
                        <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border)">
                            <div style="width:24px;height:24px;flex-shrink:0;background:var(--gold);color:var(--black);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:Oswald,sans-serif;font-weight:700;font-size:12px"><?php echo $step[0];?></div>
                            <p style="font-size:12px;color:var(--text-secondary);line-height:1.5;margin-top:2px"><?php echo esc_html($step[1]);?></p>
                        </div>
                        <?php endforeach;?>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; // has_mentor_activity ?>
            </div>

            <!-- ════════ PROFILE TAB ════════ -->
            <div class="tab-panel" id="tab-profile" data-tab="profile" role="tabpanel" aria-label="Profile">
                <div class="card text-center">
                    <img src="<?php echo esc_url($avatar_url); ?>" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--gold);margin:0 auto 16px;display:block">
                    <h2 style="font-size:22px;margin-bottom:4px"><?php echo esc_html(strtoupper($display_name)); ?></h2>
                    <p class="text-muted" style="margin-bottom:16px;font-size:14px"><?php echo esc_html(!empty($headline) ? $headline : 'Soccer Trainer'); ?></p>
                    <button class="btn btn-outline btn-sm" onclick="openPhotoUpload()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        Change Photo
                    </button>
                </div>

                <div class="card">
                    <div class="card-head"><h3 class="card-title">Settings</h3></div>
                    <div class="form-group">
                        <label class="form-label">Hourly Rate</label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span style="font-size:20px;font-weight:700;color:var(--text)">$</span>
                            <input type="number" id="hourlyRate" value="<?php echo esc_attr($stats['rate']); ?>" class="form-input" style="max-width:110px">
                            <span class="text-muted text-sm">/session</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Headline</label>
                        <input type="text" id="headline" value="<?php echo esc_attr($headline); ?>" class="form-input" placeholder="e.g., Former Pro Academy Player">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bio</label>
                        <textarea id="bio" class="form-input form-textarea" placeholder="Tell families about your experience..."><?php echo esc_textarea($bio); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Training Philosophy</label>
                        <textarea id="trainingPhilosophy" class="form-input form-textarea" rows="3" placeholder="What makes your sessions different?"><?php echo esc_textarea($training_philosophy); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Why I Coach</label>
                        <textarea id="coachingWhy" class="form-input form-textarea" rows="3" placeholder="What drives you to train young players?"><?php echo esc_textarea($coaching_why); ?></textarea>
                    </div>
                    <div style="border-top:1px solid var(--border);margin:16px 0;padding-top:16px">
                        <div class="form-group">
                            <label class="form-label">Team / Club</label>
                            <input type="text" id="team" value="<?php echo esc_attr($team); ?>" class="form-input" placeholder="e.g., Philadelphia Union, Villanova">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Playing Level</label>
                            <select id="playingLevel" class="form-input" style="font-size:16px">
                                <option value="">Select level...</option>
                                <option value="pro"<?php echo $playing_level === 'pro' ? ' selected' : ''; ?>>Professional</option>
                                <option value="semi_pro"<?php echo $playing_level === 'semi_pro' ? ' selected' : ''; ?>>Semi-Pro</option>
                                <option value="college_d1"<?php echo $playing_level === 'college_d1' ? ' selected' : ''; ?>>NCAA Division 1</option>
                                <option value="college_d2"<?php echo $playing_level === 'college_d2' ? ' selected' : ''; ?>>NCAA Division 2</option>
                                <option value="college_d3"<?php echo $playing_level === 'college_d3' ? ' selected' : ''; ?>>NCAA Division 3</option>
                                <option value="academy"<?php echo $playing_level === 'academy' ? ' selected' : ''; ?>>Academy / MLS NEXT</option>
                            </select>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div class="form-group">
                                <label class="form-label">Years Playing</label>
                                <input type="number" id="experienceYears" value="<?php echo $experience_years ?: ''; ?>" class="form-input" placeholder="e.g., 15" min="0" max="50">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Years Coaching</label>
                                <input type="number" id="yearsCoaching" value="<?php echo $years_coaching ?: ''; ?>" class="form-input" placeholder="e.g., 5" min="0" max="50">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Specialties / Certifications</label>
                            <input type="text" id="specialties" value="<?php echo esc_attr($specialties); ?>" class="form-input" placeholder="e.g., USSF D License, CPR Certified">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Travel Radius (miles)</label>
                            <input type="number" id="travelRadius" value="<?php echo $travel_radius; ?>" class="form-input" style="max-width:110px" min="1" max="50">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div class="form-group">
                                <label class="form-label">City</label>
                                <input type="text" id="city" value="<?php echo esc_attr($city); ?>" class="form-input" placeholder="e.g., Philadelphia">
                            </div>
                            <div class="form-group">
                                <label class="form-label">State</label>
                                <select id="stateSelect" class="form-input" style="font-size:16px">
                                    <option value="">Select...</option>
                                    <?php foreach (['PA'=>'Pennsylvania','NJ'=>'New Jersey','DE'=>'Delaware','MD'=>'Maryland','NY'=>'New York','CT'=>'Connecticut','VA'=>'Virginia'] as $sv => $sn): ?>
                                    <option value="<?php echo $sv; ?>"<?php echo $state === $sv ? ' selected' : ''; ?>><?php echo $sn; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-full" onclick="saveProfile()">Save Changes</button>
                </div>

                <!-- ═══ MENTORSHIP SETTINGS ═══ -->
                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">Mentorship</h3>
                        <?php
                        global $wpdb;
                        $mentor_row = $wpdb->get_row($wpdb->prepare("SELECT mentorship_enabled, mentorship_bio, mentorship_max_mentees, mentorship_packages, zoom_email FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id ?? 0));
                        $m_enabled  = !empty($mentor_row->mentorship_enabled);
                        ?>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" id="mentorshipEnabled" <?php echo $m_enabled ? 'checked' : ''; ?>
                                   style="width:18px;height:18px;accent-color:var(--gold)">
                            <span style="font-size:12px;font-weight:600;color:var(--text)">Enabled</span>
                        </label>
                    </div>
                    <p class="text-muted text-sm" style="margin-bottom:12px">
                        When enabled, a mentorship card appears on your trainer profile page. Families can request a free intro call, then purchase a single session or weekly package.
                    </p>
                    <div style="background:var(--green-10);border-radius:8px;padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:8px">
                        <span style="font-size:16px;flex-shrink:0">💰</span>
                        <span style="font-size:12px;color:var(--text-secondary);line-height:1.4"><strong style="color:var(--green)">You earn 80% of every mentorship session</strong> — paid out weekly to your Stripe account. PTP handles billing, scheduling, and parent communication.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mentorship Bio <span class="text-muted">(shown on your profile card)</span></label>
                        <textarea id="mentorshipBio" class="form-input form-textarea" rows="3"
                                  placeholder="Why you mentor, what families can expect, what makes it different..."><?php echo esc_textarea($mentor_row->mentorship_bio ?? ''); ?></textarea>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div class="form-group">
                            <label class="form-label">Max Mentees</label>
                            <input type="number" id="mentorshipMaxMentees" value="<?php echo intval($mentor_row->mentorship_max_mentees ?? 20); ?>"
                                   class="form-input" min="1" max="50">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Zoom Email</label>
                            <input type="email" id="mentorshipZoomEmail" value="<?php echo esc_attr($mentor_row->zoom_email ?? ''); ?>"
                                   class="form-input" placeholder="your@email.com">
                            <div style="font-size:11px;color:var(--muted);margin-top:4px">Used to auto-create Zoom rooms</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Active Packages</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap" id="mentorPkgPicker">
                            <?php
                            $active_pkgs = array_filter(explode(',', $mentor_row->mentorship_packages ?? 'single,kickstart,development,elite'));
                            foreach ($mentor_pkgs as $pk => $mpk_info):
                                $price_label = $mpk_info['per_session_display'] ?? '?';
                                $billing_suffix = ($mpk_info['billing'] ?? 'weekly') === 'one-time' ? '' : '/wk';
                                $plabel = ($mpk_info['name'] ?? ucfirst($pk)) . ' ($' . $price_label . $billing_suffix . ')';
                                $is_active = in_array($pk, $active_pkgs);
                            ?>
                            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;padding:6px 12px;background:<?php echo $is_active ? 'rgba(252,185,0,0.12)' : 'var(--bg-secondary)'; ?>;border:1px solid <?php echo $is_active ? 'var(--gold)' : 'var(--border)'; ?>;border-radius:8px">
                                <input type="checkbox" name="mentorPkg" value="<?php echo $pk; ?>" <?php echo $is_active ? 'checked' : ''; ?>
                                       style="accent-color:var(--gold)">
                                <?php echo esc_html($plabel); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button class="btn btn-full" onclick="saveMentorshipSettings()" id="saveMentorBtn">Save Mentorship Settings</button>
                </div>
                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">Training Locations</h3>
                        <span class="text-muted" style="font-size:12px"><?php echo count($training_locations); ?> saved</span>
                    </div>
                    <p class="text-muted text-sm" style="margin-bottom:12px">Parks, fields, and facilities where you train. Parents see these when booking.</p>

                    <div id="dashLocList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
                        <?php foreach ($training_locations as $loc):
                            $ln = is_array($loc) ? ($loc['name'] ?? '') : $loc;
                            $la = is_array($loc) ? ($loc['address'] ?? '') : '';
                            $lt = is_array($loc) ? ($loc['lat'] ?? '') : '';
                            $lg = is_array($loc) ? ($loc['lng'] ?? '') : '';
                            $lp = is_array($loc) ? ($loc['place_id'] ?? '') : '';
                            if (!$ln) continue;
                        ?>
                        <div class="dash-loc-item" data-loc="<?php echo esc_attr(wp_json_encode(array('name'=>$ln,'address'=>$la,'lat'=>$lt,'lng'=>$lg,'place_id'=>$lp))); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" style="flex-shrink:0"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <div style="flex:1;min-width:0">
                                <div style="font-size:14px;font-weight:600;word-break:break-word"><?php echo esc_html($ln); ?></div>
                                <?php if ($la && $la !== $ln): ?>
                                <div style="font-size:12px;color:var(--muted);margin-top:1px;word-break:break-word"><?php echo esc_html($la); ?></div>
                                <?php endif; ?>
                            </div>
                            <button type="button" onclick="removeDashLoc(this)" class="btn-remove-circle">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($training_locations)): ?>
                        <div id="dashLocEmpty" style="text-align:center;padding:20px;color:var(--muted);font-size:13px">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="display:block;margin:0 auto 8px;opacity:.4"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            No training locations added yet
                        </div>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:8px;position:relative" id="dashLocAddWrap">
                        <input type="text" id="dashLocInput" class="form-input" placeholder="Search for a park, field, or facility..." style="font-size:16px" autocomplete="off">
                        <button type="button" class="btn btn-full" onclick="addDashLoc()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Add Location
                        </button>
                    </div>
                    <?php if ($google_maps_key): ?>
                    <p class="text-muted text-sm" style="margin-top:6px">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2" style="display:inline;vertical-align:middle"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Google Maps autocomplete — type and select for accurate pins
                    </p>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-head"><h3 class="card-title">Full Profile Editor</h3></div>
                    <p class="text-muted text-sm" style="margin-bottom:12px">Edit training locations, experience, specialties, gallery, and more.</p>
                    <a href="<?php echo esc_url(home_url('/trainer-onboarding/?edit=1')); ?>" class="btn btn-outline btn-full">Edit Full Profile</a>
                </div>

                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">Payments</h3>
                        <?php if ($stripe_complete): ?><span class="text-green" style="font-size:13px;font-weight:700">✓ Connected</span><?php endif; ?>
                    </div>
                    <?php if (!$stripe_complete && !$has_stripe): ?>
                    <div style="padding:14px;background:var(--amber-10);border-radius:var(--radius-sm);margin-bottom:12px;font-size:13px;color:#7B6100;font-weight:500;">
                        <strong>Action needed:</strong> Connect Stripe to receive payouts after sessions. Takes under 2 minutes.
                    </div>
                    <button class="btn btn-full" onclick="connectStripe()">Connect Stripe</button>
                    <?php elseif (!$stripe_complete && $has_stripe): ?>
                    <div style="padding:14px;background:var(--amber-10);border-radius:var(--radius-sm);margin-bottom:12px;font-size:13px;color:#7B6100;font-weight:500;">
                        <strong>Almost done!</strong> Stripe needs a few more details. Tap below to finish setup.
                    </div>
                    <button class="btn btn-full" onclick="connectStripe()">Complete Stripe Setup</button>
                    <?php else: ?>
                    <p class="text-muted text-sm">Your Stripe account is connected and ready to receive payouts.</p>
                    <?php endif; ?>
                </div>

                <div class="share-card">
                    <h4 class="share-title">Your Public Profile</h4>
                    <div class="share-row">
                        <input type="text" value="<?php echo esc_attr($profile_url); ?>" class="share-input" readonly>
                        <a href="<?php echo esc_url($profile_url); ?>" target="_blank" class="share-btn">View</a>
                    </div>
                </div>

                <button class="btn btn-danger btn-full mt-4" onclick="logout()">Log Out</button>
            </div>

        </div>
    </main>

    <!-- ═══ BOTTOM NAV ═══ -->
    <nav class="dash-nav" role="tablist" aria-label="Dashboard navigation">
        <div class="nav-item active" data-tab="home" onclick="switchTab('home')" role="tab" aria-selected="true" aria-label="Home" tabindex="0">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <div class="nav-item" data-tab="schedule" onclick="switchTab('schedule')" role="tab" aria-selected="false" aria-label="Schedule" tabindex="0">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div class="nav-item" data-tab="earnings" onclick="switchTab('earnings')" role="tab" aria-selected="false" aria-label="Earnings" tabindex="0">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </div>
        <div class="nav-item" data-tab="messages" onclick="switchTab('messages')" role="tab" aria-selected="false" aria-label="Messages" tabindex="0">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            <?php if ($unread_count > 0): ?><span class="nav-badge"><?php echo esc_html($unread_count); ?></span><?php endif; ?>
        </div>
        <?php if ($has_mentor_activity): ?>
        <!-- v235.8: Surface mentorship in bottom nav when active -->
        <div class="nav-item" data-tab="mentorship" onclick="switchTab('mentorship')" role="tab" aria-selected="false" aria-label="Mentorship" tabindex="0">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            <?php
            $mentor_nav_badge = $mentor_stats['pending_videos'] + count($mentor_incoming);
            if ($mentor_nav_badge > 0): ?>
            <span class="nav-badge"><?php echo $mentor_nav_badge; ?></span>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="nav-item" data-tab="more" onclick="openMoreSheet()" role="tab" aria-selected="false" aria-label="More options" tabindex="0">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
            <?php
            $more_badge = 0;
            if ($unreplied_count > 0) $more_badge += $unreplied_count;
            $mentor_badge_count = $mentor_stats['pending_videos'] + count($mentor_incoming);
            if ($mentor_badge_count > 0) $more_badge += $mentor_badge_count;
            if ($more_badge > 0): ?>
            <span class="nav-badge"><?php echo $more_badge; ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </nav>
</div>

<!-- More Sheet -->
<div class="sheet-overlay" id="moreOverlay" onclick="closeMoreSheet()"></div>
<div class="sheet" id="moreSheet" style="max-height:55vh">
    <div class="sheet-handle"></div>
    <div class="sheet-head">
        <h2 class="sheet-title">More</h2>
        <button class="sheet-x" onclick="closeMoreSheet()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="sheet-body" style="padding:12px 20px 20px">
        <div class="more-item" onclick="closeMoreSheet();switchTab('insights');if(!insightsLoaded)loadInsights(30);">
            <div class="more-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg></div>
            <span>Insights</span>
        </div>
        <div class="more-item" onclick="closeMoreSheet();switchTab('reviews');">
            <div class="more-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
            <span>Reviews</span>
            <?php if ($unreplied_count > 0): ?><span class="more-badge"><?php echo $unreplied_count; ?></span><?php endif; ?>
        </div>
        <?php if (!$has_mentor_activity): ?>
        <div class="more-item" onclick="closeMoreSheet();switchTab('mentorship');">
            <div class="more-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></div>
            <span>Mentorship</span>
            <?php if ($mentor_badge_count > 0): ?><span class="more-badge"><?php echo $mentor_badge_count; ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="more-item" onclick="closeMoreSheet();switchTab('profile');">
            <div class="more-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
            <span>Profile</span>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- Review Reply Sheet -->
<div class="sheet-overlay" id="replyOverlay" onclick="closeReplySheet()"></div>
<div class="sheet" id="replySheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head">
        <h3 class="sheet-title">Reply to Review</h3>
        <button class="sheet-x" onclick="closeReplySheet(true)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="sheet-body">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px;background:var(--surface);border-radius:var(--radius-sm)">
            <div class="rev-avatar" id="replyAvatar" style="width:36px;height:36px;font-size:12px">--</div>
            <div>
                <div style="font-weight:700;font-size:14px" id="replyName">Parent Name</div>
                <div style="color:var(--gold);font-size:13px" id="replyStars">★★★★★</div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Your Response</label>
            <textarea class="form-input form-textarea" id="replyText" placeholder="Thank them for the kind words, or address any feedback..." maxlength="500" style="min-height:100px"></textarea>
            <div style="text-align:right;font-size:11px;color:var(--text-muted);margin-top:4px"><span id="replyCharCount">0</span>/500</div>
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin-top:8px">Your reply will be visible on your public profile. Keep it professional and friendly.</p>
    </div>
    <div class="sheet-foot">
        <button class="btn btn-full" id="replySubmitBtn" onclick="submitReply()">Submit Reply</button>
    </div>
    <input type="hidden" id="replyReviewId" value="">
</div>

<!-- Video Review Sheet (replaces prompt()) -->
<div class="sheet-overlay" id="videoReviewOverlay" onclick="closeVideoReviewSheet()"></div>
<div class="sheet" id="videoReviewSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head">
        <div class="sheet-title" style="font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase">Review Video</div>
        <div class="sheet-x" onclick="closeVideoReviewSheet(true)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
    </div>
    <div class="sheet-body">
        <div class="form-group">
            <label class="form-label">Your Feedback</label>
            <textarea class="form-input form-textarea" id="videoFeedbackText" placeholder="Great work on the turns! Try to keep your head up more during the approach..." maxlength="1000" style="min-height:100px;font-size:16px"></textarea>
        </div>
        <div class="form-group" style="margin-top:16px">
            <label class="form-label">Effort Rating</label>
            <div class="recap-effort" id="videoEffortStars">
                <div class="recap-star" data-val="1" onclick="setVideoEffort(1)">1</div>
                <div class="recap-star" data-val="2" onclick="setVideoEffort(2)">2</div>
                <div class="recap-star" data-val="3" onclick="setVideoEffort(3)">3</div>
                <div class="recap-star active" data-val="4" onclick="setVideoEffort(4)">4</div>
                <div class="recap-star" data-val="5" onclick="setVideoEffort(5)">5</div>
            </div>
        </div>
    </div>
    <div class="sheet-foot" style="display:flex;flex-direction:column;gap:8px">
        <button class="btn btn-full" id="videoReviewSubmitBtn" onclick="submitVideoReviewSheet()">Submit Review</button>
        <?php if (class_exists('PTP_AI_Assistant') && PTP_AI_Assistant::is_available()): ?>
        <button type="button" id="videoAiBtn" onclick="aiDraftVideoFeedback()" style="width:100%;padding:10px;background:transparent;border:1.5px solid #8B5CF6;color:#8B5CF6;border-radius:var(--radius-md);font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;min-height:44px;touch-action:manipulation">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            Suggest Feedback
        </button>
        <?php endif; ?>
    </div>
    <input type="hidden" id="videoReviewId" value="">
    <input type="hidden" id="videoReviewRating" value="4">
</div>

<!-- Challenge Sheet (replaces prompt()) -->
<div class="sheet-overlay" id="challengeOverlay" onclick="closeChallengeSheetModal()"></div>
<div class="sheet" id="challengeSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head">
        <div class="sheet-title" style="font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase">Post Challenge</div>
        <div class="sheet-x" onclick="closeChallengeSheetModal(true)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
    </div>
    <div class="sheet-body">
        <div class="form-group">
            <label class="form-label">Challenge Title</label>
            <input class="form-input" id="challengeTitleInput" placeholder='e.g. "Master the Cruyff Turn"' style="font-size:16px">
        </div>
        <div class="form-group" style="margin-top:14px">
            <label class="form-label">Description / Instructions</label>
            <textarea class="form-input form-textarea" id="challengeDescInput" placeholder="Explain the challenge, what to practice, and how to track progress..." maxlength="1000" style="min-height:80px;font-size:16px"></textarea>
        </div>
        <div class="form-group" style="margin-top:14px">
            <label class="form-label">Type</label>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <button type="button" class="rev-filter active" data-ctype="skill" onclick="pickChallengeType(this,'skill')">⚽ Skill</button>
                <button type="button" class="rev-filter" data-ctype="fitness" onclick="pickChallengeType(this,'fitness')">💪 Fitness</button>
                <button type="button" class="rev-filter" data-ctype="mindset" onclick="pickChallengeType(this,'mindset')">🧠 Mindset</button>
                <button type="button" class="rev-filter" data-ctype="freestyle" onclick="pickChallengeType(this,'freestyle')">🎨 Freestyle</button>
            </div>
        </div>
    </div>
    <div class="sheet-foot">
        <button class="btn btn-full" id="challengeSubmitBtn" onclick="submitChallengeSheet()">Post Challenge</button>
    </div>
    <input type="hidden" id="challengeTypeInput" value="skill">
</div>

<!-- Cancel Session Sheet (replaces prompt()) -->
<div class="sheet-overlay" id="cancelOverlay" onclick="closeCancelSheet()"></div>

<!-- v225: Intro Complete Sheet -->
<div class="sheet-overlay" id="introCompleteOverlay" onclick="closeIntroCompleteSheet()"></div>
<div class="sheet" id="introCompleteSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head">
        <div class="sheet-title" style="font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase">Complete Intro Call</div>
        <div class="sheet-x" onclick="closeIntroCompleteSheet(true)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
    </div>
    <div class="sheet-body">
        <p style="font-size:13px;color:#A3A3A3;margin-bottom:16px;line-height:1.5">Mark this intro call as complete. The parent will receive a link to choose their package and start.</p>
        <div class="form-group">
            <label class="form-label">Call Notes (optional)</label>
            <textarea class="form-input form-textarea" id="introNotes" placeholder="How did the call go? What did you discuss? Any notes for yourself..." rows="3" style="font-size:16px"></textarea>
        </div>
        <div class="form-group" style="margin-top:14px">
            <label class="form-label">Recommended Package</label>
            <select class="form-input" id="introRecPkg" style="font-size:16px">
                <option value="single">Single Session — $49 one-time (1 session, 30 min)</option>
                <option value="kickstart">Kickstart — $49/wk (12 sessions, 30 min)</option>
                <option value="development" selected>Development — $69/wk (24 sessions, 45 min)</option>
                <option value="elite">Elite — $89/wk (36 sessions, 60 min)</option>
            </select>
        </div>
    </div>
    <div class="sheet-foot">
        <button class="btn btn-full" id="introCompleteBtn" onclick="submitIntroComplete()">Complete Intro &amp; Send Checkout Link</button>
    </div>
</div>

<div class="sheet" id="cancelSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head">
        <div class="sheet-title" style="font-family:'Oswald',sans-serif;font-weight:700;text-transform:uppercase">Cancel Session</div>
        <div class="sheet-x" onclick="closeCancelSheet(true)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
    </div>
    <div class="sheet-body">
        <p style="font-size:14px;color:var(--text-secondary);margin:0 0 16px;line-height:1.5">Are you sure you want to cancel this session? The parent will be notified.</p>
        <div class="form-group">
            <label class="form-label">Reason (optional)</label>
            <textarea class="form-input form-textarea" id="cancelReasonText" placeholder="e.g. Weather, scheduling conflict..." maxlength="500" style="min-height:70px;font-size:16px"></textarea>
        </div>
    </div>
    <div class="sheet-foot">
        <button class="btn btn-full" id="cancelSubmitBtn" onclick="submitCancelSheet()" style="background:#DC2626;color:#fff">Confirm Cancellation</button>
    </div>
    <input type="hidden" id="cancelSessionId" value="">
</div>

<!-- Training Plan Sheet -->
<div class="recap-overlay" id="recapOverlay" onclick="closeRecapSheet()"></div>
<div class="recap-sheet" id="recapSheet">
    <div class="recap-handle"></div>
    <div class="recap-head">
        <div>
            <div class="recap-title" id="recapTitle">Training Plan</div>
            <div class="recap-sub" id="recapSub"></div>
        </div>
        <button class="recap-close" onclick="closeRecapSheet()">&times;</button>
    </div>
    <div class="recap-body">
        <input type="hidden" id="recapBookingId" value="">
        
        <div class="recap-field">
            <div class="recap-label"><span class="recap-label-dot" style="background:#FCB900"></span> What We Worked On</div>
            <textarea class="recap-textarea" id="recapFocus" placeholder="e.g. First touch, dribbling under pressure, 1v1 finishing..."></textarea>
        </div>
        
        <div class="recap-field">
            <div class="recap-label"><span class="recap-label-dot" style="background:#22C55E"></span> Wins</div>
            <textarea class="recap-textarea" id="recapWins" placeholder="What did they do well? Improvements you noticed..." style="min-height:56px"></textarea>
        </div>
        
        <div class="recap-field">
            <div class="recap-label"><span class="recap-label-dot" style="background:#F59E0B"></span> Keep Working On</div>
            <textarea class="recap-textarea" id="recapImprove" placeholder="Areas to focus on next session..." style="min-height:56px"></textarea>
        </div>
        
        <div class="recap-field">
            <div class="recap-label"><span class="recap-label-dot" style="background:#3B82F6"></span> Homework</div>
            <textarea class="recap-textarea" id="recapHomework" placeholder="What to practice before next time..." style="min-height:56px"></textarea>
        </div>
        
        <div class="recap-field">
            <div class="recap-label"><span class="recap-label-dot" style="background:#8B5CF6"></span> Next Session Focus</div>
            <textarea class="recap-textarea" id="recapNextFocus" placeholder="What you plan to work on next session..." style="min-height:56px"></textarea>
        </div>
        
        <div class="recap-field">
            <div class="recap-label">Effort Level</div>
            <div class="recap-effort" id="recapEffort">
                <div class="recap-star" data-val="1" onclick="setEffort(1)">1</div>
                <div class="recap-star" data-val="2" onclick="setEffort(2)">2</div>
                <div class="recap-star" data-val="3" onclick="setEffort(3)">3</div>
                <div class="recap-star" data-val="4" onclick="setEffort(4)">4</div>
                <div class="recap-star" data-val="5" onclick="setEffort(5)">5</div>
            </div>
        </div>
        
        <!-- Skill Ratings -->
        <div class="recap-field">
            <div class="recap-label"><span class="recap-label-dot" style="background:#8B5CF6"></span> Skill Ratings <span style="font-weight:400;color:var(--text-muted)">(optional)</span></div>
            <div class="recap-skills" id="recapSkills">
                <?php 
                $skill_cats = array(
                    'dribbling' => 'Dribbling', 'passing' => 'Passing', 'first_touch' => 'First Touch',
                    'shooting' => 'Shooting', 'defending' => 'Defending', 'positioning' => 'Positioning',
                    'fitness' => 'Fitness', 'confidence' => 'Confidence', 'game_iq' => 'Game IQ'
                );
                foreach ($skill_cats as $key => $label): ?>
                <div class="recap-skill-row">
                    <div class="recap-skill-name"><?php echo esc_html($label); ?></div>
                    <div class="recap-skill-dots" data-skill="<?php echo esc_attr($key); ?>">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <div class="recap-skill-dot" data-val="<?php echo $i; ?>" onclick="setSkillRating('<?php echo esc_attr($key); ?>', <?php echo $i; ?>, this)"></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Private Notes (trainer only) -->
        <div class="recap-private-note">
            <div class="recap-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="vertical-align:-1px;margin-right:4px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                Private Notes <span style="font-weight:400;color:var(--text-muted)">(only you see this)</span>
            </div>
            <textarea class="recap-textarea" id="recapPrivateNotes" placeholder="Internal notes about this session..." style="min-height:48px;background:#FFFBEB;border-color:#FDE68A"></textarea>
        </div>
        
        <button class="recap-send" id="recapSendBtn" onclick="sendRecap()">Send Training Plan</button>
        <?php if (class_exists('PTP_AI_Assistant') && PTP_AI_Assistant::is_available()): ?>
        <button type="button" id="recapAiBtn" onclick="aiDraftRecap()" style="width:100%;padding:12px;margin-top:8px;background:transparent;border:1.5px solid #8B5CF6;color:#8B5CF6;border-radius:var(--radius-md);font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;min-height:44px;touch-action:manipulation;-webkit-tap-highlight-color:transparent">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            Draft with AI
        </button>
        <?php endif; ?>
        <div class="recap-send-sub">Parent will receive a styled email with skills, recap, and homework</div>
    </div>
</div>

<!-- Payout Sheet -->
<div class="sheet-overlay" id="payoutSheetOverlay" onclick="closePayoutSheet()"></div>
<div class="sheet" id="payoutSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head">
        <h3 class="sheet-title">Request Payout</h3>
        <button class="sheet-x" onclick="closePayoutSheet(true)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="sheet-body">
        <div class="earn-hero" style="padding:16px 0">
            <div class="earn-label">Available Balance</div>
            <div class="earn-amount">$<?php echo esc_html(number_format($earnings['pending'], 2)); ?></div>
        </div>
        <p class="text-muted text-center text-sm">Funds will transfer within 1–2 business days via Stripe.</p>
    </div>
    <div class="sheet-foot">
        <button type="button" class="btn btn-green btn-full" onclick="requestPayout()" id="payoutBtn">Confirm Payout</button>
    </div>
</div>

<!-- Block Date Sheet -->
<div class="sheet-overlay" id="blockDateSheetOverlay" onclick="closeBlockDateSheet()"></div>
<div class="sheet" id="blockDateSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head">
        <h3 class="sheet-title">Block a Date</h3>
        <button class="sheet-x" onclick="closeBlockDateSheet(true)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="sheet-body">
        <div class="form-group">
            <label class="form-label">Date</label>
            <input type="date" id="blockDate" class="form-input" min="<?php echo esc_attr(date('Y-m-d')); ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Reason (optional)</label>
            <input type="text" id="blockReason" class="form-input" placeholder="e.g., Vacation, game day">
        </div>
    </div>
    <div class="sheet-foot">
        <button class="btn btn-full" onclick="saveBlockedDate()">Block This Date</button>
    </div>
</div>

<!-- ═══ JAVASCRIPT ═══ -->
<script>
var CONFIG = {
    ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
    nonce: '<?php echo esc_js($nonce); ?>',
    nonceGeneral: '<?php echo esc_js($nonce_general); ?>',
    nonceMentorship: '<?php echo esc_js($nonce_general); ?>',
    noncePhoto: '<?php echo esc_js(wp_create_nonce('ptp_photo_upload')); ?>',
    trainerId: <?php echo intval($trainer_id); ?>,
    aiEnabled: <?php echo (class_exists('PTP_AI_Assistant') && PTP_AI_Assistant::is_available()) ? 'true' : 'false'; ?>,
    onboardingUrl: '<?php echo esc_url(home_url('/trainer-onboarding/?edit=1')); ?>',
    messagesUrl: '<?php echo esc_url(home_url('/messages/')); ?>',
    logoutUrl: '<?php echo esc_url(wp_logout_url(home_url())); ?>',
    mentorTabActive: <?php echo $mentor_tab_active ? 'true' : 'false'; ?>,
    googleMapsKey: '<?php echo esc_attr($google_maps_key ?? ''); ?>'
};
</script>
<script src="<?php echo esc_url(PTP_PLUGIN_URL . 'assets/js/trainer-dashboard-v200.js'); ?>"></script>
<!-- v135: Reschedule Modal -->
<div id="reschedule-modal" class="resched-overlay" onclick="if(event.target===this)closeRescheduleModal()">
    <div class="resched-sheet">
        <div class="resched-header">
            <h3>Reschedule Session</h3>
            <button class="resched-close" onclick="closeRescheduleModal()">&times;</button>
        </div>
        <div class="resched-content" id="reschedule-body"></div>
    </div>
</div>

<!-- SESSION PLAYBOOK OVERLAY -->
<div id="endSessionOverlay" style="display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,0.65);backdrop-filter:blur(4px);justify-content:center;align-items:flex-end;overflow-y:auto">
<div style="width:100%;max-width:520px;background:#fff;border-radius:20px 20px 0 0;padding:24px 20px 40px;max-height:92vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px">
        <div>
            <div style="font-size:9px;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--gold)">Post-Session</div>
            <h2 style="font-family:Oswald,sans-serif;font-size:22px;font-weight:700;text-transform:uppercase;margin-top:2px">Complete the Session</h2>
        </div>
        <button onclick="closeEndSession()" style="width:44px;height:44px;border-radius:50%;border:1px solid #E5E5E3;background:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;touch-action:manipulation">&times;</button>
    </div>
    <p style="font-size:13px;color:#737373;margin-bottom:20px;line-height:1.5">This takes 2 minutes. The parent summary goes directly to the parent. Private notes stay with you.</p>

    <div id="endSessionError" style="display:none;background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 13px;font-size:12px;color:#991B1B;margin-bottom:14px"></div>

    <!-- Energy rating -->
    <div style="background:#F9F9F7;border-radius:10px;padding:14px;margin-bottom:16px">
        <div style="font-family:Oswald,sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#737373;margin-bottom:8px">Session Energy</div>
        <div style="display:flex;gap:10px;align-items:center">
            <div id="energyStars" style="display:flex;gap:4px">
                <span class="energy-star" onclick="setEnergyRating(1)" style="font-size:28px;cursor:pointer;color:#ccc;display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;touch-action:manipulation">&#9734;</span>
                <span class="energy-star" onclick="setEnergyRating(2)" style="font-size:28px;cursor:pointer;color:#ccc;display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;touch-action:manipulation">&#9734;</span>
                <span class="energy-star" onclick="setEnergyRating(3)" style="font-size:28px;cursor:pointer;color:#ccc;display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;touch-action:manipulation">&#9734;</span>
                <span class="energy-star" onclick="setEnergyRating(4)" style="font-size:28px;cursor:pointer;color:#ccc;display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;touch-action:manipulation">&#9734;</span>
                <span class="energy-star" onclick="setEnergyRating(5)" style="font-size:28px;cursor:pointer;color:#ccc;display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;touch-action:manipulation">&#9734;</span>
            </div>
            <span style="font-size:12px;color:#737373">How engaged were they?</span>
        </div>
        <input type="hidden" id="energyRatingVal" value="0">
    </div>

    <!-- Action item -->
    <div style="margin-bottom:16px">
        <label style="display:block;font-family:Oswald,sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#737373;margin-bottom:6px">
            This Week's Mission for <span id="endSessionPlayerName">the player</span> <span style="color:#EF4444">*</span>
        </label>
        <input id="endActionItem" type="text" placeholder='e.g. "Film 10 Cruyff turns at speed and send by Thursday"'
            style="width:100%;padding:12px;border:2px solid #E5E5E3;border-radius:8px;font-size:16px;font-family:Inter,sans-serif;outline:none"
            onfocus="this.style.borderColor='var(--gold)'" onblur="this.style.borderColor='#E5E5E3'">
        <p style="font-size:11px;color:#737373;margin-top:5px;line-height:1.4">One thing. Specific enough that they can't say they did it without actually doing it. Shown to the parent.</p>
    </div>

    <!-- Parent summary -->
    <div style="margin-bottom:16px">
        <label style="display:block;font-family:Oswald,sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#737373;margin-bottom:6px">
            What to tell the parent <span style="color:#EF4444">*</span>
        </label>
        <textarea id="endParentSummary" rows="4" placeholder="e.g. Great session today. Jake's first touch is really clicking — we focused on receiving under pressure and he nailed it by the end. He's getting more confident asking for the ball."
            style="width:100%;padding:12px;border:2px solid #E5E5E3;border-radius:8px;font-size:16px;font-family:Inter,sans-serif;resize:vertical;outline:none;line-height:1.5"
            onfocus="this.style.borderColor='var(--gold)'" onblur="this.style.borderColor='#E5E5E3'"></textarea>
        <p style="font-size:11px;color:#737373;margin-top:5px;line-height:1.4">Sent via email + shows in the parent's dashboard. Write it to the parent, not about the player. 2-3 sentences is perfect.</p>
    </div>

    <!-- Private notes -->
    <div style="margin-bottom:20px">
        <label style="display:block;font-family:Oswald,sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#737373;margin-bottom:6px">
            Private Notes <span style="font-weight:400;text-transform:none;letter-spacing:0;font-family:Inter,sans-serif">(only you see this)</span>
        </label>
        <textarea id="endPrivateNotes" rows="3" placeholder="Things to remember for next session, what to adjust, kid's mood, anything..."
            style="width:100%;padding:12px;border:2px solid #E5E5E3;border-radius:8px;font-size:16px;font-family:Inter,sans-serif;resize:vertical;outline:none;background:#FAFAF8;line-height:1.5"
            onfocus="this.style.borderColor='var(--gold)'" onblur="this.style.borderColor='#E5E5E3'"></textarea>
    </div>

    <?php if (class_exists('PTP_AI_Assistant') && PTP_AI_Assistant::is_available()): ?>
    <button type="button" id="endSessionAiBtn" onclick="aiDraftMentorship()" style="width:100%;padding:12px;margin-bottom:10px;background:transparent;border:1.5px solid #8B5CF6;color:#8B5CF6;border-radius:10px;font-family:Oswald,sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;min-height:44px;touch-action:manipulation;-webkit-tap-highlight-color:transparent">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        Draft with AI
    </button>
    <?php endif; ?>

    <button id="endSessionSubmitBtn" onclick="submitEndSession()" style="width:100%;padding:15px;background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;border:none;border-radius:10px;cursor:pointer;letter-spacing:.5px">
        Complete Session &amp; Email Parent
    </button>
    <p style="text-align:center;font-size:11px;color:#A3A3A3;margin-top:10px">The parent summary + action item will be emailed immediately</p>
</div>
</div>

<div id="playbookOverlay" style="display:none;position:fixed;inset:0;z-index:400;background:rgba(0,0,0,0.6);backdrop-filter:blur(4px);justify-content:center;align-items:flex-start;overflow-y:auto;padding:20px">
<div style="width:100%;max-width:560px;background:#fff;border-radius:20px;margin:20px auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,#0A0A0A,#1A1A1A);padding:24px 20px 20px;color:#fff;position:sticky;top:0;z-index:1">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:2px;color:#FCB900;font-family:Oswald,sans-serif">PTP Mentorship</div>
                <h2 style="font-family:Oswald,sans-serif;font-size:24px;text-transform:uppercase;margin-top:4px;color:#fff">Session Playbook</h2>
            </div>
            <button onclick="closePlaybook()" style="width:44px;height:44px;border-radius:50%;border:1px solid rgba(255,255,255,0.2);background:transparent;color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;touch-action:manipulation">&times;</button>
        </div>
        <p style="font-size:13px;color:#A3A3A3;margin-top:8px;line-height:1.5">Your framework for running sessions that keep families subscribed. The relationship is the product.</p>
    </div>

    <div style="padding:20px">

        <!-- GOLDEN RULE -->
        <div style="background:#FFFBEB;border:2px solid #FCB900;border-radius:14px;padding:18px;margin-bottom:20px">
            <div style="font-family:Oswald,sans-serif;font-weight:700;font-size:14px;text-transform:uppercase;color:#92400E;margin-bottom:6px">The Golden Rule</div>
            <p style="font-size:14px;color:#78350F;line-height:1.6;margin:0">Every session should end with the kid feeling <strong>seen</strong>. Not evaluated. Not corrected. Seen. Call them by name. Reference something specific to them. That's the moment parents pay for.</p>
        </div>

        <!-- BEFORE EVERY SESSION -->
        <div style="margin-bottom:24px">
            <h3 style="font-family:Oswald,sans-serif;font-size:16px;text-transform:uppercase;margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #0A0A0A">Before Every Session</h3>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:14px;line-height:1.6;color:#333">
                <div style="display:flex;gap:10px"><span style="color:#FCB900;font-weight:700;flex-shrink:0">1.</span><span>Review each kid's goals and recent videos (2 min)</span></div>
                <div style="display:flex;gap:10px"><span style="color:#FCB900;font-weight:700;flex-shrink:0">2.</span><span>Prepare one specific callout per kid to drop mid-session</span></div>
                <div style="display:flex;gap:10px"><span style="color:#FCB900;font-weight:700;flex-shrink:0">3.</span><span>Test your camera, mic, and the Jitsi room link</span></div>
                <div style="display:flex;gap:10px"><span style="color:#FCB900;font-weight:700;flex-shrink:0">4.</span><span>Be there 2 min early. Kids joining on time = you're already live</span></div>
            </div>
        </div>

        <!-- GROUP HUDDLE -->
        <div style="margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #0A0A0A">
                <span style="background:#525252;color:#fff;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;font-family:Oswald,sans-serif">Huddle</span>
                <h3 style="font-family:Oswald,sans-serif;font-size:16px;text-transform:uppercase">Group Huddle (30-45 min)</h3>
            </div>
            <p style="font-size:13px;color:#666;margin-bottom:12px;line-height:1.5">The backbone of mentorship. Your group builds a team identity. Kids motivate each other. Parents see their kid is part of something.</p>

            <div style="background:#F8F8F6;border-radius:12px;padding:16px;margin-bottom:8px">
                <div style="font-family:Oswald,sans-serif;font-weight:700;font-size:13px;text-transform:uppercase;margin-bottom:8px;color:#0A0A0A">The Flow</div>
                <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;color:#444;line-height:1.5">
                    <div style="display:flex;gap:10px">
                        <div style="background:#FCB900;color:#0A0A0A;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0">1</div>
                        <div><strong>Check-in (5 min)</strong> &mdash; Go around. Each kid says their name, what they worked on this week, and one thing they're proud of. You react. "Yo Marcus, you finally hit that move? Let's see that this week."</div>
                    </div>
                    <div style="display:flex;gap:10px">
                        <div style="background:#FCB900;color:#0A0A0A;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0">2</div>
                        <div><strong>Film Study (10 min)</strong> &mdash; Screen share one kid's video (rotate weekly). Pause, draw on screen, explain what you see. "Watch your hips here &mdash; you're facing the sideline. Open up to the field." Get other kids to spot the fix.</div>
                    </div>
                    <div style="display:flex;gap:10px">
                        <div style="background:#FCB900;color:#0A0A0A;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0">3</div>
                        <div><strong>Skill Focus (10 min)</strong> &mdash; One skill. Demo on camera (stand up, show it). Explain the "why" and the "when." Make it a challenge: "I want everyone to try this 20 times before next week and DM me the video."</div>
                    </div>
                    <div style="display:flex;gap:10px">
                        <div style="background:#FCB900;color:#0A0A0A;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0">4</div>
                        <div><strong>Challenge Review (5 min)</strong> &mdash; Show who crushed this month's challenge. Hype them up. If no one submitted, call it out with a smile. "Come on, I know y'all can do better than that."</div>
                    </div>
                    <div style="display:flex;gap:10px">
                        <div style="background:#FCB900;color:#0A0A0A;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0">5</div>
                        <div><strong>Q&A + Close (5 min)</strong> &mdash; Open the floor. Let kids ask about tryouts, positions, what it's like playing in college/pros. End with one clear thing to work on. "This week, one thing: first touch with the outside of the foot. That's it."</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 1:1 SESSION -->
        <div style="margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #0A0A0A">
                <span style="background:#0A0A0A;color:#fff;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;font-family:Oswald,sans-serif">Elite</span>
                <h3 style="font-family:Oswald,sans-serif;font-size:16px;text-transform:uppercase">1:1 Deep Dive (30-45 min)</h3>
            </div>
            <p style="font-size:13px;color:#666;margin-bottom:12px;line-height:1.5">This is the premium experience. The kid gets your full attention. Parents pay $<?php echo esc_html($mentor_pkgs['elite']['per_session_display'] ?? 89); ?>/session for Elite because their kid walks away thinking "my coach knows ME."</p>

            <div style="background:#F8F8F6;border-radius:12px;padding:16px;margin-bottom:8px">
                <div style="font-family:Oswald,sans-serif;font-weight:700;font-size:13px;text-transform:uppercase;margin-bottom:8px;color:#0A0A0A">The Flow</div>
                <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;color:#444;line-height:1.5">
                    <div style="display:flex;gap:10px">
                        <div style="background:#0A0A0A;color:#FCB900;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0">1</div>
                        <div><strong>Vibe Check (5 min)</strong> &mdash; How's your week? How's school? Did you play this weekend? What happened? Build rapport first. If they had a bad game, acknowledge it. Don't skip to tactics.</div>
                    </div>
                    <div style="display:flex;gap:10px">
                        <div style="background:#0A0A0A;color:#FCB900;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0">2</div>
                        <div><strong>Video Review (10-15 min)</strong> &mdash; Pull up their recent submission. Go frame by frame. "See this moment? You have time here. You're rushing because you don't trust your first touch yet. That's exactly what we're building."</div>
                    </div>
                    <div style="display:flex;gap:10px">
                        <div style="background:#0A0A0A;color:#FCB900;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0">3</div>
                        <div><strong>Goal Progress (5 min)</strong> &mdash; Pull up their goals on-screen. What's moving? What's stuck? Adjust the plan if needed. "Your weak foot is improving &mdash; let's bump that to 3 of 5 and add receiving under pressure."</div>
                    </div>
                    <div style="display:flex;gap:10px">
                        <div style="background:#0A0A0A;color:#FCB900;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0">4</div>
                        <div><strong>Game Scenarios (10 min)</strong> &mdash; "OK you're playing right mid and the ball comes to you with a defender on your back shoulder. What's your first thought?" Walk through 2-3 situations from their position. Get them to think before they move.</div>
                    </div>
                    <div style="display:flex;gap:10px">
                        <div style="background:#0A0A0A;color:#FCB900;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0">5</div>
                        <div><strong>This Week's Mission (5 min)</strong> &mdash; One clear assignment. "Film yourself doing 10 Cruyff turns at speed and send it by Thursday." Specific. Measurable. Something they'll actually do.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- VIDEO REVIEW -->
        <div style="margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #0A0A0A">
                <span style="background:#FCB900;color:#0A0A0A;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;font-family:Oswald,sans-serif">Pro</span>
                <h3 style="font-family:Oswald,sans-serif;font-size:16px;text-transform:uppercase">Async Video Reviews</h3>
            </div>
            <p style="font-size:13px;color:#666;margin-bottom:12px;line-height:1.5">A kid sends a 30-60 second clip. You send back a voice note or annotated video. Total time: 3-5 min per review. This is the scalable income.</p>

            <div style="background:#F8F8F6;border-radius:12px;padding:16px;margin-bottom:8px">
                <div style="font-family:Oswald,sans-serif;font-weight:700;font-size:13px;text-transform:uppercase;margin-bottom:8px;color:#0A0A0A">Review Formula</div>
                <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;color:#444;line-height:1.5">
                    <div><strong style="color:#FCB900">1 thing they did well.</strong> Always lead positive. "Your body shape on that first touch was clean &mdash; you opened to the field."</div>
                    <div><strong style="color:#0A0A0A">1 thing to fix.</strong> Be specific with the WHY. "Your passing weight is short because you're hitting it with your toe. Lock the ankle, strike through the ball."</div>
                    <div><strong style="color:#22C55E">1 thing to try.</strong> Give them a drill or challenge. "Try this: set a cone 10 yards out, pass to it with your laces 20 times each foot. Film the last 5."</div>
                </div>
            </div>

            <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:12px;margin-top:8px">
                <p style="font-size:12px;color:#991B1B;margin:0;line-height:1.5"><strong>Don't:</strong> Write essays. Parents don't read them. A 60-second voice note with energy beats a paragraph every time.</p>
            </div>
        </div>

        <!-- TRYOUT PREP -->
        <div style="margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #0A0A0A">
                <span style="background:#0A0A0A;color:#fff;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;font-family:Oswald,sans-serif">Elite</span>
                <h3 style="font-family:Oswald,sans-serif;font-size:16px;text-transform:uppercase">Tryout Prep Sessions</h3>
            </div>
            <p style="font-size:13px;color:#666;margin-bottom:12px;line-height:1.5">The highest-value call you can offer. Parents will literally upgrade to Elite for this. Run these 2-4 weeks before club tryouts.</p>

            <div style="background:#F8F8F6;border-radius:12px;padding:16px">
                <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;color:#444;line-height:1.5">
                    <div><strong>What coaches are looking for</strong> &mdash; Explain what evaluators actually watch at their age group. First touch. Composure. Willingness to receive the ball. Not fancy skills.</div>
                    <div><strong>Mental game</strong> &mdash; "First 5 minutes, play simple. Don't try to do too much. Show you're coachable. If you make a mistake, get the ball back immediately."</div>
                    <div><strong>Position-specific</strong> &mdash; Walk through what's expected at their position. "As a center mid, they want to see you check your shoulder before receiving. Do it every single time."</div>
                    <div><strong>Mock scenarios</strong> &mdash; "OK tryout drill: 4v4 to small goals. What's your first instinct? Show me your body language when you don't get the ball."</div>
                </div>
            </div>
        </div>

        <!-- RETENTION -->
        <div style="margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #0A0A0A">
                <h3 style="font-family:Oswald,sans-serif;font-size:16px;text-transform:uppercase">Keeping Families Subscribed</h3>
            </div>
            <div style="display:flex;flex-direction:column;gap:10px;font-size:13px;color:#444;line-height:1.5">
                <div style="display:flex;gap:10px">
                    <span style="font-size:18px;flex-shrink:0">&#127942;</span>
                    <div><strong>Celebrate milestones.</strong> When a kid hits a goal, make a big deal. Award the badge from your dashboard. Parents screenshot these and share with family.</div>
                </div>
                <div style="display:flex;gap:10px">
                    <span style="font-size:18px;flex-shrink:0">&#128172;</span>
                    <div><strong>Message between sessions.</strong> "Hey Jake, saw your team won 3-0 this weekend. Did you get any assists?" Two sentences. Takes 15 seconds. That's the moment parents buy another package.</div>
                </div>
                <div style="display:flex;gap:10px">
                    <span style="font-size:18px;flex-shrink:0">&#128197;</span>
                    <div><strong>Schedule consistently.</strong> Same day, same time, every week. "Tuesday Huddle at 7pm" becomes a habit. Habits don't get cancelled.</div>
                </div>
                <div style="display:flex;gap:10px">
                    <span style="font-size:18px;flex-shrink:0">&#127775;</span>
                    <div><strong>Name-drop in sessions.</strong> "Sarah, remember last week when you struggled with that turn? Look at this week's video &mdash; you nailed it." That's the moment parents renew.</div>
                </div>
            </div>
        </div>

        <!-- TECH TIPS -->
        <div style="margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #0A0A0A">
                <h3 style="font-family:Oswald,sans-serif;font-size:16px;text-transform:uppercase">Tech Tips</h3>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;font-size:13px;color:#444;line-height:1.5">
                <div><strong>Video rooms</strong> auto-generate when you schedule. No Zoom account needed. Works in the browser.</div>
                <div><strong>Screen share</strong> to review videos live. Pull up the kid's submission, pause, draw on screen.</div>
                <div><strong>Phone works fine.</strong> Prop it up, good lighting, be energetic. Kids don't care about production quality.</div>
                <div><strong>Record sessions</strong> for kids who couldn't make it. Upload the link in your dashboard notes.</div>
                <div><strong>Batch your video reviews.</strong> Set aside 30 min on one day. Review all pending videos back-to-back. Don't spread them across the week.</div>
            </div>
        </div>
    </div>

    <!-- Close button -->
    <div style="padding:0 20px 24px">
        <button onclick="closePlaybook()" style="width:100%;padding:14px;background:#0A0A0A;color:#FCB900;border:none;border-radius:12px;font-family:Oswald,sans-serif;font-weight:700;font-size:14px;text-transform:uppercase;cursor:pointer">Got It</button>
    </div>
</div>
</div>

<!-- ═══ Schedule Session Modal ═══ -->
<div id="scheduleSessionModal" style="display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,0.7);justify-content:center;align-items:flex-end">
<div style="background:#111;border-radius:20px 20px 0 0;padding:24px 20px 32px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;box-sizing:border-box">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <div>
            <div style="font-family:Oswald,sans-serif;font-size:10px;color:#FCB900;text-transform:uppercase;letter-spacing:2px;margin-bottom:3px">New Session</div>
            <h3 style="font-family:Oswald,sans-serif;font-size:20px;text-transform:uppercase;color:#fff;margin:0">Schedule Session</h3>
        </div>
        <button onclick="closeScheduleModal()" style="background:transparent;border:1px solid #333;color:#fff;width:44px;height:44px;border-radius:50%;cursor:pointer;font-size:18px;flex-shrink:0;touch-action:manipulation">&times;</button>
    </div>

    <!-- Type -->
    <div style="margin-bottom:16px">
        <div style="font-family:Oswald,sans-serif;font-size:10px;color:#737373;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Session Type</div>
        <select id="sched-type" style="width:100%;padding:12px;background:#1a1a1a;border:1px solid #333;border-radius:10px;color:#fff;font-size:16px;box-sizing:border-box">
            <option value="group">Group Huddle</option>
            <option value="one_on_one">1:1 Session</option>
            <option value="film_breakdown">Film Breakdown</option>
            <option value="intro">Intro Call</option>
        </select>
    </div>

    <!-- Date + Time -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
        <div>
            <div style="font-family:Oswald,sans-serif;font-size:10px;color:#737373;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Date</div>
            <input type="date" id="sched-date" style="width:100%;padding:12px;background:#1a1a1a;border:1px solid #333;border-radius:10px;color:#fff;font-size:16px;box-sizing:border-box">
        </div>
        <div>
            <div style="font-family:Oswald,sans-serif;font-size:10px;color:#737373;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Time</div>
            <input type="time" id="sched-time" style="width:100%;padding:12px;background:#1a1a1a;border:1px solid #333;border-radius:10px;color:#fff;font-size:16px;box-sizing:border-box">
        </div>
    </div>

    <!-- Duration -->
    <div style="margin-bottom:16px">
        <div style="font-family:Oswald,sans-serif;font-size:10px;color:#737373;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Duration</div>
        <div style="display:flex;gap:8px">
            <?php foreach (array(30, 45, 60) as $dur): ?>
            <label style="flex:1;text-align:center;padding:10px 4px;border:1px solid #333;border-radius:8px;cursor:pointer;font-family:Oswald,sans-serif;font-size:12px;text-transform:uppercase;color:#888;display:block">
                <input type="radio" name="sched-dur-radio" value="<?php echo $dur; ?>" style="display:none" <?php echo $dur === 45 ? 'checked' : ''; ?>> <?php echo $dur; ?> min
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Zoom note -->
    <div style="background:#FCB90015;border:1px solid #FCB90040;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:11px;color:#FCB900">
        <?php
        $zoom_configured = !empty(get_option('ptp_settings', array())['zoom_account_id']);
        $zoom_email_set  = !empty($current_trainer->zoom_email ?? '');
        if ($zoom_configured && $zoom_email_set): ?>
        Zoom meeting will be created automatically.
        <?php elseif (!$zoom_configured): ?>
        Zoom not configured. Add credentials in Settings → Zoom to auto-create rooms. A Jitsi room will be generated instead.
        <?php else: ?>
        Add your Zoom email in Profile to auto-create Zoom rooms.
        <?php endif; ?>
    </div>

    <button id="scheduleSubmitBtn" onclick="submitScheduleSession()" style="width:100%;padding:14px;background:#FCB900;color:#0A0A0A;border:none;border-radius:12px;font-family:Oswald,sans-serif;font-weight:700;font-size:14px;text-transform:uppercase;cursor:pointer">
        Schedule Session
    </button>
</div>
</div>

<!-- Style duration radio buttons via JS -->


<!-- ═══ DASHBOARD TOUR ═══ -->
<div id="tourOverlay" style="display:none;position:fixed;inset:0;z-index:400">
    <div id="tourBackdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.75);transition:opacity .3s"></div>
    <div id="tourSpotlight" style="position:absolute;border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,0.75);transition:all .35s ease;z-index:1;pointer-events:none"></div>
    <div id="tourCard" style="position:absolute;z-index:2;background:#111;border:2px solid var(--gold);border-radius:16px;padding:20px;width:min(320px,88vw);box-shadow:0 12px 40px rgba(0,0,0,0.6);transition:all .35s ease">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <div id="tourStep" style="font-family:Oswald,sans-serif;font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--gold)"></div>
            <button onclick="endDashTour()" style="background:none;border:none;color:#666;font-size:18px;cursor:pointer;padding:0;line-height:1">&times;</button>
        </div>
        <h4 id="tourTitle" style="font-family:Oswald,sans-serif;font-size:18px;text-transform:uppercase;color:#fff;margin-bottom:8px"></h4>
        <p id="tourDesc" style="font-size:13px;color:#A3A3A3;line-height:1.6;margin-bottom:16px"></p>
        <div style="display:flex;gap:8px;justify-content:space-between;align-items:center">
            <button id="tourBack" onclick="tourPrev()" style="padding:8px 16px;background:transparent;border:1px solid #333;border-radius:8px;color:#888;font-family:Oswald,sans-serif;font-weight:700;font-size:12px;text-transform:uppercase;cursor:pointer">Back</button>
            <div id="tourDots" style="display:flex;gap:5px"></div>
            <button id="tourNext" onclick="tourNext()" style="padding:8px 20px;background:var(--gold);color:#000;border:none;border-radius:8px;font-family:Oswald,sans-serif;font-weight:700;font-size:12px;text-transform:uppercase;cursor:pointer">Next</button>
        </div>
    </div>
</div>


<?php
$dashboard_type = 'trainer';
include(dirname(__FILE__) . '/components/mentorship-components.php');
include(dirname(__FILE__) . '/components/mentorship-mentee-detail.php');
include(dirname(__FILE__) . '/components/mentorship-pair-chat.php');
?>
<?php wp_footer(); ?>
</body>
</html>
