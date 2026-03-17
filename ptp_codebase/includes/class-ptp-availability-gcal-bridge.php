<?php
/**
 * PTP Availability + Google Calendar Bridge v216
 * 
 * Bridges the gap between:
 * - Backend trainer availability (weekly schedule + exceptions + Google Calendar blocks)
 * - Frontend display (trainer profile booking calendar)
 * - Admin management (admin can edit any trainer's schedule)
 * 
 * Fixes: Frontend was ignoring blocked dates and Google Calendar busy times
 * Adds:  Admin AJAX for schedule management, Google Calendar table creation,
 *        robust slot generation that checks ALL blocking sources
 */

defined('ABSPATH') || exit;

class PTP_Availability_GCal_Bridge {

    public static function init() {
        // Ensure Google Calendar tables exist
        add_action('admin_init', array(__CLASS__, 'ensure_calendar_tables'), 5);
        add_action('init', array(__CLASS__, 'ensure_calendar_tables'), 5);

        // Admin AJAX - schedule management for any trainer
        add_action('wp_ajax_ptp_admin_get_trainer_schedule', array(__CLASS__, 'ajax_admin_get_schedule'));
        add_action('wp_ajax_ptp_admin_save_trainer_schedule', array(__CLASS__, 'ajax_admin_save_schedule'));
        add_action('wp_ajax_ptp_admin_block_trainer_date', array(__CLASS__, 'ajax_admin_block_date'));
        add_action('wp_ajax_ptp_admin_unblock_trainer_date', array(__CLASS__, 'ajax_admin_unblock_date'));
        add_action('wp_ajax_ptp_admin_get_blocked_dates', array(__CLASS__, 'ajax_admin_get_blocked'));

        // Public AJAX - get REAL available slots (checks all blocking sources)
        add_action('wp_ajax_ptp_get_real_available_slots', array(__CLASS__, 'ajax_get_real_slots'));
        add_action('wp_ajax_nopriv_ptp_get_real_available_slots', array(__CLASS__, 'ajax_get_real_slots'));
        add_action('wp_ajax_ptp_get_real_available_dates', array(__CLASS__, 'ajax_get_real_dates'));
        add_action('wp_ajax_nopriv_ptp_get_real_available_dates', array(__CLASS__, 'ajax_get_real_dates'));

        // Google Calendar status for trainer dashboard
        add_action('wp_ajax_ptp_gcal_get_status', array(__CLASS__, 'ajax_gcal_status'));
        add_action('wp_ajax_ptp_gcal_connect', array(__CLASS__, 'ajax_gcal_connect'));
        add_action('wp_ajax_ptp_gcal_disconnect', array(__CLASS__, 'ajax_gcal_disconnect'));
        add_action('wp_ajax_ptp_gcal_sync_now', array(__CLASS__, 'ajax_gcal_sync'));
        add_action('wp_ajax_ptp_gcal_toggle_sync', array(__CLASS__, 'ajax_gcal_toggle_sync'));

        // Hook into availability updates to trigger Google Calendar sync
        add_action('ptp_availability_updated', array(__CLASS__, 'on_availability_updated'));
    }

    // ════════════════════════════════════════════
    // TABLE CREATION
    // ════════════════════════════════════════════

    private static $tables_checked = false;

    public static function ensure_calendar_tables() {
        if (self::$tables_checked) return;
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Calendar connections table (for Google Calendar OAuth)
        $t1 = $wpdb->prefix . 'ptp_calendar_connections';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t1)) !== $t1) {
            $wpdb->query("CREATE TABLE {$t1} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id bigint(20) UNSIGNED NOT NULL,
                provider varchar(20) NOT NULL DEFAULT 'google',
                access_token text,
                refresh_token text,
                token_expires datetime DEFAULT NULL,
                calendar_id varchar(255) DEFAULT NULL,
                email varchar(255) DEFAULT NULL,
                sync_enabled tinyint(1) DEFAULT 1,
                sync_direction enum('both','to_gcal','from_gcal') DEFAULT 'both',
                last_sync datetime DEFAULT NULL,
                ics_token varchar(64) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY user_provider (user_id, provider),
                KEY user_id (user_id)
            ) {$charset}");
            ptp_log('PTP: Created ptp_calendar_connections table');
        } else {
            // Ensure sync_direction column exists
            $cols = $wpdb->get_col("DESCRIBE {$t1}");
            if (!in_array('sync_direction', $cols)) {
                $wpdb->query("ALTER TABLE {$t1} ADD COLUMN sync_direction enum('both','to_gcal','from_gcal') DEFAULT 'both' AFTER sync_enabled");
            }
            if (!in_array('ics_token', $cols)) {
                $wpdb->query("ALTER TABLE {$t1} ADD COLUMN ics_token varchar(64) DEFAULT NULL AFTER last_sync");
            }
        }

        // Availability blocks table (Google Calendar busy times + manual blocks)
        $t2 = $wpdb->prefix . 'ptp_availability_blocks';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t2)) !== $t2) {
            $wpdb->query("CREATE TABLE {$t2} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id bigint(20) UNSIGNED NOT NULL,
                specific_date date NOT NULL,
                start_time time NOT NULL,
                end_time time NOT NULL,
                block_type enum('google_busy','manual','recurring_block') DEFAULT 'manual',
                gcal_event_id varchar(255) DEFAULT NULL,
                event_title varchar(255) DEFAULT NULL,
                is_recurring tinyint(1) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY trainer_date (trainer_id, specific_date),
                KEY block_type (block_type),
                KEY trainer_id (trainer_id)
            ) {$charset}");
            ptp_log('PTP: Created ptp_availability_blocks table');
        } else {
            $cols = $wpdb->get_col("DESCRIBE {$t2}");
            if (!in_array('gcal_event_id', $cols)) {
                $wpdb->query("ALTER TABLE {$t2} ADD COLUMN gcal_event_id varchar(255) DEFAULT NULL AFTER block_type");
            }
            if (!in_array('event_title', $cols)) {
                $wpdb->query("ALTER TABLE {$t2} ADD COLUMN event_title varchar(255) DEFAULT NULL AFTER gcal_event_id");
            }
        }

        self::$tables_checked = true;
    }

    // ════════════════════════════════════════════
    // REAL AVAILABILITY (checks ALL blocking sources)
    // ════════════════════════════════════════════

    /**
     * Get truly available time slots for a trainer on a date.
     * Checks: weekly schedule, exceptions, bookings, sessions, AND Google Calendar blocks.
     */
    public static function get_real_available_slots($trainer_id, $date) {
        if (!$trainer_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return array();
        }

        $date_ts = strtotime($date);
        if ($date_ts === false || $date_ts < strtotime('today')) {
            return array();
        }

        global $wpdb;

        // 1. Check if date is blocked (availability exceptions)
        $exception = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_availability_exceptions 
             WHERE trainer_id = %d AND exception_date = %s",
            intval($trainer_id), $date
        ));

        if ($exception && !$exception->is_available && $exception->exception_type === 'blocked') {
            return array();
        }

        // 2. Get weekly schedule for this day
        $day_of_week = (int) date('w', $date_ts);
        $weekly = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_availability 
             WHERE trainer_id = %d AND day_of_week = %d AND is_active = 1",
            intval($trainer_id), $day_of_week
        ));

        // Modified hours from exception override weekly
        if ($exception && $exception->is_available && $exception->start_time && $exception->end_time) {
            $start_time = $exception->start_time;
            $end_time = $exception->end_time;
        } elseif ($weekly) {
            $start_time = $weekly->start_time;
            $end_time = $weekly->end_time;
        } else {
            return array();
        }

        $start_ts = strtotime($start_time);
        $end_ts = strtotime($end_time);
        if ($start_ts === false || $end_ts === false || $start_ts >= $end_ts) {
            return array();
        }

        $slot_duration = isset($weekly->slot_duration) ? max(30, intval($weekly->slot_duration)) : 60;
        $now = time();
        $is_today = ($date === date('Y-m-d'));

        // 3. Get ALL blocked times from ALL sources
        $blocked_times = array();

        // 3a. Bookings
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") === $bookings_table) {
            $booked = $wpdb->get_results($wpdb->prepare(
                "SELECT start_time, COALESCE(duration, 60) as duration FROM {$bookings_table}
                 WHERE trainer_id = %d AND session_date = %s AND status NOT IN ('cancelled', 'refunded')",
                intval($trainer_id), $date
            ));
            foreach ($booked as $b) {
                $blocked_times[] = array(
                    'start' => $b->start_time,
                    'end' => date('H:i:s', strtotime($b->start_time) + (intval($b->duration) * 60))
                );
            }
        }

        // 3b. Sessions table
        $sessions_table = $wpdb->prefix . 'ptp_sessions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$sessions_table'") === $sessions_table) {
            $sessions = $wpdb->get_results($wpdb->prepare(
                "SELECT start_time, COALESCE(duration, 60) as duration FROM {$sessions_table}
                 WHERE trainer_id = %d AND session_date = %s AND session_status NOT IN ('cancelled', 'no_show')",
                intval($trainer_id), $date
            ));
            foreach ($sessions as $s) {
                $blocked_times[] = array(
                    'start' => $s->start_time,
                    'end' => date('H:i:s', strtotime($s->start_time) + (intval($s->duration) * 60))
                );
            }
        }

        // 3c. Google Calendar blocks + manual blocks
        $blocks_table = $wpdb->prefix . 'ptp_availability_blocks';
        if ($wpdb->get_var("SHOW TABLES LIKE '$blocks_table'") === $blocks_table) {
            $blocks = $wpdb->get_results($wpdb->prepare(
                "SELECT start_time, end_time FROM {$blocks_table}
                 WHERE trainer_id = %d AND specific_date = %s",
                intval($trainer_id), $date
            ));
            foreach ($blocks as $bl) {
                $blocked_times[] = array(
                    'start' => $bl->start_time,
                    'end' => $bl->end_time
                );
            }
        }

        // 4. Generate slots, checking against ALL blocked times
        $slots = array();
        $current_ts = $start_ts;

        while ($current_ts < $end_ts) {
            $time_str = date('H:i', $current_ts);
            $time_full = $time_str . ':00';
            $slot_end_ts = $current_ts + ($slot_duration * 60);
            $slot_end_str = date('H:i:s', $slot_end_ts);

            // Skip past times for today
            if ($is_today) {
                $slot_datetime = strtotime($date . ' ' . $time_str);
                if ($slot_datetime <= $now + 1800) { // 30 min buffer
                    $current_ts += $slot_duration * 60;
                    continue;
                }
            }

            // Check if this slot overlaps with any blocked time
            $is_blocked = false;
            foreach ($blocked_times as $bt) {
                $bt_start = strtotime($bt['start']);
                $bt_end = strtotime($bt['end']);
                // Overlap: slot_start < block_end AND slot_end > block_start
                if ($current_ts < $bt_end && $slot_end_ts > $bt_start) {
                    $is_blocked = true;
                    break;
                }
            }

            if (!$is_blocked) {
                $slots[] = array(
                    'time' => $time_full,
                    'start' => $time_str,
                    'end' => date('H:i', $slot_end_ts),
                    'display' => date('g:i A', $current_ts),
                    'available' => true
                );
            }

            $current_ts += $slot_duration * 60;
        }

        return $slots;
    }

    /**
     * Get real available dates for a month (checks all blocking sources)
     */
    public static function get_real_available_dates($trainer_id, $month, $year) {
        if (!$trainer_id) return array();

        $month = intval($month);
        $year = intval($year);
        if ($month < 1 || $month > 12 || $year < 2020) return array();

        $dates = array();
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $today = date('Y-m-d');

        for ($day = 1; $day <= $days_in_month; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if ($date < $today) continue;

            $slots = self::get_real_available_slots($trainer_id, $date);
            if (!empty($slots)) {
                $dates[$date] = array(
                    'count' => count($slots),
                    'first' => $slots[0]['display'],
                    'last' => end($slots)['display']
                );
            }
        }

        return $dates;
    }

    /**
     * Get Google Calendar blocks for a trainer on a date range
     */
    public static function get_gcal_blocks($trainer_id, $start_date, $end_date) {
        global $wpdb;
        self::ensure_calendar_tables();

        $table = $wpdb->prefix . 'ptp_availability_blocks';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE trainer_id = %d AND specific_date >= %s AND specific_date <= %s AND block_type = 'google_busy'
             ORDER BY specific_date ASC, start_time ASC",
            intval($trainer_id), $start_date, $end_date
        )) ?: array();
    }

    // ════════════════════════════════════════════
    // PUBLIC AJAX: Real Availability
    // ════════════════════════════════════════════

    public static function ajax_get_real_slots() {
        $trainer_id = intval($_REQUEST['trainer_id'] ?? 0);
        $date = sanitize_text_field($_REQUEST['date'] ?? '');

        if (!$trainer_id || !$date) {
            wp_send_json_error(array('message' => 'Missing trainer or date'));
        }

        $slots = self::get_real_available_slots($trainer_id, $date);
        wp_send_json_success(array('slots' => $slots, 'date' => $date));
    }

    public static function ajax_get_real_dates() {
        $trainer_id = intval($_REQUEST['trainer_id'] ?? 0);
        $month = intval($_REQUEST['month'] ?? date('n'));
        $year = intval($_REQUEST['year'] ?? date('Y'));

        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Missing trainer'));
        }

        $dates = self::get_real_available_dates($trainer_id, $month, $year);
        wp_send_json_success(array('dates' => $dates, 'month' => $month, 'year' => $year));
    }

    // ════════════════════════════════════════════
    // ADMIN AJAX: Schedule Management
    // ════════════════════════════════════════════

    public static function ajax_admin_get_schedule() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        check_ajax_referer('ptp_admin_nonce', 'nonce');

        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Missing trainer ID'));
        }

        // Get weekly schedule
        $weekly = array();
        if (class_exists('PTP_Availability')) {
            $rows = PTP_Availability::get_weekly($trainer_id);
            foreach ($rows as $row) {
                $weekly[$row->day_of_week] = array(
                    'enabled' => (bool) $row->is_active,
                    'start' => substr($row->start_time, 0, 5),
                    'end' => substr($row->end_time, 0, 5)
                );
            }
        }

        // Get blocked dates
        global $wpdb;
        $blocked = $wpdb->get_results($wpdb->prepare(
            "SELECT exception_date as date, reason FROM {$wpdb->prefix}ptp_availability_exceptions
             WHERE trainer_id = %d AND is_available = 0 AND exception_date >= CURDATE()
             ORDER BY exception_date ASC",
            $trainer_id
        )) ?: array();

        // Get Google Calendar connection status
        self::ensure_calendar_tables();
        $gcal = $wpdb->get_row($wpdb->prepare(
            "SELECT email, sync_enabled, last_sync, calendar_id FROM {$wpdb->prefix}ptp_calendar_connections
             WHERE user_id = (SELECT user_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d) AND provider = 'google'",
            $trainer_id
        ));

        wp_send_json_success(array(
            'schedule' => $weekly,
            'blocked_dates' => $blocked,
            'gcal_connected' => !empty($gcal),
            'gcal_email' => $gcal->email ?? '',
            'gcal_last_sync' => $gcal->last_sync ?? '',
            'gcal_sync_enabled' => !empty($gcal->sync_enabled)
        ));
    }

    public static function ajax_admin_save_schedule() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        check_ajax_referer('ptp_admin_nonce', 'nonce');

        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $day = isset($_POST['day']) ? intval($_POST['day']) : -1;

        if (!$trainer_id || $day < 0 || $day > 6) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }

        $enabled = isset($_POST['enabled']) && in_array($_POST['enabled'], array('1', 1, 'true', true), true);
        $start = sanitize_text_field($_POST['start'] ?? '09:00');
        $end = sanitize_text_field($_POST['end'] ?? '17:00');

        if (class_exists('PTP_Availability')) {
            $result = PTP_Availability::save_day($trainer_id, $day, $enabled, $start, $end);
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            PTP_Availability::clear_cache($trainer_id);
        }

        // v216: Aggressive cache clearing so frontend profile shows updated schedule immediately
        // Clear object cache for this trainer's availability
        wp_cache_delete('ptp_avail_' . $trainer_id, 'ptp');
        wp_cache_delete('ptp_open_dates_' . $trainer_id, 'ptp');
        wp_cache_flush_group('ptp'); // Clear all PTP cache if supported
        
        // Clear transients that cache trainer data on landing pages
        delete_transient('ptp_featured_trainers_landing');
        delete_transient('ptp_active_trainer_count');
        delete_transient('ptp_trainers_grid_data');
        
        // Clear any page cache for this trainer's profile
        if ($trainer_id) {
            global $wpdb;
            $trainer_slug = $wpdb->get_var($wpdb->prepare(
                "SELECT slug FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
            ));
            if ($trainer_slug) {
                // Clear popular cache plugins
                $profile_url = home_url('/trainer/' . $trainer_slug . '/');
                if (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache(); // WP Super Cache
                if (function_exists('w3tc_flush_post')) {
                    $post_id = url_to_postid($profile_url);
                    if ($post_id) w3tc_flush_post($post_id);
                }
                if (class_exists('LiteSpeed_Cache_API')) {
                    LiteSpeed_Cache_API::purge($profile_url);
                }
                // Clear WP Engine cache
                if (class_exists('WpeCommon')) {
                    WpeCommon::purge_varnish_cache();
                }
                // Clear Cloudflare cache for this URL via action hook
                do_action('ptp_clear_page_cache', $profile_url, $trainer_id);
            }
        }
        
        // Fire hook for Google Calendar sync
        do_action('ptp_availability_updated', $trainer_id);

        // Log admin action
        ptp_log(sprintf('PTP Admin: User %d updated trainer %d schedule - day %d: %s %s-%s',
            get_current_user_id(), $trainer_id, $day, $enabled ? 'ON' : 'OFF', $start, $end));

        wp_send_json_success(array('message' => 'Schedule updated'));
    }

    public static function ajax_admin_block_date() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        check_ajax_referer('ptp_admin_nonce', 'nonce');

        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $date = sanitize_text_field($_POST['date'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? 'Blocked by admin');

        if (!$trainer_id || !$date) {
            wp_send_json_error(array('message' => 'Missing parameters'));
        }

        if (class_exists('PTP_Availability')) {
            $result = PTP_Availability::block_date($trainer_id, $date, $reason);
            if ($result) {
                ptp_log(sprintf('PTP Admin: User %d blocked date %s for trainer %d: %s',
                    get_current_user_id(), $date, $trainer_id, $reason));
                // v216: Clear all caches
                PTP_Availability::clear_cache($trainer_id);
                delete_transient('ptp_featured_trainers_landing');
                wp_cache_delete('ptp_avail_' . $trainer_id, 'ptp');
                do_action('ptp_availability_updated', $trainer_id);
                wp_send_json_success(array('message' => 'Date blocked'));
            }
        }
        wp_send_json_error(array('message' => 'Failed to block date'));
    }

    public static function ajax_admin_unblock_date() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        check_ajax_referer('ptp_admin_nonce', 'nonce');

        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $date = sanitize_text_field($_POST['date'] ?? '');

        if (!$trainer_id || !$date) {
            wp_send_json_error(array('message' => 'Missing parameters'));
        }

        if (class_exists('PTP_Availability')) {
            PTP_Availability::unblock_date($trainer_id, $date);
            // v216: Clear all caches
            PTP_Availability::clear_cache($trainer_id);
            delete_transient('ptp_featured_trainers_landing');
            wp_cache_delete('ptp_avail_' . $trainer_id, 'ptp');
            do_action('ptp_availability_updated', $trainer_id);
            wp_send_json_success(array('message' => 'Date unblocked'));
        }
        wp_send_json_error(array('message' => 'Failed'));
    }

    public static function ajax_admin_get_blocked() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        check_ajax_referer('ptp_admin_nonce', 'nonce');

        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Missing trainer'));
        }

        global $wpdb;
        $blocked = $wpdb->get_results($wpdb->prepare(
            "SELECT exception_date as date, reason, created_at 
             FROM {$wpdb->prefix}ptp_availability_exceptions
             WHERE trainer_id = %d AND is_available = 0 AND exception_date >= CURDATE()
             ORDER BY exception_date ASC",
            $trainer_id
        )) ?: array();

        wp_send_json_success(array('blocked_dates' => $blocked));
    }

    // ════════════════════════════════════════════
    // GOOGLE CALENDAR AJAX (Trainer Dashboard)
    // ════════════════════════════════════════════

    public static function ajax_gcal_status() {
        check_ajax_referer('ptp_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Login required'));

        self::ensure_calendar_tables();
        global $wpdb;
        $user_id = get_current_user_id();

        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_calendar_connections 
             WHERE user_id = %d AND provider = 'google'",
            $user_id
        ));

        $client_id = get_option('ptp_google_client_id', '');

        wp_send_json_success(array(
            'configured' => !empty($client_id),
            'connected' => !empty($connection) && !empty($connection->access_token),
            'email' => $connection->email ?? '',
            'calendar_id' => $connection->calendar_id ?? '',
            'sync_enabled' => !empty($connection->sync_enabled),
            'sync_direction' => $connection->sync_direction ?? 'both',
            'last_sync' => $connection->last_sync ?? null,
            'last_sync_display' => $connection->last_sync ? human_time_diff(strtotime($connection->last_sync)) . ' ago' : 'Never'
        ));
    }

    public static function ajax_gcal_connect() {
        check_ajax_referer('ptp_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Login required'));

        if (class_exists('PTP_Google_Calendar_V71')) {
            $gcal = PTP_Google_Calendar_V71::instance();
            $auth_url = $gcal->get_auth_url(get_current_user_id());
            wp_send_json_success(array('auth_url' => $auth_url));
        }

        wp_send_json_error(array('message' => 'Google Calendar not configured. Contact admin.'));
    }

    public static function ajax_gcal_disconnect() {
        check_ajax_referer('ptp_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Login required'));

        self::ensure_calendar_tables();
        global $wpdb;

        $wpdb->delete(
            $wpdb->prefix . 'ptp_calendar_connections',
            array('user_id' => get_current_user_id(), 'provider' => 'google')
        );

        // Also clear blocks from Google Calendar for this trainer
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));

        if ($trainer_id) {
            $wpdb->delete(
                $wpdb->prefix . 'ptp_availability_blocks',
                array('trainer_id' => $trainer_id, 'block_type' => 'google_busy')
            );
        }

        wp_send_json_success(array('message' => 'Google Calendar disconnected'));
    }

    public static function ajax_gcal_sync() {
        check_ajax_referer('ptp_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Login required'));

        if (class_exists('PTP_Google_Calendar_V71')) {
            $gcal = PTP_Google_Calendar_V71::instance();
            $result = $gcal->sync_user_calendar(get_current_user_id());
            if ($result) {
                wp_send_json_success(array('message' => 'Calendar synced'));
            }
        }
        wp_send_json_error(array('message' => 'Sync failed - check connection'));
    }

    public static function ajax_gcal_toggle_sync() {
        check_ajax_referer('ptp_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(array('message' => 'Login required'));

        self::ensure_calendar_tables();
        global $wpdb;

        $enabled = intval($_POST['enabled'] ?? 1);
        $direction = sanitize_text_field($_POST['direction'] ?? 'both');

        $wpdb->update(
            $wpdb->prefix . 'ptp_calendar_connections',
            array(
                'sync_enabled' => $enabled,
                'sync_direction' => in_array($direction, array('both', 'to_gcal', 'from_gcal')) ? $direction : 'both'
            ),
            array('user_id' => get_current_user_id(), 'provider' => 'google')
        );

        wp_send_json_success(array('message' => 'Settings updated'));
    }

    public static function on_availability_updated($trainer_id) {
        // After schedule update, if Google Calendar connected, sync
        global $wpdb;
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));

        if ($user_id && class_exists('PTP_Google_Calendar_V71')) {
            $connection = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_calendar_connections 
                 WHERE user_id = %d AND provider = 'google' AND sync_enabled = 1",
                $user_id
            ));
            if ($connection) {
                $gcal = PTP_Google_Calendar_V71::instance();
                $gcal->sync_user_calendar($user_id);
            }
        }
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_Availability_GCal_Bridge', 'init'), 25);
