<?php
/**
 * PTP Training Plans & Progress Tracking
 * Comprehensive player development tracking system
 */

defined('ABSPATH') || exit;

class PTP_Training_Plans {
    
    // Skill categories for training
    private static $skill_categories = array(
        'technical' => array(
            'name' => 'Technical Skills',
            'skills' => array(
                'dribbling' => 'Dribbling',
                'passing' => 'Passing',
                'receiving' => 'First Touch/Receiving',
                'shooting' => 'Shooting',
                'heading' => 'Heading',
                'crossing' => 'Crossing',
                'ball_control' => 'Ball Control',
                'weak_foot' => 'Weak Foot',
            ),
        ),
        'tactical' => array(
            'name' => 'Tactical Awareness',
            'skills' => array(
                'positioning' => 'Positioning',
                'decision_making' => 'Decision Making',
                'game_reading' => 'Reading the Game',
                'spacing' => 'Spacing & Movement',
                'pressing' => 'Pressing',
                'transition' => 'Transition Play',
            ),
        ),
        'physical' => array(
            'name' => 'Physical Attributes',
            'skills' => array(
                'speed' => 'Speed',
                'agility' => 'Agility',
                'strength' => 'Strength',
                'endurance' => 'Endurance',
                'balance' => 'Balance',
                'coordination' => 'Coordination',
            ),
        ),
        'mental' => array(
            'name' => 'Mental & Character',
            'skills' => array(
                'focus' => 'Focus & Concentration',
                'confidence' => 'Confidence',
                'coachability' => 'Coachability',
                'work_ethic' => 'Work Ethic',
                'composure' => 'Composure Under Pressure',
                'leadership' => 'Leadership',
            ),
        ),
    );
    
    public static function init() {
        // AJAX endpoints
        add_action('wp_ajax_ptp_create_training_plan', array(__CLASS__, 'ajax_create_plan'));
        add_action('wp_ajax_ptp_update_training_plan', array(__CLASS__, 'ajax_update_plan'));
        add_action('wp_ajax_ptp_add_assessment', array(__CLASS__, 'ajax_add_assessment'));
        add_action('wp_ajax_ptp_add_session_note', array(__CLASS__, 'ajax_add_session_note'));
        add_action('wp_ajax_ptp_get_player_progress', array(__CLASS__, 'ajax_get_progress'));
        add_action('wp_ajax_ptp_complete_milestone', array(__CLASS__, 'ajax_complete_milestone'));
        add_action('wp_ajax_ptp_send_session_recap', array(__CLASS__, 'ajax_send_session_recap'));
        add_action('wp_ajax_ptp_get_session_recap', array(__CLASS__, 'ajax_get_session_recap'));
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Training plans table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_training_plans (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            title varchar(255) NOT NULL,
            description text,
            focus_areas text,
            goals text,
            duration_weeks int(11) DEFAULT 12,
            sessions_per_week int(11) DEFAULT 1,
            status enum('draft','active','completed','paused') DEFAULT 'draft',
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY player_id (player_id),
            KEY trainer_id (trainer_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Plan milestones table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_plan_milestones (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id bigint(20) UNSIGNED NOT NULL,
            title varchar(255) NOT NULL,
            description text,
            target_week int(11) DEFAULT NULL,
            skill_category varchar(50) DEFAULT NULL,
            skill_name varchar(50) DEFAULT NULL,
            target_level int(11) DEFAULT NULL,
            is_completed tinyint(1) DEFAULT 0,
            completed_at datetime DEFAULT NULL,
            notes text,
            sort_order int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY plan_id (plan_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Skill assessments table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_skill_assessments (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            plan_id bigint(20) UNSIGNED DEFAULT NULL,
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            assessment_date date NOT NULL,
            assessment_type enum('initial','progress','final') DEFAULT 'progress',
            overall_rating decimal(3,1) DEFAULT NULL,
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY player_id (player_id),
            KEY plan_id (plan_id),
            KEY assessment_date (assessment_date)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Individual skill ratings
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_skill_ratings (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            assessment_id bigint(20) UNSIGNED NOT NULL,
            skill_category varchar(50) NOT NULL,
            skill_name varchar(50) NOT NULL,
            rating int(11) NOT NULL,
            notes text,
            PRIMARY KEY (id),
            KEY assessment_id (assessment_id),
            KEY skill_name (skill_category, skill_name)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Session notes/progress entries
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_session_notes (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            plan_id bigint(20) UNSIGNED DEFAULT NULL,
            session_date date NOT NULL,
            focus_worked_on text,
            drills_performed text,
            achievements text,
            areas_to_improve text,
            homework text,
            player_effort int(11) DEFAULT NULL,
            player_attitude int(11) DEFAULT NULL,
            private_notes text,
            is_visible_to_parent tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY booking_id (booking_id),
            KEY player_id (player_id),
            KEY plan_id (plan_id)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Goals table (specific measurable goals)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_player_goals (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id bigint(20) UNSIGNED NOT NULL,
            plan_id bigint(20) UNSIGNED DEFAULT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            title varchar(255) NOT NULL,
            description text,
            category varchar(50) DEFAULT 'general',
            target_date date DEFAULT NULL,
            target_value varchar(100) DEFAULT NULL,
            current_value varchar(100) DEFAULT NULL,
            status enum('active','achieved','missed','cancelled') DEFAULT 'active',
            achieved_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY player_id (player_id),
            KEY plan_id (plan_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql);
    }
    
    /**
     * Get skill categories
     */
    public static function get_skill_categories() {
        return self::$skill_categories;
    }
    
    /**
     * Create a training plan
     */
    public static function create_plan($data) {
        global $wpdb;
        
        $insert_data = array(
            'player_id' => intval($data['player_id']),
            'trainer_id' => intval($data['trainer_id']),
            'title' => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'focus_areas' => maybe_serialize($data['focus_areas'] ?? array()),
            'goals' => sanitize_textarea_field($data['goals'] ?? ''),
            'duration_weeks' => intval($data['duration_weeks'] ?? 12),
            'sessions_per_week' => intval($data['sessions_per_week'] ?? 1),
            'status' => 'draft',
        );
        
        if (!empty($data['start_date'])) {
            $insert_data['start_date'] = sanitize_text_field($data['start_date']);
            $start = new DateTime($data['start_date']);
            $start->modify('+' . $insert_data['duration_weeks'] . ' weeks');
            $insert_data['end_date'] = $start->format('Y-m-d');
        }
        
        $wpdb->insert($wpdb->prefix . 'ptp_training_plans', $insert_data);
        $plan_id = $wpdb->insert_id;
        
        // Create milestones if provided
        if (!empty($data['milestones']) && is_array($data['milestones'])) {
            foreach ($data['milestones'] as $i => $milestone) {
                self::add_milestone($plan_id, array_merge($milestone, array('sort_order' => $i)));
            }
        }
        
        return $plan_id;
    }
    
    /**
     * Add milestone to plan
     */
    public static function add_milestone($plan_id, $data) {
        global $wpdb;
        
        return $wpdb->insert($wpdb->prefix . 'ptp_plan_milestones', array(
            'plan_id' => $plan_id,
            'title' => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'target_week' => !empty($data['target_week']) ? intval($data['target_week']) : null,
            'skill_category' => sanitize_text_field($data['skill_category'] ?? ''),
            'skill_name' => sanitize_text_field($data['skill_name'] ?? ''),
            'target_level' => !empty($data['target_level']) ? intval($data['target_level']) : null,
            'sort_order' => intval($data['sort_order'] ?? 0),
        ));
    }
    
    /**
     * Get a training plan with details
     */
    public static function get_plan($plan_id) {
        global $wpdb;
        
        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, pl.name as player_name, pl.age as player_age, pl.skill_level as player_level,
                    t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_training_plans p
             JOIN {$wpdb->prefix}ptp_players pl ON p.player_id = pl.id
             JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
             WHERE p.id = %d",
            $plan_id
        ));
        
        if (!$plan) return null;
        
        $plan->focus_areas = maybe_unserialize($plan->focus_areas);
        $plan->milestones = self::get_plan_milestones($plan_id);
        $plan->progress = self::calculate_plan_progress($plan_id);
        
        return $plan;
    }
    
    /**
     * Get milestones for a plan
     */
    public static function get_plan_milestones($plan_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_plan_milestones WHERE plan_id = %d ORDER BY sort_order ASC",
            $plan_id
        ));
    }
    
    /**
     * Calculate plan progress
     */
    public static function calculate_plan_progress($plan_id) {
        global $wpdb;
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_plan_milestones WHERE plan_id = %d",
            $plan_id
        ));
        
        $completed = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_plan_milestones WHERE plan_id = %d AND is_completed = 1",
            $plan_id
        ));
        
        return array(
            'total' => intval($total),
            'completed' => intval($completed),
            'percentage' => $total > 0 ? round(($completed / $total) * 100) : 0,
        );
    }
    
    /**
     * Mark milestone as complete
     */
    public static function complete_milestone($milestone_id, $notes = '') {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_plan_milestones',
            array(
                'is_completed' => 1,
                'completed_at' => current_time('mysql'),
                'notes' => sanitize_textarea_field($notes),
            ),
            array('id' => $milestone_id)
        );
    }
    
    /**
     * Create a skill assessment
     */
    public static function create_assessment($data) {
        global $wpdb;
        
        $insert_data = array(
            'player_id' => intval($data['player_id']),
            'trainer_id' => intval($data['trainer_id']),
            'plan_id' => !empty($data['plan_id']) ? intval($data['plan_id']) : null,
            'booking_id' => !empty($data['booking_id']) ? intval($data['booking_id']) : null,
            'assessment_date' => sanitize_text_field($data['assessment_date'] ?? date('Y-m-d')),
            'assessment_type' => sanitize_text_field($data['assessment_type'] ?? 'progress'),
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
        );
        
        $wpdb->insert($wpdb->prefix . 'ptp_skill_assessments', $insert_data);
        $assessment_id = $wpdb->insert_id;
        
        // Add skill ratings
        $total_rating = 0;
        $rating_count = 0;
        
        if (!empty($data['ratings']) && is_array($data['ratings'])) {
            foreach ($data['ratings'] as $category => $skills) {
                foreach ($skills as $skill => $rating) {
                    if ($rating > 0) {
                        $wpdb->insert($wpdb->prefix . 'ptp_skill_ratings', array(
                            'assessment_id' => $assessment_id,
                            'skill_category' => $category,
                            'skill_name' => $skill,
                            'rating' => intval($rating),
                            'notes' => $data['rating_notes'][$category][$skill] ?? '',
                        ));
                        $total_rating += intval($rating);
                        $rating_count++;
                    }
                }
            }
        }
        
        // Update overall rating
        if ($rating_count > 0) {
            $overall = round($total_rating / $rating_count, 1);
            $wpdb->update(
                $wpdb->prefix . 'ptp_skill_assessments',
                array('overall_rating' => $overall),
                array('id' => $assessment_id)
            );
        }
        
        return $assessment_id;
    }
    
    /**
     * Get assessment with ratings
     */
    public static function get_assessment($assessment_id) {
        global $wpdb;
        
        $assessment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, p.name as player_name, t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_skill_assessments a
             JOIN {$wpdb->prefix}ptp_players p ON a.player_id = p.id
             JOIN {$wpdb->prefix}ptp_trainers t ON a.trainer_id = t.id
             WHERE a.id = %d",
            $assessment_id
        ));
        
        if (!$assessment) return null;
        
        // Get ratings organized by category
        $ratings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_skill_ratings WHERE assessment_id = %d",
            $assessment_id
        ));
        
        $assessment->ratings = array();
        foreach ($ratings as $rating) {
            if (!isset($assessment->ratings[$rating->skill_category])) {
                $assessment->ratings[$rating->skill_category] = array();
            }
            $assessment->ratings[$rating->skill_category][$rating->skill_name] = array(
                'rating' => $rating->rating,
                'notes' => $rating->notes,
            );
        }
        
        return $assessment;
    }
    
    /**
     * Get player's assessment history
     */
    public static function get_player_assessments($player_id, $limit = 10) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_skill_assessments a
             JOIN {$wpdb->prefix}ptp_trainers t ON a.trainer_id = t.id
             WHERE a.player_id = %d
             ORDER BY a.assessment_date DESC
             LIMIT %d",
            $player_id, $limit
        ));
    }
    
    /**
     * Get skill progression over time
     */
    public static function get_skill_progression($player_id, $skill_category = null, $skill_name = null) {
        global $wpdb;
        
        $where = "a.player_id = %d";
        $params = array($player_id);
        
        if ($skill_category) {
            $where .= " AND r.skill_category = %s";
            $params[] = $skill_category;
        }
        
        if ($skill_name) {
            $where .= " AND r.skill_name = %s";
            $params[] = $skill_name;
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.assessment_date, r.skill_category, r.skill_name, r.rating
             FROM {$wpdb->prefix}ptp_skill_assessments a
             JOIN {$wpdb->prefix}ptp_skill_ratings r ON a.id = r.assessment_id
             WHERE {$where}
             ORDER BY a.assessment_date ASC",
            $params
        ));
    }
    
    /**
     * Add session notes
     */
    public static function add_session_notes($booking_id, $data) {
        global $wpdb;
        
        // Get booking details
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking) return false;
        
        // Check for active plan
        $plan_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_training_plans 
             WHERE player_id = %d AND trainer_id = %d AND status = 'active'
             ORDER BY created_at DESC LIMIT 1",
            $booking->player_id, $booking->trainer_id
        ));
        
        return $wpdb->insert($wpdb->prefix . 'ptp_session_notes', array(
            'booking_id' => $booking_id,
            'player_id' => $booking->player_id,
            'trainer_id' => $booking->trainer_id,
            'plan_id' => $plan_id,
            'session_date' => $booking->session_date,
            'focus_worked_on' => sanitize_textarea_field($data['focus_worked_on'] ?? ''),
            'drills_performed' => sanitize_textarea_field($data['drills_performed'] ?? ''),
            'achievements' => sanitize_textarea_field($data['achievements'] ?? ''),
            'areas_to_improve' => sanitize_textarea_field($data['areas_to_improve'] ?? ''),
            'homework' => sanitize_textarea_field($data['homework'] ?? ''),
            'next_session_focus' => sanitize_textarea_field($data['next_session_focus'] ?? ''),
            'player_effort' => !empty($data['player_effort']) ? intval($data['player_effort']) : null,
            'player_attitude' => !empty($data['player_attitude']) ? intval($data['player_attitude']) : null,
            'private_notes' => sanitize_textarea_field($data['private_notes'] ?? ''),
            'is_visible_to_parent' => isset($data['is_visible_to_parent']) ? intval($data['is_visible_to_parent']) : 1,
        ));
    }
    
    /**
     * Get session notes for a player
     */
    public static function get_player_session_notes($player_id, $limit = 20, $for_parent = false) {
        global $wpdb;
        
        $visibility = $for_parent ? "AND sn.is_visible_to_parent = 1" : "";
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT sn.*, t.display_name as trainer_name, b.booking_number
             FROM {$wpdb->prefix}ptp_session_notes sn
             JOIN {$wpdb->prefix}ptp_trainers t ON sn.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_bookings b ON sn.booking_id = b.id
             WHERE sn.player_id = %d {$visibility}
             ORDER BY sn.session_date DESC
             LIMIT %d",
            $player_id, $limit
        ));
    }
    
    /**
     * Create/update player goal
     */
    public static function save_goal($data) {
        global $wpdb;
        
        $goal_data = array(
            'player_id' => intval($data['player_id']),
            'trainer_id' => intval($data['trainer_id']),
            'plan_id' => !empty($data['plan_id']) ? intval($data['plan_id']) : null,
            'title' => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'category' => sanitize_text_field($data['category'] ?? 'general'),
            'target_date' => !empty($data['target_date']) ? sanitize_text_field($data['target_date']) : null,
            'target_value' => sanitize_text_field($data['target_value'] ?? ''),
            'current_value' => sanitize_text_field($data['current_value'] ?? ''),
        );
        
        if (!empty($data['id'])) {
            $wpdb->update($wpdb->prefix . 'ptp_player_goals', $goal_data, array('id' => intval($data['id'])));
            return intval($data['id']);
        } else {
            $wpdb->insert($wpdb->prefix . 'ptp_player_goals', $goal_data);
            return $wpdb->insert_id;
        }
    }
    
    /**
     * Mark goal as achieved
     */
    public static function achieve_goal($goal_id) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_player_goals',
            array(
                'status' => 'achieved',
                'achieved_at' => current_time('mysql'),
            ),
            array('id' => $goal_id)
        );
    }
    
    /**
     * Get player's goals
     */
    public static function get_player_goals($player_id, $status = null) {
        global $wpdb;
        
        $where = "player_id = %d";
        $params = array($player_id);
        
        if ($status) {
            $where .= " AND status = %s";
            $params[] = $status;
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_player_goals WHERE {$where} ORDER BY target_date ASC",
            $params
        ));
    }
    
    /**
     * Get comprehensive player progress report
     */
    public static function get_player_progress_report($player_id) {
        global $wpdb;
        
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, pa.display_name as parent_name
             FROM {$wpdb->prefix}ptp_players p
             JOIN {$wpdb->prefix}ptp_parents pa ON p.parent_id = pa.id
             WHERE p.id = %d",
            $player_id
        ));
        
        if (!$player) return null;
        
        // Get active training plan
        $active_plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_training_plans 
             WHERE player_id = %d AND status = 'active'
             ORDER BY created_at DESC LIMIT 1",
            $player_id
        ));
        
        if ($active_plan) {
            $active_plan->focus_areas = maybe_unserialize($active_plan->focus_areas);
            $active_plan->milestones = self::get_plan_milestones($active_plan->id);
            $active_plan->progress = self::calculate_plan_progress($active_plan->id);
        }
        
        // Get latest assessment
        $latest_assessment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_skill_assessments 
             WHERE player_id = %d 
             ORDER BY assessment_date DESC LIMIT 1",
            $player_id
        ));
        
        if ($latest_assessment) {
            $latest_assessment = self::get_assessment($latest_assessment->id);
        }
        
        // Get skill progression (compare first vs latest)
        $first_assessment = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_skill_assessments 
             WHERE player_id = %d 
             ORDER BY assessment_date ASC LIMIT 1",
            $player_id
        ));
        
        $skill_changes = array();
        if ($first_assessment && $latest_assessment && $first_assessment->id !== $latest_assessment->id) {
            $first = self::get_assessment($first_assessment->id);
            foreach (self::$skill_categories as $cat_key => $category) {
                foreach ($category['skills'] as $skill_key => $skill_name) {
                    $old = $first->ratings[$cat_key][$skill_key]['rating'] ?? 0;
                    $new = $latest_assessment->ratings[$cat_key][$skill_key]['rating'] ?? 0;
                    if ($old > 0 || $new > 0) {
                        $skill_changes[$cat_key][$skill_key] = array(
                            'name' => $skill_name,
                            'old' => $old,
                            'new' => $new,
                            'change' => $new - $old,
                        );
                    }
                }
            }
        }
        
        // Get recent session notes
        $recent_notes = self::get_player_session_notes($player_id, 5, true);
        
        // Get active goals
        $goals = self::get_player_goals($player_id, 'active');
        
        // Get stats
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_sessions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_sessions,
                MIN(session_date) as first_session,
                MAX(session_date) as last_session
             FROM {$wpdb->prefix}ptp_bookings
             WHERE player_id = %d",
            $player_id
        ));
        
        return array(
            'player' => $player,
            'active_plan' => $active_plan,
            'latest_assessment' => $latest_assessment,
            'skill_changes' => $skill_changes,
            'recent_notes' => $recent_notes,
            'goals' => $goals,
            'stats' => $stats,
            'skill_categories' => self::$skill_categories,
        );
    }
    
    /**
     * Get trainer's players with active plans
     */
    public static function get_trainer_players($trainer_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.*, pa.display_name as parent_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings b 
                     WHERE b.player_id = p.id AND b.trainer_id = %d AND b.status = 'completed') as sessions_with_trainer,
                    (SELECT id FROM {$wpdb->prefix}ptp_training_plans tp 
                     WHERE tp.player_id = p.id AND tp.trainer_id = %d AND tp.status = 'active' LIMIT 1) as active_plan_id
             FROM {$wpdb->prefix}ptp_players p
             JOIN {$wpdb->prefix}ptp_parents pa ON p.parent_id = pa.id
             JOIN {$wpdb->prefix}ptp_bookings b ON p.id = b.player_id
             WHERE b.trainer_id = %d
             GROUP BY p.id
             ORDER BY p.name ASC",
            $trainer_id, $trainer_id, $trainer_id
        ));
    }
    
    /**
     * AJAX: Create training plan
     */
    public static function ajax_create_plan() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Not a trainer'));
        }
        
        $_POST['trainer_id'] = $trainer->id;
        $plan_id = self::create_plan($_POST);
        
        if ($plan_id) {
            wp_send_json_success(array('plan_id' => $plan_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to create plan'));
        }
    }
    
    /**
     * AJAX: Add assessment
     */
    public static function ajax_add_assessment() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Not a trainer'));
        }
        
        $_POST['trainer_id'] = $trainer->id;
        $assessment_id = self::create_assessment($_POST);
        
        if ($assessment_id) {
            wp_send_json_success(array('assessment_id' => $assessment_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to create assessment'));
        }
    }
    
    /**
     * AJAX: Add session note
     */
    public static function ajax_add_session_note() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $booking_id = intval($_POST['booking_id']);
        $result = self::add_session_notes($booking_id, $_POST);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Session notes saved'));
        } else {
            wp_send_json_error(array('message' => 'Failed to save notes'));
        }
    }
    
    /**
     * AJAX: Get player progress
     */
    public static function ajax_get_progress() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $player_id = intval($_POST['player_id']);
        $report = self::get_player_progress_report($player_id);
        
        if ($report) {
            wp_send_json_success($report);
        } else {
            wp_send_json_error(array('message' => 'Player not found'));
        }
    }
    
    /**
     * AJAX: Complete milestone
     */
    public static function ajax_complete_milestone() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $milestone_id = intval($_POST['milestone_id']);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        $result = self::complete_milestone($milestone_id, $notes);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Milestone completed!'));
        } else {
            wp_send_json_error(array('message' => 'Failed to update milestone'));
        }
    }
    
    /**
     * Check if a booking already has a recap
     */
    public static function get_recap_for_booking($booking_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_session_notes';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE booking_id = %d LIMIT 1",
            $booking_id
        ));
    }
    
    /**
     * Get recaps for multiple booking IDs at once
     */
    public static function get_recaps_for_bookings($booking_ids) {
        global $wpdb;
        if (empty($booking_ids)) return array();
        $table = $wpdb->prefix . 'ptp_session_notes';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) return array();
        $ids = implode(',', array_map('intval', $booking_ids));
        $results = $wpdb->get_results("SELECT * FROM {$table} WHERE booking_id IN ({$ids})");
        $map = array();
        foreach ($results as $r) { $map[$r->booking_id] = $r; }
        return $map;
    }
    
    /**
     * AJAX: Send session recap (save note + email parent)
     */
    public static function ajax_send_session_recap() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Missing booking ID'));
        }
        
        // Check if recap already exists
        $existing = self::get_recap_for_booking($booking_id);
        if ($existing) {
            wp_send_json_error(array('message' => 'Recap already sent for this session'));
        }
        
        // Save the session note
        $result = self::add_session_notes($booking_id, $_POST);
        
        if (!$result) {
            wp_send_json_error(array('message' => 'Failed to save recap'));
        }
        
        // Get full booking details for email
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, 
                    t.display_name as trainer_name, t.photo_url as trainer_photo,
                    COALESCE(pa.display_name, 'Parent') as parent_name, 
                    pa.user_id as parent_user_id, pa.email as parent_email,
                    COALESCE(pl.name, 'Player') as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            wp_send_json_success(array('message' => 'Recap saved (no booking found for email)'));
        }
        
        // Get parent email
        $parent_email = '';
        if (!empty($booking->parent_email)) {
            $parent_email = $booking->parent_email;
        } elseif (!empty($booking->parent_user_id)) {
            $user = get_userdata($booking->parent_user_id);
            if ($user) $parent_email = $user->user_email;
        }
        
        if (empty($parent_email)) {
            wp_send_json_success(array('message' => 'Recap saved (no parent email found)'));
        }
        
        // Build effort display
        $effort = intval($_POST['player_effort'] ?? 0);
        $effort_labels = array(1 => 'Needs Work', 2 => 'Fair', 3 => 'Good', 4 => 'Great', 5 => 'Elite');
        $effort_label = $effort_labels[$effort] ?? '';
        
        // Send recap email
        $email_data = array(
            'parent_name'      => $booking->parent_name,
            'player_name'      => $booking->player_name,
            'trainer_name'     => $booking->trainer_name,
            'trainer_photo'    => $booking->trainer_photo ?: '',
            'session_date'     => date('l, F j, Y', strtotime($booking->session_date)),
            'focus_worked_on'  => sanitize_textarea_field($_POST['focus_worked_on'] ?? ''),
            'achievements'     => sanitize_textarea_field($_POST['achievements'] ?? ''),
            'areas_to_improve' => sanitize_textarea_field($_POST['areas_to_improve'] ?? ''),
            'homework'         => sanitize_textarea_field($_POST['homework'] ?? ''),
            'next_session_focus' => sanitize_textarea_field($_POST['next_session_focus'] ?? ''),
            'player_effort'    => $effort,
            'effort_label'     => $effort_label,
            'skills_html'      => '',
            'ai_plan_html'     => '',
            'dashboard_url'    => home_url('/parent-dashboard/'),
        );
        
        // Use PTP_Email render
        if (class_exists('PTP_Email')) {
            if (method_exists('PTP_Email', 'render_training_plan_email')) {
                $body = PTP_Email::render_training_plan_email($email_data);
            } else {
                $body = PTP_Email::render_session_recap_email($email_data);
            }
            $headers = array('Content-Type: text/html; charset=UTF-8');
            $subject = "{$booking->player_name}'s Training Plan — {$booking->trainer_name}";
            wp_mail($parent_email, $subject, $body, $headers);
        }
        
        wp_send_json_success(array('message' => 'Recap sent to parent!'));
    }
    
    /**
     * AJAX: Get session recap for a booking (for parent view)
     */
    public static function ajax_get_session_recap() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $recap = self::get_recap_for_booking($booking_id);
        
        if ($recap) {
            wp_send_json_success(array(
                'focus_worked_on'  => $recap->focus_worked_on,
                'achievements'     => $recap->achievements,
                'areas_to_improve' => $recap->areas_to_improve,
                'homework'         => $recap->homework,
                'next_session_focus' => $recap->next_session_focus ?? '',
                'player_effort'    => intval($recap->player_effort),
                'session_date'     => $recap->session_date,
            ));
        } else {
            wp_send_json_error(array('message' => 'No recap found'));
        }
    }
}
