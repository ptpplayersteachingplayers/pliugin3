<?php
/**
 * PTP Unified Player — Single player view across all systems
 * 
 * Bridges: ptp_players, ptp_camp_orders, ptp_bookings, ptp_mentorship_pairs,
 *          ptp_session_notes, ptp_mentorship_sessions, ptp_skill_assessments,
 *          ptp_mentorship_goals, ptp_mentorship_videos, ptp_mentorship_milestones
 * 
 * Usage:
 *   $player = PTP_Unified_Player::get( $player_id );
 *   $player = PTP_Unified_Player::get_by_parent( $parent_id );
 *   $journey = PTP_Unified_Player::get_journey( $player_id );
 *   $ltv     = PTP_Unified_Player::get_family_ltv( $parent_id );
 * 
 * @since v137
 */
defined('ABSPATH') || exit;

class PTP_Unified_Player {

    // ================================================================
    // GET PLAYER(S) BY PARENT
    // ================================================================
    public static function get_by_parent( $parent_id ) {
        global $wpdb;
        $parent_id = intval($parent_id);
        if (!$parent_id) return array();

        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d ORDER BY first_name ASC",
            $parent_id
        ));

        return array_map(function($p) { return self::enrich($p); }, $players);
    }

    // ================================================================
    // GET SINGLE PLAYER
    // ================================================================
    public static function get( $player_id ) {
        global $wpdb;
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d", intval($player_id)
        ));
        return $player ? self::enrich($player) : null;
    }

    // ================================================================
    // ENRICH PLAYER WITH CROSS-SYSTEM DATA
    // ================================================================
    private static function enrich( $player ) {
        global $wpdb;
        $pid = intval($player->id);
        $parent_id = intval($player->parent_id);

        // ── Camp history ──
        $player->camps = $wpdb->get_results($wpdb->prepare(
            "SELECT co.*, t.display_name as trainer_name 
             FROM {$wpdb->prefix}ptp_camp_orders co
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON co.trainer_id = t.id
             WHERE co.player_id = %d
             ORDER BY co.created_at DESC",
            $pid
        )) ?: array();
        $player->camp_count = count($player->camps);

        // ── Training bookings ──
        $player->bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.player_id = %d OR (b.parent_id = %d AND b.player_id IS NULL)
             ORDER BY b.created_at DESC",
            $pid, $parent_id
        )) ?: array();
        $player->booking_count = count($player->bookings);
        $player->completed_sessions = 0;
        foreach ($player->bookings as $b) {
            if (in_array($b->status, array('completed', 'confirmed'))) $player->completed_sessions++;
        }

        // ── Mentorship pairs ──
        $player->mentorship = $wpdb->get_results($wpdb->prepare(
            "SELECT mp.*, t.display_name as trainer_name, t.photo_url as trainer_photo
             FROM {$wpdb->prefix}ptp_mentorship_pairs mp
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON mp.trainer_id = t.id
             WHERE mp.player_id = %d
             ORDER BY mp.created_at DESC",
            $pid
        )) ?: array();
        $player->active_mentorship = null;
        foreach ($player->mentorship as $m) {
            if ($m->status === 'active') { $player->active_mentorship = $m; break; }
        }
        $player->has_mentorship = !empty($player->active_mentorship);

        // ── Goals (from mentorship) ──
        $player->goals = array();
        if ($player->active_mentorship) {
            $player->goals = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_mentorship_goals WHERE pair_id = %d ORDER BY status ASC, created_at DESC",
                $player->active_mentorship->id
            )) ?: array();
        }

        // ── Skill assessments (from training plans) ──
        $player->assessments = $wpdb->get_results($wpdb->prepare(
            "SELECT sa.*, t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_skill_assessments sa
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON sa.trainer_id = t.id
             WHERE sa.player_id = %d
             ORDER BY sa.assessment_date DESC",
            $pid
        )) ?: array();

        // ── Latest skill ratings ──
        $player->skill_snapshot = array();
        if (!empty($player->assessments)) {
            $latest = $player->assessments[0];
            $player->skill_snapshot = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_skill_ratings WHERE assessment_id = %d ORDER BY skill_category, skill_name",
                $latest->id
            )) ?: array();
        }

        // ── Training plans ──
        $player->training_plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_training_plans WHERE player_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT 1",
            $pid
        ));

        // ── Videos (mentorship) ──
        $player->videos = array();
        if ($player->active_mentorship) {
            $player->videos = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_mentorship_videos WHERE pair_id = %d ORDER BY created_at DESC LIMIT 20",
                $player->active_mentorship->id
            )) ?: array();
        }

        // ── Milestones ──
        $player->milestones = array();
        if ($player->active_mentorship) {
            $player->milestones = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_mentorship_milestones WHERE pair_id = %d ORDER BY awarded_at DESC",
                $player->active_mentorship->id
            )) ?: array();
        }

        // ── Engagement score ──
        $player->engagement_score = self::calc_engagement($player);

        // ── Trainers worked with ──
        $trainer_ids = array();
        foreach ($player->bookings as $b) if ($b->trainer_id) $trainer_ids[$b->trainer_id] = true;
        foreach ($player->camps as $c) if (!empty($c->trainer_id)) $trainer_ids[$c->trainer_id] = true;
        foreach ($player->mentorship as $m) $trainer_ids[$m->trainer_id] = true;
        $player->trainer_ids = array_keys($trainer_ids);
        $player->trainer_count = count($player->trainer_ids);

        return $player;
    }

    // ================================================================
    // UNIFIED JOURNEY TIMELINE
    // Returns all events for a player in chronological order
    // ================================================================
    public static function get_journey( $player_id ) {
        global $wpdb;
        $pid = intval($player_id);
        $events = array();

        // Camps
        $camps = $wpdb->get_results($wpdb->prepare(
            "SELECT 'camp' as event_type, co.id, co.created_at as event_date, 
                    CONCAT('Camp: ', COALESCE(co.camp_name, 'PTP Camp')) as event_title,
                    t.display_name as trainer_name, co.status
             FROM {$wpdb->prefix}ptp_camp_orders co
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON co.trainer_id = t.id
             WHERE co.player_id = %d", $pid
        )) ?: array();
        foreach ($camps as $c) $events[] = $c;

        // Bookings
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT 'booking' as event_type, b.id, b.created_at as event_date,
                    CONCAT('1:1 Session with ', t.display_name) as event_title,
                    t.display_name as trainer_name, b.status
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.player_id = %d", $pid
        )) ?: array();
        foreach ($bookings as $b) $events[] = $b;

        // Mentorship milestones
        $m_events = $wpdb->get_results($wpdb->prepare(
            "SELECT 'mentorship_start' as event_type, mp.id, mp.started_at as event_date,
                    CONCAT('Mentorship started with ', t.display_name) as event_title,
                    t.display_name as trainer_name, mp.status
             FROM {$wpdb->prefix}ptp_mentorship_pairs mp
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON mp.trainer_id = t.id
             WHERE mp.player_id = %d AND mp.started_at IS NOT NULL", $pid
        )) ?: array();
        foreach ($m_events as $m) $events[] = $m;

        // Mentorship sessions
        $m_sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT 'mentorship_session' as event_type, s.id, s.scheduled_at as event_date,
                    CONCAT(UPPER(REPLACE(s.session_type, '_', ' ')), ': ', COALESCE(s.title, 'Session')) as event_title,
                    '' as trainer_name, s.status
             FROM {$wpdb->prefix}ptp_mentorship_sessions s
             JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
             JOIN {$wpdb->prefix}ptp_mentorship_pairs mp ON a.pair_id = mp.id
             WHERE mp.player_id = %d", $pid
        )) ?: array();
        foreach ($m_sessions as $ms) $events[] = $ms;

        // Skill assessments
        $assessments = $wpdb->get_results($wpdb->prepare(
            "SELECT 'assessment' as event_type, sa.id, sa.assessment_date as event_date,
                    CONCAT(UPPER(sa.assessment_type), ' Assessment') as event_title,
                    t.display_name as trainer_name, 'completed' as status
             FROM {$wpdb->prefix}ptp_skill_assessments sa
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON sa.trainer_id = t.id
             WHERE sa.player_id = %d", $pid
        )) ?: array();
        foreach ($assessments as $a) $events[] = $a;

        // Goal completions
        $goals = $wpdb->get_results($wpdb->prepare(
            "SELECT 'goal_completed' as event_type, g.id, g.completed_at as event_date,
                    CONCAT('Goal achieved: ', g.title) as event_title,
                    '' as trainer_name, 'completed' as status
             FROM {$wpdb->prefix}ptp_mentorship_goals g
             JOIN {$wpdb->prefix}ptp_mentorship_pairs mp ON g.pair_id = mp.id
             WHERE mp.player_id = %d AND g.status = 'completed' AND g.completed_at IS NOT NULL", $pid
        )) ?: array();
        foreach ($goals as $g) $events[] = $g;

        // Sort chronologically
        usort($events, function($a, $b) {
            $da = strtotime($a->event_date ?: '2020-01-01');
            $db = strtotime($b->event_date ?: '2020-01-01');
            return $da - $db;
        });

        return $events;
    }

    // ================================================================
    // FAMILY LIFETIME VALUE
    // ================================================================
    public static function get_family_ltv( $parent_id ) {
        global $wpdb;
        $parent_id = intval($parent_id);
        
        // Camp revenue
        $camp_rev = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}ptp_camp_orders WHERE parent_id = %d AND status != 'cancelled'",
            $parent_id
        )));

        // Training booking revenue
        $booking_rev = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM {$wpdb->prefix}ptp_bookings WHERE parent_id = %d AND status IN ('completed','confirmed')",
            $parent_id
        )));

        // Mentorship revenue (sessions_completed * per_session_price)
        $mentorship_rev = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(sessions_completed * per_session_price), 0) FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE parent_id = %d AND status NOT IN ('cancelled')",
            $parent_id
        )));

        return array(
            'camp'       => $camp_rev,
            'training'   => $booking_rev,
            'mentorship' => $mentorship_rev,
            'total'      => $camp_rev + $booking_rev + $mentorship_rev,
            'parent_id'  => $parent_id,
        );
    }

    // ================================================================
    // ENGAGEMENT SCORE (0-100)
    // Composite of sessions, goals, videos, attendance
    // ================================================================
    private static function calc_engagement( $player ) {
        $score = 0;
        $max = 0;

        // Sessions completed (weight: 30)
        $max += 30;
        if ($player->has_mentorship) {
            $pair = $player->active_mentorship;
            $pct = $pair->sessions_total > 0 ? ($pair->sessions_completed / $pair->sessions_total) : 0;
            $score += min(30, round($pct * 30));
        } elseif ($player->completed_sessions > 0) {
            $score += min(30, $player->completed_sessions * 5);
        }

        // Goals (weight: 20)
        $max += 20;
        $active_goals = 0; $completed_goals = 0;
        foreach ($player->goals as $g) {
            if ($g->status === 'completed') $completed_goals++;
            else $active_goals++;
        }
        if ($active_goals + $completed_goals > 0) {
            $score += min(20, ($completed_goals * 8) + ($active_goals * 3));
        }

        // Video submissions (weight: 20)
        $max += 20;
        $score += min(20, count($player->videos) * 5);

        // Skill assessments (weight: 15)
        $max += 15;
        $score += min(15, count($player->assessments) * 5);

        // Consistency - has training plan (weight: 15)
        $max += 15;
        if ($player->training_plan) $score += 8;
        if (!empty($player->milestones)) $score += min(7, count($player->milestones) * 2);

        return $max > 0 ? min(100, round(($score / $max) * 100)) : 0;
    }

    // ================================================================
    // UNIFIED SESSION NOTES — GET ALL NOTES FOR A PLAYER
    // Merges ptp_session_notes + mentorship trainer_notes
    // ================================================================
    public static function get_all_session_notes( $player_id ) {
        global $wpdb;
        $pid = intval($player_id);
        $notes = array();

        // Training session notes
        $training_notes = $wpdb->get_results($wpdb->prepare(
            "SELECT sn.*, 'training' as source, t.display_name as trainer_name,
                    b.session_date as session_date_alt
             FROM {$wpdb->prefix}ptp_session_notes sn
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON sn.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_bookings b ON sn.booking_id = b.id
             WHERE sn.player_id = %d
             ORDER BY sn.session_date DESC",
            $pid
        )) ?: array();

        foreach ($training_notes as $tn) {
            $notes[] = (object) array(
                'id'             => $tn->id,
                'source'         => 'training',
                'date'           => $tn->session_date ?: $tn->session_date_alt,
                'trainer_name'   => $tn->trainer_name,
                'focus'          => $tn->focus_worked_on,
                'notes'          => $tn->achievements . ($tn->areas_to_improve ? "\n\nImprove: " . $tn->areas_to_improve : ''),
                'action_item'    => $tn->homework,
                'parent_summary' => null,
                'effort'         => $tn->player_effort,
                'attitude'       => $tn->player_attitude,
                'visible'        => $tn->is_visible_to_parent,
            );
        }

        // Mentorship session notes
        $mentorship_notes = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.scheduled_at, s.trainer_notes, s.action_item, s.parent_summary,
                    s.energy_rating, s.session_type, s.title,
                    t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_mentorship_sessions s
             JOIN {$wpdb->prefix}ptp_mentorship_session_attendees a ON s.id = a.session_id
             JOIN {$wpdb->prefix}ptp_mentorship_pairs mp ON a.pair_id = mp.id
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON s.trainer_id = t.id
             WHERE mp.player_id = %d AND s.status = 'completed'
             ORDER BY s.scheduled_at DESC",
            $pid
        )) ?: array();

        foreach ($mentorship_notes as $mn) {
            $notes[] = (object) array(
                'id'             => $mn->id,
                'source'         => 'mentorship',
                'date'           => $mn->scheduled_at,
                'trainer_name'   => $mn->trainer_name,
                'focus'          => $mn->title ?: ucfirst(str_replace('_', ' ', $mn->session_type)),
                'notes'          => $mn->trainer_notes,
                'action_item'    => $mn->action_item,
                'parent_summary' => $mn->parent_summary,
                'effort'         => null,
                'attitude'       => null,
                'energy'         => $mn->energy_rating,
                'visible'        => true,
            );
        }

        // Sort all notes by date descending
        usort($notes, function($a, $b) {
            return strtotime($b->date ?: '2020-01-01') - strtotime($a->date ?: '2020-01-01');
        });

        return $notes;
    }

    // ================================================================
    // GET TRAINERS A PLAYER HAS WORKED WITH (w/ mentorship availability)
    // ================================================================
    public static function get_player_trainers( $player_id ) {
        global $wpdb;
        $pid = intval($player_id);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.display_name, t.photo_url, t.slug, t.mentorship_enabled,
                    t.mentorship_bio, t.hourly_rate, t.average_rating,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings b WHERE b.trainer_id = t.id AND b.player_id = %d AND b.status IN ('completed','confirmed')) as session_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_camp_orders co WHERE co.trainer_id = t.id AND co.player_id = %d) as camp_count,
                    (SELECT mp.id FROM {$wpdb->prefix}ptp_mentorship_pairs mp WHERE mp.trainer_id = t.id AND mp.player_id = %d AND mp.status NOT IN ('cancelled') LIMIT 1) as mentorship_pair_id,
                    (SELECT mp.status FROM {$wpdb->prefix}ptp_mentorship_pairs mp WHERE mp.trainer_id = t.id AND mp.player_id = %d AND mp.status NOT IN ('cancelled') LIMIT 1) as mentorship_status
             FROM {$wpdb->prefix}ptp_trainers t
             WHERE t.id IN (
                SELECT trainer_id FROM {$wpdb->prefix}ptp_bookings WHERE player_id = %d
                UNION
                SELECT trainer_id FROM {$wpdb->prefix}ptp_camp_orders WHERE player_id = %d AND trainer_id IS NOT NULL
                UNION
                SELECT trainer_id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE player_id = %d
             )
             ORDER BY (SELECT MAX(created_at) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = t.id AND player_id = %d) DESC",
            $pid, $pid, $pid, $pid, $pid, $pid, $pid, $pid
        )) ?: array();
    }

    // ================================================================
    // SHORTCODE: [ptp_player_profile player_id=X]
    // Renders the unified player profile card
    // ================================================================
    public static function render_profile_shortcode( $atts ) {
        $atts = shortcode_atts(array('player_id' => 0), $atts);
        $player = self::get(intval($atts['player_id']));
        if (!$player) return '<p>Player not found.</p>';

        ob_start();
        include PTP_PLUGIN_DIR . 'templates/components/unified-player-card.php';
        return ob_get_clean();
    }

    // ================================================================
    // INIT
    // ================================================================
    public static function init() {
        add_shortcode('ptp_player_profile', array(__CLASS__, 'render_profile_shortcode'));

        // Hook into mentorship session completion to create unified session note
        add_action('ptp_mentorship_session_recap_ready', array(__CLASS__, 'sync_mentorship_to_session_notes'), 20, 2);
    }

    // ================================================================
    // SYNC: When mentorship session completes, also write to ptp_session_notes
    // This bridges the two systems
    // ================================================================
    public static function sync_mentorship_to_session_notes( $session_id, $pair_id ) {
        global $wpdb;

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id = %d", intval($session_id)
        ));
        if (!$session || $session->status !== 'completed') return;

        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d", intval($pair_id)
        ));
        if (!$pair) return;

        // Check if already synced
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_session_notes WHERE booking_id = %d AND player_id = %d AND session_date = %s LIMIT 1",
            -1 * $session->id, // negative ID = mentorship session
            $pair->player_id,
            date('Y-m-d', strtotime($session->scheduled_at))
        ));
        if ($exists) return;

        $wpdb->insert(
            $wpdb->prefix . 'ptp_session_notes',
            array(
                'booking_id'       => -1 * $session->id, // negative = mentorship source
                'player_id'        => $pair->player_id,
                'trainer_id'       => $session->trainer_id,
                'plan_id'          => null,
                'session_date'     => date('Y-m-d', strtotime($session->scheduled_at)),
                'focus_worked_on'  => $session->title ?: ucfirst(str_replace('_', ' ', $session->session_type)),
                'drills_performed' => '',
                'achievements'     => $session->trainer_notes,
                'areas_to_improve' => '',
                'homework'         => $session->action_item,
                'player_effort'    => $session->energy_rating,
                'player_attitude'  => $session->energy_rating,
                'private_notes'    => '[mentorship_session:' . $session->id . '] ' . ($session->parent_summary ?: ''),
                'is_visible_to_parent' => 1,
                'created_at'       => current_time('mysql'),
            ),
            array('%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%d','%d','%s','%d','%s')
        );
    }
}
