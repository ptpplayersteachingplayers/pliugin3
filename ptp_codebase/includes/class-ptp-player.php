<?php
/**
 * Player Class v24.4.0
 * With bulletproof auto-table creation
 */

defined('ABSPATH') || exit;

class PTP_Player {
    
    private static $table_checked = false;
    
    /**
     * Ensure table exists - bulletproof version
     */
    public static function ensure_table() {
        if (self::$table_checked) {
            return true;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ptp_players';
        
        // Use SHOW TABLES - the reliable way
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );
        
        if ($table_exists === $table_name) {
            self::$table_checked = true;
            return true;
        }
        
        // Create table - using VARCHAR for skill_level to avoid enum issues
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) UNSIGNED NOT NULL,
            name varchar(100) NOT NULL DEFAULT '',
            age int(11) DEFAULT NULL,
            skill_level varchar(20) DEFAULT 'beginner',
            position varchar(100) DEFAULT '',
            goals text,
            notes text,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY parent_id (parent_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Verify
        $verify = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );
        
        self::$table_checked = ($verify === $table_name);
        return self::$table_checked;
    }
    
    public static function get($player_id) {
        global $wpdb;
        self::ensure_table();
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d",
            $player_id
        ));
    }
    
    public static function get_by_parent($parent_id) {
        global $wpdb;
        self::ensure_table();
        
        // Get players, deduplicated by name (keep the most recent)
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT p.* FROM {$wpdb->prefix}ptp_players p
             INNER JOIN (
                SELECT name, MAX(id) as max_id
                FROM {$wpdb->prefix}ptp_players
                WHERE parent_id = %d AND is_active = 1
                GROUP BY name
             ) latest ON p.id = latest.max_id
             WHERE p.parent_id = %d AND p.is_active = 1
             ORDER BY p.name ASC",
            $parent_id,
            $parent_id
        ));
        
        return is_array($results) ? $results : array();
    }
    
    /**
     * Get all players including duplicates (for admin)
     */
    public static function get_all_by_parent($parent_id) {
        global $wpdb;
        self::ensure_table();
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d AND is_active = 1 ORDER BY name ASC, id DESC",
            $parent_id
        ));
        
        return is_array($results) ? $results : array();
    }
    
    /**
     * Check if a player with this name already exists for this parent
     */
    public static function exists($parent_id, $name) {
        global $wpdb;
        self::ensure_table();
        
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d AND name = %s AND is_active = 1",
            $parent_id,
            $name
        ));
    }
    
    /**
     * Merge duplicate players (keeps the first, deactivates others)
     */
    public static function merge_duplicates($parent_id) {
        global $wpdb;
        self::ensure_table();
        
        // Find duplicate names
        $duplicates = $wpdb->get_results($wpdb->prepare(
            "SELECT name, COUNT(*) as cnt, MIN(id) as keep_id
             FROM {$wpdb->prefix}ptp_players 
             WHERE parent_id = %d AND is_active = 1
             GROUP BY name
             HAVING cnt > 1",
            $parent_id
        ));
        
        $merged = 0;
        foreach ($duplicates as $dup) {
            // Deactivate all except the one to keep
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_players 
                 SET is_active = 0 
                 WHERE parent_id = %d AND name = %s AND id != %d AND is_active = 1",
                $parent_id,
                $dup->name,
                $dup->keep_id
            ));
            $merged += ($dup->cnt - 1);
        }
        
        return $merged;
    }
    
    public static function create($parent_id, $data) {
        global $wpdb;
        
        // Make sure table exists
        if (!self::ensure_table()) {
            ptp_log('PTP: Failed to create/verify players table');
            return new WP_Error('table_error', 'Database table could not be created');
        }
        
        // Validate and sanitize skill level
        $valid_skills = array('beginner', 'intermediate', 'advanced', 'elite');
        $skill = strtolower(trim(sanitize_text_field($data['skill_level'] ?? 'beginner')));
        if (!in_array($skill, $valid_skills)) {
            $skill = 'beginner';
        }
        
        // Handle age
        $age = intval($data['age'] ?? 0);
        if ($age < 1 || $age > 99) {
            $age = null;
        }
        
        // Insert
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'ptp_players',
            array(
                'parent_id'   => intval($parent_id),
                'name'        => sanitize_text_field($data['name'] ?? ''),
                'age'         => $age,
                'skill_level' => $skill,
                'position'    => sanitize_text_field($data['position'] ?? ''),
                'goals'       => sanitize_textarea_field($data['goals'] ?? ''),
                'notes'       => sanitize_textarea_field($data['notes'] ?? ''),
                'is_active'   => 1,
            ),
            array('%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d')
        );
        
        if ($inserted === false) {
            $error = $wpdb->last_error;
            ptp_log('PTP Player Insert Failed: ' . $error);
            ptp_log('PTP Last Query: ' . $wpdb->last_query);
            return new WP_Error('insert_failed', 'Could not save player');
        }
        
        $new_id = $wpdb->insert_id;
        
        if (!$new_id) {
            return new WP_Error('no_id', 'Insert succeeded but no ID returned');
        }
        
        return $new_id;
    }
    
    public static function update($player_id, $data) {
        global $wpdb;
        
        $update_data = array();
        $allowed = array('name', 'age', 'skill_level', 'position', 'goals', 'notes', 'is_active');
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                if ($field === 'age' || $field === 'is_active') {
                    $update_data[$field] = intval($data[$field]);
                } elseif ($field === 'goals' || $field === 'notes') {
                    $update_data[$field] = sanitize_textarea_field($data[$field]);
                } elseif ($field === 'skill_level') {
                    $valid = array('beginner', 'intermediate', 'advanced', 'elite');
                    $val = strtolower(trim(sanitize_text_field($data[$field])));
                    if (in_array($val, $valid)) {
                        $update_data[$field] = $val;
                    }
                } else {
                    $update_data[$field] = sanitize_text_field($data[$field]);
                }
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_players',
            $update_data,
            array('id' => $player_id)
        );
    }
    
    public static function delete($player_id) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'ptp_players',
            array('is_active' => 0),
            array('id' => $player_id)
        );
    }
    
    public static function belongs_to_parent($player_id, $parent_id) {
        global $wpdb;
        self::ensure_table();
        
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_players WHERE id = %d AND parent_id = %d",
            $player_id, $parent_id
        ));
    }
    
    public static function get_skill_levels() {
        return array(
            'beginner'     => 'Beginner',
            'intermediate' => 'Intermediate',
            'advanced'     => 'Advanced',
            'elite'        => 'Elite',
        );
    }
    
    public static function get_positions() {
        return array(
            'goalkeeper' => 'Goalkeeper',
            'defender'   => 'Defender',
            'midfielder' => 'Midfielder',
            'forward'    => 'Forward',
            'multiple'   => 'Multiple Positions',
        );
    }
}
