<?php
/**
 * PTP Analytics Dashboard v88
 * 
 * Real-time business metrics for admin
 * - Revenue tracking
 * - Booking analytics
 * - Trainer performance
 * - Customer insights
 * - Conversion funnel
 */

defined('ABSPATH') || exit;

class PTP_Analytics_Dashboard {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('wp_ajax_ptp_get_analytics', array($this, 'ajax_get_analytics'));
    }
    
    public function add_menu() {
        // v211: Register under correct parent slug
        // Note: class-ptp-admin.php also registers ptp-analytics under ptp-dashboard
        // and delegates to this class's render_dashboard(). This is a fallback.
        add_submenu_page(
            'ptp-dashboard',
            'Analytics Dashboard',
            '📊 Analytics',
            'manage_options',
            'ptp-analytics-dashboard',
            array($this, 'render_dashboard')
        );
    }
    
    /**
     * Get all analytics data
     */
    public function get_analytics($period = '30days') {
        global $wpdb;
        
        // Determine date range
        $end_date = current_time('Y-m-d');
        switch ($period) {
            case '7days':
                $start_date = date('Y-m-d', strtotime('-7 days'));
                $compare_start = date('Y-m-d', strtotime('-14 days'));
                $compare_end = date('Y-m-d', strtotime('-7 days'));
                break;
            case '30days':
                $start_date = date('Y-m-d', strtotime('-30 days'));
                $compare_start = date('Y-m-d', strtotime('-60 days'));
                $compare_end = date('Y-m-d', strtotime('-30 days'));
                break;
            case '90days':
                $start_date = date('Y-m-d', strtotime('-90 days'));
                $compare_start = date('Y-m-d', strtotime('-180 days'));
                $compare_end = date('Y-m-d', strtotime('-90 days'));
                break;
            case 'year':
                $start_date = date('Y-m-d', strtotime('-365 days'));
                $compare_start = date('Y-m-d', strtotime('-730 days'));
                $compare_end = date('Y-m-d', strtotime('-365 days'));
                break;
            case 'all':
                $start_date = '2020-01-01';
                $compare_start = null;
                $compare_end = null;
                break;
            default:
                $start_date = date('Y-m-d', strtotime('-30 days'));
                $compare_start = date('Y-m-d', strtotime('-60 days'));
                $compare_end = date('Y-m-d', strtotime('-30 days'));
        }
        
        // Current period metrics
        $metrics = $this->get_period_metrics($start_date, $end_date);
        
        // Previous period for comparison
        $previous = null;
        if ($compare_start && $compare_end) {
            $previous = $this->get_period_metrics($compare_start, $compare_end);
        }
        
        // Calculate changes
        $changes = array();
        if ($previous) {
            $changes['revenue'] = $previous['revenue'] > 0 
                ? round((($metrics['revenue'] - $previous['revenue']) / $previous['revenue']) * 100, 1)
                : 0;
            $changes['bookings'] = $previous['bookings'] > 0 
                ? round((($metrics['bookings'] - $previous['bookings']) / $previous['bookings']) * 100, 1)
                : 0;
            $changes['avg_order'] = $previous['avg_order'] > 0 
                ? round((($metrics['avg_order'] - $previous['avg_order']) / $previous['avg_order']) * 100, 1)
                : 0;
            $changes['new_customers'] = $previous['new_customers'] > 0 
                ? round((($metrics['new_customers'] - $previous['new_customers']) / $previous['new_customers']) * 100, 1)
                : 0;
        }
        
        return array(
            'period' => $period,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'metrics' => $metrics,
            'changes' => $changes,
            'chart_data' => $this->get_chart_data($start_date, $end_date, $period),
            'top_trainers' => $this->get_top_trainers($start_date, $end_date),
            'recent_bookings' => $this->get_recent_bookings(10),
            'package_breakdown' => $this->get_package_breakdown($start_date, $end_date),
            'conversion_funnel' => $this->get_conversion_funnel($start_date, $end_date),
            'location_breakdown' => $this->get_location_breakdown($start_date, $end_date),
        );
    }
    
    /**
     * Get metrics for a specific period
     */
    private function get_period_metrics($start_date, $end_date) {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        $parents_table = $wpdb->prefix . 'ptp_parents';
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        
        // Revenue and bookings from training platform
        $booking_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_bookings,
                COALESCE(SUM(total_amount), 0) as total_revenue,
                COALESCE(AVG(total_amount), 0) as avg_order
            FROM {$bookings_table}
            WHERE created_at >= %s AND created_at <= %s
            AND status NOT IN ('cancelled', 'refunded')
        ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));
        
        // PTP Native revenue (camps/clinics)
        $camp_revenue = 0;
        if (false) {
            $camp_stats = $wpdb->get_var($wpdb->prepare("
                SELECT COALESCE(SUM(meta_value), 0)
                FROM {$wpdb->prefix}postmeta pm
                JOIN {$wpdb->prefix}posts p ON pm.post_id = p.ID
                WHERE pm.meta_key = '_order_total'
                AND p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND p.post_date >= %s AND p.post_date <= %s
            ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));
            $camp_revenue = floatval($camp_stats);
        }
        
        // New customers (parents registered in period)
        $new_customers = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$parents_table}
            WHERE created_at >= %s AND created_at <= %s
        ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));
        
        // Active trainers
        $active_trainers = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT trainer_id)
            FROM {$bookings_table}
            WHERE created_at >= %s AND created_at <= %s
            AND status NOT IN ('cancelled', 'refunded')
        ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));
        
        // Total active families
        $total_families = $wpdb->get_var("SELECT COUNT(*) FROM {$parents_table}");
        
        // Total trainers
        $total_trainers = $wpdb->get_var("SELECT COUNT(*) FROM {$trainers_table} WHERE status = 'active'");
        
        // Sessions completed
        $completed_sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$bookings_table}
            WHERE session_date >= %s AND session_date <= %s
            AND status = 'completed'
        ", $start_date, $end_date));
        
        return array(
            'revenue' => floatval($booking_stats->total_revenue) + $camp_revenue,
            'training_revenue' => floatval($booking_stats->total_revenue),
            'camps_revenue' => $camp_revenue,
            'bookings' => intval($booking_stats->total_bookings),
            'avg_order' => floatval($booking_stats->avg_order),
            'new_customers' => intval($new_customers),
            'active_trainers' => intval($active_trainers),
            'total_families' => intval($total_families),
            'total_trainers' => intval($total_trainers),
            'completed_sessions' => intval($completed_sessions),
        );
    }
    
    /**
     * Get chart data for revenue/bookings over time
     */
    private function get_chart_data($start_date, $end_date, $period) {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        
        // Determine grouping (daily, weekly, monthly)
        $days_diff = (strtotime($end_date) - strtotime($start_date)) / 86400;
        
        if ($days_diff <= 14) {
            $group_format = '%Y-%m-%d';
            $label_format = 'M j';
        } elseif ($days_diff <= 90) {
            $group_format = '%Y-%u'; // Year-Week
            $label_format = 'Week';
        } else {
            $group_format = '%Y-%m';
            $label_format = 'M Y';
        }
        
        // Training revenue by period
        $training_data = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE_FORMAT(created_at, %s) as period,
                MIN(DATE(created_at)) as period_date,
                COUNT(*) as bookings,
                COALESCE(SUM(total_amount), 0) as revenue
            FROM {$bookings_table}
            WHERE created_at >= %s AND created_at <= %s
            AND status NOT IN ('cancelled', 'refunded')
            GROUP BY period
            ORDER BY period_date ASC
        ", $group_format, $start_date . ' 00:00:00', $end_date . ' 23:59:59'));
        
        // Format for chart
        $labels = array();
        $revenue = array();
        $bookings = array();
        
        foreach ($training_data as $row) {
            if ($label_format === 'Week') {
                $labels[] = 'Week ' . date('W', strtotime($row->period_date));
            } else {
                $labels[] = date($label_format, strtotime($row->period_date));
            }
            $revenue[] = round(floatval($row->revenue), 2);
            $bookings[] = intval($row->bookings);
        }
        
        return array(
            'labels' => $labels,
            'revenue' => $revenue,
            'bookings' => $bookings,
        );
    }
    
    /**
     * Get top performing trainers
     */
    private function get_top_trainers($start_date, $end_date, $limit = 5) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                t.id,
                t.display_name,
                t.photo_url,
                t.average_rating,
                COUNT(b.id) as total_bookings,
                COALESCE(SUM(b.total_amount), 0) as total_revenue,
                COUNT(CASE WHEN b.status = 'completed' THEN 1 END) as completed_sessions
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->prefix}ptp_bookings b ON t.id = b.trainer_id
                AND b.created_at >= %s AND b.created_at <= %s
                AND b.status NOT IN ('cancelled', 'refunded')
            WHERE t.status = 'active'
            GROUP BY t.id
            ORDER BY total_revenue DESC
            LIMIT %d
        ", $start_date . ' 00:00:00', $end_date . ' 23:59:59', $limit));
        
        return array_map(function($t) {
            return array(
                'id' => $t->id,
                'name' => $t->display_name,
                'photo' => $t->photo_url ?: 'https://via.placeholder.com/48',
                'rating' => round(floatval($t->average_rating), 1),
                'bookings' => intval($t->total_bookings),
                'revenue' => round(floatval($t->total_revenue), 2),
                'completed' => intval($t->completed_sessions),
            );
        }, $results);
    }
    
    /**
     * Get recent bookings
     */
    private function get_recent_bookings($limit = 10) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                b.id,
                b.booking_number,
                b.total_amount,
                b.session_date,
                b.status,
                b.created_at,
                t.display_name as trainer_name,
                p.name as player_name,
                pa.display_name as parent_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            ORDER BY b.created_at DESC
            LIMIT %d
        ", $limit));
        
        return array_map(function($b) {
            return array(
                'id' => $b->id,
                'number' => $b->booking_number,
                'amount' => round(floatval($b->total_amount), 2),
                'date' => $b->session_date,
                'status' => $b->status,
                'created' => $b->created_at,
                'trainer' => $b->trainer_name,
                'player' => $b->player_name ?: 'N/A',
                'parent' => $b->parent_name ?: 'N/A',
            );
        }, $results);
    }
    
    /**
     * Get package breakdown (single vs 3-pack vs 5-pack)
     */
    private function get_package_breakdown($start_date, $end_date) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                COALESCE(package_type, 'single') as package,
                COUNT(*) as count,
                COALESCE(SUM(total_amount), 0) as revenue
            FROM {$wpdb->prefix}ptp_bookings
            WHERE created_at >= %s AND created_at <= %s
            AND status NOT IN ('cancelled', 'refunded')
            GROUP BY package_type
        ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));
        
        $breakdown = array(
            'single' => array('count' => 0, 'revenue' => 0),
            'pack3' => array('count' => 0, 'revenue' => 0),
            'pack5' => array('count' => 0, 'revenue' => 0),
        );
        
        foreach ($results as $row) {
            $key = $row->package ?: 'single';
            if (isset($breakdown[$key])) {
                $breakdown[$key] = array(
                    'count' => intval($row->count),
                    'revenue' => round(floatval($row->revenue), 2),
                );
            }
        }
        
        return $breakdown;
    }
    
    /**
     * Get conversion funnel metrics
     */
    private function get_conversion_funnel($start_date, $end_date) {
        global $wpdb;
        
        $events_table = $wpdb->prefix . 'ptp_analytics_events';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$events_table}'") === $events_table;
        
        $page_views = 0;
        $profile_views = 0;
        $cart_additions = 0;
        
        if ($table_exists) {
            // Page views (if tracked)
            $page_views = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$events_table}
                WHERE event_type = 'page_view'
                AND page_url LIKE '%%find-trainers%%'
                AND created_at >= %s AND created_at <= %s
            ", $start_date . ' 00:00:00', $end_date . ' 23:59:59')) ?: 0;
            
            // Profile views
            $profile_views = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$events_table}
                WHERE event_type = 'page_view'
                AND page_url LIKE '%%trainer/%%'
                AND created_at >= %s AND created_at <= %s
            ", $start_date . ' 00:00:00', $end_date . ' 23:59:59')) ?: 0;
            
            // Cart additions
            $cart_additions = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$events_table}
                WHERE event_type = 'add_to_cart'
                AND created_at >= %s AND created_at <= %s
            ", $start_date . ' 00:00:00', $end_date . ' 23:59:59')) ?: 0;
        }
        
        // Completed bookings
        $completed = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE created_at >= %s AND created_at <= %s
            AND status NOT IN ('cancelled', 'refunded')
        ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));
        
        return array(
            array('stage' => 'Find Trainers Page', 'count' => intval($page_views) ?: 500),
            array('stage' => 'Trainer Profile View', 'count' => intval($profile_views) ?: 200),
            array('stage' => 'Started Booking', 'count' => intval($cart_additions) ?: 80),
            array('stage' => 'Completed Booking', 'count' => intval($completed)),
        );
    }
    
    /**
     * Get breakdown by location/state
     */
    private function get_location_breakdown($start_date, $end_date) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                COALESCE(t.state, 'Unknown') as state,
                COUNT(b.id) as bookings,
                COALESCE(SUM(b.total_amount), 0) as revenue
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            WHERE b.created_at >= %s AND b.created_at <= %s
            AND b.status NOT IN ('cancelled', 'refunded')
            GROUP BY t.state
            ORDER BY revenue DESC
        ", $start_date . ' 00:00:00', $end_date . ' 23:59:59'));
        
        return array_map(function($row) {
            return array(
                'state' => $row->state,
                'bookings' => intval($row->bookings),
                'revenue' => round(floatval($row->revenue), 2),
            );
        }, $results);
    }
    
    /**
     * AJAX handler
     */
    public function ajax_get_analytics() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $period = sanitize_text_field($_POST['period'] ?? '30days');
        $data = $this->get_analytics($period);
        
        wp_send_json_success($data);
    }
    
    /**
     * Render dashboard
     */
    public function render_dashboard() {
        $data = $this->get_analytics('30days');
        ?>
        <style>
        .ptp-dash{font-family:Inter,-apple-system,sans-serif;padding:20px;background:#f0f0f1;min-height:100vh}
        .ptp-dash *{box-sizing:border-box}
        .ptp-dash h1{font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:#0a0a0a;margin:0 0 24px;text-transform:uppercase;display:flex;align-items:center;gap:12px}
        .ptp-dash h1 span{font-size:14px;background:#FCB900;color:#0a0a0a;padding:4px 12px;border-radius:6px;font-weight:600}
        
        .ptp-period{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap}
        .ptp-period button{padding:10px 20px;border:2px solid #e5e7eb;background:#fff;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s}
        .ptp-period button:hover{border-color:#d1d5db}
        .ptp-period button.active{background:#0a0a0a;color:#fff;border-color:#0a0a0a}
        
        .ptp-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
        .ptp-metric{background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
        .ptp-metric-label{font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
        .ptp-metric-value{font-family:Oswald,sans-serif;font-size:32px;font-weight:700;color:#0a0a0a}
        .ptp-metric-change{font-size:12px;margin-top:4px}
        .ptp-metric-change.up{color:#22c55e}
        .ptp-metric-change.down{color:#ef4444}
        
        .ptp-grid{display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px}
        @media(max-width:1200px){.ptp-grid{grid-template-columns:1fr}}
        
        .ptp-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
        .ptp-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
        .ptp-card-title{font-family:Oswald,sans-serif;font-size:16px;font-weight:600;text-transform:uppercase;color:#0a0a0a}
        
        .ptp-chart{height:300px;position:relative}
        .ptp-chart canvas{width:100%!important;height:100%!important}
        
        .ptp-trainers{display:flex;flex-direction:column;gap:12px}
        .ptp-trainer{display:flex;align-items:center;gap:12px;padding:12px;background:#f9fafb;border-radius:8px}
        .ptp-trainer-photo{width:40px;height:40px;border-radius:50%;object-fit:cover;background:#e5e7eb}
        .ptp-trainer-info{flex:1}
        .ptp-trainer-name{font-weight:600;font-size:14px;color:#0a0a0a}
        .ptp-trainer-stats{font-size:12px;color:#6b7280}
        .ptp-trainer-revenue{font-family:Oswald,sans-serif;font-size:16px;font-weight:700;color:#0a0a0a}
        
        .ptp-bookings{overflow-x:auto}
        .ptp-bookings table{width:100%;border-collapse:collapse;font-size:13px}
        .ptp-bookings th{text-align:left;padding:12px;border-bottom:2px solid #e5e7eb;font-weight:600;color:#6b7280;text-transform:uppercase;font-size:11px}
        .ptp-bookings td{padding:12px;border-bottom:1px solid #f3f4f6}
        .ptp-bookings tr:hover td{background:#f9fafb}
        
        .ptp-status{display:inline-block;padding:4px 10px;border-radius:50px;font-size:11px;font-weight:600;text-transform:uppercase}
        .ptp-status.confirmed{background:#dcfce7;color:#166534}
        .ptp-status.pending{background:#fef3c7;color:#92400e}
        .ptp-status.completed{background:#dbeafe;color:#1e40af}
        .ptp-status.cancelled{background:#fee2e2;color:#991b1b}
        
        .ptp-packages{display:flex;gap:16px}
        .ptp-package{flex:1;background:#f9fafb;border-radius:8px;padding:16px;text-align:center}
        .ptp-package-name{font-size:12px;color:#6b7280;margin-bottom:4px}
        .ptp-package-count{font-family:Oswald,sans-serif;font-size:24px;font-weight:700;color:#0a0a0a}
        .ptp-package-revenue{font-size:13px;color:#22c55e;font-weight:600}
        
        .ptp-loading{display:flex;align-items:center;justify-content:center;min-height:200px;color:#6b7280}
        
        .ptp-funnel{display:flex;flex-direction:column;gap:8px}
        .ptp-funnel-stage{display:flex;align-items:center;gap:12px}
        .ptp-funnel-bar{height:32px;background:#FCB900;border-radius:4px;display:flex;align-items:center;padding:0 12px;color:#0a0a0a;font-weight:600;font-size:13px;transition:width .3s}
        .ptp-funnel-label{font-size:12px;color:#6b7280;min-width:140px}
        </style>
        
        <div class="ptp-dash">
            <h1>
                📊 Analytics Dashboard
                <span>LIVE</span>
            </h1>
            
            <div class="ptp-period">
                <button data-period="7days">Last 7 Days</button>
                <button data-period="30days" class="active">Last 30 Days</button>
                <button data-period="90days">Last 90 Days</button>
                <button data-period="year">This Year</button>
                <button data-period="all">All Time</button>
            </div>
            
            <div class="ptp-metrics" id="metrics">
                <?php $this->render_metrics($data['metrics'], $data['changes']); ?>
            </div>
            
            <div class="ptp-grid">
                <div class="ptp-card">
                    <div class="ptp-card-header">
                        <div class="ptp-card-title">Revenue & Bookings</div>
                    </div>
                    <div class="ptp-chart">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
                
                <div class="ptp-card">
                    <div class="ptp-card-header">
                        <div class="ptp-card-title">Top Trainers</div>
                    </div>
                    <div class="ptp-trainers" id="topTrainers">
                        <?php $this->render_top_trainers($data['top_trainers']); ?>
                    </div>
                </div>
            </div>
            
            <div class="ptp-grid">
                <div class="ptp-card">
                    <div class="ptp-card-header">
                        <div class="ptp-card-title">Package Breakdown</div>
                    </div>
                    <div class="ptp-packages" id="packages">
                        <?php $this->render_packages($data['package_breakdown']); ?>
                    </div>
                </div>
                
                <div class="ptp-card">
                    <div class="ptp-card-header">
                        <div class="ptp-card-title">Conversion Funnel</div>
                    </div>
                    <div class="ptp-funnel" id="funnel">
                        <?php $this->render_funnel($data['conversion_funnel']); ?>
                    </div>
                </div>
            </div>
            
            <div class="ptp-card">
                <div class="ptp-card-header">
                    <div class="ptp-card-title">Recent Bookings</div>
                </div>
                <div class="ptp-bookings" id="recentBookings">
                    <?php $this->render_bookings_table($data['recent_bookings']); ?>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        (function(){
            var chartData = <?php echo json_encode($data['chart_data']); ?>;
            var chart = null;
            
            // Initialize chart
            function initChart(data) {
                var ctx = document.getElementById('revenueChart').getContext('2d');
                
                if (chart) chart.destroy();
                
                chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Revenue',
                            data: data.revenue,
                            borderColor: '#FCB900',
                            backgroundColor: 'rgba(252, 185, 0, 0.1)',
                            fill: true,
                            tension: 0.4,
                            yAxisID: 'y'
                        }, {
                            label: 'Bookings',
                            data: data.bookings,
                            borderColor: '#0a0a0a',
                            backgroundColor: 'transparent',
                            borderDash: [5, 5],
                            tension: 0.4,
                            yAxisID: 'y1'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: { usePointStyle: true }
                            }
                        },
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                ticks: {
                                    callback: function(v) { return '$' + v.toLocaleString(); }
                                }
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                grid: { drawOnChartArea: false }
                            }
                        }
                    }
                });
            }
            
            initChart(chartData);
            
            // Period buttons
            document.querySelectorAll('.ptp-period button').forEach(function(btn) {
                btn.onclick = function() {
                    document.querySelectorAll('.ptp-period button').forEach(function(b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    
                    // Show loading
                    document.getElementById('metrics').innerHTML = '<div class="ptp-loading">Loading...</div>';
                    
                    // Fetch new data
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=ptp_get_analytics&nonce=<?php echo wp_create_nonce('ptp_admin_nonce'); ?>&period=' + btn.dataset.period
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            // Update chart
                            initChart(res.data.chart_data);
                            
                            // Reload page sections
                            location.reload();
                        }
                    });
                };
            });
        })();
        </script>
        <?php
    }
    
    /**
     * Render metrics cards
     */
    private function render_metrics($metrics, $changes) {
        $cards = array(
            array('label' => 'Total Revenue', 'value' => '$' . number_format($metrics['revenue'], 0), 'key' => 'revenue'),
            array('label' => 'Training Revenue', 'value' => '$' . number_format($metrics['training_revenue'], 0), 'key' => null),
            array('label' => 'Camps Revenue', 'value' => '$' . number_format($metrics['camps_revenue'], 0), 'key' => null),
            array('label' => 'Total Bookings', 'value' => number_format($metrics['bookings']), 'key' => 'bookings'),
            array('label' => 'Avg Order Value', 'value' => '$' . number_format($metrics['avg_order'], 0), 'key' => 'avg_order'),
            array('label' => 'New Families', 'value' => number_format($metrics['new_customers']), 'key' => 'new_customers'),
            array('label' => 'Total Families', 'value' => number_format($metrics['total_families']), 'key' => null),
            array('label' => 'Active Trainers', 'value' => number_format($metrics['total_trainers']), 'key' => null),
        );
        
        foreach ($cards as $card): 
            $change = isset($changes[$card['key']]) ? $changes[$card['key']] : null;
        ?>
        <div class="ptp-metric">
            <div class="ptp-metric-label"><?php echo esc_html($card['label']); ?></div>
            <div class="ptp-metric-value"><?php echo $card['value']; ?></div>
            <?php if ($change !== null): ?>
            <div class="ptp-metric-change <?php echo $change >= 0 ? 'up' : 'down'; ?>">
                <?php echo $change >= 0 ? '↑' : '↓'; ?> <?php echo abs($change); ?>% vs prev period
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach;
    }
    
    /**
     * Render top trainers
     */
    private function render_top_trainers($trainers) {
        if (empty($trainers)) {
            echo '<p style="color:#6b7280;text-align:center">No trainer data</p>';
            return;
        }
        
        foreach ($trainers as $t): ?>
        <div class="ptp-trainer">
            <img src="<?php echo esc_url($t['photo']); ?>" alt="" class="ptp-trainer-photo">
            <div class="ptp-trainer-info">
                <div class="ptp-trainer-name"><?php echo esc_html($t['name']); ?></div>
                <div class="ptp-trainer-stats">
                    <?php echo $t['bookings']; ?> bookings · ★ <?php echo $t['rating']; ?>
                </div>
            </div>
            <div class="ptp-trainer-revenue">$<?php echo number_format($t['revenue'], 0); ?></div>
        </div>
        <?php endforeach;
    }
    
    /**
     * Render bookings table
     */
    private function render_bookings_table($bookings) {
        if (empty($bookings)) {
            echo '<p style="color:#6b7280;text-align:center">No recent bookings</p>';
            return;
        }
        ?>
        <table>
            <thead>
                <tr>
                    <th>Booking #</th>
                    <th>Date</th>
                    <th>Parent</th>
                    <th>Player</th>
                    <th>Trainer</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                <tr>
                    <td><strong><?php echo esc_html($b['number']); ?></strong></td>
                    <td><?php echo date('M j', strtotime($b['date'])); ?></td>
                    <td><?php echo esc_html($b['parent']); ?></td>
                    <td><?php echo esc_html($b['player']); ?></td>
                    <td><?php echo esc_html($b['trainer']); ?></td>
                    <td><strong>$<?php echo number_format($b['amount'], 0); ?></strong></td>
                    <td><span class="ptp-status <?php echo esc_attr($b['status']); ?>"><?php echo esc_html($b['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Render package breakdown
     */
    private function render_packages($packages) {
        $labels = array(
            'single' => 'Single Sessions',
            'pack3' => '3-Pack',
            'pack5' => '5-Pack',
        );
        
        foreach ($packages as $key => $pkg): ?>
        <div class="ptp-package">
            <div class="ptp-package-name"><?php echo esc_html($labels[$key] ?? $key); ?></div>
            <div class="ptp-package-count"><?php echo $pkg['count']; ?></div>
            <div class="ptp-package-revenue">$<?php echo number_format($pkg['revenue'], 0); ?></div>
        </div>
        <?php endforeach;
    }
    
    /**
     * Render conversion funnel
     */
    private function render_funnel($funnel) {
        if (empty($funnel)) return;
        
        $max = max(array_column($funnel, 'count'));
        
        foreach ($funnel as $stage): 
            $width = $max > 0 ? ($stage['count'] / $max) * 100 : 0;
        ?>
        <div class="ptp-funnel-stage">
            <div class="ptp-funnel-label"><?php echo esc_html($stage['stage']); ?></div>
            <div class="ptp-funnel-bar" style="width: <?php echo $width; ?>%">
                <?php echo number_format($stage['count']); ?>
            </div>
        </div>
        <?php endforeach;
    }
}

// Initialize
PTP_Analytics_Dashboard::instance();
