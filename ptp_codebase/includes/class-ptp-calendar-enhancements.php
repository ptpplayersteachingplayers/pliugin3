<?php
/**
 * PTP Calendar Enhancements v125
 * 
 * Features:
 * - Recurring session creation (weekly, bi-weekly)
 * - Conflict detection (double-booking prevention)
 * - Session reminders (email + SMS)
 * 
 * @since 125.0.0
 */

defined('ABSPATH') || exit;

class PTP_Calendar_Enhancements {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_ptp_create_recurring_sessions', array($this, 'ajax_create_recurring'));
        add_action('wp_ajax_ptp_check_conflicts', array($this, 'ajax_check_conflicts'));
        add_action('wp_ajax_ptp_send_session_reminder', array($this, 'ajax_send_reminder'));
        add_action('wp_ajax_ptp_get_trainer_conflicts', array($this, 'ajax_get_trainer_conflicts'));
        
        // Cron for automated reminders
        add_action('ptp_send_session_reminders', array($this, 'send_automated_reminders'));
    }
    
    /**
     * Schedule reminder cron (called on init)
     */
    public static function schedule_cron() {
        if (!wp_next_scheduled('ptp_send_session_reminders')) {
            wp_schedule_event(time(), 'hourly', 'ptp_send_session_reminders');
        }
    }
    
    /**
     * Create recurring sessions
     */
    public function ajax_create_recurring() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $parent_id = intval($_POST['parent_id'] ?? 0) ?: null;
        $player_name = sanitize_text_field($_POST['player_name'] ?? '');
        $player_age = intval($_POST['player_age'] ?? 0) ?: null;
        $day_of_week = intval($_POST['day_of_week']);
        $start_time = sanitize_text_field($_POST['start_time']);
        $duration = intval($_POST['duration_minutes'] ?? 60);
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'weekly');
        $start_date = sanitize_text_field($_POST['start_date']);
        $session_count = intval($_POST['session_count'] ?? 8);
        $location = sanitize_text_field($_POST['location_text'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        
        if (!$trainer_id || !$start_date) {
            wp_send_json_error('Missing required fields');
        }
        
        // Calculate dates
        $dates = $this->calculate_recurring_dates($start_date, $day_of_week, $frequency, $session_count);
        
        // v194: Graduated platform fee
        // For recurring sessions, each one advances the counter
        // We calculate per-session in the loop below
        
        $created = 0;
        $skipped = 0;
        
        $session_offset = 0; // Track sessions created for graduated fee
        foreach ($dates as $date) {
            // Check for conflicts
            if ($this->has_conflict($trainer_id, $date, $start_time, $duration)) {
                $skipped++;
                continue;
            }
            
            // v194: Graduated fee per session
            $session_offset++;
            if (class_exists('PTP_Unified_Checkout') && $parent_id) {
                $sess_fee = PTP_Unified_Checkout::calculate_graduated_fee($trainer_id, $parent_id, $price, 1);
                $trainer_payout = $sess_fee['trainer_payout'];
            } else {
                $fallback_pct = floatval(get_option('ptp_platform_fee_percent', 25)) / 100;
                $trainer_payout = $price * (1 - $fallback_pct);
            }
            
            $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
            
            $wpdb->insert(
                $wpdb->prefix . 'ptp_sessions',
                array(
                    'trainer_id' => $trainer_id,
                    'parent_id' => $parent_id,
                    'player_name' => $player_name,
                    'player_age' => $player_age,
                    'session_date' => $date,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'duration_minutes' => $duration,
                    'session_status' => 'scheduled',
                    'payment_status' => 'unpaid',
                    'session_type' => '1on1',
                    'location_text' => $location,
                    'price' => $price,
                    'trainer_payout' => $trainer_payout,
                    'internal_notes' => 'Recurring session - ' . ucfirst($frequency),
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s')
            );
            
            if ($wpdb->insert_id) {
                $created++;
            }
        }
        
        wp_send_json_success(array(
            'created' => $created,
            'skipped' => $skipped,
        ));
    }
    
    /**
     * Calculate recurring dates
     */
    private function calculate_recurring_dates($start_date, $day_of_week, $frequency, $count) {
        $dates = array();
        $interval = $frequency === 'biweekly' ? 14 : 7;
        
        // Find first occurrence on the target day
        $current = new DateTime($start_date);
        $target_day = $day_of_week; // 0=Sun, 1=Mon, etc.
        
        // Adjust to first occurrence
        $current_day = (int)$current->format('w');
        $diff = $target_day - $current_day;
        if ($diff < 0) $diff += 7;
        $current->modify("+{$diff} days");
        
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $current->format('Y-m-d');
            $current->modify("+{$interval} days");
        }
        
        return $dates;
    }
    
    /**
     * Check for conflicts (preview)
     */
    public function ajax_check_conflicts() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        $trainer_id = intval($_POST['trainer_id']);
        $day_of_week = intval($_POST['day_of_week']);
        $start_time = sanitize_text_field($_POST['start_time']);
        $duration = intval($_POST['duration_minutes'] ?? 60);
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'weekly');
        $start_date = sanitize_text_field($_POST['start_date']);
        $session_count = intval($_POST['session_count'] ?? 8);
        
        $dates = $this->calculate_recurring_dates($start_date, $day_of_week, $frequency, $session_count);
        
        $sessions = array();
        $conflicts = array();
        
        foreach ($dates as $date) {
            $formatted_date = date('D, M j', strtotime($date));
            $sessions[] = array(
                'date' => $formatted_date,
                'time' => date('g:i A', strtotime($start_time)),
            );
            
            if ($this->has_conflict($trainer_id, $date, $start_time, $duration)) {
                $conflicts[] = array(
                    'date' => $formatted_date,
                    'reason' => 'Trainer already has a session',
                );
            }
        }
        
        wp_send_json_success(array(
            'sessions' => $sessions,
            'conflicts' => $conflicts,
        ));
    }
    
    /**
     * Check if trainer has a conflict at the given time
     */
    private function has_conflict($trainer_id, $date, $start_time, $duration) {
        global $wpdb;
        
        $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
        
        // Check sessions table
        $session_conflict = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_sessions
            WHERE trainer_id = %d 
            AND session_date = %s
            AND session_status NOT IN ('cancelled')
            AND (
                (start_time <= %s AND end_time > %s)
                OR (start_time < %s AND end_time >= %s)
                OR (start_time >= %s AND end_time <= %s)
            )
        ", $trainer_id, $date, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time));
        
        if ($session_conflict > 0) return true;
        
        // Check bookings table
        $booking_conflict = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d 
            AND session_date = %s
            AND status NOT IN ('cancelled')
            AND (
                (start_time <= %s AND end_time > %s)
                OR (start_time < %s AND end_time >= %s)
                OR (start_time >= %s AND end_time <= %s)
            )
        ", $trainer_id, $date, $start_time, $start_time, $end_time, $end_time, $start_time, $end_time));
        
        return $booking_conflict > 0;
    }
    
    /**
     * Get all trainer conflicts in a date range
     */
    public function ajax_get_trainer_conflicts() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        global $wpdb;
        
        $days = intval($_GET['days'] ?? 30);
        $start = date('Y-m-d');
        $end = date('Y-m-d', strtotime("+{$days} days"));
        
        // Find overlapping sessions
        $conflicts = $wpdb->get_results($wpdb->prepare("
            SELECT 
                s1.id as session1_id,
                s2.id as session2_id,
                t.display_name as trainer,
                s1.session_date as date,
                s1.start_time as time
            FROM {$wpdb->prefix}ptp_sessions s1
            JOIN {$wpdb->prefix}ptp_sessions s2 ON s1.trainer_id = s2.trainer_id 
                AND s1.session_date = s2.session_date
                AND s1.id < s2.id
                AND s1.session_status NOT IN ('cancelled')
                AND s2.session_status NOT IN ('cancelled')
                AND (
                    (s1.start_time <= s2.start_time AND s1.end_time > s2.start_time)
                    OR (s2.start_time <= s1.start_time AND s2.end_time > s1.start_time)
                )
            JOIN {$wpdb->prefix}ptp_trainers t ON s1.trainer_id = t.id
            WHERE s1.session_date BETWEEN %s AND %s
            ORDER BY s1.session_date, s1.start_time
        ", $start, $end));
        
        $all_conflicts = array_map(function($c) {
            return array(
                'trainer' => $c->trainer,
                'date' => date('D, M j', strtotime($c->date)),
                'time' => date('g:i A', strtotime($c->time)),
                'type' => 'session-session',
            );
        }, $conflicts);
        
        wp_send_json_success($all_conflicts);
    }
    
    /**
     * Send manual reminder for a session
     */
    public function ajax_send_reminder() {
        check_ajax_referer('ptp_schedule_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        $session_id = sanitize_text_field($_POST['id'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? 'email');
        
        $result = $this->send_reminder($session_id, $type);
        
        if ($result) {
            wp_send_json_success('Reminder sent');
        } else {
            wp_send_json_error('Failed to send reminder');
        }
    }
    
    /**
     * Send reminder for a session
     */
    public function send_reminder($session_id, $type = 'email') {
        global $wpdb;
        
        // Determine if it's a booking or session
        if (strpos($session_id, 'booking_') === 0) {
            $id = intval(str_replace('booking_', '', $session_id));
            $session = $wpdb->get_row($wpdb->prepare("
                SELECT b.*, t.display_name as trainer_name, t.user_id as trainer_user_id,
                       p.user_id as parent_user_id
                FROM {$wpdb->prefix}ptp_bookings b
                JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
                WHERE b.id = %d
            ", $id));
        } else {
            $id = intval(str_replace('session_', '', $session_id));
            $session = $wpdb->get_row($wpdb->prepare("
                SELECT s.*, t.display_name as trainer_name, t.user_id as trainer_user_id,
                       p.user_id as parent_user_id
                FROM {$wpdb->prefix}ptp_sessions s
                JOIN {$wpdb->prefix}ptp_trainers t ON s.trainer_id = t.id
                LEFT JOIN {$wpdb->prefix}ptp_parents p ON s.parent_id = p.id
                WHERE s.id = %d
            ", $id));
        }
        
        if (!$session) return false;
        
        // Get user emails
        $trainer_user = get_userdata($session->trainer_user_id);
        $trainer_email = $trainer_user ? $trainer_user->user_email : '';
        $parent_email = '';
        if (!empty($session->parent_user_id)) {
            $parent_user = get_userdata($session->parent_user_id);
            $parent_email = $parent_user ? $parent_user->user_email : '';
        }
        
        $date = date('l, F j', strtotime($session->session_date));
        $time = date('g:i A', strtotime($session->start_time));
        $location = isset($session->location) ? $session->location : (isset($session->location_text) ? $session->location_text : 'TBD');
        $player = isset($session->player_name) ? $session->player_name : 'Player';
        
        $sent = false;
        
        // Send email reminder
        if ($type === 'email' || $type === 'both') {
            // To parent
            if ($parent_email) {
                $subject = "Training Reminder: {$date} at {$time}";
                $message = "Hi,\n\nThis is a reminder about your upcoming training session:\n\n";
                $message .= "Date: {$date}\n";
                $message .= "Time: {$time}\n";
                $message .= "Trainer: {$session->trainer_name}\n";
                $message .= "Location: {$location}\n\n";
                $message .= "See you there!\n\n- PTP Soccer";
                
                wp_mail($parent_email, $subject, $message);
                $sent = true;
            }
            
            // To trainer
            if ($trainer_email) {
                $subject = "Session Reminder: {$date} at {$time}";
                $message = "Hi {$session->trainer_name},\n\nThis is a reminder about your upcoming session:\n\n";
                $message .= "Date: {$date}\n";
                $message .= "Time: {$time}\n";
                $message .= "Player: {$player}\n";
                $message .= "Location: {$location}\n\n";
                $message .= "Good luck!\n\n- PTP Soccer";
                
                wp_mail($trainer_email, $subject, $message);
                $sent = true;
            }
        }
        
        return $sent;
    }
    
    /**
     * Send automated reminders (called by cron)
     */
    public function send_automated_reminders() {
        global $wpdb;
        
        // Get sessions happening in 24 hours that haven't been reminded
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        // Check if reminder_sent column exists
        $col = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}ptp_sessions LIKE 'reminder_sent'");
        if (!$col) {
            // Column doesn't exist yet, skip
            return;
        }
        
        // Sessions
        $sessions = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}ptp_sessions
            WHERE session_date = %s
            AND session_status IN ('scheduled', 'confirmed')
            AND (reminder_sent IS NULL OR reminder_sent = 0)
        ", $tomorrow));
        
        foreach ($sessions as $s) {
            $this->send_reminder('session_' . $s->id, 'email');
            $wpdb->update(
                $wpdb->prefix . 'ptp_sessions',
                array('reminder_sent' => 1),
                array('id' => $s->id)
            );
        }
        
        // Bookings
        $col = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings LIKE 'reminder_sent'");
        if (!$col) return;
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}ptp_bookings
            WHERE session_date = %s
            AND status IN ('pending', 'confirmed')
            AND (reminder_sent IS NULL OR reminder_sent = 0)
        ", $tomorrow));
        
        foreach ($bookings as $b) {
            $this->send_reminder('booking_' . $b->id, 'email');
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('reminder_sent' => 1),
                array('id' => $b->id)
            );
        }
    }
    
    /**
     * Add reminder_sent column if it doesn't exist
     */
    public static function ensure_reminder_columns() {
        global $wpdb;
        
        // Sessions table
        $col = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}ptp_sessions LIKE 'reminder_sent'");
        if (!$col) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}ptp_sessions ADD COLUMN reminder_sent tinyint(1) DEFAULT 0");
        }
        
        // Bookings table  
        $col = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings LIKE 'reminder_sent'");
        if (!$col) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}ptp_bookings ADD COLUMN reminder_sent tinyint(1) DEFAULT 0");
        }
    }
}

// Initialize on plugins_loaded
add_action('plugins_loaded', array('PTP_Calendar_Enhancements', 'instance'));

// Schedule cron on init (safer timing)
add_action('init', array('PTP_Calendar_Enhancements', 'schedule_cron'));
