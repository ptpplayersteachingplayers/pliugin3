<?php
/**
 * PTP Quality Control System
 * 
 * Trainer accountability and quality assurance:
 * - Mandatory post-session notes
 * - Parent ratings with 24hr SMS prompts
 * - Response time tracking
 * - No-show tracking
 * - Automated flags and actions
 * - Admin oversight dashboard
 * 
 * @since 59.3.0
 */

defined('ABSPATH') || exit;

class PTP_Quality_Control {
    
    // Rating thresholds
    const RATING_WARNING = 4.0;      // Below this = warning
    const RATING_PROBATION = 3.5;    // Below this = probation
    const RATING_REMOVAL = 3.0;      // Below this = removal
    
    // Flag thresholds
    const MAX_LOW_RATINGS = 2;       // 2+ ratings below 3 = pause
    const MAX_MISSING_NOTES = 3;     // 3 sessions without notes = warning
    const RESPONSE_TIME_HOURS = 24;  // Expected response within 24 hours
    
    // Review prompt timing
    const REVIEW_PROMPT_DELAY = 24;  // Hours after session to send SMS
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // AJAX handlers
        add_action('wp_ajax_ptp_save_session_notes', array($this, 'ajax_save_session_notes'));
        add_action('wp_ajax_ptp_submit_session_review', array($this, 'ajax_submit_session_review'));
        add_action('wp_ajax_ptp_report_no_show', array($this, 'ajax_report_no_show'));
        add_action('wp_ajax_ptp_resolve_flag', array($this, 'ajax_resolve_flag'));
        add_action('wp_ajax_ptp_pause_trainer', array($this, 'ajax_pause_trainer'));
        add_action('wp_ajax_ptp_unpause_trainer', array($this, 'ajax_unpause_trainer'));
        add_action('wp_ajax_ptp_get_quality_dashboard', array($this, 'ajax_get_quality_dashboard'));
        
        // Hooks into session lifecycle
        add_action('ptp_session_completed', array($this, 'on_session_completed'), 10, 2);
        add_action('ptp_review_submitted', array($this, 'on_review_submitted'), 10, 2);
        add_action('ptp_message_received', array($this, 'track_response_time'), 10, 2);
        
        // Cron jobs
        add_action('ptp_send_review_prompts', array($this, 'send_review_prompts'));
        add_action('ptp_check_missing_notes', array($this, 'check_missing_notes'));
        add_action('ptp_update_response_times', array($this, 'update_response_times'));
        
        // Schedule cron if not scheduled
        if (!wp_next_scheduled('ptp_send_review_prompts')) {
            wp_schedule_event(time(), 'hourly', 'ptp_send_review_prompts');
        }
        if (!wp_next_scheduled('ptp_check_missing_notes')) {
            wp_schedule_event(time(), 'daily', 'ptp_check_missing_notes');
        }
        if (!wp_next_scheduled('ptp_update_response_times')) {
            wp_schedule_event(time(), 'hourly', 'ptp_update_response_times');
        }
        
        // Shortcodes
        add_shortcode('ptp_session_notes_form', array($this, 'shortcode_session_notes_form'));
        add_shortcode('ptp_review_form', array($this, 'shortcode_review_form'));
        add_shortcode('ptp_quality_dashboard', array($this, 'shortcode_quality_dashboard'));
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        // Session notes table
        $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_session_notes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id BIGINT UNSIGNED NOT NULL,
            trainer_id BIGINT UNSIGNED NOT NULL,
            skills_worked TEXT,
            progress_notes TEXT,
            homework TEXT,
            next_focus TEXT,
            session_rating TINYINT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY booking_id (booking_id),
            KEY trainer_id (trainer_id)
        ) $charset;";
        
        // Quality flags table
        $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_quality_flags (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trainer_id BIGINT UNSIGNED NOT NULL,
            flag_type VARCHAR(50) NOT NULL,
            severity ENUM('warning', 'serious', 'critical') DEFAULT 'warning',
            details TEXT,
            booking_id BIGINT UNSIGNED,
            auto_action VARCHAR(50),
            resolved TINYINT(1) DEFAULT 0,
            resolved_by BIGINT UNSIGNED,
            resolved_at DATETIME,
            resolution_notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY trainer_id (trainer_id),
            KEY flag_type (flag_type),
            KEY resolved (resolved),
            KEY created_at (created_at)
        ) $charset;";
        
        // Trainer metrics table (daily snapshot)
        $sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_trainer_metrics (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trainer_id BIGINT UNSIGNED NOT NULL,
            metric_date DATE NOT NULL,
            total_sessions INT DEFAULT 0,
            completed_sessions INT DEFAULT 0,
            cancelled_sessions INT DEFAULT 0,
            no_shows INT DEFAULT 0,
            avg_rating DECIMAL(3,2),
            total_reviews INT DEFAULT 0,
            sessions_with_notes INT DEFAULT 0,
            avg_response_time_hours DECIMAL(5,2),
            completion_rate DECIMAL(5,2),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY trainer_date (trainer_id, metric_date),
            KEY trainer_id (trainer_id)
        ) $charset;";
        
        // Response time tracking
        $sql4 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_response_tracking (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trainer_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            parent_message_at DATETIME NOT NULL,
            trainer_response_at DATETIME,
            response_time_hours DECIMAL(5,2),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY trainer_id (trainer_id),
            KEY conversation_id (conversation_id)
        ) $charset;";
        
        // Review prompts tracking
        $sql5 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_review_prompts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            booking_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NOT NULL,
            prompt_type ENUM('email', 'sms') NOT NULL,
            sent_at DATETIME,
            opened_at DATETIME,
            completed_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY booking_prompt (booking_id, prompt_type),
            KEY parent_id (parent_id)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
        dbDelta($sql5);
    }
    
    /**
     * ======================
     * SESSION NOTES SYSTEM
     * ======================
     */
    
    /**
     * Save session notes (trainer action)
     */
    public function ajax_save_session_notes() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $skills_worked = sanitize_textarea_field($_POST['skills_worked'] ?? '');
        $progress_notes = sanitize_textarea_field($_POST['progress_notes'] ?? '');
        $homework = sanitize_textarea_field($_POST['homework'] ?? '');
        $next_focus = sanitize_textarea_field($_POST['next_focus'] ?? '');
        $session_rating = intval($_POST['session_rating'] ?? 0);
        
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Invalid booking'));
        }
        
        // Verify trainer owns this booking
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.id as trainer_id 
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.id = %d AND t.user_id = %d",
            $booking_id,
            get_current_user_id()
        ));
        
        if (!$booking) {
            wp_send_json_error(array('message' => 'Booking not found or unauthorized'));
        }
        
        // Require at least skills worked and progress notes
        if (empty($skills_worked) || empty($progress_notes)) {
            wp_send_json_error(array('message' => 'Skills worked and progress notes are required'));
        }
        
        // Save or update notes
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_session_notes WHERE booking_id = %d",
            $booking_id
        ));
        
        $data = array(
            'booking_id' => $booking_id,
            'trainer_id' => $booking->trainer_id,
            'skills_worked' => $skills_worked,
            'progress_notes' => $progress_notes,
            'homework' => $homework,
            'next_focus' => $next_focus,
            'session_rating' => $session_rating ?: null,
        );
        
        if ($existing) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_session_notes',
                $data,
                array('id' => $existing)
            );
        } else {
            $wpdb->insert($wpdb->prefix . 'ptp_session_notes', $data);
        }
        
        wp_send_json_success(array('message' => 'Session notes saved'));
    }
    
    /**
     * Get session notes for a booking
     */
    public static function get_session_notes($booking_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_session_notes WHERE booking_id = %d",
            $booking_id
        ));
    }
    
    /**
     * Check if session has notes
     */
    public static function has_session_notes($booking_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_session_notes WHERE booking_id = %d",
            $booking_id
        ));
    }
    
    /**
     * Get trainers with missing notes
     */
    public static function get_trainers_missing_notes() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT t.id, t.display_name, t.user_id, COUNT(b.id) as missing_count
            FROM {$wpdb->prefix}ptp_trainers t
            JOIN {$wpdb->prefix}ptp_bookings b ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_session_notes sn ON sn.booking_id = b.id
            WHERE b.status = 'completed'
            AND b.session_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND sn.id IS NULL
            GROUP BY t.id
            HAVING missing_count >= " . self::MAX_MISSING_NOTES . "
        ");
    }
    
    /**
     * ======================
     * PARENT REVIEW SYSTEM
     * ======================
     */
    
    /**
     * Submit session review (parent action)
     */
    public function ajax_submit_session_review() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $rating = intval($_POST['rating'] ?? 0);
        $review_text = sanitize_textarea_field($_POST['review'] ?? '');
        $would_recommend = intval($_POST['would_recommend'] ?? 1);
        
        if (!$booking_id || $rating < 1 || $rating > 5) {
            wp_send_json_error(array('message' => 'Invalid rating'));
        }
        
        global $wpdb;
        
        // Verify parent owns this booking
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, p.id as parent_id, p.user_id as parent_user_id
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             WHERE b.id = %d AND p.user_id = %d",
            $booking_id,
            get_current_user_id()
        ));
        
        if (!$booking) {
            wp_send_json_error(array('message' => 'Booking not found'));
        }
        
        // Check if already reviewed
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_reviews WHERE booking_id = %d",
            $booking_id
        ));
        
        if ($existing) {
            wp_send_json_error(array('message' => 'Session already reviewed'));
        }
        
        // Create review
        $wpdb->insert($wpdb->prefix . 'ptp_reviews', array(
            'booking_id' => $booking_id,
            'trainer_id' => $booking->trainer_id,
            'parent_id' => $booking->parent_id,
            'rating' => $rating,
            'review' => $review_text,
            'would_recommend' => $would_recommend,
            'is_published' => 1,
        ));
        
        $review_id = $wpdb->insert_id;
        
        // Mark review prompt as completed
        $wpdb->update(
            $wpdb->prefix . 'ptp_review_prompts',
            array('completed_at' => current_time('mysql')),
            array('booking_id' => $booking_id)
        );
        
        // Trigger review submitted action
        do_action('ptp_review_submitted', $review_id, $rating);
        
        // Check for quality flags
        $this->check_rating_flags($booking->trainer_id, $rating);
        
        wp_send_json_success(array(
            'message' => 'Thank you for your feedback!',
            'review_id' => $review_id
        ));
    }
    
    /**
     * Check if rating triggers quality flags
     */
    private function check_rating_flags($trainer_id, $rating) {
        global $wpdb;
        
        // If rating is below 3, check for flag
        if ($rating < 3) {
            // Count recent low ratings
            $low_ratings = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->prefix}ptp_reviews
                WHERE trainer_id = %d
                AND rating < 3
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ", $trainer_id));
            
            if ($low_ratings >= self::MAX_LOW_RATINGS) {
                $this->create_flag(
                    $trainer_id,
                    'multiple_low_ratings',
                    'critical',
                    sprintf('%d ratings below 3 stars in the last 30 days', $low_ratings),
                    null,
                    'pause_profile'
                );
                
                // Auto-pause trainer
                $this->pause_trainer($trainer_id, 'Automatic pause due to multiple low ratings');
            } else {
                $this->create_flag(
                    $trainer_id,
                    'low_rating',
                    'warning',
                    sprintf('Received %d star rating', $rating)
                );
            }
        }
        
        // Check overall average
        $avg_rating = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(rating) FROM {$wpdb->prefix}ptp_reviews
            WHERE trainer_id = %d AND is_published = 1
        ", $trainer_id));
        
        if ($avg_rating && $avg_rating < self::RATING_WARNING) {
            $this->create_flag(
                $trainer_id,
                'low_average_rating',
                $avg_rating < self::RATING_PROBATION ? 'serious' : 'warning',
                sprintf('Average rating dropped to %.2f', $avg_rating)
            );
        }
    }
    
    /**
     * Send review prompts (cron job)
     */
    public function send_review_prompts() {
        global $wpdb;
        
        // Find completed sessions from ~24 hours ago without reviews
        $sessions = $wpdb->get_results($wpdb->prepare("
            SELECT b.id as booking_id, b.parent_id, b.trainer_id,
                   p.display_name as parent_name, p.phone as parent_phone, p.user_id as parent_user_id,
                   t.display_name as trainer_name,
                   pl.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            LEFT JOIN {$wpdb->prefix}ptp_reviews r ON r.booking_id = b.id
            LEFT JOIN {$wpdb->prefix}ptp_review_prompts rp ON rp.booking_id = b.id
            WHERE b.status = 'completed'
            AND b.updated_at BETWEEN DATE_SUB(NOW(), INTERVAL %d HOUR) AND DATE_SUB(NOW(), INTERVAL %d HOUR)
            AND r.id IS NULL
            AND rp.id IS NULL
        ", self::REVIEW_PROMPT_DELAY + 1, self::REVIEW_PROMPT_DELAY - 1));
        
        foreach ($sessions as $session) {
            // Create prompt record
            $wpdb->insert($wpdb->prefix . 'ptp_review_prompts', array(
                'booking_id' => $session->booking_id,
                'parent_id' => $session->parent_id,
                'prompt_type' => 'sms',
            ));
            
            // Send SMS if phone available and SMS enabled
            if (!empty($session->parent_phone) && class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
                $review_url = home_url('/my-training/?review=' . $session->booking_id);
                
                $message = sprintf(
                    "Hi %s! How was %s's training with %s? Leave a quick review: %s",
                    explode(' ', $session->parent_name)[0],
                    $session->player_name ?: 'your player',
                    explode(' ', $session->trainer_name)[0],
                    $review_url
                );
                
                PTP_SMS::send($session->parent_phone, $message);
                
                $wpdb->update(
                    $wpdb->prefix . 'ptp_review_prompts',
                    array('sent_at' => current_time('mysql')),
                    array('booking_id' => $session->booking_id, 'prompt_type' => 'sms')
                );
            }
            
            // Also send email
            if (class_exists('PTP_Email')) {
                $wpdb->insert($wpdb->prefix . 'ptp_review_prompts', array(
                    'booking_id' => $session->booking_id,
                    'parent_id' => $session->parent_id,
                    'prompt_type' => 'email',
                    'sent_at' => current_time('mysql'),
                ));
                
                // Email would be sent via PTP_Email::send_review_request()
            }
        }
    }
    
    /**
     * ======================
     * NO-SHOW TRACKING
     * ======================
     */
    
    /**
     * Report no-show (parent action)
     */
    public function ajax_report_no_show() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $details = sanitize_textarea_field($_POST['details'] ?? '');
        
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Invalid booking'));
        }
        
        global $wpdb;
        
        // Verify parent owns this booking
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, p.user_id as parent_user_id, t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.id = %d AND p.user_id = %d",
            $booking_id,
            get_current_user_id()
        ));
        
        if (!$booking) {
            wp_send_json_error(array('message' => 'Booking not found'));
        }
        
        // Mark booking as no-show
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array('status' => 'no_show'),
            array('id' => $booking_id)
        );
        
        // Create critical flag - immediate pause
        $this->create_flag(
            $booking->trainer_id,
            'no_show',
            'critical',
            sprintf('No-show reported for session on %s. Details: %s', $booking->session_date, $details),
            $booking_id,
            'pause_profile'
        );
        
        // Auto-pause trainer
        $this->pause_trainer($booking->trainer_id, 'Automatic pause due to no-show report');
        
        // Initiate refund
        if (!empty($booking->payment_intent_id) && class_exists('PTP_Stripe')) {
            PTP_Stripe::create_refund($booking->payment_intent_id, null, 'trainer_no_show');
        }
        
        // Notify admin
        $this->notify_admin_flag($booking->trainer_id, 'no_show', $booking->trainer_name);
        
        wp_send_json_success(array(
            'message' => 'No-show reported. The trainer has been paused and your refund is being processed.'
        ));
    }
    
    /**
     * ======================
     * RESPONSE TIME TRACKING
     * ======================
     */
    
    /**
     * Track message response time
     */
    public function track_response_time($message_id, $conversation_id) {
        global $wpdb;
        
        // Get message details
        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT m.*, c.trainer_id, c.parent_id
             FROM {$wpdb->prefix}ptp_messages m
             JOIN {$wpdb->prefix}ptp_conversations c ON m.conversation_id = c.id
             WHERE m.id = %d",
            $message_id
        ));
        
        if (!$message) return;
        
        // Check if this is a trainer responding to a parent
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $message->sender_id
        ));
        
        if ($trainer && $trainer->id == $message->trainer_id) {
            // Trainer is responding - find the parent's last unanswered message
            $parent_message = $wpdb->get_row($wpdb->prepare("
                SELECT rt.* FROM {$wpdb->prefix}ptp_response_tracking rt
                WHERE rt.conversation_id = %d
                AND rt.trainer_id = %d
                AND rt.trainer_response_at IS NULL
                ORDER BY rt.parent_message_at DESC
                LIMIT 1
            ", $conversation_id, $trainer->id));
            
            if ($parent_message) {
                $response_hours = (strtotime($message->created_at) - strtotime($parent_message->parent_message_at)) / 3600;
                
                $wpdb->update(
                    $wpdb->prefix . 'ptp_response_tracking',
                    array(
                        'trainer_response_at' => $message->created_at,
                        'response_time_hours' => round($response_hours, 2)
                    ),
                    array('id' => $parent_message->id)
                );
                
                // Check if response was slow
                if ($response_hours > self::RESPONSE_TIME_HOURS) {
                    $this->check_response_time_flags($trainer->id);
                }
            }
        } else {
            // Parent is messaging - create tracking record
            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $message->sender_id
            ));
            
            if ($parent && $parent->id == $message->parent_id) {
                $wpdb->insert($wpdb->prefix . 'ptp_response_tracking', array(
                    'trainer_id' => $message->trainer_id,
                    'conversation_id' => $conversation_id,
                    'parent_message_at' => $message->created_at,
                ));
            }
        }
    }
    
    /**
     * Check if trainer has consistent slow responses
     */
    private function check_response_time_flags($trainer_id) {
        global $wpdb;
        
        // Get average response time in last 30 days
        $avg_response = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(response_time_hours)
            FROM {$wpdb->prefix}ptp_response_tracking
            WHERE trainer_id = %d
            AND trainer_response_at IS NOT NULL
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ", $trainer_id));
        
        // Count slow responses
        $slow_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}ptp_response_tracking
            WHERE trainer_id = %d
            AND response_time_hours > %d
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ", $trainer_id, self::RESPONSE_TIME_HOURS));
        
        if ($slow_count >= 5 || ($avg_response && $avg_response > self::RESPONSE_TIME_HOURS)) {
            // Check if already flagged recently
            $recent_flag = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}ptp_quality_flags
                WHERE trainer_id = %d
                AND flag_type = 'slow_response'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ", $trainer_id));
            
            if (!$recent_flag) {
                $this->create_flag(
                    $trainer_id,
                    'slow_response',
                    'warning',
                    sprintf('Average response time: %.1f hours. %d slow responses in last 30 days.', $avg_response, $slow_count),
                    null,
                    'remove_fast_response_badge'
                );
                
                // Remove "Fast Response" badge
                $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    array('fast_responder' => 0),
                    array('id' => $trainer_id)
                );
            }
        }
    }
    
    /**
     * Update response time metrics (cron)
     */
    public function update_response_times() {
        global $wpdb;
        
        // Find unanswered messages older than threshold
        $unanswered = $wpdb->get_results($wpdb->prepare("
            SELECT trainer_id, COUNT(*) as count
            FROM {$wpdb->prefix}ptp_response_tracking
            WHERE trainer_response_at IS NULL
            AND parent_message_at < DATE_SUB(NOW(), INTERVAL %d HOUR)
            GROUP BY trainer_id
        ", self::RESPONSE_TIME_HOURS));
        
        foreach ($unanswered as $row) {
            if ($row->count >= 3) {
                $this->check_response_time_flags($row->trainer_id);
            }
        }
    }
    
    /**
     * ======================
     * FLAG MANAGEMENT
     * ======================
     */
    
    /**
     * Create quality flag
     */
    public function create_flag($trainer_id, $flag_type, $severity, $details, $booking_id = null, $auto_action = null) {
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . 'ptp_quality_flags', array(
            'trainer_id' => $trainer_id,
            'flag_type' => $flag_type,
            'severity' => $severity,
            'details' => $details,
            'booking_id' => $booking_id,
            'auto_action' => $auto_action,
        ));
        
        $flag_id = $wpdb->insert_id;
        
        // Execute auto-action if set
        if ($auto_action === 'pause_profile') {
            $this->pause_trainer($trainer_id, 'Auto-paused: ' . $flag_type);
        }
        
        // Notify admin for serious/critical flags
        if ($severity !== 'warning') {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT display_name FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $trainer_id
            ));
            $this->notify_admin_flag($trainer_id, $flag_type, $trainer->display_name ?? 'Unknown');
        }
        
        return $flag_id;
    }
    
    /**
     * Resolve flag
     */
    public function ajax_resolve_flag() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $flag_id = intval($_POST['flag_id'] ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_quality_flags',
            array(
                'resolved' => 1,
                'resolved_by' => get_current_user_id(),
                'resolved_at' => current_time('mysql'),
                'resolution_notes' => $notes,
            ),
            array('id' => $flag_id)
        );
        
        wp_send_json_success(array('message' => 'Flag resolved'));
    }
    
    /**
     * Get active flags for trainer
     */
    public static function get_trainer_flags($trainer_id, $include_resolved = false) {
        global $wpdb;
        
        $where_resolved = $include_resolved ? '' : 'AND resolved = 0';
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_quality_flags
            WHERE trainer_id = %d
            $where_resolved
            ORDER BY created_at DESC
        ", $trainer_id));
    }
    
    /**
     * Get all active flags (for admin dashboard)
     */
    public static function get_all_active_flags($limit = 50) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT f.*, t.display_name as trainer_name, t.photo_url as trainer_photo
            FROM {$wpdb->prefix}ptp_quality_flags f
            JOIN {$wpdb->prefix}ptp_trainers t ON f.trainer_id = t.id
            WHERE f.resolved = 0
            ORDER BY 
                CASE f.severity 
                    WHEN 'critical' THEN 1 
                    WHEN 'serious' THEN 2 
                    ELSE 3 
                END,
                f.created_at DESC
            LIMIT %d
        ", $limit));
    }
    
    /**
     * ======================
     * TRAINER PAUSE/UNPAUSE
     * ======================
     */
    
    /**
     * Pause trainer profile
     */
    public function pause_trainer($trainer_id, $reason = '') {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'is_active' => 0,
                'paused_at' => current_time('mysql'),
                'pause_reason' => $reason,
            ),
            array('id' => $trainer_id)
        );
        
        // Cancel any pending bookings
        $pending_bookings = $wpdb->get_results($wpdb->prepare("
            SELECT id, payment_intent_id FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d AND status = 'confirmed' AND session_date > NOW()
        ", $trainer_id));
        
        foreach ($pending_bookings as $booking) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('status' => 'cancelled', 'cancel_reason' => 'Trainer paused: ' . $reason),
                array('id' => $booking->id)
            );
            
            // Refund if paid
            if ($booking->payment_intent_id && class_exists('PTP_Stripe')) {
                PTP_Stripe::create_refund($booking->payment_intent_id);
            }
        }
        
        // Notify trainer
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t
             JOIN {$wpdb->prefix}users u ON t.user_id = u.ID
             WHERE t.id = %d",
            $trainer_id
        ));
        
        if ($trainer && class_exists('PTP_Email')) {
            // Send email notification about pause
        }
        
        return true;
    }
    
    /**
     * Unpause trainer (admin action)
     */
    public function ajax_unpause_trainer() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'is_active' => 1,
                'paused_at' => null,
                'pause_reason' => null,
            ),
            array('id' => $trainer_id)
        );
        
        wp_send_json_success(array('message' => 'Trainer reactivated'));
    }
    
    public function ajax_pause_trainer() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $reason = sanitize_text_field($_POST['reason'] ?? 'Admin action');
        
        $this->pause_trainer($trainer_id, $reason);
        
        wp_send_json_success(array('message' => 'Trainer paused'));
    }
    
    /**
     * ======================
     * TRAINER METRICS
     * ======================
     */
    
    /**
     * Get trainer quality metrics
     */
    public static function get_trainer_metrics($trainer_id) {
        global $wpdb;
        
        // Total sessions
        $total_sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d AND status IN ('completed', 'confirmed', 'cancelled', 'no_show')
        ", $trainer_id));
        
        // Completed sessions
        $completed = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d AND status = 'completed'
        ", $trainer_id));
        
        // Cancelled by trainer
        $cancelled = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d AND status = 'cancelled' AND cancel_reason LIKE '%trainer%'
        ", $trainer_id));
        
        // No-shows
        $no_shows = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d AND status = 'no_show'
        ", $trainer_id));
        
        // Rating
        $rating = $wpdb->get_row($wpdb->prepare("
            SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
            FROM {$wpdb->prefix}ptp_reviews
            WHERE trainer_id = %d AND is_published = 1
        ", $trainer_id));
        
        // Sessions with notes
        $with_notes = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_session_notes
            WHERE trainer_id = %d
        ", $trainer_id));
        
        // Response time
        $avg_response = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(response_time_hours) FROM {$wpdb->prefix}ptp_response_tracking
            WHERE trainer_id = %d AND trainer_response_at IS NOT NULL
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ", $trainer_id));
        
        // Active flags
        $active_flags = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_quality_flags
            WHERE trainer_id = %d AND resolved = 0
        ", $trainer_id));
        
        // Completion rate
        $completion_rate = $total_sessions > 0 ? round(($completed / $total_sessions) * 100, 1) : 100;
        
        // Notes rate
        $notes_rate = $completed > 0 ? round(($with_notes / $completed) * 100, 1) : 0;
        
        return array(
            'total_sessions' => (int) $total_sessions,
            'completed_sessions' => (int) $completed,
            'cancelled_sessions' => (int) $cancelled,
            'no_shows' => (int) $no_shows,
            'avg_rating' => $rating->avg_rating ? round($rating->avg_rating, 2) : null,
            'total_reviews' => (int) $rating->total_reviews,
            'sessions_with_notes' => (int) $with_notes,
            'notes_rate' => $notes_rate,
            'avg_response_hours' => $avg_response ? round($avg_response, 1) : null,
            'completion_rate' => $completion_rate,
            'active_flags' => (int) $active_flags,
            'quality_score' => self::calculate_quality_score($trainer_id),
        );
    }
    
    /**
     * Calculate overall quality score (0-100)
     */
    public static function calculate_quality_score($trainer_id) {
        $metrics = array();
        global $wpdb;
        
        // Rating component (40%)
        $avg_rating = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(rating) FROM {$wpdb->prefix}ptp_reviews
            WHERE trainer_id = %d AND is_published = 1
        ", $trainer_id));
        $rating_score = $avg_rating ? min(100, ($avg_rating / 5) * 100) : 80; // Default 80 if no reviews
        
        // Completion rate (25%)
        $total = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d AND status IN ('completed', 'cancelled', 'no_show')
        ", $trainer_id));
        $completed = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND status = 'completed'
        ", $trainer_id));
        $completion_score = $total > 0 ? ($completed / $total) * 100 : 100;
        
        // Response time (20%)
        $avg_response = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(response_time_hours) FROM {$wpdb->prefix}ptp_response_tracking
            WHERE trainer_id = %d AND trainer_response_at IS NOT NULL
        ", $trainer_id));
        $response_score = 100;
        if ($avg_response) {
            if ($avg_response <= 2) $response_score = 100;
            elseif ($avg_response <= 6) $response_score = 90;
            elseif ($avg_response <= 12) $response_score = 75;
            elseif ($avg_response <= 24) $response_score = 50;
            else $response_score = 25;
        }
        
        // Notes rate (15%)
        $notes = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_session_notes WHERE trainer_id = %d
        ", $trainer_id));
        $notes_score = $completed > 0 ? min(100, ($notes / $completed) * 100) : 100;
        
        // Calculate weighted score
        $score = ($rating_score * 0.40) + ($completion_score * 0.25) + ($response_score * 0.20) + ($notes_score * 0.15);
        
        // Deduct for active flags
        $flags = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_quality_flags
            WHERE trainer_id = %d AND resolved = 0
        ", $trainer_id));
        $score -= ($flags * 5);
        
        return max(0, min(100, round($score)));
    }
    
    /**
     * ======================
     * ADMIN NOTIFICATIONS
     * ======================
     */
    
    /**
     * Notify admin of critical flag
     */
    private function notify_admin_flag($trainer_id, $flag_type, $trainer_name) {
        $admin_email = get_option('admin_email');
        
        $flag_labels = array(
            'no_show' => 'No-Show Reported',
            'multiple_low_ratings' => 'Multiple Low Ratings',
            'low_rating' => 'Low Rating',
            'low_average_rating' => 'Low Average Rating',
            'slow_response' => 'Slow Response Time',
            'missing_notes' => 'Missing Session Notes',
        );
        
        $subject = sprintf('[PTP Alert] %s - %s', $flag_labels[$flag_type] ?? $flag_type, $trainer_name);
        
        $message = sprintf(
            "Quality flag triggered for trainer: %s\n\n" .
            "Flag Type: %s\n" .
            "Trainer ID: %d\n\n" .
            "View details: %s",
            $trainer_name,
            $flag_labels[$flag_type] ?? $flag_type,
            $trainer_id,
            admin_url('admin.php?page=ptp-trainers&action=view&id=' . $trainer_id)
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Check for missing notes (cron)
     */
    public function check_missing_notes() {
        $trainers = self::get_trainers_missing_notes();
        
        foreach ($trainers as $trainer) {
            // Check if already flagged recently
            global $wpdb;
            $recent = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$wpdb->prefix}ptp_quality_flags
                WHERE trainer_id = %d AND flag_type = 'missing_notes'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ", $trainer->id));
            
            if (!$recent) {
                $this->create_flag(
                    $trainer->id,
                    'missing_notes',
                    'warning',
                    sprintf('%d sessions without notes in the last 30 days', $trainer->missing_count)
                );
                
                // Send warning email to trainer
                $user = get_user_by('ID', $trainer->user_id);
                if ($user) {
                    $subject = 'Action Required: Session Notes Missing';
                    $message = sprintf(
                        "Hi %s,\n\n" .
                        "You have %d training sessions without session notes.\n\n" .
                        "Session notes help parents see the value of training and track their child's progress. " .
                        "Please log into your dashboard and add notes for your recent sessions.\n\n" .
                        "Dashboard: %s\n\n" .
                        "Thank you,\nPTP Team",
                        $trainer->display_name,
                        $trainer->missing_count,
                        home_url('/trainer-dashboard/')
                    );
                    
                    wp_mail($user->user_email, $subject, $message);
                }
            }
        }
    }
    
    /**
     * ======================
     * SHORTCODES & UI
     * ======================
     */
    
    /**
     * Session notes form shortcode (for trainers)
     */
    public function shortcode_session_notes_form($atts) {
        $atts = shortcode_atts(array('booking_id' => 0), $atts);
        $booking_id = intval($atts['booking_id']);
        
        if (!$booking_id || !is_user_logged_in()) {
            return '';
        }
        
        global $wpdb;
        
        // Verify trainer access
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.id as trainer_id, p.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE b.id = %d AND t.user_id = %d",
            $booking_id,
            get_current_user_id()
        ));
        
        if (!$booking) {
            return '<p>Session not found.</p>';
        }
        
        $existing = self::get_session_notes($booking_id);
        
        ob_start();
        ?>
        <div class="ptp-session-notes-form" data-booking="<?php echo $booking_id; ?>">
            <style>
                .ptp-session-notes-form{font-family:Inter,-apple-system,sans-serif;max-width:600px}
                .ptp-session-notes-form h3{font-family:Oswald,sans-serif;text-transform:uppercase;margin:0 0 20px;font-size:20px}
                .ptp-notes-field{margin-bottom:20px}
                .ptp-notes-field label{display:block;font-weight:600;margin-bottom:8px;font-size:14px;text-transform:uppercase;letter-spacing:0.5px}
                .ptp-notes-field label span{color:#DC2626;margin-left:2px}
                .ptp-notes-field textarea,.ptp-notes-field input{width:100%;padding:12px;border:2px solid #E5E5E5;font-size:14px;font-family:inherit;transition:border-color 0.2s}
                .ptp-notes-field textarea:focus,.ptp-notes-field input:focus{border-color:#FCB900;outline:none}
                .ptp-notes-field textarea{min-height:100px;resize:vertical}
                .ptp-notes-field .ptp-field-hint{color:#6B7280;font-size:12px;margin-top:6px}
                .ptp-session-rating{display:flex;gap:8px;margin-top:8px}
                .ptp-session-rating label{display:flex;align-items:center;justify-content:center;width:44px;height:44px;border:2px solid #E5E5E5;cursor:pointer;font-weight:600;transition:all 0.2s}
                .ptp-session-rating input{display:none}
                .ptp-session-rating input:checked+span{background:#FCB900;border-color:#FCB900}
                .ptp-session-rating label:hover{border-color:#FCB900}
                .ptp-notes-submit{background:#0A0A0A;color:#fff;border:none;padding:14px 32px;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:1px;cursor:pointer;transition:background 0.2s}
                .ptp-notes-submit:hover{background:#FCB900;color:#0A0A0A}
                .ptp-notes-submit:disabled{background:#ccc;cursor:not-allowed}
                .ptp-notes-success{background:#D1FAE5;border:2px solid #10B981;padding:16px;color:#065F46;margin-bottom:20px}
            </style>
            
            <h3>Session Notes - <?php echo esc_html($booking->player_name ?: 'Player'); ?></h3>
            <p style="color:#6B7280;margin-bottom:24px;">
                <?php echo date('l, F j, Y', strtotime($booking->session_date)); ?> at 
                <?php echo date('g:i A', strtotime($booking->start_time)); ?>
            </p>
            
            <div class="ptp-notes-success" style="display:none;" id="notes-success">
                Session notes saved successfully!
            </div>
            
            <form id="session-notes-form">
                <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
                
                <div class="ptp-notes-field">
                    <label>Skills Worked On <span>*</span></label>
                    <textarea name="skills_worked" required placeholder="e.g., Dribbling with weak foot, 1v1 moves, shooting technique..."><?php echo esc_textarea($existing->skills_worked ?? ''); ?></textarea>
                    <div class="ptp-field-hint">What specific skills did you focus on during this session?</div>
                </div>
                
                <div class="ptp-notes-field">
                    <label>Progress Notes <span>*</span></label>
                    <textarea name="progress_notes" required placeholder="How did the player do? What improvements did you notice?"><?php echo esc_textarea($existing->progress_notes ?? ''); ?></textarea>
                    <div class="ptp-field-hint">Describe the player's performance and any progress observed</div>
                </div>
                
                <div class="ptp-notes-field">
                    <label>Homework / Practice Drills</label>
                    <textarea name="homework" placeholder="Drills or exercises the player should practice at home..."><?php echo esc_textarea($existing->homework ?? ''); ?></textarea>
                    <div class="ptp-field-hint">Optional: Give the player something to work on before next session</div>
                </div>
                
                <div class="ptp-notes-field">
                    <label>Focus for Next Session</label>
                    <input type="text" name="next_focus" value="<?php echo esc_attr($existing->next_focus ?? ''); ?>" placeholder="e.g., Continue weak foot work, introduce new moves...">
                </div>
                
                <div class="ptp-notes-field">
                    <label>How Was This Session? (1-5)</label>
                    <div class="ptp-session-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <label>
                            <input type="radio" name="session_rating" value="<?php echo $i; ?>" <?php checked($existing->session_rating ?? 0, $i); ?>>
                            <span style="display:flex;align-items:center;justify-content:center;width:40px;height:40px;border:2px solid #E5E5E5"><?php echo $i; ?></span>
                        </label>
                        <?php endfor; ?>
                    </div>
                    <div class="ptp-field-hint">Your internal rating for this session (not shown to parents)</div>
                </div>
                
                <button type="submit" class="ptp-notes-submit">Save Session Notes</button>
            </form>
            
            <script>
            document.getElementById('session-notes-form').addEventListener('submit', async function(e) {
                e.preventDefault();
                const btn = this.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.textContent = 'Saving...';
                
                const formData = new FormData(this);
                formData.append('action', 'ptp_save_session_notes');
                formData.append('nonce', '<?php echo wp_create_nonce('ptp_nonce'); ?>');
                
                try {
                    const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        document.getElementById('notes-success').style.display = 'block';
                        btn.textContent = 'Saved!';
                        setTimeout(() => { btn.textContent = 'Save Session Notes'; btn.disabled = false; }, 2000);
                    } else {
                        alert(data.data?.message || 'Error saving notes');
                        btn.disabled = false;
                        btn.textContent = 'Save Session Notes';
                    }
                } catch (err) {
                    alert('Error saving notes');
                    btn.disabled = false;
                    btn.textContent = 'Save Session Notes';
                }
            });
            </script>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Review form shortcode (for parents)
     */
    public function shortcode_review_form($atts) {
        $atts = shortcode_atts(array('booking_id' => 0), $atts);
        $booking_id = intval($atts['booking_id']);
        
        if (!$booking_id || !is_user_logged_in()) {
            return '';
        }
        
        global $wpdb;
        
        // Verify parent access
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
                    p.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE b.id = %d AND pa.user_id = %d AND b.status = 'completed'",
            $booking_id,
            get_current_user_id()
        ));
        
        if (!$booking) {
            return '<p>Session not found or not yet completed.</p>';
        }
        
        // Check if already reviewed
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_reviews WHERE booking_id = %d",
            $booking_id
        ));
        
        if ($existing) {
            return '<p>You have already reviewed this session. Thank you!</p>';
        }
        
        $trainer_photo = $booking->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($booking->trainer_name) . '&size=80&background=FCB900&color=0A0A0A&bold=true';
        
        ob_start();
        ?>
        <div class="ptp-review-form" data-booking="<?php echo $booking_id; ?>">
            <style>
                .ptp-review-form{font-family:Inter,-apple-system,sans-serif;max-width:500px;margin:0 auto}
                .ptp-review-header{text-align:center;margin-bottom:30px}
                .ptp-review-header img{width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #FCB900}
                .ptp-review-header h3{font-family:Oswald,sans-serif;margin:15px 0 5px;font-size:20px}
                .ptp-review-header p{color:#6B7280;margin:0}
                .ptp-star-rating{display:flex;justify-content:center;gap:8px;margin:24px 0}
                .ptp-star-rating label{cursor:pointer;font-size:36px;color:#E5E5E5;transition:color 0.2s}
                .ptp-star-rating label:hover,.ptp-star-rating label:hover~label,.ptp-star-rating input:checked~label{color:#FCB900}
                .ptp-star-rating{flex-direction:row-reverse;justify-content:center}
                .ptp-star-rating input{display:none}
                .ptp-review-field{margin-bottom:20px}
                .ptp-review-field label{display:block;font-weight:600;margin-bottom:8px}
                .ptp-review-field textarea{width:100%;padding:12px;border:2px solid #E5E5E5;min-height:100px;font-family:inherit;font-size:14px}
                .ptp-review-field textarea:focus{border-color:#FCB900;outline:none}
                .ptp-recommend{display:flex;gap:12px;margin:20px 0}
                .ptp-recommend label{flex:1;padding:12px;border:2px solid #E5E5E5;text-align:center;cursor:pointer;font-weight:600;transition:all 0.2s}
                .ptp-recommend input{display:none}
                .ptp-recommend input:checked+span{background:#D1FAE5;border-color:#10B981}
                .ptp-recommend label:has(input:checked){background:#D1FAE5;border-color:#10B981}
                .ptp-review-submit{width:100%;background:#0A0A0A;color:#fff;border:none;padding:16px;font-size:16px;font-weight:600;text-transform:uppercase;cursor:pointer;transition:background 0.2s}
                .ptp-review-submit:hover{background:#FCB900;color:#0A0A0A}
                .ptp-review-success{text-align:center;padding:40px;background:#D1FAE5;border:2px solid #10B981}
                .ptp-review-success h3{color:#065F46;margin:0 0 10px}
            </style>
            
            <div class="ptp-review-header">
                <img src="<?php echo esc_url($trainer_photo); ?>" alt="">
                <h3>Rate Your Session with <?php echo esc_html($booking->trainer_name); ?></h3>
                <p><?php echo esc_html($booking->player_name); ?> • <?php echo date('M j, Y', strtotime($booking->session_date)); ?></p>
            </div>
            
            <div class="ptp-review-success" style="display:none;" id="review-success">
                <h3>Thank You!</h3>
                <p>Your feedback helps other parents and motivates our trainers.</p>
            </div>
            
            <form id="review-form">
                <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
                
                <div class="ptp-star-rating">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                    <label>
                        <input type="radio" name="rating" value="<?php echo $i; ?>" required>
                        ★
                    </label>
                    <?php endfor; ?>
                </div>
                
                <div class="ptp-review-field">
                    <label>Tell us about your experience (optional)</label>
                    <textarea name="review" placeholder="What did your child enjoy? How was the trainer?"></textarea>
                </div>
                
                <div class="ptp-review-field">
                    <label>Would you recommend this trainer?</label>
                    <div class="ptp-recommend">
                        <label>
                            <input type="radio" name="would_recommend" value="1" checked>
                            <span>👍 Yes</span>
                        </label>
                        <label>
                            <input type="radio" name="would_recommend" value="0">
                            <span>👎 No</span>
                        </label>
                    </div>
                </div>
                
                <button type="submit" class="ptp-review-submit">Submit Review</button>
            </form>
            
            <script>
            document.getElementById('review-form').addEventListener('submit', async function(e) {
                e.preventDefault();
                const btn = this.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.textContent = 'Submitting...';
                
                const formData = new FormData(this);
                formData.append('action', 'ptp_submit_session_review');
                formData.append('nonce', '<?php echo wp_create_nonce('ptp_nonce'); ?>');
                
                try {
                    const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        this.style.display = 'none';
                        document.getElementById('review-success').style.display = 'block';
                    } else {
                        alert(data.data?.message || 'Error submitting review');
                        btn.disabled = false;
                        btn.textContent = 'Submit Review';
                    }
                } catch (err) {
                    alert('Error submitting review');
                    btn.disabled = false;
                    btn.textContent = 'Submit Review';
                }
            });
            </script>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Quality dashboard shortcode (for admin)
     */
    public function shortcode_quality_dashboard($atts) {
        if (!current_user_can('manage_options')) {
            return '<p>Access denied.</p>';
        }
        
        ob_start();
        $this->render_quality_dashboard();
        return ob_get_clean();
    }
    
    /**
     * Render admin quality dashboard
     */
    public function render_quality_dashboard() {
        global $wpdb;
        
        // Get active flags
        $flags = self::get_all_active_flags();
        
        // Get trainers with issues
        $problem_trainers = $wpdb->get_results("
            SELECT t.*, 
                   COUNT(DISTINCT f.id) as flag_count,
                   AVG(r.rating) as avg_rating,
                   (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = t.id AND status = 'no_show') as no_shows
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->prefix}ptp_quality_flags f ON f.trainer_id = t.id AND f.resolved = 0
            LEFT JOIN {$wpdb->prefix}ptp_reviews r ON r.trainer_id = t.id
            GROUP BY t.id
            HAVING flag_count > 0 OR avg_rating < 4.0 OR no_shows > 0
            ORDER BY flag_count DESC, avg_rating ASC
            LIMIT 20
        ");
        
        // Stats
        $total_flags = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_quality_flags WHERE resolved = 0");
        $paused_trainers = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE is_active = 0 AND paused_at IS NOT NULL");
        $low_rated = $wpdb->get_var("
            SELECT COUNT(DISTINCT trainer_id) FROM {$wpdb->prefix}ptp_reviews
            GROUP BY trainer_id HAVING AVG(rating) < 4.0
        ");
        ?>
        <div class="ptp-quality-dashboard">
            <style>
                .ptp-quality-dashboard{font-family:Inter,-apple-system,sans-serif}
                .ptp-qd-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:30px}
                .ptp-qd-stat{background:#fff;border:2px solid #E5E5E5;padding:20px;text-align:center}
                .ptp-qd-stat-value{font-size:36px;font-weight:700;font-family:Oswald,sans-serif}
                .ptp-qd-stat-label{color:#6B7280;font-size:13px;text-transform:uppercase;letter-spacing:0.5px;margin-top:5px}
                .ptp-qd-stat.critical .ptp-qd-stat-value{color:#DC2626}
                .ptp-qd-stat.warning .ptp-qd-stat-value{color:#F59E0B}
                .ptp-qd-section{background:#fff;border:2px solid #E5E5E5;margin-bottom:20px}
                .ptp-qd-section-header{background:#0A0A0A;color:#fff;padding:15px 20px;font-family:Oswald,sans-serif;text-transform:uppercase;font-size:14px;letter-spacing:1px}
                .ptp-qd-section-body{padding:0}
                .ptp-qd-flag{display:flex;align-items:center;gap:15px;padding:15px 20px;border-bottom:1px solid #E5E5E5}
                .ptp-qd-flag:last-child{border-bottom:none}
                .ptp-qd-flag-severity{width:8px;height:8px;border-radius:50%;flex-shrink:0}
                .ptp-qd-flag-severity.critical{background:#DC2626}
                .ptp-qd-flag-severity.serious{background:#F59E0B}
                .ptp-qd-flag-severity.warning{background:#6B7280}
                .ptp-qd-flag-photo{width:40px;height:40px;border-radius:50%;object-fit:cover}
                .ptp-qd-flag-info{flex:1}
                .ptp-qd-flag-trainer{font-weight:600}
                .ptp-qd-flag-type{font-size:13px;color:#6B7280}
                .ptp-qd-flag-details{font-size:12px;color:#9CA3AF;margin-top:4px}
                .ptp-qd-flag-actions{display:flex;gap:8px}
                .ptp-qd-btn{padding:6px 12px;border:2px solid;font-size:12px;font-weight:600;text-transform:uppercase;cursor:pointer;transition:all 0.2s}
                .ptp-qd-btn-resolve{background:#fff;border-color:#10B981;color:#10B981}
                .ptp-qd-btn-resolve:hover{background:#10B981;color:#fff}
                .ptp-qd-btn-view{background:#fff;border-color:#0A0A0A;color:#0A0A0A}
                .ptp-qd-btn-view:hover{background:#0A0A0A;color:#fff}
                .ptp-qd-empty{padding:40px;text-align:center;color:#6B7280}
            </style>
            
            <div class="ptp-qd-stats">
                <div class="ptp-qd-stat <?php echo $total_flags > 0 ? 'critical' : ''; ?>">
                    <div class="ptp-qd-stat-value"><?php echo $total_flags; ?></div>
                    <div class="ptp-qd-stat-label">Active Flags</div>
                </div>
                <div class="ptp-qd-stat <?php echo $paused_trainers > 0 ? 'warning' : ''; ?>">
                    <div class="ptp-qd-stat-value"><?php echo $paused_trainers; ?></div>
                    <div class="ptp-qd-stat-label">Paused Trainers</div>
                </div>
                <div class="ptp-qd-stat">
                    <div class="ptp-qd-stat-value"><?php echo $low_rated ?: 0; ?></div>
                    <div class="ptp-qd-stat-label">Below 4.0 Rating</div>
                </div>
                <div class="ptp-qd-stat">
                    <div class="ptp-qd-stat-value"><?php echo count($problem_trainers); ?></div>
                    <div class="ptp-qd-stat-label">Need Attention</div>
                </div>
            </div>
            
            <div class="ptp-qd-section">
                <div class="ptp-qd-section-header">Active Quality Flags</div>
                <div class="ptp-qd-section-body">
                    <?php if (empty($flags)): ?>
                    <div class="ptp-qd-empty">No active flags - all trainers are performing well!</div>
                    <?php else: ?>
                        <?php foreach ($flags as $flag): 
                            $photo = $flag->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($flag->trainer_name) . '&size=80&background=FCB900&color=0A0A0A&bold=true';
                            $flag_labels = array(
                                'no_show' => 'No-Show',
                                'multiple_low_ratings' => 'Multiple Low Ratings',
                                'low_rating' => 'Low Rating',
                                'low_average_rating' => 'Low Average',
                                'slow_response' => 'Slow Response',
                                'missing_notes' => 'Missing Notes',
                            );
                        ?>
                        <div class="ptp-qd-flag" data-flag-id="<?php echo $flag->id; ?>">
                            <div class="ptp-qd-flag-severity <?php echo $flag->severity; ?>"></div>
                            <img src="<?php echo esc_url($photo); ?>" class="ptp-qd-flag-photo" alt="">
                            <div class="ptp-qd-flag-info">
                                <div class="ptp-qd-flag-trainer"><?php echo esc_html($flag->trainer_name); ?></div>
                                <div class="ptp-qd-flag-type"><?php echo $flag_labels[$flag->flag_type] ?? $flag->flag_type; ?></div>
                                <div class="ptp-qd-flag-details"><?php echo esc_html($flag->details); ?></div>
                            </div>
                            <div class="ptp-qd-flag-actions">
                                <button class="ptp-qd-btn ptp-qd-btn-view" onclick="location.href='<?php echo admin_url('admin.php?page=ptp-trainers&action=view&id=' . $flag->trainer_id); ?>'">View</button>
                                <button class="ptp-qd-btn ptp-qd-btn-resolve" onclick="resolveFlag(<?php echo $flag->id; ?>)">Resolve</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <script>
        async function resolveFlag(flagId) {
            const notes = prompt('Resolution notes (optional):');
            if (notes === null) return;
            
            const formData = new FormData();
            formData.append('action', 'ptp_resolve_flag');
            formData.append('nonce', '<?php echo wp_create_nonce('ptp_nonce'); ?>');
            formData.append('flag_id', flagId);
            formData.append('notes', notes);
            
            const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (data.success) {
                document.querySelector(`[data-flag-id="${flagId}"]`).remove();
            } else {
                alert('Error resolving flag');
            }
        }
        </script>
        <?php
    }
}

// Initialize
PTP_Quality_Control::instance();
