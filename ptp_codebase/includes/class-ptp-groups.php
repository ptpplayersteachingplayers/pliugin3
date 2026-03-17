<?php
/**
 * PTP Group Sessions
 * Handles multi-player sessions with split pricing
 */

defined('ABSPATH') || exit;

class PTP_Groups {
    
    public static function init() {
        add_action('wp_ajax_ptp_create_group_session', array(__CLASS__, 'ajax_create_group_session'));
        add_action('wp_ajax_ptp_join_group_session', array(__CLASS__, 'ajax_join_group_session'));
        add_action('wp_ajax_ptp_leave_group_session', array(__CLASS__, 'ajax_leave_group_session'));
        add_action('wp_ajax_nopriv_ptp_get_open_groups', array(__CLASS__, 'ajax_get_open_groups'));
        add_action('wp_ajax_ptp_get_open_groups', array(__CLASS__, 'ajax_get_open_groups'));
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Group sessions table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_group_sessions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            title varchar(255) NOT NULL,
            description text,
            session_date date NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            duration_minutes int(11) DEFAULT 60,
            location varchar(255) DEFAULT '',
            location_notes text,
            min_players int(11) DEFAULT 2,
            max_players int(11) DEFAULT 6,
            current_players int(11) DEFAULT 0,
            price_per_player decimal(10,2) NOT NULL,
            total_price decimal(10,2) NOT NULL,
            skill_level enum('all','beginner','intermediate','advanced','elite') DEFAULT 'all',
            age_min int(11) DEFAULT NULL,
            age_max int(11) DEFAULT NULL,
            focus_area varchar(100) DEFAULT '',
            status enum('open','full','confirmed','completed','cancelled') DEFAULT 'open',
            is_public tinyint(1) DEFAULT 1,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY session_date (session_date),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Group participants table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_group_participants (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            group_session_id bigint(20) UNSIGNED NOT NULL,
            parent_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED NOT NULL,
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            amount_paid decimal(10,2) DEFAULT 0,
            payment_status enum('pending','paid','refunded') DEFAULT 'pending',
            status enum('registered','confirmed','attended','no_show','cancelled') DEFAULT 'registered',
            joined_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY group_session_id (group_session_id),
            KEY parent_id (parent_id),
            UNIQUE KEY unique_player (group_session_id, player_id)
        ) $charset_collate;";
        dbDelta($sql);
    }
    
    /**
     * Create a group session
     */
    public static function create_group_session($trainer_id, $data) {
        global $wpdb;
        
        // Calculate end time
        $start = new DateTime($data['start_time']);
        $duration = intval($data['duration_minutes'] ?? 60);
        $end = clone $start;
        $end->modify("+{$duration} minutes");
        
        // Calculate pricing
        $total_price = floatval($data['total_price']);
        $max_players = intval($data['max_players'] ?? 6);
        $price_per_player = $total_price / $max_players;
        
        $insert_data = array(
            'trainer_id' => $trainer_id,
            'title' => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'session_date' => sanitize_text_field($data['session_date']),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'duration_minutes' => $duration,
            'location' => sanitize_text_field($data['location'] ?? ''),
            'location_notes' => sanitize_textarea_field($data['location_notes'] ?? ''),
            'min_players' => intval($data['min_players'] ?? 2),
            'max_players' => $max_players,
            'price_per_player' => $price_per_player,
            'total_price' => $total_price,
            'skill_level' => sanitize_text_field($data['skill_level'] ?? 'all'),
            'age_min' => !empty($data['age_min']) ? intval($data['age_min']) : null,
            'age_max' => !empty($data['age_max']) ? intval($data['age_max']) : null,
            'focus_area' => sanitize_text_field($data['focus_area'] ?? ''),
            'is_public' => isset($data['is_public']) ? intval($data['is_public']) : 1,
            'created_by' => get_current_user_id(),
        );
        
        $wpdb->insert($wpdb->prefix . 'ptp_group_sessions', $insert_data);
        return $wpdb->insert_id;
    }
    
    /**
     * Join a group session
     */
    public static function join_group_session($group_id, $parent_id, $player_id) {
        global $wpdb;
        
        $group = self::get_group_session($group_id);
        
        if (!$group) {
            return new WP_Error('not_found', 'Group session not found');
        }
        
        if ($group->status === 'full' || $group->current_players >= $group->max_players) {
            return new WP_Error('full', 'This session is full');
        }
        
        if ($group->status === 'cancelled') {
            return new WP_Error('cancelled', 'This session has been cancelled');
        }
        
        // Check if player already registered
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_group_participants 
             WHERE group_session_id = %d AND player_id = %d AND status != 'cancelled'",
            $group_id, $player_id
        ));
        
        if ($existing) {
            return new WP_Error('already_registered', 'This player is already registered');
        }
        
        // Check age requirements
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d",
            $player_id
        ));
        
        if ($group->age_min && $player->age < $group->age_min) {
            return new WP_Error('too_young', 'Player does not meet minimum age requirement');
        }
        
        if ($group->age_max && $player->age > $group->age_max) {
            return new WP_Error('too_old', 'Player exceeds maximum age for this session');
        }
        
        // Add participant
        $wpdb->insert($wpdb->prefix . 'ptp_group_participants', array(
            'group_session_id' => $group_id,
            'parent_id' => $parent_id,
            'player_id' => $player_id,
            'amount_paid' => $group->price_per_player,
        ));
        $participant_id = $wpdb->insert_id;
        
        // Update player count
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_group_sessions 
             SET current_players = current_players + 1,
                 status = CASE WHEN current_players + 1 >= max_players THEN 'full' ELSE status END
             WHERE id = %d",
            $group_id
        ));
        
        return $participant_id;
    }
    
    /**
     * Leave a group session
     */
    public static function leave_group_session($group_id, $player_id) {
        global $wpdb;
        
        // Update participant status
        $wpdb->update(
            $wpdb->prefix . 'ptp_group_participants',
            array('status' => 'cancelled'),
            array('group_session_id' => $group_id, 'player_id' => $player_id)
        );
        
        // Update player count
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_group_sessions 
             SET current_players = GREATEST(current_players - 1, 0),
                 status = CASE WHEN status = 'full' THEN 'open' ELSE status END
             WHERE id = %d",
            $group_id
        ));
        
        return true;
    }
    
    /**
     * Get a group session
     */
    public static function get_group_session($group_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT g.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug
             FROM {$wpdb->prefix}ptp_group_sessions g
             JOIN {$wpdb->prefix}ptp_trainers t ON g.trainer_id = t.id
             WHERE g.id = %d",
            $group_id
        ));
    }
    
    /**
     * Get participants for a group session
     */
    public static function get_participants($group_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT gp.*, p.name as player_name, p.age as player_age, 
                    pa.display_name as parent_name, pa.phone as parent_phone
             FROM {$wpdb->prefix}ptp_group_participants gp
             JOIN {$wpdb->prefix}ptp_players p ON gp.player_id = p.id
             JOIN {$wpdb->prefix}ptp_parents pa ON gp.parent_id = pa.id
             WHERE gp.group_session_id = %d AND gp.status != 'cancelled'
             ORDER BY gp.joined_at ASC",
            $group_id
        ));
    }
    
    /**
     * Get open group sessions (for browsing)
     */
    public static function get_open_sessions($filters = array()) {
        global $wpdb;
        
        $where = array("g.status IN ('open') AND g.session_date >= CURDATE() AND g.is_public = 1");
        $params = array();
        
        if (!empty($filters['trainer_id'])) {
            $where[] = "g.trainer_id = %d";
            $params[] = intval($filters['trainer_id']);
        }
        
        if (!empty($filters['skill_level']) && $filters['skill_level'] !== 'all') {
            $where[] = "(g.skill_level = %s OR g.skill_level = 'all')";
            $params[] = $filters['skill_level'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "g.session_date >= %s";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "g.session_date <= %s";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['location'])) {
            $where[] = "g.location LIKE %s";
            $params[] = '%' . $filters['location'] . '%';
        }
        
        $where_sql = implode(' AND ', $where);
        $limit = intval($filters['limit'] ?? 20);
        
        $sql = "SELECT g.*, t.display_name as trainer_name, t.photo_url as trainer_photo, 
                       t.slug as trainer_slug, t.average_rating as trainer_rating
                FROM {$wpdb->prefix}ptp_group_sessions g
                JOIN {$wpdb->prefix}ptp_trainers t ON g.trainer_id = t.id
                WHERE {$where_sql}
                ORDER BY g.session_date ASC, g.start_time ASC
                LIMIT {$limit}";
        
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * Get trainer's group sessions
     */
    public static function get_trainer_sessions($trainer_id, $include_past = false) {
        global $wpdb;
        
        $date_filter = $include_past ? "" : "AND g.session_date >= CURDATE()";
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT g.*, 
                    (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_group_participants gp 
                     WHERE gp.group_session_id = g.id AND gp.status != 'cancelled') as participant_count
             FROM {$wpdb->prefix}ptp_group_sessions g
             WHERE g.trainer_id = %d {$date_filter}
             ORDER BY g.session_date ASC, g.start_time ASC",
            $trainer_id
        ));
    }
    
    /**
     * Get parent's group session registrations
     */
    public static function get_parent_registrations($parent_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT gp.*, g.title, g.session_date, g.start_time, g.end_time, g.location,
                    g.price_per_player, g.current_players, g.max_players, g.status as session_status,
                    t.display_name as trainer_name, t.slug as trainer_slug,
                    p.name as player_name
             FROM {$wpdb->prefix}ptp_group_participants gp
             JOIN {$wpdb->prefix}ptp_group_sessions g ON gp.group_session_id = g.id
             JOIN {$wpdb->prefix}ptp_trainers t ON g.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_players p ON gp.player_id = p.id
             WHERE gp.parent_id = %d AND gp.status != 'cancelled'
             ORDER BY g.session_date ASC",
            $parent_id
        ));
    }
    
    /**
     * Complete a group session and process payments
     */
    public static function complete_session($group_id) {
        global $wpdb;
        
        $group = self::get_group_session($group_id);
        if (!$group) return false;
        
        // Update session status
        $wpdb->update(
            $wpdb->prefix . 'ptp_group_sessions',
            array('status' => 'completed'),
            array('id' => $group_id)
        );
        
        // Calculate trainer payout (group sessions use ongoing rate)
        $participants = self::get_participants($group_id);
        $total_collected = count($participants) * $group->price_per_player;
        $platform_fee = round($total_collected * PTP_Escrow::FEE_ONGOING, 2);
        $trainer_payout = $total_collected - $platform_fee;
        
        // Queue payout for trainer
        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_payouts WHERE trainer_id = %d AND status = 'pending'",
            $group->trainer_id
        ));
        
        if (!$payout) {
            $wpdb->insert($wpdb->prefix . 'ptp_payouts', array(
                'trainer_id' => $group->trainer_id,
                'amount' => $trainer_payout,
                'status' => 'pending',
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_payouts SET amount = amount + %f WHERE id = %d",
                $trainer_payout, $payout->id
            ));
        }
        
        // Update participant statuses
        $wpdb->update(
            $wpdb->prefix . 'ptp_group_participants',
            array('status' => 'attended'),
            array('group_session_id' => $group_id, 'status' => 'confirmed')
        );
        
        return true;
    }
    
    /**
     * AJAX handlers
     */
    public static function ajax_create_group_session() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Not a trainer'));
        }
        
        $group_id = self::create_group_session($trainer->id, $_POST);
        
        if ($group_id) {
            wp_send_json_success(array('group_id' => $group_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to create group session'));
        }
    }
    
    public static function ajax_join_group_session() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $parent = PTP_Parent::get_by_user_id(get_current_user_id());
        if (!$parent) {
            wp_send_json_error(array('message' => 'Parent account required'));
        }
        
        $result = self::join_group_session(
            intval($_POST['group_id']),
            $parent->id,
            intval($_POST['player_id'])
        );
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('participant_id' => $result));
    }
    
    public static function ajax_leave_group_session() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $result = self::leave_group_session(
            intval($_POST['group_id']),
            intval($_POST['player_id'])
        );
        
        wp_send_json_success(array('message' => 'Successfully left the group session'));
    }
    
    public static function ajax_get_open_groups() {
        $sessions = self::get_open_sessions($_GET);
        wp_send_json_success(array('sessions' => $sessions));
    }
}
