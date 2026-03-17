<?php
/**
 * PTP Analytics System v1.0.0
 * 
 * Tracks:
 * - Page views and funnel progression
 * - Conversion events
 * - User behavior
 * - Revenue metrics
 */

defined('ABSPATH') || exit;

class PTP_Analytics {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Track events via AJAX
        add_action('wp_ajax_ptp_track_event', array($this, 'track_event'));
        add_action('wp_ajax_nopriv_ptp_track_event', array($this, 'track_event'));
        
        // Add tracking script to frontend
        add_action('wp_footer', array($this, 'output_tracking_script'));
        
        // Daily stats aggregation
        add_action('ptp_aggregate_daily_stats', array($this, 'aggregate_daily_stats'));
        if (!wp_next_scheduled('ptp_aggregate_daily_stats')) {
            wp_schedule_event(strtotime('tomorrow 1:00am'), 'daily', 'ptp_aggregate_daily_stats');
        }
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_analytics_events (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            event_type varchar(50) NOT NULL,
            event_data longtext,
            page_url varchar(500),
            referrer varchar(500),
            device_type varchar(20),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY event_type (event_type),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) $charset;
        
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_analytics_daily (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stat_date date NOT NULL,
            metric varchar(50) NOT NULL,
            value decimal(15,2) NOT NULL DEFAULT 0,
            dimension varchar(100) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY date_metric_dim (stat_date, metric, dimension),
            KEY stat_date (stat_date),
            KEY metric (metric)
        ) $charset;
        
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_funnel_progress (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            step_1_home datetime DEFAULT NULL,
            step_2_browse datetime DEFAULT NULL,
            step_3_profile datetime DEFAULT NULL,
            step_4_booking datetime DEFAULT NULL,
            step_5_checkout datetime DEFAULT NULL,
            step_6_complete datetime DEFAULT NULL,
            trainer_id bigint(20) UNSIGNED DEFAULT NULL,
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Track event via AJAX
     */
    public function track_event() {
        global $wpdb;
        
        $event_type = sanitize_text_field($_POST['event_type'] ?? '');
        $event_data = isset($_POST['event_data']) ? json_decode(stripslashes($_POST['event_data']), true) : array();
        $page_url = esc_url_raw($_POST['page_url'] ?? '');
        $referrer = esc_url_raw($_POST['referrer'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($event_type) || empty($session_id)) {
            wp_send_json_error('Missing data');
        }
        
        $user_id = get_current_user_id() ?: null;
        $device_type = $this->detect_device();
        
        // Insert event
        $wpdb->insert(
            $wpdb->prefix . 'ptp_analytics_events',
            array(
                'session_id' => $session_id,
                'user_id' => $user_id,
                'event_type' => $event_type,
                'event_data' => json_encode($event_data),
                'page_url' => $page_url,
                'referrer' => $referrer,
                'device_type' => $device_type,
            )
        );
        
        // Update funnel progress
        $this->update_funnel_progress($session_id, $user_id, $event_type, $event_data);
        
        wp_send_json_success();
    }
    
    /**
     * Update funnel progress
     */
    private function update_funnel_progress($session_id, $user_id, $event_type, $event_data) {
        global $wpdb;
        
        $funnel_map = array(
            'page_view_home' => 'step_1_home',
            'page_view_training_landing' => 'step_1_home',
            'page_view_find_trainers' => 'step_2_browse',
            'page_view_trainer_profile' => 'step_3_profile',
            'booking_started' => 'step_4_booking',
            'checkout_started' => 'step_5_checkout',
            'booking_completed' => 'step_6_complete',
        );
        
        if (!isset($funnel_map[$event_type])) {
            return;
        }
        
        $column = $funnel_map[$event_type];
        $now = current_time('mysql');
        
        // Check if session exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_funnel_progress WHERE session_id = %s",
            $session_id
        ));
        
        if ($existing) {
            $update_data = array($column => $now);
            if (isset($event_data['trainer_id'])) {
                $update_data['trainer_id'] = intval($event_data['trainer_id']);
            }
            if (isset($event_data['booking_id'])) {
                $update_data['booking_id'] = intval($event_data['booking_id']);
            }
            if ($user_id) {
                $update_data['user_id'] = $user_id;
            }
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_funnel_progress',
                $update_data,
                array('session_id' => $session_id)
            );
        } else {
            $wpdb->insert(
                $wpdb->prefix . 'ptp_funnel_progress',
                array(
                    'session_id' => $session_id,
                    'user_id' => $user_id,
                    $column => $now,
                    'trainer_id' => isset($event_data['trainer_id']) ? intval($event_data['trainer_id']) : null,
                )
            );
        }
    }
    
    /**
     * Detect device type
     */
    private function detect_device() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (preg_match('/mobile|android|iphone|ipad|ipod|blackberry|windows phone/i', $user_agent)) {
            if (preg_match('/ipad|tablet/i', $user_agent)) {
                return 'tablet';
            }
            return 'mobile';
        }
        return 'desktop';
    }
    
    /**
     * Output tracking script
     */
    public function output_tracking_script() {
        $nonce = wp_create_nonce('ptp_analytics');
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <script>
        (function() {
            // Generate or retrieve session ID
            var sessionId = sessionStorage.getItem('ptp_session_id');
            if (!sessionId) {
                sessionId = 'ptp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                sessionStorage.setItem('ptp_session_id', sessionId);
            }
            
            // Track function
            window.ptpTrack = function(eventType, eventData) {
                eventData = eventData || {};
                
                var data = new FormData();
                data.append('action', 'ptp_track_event');
                data.append('nonce', '<?php echo $nonce; ?>');
                data.append('event_type', eventType);
                data.append('event_data', JSON.stringify(eventData));
                data.append('page_url', window.location.href);
                data.append('referrer', document.referrer);
                data.append('session_id', sessionId);
                
                navigator.sendBeacon('<?php echo $ajax_url; ?>', data);
            };
            
            // Auto-track page views
            var page = '<?php echo $this->get_current_page_type(); ?>';
            if (page) {
                ptpTrack('page_view_' + page, {
                    title: document.title,
                    path: window.location.pathname
                });
            }
            
            // Track time on page
            var startTime = Date.now();
            window.addEventListener('beforeunload', function() {
                var timeOnPage = Math.round((Date.now() - startTime) / 1000);
                ptpTrack('time_on_page', { seconds: timeOnPage, page: page });
            });
            
            // Track scroll depth
            var maxScroll = 0;
            window.addEventListener('scroll', function() {
                var scrollPercent = Math.round((window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100);
                if (scrollPercent > maxScroll) {
                    maxScroll = scrollPercent;
                    if (maxScroll === 25 || maxScroll === 50 || maxScroll === 75 || maxScroll === 100) {
                        ptpTrack('scroll_depth', { percent: maxScroll, page: page });
                    }
                }
            });
            
            // Expose session ID for other scripts
            window.ptpSessionId = sessionId;
        })();
        </script>
        <?php
    }
    
    /**
     * Get current page type for tracking
     */
    private function get_current_page_type() {
        global $post;
        
        $url = $_SERVER['REQUEST_URI'] ?? '';
        
        if (strpos($url, '/find-trainers') !== false) return 'find_trainers';
        if (strpos($url, '/trainer/') !== false) return 'trainer_profile';
        if (strpos($url, '/checkout') !== false) return 'checkout';
        if (strpos($url, '/book-session') !== false) return 'booking';
        if (strpos($url, '/booking-confirmation') !== false) return 'confirmation';
        if (strpos($url, '/training') !== false) return 'training_landing';
        if (strpos($url, '/my-training') !== false) return 'my_training';
        if (strpos($url, '/login') !== false) return 'login';
        if (strpos($url, '/register') !== false) return 'register';
        if (strpos($url, '/apply') !== false) return 'apply';
        if (is_front_page() || is_home()) return 'home';
        
        return '';
    }
    
    /**
     * Aggregate daily stats
     */
    public function aggregate_daily_stats() {
        global $wpdb;
        
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        // Unique visitors
        $visitors = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM {$wpdb->prefix}ptp_analytics_events 
             WHERE DATE(created_at) = %s",
            $yesterday
        ));
        $this->save_daily_stat($yesterday, 'unique_visitors', $visitors);
        
        // Page views by page
        $page_views = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                SUBSTRING_INDEX(SUBSTRING_INDEX(event_type, 'page_view_', -1), '_', 2) as page,
                COUNT(*) as views
             FROM {$wpdb->prefix}ptp_analytics_events 
             WHERE DATE(created_at) = %s AND event_type LIKE 'page_view_%'
             GROUP BY page",
            $yesterday
        ));
        
        foreach ($page_views as $pv) {
            $this->save_daily_stat($yesterday, 'page_views', $pv->views, $pv->page);
        }
        
        // Funnel conversion
        $funnel = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT CASE WHEN step_1_home IS NOT NULL THEN session_id END) as step_1,
                COUNT(DISTINCT CASE WHEN step_2_browse IS NOT NULL THEN session_id END) as step_2,
                COUNT(DISTINCT CASE WHEN step_3_profile IS NOT NULL THEN session_id END) as step_3,
                COUNT(DISTINCT CASE WHEN step_4_booking IS NOT NULL THEN session_id END) as step_4,
                COUNT(DISTINCT CASE WHEN step_5_checkout IS NOT NULL THEN session_id END) as step_5,
                COUNT(DISTINCT CASE WHEN step_6_complete IS NOT NULL THEN session_id END) as step_6
             FROM {$wpdb->prefix}ptp_funnel_progress 
             WHERE DATE(created_at) = %s",
            $yesterday
        ));
        
        if ($funnel) {
            $this->save_daily_stat($yesterday, 'funnel_step_1', $funnel->step_1);
            $this->save_daily_stat($yesterday, 'funnel_step_2', $funnel->step_2);
            $this->save_daily_stat($yesterday, 'funnel_step_3', $funnel->step_3);
            $this->save_daily_stat($yesterday, 'funnel_step_4', $funnel->step_4);
            $this->save_daily_stat($yesterday, 'funnel_step_5', $funnel->step_5);
            $this->save_daily_stat($yesterday, 'funnel_step_6', $funnel->step_6);
        }
        
        // Revenue
        $revenue = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as bookings,
                SUM(total_amount) as gmv,
                SUM(platform_fee) as platform_revenue
             FROM {$wpdb->prefix}ptp_bookings 
             WHERE DATE(created_at) = %s AND status IN ('confirmed', 'completed')",
            $yesterday
        ));
        
        if ($revenue) {
            $this->save_daily_stat($yesterday, 'bookings', $revenue->bookings ?: 0);
            $this->save_daily_stat($yesterday, 'gmv', $revenue->gmv ?: 0);
            $this->save_daily_stat($yesterday, 'platform_revenue', $revenue->platform_revenue ?: 0);
        }
        
        // Device breakdown
        $devices = $wpdb->get_results($wpdb->prepare(
            "SELECT device_type, COUNT(DISTINCT session_id) as count
             FROM {$wpdb->prefix}ptp_analytics_events 
             WHERE DATE(created_at) = %s
             GROUP BY device_type",
            $yesterday
        ));
        
        foreach ($devices as $d) {
            $this->save_daily_stat($yesterday, 'visitors_by_device', $d->count, $d->device_type);
        }
    }
    
    /**
     * Save daily stat
     */
    private function save_daily_stat($date, $metric, $value, $dimension = null) {
        global $wpdb;
        
        $wpdb->replace(
            $wpdb->prefix . 'ptp_analytics_daily',
            array(
                'stat_date' => $date,
                'metric' => $metric,
                'value' => $value,
                'dimension' => $dimension,
            )
        );
    }
    
    /**
     * Get stats for dashboard
     */
    public static function get_dashboard_stats($days = 30) {
        global $wpdb;
        
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        
        $stats = array();
        
        // Summary metrics
        $stats['summary'] = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(CASE WHEN metric = 'unique_visitors' THEN value ELSE 0 END) as visitors,
                SUM(CASE WHEN metric = 'bookings' THEN value ELSE 0 END) as bookings,
                SUM(CASE WHEN metric = 'gmv' THEN value ELSE 0 END) as gmv,
                SUM(CASE WHEN metric = 'platform_revenue' THEN value ELSE 0 END) as revenue
             FROM {$wpdb->prefix}ptp_analytics_daily 
             WHERE stat_date >= %s",
            $start_date
        ), ARRAY_A);
        
        // Daily trend
        $stats['daily_trend'] = $wpdb->get_results($wpdb->prepare(
            "SELECT stat_date, metric, value 
             FROM {$wpdb->prefix}ptp_analytics_daily 
             WHERE stat_date >= %s AND metric IN ('unique_visitors', 'bookings', 'gmv')
             ORDER BY stat_date ASC",
            $start_date
        ), ARRAY_A);
        
        // Funnel for period
        $stats['funnel'] = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(CASE WHEN metric = 'funnel_step_1' THEN value ELSE 0 END) as step_1,
                SUM(CASE WHEN metric = 'funnel_step_2' THEN value ELSE 0 END) as step_2,
                SUM(CASE WHEN metric = 'funnel_step_3' THEN value ELSE 0 END) as step_3,
                SUM(CASE WHEN metric = 'funnel_step_4' THEN value ELSE 0 END) as step_4,
                SUM(CASE WHEN metric = 'funnel_step_5' THEN value ELSE 0 END) as step_5,
                SUM(CASE WHEN metric = 'funnel_step_6' THEN value ELSE 0 END) as step_6
             FROM {$wpdb->prefix}ptp_analytics_daily 
             WHERE stat_date >= %s",
            $start_date
        ), ARRAY_A);
        
        // Top trainers
        $stats['top_trainers'] = $wpdb->get_results($wpdb->prepare(
            "SELECT t.display_name, t.photo_url, COUNT(b.id) as bookings, SUM(b.total_amount) as revenue
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.created_at >= %s AND b.status IN ('confirmed', 'completed')
             GROUP BY b.trainer_id
             ORDER BY bookings DESC
             LIMIT 5",
            $start_date
        ), ARRAY_A);
        
        // Device breakdown
        $stats['devices'] = $wpdb->get_results($wpdb->prepare(
            "SELECT dimension as device, SUM(value) as visitors
             FROM {$wpdb->prefix}ptp_analytics_daily 
             WHERE stat_date >= %s AND metric = 'visitors_by_device'
             GROUP BY dimension",
            $start_date
        ), ARRAY_A);
        
        return $stats;
    }
    
    /**
     * Get real-time stats (last 30 minutes)
     */
    public static function get_realtime_stats() {
        global $wpdb;
        
        $since = date('Y-m-d H:i:s', strtotime('-30 minutes'));
        
        return array(
            'active_users' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT session_id) FROM {$wpdb->prefix}ptp_analytics_events 
                 WHERE created_at >= %s",
                $since
            )),
            'page_views' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_analytics_events 
                 WHERE created_at >= %s AND event_type LIKE 'page_view_%'",
                $since
            )),
            'bookings_today' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE DATE(created_at) = CURDATE() AND status IN ('confirmed', 'completed')"
            ),
            'revenue_today' => $wpdb->get_var(
                "SELECT COALESCE(SUM(platform_fee), 0) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE DATE(created_at) = CURDATE() AND status IN ('confirmed', 'completed')"
            ),
        );
    }
}

// Initialize
PTP_Analytics::instance();
