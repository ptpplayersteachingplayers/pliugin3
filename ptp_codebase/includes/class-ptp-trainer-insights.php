<?php
/**
 * PTP Trainer Insights v200
 * 
 * Trainer-facing analytics: earnings trends, booking stats,
 * profile views, conversion rates, and performance metrics.
 * 
 * Accessed via AJAX from the trainer dashboard.
 */

defined('ABSPATH') || exit;

class PTP_Trainer_Insights {

    public static function init() {
        add_action('wp_ajax_ptp_get_trainer_insights', array(__CLASS__, 'ajax_get_insights'));
        add_action('wp_ajax_ptp_get_earnings_chart', array(__CLASS__, 'ajax_get_earnings_chart'));
    }

    /**
     * Get the current trainer ID for the logged-in user
     */
    private static function get_trainer_id() {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
    }

    /**
     * AJAX: Get full insights dashboard data
     */
    public static function ajax_get_insights() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $trainer_id = self::get_trainer_id();
        if (!$trainer_id) wp_send_json_error(array('message' => 'Trainer not found'));

        $period = sanitize_text_field($_POST['period'] ?? '30');

        $data = array(
            'earnings' => self::get_earnings_data($trainer_id, $period),
            'bookings' => self::get_booking_stats($trainer_id, $period),
            'profile' => self::get_profile_stats($trainer_id, $period),
            'trends' => self::get_trends($trainer_id, $period),
            'top_clients' => self::get_top_clients($trainer_id),
        );

        wp_send_json_success($data);
    }

    /**
     * AJAX: Get earnings chart data
     */
    public static function ajax_get_earnings_chart() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $trainer_id = self::get_trainer_id();
        if (!$trainer_id) wp_send_json_error();

        $period = intval($_POST['period'] ?? 30);
        $chart = self::get_daily_earnings($trainer_id, $period);

        wp_send_json_success(array('chart' => $chart));
    }

    /**
     * Earnings summary for period
     */
    public static function get_earnings_data($trainer_id, $days = 30) {
        global $wpdb;

        $period_start = date('Y-m-d', strtotime("-{$days} days"));
        $prev_start = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));

        // Current period
        $current = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COALESCE(SUM(trainer_payout), 0) as total,
                COUNT(*) as sessions,
                COALESCE(AVG(trainer_payout), 0) as avg_per_session
            FROM {$wpdb->prefix}ptp_bookings 
            WHERE trainer_id = %d AND status = 'completed' AND session_date >= %s
        ", $trainer_id, $period_start));

        // Previous period (for comparison)
        $previous = $wpdb->get_row($wpdb->prepare("
            SELECT COALESCE(SUM(trainer_payout), 0) as total, COUNT(*) as sessions
            FROM {$wpdb->prefix}ptp_bookings 
            WHERE trainer_id = %d AND status = 'completed' AND session_date >= %s AND session_date < %s
        ", $trainer_id, $prev_start, $period_start));

        $earnings_change = ($previous->total > 0) 
            ? round((($current->total - $previous->total) / $previous->total) * 100, 1)
            : ($current->total > 0 ? 100 : 0);

        $sessions_change = ($previous->sessions > 0) 
            ? round((($current->sessions - $previous->sessions) / $previous->sessions) * 100, 1)
            : ($current->sessions > 0 ? 100 : 0);

        // All time
        $all_time = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND status = 'completed'",
            $trainer_id
        ));

        // Pending payout
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'",
            $trainer_id
        ));

        return array(
            'period_total' => floatval($current->total),
            'period_sessions' => intval($current->sessions),
            'avg_per_session' => round(floatval($current->avg_per_session), 2),
            'earnings_change' => $earnings_change,
            'sessions_change' => $sessions_change,
            'all_time' => floatval($all_time),
            'pending_payout' => floatval($pending),
        );
    }

    /**
     * Booking statistics
     */
    public static function get_booking_stats($trainer_id, $days = 30) {
        global $wpdb;

        $period_start = date('Y-m-d', strtotime("-{$days} days"));

        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_shows,
                SUM(CASE WHEN status IN ('confirmed', 'pending') AND session_date >= CURDATE() THEN 1 ELSE 0 END) as upcoming
            FROM {$wpdb->prefix}ptp_bookings 
            WHERE trainer_id = %d AND created_at >= %s
        ", $trainer_id, $period_start));

        $completion_rate = ($stats->total > 0) 
            ? round(($stats->completed / $stats->total) * 100, 1) 
            : 0;

        $cancel_rate = ($stats->total > 0) 
            ? round(($stats->cancelled / $stats->total) * 100, 1) 
            : 0;

        // Unique clients in period
        $unique_clients = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT parent_id) 
            FROM {$wpdb->prefix}ptp_bookings 
            WHERE trainer_id = %d AND created_at >= %s AND status != 'cancelled'
        ", $trainer_id, $period_start));

        // Repeat clients (booked more than once)
        $repeat_clients = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM (
                SELECT parent_id, COUNT(*) as cnt 
                FROM {$wpdb->prefix}ptp_bookings 
                WHERE trainer_id = %d AND status IN ('completed', 'confirmed') 
                GROUP BY parent_id HAVING cnt > 1
            ) as repeats
        ", $trainer_id));

        return array(
            'total' => intval($stats->total),
            'completed' => intval($stats->completed),
            'cancelled' => intval($stats->cancelled),
            'no_shows' => intval($stats->no_shows),
            'upcoming' => intval($stats->upcoming),
            'completion_rate' => $completion_rate,
            'cancel_rate' => $cancel_rate,
            'unique_clients' => intval($unique_clients),
            'repeat_clients' => intval($repeat_clients),
        );
    }

    /**
     * Profile performance stats
     */
    public static function get_profile_stats($trainer_id, $days = 30) {
        global $wpdb;

        $period_start = date('Y-m-d', strtotime("-{$days} days"));
        $views_table = $wpdb->prefix . 'ptp_profile_views';

        $views = 0;
        $prev_views = 0;

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $views_table)) === $views_table) {
            $views = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(view_count), 0) FROM {$views_table} WHERE trainer_id = %d AND view_date >= %s",
                $trainer_id, $period_start
            )));

            $prev_start = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
            $prev_views = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(view_count), 0) FROM {$views_table} WHERE trainer_id = %d AND view_date >= %s AND view_date < %s",
                $trainer_id, $prev_start, $period_start
            )));
        }

        $views_change = ($prev_views > 0) 
            ? round((($views - $prev_views) / $prev_views) * 100, 1)
            : ($views > 0 ? 100 : 0);

        // Conversion rate: views → bookings
        $period_bookings = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND created_at >= %s",
            $trainer_id, $period_start
        )));

        $conversion_rate = ($views > 0) ? round(($period_bookings / $views) * 100, 1) : 0;

        // Rating
        $rating = $wpdb->get_row($wpdb->prepare(
            "SELECT AVG(rating) as avg_rating, COUNT(*) as count FROM {$wpdb->prefix}ptp_reviews WHERE trainer_id = %d AND is_published = 1",
            $trainer_id
        ));

        return array(
            'views' => $views,
            'views_change' => $views_change,
            'conversion_rate' => $conversion_rate,
            'avg_rating' => round(floatval($rating->avg_rating ?: 5.0), 1),
            'review_count' => intval($rating->count ?? 0),
        );
    }

    /**
     * Weekly trends for the chart
     */
    public static function get_trends($trainer_id, $days = 30) {
        global $wpdb;

        $period_start = date('Y-m-d', strtotime("-{$days} days"));

        // Weekly earnings
        $weekly = $wpdb->get_results($wpdb->prepare("
            SELECT 
                YEARWEEK(session_date, 1) as week,
                MIN(session_date) as week_start,
                COALESCE(SUM(trainer_payout), 0) as earnings,
                COUNT(*) as sessions
            FROM {$wpdb->prefix}ptp_bookings 
            WHERE trainer_id = %d AND status = 'completed' AND session_date >= %s
            GROUP BY YEARWEEK(session_date, 1)
            ORDER BY week ASC
        ", $trainer_id, $period_start));

        $trend_data = array();
        foreach ($weekly as $w) {
            $trend_data[] = array(
                'label' => date('M j', strtotime($w->week_start)),
                'earnings' => floatval($w->earnings),
                'sessions' => intval($w->sessions),
            );
        }

        return $trend_data;
    }

    /**
     * Daily earnings for chart
     */
    public static function get_daily_earnings($trainer_id, $days = 30) {
        global $wpdb;

        $period_start = date('Y-m-d', strtotime("-{$days} days"));

        $daily = $wpdb->get_results($wpdb->prepare("
            SELECT 
                session_date as date,
                COALESCE(SUM(trainer_payout), 0) as earnings,
                COUNT(*) as sessions
            FROM {$wpdb->prefix}ptp_bookings 
            WHERE trainer_id = %d AND status = 'completed' AND session_date >= %s
            GROUP BY session_date
            ORDER BY session_date ASC
        ", $trainer_id, $period_start));

        // Fill in missing dates
        $chart = array();
        $current = new DateTime($period_start);
        $end = new DateTime(date('Y-m-d'));
        $daily_map = array();

        foreach ($daily as $d) {
            $daily_map[$d->date] = array(
                'earnings' => floatval($d->earnings),
                'sessions' => intval($d->sessions),
            );
        }

        while ($current <= $end) {
            $date_str = $current->format('Y-m-d');
            $chart[] = array(
                'date' => $date_str,
                'label' => $current->format('M j'),
                'earnings' => $daily_map[$date_str]['earnings'] ?? 0,
                'sessions' => $daily_map[$date_str]['sessions'] ?? 0,
            );
            $current->modify('+1 day');
        }

        return $chart;
    }

    /**
     * Top clients by revenue
     */
    public static function get_top_clients($trainer_id, $limit = 5) {
        global $wpdb;

        $clients = $wpdb->get_results($wpdb->prepare("
            SELECT 
                pa.display_name as name,
                COUNT(*) as sessions,
                COALESCE(SUM(b.trainer_payout), 0) as total_earned,
                MAX(b.session_date) as last_session
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.trainer_id = %d AND b.status = 'completed'
            GROUP BY b.parent_id
            ORDER BY total_earned DESC
            LIMIT %d
        ", $trainer_id, $limit));

        return $clients ?: array();
    }
}
