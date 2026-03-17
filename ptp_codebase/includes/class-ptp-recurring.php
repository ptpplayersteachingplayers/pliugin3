<?php
/**
 * PTP Recurring Bookings & Training Packages
 * Handles weekly recurring sessions and package deals
 */

defined('ABSPATH') || exit;

class PTP_Recurring {
    
    public static function init() {
        add_action('wp_ajax_ptp_create_package', array(__CLASS__, 'ajax_create_package'));
        add_action('wp_ajax_ptp_book_recurring', array(__CLASS__, 'ajax_book_recurring'));
        add_action('wp_ajax_ptp_cancel_recurring', array(__CLASS__, 'ajax_cancel_recurring'));
        add_action('wp_ajax_ptp_skip_session', array(__CLASS__, 'ajax_skip_session'));
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Training packages table (what trainers offer)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_packages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            name varchar(100) NOT NULL,
            description text,
            session_count int(11) NOT NULL DEFAULT 4,
            sessions_per_week int(11) NOT NULL DEFAULT 1,
            duration_minutes int(11) NOT NULL DEFAULT 60,
            price decimal(10,2) NOT NULL,
            discount_percent decimal(5,2) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            max_players int(11) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Recurring subscriptions (active recurring bookings)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_recurring (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED NOT NULL,
            package_id bigint(20) UNSIGNED DEFAULT NULL,
            day_of_week tinyint(1) NOT NULL,
            start_time time NOT NULL,
            duration_minutes int(11) DEFAULT 60,
            location varchar(255) DEFAULT '',
            sessions_remaining int(11) DEFAULT NULL,
            sessions_completed int(11) DEFAULT 0,
            total_sessions int(11) DEFAULT NULL,
            hourly_rate decimal(10,2) NOT NULL,
            status enum('active','paused','completed','cancelled') DEFAULT 'active',
            start_date date NOT NULL,
            end_date date DEFAULT NULL,
            next_session_date date DEFAULT NULL,
            payment_type enum('per_session','upfront','subscription') DEFAULT 'per_session',
            stripe_subscription_id varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY parent_id (parent_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Link individual bookings to recurring series
        $sql = "ALTER TABLE {$wpdb->prefix}ptp_bookings 
                ADD COLUMN recurring_id bigint(20) UNSIGNED DEFAULT NULL,
                ADD COLUMN is_recurring tinyint(1) DEFAULT 0,
                ADD KEY recurring_id (recurring_id);";
        $wpdb->query($sql);
    }
    
    /**
     * Get trainer's packages
     */
    public static function get_trainer_packages($trainer_id, $active_only = true) {
        global $wpdb;
        
        $where = $active_only ? "AND is_active = 1" : "";
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_packages 
             WHERE trainer_id = %d {$where}
             ORDER BY session_count ASC",
            $trainer_id
        ));
    }
    
    /**
     * Create a package
     */
    public static function create_package($trainer_id, $data) {
        global $wpdb;
        
        $insert_data = array(
            'trainer_id' => $trainer_id,
            'name' => sanitize_text_field($data['name']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'session_count' => intval($data['session_count']),
            'sessions_per_week' => intval($data['sessions_per_week'] ?? 1),
            'duration_minutes' => intval($data['duration_minutes'] ?? 60),
            'price' => floatval($data['price']),
            'discount_percent' => floatval($data['discount_percent'] ?? 0),
            'max_players' => intval($data['max_players'] ?? 1),
        );
        
        $wpdb->insert($wpdb->prefix . 'ptp_packages', $insert_data);
        return $wpdb->insert_id;
    }
    
    /**
     * Book a recurring series
     */
    public static function create_recurring($data) {
        global $wpdb;
        
        $recurring_data = array(
            'parent_id' => intval($data['parent_id']),
            'trainer_id' => intval($data['trainer_id']),
            'player_id' => intval($data['player_id']),
            'package_id' => !empty($data['package_id']) ? intval($data['package_id']) : null,
            'day_of_week' => intval($data['day_of_week']),
            'start_time' => sanitize_text_field($data['start_time']),
            'duration_minutes' => intval($data['duration_minutes'] ?? 60),
            'location' => sanitize_text_field($data['location'] ?? ''),
            'total_sessions' => !empty($data['total_sessions']) ? intval($data['total_sessions']) : null,
            'sessions_remaining' => !empty($data['total_sessions']) ? intval($data['total_sessions']) : null,
            'hourly_rate' => floatval($data['hourly_rate']),
            'start_date' => sanitize_text_field($data['start_date']),
            'payment_type' => sanitize_text_field($data['payment_type'] ?? 'per_session'),
        );
        
        // Calculate next session date
        $recurring_data['next_session_date'] = self::get_next_session_date(
            $data['start_date'], 
            $data['day_of_week']
        );
        
        $wpdb->insert($wpdb->prefix . 'ptp_recurring', $recurring_data);
        $recurring_id = $wpdb->insert_id;
        
        // Generate first batch of bookings (next 4 weeks)
        self::generate_upcoming_bookings($recurring_id, 4);
        
        return $recurring_id;
    }
    
    /**
     * Generate upcoming bookings for a recurring series
     */
    public static function generate_upcoming_bookings($recurring_id, $weeks = 4) {
        global $wpdb;
        
        $recurring = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_recurring WHERE id = %d",
            $recurring_id
        ));
        
        if (!$recurring || $recurring->status !== 'active') {
            return false;
        }
        
        // Check sessions remaining
        if ($recurring->sessions_remaining !== null && $recurring->sessions_remaining <= 0) {
            return false;
        }
        
        $bookings_created = 0;
        $current_date = new DateTime($recurring->next_session_date);
        $max_bookings = $recurring->sessions_remaining ?? $weeks;
        $max_bookings = min($max_bookings, $weeks);
        
        for ($i = 0; $i < $max_bookings; $i++) {
            $session_date = $current_date->format('Y-m-d');
            
            // Check if booking already exists for this date
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_bookings 
                 WHERE recurring_id = %d AND session_date = %s",
                $recurring_id, $session_date
            ));
            
            if (!$exists) {
                // Calculate end time
                $start = new DateTime($recurring->start_time);
                $end = clone $start;
                $end->modify("+{$recurring->duration_minutes} minutes");
                
                // Calculate pricing with graduated fee
                $hours = $recurring->duration_minutes / 60;
                $total = $recurring->hourly_rate * $hours;
                $session_number = PTP_Escrow::get_session_number($recurring->trainer_id, $recurring->parent_id);
                $fee_rate = PTP_Escrow::get_fee_rate($session_number);
                $platform_fee = round($total * $fee_rate, 2);
                $trainer_payout = $total - $platform_fee;
                
                // Create booking
                $booking_data = array(
                    'booking_number' => 'PTP-' . strtoupper(substr(md5(uniqid()), 0, 8)),
                    'trainer_id' => $recurring->trainer_id,
                    'parent_id' => $recurring->parent_id,
                    'player_id' => $recurring->player_id,
                    'session_date' => $session_date,
                    'start_time' => $recurring->start_time,
                    'end_time' => $end->format('H:i:s'),
                    'duration_minutes' => $recurring->duration_minutes,
                    'location' => $recurring->location,
                    'hourly_rate' => $recurring->hourly_rate,
                    'total_amount' => $total,
                    'platform_fee' => $platform_fee,
                    'trainer_payout' => $trainer_payout,
                    'status' => 'confirmed',
                    'payment_status' => $recurring->payment_type === 'upfront' ? 'paid' : 'pending',
                    'recurring_id' => $recurring_id,
                    'is_recurring' => 1,
                );
                
                // v223-fix: Skip if slot conflicts with existing booking
                $rc_conflict = false;
                if (class_exists('PTP_Booking') && method_exists('PTP_Booking', 'has_conflict')) {
                    $rc_conflict = PTP_Booking::has_conflict(
                        $booking_data['trainer_id'],
                        $booking_data['session_date'],
                        $booking_data['start_time'],
                        $booking_data['duration_minutes'] ?? 60
                    );
                }
                if ($rc_conflict) {
                    ptp_log('[PTP Recurring] Skipped ' . $booking_data['session_date'] . ' ' . $booking_data['start_time'] . ' — conflicts with booking #' . $rc_conflict);
                } else {
                    $wpdb->insert($wpdb->prefix . 'ptp_bookings', $booking_data);
                    $bookings_created++;
                }
            }
            
            // Move to next week
            $current_date->modify('+1 week');
        }
        
        // Update next session date
        $wpdb->update(
            $wpdb->prefix . 'ptp_recurring',
            array('next_session_date' => $current_date->format('Y-m-d')),
            array('id' => $recurring_id)
        );
        
        return $bookings_created;
    }
    
    /**
     * Get next occurrence of a day of week
     */
    private static function get_next_session_date($start_date, $day_of_week) {
        $date = new DateTime($start_date);
        $target_day = intval($day_of_week);
        $current_day = intval($date->format('w'));
        
        $days_until = ($target_day - $current_day + 7) % 7;
        if ($days_until === 0 && $date <= new DateTime()) {
            $days_until = 7;
        }
        
        $date->modify("+{$days_until} days");
        return $date->format('Y-m-d');
    }
    
    /**
     * Cancel a recurring series
     */
    public static function cancel_recurring($recurring_id, $cancel_future_only = true) {
        global $wpdb;
        
        // Update recurring status
        $wpdb->update(
            $wpdb->prefix . 'ptp_recurring',
            array('status' => 'cancelled'),
            array('id' => $recurring_id)
        );
        
        if ($cancel_future_only) {
            // Cancel only future bookings
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_bookings 
                 SET status = 'cancelled', cancelled_at = NOW()
                 WHERE recurring_id = %d AND session_date > CURDATE() AND status != 'completed'",
                $recurring_id
            ));
        }
        
        return true;
    }
    
    /**
     * Skip a single session in a recurring series
     */
    public static function skip_session($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking || !$booking->recurring_id) {
            return new WP_Error('not_recurring', 'This is not a recurring booking');
        }
        
        // Mark as skipped (cancelled but don't count against package)
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'cancelled',
                'cancellation_reason' => 'Skipped by user',
                'cancelled_at' => current_time('mysql'),
            ),
            array('id' => $booking_id)
        );
        
        return true;
    }
    
    /**
     * Get parent's active recurring bookings
     */
    public static function get_parent_recurring($parent_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
                    p.name as player_name, pk.name as package_name
             FROM {$wpdb->prefix}ptp_recurring r
             JOIN {$wpdb->prefix}ptp_trainers t ON r.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_players p ON r.player_id = p.id
             LEFT JOIN {$wpdb->prefix}ptp_packages pk ON r.package_id = pk.id
             WHERE r.parent_id = %d AND r.status = 'active'
             ORDER BY r.next_session_date ASC",
            $parent_id
        ));
    }
    
    /**
     * Get trainer's recurring clients
     */
    public static function get_trainer_recurring($trainer_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, pa.display_name as parent_name, p.name as player_name,
                    pk.name as package_name
             FROM {$wpdb->prefix}ptp_recurring r
             JOIN {$wpdb->prefix}ptp_parents pa ON r.parent_id = pa.id
             JOIN {$wpdb->prefix}ptp_players p ON r.player_id = p.id
             LEFT JOIN {$wpdb->prefix}ptp_packages pk ON r.package_id = pk.id
             WHERE r.trainer_id = %d AND r.status = 'active'
             ORDER BY r.day_of_week ASC, r.start_time ASC",
            $trainer_id
        ));
    }
    
    /**
     * Process completed sessions and update counts
     */
    public static function process_completed_session($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking || !$booking->recurring_id) {
            return;
        }
        
        // Update recurring counts
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_recurring 
             SET sessions_completed = sessions_completed + 1,
                 sessions_remaining = CASE WHEN sessions_remaining IS NOT NULL THEN sessions_remaining - 1 ELSE NULL END
             WHERE id = %d",
            $booking->recurring_id
        ));
        
        // Check if package is complete
        $recurring = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_recurring WHERE id = %d",
            $booking->recurring_id
        ));
        
        if ($recurring->sessions_remaining !== null && $recurring->sessions_remaining <= 0) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_recurring',
                array('status' => 'completed'),
                array('id' => $booking->recurring_id)
            );
        }
    }
    
    /**
     * AJAX: Create package
     */
    public static function ajax_create_package() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Not a trainer'));
        }
        
        $package_id = self::create_package($trainer->id, $_POST);
        
        if ($package_id) {
            wp_send_json_success(array('package_id' => $package_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to create package'));
        }
    }
    
    /**
     * AJAX: Book recurring
     */
    public static function ajax_book_recurring() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        $parent = PTP_Parent::get_by_user_id(get_current_user_id());
        if (!$parent) {
            wp_send_json_error(array('message' => 'Parent account required'));
        }
        
        $_POST['parent_id'] = $parent->id;
        $recurring_id = self::create_recurring($_POST);
        
        if ($recurring_id) {
            wp_send_json_success(array(
                'recurring_id' => $recurring_id,
                'message' => 'Recurring sessions booked successfully!'
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to create recurring booking'));
        }
    }
    
    /**
     * AJAX: Cancel recurring
     */
    public static function ajax_cancel_recurring() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $recurring_id = intval($_POST['recurring_id']);
        $result = self::cancel_recurring($recurring_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Recurring sessions cancelled'));
        } else {
            wp_send_json_error(array('message' => 'Failed to cancel'));
        }
    }
}
