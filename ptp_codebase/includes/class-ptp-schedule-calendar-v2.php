<?php
/**
 * PTP Schedule Calendar v2.0
 * Enhanced admin calendar with:
 * - Dashboard stats panel
 * - Recurring sessions
 * - Conflict detection
 * - Bulk actions
 * - Quick status updates
 * - Export functionality
 * - Keyboard shortcuts
 * - Advanced filtering
 * 
 * @version 2.0.0
 * @since 125.0.0
 */

defined('ABSPATH') || exit;

class PTP_Schedule_Calendar_V2 {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function init() {
        self::instance();
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu'), 30);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // AJAX handlers - existing
        add_action('wp_ajax_ptp_schedule_get_events', array($this, 'ajax_get_events'));
        add_action('wp_ajax_ptp_schedule_create_session', array($this, 'ajax_create_session'));
        add_action('wp_ajax_ptp_schedule_update_session', array($this, 'ajax_update_session'));
        add_action('wp_ajax_ptp_schedule_delete_session', array($this, 'ajax_delete_session'));
        add_action('wp_ajax_ptp_schedule_search_customers', array($this, 'ajax_search_customers'));
        add_action('wp_ajax_ptp_schedule_search_players', array($this, 'ajax_search_players'));
        add_action('wp_ajax_ptp_schedule_get_trainer_stats', array($this, 'ajax_get_trainer_stats'));
        
        // AJAX handlers - NEW v2
        add_action('wp_ajax_ptp_schedule_get_dashboard_stats', array($this, 'ajax_get_dashboard_stats'));
        add_action('wp_ajax_ptp_schedule_quick_status', array($this, 'ajax_quick_status'));
        add_action('wp_ajax_ptp_schedule_bulk_action', array($this, 'ajax_bulk_action'));
        add_action('wp_ajax_ptp_schedule_create_recurring', array($this, 'ajax_create_recurring'));
        add_action('wp_ajax_ptp_schedule_check_conflicts', array($this, 'ajax_check_conflicts'));
        add_action('wp_ajax_ptp_schedule_export', array($this, 'ajax_export'));
        add_action('wp_ajax_ptp_schedule_get_trainer_availability', array($this, 'ajax_get_trainer_availability'));
        add_action('wp_ajax_ptp_schedule_get_session_detail', array($this, 'ajax_get_session_detail'));
        add_action('wp_ajax_ptp_schedule_save_note', array($this, 'ajax_save_note'));
        add_action('wp_ajax_ptp_schedule_toggle_payment', array($this, 'ajax_toggle_payment'));
    }
    
    public function add_menu() {
        // Menu handled by PTP_Admin — only register if not already present
        global $submenu;
        $already = false;
        if (!empty($submenu)) {
            foreach ($submenu as $parent => $items) {
                foreach ($items as $item) {
                    if (isset($item[2]) && $item[2] === 'ptp-schedule') { $already = true; break 2; }
                }
            }
        }
        if (!$already) {
            add_submenu_page('ptp-dashboard', 'Schedule', 'Schedule', 'manage_options', 'ptp-schedule', array($this, 'render_calendar'));
        }
    }
    
    public function enqueue_assets($hook) {
        if (strpos($hook, 'ptp-schedule') === false) return;
        
        // Plugin assets only — no external CDNs
        wp_enqueue_style('ptp-schedule-v2', PTP_PLUGIN_URL . 'assets/css/schedule-v2.css', array(), PTP_VERSION);
        wp_enqueue_script('ptp-schedule-v2', PTP_PLUGIN_URL . 'assets/js/schedule-v2.js', array('jquery'), PTP_VERSION, true);
        
        wp_localize_script('ptp-schedule-v2', 'PTPSchedule', array(
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_schedule_nonce'),
            'trainers' => $this->get_trainers_data(),
        ));
    }
    
    private function get_trainers_data() {
        global $wpdb;
        $trainers = $wpdb->get_results(
            "SELECT id, user_id, display_name, photo_url, status, hourly_rate
             FROM {$wpdb->prefix}ptp_trainers 
             WHERE status = 'active' 
             ORDER BY display_name"
        );
        
        $colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#6366f1'];
        
        $data = array();
        foreach ($trainers as $i => $t) {
            $data[] = array(
                'id' => $t->id,
                'name' => $t->display_name,
                'photo' => $t->photo_url,
                'color' => $colors[$i % 10],
                'rate' => $t->hourly_rate,
            );
        }
        return $data;
    }
    
    public function render_calendar() {
        global $wpdb;
        
        $trainers = $wpdb->get_results(
            "SELECT id, user_id, display_name, photo_url, status, hourly_rate
             FROM {$wpdb->prefix}ptp_trainers 
             WHERE status = 'active' 
             ORDER BY display_name"
        );
        
        $colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16','#f97316','#6366f1'];
        
        ?>
        <div class="sc">
        <div class="sc-wrap">
            <!-- ═══ SIDEBAR ═══ -->
            <div class="sc-side">
                <button type="button" class="sc-new-btn" id="scNewBtn">
                    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Session
                </button>
                
                <!-- Mini Calendar -->
                <div class="sc-mini">
                    <div class="sc-mini-hdr">
                        <button type="button" id="scMiniPrev"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></button>
                        <span id="scMiniTitle"></span>
                        <button type="button" id="scMiniNext"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>
                    </div>
                    <div class="sc-mini-grid" id="scMiniGrid"></div>
                </div>
                
                <!-- Stats -->
                <div class="sc-stats">
                    <div class="sc-stat"><div class="sc-stat-n" id="scStatToday">-</div><div class="sc-stat-l">Today</div></div>
                    <div class="sc-stat"><div class="sc-stat-n" id="scStatWeek">-</div><div class="sc-stat-l">This Week</div></div>
                    <div class="sc-stat"><div class="sc-stat-n" id="scStatPending">-</div><div class="sc-stat-l">Pending</div></div>
                    <div class="sc-stat"><div class="sc-stat-n" id="scStatRevenue">-</div><div class="sc-stat-l">Revenue</div></div>
                </div>
                
                <!-- Trainers -->
                <div class="sc-trainers">
                    <div class="sc-trainers-hdr">
                        <h3>Trainers</h3>
                        <button type="button" id="scTrainersAll">Show All</button>
                    </div>
                    <?php foreach ($trainers as $i => $t): 
                        $color = $colors[$i % 10];
                        $initial = strtoupper(substr($t->display_name, 0, 1));
                    ?>
                    <div class="sc-trainer" data-id="<?php echo $t->id; ?>" data-color="<?php echo $color; ?>">
                        <input type="checkbox" checked>
                        <div class="sc-trainer-dot" style="background:<?php echo $color; ?>"></div>
                        <?php if ($t->photo_url): ?>
                        <img src="<?php echo esc_url($t->photo_url); ?>" class="sc-trainer-photo" alt="">
                        <?php else: ?>
                        <div class="sc-trainer-av" style="background:<?php echo $color; ?>"><?php echo $initial; ?></div>
                        <?php endif; ?>
                        <span class="sc-trainer-name"><?php echo esc_html($t->display_name); ?></span>
                        <span class="sc-trainer-cnt">0</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- ═══ MAIN ═══ -->
            <div class="sc-main">
                <!-- Top Bar -->
                <div class="sc-topbar">
                    <div class="sc-nav">
                        <button type="button" class="sc-nav-arrow" id="scPrev"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></button>
                        <button type="button" class="sc-nav-btn today" id="scToday">Today</button>
                        <button type="button" class="sc-nav-arrow" id="scNext"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>
                    </div>
                    <div class="sc-title" id="scTitle"></div>
                    <div class="sc-view-btns">
                        <button type="button" class="sc-view-btn active" id="scViewWeek">Week</button>
                        <button type="button" class="sc-view-btn" id="scViewDay">Day</button>
                    </div>
                </div>
                
                <!-- Time Grid -->
                <div class="sc-grid-wrap">
                    <!-- Day Headers -->
                    <div class="sc-hdr-row">
                        <div class="sc-hdr-time"></div>
                        <div class="sc-hdr-cols" id="scHdrCols"></div>
                    </div>
                    
                    <!-- Body -->
                    <div class="sc-body">
                        <div class="sc-times" id="scTimes"></div>
                        <div class="sc-cols" id="scCols"></div>
                    </div>
                    
                    <!-- Now Line -->
                    <div class="sc-now-line" id="scNow"></div>
                </div>
            </div>
        </div>
        </div>
        
        <!-- ═══ NEW/EDIT SESSION MODAL ═══ -->
        <div class="sc-overlay" id="scNewOverlay" onclick="if(event.target===this)scCloseNew()">
        <div class="sc-modal">
            <div class="sc-mhdr">
                <h2 id="scNewTitle">New Session</h2>
                <button type="button" class="sc-mclose" onclick="scCloseNew()">&times;</button>
            </div>
            <div class="sc-mbody">
                <form id="scNewForm" onsubmit="event.preventDefault();scSaveSession()">
                    <input type="hidden" id="scNewId">
                    
                    <div class="sc-row">
                        <div class="sc-field">
                            <label>Trainer <span class="req">*</span></label>
                            <select id="scNewTrainer" required>
                                <option value="">Select trainer...</option>
                                <?php foreach ($trainers as $t): ?>
                                <option value="<?php echo $t->id; ?>"><?php echo esc_html($t->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sc-field">
                            <label>Status</label>
                            <select id="scNewStatus">
                                <option value="scheduled">Scheduled</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="pending">Pending</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="sc-row-3">
                        <div class="sc-field">
                            <label>Date <span class="req">*</span></label>
                            <input type="date" id="scNewDate" required>
                        </div>
                        <div class="sc-field">
                            <label>Time <span class="req">*</span></label>
                            <input type="time" id="scNewTime" required>
                        </div>
                        <div class="sc-field">
                            <label>Duration</label>
                            <select id="scNewDuration">
                                <option value="30">30 min</option>
                                <option value="45">45 min</option>
                                <option value="60" selected>1 hour</option>
                                <option value="90">1.5 hours</option>
                                <option value="120">2 hours</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="sc-row">
                        <div class="sc-field">
                            <label>Player Name</label>
                            <input type="text" id="scNewPlayer" placeholder="Player name">
                        </div>
                        <div class="sc-field">
                            <label>Age</label>
                            <input type="number" id="scNewAge" min="4" max="18" placeholder="Age">
                        </div>
                    </div>
                    
                    <div class="sc-field">
                        <label>Session Type</label>
                        <select id="scNewType">
                            <option value="1on1">1-on-1</option>
                            <option value="small_group">Small Group</option>
                            <option value="group">Group</option>
                        </select>
                    </div>
                    
                    <div class="sc-row">
                        <div class="sc-field">
                            <label>Price ($)</label>
                            <input type="number" id="scNewPrice" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="sc-field">
                            <label>Payment</label>
                            <select id="scNewPayment">
                                <option value="unpaid">Unpaid</option>
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="sc-field">
                        <label>Location</label>
                        <input type="text" id="scNewLocation" placeholder="Field name or address">
                    </div>
                    
                    <div class="sc-field">
                        <label>Notes</label>
                        <textarea id="scNewNotes" rows="2" placeholder="Internal notes..."></textarea>
                    </div>
                </form>
            </div>
            <div class="sc-mfoot">
                <button type="button" class="sc-btn sc-btn-outline" onclick="scCloseNew()">Cancel</button>
                <button type="button" class="sc-btn sc-btn-gold" id="scSaveBtn" onclick="scSaveSession()">Save Session</button>
            </div>
        </div>
        </div>
        
        <!-- ═══ DETAIL MODAL ═══ -->
        <div class="sc-overlay" id="scDetailOverlay" onclick="if(event.target===this)scCloseDetail()">
        <div class="sc-modal">
            <div class="sc-mhdr">
                <h2>Session Details</h2>
                <button type="button" class="sc-mclose" onclick="scCloseDetail()">&times;</button>
            </div>
            <div id="scDetailBody"></div>
            <div class="sc-mfoot" id="scDetailFoot"></div>
        </div>
        </div>
        
        <!-- Toast -->
        <div class="sc-toast" id="scToast"></div>
        
        <?php
    }
    
    // ===================
    // AJAX HANDLERS
    // ===================

    public function ajax_get_events() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $start = sanitize_text_field($_GET['start'] ?? '');
        $end = sanitize_text_field($_GET['end'] ?? '');
        $trainer_ids = isset($_GET['trainers']) ? sanitize_text_field($_GET['trainers']) : '';
        $trainer_ids = $trainer_ids ? array_map('intval', explode(',', $trainer_ids)) : array();
        $status_filter = sanitize_text_field($_GET['status'] ?? '');
        $payment_filter = sanitize_text_field($_GET['payment'] ?? '');
        $type_filter = sanitize_text_field($_GET['type'] ?? '');
        $search = sanitize_text_field($_GET['search'] ?? '');
        
        $events = array();
        
        // Get admin-created sessions from ptp_sessions
        $sql = "SELECT s.*, t.display_name as trainer_name, u.display_name as customer_name, u.user_email as customer_email
                FROM {$wpdb->prefix}ptp_sessions s
                LEFT JOIN {$wpdb->prefix}ptp_trainers t ON s.trainer_id = t.id
                LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
                WHERE s.session_date >= %s AND s.session_date <= %s";
        
        $params = array($start, $end);
        
        if (!empty($trainer_ids)) {
            $placeholders = implode(',', array_fill(0, count($trainer_ids), '%d'));
            $sql .= " AND s.trainer_id IN ($placeholders)";
            $params = array_merge($params, $trainer_ids);
        }
        
        if ($status_filter && $status_filter !== 'all') {
            $sql .= " AND s.session_status = %s";
            $params[] = $status_filter;
        }
        
        if ($payment_filter) {
            $sql .= " AND s.payment_status = %s";
            $params[] = $payment_filter;
        }
        
        if ($type_filter) {
            $sql .= " AND s.session_type = %s";
            $params[] = $type_filter;
        }
        
        if ($search) {
            $sql .= " AND (s.player_name LIKE %s OR t.display_name LIKE %s)";
            $search_param = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        $sql .= " ORDER BY s.session_date, s.start_time";
        
        $sessions = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        foreach ($sessions as $s) {
            $events[] = $this->format_event($s, 'admin');
        }
        
        // Get parent-booked sessions from ptp_bookings
        $sql2 = "SELECT b.*, t.display_name as trainer_name, 
                        p.name as player_name, p.age as player_age,
                        pa.user_id as customer_id,
                        u.display_name as customer_name, u.user_email as customer_email
                 FROM {$wpdb->prefix}ptp_bookings b
                 LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
                 LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
                 LEFT JOIN {$wpdb->users} u ON pa.user_id = u.ID
                 WHERE b.session_date >= %s AND b.session_date <= %s";
        
        $params2 = array($start, $end);
        
        if (!empty($trainer_ids)) {
            $sql2 .= " AND b.trainer_id IN ($placeholders)";
            $params2 = array_merge($params2, $trainer_ids);
        }
        
        if ($status_filter && $status_filter !== 'all') {
            $sql2 .= " AND b.status = %s";
            $params2[] = $status_filter;
        }
        
        if ($payment_filter) {
            $sql2 .= " AND b.payment_status = %s";
            $params2[] = $payment_filter;
        }
        
        if ($search) {
            $sql2 .= " AND (p.name LIKE %s OR t.display_name LIKE %s)";
            $params2[] = $search_param;
            $params2[] = $search_param;
        }
        
        $sql2 .= " ORDER BY b.session_date, b.start_time";
        
        $bookings = $wpdb->get_results($wpdb->prepare($sql2, $params2));
        
        foreach ($bookings as $b) {
            $events[] = $this->format_event($b, 'booking');
        }
        
        wp_send_json($events);
    }
    
    private function format_event($row, $source) {
        $status = $source === 'booking' ? ($row->status ?? 'scheduled') : ($row->session_status ?? 'scheduled');
        $id_prefix = $source === 'booking' ? 'booking_' : 'session_';
        $id_field = $source === 'booking' ? $row->id : $row->id;
        
        return array(
            'id' => $id_prefix . $id_field,
            'title' => ($row->player_name ?: 'Session') . ' - ' . ($row->trainer_name ?: 'Unassigned'),
            'start' => $row->session_date . 'T' . $row->start_time,
            'end' => $row->session_date . 'T' . ($row->end_time ?? date('H:i:s', strtotime($row->start_time) + 3600)),
            'className' => 'status-' . $status . ' source-' . $source,
            'extendedProps' => array(
                'source' => $source,
                'record_id' => $id_field,
                'trainer_id' => $row->trainer_id,
                'trainer_name' => $row->trainer_name,
                'customer_id' => $row->customer_id ?? null,
                'customer_name' => $row->customer_name ?? '',
                'customer_email' => $row->customer_email ?? '',
                'parent_id' => $row->parent_id ?? null,
                'player_id' => $row->player_id ?? null,
                'player_name' => $row->player_name ?? '',
                'player_age' => $row->player_age ?? null,
                'session_status' => $status,
                'payment_status' => $row->payment_status ?? 'unpaid',
                'session_type' => $row->session_type ?? '1on1',
                'location_text' => $row->location_text ?? $row->location ?? '',
                'price' => $row->price ?? $row->total_amount ?? 0,
                'internal_notes' => $row->internal_notes ?? $row->notes ?? '',
                'duration_minutes' => $row->duration_minutes ?? 60,
                'booking_number' => $row->booking_number ?? null,
            ),
        );
    }
    
    public function ajax_get_dashboard_stats() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $today = date('Y-m-d');
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime('sunday this week'));
        
        // Today's sessions
        $today_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_sessions WHERE session_date = %s AND session_status NOT IN ('cancelled')",
            $today
        ));
        $today_bookings = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE session_date = %s AND status NOT IN ('cancelled')",
            $today
        ));
        
        // This week
        $week_sessions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_sessions WHERE session_date BETWEEN %s AND %s AND session_status NOT IN ('cancelled')",
            $week_start, $week_end
        ));
        $week_bookings = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE session_date BETWEEN %s AND %s AND status NOT IN ('cancelled')",
            $week_start, $week_end
        ));
        
        // Pending
        $pending_sessions = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_sessions WHERE session_status = 'pending'"
        );
        $pending_bookings = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE status = 'pending'"
        );
        
        // Week revenue
        $session_revenue = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(price), 0) FROM {$wpdb->prefix}ptp_sessions 
             WHERE session_date BETWEEN %s AND %s AND session_status NOT IN ('cancelled') AND payment_status = 'paid'",
            $week_start, $week_end
        ));
        $booking_revenue = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE session_date BETWEEN %s AND %s AND status NOT IN ('cancelled') AND payment_status = 'paid'",
            $week_start, $week_end
        ));
        
        // Active trainers
        $active_trainers = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'"
        );
        
        wp_send_json_success(array(
            'today' => intval($today_count) + intval($today_bookings),
            'week' => intval($week_sessions) + intval($week_bookings),
            'pending' => intval($pending_sessions) + intval($pending_bookings),
            'revenue' => floatval($session_revenue) + floatval($booking_revenue),
            'trainers' => intval($active_trainers),
        ));
    }
    
    public function ajax_quick_status() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $new_status = sanitize_text_field($_POST['status'] ?? '');
        if (!$new_status) {
            wp_send_json_error('Missing status');
        }
        
        // Support both combined id (booking_123) and separate source+record_id
        $source = sanitize_text_field($_POST['source'] ?? '');
        $record_id = intval($_POST['record_id'] ?? 0);
        
        if (!$source || !$record_id) {
            // Fallback to combined id format
            $id = sanitize_text_field($_POST['id'] ?? '');
            if (strpos($id, 'booking_') === 0) {
                $source = 'booking';
                $record_id = intval(str_replace('booking_', '', $id));
            } else {
                $source = 'admin';
                $record_id = intval(str_replace('session_', '', $id));
            }
        }
        
        if (!$record_id) {
            wp_send_json_error('Missing record ID');
        }
        
        if ($source === 'booking') {
            $result = $wpdb->update(
                "{$wpdb->prefix}ptp_bookings",
                array('status' => $new_status),
                array('id' => $record_id)
            );
        } else {
            $result = $wpdb->update(
                "{$wpdb->prefix}ptp_sessions",
                array('session_status' => $new_status),
                array('id' => $record_id)
            );
        }
        
        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Update failed');
        }
    }
    
    public function ajax_bulk_action() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $ids = isset($_POST['ids']) ? array_map('sanitize_text_field', $_POST['ids']) : array();
        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        
        if (empty($ids) || !$action) {
            wp_send_json_error('Missing parameters');
        }
        
        $status_map = array(
            'confirm' => 'confirmed',
            'complete' => 'completed',
            'cancel' => 'cancelled',
        );
        
        if (!isset($status_map[$action])) {
            wp_send_json_error('Invalid action');
        }
        
        $new_status = $status_map[$action];
        $updated = 0;
        
        foreach ($ids as $id) {
            if (strpos($id, 'booking_') === 0) {
                $record_id = intval(str_replace('booking_', '', $id));
                $result = $wpdb->update(
                    "{$wpdb->prefix}ptp_bookings",
                    array('status' => $new_status),
                    array('id' => $record_id)
                );
            } else {
                $record_id = intval(str_replace('session_', '', $id));
                $result = $wpdb->update(
                    "{$wpdb->prefix}ptp_sessions",
                    array('session_status' => $new_status),
                    array('id' => $record_id)
                );
            }
            if ($result) $updated++;
        }
        
        wp_send_json_success(array('updated' => $updated));
    }
    
    public function ajax_create_recurring() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'weekly');
        $days = isset($_POST['days']) ? array_map('intval', $_POST['days']) : array();
        $start_time = sanitize_text_field($_POST['start_time'] ?? '09:00');
        $duration = intval($_POST['duration_minutes'] ?? 60);
        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $count = intval($_POST['count'] ?? 8);
        $player_name = sanitize_text_field($_POST['player_name'] ?? '');
        
        if (!$trainer_id || empty($days) || !$start_date) {
            wp_send_json_error('Missing required fields');
        }
        
        $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
        $created = 0;
        $conflicts = 0;
        
        $interval = $frequency === 'weekly' ? 7 : ($frequency === 'biweekly' ? 14 : 28);
        
        $current_date = new DateTime($start_date);
        $sessions_created = 0;
        $max_iterations = $count * 7; // Safety limit
        $iterations = 0;
        
        while ($sessions_created < $count && $iterations < $max_iterations) {
            $iterations++;
            $day_of_week = intval($current_date->format('w'));
            
            if (in_array($day_of_week, $days)) {
                $session_date = $current_date->format('Y-m-d');
                
                // Check for conflicts
                $conflict = $this->check_conflict($trainer_id, $session_date, $start_time, $end_time);
                
                if (!$conflict) {
                    $data = array(
                        'trainer_id' => $trainer_id,
                        'player_name' => $player_name,
                        'session_date' => $session_date,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'duration_minutes' => $duration,
                        'session_status' => 'scheduled',
                        'session_type' => '1on1',
                        'created_by' => get_current_user_id(),
                    );
                    
                    $wpdb->insert("{$wpdb->prefix}ptp_sessions", $data);
                    $created++;
                } else {
                    $conflicts++;
                }
                
                $sessions_created++;
            }
            
            $current_date->modify('+1 day');
            
            // Reset to start of next interval period if we've passed all selected days
            if ($day_of_week === 6 && $frequency !== 'weekly') {
                $current_date->modify('+' . ($interval - 7) . ' days');
            }
        }
        
        wp_send_json_success(array(
            'created' => $created,
            'conflicts' => $conflicts,
        ));
    }
    
    private function check_conflict($trainer_id, $date, $start_time, $end_time, $exclude_id = null) {
        global $wpdb;
        
        // Check sessions table
        $sql = "SELECT id FROM {$wpdb->prefix}ptp_sessions 
                WHERE trainer_id = %d 
                AND session_date = %s 
                AND session_status NOT IN ('cancelled')
                AND (
                    (start_time < %s AND end_time > %s) OR
                    (start_time < %s AND end_time > %s) OR
                    (start_time >= %s AND end_time <= %s)
                )";
        
        $params = array($trainer_id, $date, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time);
        
        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }
        
        $session_conflict = $wpdb->get_var($wpdb->prepare($sql, $params));
        
        if ($session_conflict) return true;
        
        // Check bookings table
        $sql2 = "SELECT id FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d 
                 AND session_date = %s 
                 AND status NOT IN ('cancelled')
                 AND (
                     (start_time < %s AND end_time > %s) OR
                     (start_time < %s AND end_time > %s) OR
                     (start_time >= %s AND end_time <= %s)
                 )";
        
        $booking_conflict = $wpdb->get_var($wpdb->prepare($sql2, $trainer_id, $date, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time));
        
        return $booking_conflict ? true : false;
    }
    
    public function ajax_check_conflicts() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        $trainer_id = intval($_GET['trainer_id'] ?? 0);
        $date = sanitize_text_field($_GET['date'] ?? '');
        $start_time = sanitize_text_field($_GET['start_time'] ?? '');
        $duration = intval($_GET['duration'] ?? 60);
        $exclude_id = sanitize_text_field($_GET['exclude_id'] ?? '');
        
        if (!$trainer_id || !$date || !$start_time) {
            wp_send_json_error('Missing parameters');
        }
        
        $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
        
        $exclude = null;
        if ($exclude_id && strpos($exclude_id, 'session_') === 0) {
            $exclude = intval(str_replace('session_', '', $exclude_id));
        }
        
        $has_conflict = $this->check_conflict($trainer_id, $date, $start_time, $end_time, $exclude);
        
        wp_send_json_success(array('conflict' => $has_conflict));
    }
    
    public function ajax_export() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $start = sanitize_text_field($_POST['start'] ?? '');
        $end = sanitize_text_field($_POST['end'] ?? '');
        $format = sanitize_text_field($_POST['format'] ?? 'csv');
        
        // Get all sessions and bookings in date range
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, t.display_name as trainer_name, 'admin' as source
             FROM {$wpdb->prefix}ptp_sessions s
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON s.trainer_id = t.id
             WHERE s.session_date BETWEEN %s AND %s
             ORDER BY s.session_date, s.start_time",
            $start, $end
        ));
        
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, p.name as player_name, 'booking' as source
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE b.session_date BETWEEN %s AND %s
             ORDER BY b.session_date, b.start_time",
            $start, $end
        ));
        
        $all = array_merge($sessions, $bookings);
        usort($all, function($a, $b) {
            $cmp = strcmp($a->session_date, $b->session_date);
            return $cmp !== 0 ? $cmp : strcmp($a->start_time, $b->start_time);
        });
        
        if ($format === 'csv') {
            $csv = "Date,Time,Trainer,Player,Status,Type,Price,Source\n";
            foreach ($all as $row) {
                $status = $row->source === 'booking' ? ($row->status ?? '') : ($row->session_status ?? '');
                $price = $row->source === 'booking' ? ($row->total_amount ?? 0) : ($row->price ?? 0);
                $csv .= sprintf(
                    "%s,%s,%s,%s,%s,%s,$%.2f,%s\n",
                    $row->session_date,
                    $row->start_time,
                    str_replace(',', '', $row->trainer_name ?? ''),
                    str_replace(',', '', $row->player_name ?? ''),
                    $status,
                    $row->session_type ?? '1on1',
                    $price,
                    $row->source
                );
            }
            
            wp_send_json_success(array(
                'data' => $csv,
                'filename' => 'ptp-schedule-' . $start . '-to-' . $end . '.csv',
                'mime' => 'text/csv',
            ));
        } else {
            // iCal format
            $ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//PTP//Schedule//EN\r\n";
            
            foreach ($all as $row) {
                $uid = ($row->source === 'booking' ? 'booking-' : 'session-') . $row->id . '@ptp';
                $dtstart = date('Ymd\THis', strtotime($row->session_date . ' ' . $row->start_time));
                $dtend = date('Ymd\THis', strtotime($row->session_date . ' ' . ($row->end_time ?? date('H:i:s', strtotime($row->start_time) + 3600))));
                
                $ical .= "BEGIN:VEVENT\r\n";
                $ical .= "UID:$uid\r\n";
                $ical .= "DTSTART:$dtstart\r\n";
                $ical .= "DTEND:$dtend\r\n";
                $ical .= "SUMMARY:" . ($row->player_name ?? 'Session') . " - " . ($row->trainer_name ?? 'Trainer') . "\r\n";
                $ical .= "END:VEVENT\r\n";
            }
            
            $ical .= "END:VCALENDAR\r\n";
            
            wp_send_json_success(array(
                'data' => $ical,
                'filename' => 'ptp-schedule-' . $start . '-to-' . $end . '.ics',
                'mime' => 'text/calendar',
            ));
        }
    }
    
    public function ajax_create_session() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $duration = intval($_POST['duration_minutes'] ?? 60);
        $start_time = sanitize_text_field($_POST['start_time']);
        $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
        
        $price = floatval($_POST['price'] ?? 0);
        $platform_fee = floatval(get_option('ptp_platform_fee_percent', 25)) / 100;
        $trainer_payout = $price * (1 - $platform_fee);
        
        $data = array(
            'trainer_id' => intval($_POST['trainer_id']),
            'customer_id' => intval($_POST['customer_id']) ?: null,
            'parent_id' => intval($_POST['parent_id']) ?: null,
            'player_id' => intval($_POST['player_id']) ?: null,
            'player_name' => sanitize_text_field($_POST['player_name']),
            'player_age' => intval($_POST['player_age']) ?: null,
            'session_date' => sanitize_text_field($_POST['session_date']),
            'start_time' => $start_time,
            'end_time' => $end_time,
            'duration_minutes' => $duration,
            'session_status' => sanitize_text_field($_POST['session_status'] ?? 'scheduled'),
            'payment_status' => sanitize_text_field($_POST['payment_status'] ?? 'unpaid'),
            'session_type' => sanitize_text_field($_POST['session_type'] ?? '1on1'),
            'location_text' => sanitize_text_field($_POST['location_text'] ?? ''),
            'price' => $price,
            'trainer_payout' => $trainer_payout,
            'internal_notes' => sanitize_textarea_field($_POST['internal_notes'] ?? ''),
            'created_by' => get_current_user_id(),
        );
        
        $result = $wpdb->insert("{$wpdb->prefix}ptp_sessions", $data);
        
        if ($result) {
            wp_send_json_success(array('id' => $wpdb->insert_id));
        } else {
            wp_send_json_error('Failed to create session: ' . $wpdb->last_error);
        }
    }
    
    public function ajax_update_session() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $id = sanitize_text_field($_POST['id'] ?? '');
        $source = 'admin';
        
        if (strpos($id, 'booking_') === 0) {
            $source = 'booking';
            $id = intval(str_replace('booking_', '', $id));
        } else {
            $id = intval(str_replace('session_', '', $id));
        }
        
        $duration = intval($_POST['duration_minutes'] ?? 60);
        $start_time = sanitize_text_field($_POST['start_time']);
        $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
        
        if ($source === 'booking') {
            $data = array(
                'trainer_id' => intval($_POST['trainer_id']),
                'session_date' => sanitize_text_field($_POST['session_date']),
                'start_time' => $start_time,
                'end_time' => $end_time,
                'duration_minutes' => $duration,
                'status' => sanitize_text_field($_POST['session_status'] ?? 'scheduled'),
                'payment_status' => sanitize_text_field($_POST['payment_status'] ?? 'unpaid'),
                'location' => sanitize_text_field($_POST['location_text'] ?? ''),
                'notes' => sanitize_textarea_field($_POST['internal_notes'] ?? ''),
            );
            
            $result = $wpdb->update("{$wpdb->prefix}ptp_bookings", $data, array('id' => $id));
        } else {
            $price = floatval($_POST['price'] ?? 0);
            $platform_fee = floatval(get_option('ptp_platform_fee_percent', 25)) / 100;
            $trainer_payout = $price * (1 - $platform_fee);
            
            $data = array(
                'trainer_id' => intval($_POST['trainer_id']),
                'customer_id' => intval($_POST['customer_id']) ?: null,
                'parent_id' => intval($_POST['parent_id']) ?: null,
                'player_id' => intval($_POST['player_id']) ?: null,
                'player_name' => sanitize_text_field($_POST['player_name']),
                'player_age' => intval($_POST['player_age']) ?: null,
                'session_date' => sanitize_text_field($_POST['session_date']),
                'start_time' => $start_time,
                'end_time' => $end_time,
                'duration_minutes' => $duration,
                'session_status' => sanitize_text_field($_POST['session_status'] ?? 'scheduled'),
                'payment_status' => sanitize_text_field($_POST['payment_status'] ?? 'unpaid'),
                'session_type' => sanitize_text_field($_POST['session_type'] ?? '1on1'),
                'location_text' => sanitize_text_field($_POST['location_text'] ?? ''),
                'price' => $price,
                'trainer_payout' => $trainer_payout,
                'internal_notes' => sanitize_textarea_field($_POST['internal_notes'] ?? ''),
            );
            
            $result = $wpdb->update("{$wpdb->prefix}ptp_sessions", $data, array('id' => $id));
        }
        
        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to update: ' . $wpdb->last_error);
        }
    }
    
    public function ajax_delete_session() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $id = sanitize_text_field($_POST['id'] ?? '');
        
        if (strpos($id, 'booking_') === 0) {
            $record_id = intval(str_replace('booking_', '', $id));
            $result = $wpdb->update(
                "{$wpdb->prefix}ptp_bookings",
                array('status' => 'cancelled'),
                array('id' => $record_id)
            );
        } else {
            $record_id = intval(str_replace('session_', '', $id));
            $result = $wpdb->delete("{$wpdb->prefix}ptp_sessions", array('id' => $record_id));
        }
        
        if ($result !== false) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Delete failed');
        }
    }
    
    public function ajax_search_customers() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $q = sanitize_text_field($_GET['q'] ?? '');
        
        if (strlen($q) < 2) {
            wp_send_json_success(array());
        }
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID as user_id, u.display_name, u.user_email, p.id as parent_id
             FROM {$wpdb->users} u
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON u.ID = p.user_id
             WHERE u.display_name LIKE %s OR u.user_email LIKE %s
             LIMIT 10",
            '%' . $wpdb->esc_like($q) . '%',
            '%' . $wpdb->esc_like($q) . '%'
        ));
        
        wp_send_json_success($results);
    }
    
    public function ajax_search_players() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $parent_id = intval($_GET['parent_id'] ?? 0);
        
        if (!$parent_id) {
            wp_send_json_success(array());
        }
        
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, age FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d ORDER BY name",
            $parent_id
        ));
        
        wp_send_json_success($players);
    }
    
    public function ajax_get_trainer_stats() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $start = sanitize_text_field($_GET['start'] ?? date('Y-m-d'));
        $end = sanitize_text_field($_GET['end'] ?? date('Y-m-d', strtotime('+7 days')));
        
        // Get session counts
        $session_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT trainer_id, COUNT(*) as cnt 
             FROM {$wpdb->prefix}ptp_sessions 
             WHERE session_date BETWEEN %s AND %s AND session_status NOT IN ('cancelled')
             GROUP BY trainer_id",
            $start, $end
        ), OBJECT_K);
        
        // Get booking counts
        $booking_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT trainer_id, COUNT(*) as cnt 
             FROM {$wpdb->prefix}ptp_bookings 
             WHERE session_date BETWEEN %s AND %s AND status NOT IN ('cancelled')
             GROUP BY trainer_id",
            $start, $end
        ), OBJECT_K);
        
        $result = array();
        foreach ($session_counts as $tid => $row) {
            $result[$tid] = intval($row->cnt);
        }
        foreach ($booking_counts as $tid => $row) {
            $result[$tid] = ($result[$tid] ?? 0) + intval($row->cnt);
        }
        
        wp_send_json_success($result);
    }
    
    public function ajax_get_trainer_availability() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $trainer_id = intval($_GET['trainer_id'] ?? 0);
        $date = sanitize_text_field($_GET['date'] ?? '');
        
        if (!$trainer_id || !$date) {
            wp_send_json_error('Missing parameters');
        }
        
        // Get trainer's availability settings
        $availability = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_availability 
             WHERE trainer_id = %d AND day_of_week = %d AND is_available = 1",
            $trainer_id,
            date('w', strtotime($date))
        ));
        
        // Get existing sessions/bookings for the day
        $busy_times = $wpdb->get_results($wpdb->prepare(
            "SELECT start_time, end_time FROM {$wpdb->prefix}ptp_sessions 
             WHERE trainer_id = %d AND session_date = %s AND session_status NOT IN ('cancelled')
             UNION
             SELECT start_time, end_time FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date = %s AND status NOT IN ('cancelled')",
            $trainer_id, $date, $trainer_id, $date
        ));
        
        wp_send_json_success(array(
            'availability' => $availability,
            'busy' => $busy_times,
        ));
    }
    public function ajax_get_session_detail() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $event_id = sanitize_text_field($_GET['event_id'] ?? '');
        if (!$event_id) {
            wp_send_json_error('Missing event ID');
        }
        
        $is_booking = strpos($event_id, 'booking_') === 0;
        $record_id = intval(str_replace(array('booking_', 'session_'), '', $event_id));
        
        $session = null;
        $source = $is_booking ? 'booking' : 'admin';
        
        if ($is_booking) {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
                        t.hourly_rate as trainer_rate, t.total_sessions as trainer_total_sessions,
                        t.average_rating as trainer_rating, t.review_count as trainer_review_count,
                        t.phone as trainer_phone, t.email as trainer_email, t.slug as trainer_slug,
                        t.stripe_account_id as trainer_stripe, t.city as trainer_city, t.state as trainer_state,
                        p.name as player_name_db, p.age as player_age_db, p.skill_level, p.position as player_position,
                        p.current_team, p.goals as player_goals,
                        pa.display_name as parent_name, pa.email as parent_email_db, pa.phone as parent_phone,
                        pa.total_sessions as parent_total_sessions, pa.total_spent as parent_total_spent,
                        u.user_email as parent_user_email, u.display_name as parent_display_name
                 FROM {$wpdb->prefix}ptp_bookings b
                 LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
                 LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
                 LEFT JOIN {$wpdb->users} u ON pa.user_id = u.ID
                 WHERE b.id = %d",
                $record_id
            ));
        } else {
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT s.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
                        t.hourly_rate as trainer_rate, t.total_sessions as trainer_total_sessions,
                        t.average_rating as trainer_rating, t.review_count as trainer_review_count,
                        t.phone as trainer_phone, t.email as trainer_email, t.slug as trainer_slug,
                        t.stripe_account_id as trainer_stripe, t.city as trainer_city, t.state as trainer_state,
                        p.name as player_name_db, p.age as player_age_db, p.skill_level, p.position as player_position,
                        p.current_team, p.goals as player_goals,
                        pa.display_name as parent_name, pa.email as parent_email_db, pa.phone as parent_phone,
                        pa.total_sessions as parent_total_sessions, pa.total_spent as parent_total_spent,
                        u.display_name as parent_display_name, u.user_email as parent_user_email
                 FROM {$wpdb->prefix}ptp_sessions s
                 LEFT JOIN {$wpdb->prefix}ptp_trainers t ON s.trainer_id = t.id
                 LEFT JOIN {$wpdb->prefix}ptp_players p ON s.player_id = p.id
                 LEFT JOIN {$wpdb->prefix}ptp_parents pa ON s.parent_id = pa.id
                 LEFT JOIN {$wpdb->users} u ON pa.user_id = u.ID
                 WHERE s.id = %d",
                $record_id
            ));
        }
        
        if (!$session) {
            wp_send_json_error('Session not found');
        }
        
        // Get booking history between this parent and trainer
        $history = array();
        $parent_id = $session->parent_id ?? null;
        $trainer_id = $session->trainer_id ?? null;
        
        if ($parent_id && $trainer_id) {
            $past_bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT id, session_date, start_time, status, payment_status, total_amount, 'booking' as source
                 FROM {$wpdb->prefix}ptp_bookings 
                 WHERE parent_id = %d AND trainer_id = %d AND id != %d
                 ORDER BY session_date DESC LIMIT 10",
                $parent_id, $trainer_id, $is_booking ? $record_id : 0
            ));
            
            $past_sessions = $wpdb->get_results($wpdb->prepare(
                "SELECT id, session_date, start_time, session_status as status, payment_status, price as total_amount, 'admin' as source
                 FROM {$wpdb->prefix}ptp_sessions 
                 WHERE parent_id = %d AND trainer_id = %d AND id != %d
                 ORDER BY session_date DESC LIMIT 10",
                $parent_id, $trainer_id, $is_booking ? 0 : $record_id
            ));
            
            $history = array_merge($past_bookings, $past_sessions);
            usort($history, function($a, $b) { return strcmp($b->session_date, $a->session_date); });
            $history = array_slice($history, 0, 10);
        }
        
        // Get the latest review from this parent for this trainer
        $review = null;
        if ($parent_id && $trainer_id) {
            $review = $wpdb->get_row($wpdb->prepare(
                "SELECT rating, review_text, created_at FROM {$wpdb->prefix}ptp_reviews 
                 WHERE trainer_id = %d AND parent_id = %d 
                 ORDER BY created_at DESC LIMIT 1",
                $trainer_id, $parent_id
            ));
        }
        
        // Get upcoming sessions with this player
        $upcoming = array();
        $player_name = $session->player_name_db ?? $session->player_name ?? '';
        $today = date('Y-m-d');
        if ($player_name && $trainer_id) {
            $upcoming_bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT b.id, b.session_date, b.start_time, b.status, b.total_amount, 'booking' as source
                 FROM {$wpdb->prefix}ptp_bookings b
                 LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
                 WHERE b.trainer_id = %d AND b.session_date >= %s AND b.status NOT IN ('cancelled')
                 AND (p.name LIKE %s OR b.player_id = %d)
                 AND b.id != %d
                 ORDER BY b.session_date, b.start_time LIMIT 5",
                $trainer_id, $today, $player_name, intval($session->player_id ?? 0), $is_booking ? $record_id : 0
            ));
            
            $upcoming_sessions = $wpdb->get_results($wpdb->prepare(
                "SELECT id, session_date, start_time, session_status as status, price as total_amount, 'admin' as source
                 FROM {$wpdb->prefix}ptp_sessions 
                 WHERE trainer_id = %d AND session_date >= %s AND session_status NOT IN ('cancelled')
                 AND player_name LIKE %s
                 AND id != %d
                 ORDER BY session_date, start_time LIMIT 5",
                $trainer_id, $today, $player_name, $is_booking ? 0 : $record_id
            ));
            
            $upcoming = array_merge($upcoming_bookings, $upcoming_sessions);
            usort($upcoming, function($a, $b) { return strcmp($a->session_date, $b->session_date); });
            $upcoming = array_slice($upcoming, 0, 5);
        }
        
        // Build response
        $status = $is_booking ? ($session->status ?? 'scheduled') : ($session->session_status ?? 'scheduled');
        $price = $is_booking ? ($session->total_amount ?? 0) : ($session->price ?? 0);
        $platform_fee_rate = floatval(get_option('ptp_platform_fee_percent', 25)) / 100;
        $platform_fee = $price * $platform_fee_rate;
        $trainer_payout = $is_booking ? ($session->trainer_payout ?? ($price - $platform_fee)) : ($session->trainer_payout ?? ($price - $platform_fee));
        
        $data = array(
            'event_id' => $event_id,
            'source' => $source,
            'record_id' => $record_id,
            'status' => $status,
            'payment_status' => $session->payment_status ?? 'unpaid',
            'session_date' => $session->session_date,
            'start_time' => $session->start_time,
            'end_time' => $session->end_time ?? date('H:i:s', strtotime($session->start_time) + 3600),
            'duration_minutes' => $session->duration_minutes ?? 60,
            'session_type' => $session->session_type ?? '1on1',
            'location' => $is_booking ? ($session->location ?? '') : ($session->location_text ?? ''),
            'booking_number' => $session->booking_number ?? null,
            'notes' => $is_booking ? ($session->notes ?? '') : ($session->internal_notes ?? ''),
            'created_at' => $session->created_at ?? null,
            
            // Financial
            'price' => floatval($price),
            'platform_fee' => round($platform_fee, 2),
            'trainer_payout' => round(floatval($trainer_payout), 2),
            'payment_intent_id' => $session->payment_intent_id ?? null,
            'stripe_transfer_id' => $session->stripe_transfer_id ?? null,
            'coupon_code' => $session->coupon_code ?? null,
            'coupon_discount' => $session->coupon_discount ?? 0,
            'referral_code' => $session->referral_code ?? null,
            
            // Trainer
            'trainer' => array(
                'id' => $trainer_id,
                'name' => $session->trainer_name ?? 'Unassigned',
                'photo' => $session->trainer_photo ?? '',
                'rate' => floatval($session->trainer_rate ?? 0),
                'total_sessions' => intval($session->trainer_total_sessions ?? 0),
                'rating' => floatval($session->trainer_rating ?? 0),
                'review_count' => intval($session->trainer_review_count ?? 0),
                'phone' => $session->trainer_phone ?? '',
                'email' => $session->trainer_email ?? '',
                'slug' => $session->trainer_slug ?? '',
                'stripe_connected' => !empty($session->trainer_stripe),
                'city' => $session->trainer_city ?? '',
                'state' => $session->trainer_state ?? '',
            ),
            
            // Player
            'player' => array(
                'id' => $session->player_id ?? null,
                'name' => $session->player_name_db ?? $session->player_name ?? '',
                'age' => $session->player_age_db ?? $session->player_age ?? null,
                'skill_level' => $session->skill_level ?? '',
                'position' => $session->player_position ?? '',
                'team' => $session->current_team ?? '',
                'goals' => $session->player_goals ?? '',
            ),
            
            // Parent
            'parent' => array(
                'id' => $parent_id,
                'name' => $session->parent_name ?? $session->parent_display_name ?? '',
                'email' => $session->parent_email_db ?? $session->parent_user_email ?? '',
                'phone' => $session->parent_phone ?? '',
                'total_sessions' => intval($session->parent_total_sessions ?? 0),
                'total_spent' => floatval($session->parent_total_spent ?? 0),
            ),
            
            // History
            'history' => array_map(function($h) {
                return array(
                    'id' => $h->id,
                    'date' => $h->session_date,
                    'time' => $h->start_time,
                    'status' => $h->status,
                    'payment' => $h->payment_status ?? 'unpaid',
                    'amount' => floatval($h->total_amount ?? 0),
                    'source' => $h->source,
                );
            }, $history),
            
            // Review
            'review' => $review ? array(
                'rating' => intval($review->rating),
                'text' => $review->review_text,
                'date' => $review->created_at,
            ) : null,
            
            // Upcoming
            'upcoming' => array_map(function($u) {
                return array(
                    'id' => $u->id,
                    'date' => $u->session_date,
                    'time' => $u->start_time,
                    'status' => $u->status,
                    'amount' => floatval($u->total_amount ?? 0),
                    'source' => $u->source,
                );
            }, $upcoming),
        );
        
        wp_send_json_success($data);
    }
    
    public function ajax_save_note() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $note = sanitize_textarea_field($_POST['note'] ?? '');
        
        if (!$event_id) {
            wp_send_json_error('Missing event ID');
        }
        
        $is_booking = strpos($event_id, 'booking_') === 0;
        $record_id = intval(str_replace(array('booking_', 'session_'), '', $event_id));
        
        if ($is_booking) {
            $result = $wpdb->update(
                "{$wpdb->prefix}ptp_bookings",
                array('notes' => $note),
                array('id' => $record_id)
            );
        } else {
            $result = $wpdb->update(
                "{$wpdb->prefix}ptp_sessions",
                array('internal_notes' => $note),
                array('id' => $record_id)
            );
        }
        
        if ($result !== false) {
            wp_send_json_success(array('note' => $note));
        } else {
            wp_send_json_error('Failed to save note');
        }
    }
    
    public function ajax_toggle_payment() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $new_status = sanitize_text_field($_POST['payment_status'] ?? 'paid');
        
        if (!$event_id) {
            wp_send_json_error('Missing event ID');
        }
        
        $is_booking = strpos($event_id, 'booking_') === 0;
        $record_id = intval(str_replace(array('booking_', 'session_'), '', $event_id));
        
        if ($is_booking) {
            $result = $wpdb->update(
                "{$wpdb->prefix}ptp_bookings",
                array('payment_status' => $new_status),
                array('id' => $record_id)
            );
        } else {
            $result = $wpdb->update(
                "{$wpdb->prefix}ptp_sessions",
                array('payment_status' => $new_status),
                array('id' => $record_id)
            );
        }
        
        if ($result !== false) {
            wp_send_json_success(array('payment_status' => $new_status));
        } else {
            wp_send_json_error('Failed to update payment');
        }
    }
    
}

// Initialize
PTP_Schedule_Calendar_V2::init();
