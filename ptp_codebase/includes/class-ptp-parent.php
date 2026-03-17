<?php
/**
 * Parent Class v24.4.0
 * With bulletproof auto-table creation
 */

defined('ABSPATH') || exit;

class PTP_Parent {
    
    private static $table_checked = false;
    
    /**
     * Ensure table exists - bulletproof version with column repair
     */
    public static function ensure_table() {
        // Only check once per request
        if (self::$table_checked) {
            return true;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ptp_parents';
        
        // Use SHOW TABLES - the reliable way
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );
        
        if ($table_exists === $table_name) {
            // Table exists - check for missing user_id column
            $user_id_exists = $wpdb->get_var("SHOW COLUMNS FROM $table_name LIKE 'user_id'");
            
            if (!$user_id_exists) {
                // Add missing column
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN user_id bigint(20) UNSIGNED NOT NULL AFTER id");
                $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY user_id (user_id)");
                ptp_log('PTP: Repaired ptp_parents table - added user_id column');
            }
            
            self::$table_checked = true;
            return true;
        }
        
        // Table doesn't exist - create it
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            display_name varchar(100) NOT NULL DEFAULT '',
            phone varchar(20) DEFAULT '',
            location varchar(255) DEFAULT '',
            latitude decimal(10,8) DEFAULT NULL,
            longitude decimal(11,8) DEFAULT NULL,
            total_sessions int(11) DEFAULT 0,
            total_spent decimal(10,2) DEFAULT 0.00,
            notification_email tinyint(1) DEFAULT 1,
            notification_sms tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Verify it was created
        $verify = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );
        
        self::$table_checked = ($verify === $table_name);
        return self::$table_checked;
    }
    
    public static function get($parent_id) {
        global $wpdb;
        self::ensure_table();
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE id = %d",
            $parent_id
        ));
    }
    
    public static function get_by_user_id($user_id) {
        global $wpdb;
        self::ensure_table();
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
    }
    
    public static function create($user_id, $data = array()) {
        global $wpdb;
        
        // Make sure table exists
        if (!self::ensure_table()) {
            ptp_log('PTP: Failed to create/verify parents table');
            return new WP_Error('table_error', 'Database table could not be created');
        }
        
        // Check if already exists first
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if ($existing) {
            return $existing->id;
        }
        
        // Get WordPress user
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('invalid_user', 'WordPress user not found');
        }
        
        // Build display name
        $display_name = 'Parent';
        if (!empty($data['display_name'])) {
            $display_name = $data['display_name'];
        } elseif (!empty($user->display_name)) {
            $display_name = $user->display_name;
        } elseif (!empty($user->user_login)) {
            $display_name = $user->user_login;
        }
        
        // v118.1: Get email from WordPress user
        $email = !empty($data['email']) ? $data['email'] : $user->user_email;
        
        // v118.1: Get first/last name
        $first_name = !empty($data['first_name']) ? $data['first_name'] : $user->first_name;
        $last_name = !empty($data['last_name']) ? $data['last_name'] : $user->last_name;
        
        // v118.1: Check which columns exist in the table
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_parents");
        
        // Build insert data based on available columns
        $insert_data = array(
            'user_id'            => $user_id,
            'display_name'       => sanitize_text_field($display_name),
            'phone'              => sanitize_text_field($data['phone'] ?? ''),
            'location'           => sanitize_text_field($data['location'] ?? ''),
            'total_sessions'     => 0,
            'total_spent'        => 0.00,
            'notification_email' => 1,
            'notification_sms'   => 1,
        );
        $format = array('%d', '%s', '%s', '%s', '%d', '%f', '%d', '%d');
        
        // Add email if column exists
        if (in_array('email', $columns) && $email) {
            $insert_data['email'] = sanitize_email($email);
            $format[] = '%s';
        }
        
        // Add first_name if column exists
        if (in_array('first_name', $columns) && $first_name) {
            $insert_data['first_name'] = sanitize_text_field($first_name);
            $format[] = '%s';
        }
        
        // Add last_name if column exists
        if (in_array('last_name', $columns) && $last_name) {
            $insert_data['last_name'] = sanitize_text_field($last_name);
            $format[] = '%s';
        }
        
        // Insert
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'ptp_parents',
            $insert_data,
            $format
        );
        
        if ($inserted === false) {
            $error = $wpdb->last_error;
            ptp_log('PTP Parent Insert Failed: ' . $error);
            ptp_log('PTP Last Query: ' . $wpdb->last_query);
            
            // Check if it's a duplicate key error (race condition)
            if (strpos($error, 'Duplicate') !== false) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                    $user_id
                ));
                if ($existing) {
                    return $existing->id;
                }
            }
            
            return new WP_Error('insert_failed', 'Could not save to database');
        }
        
        $new_id = $wpdb->insert_id;
        
        if (!$new_id) {
            return new WP_Error('no_id', 'Insert succeeded but no ID returned');
        }
        
        return $new_id;
    }
    
    public static function update($parent_id, $data) {
        global $wpdb;
        
        $update_data = array();
        $allowed = array('display_name', 'phone', 'location', 'latitude', 'longitude', 'notification_email', 'notification_sms');
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = sanitize_text_field($data[$field]);
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_parents',
            $update_data,
            array('id' => $parent_id)
        );
    }
    
    public static function update_stats($parent_id) {
        global $wpdb;
        
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        
        // Check if bookings table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $bookings_table)
        );
        
        if (!$table_exists) {
            return;
        }
        
        $total_sessions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $bookings_table WHERE parent_id = %d AND status = 'completed'",
            $parent_id
        ));
        
        $total_spent = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM $bookings_table WHERE parent_id = %d AND status = 'completed'",
            $parent_id
        ));
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_parents',
            array(
                'total_sessions' => intval($total_sessions),
                'total_spent'    => floatval($total_spent),
            ),
            array('id' => $parent_id)
        );
    }
    
    public static function get_players($parent_id) {
        return PTP_Player::get_by_parent($parent_id);
    }
    
    public static function get_upcoming_sessions($parent_id, $limit = 10) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug, p.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE b.parent_id = %d AND b.status IN ('pending', 'confirmed') AND b.session_date >= CURDATE()
             ORDER BY b.session_date ASC, b.start_time ASC
             LIMIT %d",
            $parent_id, $limit
        ));
    }
    
    public static function get_past_sessions($parent_id, $limit = 20) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug, p.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE b.parent_id = %d AND (b.status = 'completed' OR b.session_date < CURDATE())
             ORDER BY b.session_date DESC, b.start_time DESC
             LIMIT %d",
            $parent_id, $limit
        ));
    }
}
