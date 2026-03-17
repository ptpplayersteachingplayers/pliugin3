<?php
/**
 * PTP Mentorship Admin — Admin page, stats, trainer management
 * 
 * Handles:
 *  - WP Admin submenu page (mentorship pairs, status management)
 *  - Trainer mentorship toggle (enable/disable per trainer)
 *  - Admin stats AJAX
 *  - Trainer stats aggregation
 *  - Player mentorship lookup
 */

if (!defined('ABSPATH')) exit;

class PTP_Mentorship_Admin {

    public static function init() {
        add_action('wp_ajax_ptp_admin_mentorship_stats', array(__CLASS__, 'ajax_admin_stats'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
    }

    // ================================================================
    // ADMIN STATS AJAX
    // ================================================================
    public static function ajax_admin_stats() {
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $prefix = $wpdb->prefix;

        wp_send_json_success(array(
            'total_active'    => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}ptp_mentorship_pairs WHERE status = 'active'"),
            'total_interest'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}ptp_mentorship_pairs WHERE status = 'interest'"),
            'total_completed' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}ptp_mentorship_pairs WHERE status = 'completed'"),
            'total_cancelled' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}ptp_mentorship_pairs WHERE status = 'cancelled'"),
            'by_source'       => $wpdb->get_results("SELECT source, COUNT(*) as count FROM {$prefix}ptp_mentorship_pairs GROUP BY source"),
            'pending_videos'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}ptp_mentorship_videos WHERE status = 'pending'"),
            'sessions_done'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}ptp_mentorship_sessions WHERE status = 'completed'"),
        ));
    }

    // ================================================================
    // TRAINER STATS (used by trainer dashboard)
    // ================================================================
    public static function get_trainer_stats($trainer_id) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $pair_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(status = 'active') as active_mentees,
                SUM(status = 'interest') as pending_interest,
                SUM(status IN ('intro_scheduled','intro_done')) as pending_intros,
                SUM(status = 'active' AND package_type = 'single') as pkg_single,
                SUM(status = 'active' AND package_type = 'kickstart') as pkg_kickstart,
                SUM(status = 'active' AND package_type = 'development') as pkg_development,
                SUM(status = 'active' AND package_type = 'elite') as pkg_elite,
                SUM(CASE WHEN status = 'active' THEN per_session_price * (sessions_total - sessions_completed) * (1 - %f) / 100 ELSE 0 END) as remaining_revenue
             FROM {$prefix}ptp_mentorship_pairs WHERE trainer_id = %d",
            PTP_Mentorship::get_fee(), $trainer_id
        ));

        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT
                (SELECT COUNT(*) FROM {$prefix}ptp_mentorship_videos WHERE trainer_id = %d AND status = 'pending') as pending_videos,
                (SELECT COUNT(*) FROM {$prefix}ptp_mentorship_videos WHERE trainer_id = %d AND status = 'reviewed') as total_reviewed,
                (SELECT COUNT(*) FROM {$prefix}ptp_mentorship_sessions WHERE trainer_id = %d AND scheduled_at > NOW() AND status = 'scheduled') as upcoming_sessions",
            $trainer_id, $trainer_id, $trainer_id
        ));

        $challenge = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}ptp_mentorship_challenges WHERE trainer_id = %d AND status = 'active' LIMIT 1", $trainer_id
        ));

        return array(
            'active_mentees'    => (int)($pair_stats->active_mentees ?? 0),
            'pending_interest'  => (int)($pair_stats->pending_interest ?? 0),
            'pending_intros'    => (int)($pair_stats->pending_intros ?? 0),
            'by_package'        => array(
                'single'      => (int)($pair_stats->pkg_single ?? 0),
                'kickstart'   => (int)($pair_stats->pkg_kickstart ?? 0),
                'development' => (int)($pair_stats->pkg_development ?? 0),
                'elite'       => (int)($pair_stats->pkg_elite ?? 0),
            ),
            'remaining_revenue' => (float)($pair_stats->remaining_revenue ?? 0),
            'pending_videos'    => (int)($counts->pending_videos ?? 0),
            'total_reviewed'    => (int)($counts->total_reviewed ?? 0),
            'upcoming_sessions' => (int)($counts->upcoming_sessions ?? 0),
            'active_challenge'  => $challenge,
        );
    }

    // ================================================================
    // PLAYER MENTORSHIP LOOKUP
    // ================================================================
    public static function get_player_mentorship($player_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, t.display_name as trainer_name, t.photo_url as trainer_photo
             FROM {$wpdb->prefix}ptp_mentorship_pairs p
             JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
             WHERE p.player_id = %d AND p.status IN ('active','interest','intro_scheduled','intro_done')
             ORDER BY p.created_at DESC LIMIT 1",
            $player_id
        ));
    }

    // ================================================================
    // ADMIN MENU
    // ================================================================
    public static function admin_menu() {
        add_submenu_page('ptp-admin', 'Mentorship', 'Mentorship', 'manage_options', 'ptp-mentorship', array(__CLASS__, 'render_admin_page'));
    }

    // ================================================================
    // ADMIN PAGE
    // ================================================================
    public static function render_admin_page() {
        global $wpdb;

        // Handle manual status change
        if (!empty($_POST['ptp_change_pair_status']) && check_admin_referer('ptp_mentorship_admin')) {
            $pair_id    = intval($_POST['pair_id']);
            $new_status = sanitize_text_field($_POST['new_status']);
            $valid      = array('interest','intro_scheduled','intro_done','active','paused','completed','cancelled');
            if ($pair_id && in_array($new_status, $valid)) {
                $wpdb->update("{$wpdb->prefix}ptp_mentorship_pairs", array('status' => $new_status), array('id' => $pair_id));
                echo '<div class="notice notice-success"><p>Pair #' . $pair_id . ' status updated to <strong>' . esc_html($new_status) . '</strong>.</p></div>';
            }
        }

        // Handle mentorship enable/disable toggle
        if (!empty($_POST['ptp_toggle_mentorship']) && check_admin_referer('ptp_mentorship_admin')) {
            $toggle_id = intval($_POST['toggle_trainer_id']);
            $enable    = intval($_POST['toggle_enable']);
            if ($toggle_id) {
                $wpdb->update("{$wpdb->prefix}ptp_trainers", array('mentorship_enabled' => $enable), array('id' => $toggle_id));
                $tname = $wpdb->get_var($wpdb->prepare("SELECT display_name FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $toggle_id));
                echo '<div class="notice notice-success"><p>Mentorship <strong>' . ($enable ? 'enabled' : 'disabled') . '</strong> for ' . esc_html($tname) . '.</p></div>';
            }
        }

        // Handle bulk enable
        if (!empty($_POST['ptp_bulk_enable_mentorship']) && check_admin_referer('ptp_mentorship_admin')) {
            $count = $wpdb->query("UPDATE {$wpdb->prefix}ptp_trainers SET mentorship_enabled = 1 WHERE status = 'approved' AND mentorship_enabled = 0");
            echo '<div class="notice notice-success"><p>Mentorship enabled for <strong>' . intval($count) . '</strong> trainers.</p></div>';
        }

        // Stats
        $total_pairs    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs");
        $active_pairs   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE status='active'");
        $pending_videos = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_videos WHERE status='pending'");
        $interest_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE status='interest'");

        // Pairs list with filters
        $status_filter = sanitize_text_field($_GET['status_filter'] ?? '');
        $where = $status_filter ? $wpdb->prepare("WHERE mp.status = %s", $status_filter) : '';

        // v233 L3: Pagination (was LIMIT 200 with no offset)
        $per_page = 50;
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        $offset = ($current_page - 1) * $per_page;

        $filtered_total = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs mp {$where}
        ");
        $total_pages = max(1, ceil($filtered_total / $per_page));

        $pairs = $wpdb->get_results("
            SELECT mp.*,
                   t.display_name AS trainer_name,
                   pl.name AS player_name,
                   u.user_email AS parent_email_user
            FROM {$wpdb->prefix}ptp_mentorship_pairs mp
            LEFT JOIN {$wpdb->prefix}ptp_trainers t  ON mp.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl  ON mp.player_id  = pl.id
            LEFT JOIN {$wpdb->users} u               ON mp.parent_id  = u.ID
            {$where}
            ORDER BY mp.created_at DESC
            LIMIT {$per_page} OFFSET {$offset}
        ");

        $status_colors = array(
            'interest'       => '#8B5CF6',
            'intro_scheduled'=> '#3B82F6',
            'intro_done'     => '#F59E0B',
            'active'         => '#22C55E',
            'paused'         => '#F97316',
            'completed'      => '#6B7280',
            'cancelled'      => '#EF4444',
        );

        $all_statuses = array('interest','intro_scheduled','intro_done','active','paused','completed','cancelled');

        $trainers_all = $wpdb->get_results("SELECT id, display_name, status, mentorship_enabled FROM {$wpdb->prefix}ptp_trainers WHERE status='approved' ORDER BY display_name ASC");
        $enabled_count = 0;
        foreach ($trainers_all as $t) { if ($t->mentorship_enabled) $enabled_count++; }

        // Render
        include PTP_PLUGIN_DIR . 'templates/admin/mentorship-admin.php';
    }
}
