<?php
/**
 * PTP Availability Class - v71.0.0
 * 
 * Enhanced features:
 * - Bridge availability (on/off/on periods throughout the day)
 * - Recurring weekly schedules
 * - Google Calendar integration
 * - Mobile-optimized AJAX handlers
 * 
 * @since 71.0.0
 */

defined('ABSPATH') || exit;

class PTP_Availability_V71 {
    
    private static $table_verified = false;
    private static $blocks_table_verified = false;
    
    /**
     * Initialize
     */
    public static function init() {
        self::$table_verified = false;
        self::$blocks_table_verified = false;
        
        add_action('admin_init', array(__CLASS__, 'maybe_create_tables'), 5);
        add_action('init', array(__CLASS__, 'ensure_tables'), 5);
        
        // AJAX handlers
        add_action('wp_ajax_ptp_save_availability_blocks', array(__CLASS__, 'ajax_save_blocks'));
        add_action('wp_ajax_ptp_get_availability_blocks', array(__CLASS__, 'ajax_get_blocks'));
        add_action('wp_ajax_ptp_copy_schedule_to_days', array(__CLASS__, 'ajax_copy_schedule'));
        add_action('wp_ajax_ptp_clear_day_schedule', array(__CLASS__, 'ajax_clear_day'));
        add_action('wp_ajax_ptp_save_recurring_schedule', array(__CLASS__, 'ajax_save_recurring'));
        
        // Public AJAX for booking calendar
        add_action('wp_ajax_ptp_get_available_time_slots', array(__CLASS__, 'ajax_get_time_slots'));
        add_action('wp_ajax_nopriv_ptp_get_available_time_slots', array(__CLASS__, 'ajax_get_time_slots'));
    }
    
    /**
     * Ensure tables exist
     */
    public static function maybe_create_tables() {
        self::ensure_tables();
    }
    
    /**
     * Create/verify all availability tables
     */
    public static function ensure_tables() {
        self::ensure_availability_table();
        self::ensure_blocks_table();
    }
    
    /**
     * Ensure main availability table
     */
    private static function ensure_availability_table() {
        if (self::$table_verified) return true;
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ptp_availability';
        
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        
        if ($table_exists !== $table_name) {
            $charset = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id bigint(20) UNSIGNED NOT NULL,
                day_of_week tinyint(1) NOT NULL DEFAULT 0,
                start_time time NOT NULL DEFAULT '09:00:00',
                end_time time NOT NULL DEFAULT '17:00:00',
                slot_duration int(11) DEFAULT 60,
                is_active tinyint(1) DEFAULT 1,
                is_recurring tinyint(1) DEFAULT 1,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY trainer_day (trainer_id, day_of_week),
                KEY trainer_id (trainer_id),
                KEY is_active (is_active)
            ) $charset;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        self::$table_verified = true;
        return true;
    }
    
    /**
     * Ensure availability blocks table for bridge scheduling
     */
    private static function ensure_blocks_table() {
        if (self::$blocks_table_verified) return true;
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ptp_availability_blocks';
        
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        
        if ($table_exists !== $table_name) {
            $charset = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id bigint(20) UNSIGNED NOT NULL,
                day_of_week tinyint(1) NOT NULL DEFAULT 0,
                block_type enum('available','unavailable') NOT NULL DEFAULT 'available',
                start_time time NOT NULL,
                end_time time NOT NULL,
                label varchar(100) DEFAULT '',
                sort_order int(11) DEFAULT 0,
                is_recurring tinyint(1) DEFAULT 1,
                specific_date date DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY trainer_day (trainer_id, day_of_week),
                KEY trainer_date (trainer_id, specific_date),
                KEY block_type (block_type)
            ) $charset;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        self::$blocks_table_verified = true;
        return true;
    }
    
    /**
     * Get availability blocks for a trainer's day
     * 
     * @param int $trainer_id
     * @param int $day_of_week 0=Sun, 1=Mon, etc.
     * @param string|null $specific_date YYYY-MM-DD for specific date overrides
     * @return array
     */
    public static function get_day_blocks($trainer_id, $day_of_week, $specific_date = null) {
        global $wpdb;
        self::ensure_blocks_table();
        
        $table = $wpdb->prefix . 'ptp_availability_blocks';
        
        // First check for specific date overrides
        if ($specific_date) {
            $specific_blocks = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE trainer_id = %d AND specific_date = %s ORDER BY start_time ASC",
                $trainer_id, $specific_date
            ));
            
            if (!empty($specific_blocks)) {
                return $specific_blocks;
            }
        }
        
        // Fall back to recurring schedule
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE trainer_id = %d AND day_of_week = %d AND is_recurring = 1 AND specific_date IS NULL ORDER BY start_time ASC",
            $trainer_id, $day_of_week
        ));
    }
    
    /**
     * Get all weekly blocks for trainer
     */
    public static function get_weekly_blocks($trainer_id) {
        global $wpdb;
        self::ensure_blocks_table();
        
        $table = $wpdb->prefix . 'ptp_availability_blocks';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE trainer_id = %d AND is_recurring = 1 AND specific_date IS NULL ORDER BY day_of_week ASC, start_time ASC",
            $trainer_id
        ));
        
        // Organize by day
        $by_day = array();
        for ($i = 0; $i < 7; $i++) {
            $by_day[$i] = array();
        }
        
        foreach ($results as $block) {
            $by_day[intval($block->day_of_week)][] = $block;
        }
        
        return $by_day;
    }
    
    /**
     * Save availability blocks for a day
     * 
     * @param int $trainer_id
     * @param int $day_of_week
     * @param array $blocks Array of block data [{type, start, end, label}]
     * @param bool $is_recurring
     * @param string|null $specific_date
     */
    public static function save_day_blocks($trainer_id, $day_of_week, $blocks, $is_recurring = true, $specific_date = null) {
        global $wpdb;
        self::ensure_blocks_table();
        
        $table = $wpdb->prefix . 'ptp_availability_blocks';
        
        // Delete existing blocks for this day
        if ($specific_date) {
            $wpdb->delete($table, array(
                'trainer_id' => $trainer_id,
                'specific_date' => $specific_date
            ));
        } else {
            $wpdb->delete($table, array(
                'trainer_id' => $trainer_id,
                'day_of_week' => $day_of_week,
                'is_recurring' => $is_recurring ? 1 : 0,
                'specific_date' => null
            ));
            // Also explicitly handle NULL
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE trainer_id = %d AND day_of_week = %d AND is_recurring = %d AND specific_date IS NULL",
                $trainer_id, $day_of_week, $is_recurring ? 1 : 0
            ));
        }
        
        // Insert new blocks
        $sort = 0;
        foreach ($blocks as $block) {
            if (empty($block['start']) || empty($block['end'])) continue;
            
            $wpdb->insert($table, array(
                'trainer_id' => $trainer_id,
                'day_of_week' => $day_of_week,
                'block_type' => $block['type'] === 'unavailable' ? 'unavailable' : 'available',
                'start_time' => self::format_time($block['start']),
                'end_time' => self::format_time($block['end']),
                'label' => sanitize_text_field($block['label'] ?? ''),
                'sort_order' => $sort++,
                'is_recurring' => $is_recurring ? 1 : 0,
                'specific_date' => $specific_date
            ));
        }
        
        // Clear cache
        wp_cache_delete('ptp_avail_blocks_' . $trainer_id, 'ptp');
        
        // Trigger calendar sync
        do_action('ptp_availability_updated', $trainer_id);
        
        return true;
    }
    
    /**
     * Copy schedule from one day to multiple days
     */
    public static function copy_to_days($trainer_id, $source_day, $target_days) {
        $source_blocks = self::get_day_blocks($trainer_id, $source_day);
        
        $blocks_data = array();
        foreach ($source_blocks as $block) {
            $blocks_data[] = array(
                'type' => $block->block_type,
                'start' => $block->start_time,
                'end' => $block->end_time,
                'label' => $block->label
            );
        }
        
        foreach ($target_days as $day) {
            if ($day != $source_day) {
                self::save_day_blocks($trainer_id, $day, $blocks_data);
            }
        }
        
        return true;
    }
    
    /**
     * Get available time slots for booking
     * Accounts for bridge availability and existing bookings
     */
    public static function get_available_slots($trainer_id, $date, $duration = 60) {
        $day_of_week = date('w', strtotime($date));
        $blocks = self::get_day_blocks($trainer_id, $day_of_week, $date);
        
        // Get booked slots for this date
        $booked = self::get_booked_slots($trainer_id, $date);
        
        $slots = array();
        
        foreach ($blocks as $block) {
            if ($block->block_type !== 'available') continue;
            
            $start = strtotime($date . ' ' . $block->start_time);
            $end = strtotime($date . ' ' . $block->end_time);
            
            // Generate slots within this available block
            $current = $start;
            while ($current + ($duration * 60) <= $end) {
                $slot_start = date('H:i', $current);
                $slot_end = date('H:i', $current + ($duration * 60));
                
                // Check if slot conflicts with existing booking
                $is_booked = false;
                foreach ($booked as $booking) {
                    $book_start = strtotime($date . ' ' . $booking->start_time);
                    $book_end = strtotime($date . ' ' . $booking->end_time);
                    
                    if ($current < $book_end && ($current + $duration * 60) > $book_start) {
                        $is_booked = true;
                        break;
                    }
                }
                
                if (!$is_booked) {
                    $slots[] = array(
                        'start' => $slot_start,
                        'end' => $slot_end,
                        'display' => date('g:i A', $current) . ' - ' . date('g:i A', $current + ($duration * 60))
                    );
                }
                
                $current += ($duration * 60);
            }
        }
        
        return $slots;
    }
    
    /**
     * Get booked slots for a date
     */
    private static function get_booked_slots($trainer_id, $date) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array();
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT start_time, end_time FROM $table 
             WHERE trainer_id = %d AND session_date = %s AND status NOT IN ('cancelled', 'refunded')",
            $trainer_id, $date
        ));
    }
    
    /**
     * Format time string to HH:MM:SS
     */
    private static function format_time($time) {
        $time = trim($time);
        if (strlen($time) === 5) {
            return $time . ':00';
        }
        return $time;
    }
    
    /**
     * AJAX: Save availability blocks
     */
    public static function ajax_save_blocks() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in() || !class_exists('PTP_Trainer')) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $day = isset($_POST['day']) ? intval($_POST['day']) : -1;
        if ($day < 0 || $day > 6) {
            wp_send_json_error(array('message' => 'Invalid day'));
            return;
        }
        
        $blocks_json = isset($_POST['blocks']) ? stripslashes($_POST['blocks']) : '[]';
        $blocks = json_decode($blocks_json, true);
        
        if (!is_array($blocks)) {
            wp_send_json_error(array('message' => 'Invalid blocks data'));
            return;
        }
        
        // Validate blocks
        $validated_blocks = array();
        foreach ($blocks as $block) {
            if (empty($block['start']) || empty($block['end'])) continue;
            
            $validated_blocks[] = array(
                'type' => ($block['type'] ?? 'available') === 'unavailable' ? 'unavailable' : 'available',
                'start' => sanitize_text_field($block['start']),
                'end' => sanitize_text_field($block['end']),
                'label' => sanitize_text_field($block['label'] ?? '')
            );
        }
        
        self::save_day_blocks($trainer->id, $day, $validated_blocks);
        
        // Also update legacy availability table for backward compatibility
        if (class_exists('PTP_Availability')) {
            $has_available = false;
            $earliest_start = '23:59';
            $latest_end = '00:00';
            
            foreach ($validated_blocks as $block) {
                if ($block['type'] === 'available') {
                    $has_available = true;
                    if ($block['start'] < $earliest_start) $earliest_start = $block['start'];
                    if ($block['end'] > $latest_end) $latest_end = $block['end'];
                }
            }
            
            PTP_Availability::save_day($trainer->id, $day, $has_available, $earliest_start, $latest_end);
        }
        
        wp_send_json_success(array(
            'message' => 'Schedule saved',
            'day' => $day,
            'blocks' => $validated_blocks
        ));
    }
    
    /**
     * AJAX: Get availability blocks
     */
    public static function ajax_get_blocks() {
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $trainer_id = isset($_REQUEST['trainer_id']) ? intval($_REQUEST['trainer_id']) : 0;
        
        if (!$trainer_id && is_user_logged_in() && class_exists('PTP_Trainer')) {
            $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
            if ($trainer) $trainer_id = $trainer->id;
        }
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer'));
            return;
        }
        
        $weekly = self::get_weekly_blocks($trainer_id);
        
        wp_send_json_success(array('blocks' => $weekly));
    }
    
    /**
     * AJAX: Copy schedule to multiple days
     */
    public static function ajax_copy_schedule() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in() || !class_exists('PTP_Trainer')) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $source = isset($_POST['source_day']) ? intval($_POST['source_day']) : -1;
        $targets_json = isset($_POST['target_days']) ? stripslashes($_POST['target_days']) : '[]';
        $targets = json_decode($targets_json, true);
        
        if ($source < 0 || $source > 6 || !is_array($targets)) {
            wp_send_json_error(array('message' => 'Invalid data'));
            return;
        }
        
        $valid_targets = array_filter($targets, function($d) { return $d >= 0 && $d <= 6; });
        
        self::copy_to_days($trainer->id, $source, $valid_targets);
        
        wp_send_json_success(array('message' => 'Schedule copied to selected days'));
    }
    
    /**
     * AJAX: Clear day schedule
     */
    public static function ajax_clear_day() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in() || !class_exists('PTP_Trainer')) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $day = isset($_POST['day']) ? intval($_POST['day']) : -1;
        if ($day < 0 || $day > 6) {
            wp_send_json_error(array('message' => 'Invalid day'));
            return;
        }
        
        self::save_day_blocks($trainer->id, $day, array());
        
        // Also update legacy table
        if (class_exists('PTP_Availability')) {
            PTP_Availability::save_day($trainer->id, $day, false, '09:00', '17:00');
        }
        
        wp_send_json_success(array('message' => 'Day cleared'));
    }
    
    /**
     * AJAX: Save recurring schedule template
     */
    public static function ajax_save_recurring() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in() || !class_exists('PTP_Trainer')) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $schedule_json = isset($_POST['schedule']) ? stripslashes($_POST['schedule']) : '{}';
        $schedule = json_decode($schedule_json, true);
        
        if (!is_array($schedule)) {
            wp_send_json_error(array('message' => 'Invalid schedule data'));
            return;
        }
        
        // Save each day's blocks
        foreach ($schedule as $day => $blocks) {
            $day = intval($day);
            if ($day < 0 || $day > 6) continue;
            
            $validated = array();
            foreach ($blocks as $block) {
                if (empty($block['start']) || empty($block['end'])) continue;
                $validated[] = array(
                    'type' => ($block['type'] ?? 'available') === 'unavailable' ? 'unavailable' : 'available',
                    'start' => sanitize_text_field($block['start']),
                    'end' => sanitize_text_field($block['end']),
                    'label' => sanitize_text_field($block['label'] ?? '')
                );
            }
            
            self::save_day_blocks($trainer->id, $day, $validated);
        }
        
        wp_send_json_success(array('message' => 'Recurring schedule saved'));
    }
    
    /**
     * AJAX: Get available time slots for booking (public)
     */
    public static function ajax_get_time_slots() {
        $trainer_id = isset($_REQUEST['trainer_id']) ? intval($_REQUEST['trainer_id']) : 0;
        $date = isset($_REQUEST['date']) ? sanitize_text_field($_REQUEST['date']) : '';
        $duration = isset($_REQUEST['duration']) ? intval($_REQUEST['duration']) : 60;
        
        if (!$trainer_id || !$date) {
            wp_send_json_error(array('message' => 'Missing required parameters'));
            return;
        }
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(array('message' => 'Invalid date format'));
            return;
        }
        
        // Don't allow past dates
        if (strtotime($date) < strtotime(date('Y-m-d'))) {
            wp_send_json_success(array('slots' => array(), 'message' => 'Past date'));
            return;
        }
        
        $slots = self::get_available_slots($trainer_id, $date, $duration);
        
        wp_send_json_success(array(
            'slots' => $slots,
            'date' => $date,
            'duration' => $duration
        ));
    }
}

// Initialize on plugins loaded
add_action('plugins_loaded', array('PTP_Availability_V71', 'init'), 20);
