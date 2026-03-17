<?php
/**
 * PTP Happy Student Score System
 * Algorithmic rating (0-100) based on:
 * - Reliability (shows up on time, doesn't cancel)
 * - Responsiveness (avg time to confirm bookings)
 * - Return Rate (% of students who rebook)
 * 
 * Also handles SuperCoach badge assignment
 * 
 * Version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_Happy_Score {
    
    // Score weights
    const WEIGHT_RELIABILITY = 0.35;
    const WEIGHT_RESPONSIVENESS = 0.25;
    const WEIGHT_RETURN_RATE = 0.25;
    const WEIGHT_RATING = 0.15;
    
    // SuperCoach thresholds
    const SUPERCOACH_MIN_SCORE = 85;
    const SUPERCOACH_MIN_SESSIONS = 25;
    const SUPERCOACH_MIN_RATING = 4.5;
    
    public static function init() {
        // Recalculate scores after key events
        add_action('ptp_booking_completed', array(__CLASS__, 'recalculate_trainer_score'), 10, 1);
        add_action('ptp_booking_confirmed', array(__CLASS__, 'track_response_time'), 10, 2);
        add_action('ptp_booking_cancelled', array(__CLASS__, 'track_cancellation'), 10, 2);
        add_action('ptp_review_submitted', array(__CLASS__, 'recalculate_trainer_score'), 10, 1);
        
        // Daily cron to recalculate all scores
        add_action('ptp_daily_score_recalculation', array(__CLASS__, 'recalculate_all_scores'));
        
        if (!wp_next_scheduled('ptp_daily_score_recalculation')) {
            wp_schedule_event(strtotime('03:00:00'), 'daily', 'ptp_daily_score_recalculation');
        }
        
        // AJAX endpoints
        add_action('wp_ajax_ptp_get_trainer_score_breakdown', array(__CLASS__, 'ajax_get_score_breakdown'));
    }
    
    /**
     * Calculate Happy Student Score for a trainer
     * Returns score 0-100
     */
    public static function calculate_score($trainer_id) {
        $reliability = self::calculate_reliability_score($trainer_id);
        $responsiveness = self::calculate_responsiveness_score($trainer_id);
        $return_rate = self::calculate_return_rate($trainer_id);
        $rating_score = self::calculate_rating_score($trainer_id);
        
        $total_score = round(
            ($reliability * self::WEIGHT_RELIABILITY) +
            ($responsiveness * self::WEIGHT_RESPONSIVENESS) +
            ($return_rate * self::WEIGHT_RETURN_RATE) +
            ($rating_score * self::WEIGHT_RATING)
        );
        
        // Ensure score is 0-100
        $total_score = max(0, min(100, $total_score));
        
        // Update trainer record
        self::update_trainer_scores($trainer_id, array(
            'happy_student_score' => $total_score,
            'reliability_score' => $reliability,
            'responsiveness_score' => $responsiveness,
            'return_rate' => $return_rate,
        ));
        
        // Check for SuperCoach eligibility
        self::check_supercoach_eligibility($trainer_id, $total_score);
        
        return $total_score;
    }
    
    /**
     * Reliability Score (0-100)
     * Based on: cancellation rate, no-show rate, on-time completion
     */
    public static function calculate_reliability_score($trainer_id) {
        global $wpdb;
        
        // Get last 90 days of bookings
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' AND cancelled_by = 'trainer' THEN 1 ELSE 0 END) as trainer_cancelled,
                SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_shows
            FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d
            AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        ", $trainer_id));
        
        if (!$stats || $stats->total_bookings == 0) {
            return 100; // New trainers start with perfect score
        }
        
        $total = $stats->total_bookings;
        $completed = $stats->completed;
        $trainer_cancelled = $stats->trainer_cancelled;
        $no_shows = $stats->no_shows;
        
        // Completion rate (main factor)
        $completion_rate = ($completed / $total) * 100;
        
        // Penalties
        $cancellation_penalty = ($trainer_cancelled / $total) * 30; // Up to 30 points off
        $no_show_penalty = ($no_shows / $total) * 50; // Up to 50 points off (severe)
        
        $score = $completion_rate - $cancellation_penalty - $no_show_penalty;
        
        return max(0, min(100, round($score)));
    }
    
    /**
     * Responsiveness Score (0-100)
     * Based on: average time to confirm/respond to booking requests
     */
    public static function calculate_responsiveness_score($trainer_id) {
        global $wpdb;
        
        // Get average confirmation time (in hours) for last 30 days
        $avg_response = $wpdb->get_var($wpdb->prepare("
            SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, confirmed_at))
            FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d
            AND confirmed_at IS NOT NULL
            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ", $trainer_id));
        
        if ($avg_response === null) {
            return 100; // No data = perfect score
        }
        
        $avg_hours = floatval($avg_response);
        
        // Scoring: 
        // < 1 hour = 100
        // 1-4 hours = 90-100
        // 4-12 hours = 70-90
        // 12-24 hours = 50-70
        // > 24 hours = 0-50
        
        if ($avg_hours < 1) {
            return 100;
        } elseif ($avg_hours <= 4) {
            return round(100 - (($avg_hours - 1) * 3.33));
        } elseif ($avg_hours <= 12) {
            return round(90 - (($avg_hours - 4) * 2.5));
        } elseif ($avg_hours <= 24) {
            return round(70 - (($avg_hours - 12) * 1.67));
        } else {
            return max(0, round(50 - (($avg_hours - 24) * 2)));
        }
    }
    
    /**
     * Return Rate (0-100)
     * Percentage of students who book more than one session
     */
    public static function calculate_return_rate($trainer_id) {
        global $wpdb;
        
        // Count unique parents
        $total_parents = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT parent_id)
            FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d
            AND status IN ('completed', 'confirmed')
        ", $trainer_id));
        
        if (!$total_parents || $total_parents == 0) {
            return 100;
        }
        
        // Count parents with 2+ bookings
        $returning_parents = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM (
                SELECT parent_id, COUNT(*) as booking_count
                FROM {$wpdb->prefix}ptp_bookings
                WHERE trainer_id = %d
                AND status IN ('completed', 'confirmed')
                GROUP BY parent_id
                HAVING booking_count >= 2
            ) as returning
        ", $trainer_id));
        
        return round(($returning_parents / $total_parents) * 100);
    }
    
    /**
     * Rating Score (0-100)
     * Convert 5-star rating to 0-100 scale
     */
    public static function calculate_rating_score($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT average_rating, review_count FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer || $trainer->review_count == 0) {
            return 80; // Default score for new trainers
        }
        
        // Convert 1-5 star to 0-100
        // 5.0 = 100, 4.0 = 80, 3.0 = 60, etc.
        return round(($trainer->average_rating / 5) * 100);
    }
    
    /**
     * Update trainer score columns
     */
    private static function update_trainer_scores($trainer_id, $scores) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            $scores,
            array('id' => $trainer_id),
            array('%d', '%d', '%d', '%d'),
            array('%d')
        );
        
        // Clear cache
        wp_cache_delete('ptp_trainer_' . $trainer_id, 'ptp');
    }
    
    /**
     * Check and update SuperCoach status
     */
    public static function check_supercoach_eligibility($trainer_id, $happy_score = null) {
        global $wpdb;
        
        if ($happy_score === null) {
            $happy_score = self::calculate_score($trainer_id);
        }
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT total_sessions, average_rating, is_supercoach FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return false;
        
        $qualifies = (
            $happy_score >= self::SUPERCOACH_MIN_SCORE &&
            $trainer->total_sessions >= self::SUPERCOACH_MIN_SESSIONS &&
            $trainer->average_rating >= self::SUPERCOACH_MIN_RATING
        );
        
        // Update if status changed
        if ($qualifies && !$trainer->is_supercoach) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                array(
                    'is_supercoach' => 1,
                    'supercoach_awarded_at' => current_time('mysql')
                ),
                array('id' => $trainer_id)
            );
            
            // Send congratulations notification
            do_action('ptp_supercoach_awarded', $trainer_id);
            
            return true;
        }
        
        // Note: We don't automatically remove SuperCoach status
        // That should be a manual admin decision
        
        return $qualifies;
    }
    
    /**
     * Track booking confirmation response time
     */
    public static function track_response_time($booking_id, $trainer_id) {
        // Score will be recalculated on next cron run or booking completion
        // This hook is for potential real-time tracking later
    }
    
    /**
     * Track cancellation for reliability
     */
    public static function track_cancellation($booking_id, $cancelled_by) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT trainer_id FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if ($booking && $cancelled_by === 'trainer') {
            // Immediately recalculate reliability
            self::calculate_reliability_score($booking->trainer_id);
        }
    }
    
    /**
     * Recalculate score after booking completion
     */
    public static function recalculate_trainer_score($booking_or_review_id) {
        global $wpdb;
        
        // Try to get trainer_id from booking or review
        $trainer_id = $wpdb->get_var($wpdb->prepare("
            SELECT trainer_id FROM {$wpdb->prefix}ptp_bookings WHERE id = %d
            UNION
            SELECT trainer_id FROM {$wpdb->prefix}ptp_reviews WHERE id = %d
            LIMIT 1
        ", $booking_or_review_id, $booking_or_review_id));
        
        if ($trainer_id) {
            self::calculate_score($trainer_id);
        }
    }
    
    /**
     * Recalculate all trainer scores (cron job)
     */
    public static function recalculate_all_scores() {
        global $wpdb;
        
        $trainer_ids = $wpdb->get_col("
            SELECT id FROM {$wpdb->prefix}ptp_trainers 
            WHERE status = 'active'
        ");
        
        foreach ($trainer_ids as $trainer_id) {
            self::calculate_score($trainer_id);
        }
        
        ptp_log('PTP: Recalculated Happy Student Scores for ' . count($trainer_ids) . ' trainers');
    }
    
    /**
     * Get score breakdown for display
     */
    public static function get_score_breakdown($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare("
            SELECT happy_student_score, reliability_score, responsiveness_score, 
                   return_rate, average_rating, review_count, total_sessions,
                   is_supercoach, supercoach_awarded_at
            FROM {$wpdb->prefix}ptp_trainers 
            WHERE id = %d
        ", $trainer_id));
        
        if (!$trainer) {
            return null;
        }
        
        return array(
            'happy_score' => intval($trainer->happy_student_score),
            'reliability' => intval($trainer->reliability_score),
            'responsiveness' => intval($trainer->responsiveness_score),
            'return_rate' => intval($trainer->return_rate),
            'rating' => floatval($trainer->average_rating),
            'review_count' => intval($trainer->review_count),
            'total_sessions' => intval($trainer->total_sessions),
            'is_supercoach' => (bool) $trainer->is_supercoach,
            'supercoach_since' => $trainer->supercoach_awarded_at,
            'weights' => array(
                'reliability' => self::WEIGHT_RELIABILITY,
                'responsiveness' => self::WEIGHT_RESPONSIVENESS,
                'return_rate' => self::WEIGHT_RETURN_RATE,
                'rating' => self::WEIGHT_RATING,
            ),
        );
    }
    
    /**
     * AJAX: Get trainer score breakdown
     */
    public static function ajax_get_score_breakdown() {
        $trainer_id = intval($_GET['trainer_id'] ?? 0);
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer ID'));
        }
        
        $breakdown = self::get_score_breakdown($trainer_id);
        
        if (!$breakdown) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        wp_send_json_success($breakdown);
    }
    
    /**
     * Render Happy Score badge HTML
     */
    public static function render_score_badge($trainer_id, $size = 'medium') {
        $breakdown = self::get_score_breakdown($trainer_id);
        
        if (!$breakdown) return '';
        
        $score = $breakdown['happy_score'];
        $is_supercoach = $breakdown['is_supercoach'];
        
        // Determine color
        if ($score >= 90) {
            $color = '#22C55E'; // Green
        } elseif ($score >= 70) {
            $color = '#FCB900'; // Gold
        } elseif ($score >= 50) {
            $color = '#F59E0B'; // Orange
        } else {
            $color = '#EF4444'; // Red
        }
        
        $sizes = array(
            'small' => array('width' => 40, 'font' => 12),
            'medium' => array('width' => 56, 'font' => 16),
            'large' => array('width' => 72, 'font' => 20),
        );
        
        $s = $sizes[$size] ?? $sizes['medium'];
        
        ob_start();
        ?>
        <div class="ptp-happy-score" style="display:inline-flex;align-items:center;gap:8px;">
            <div style="
                width:<?php echo $s['width']; ?>px;
                height:<?php echo $s['width']; ?>px;
                border-radius:50%;
                background:<?php echo $color; ?>;
                display:flex;
                align-items:center;
                justify-content:center;
                font-family:'Inter',sans-serif;
                font-weight:700;
                font-size:<?php echo $s['font']; ?>px;
                color:#fff;
            "><?php echo $score; ?></div>
            <?php if ($is_supercoach): ?>
            <div style="
                background:linear-gradient(135deg,#FCB900,#F59E0B);
                color:#0A0A0A;
                padding:4px 8px;
                font-size:11px;
                font-weight:700;
                text-transform:uppercase;
                letter-spacing:0.5px;
            ">⭐ SUPERCOACH</div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize
PTP_Happy_Score::init();
