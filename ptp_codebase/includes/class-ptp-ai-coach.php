<?php
/**
 * PTP AI Coach — Claude-powered trainer insights
 * 
 * Adds an "AI Coach" card to the trainer dashboard insights tab.
 * Sends trainer performance data to Claude API and returns
 * actionable recommendations for improving bookings, earnings, and retention.
 * 
 * @version 222.1.0
 */

defined('ABSPATH') || exit;

class PTP_AI_Coach {

    private static $api_key = null;

    public static function init() {
        add_action('wp_ajax_ptp_ai_coach_insights', array(__CLASS__, 'ajax_get_insights'));
    }

    /**
     * Get Anthropic API key from options
     */
    private static function get_api_key() {
        if (self::$api_key) return self::$api_key;
        
        self::$api_key = get_option('ptp_anthropic_api_key', '');
        
        // Fallback to constant if defined
        if (empty(self::$api_key) && defined('PTP_ANTHROPIC_API_KEY')) {
            self::$api_key = PTP_ANTHROPIC_API_KEY;
        }
        
        return self::$api_key;
    }

    /**
     * AJAX handler: Get AI coaching insights for trainer
     */
    public static function ajax_get_insights() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error('Not logged in');
        }
        
        // Get trainer ID
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", $user_id
        ));
        
        if (!$trainer) {
            wp_send_json_error('Trainer not found');
        }
        
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            wp_send_json_error('AI Coach not configured');
        }
        
        // Gather trainer data
        $data = self::gather_trainer_data($trainer);
        
        // Build prompt
        $prompt = self::build_prompt($data);
        
        // Call Claude API
        $response = self::call_claude($api_key, $prompt);
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        // Cache for 6 hours (don't spam API)
        set_transient('ptp_ai_coach_' . $trainer->id, $response, 6 * HOUR_IN_SECONDS);
        
        wp_send_json_success(array(
            'insights' => $response,
            'generated_at' => current_time('c'),
        ));
    }

    /**
     * Gather all relevant trainer data for analysis
     */
    private static function gather_trainer_data($trainer) {
        global $wpdb;
        $tid = $trainer->id;
        $prefix = $wpdb->prefix;
        
        // ---- Earnings ----
        $earnings_30d = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", $tid
        )));
        $earnings_prev_30d = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' 
             AND session_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) 
             AND session_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)", $tid
        )));
        $pending_payout = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'", $tid
        )));
        
        // ---- Bookings ----
        $sessions_30d = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", $tid
        )));
        $cancelled_30d = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'cancelled' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", $tid
        )));
        $upcoming = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date > CURDATE() AND status IN ('confirmed','pending')", $tid
        )));
        $unique_clients_30d = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT parent_id) FROM {$prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", $tid
        )));
        $repeat_clients = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT parent_id) FROM {$prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed'
             AND parent_id IN (
                 SELECT parent_id FROM {$prefix}ptp_bookings 
                 WHERE trainer_id = %d AND status = 'completed'
                 GROUP BY parent_id HAVING COUNT(*) > 1
             )", $tid, $tid
        )));
        
        // ---- Recaps ----
        $sessions_needing_recaps = intval($wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$prefix}ptp_bookings b
            LEFT JOIN {$prefix}ptp_session_notes sn ON b.id = sn.booking_id
            WHERE b.trainer_id = %d AND b.status = 'completed' 
            AND b.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND sn.id IS NULL
        ", $tid)));
        $total_recaps_sent = intval($wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$prefix}ptp_session_notes sn
            JOIN {$prefix}ptp_bookings b ON sn.booking_id = b.id
            WHERE b.trainer_id = %d
        ", $tid)));
        
        // ---- Reviews ----
        $avg_rating = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(AVG(rating), 0) FROM {$prefix}ptp_reviews 
             WHERE trainer_id = %d AND rating > 0", $tid
        )));
        $review_count = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}ptp_reviews WHERE trainer_id = %d AND rating > 0", $tid
        )));
        $unreplied_reviews = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}ptp_reviews 
             WHERE trainer_id = %d AND rating > 0 
             AND (trainer_reply IS NULL OR trainer_reply = '') 
             AND (trainer_response IS NULL OR trainer_response = '')", $tid
        )));
        
        // ---- Profile ----
        $profile_views = intval(get_user_meta($trainer->user_id, 'ptp_profile_views_30d', true));
        $has_photo = !empty($trainer->photo_url);
        $has_bio = !empty($trainer->bio) && strlen($trainer->bio) > 50;
        $has_video = !empty($trainer->video_url);
        $location_count = 0;
        if (!empty($trainer->locations)) {
            $locs = json_decode($trainer->locations, true);
            $location_count = is_array($locs) ? count($locs) : 0;
        }
        
        // ---- Availability ----
        $avail_slots = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}ptp_availability 
             WHERE trainer_id = %d AND date >= CURDATE() AND date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)", $tid
        )));
        
        // ---- Clients at risk (booked before but not in last 21 days) ----
        $at_risk_clients = $wpdb->get_results($wpdb->prepare("
            SELECT pa.display_name AS name, MAX(b.session_date) AS last_session, COUNT(*) AS total_sessions
            FROM {$prefix}ptp_bookings b
            JOIN {$prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.trainer_id = %d AND b.status = 'completed'
            GROUP BY b.parent_id
            HAVING last_session < DATE_SUB(CURDATE(), INTERVAL 21 DAY)
            AND last_session >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            ORDER BY last_session DESC
            LIMIT 5
        ", $tid));
        
        $at_risk = array();
        foreach ($at_risk_clients as $c) {
            $days_ago = floor((time() - strtotime($c->last_session)) / 86400);
            $at_risk[] = array(
                'name' => $c->name,
                'last_session_days_ago' => $days_ago,
                'total_sessions' => intval($c->total_sessions),
            );
        }
        
        return array(
            'trainer_name' => $trainer->display_name,
            'joined' => $trainer->created_at ?? '',
            'earnings_30d' => $earnings_30d,
            'earnings_prev_30d' => $earnings_prev_30d,
            'pending_payout' => $pending_payout,
            'sessions_30d' => $sessions_30d,
            'cancelled_30d' => $cancelled_30d,
            'upcoming_sessions' => $upcoming,
            'unique_clients_30d' => $unique_clients_30d,
            'repeat_clients_total' => $repeat_clients,
            'missing_recaps_30d' => $sessions_needing_recaps,
            'total_recaps_sent' => $total_recaps_sent,
            'avg_rating' => round($avg_rating, 1),
            'review_count' => $review_count,
            'unreplied_reviews' => $unreplied_reviews,
            'profile_views_30d' => $profile_views,
            'has_photo' => $has_photo,
            'has_bio' => $has_bio,
            'has_video' => $has_video,
            'location_count' => $location_count,
            'availability_next_14d' => $avail_slots,
            'at_risk_clients' => $at_risk,
        );
    }

    /**
     * Build the Claude prompt
     */
    private static function build_prompt($data) {
        $at_risk_text = '';
        if (!empty($data['at_risk_clients'])) {
            $at_risk_text = "\n\nClients at risk of churning (haven't booked in 21+ days):\n";
            foreach ($data['at_risk_clients'] as $c) {
                $at_risk_text .= "- {$c['name']}: last session {$c['last_session_days_ago']} days ago, {$c['total_sessions']} total sessions\n";
            }
        }
        
        $profile_gaps = array();
        if (!$data['has_photo']) $profile_gaps[] = 'No profile photo';
        if (!$data['has_bio']) $profile_gaps[] = 'Bio missing or very short';
        if (!$data['has_video']) $profile_gaps[] = 'No intro video';
        if ($data['location_count'] < 2) $profile_gaps[] = 'Only ' . $data['location_count'] . ' training location(s)';
        $profile_text = !empty($profile_gaps) ? "\nProfile gaps: " . implode(', ', $profile_gaps) : "\nProfile: Complete (photo, bio, video, multiple locations)";
        
        $earnings_change = $data['earnings_prev_30d'] > 0 
            ? round((($data['earnings_30d'] - $data['earnings_prev_30d']) / $data['earnings_prev_30d']) * 100)
            : ($data['earnings_30d'] > 0 ? 100 : 0);
        
        $completion_rate = ($data['sessions_30d'] + $data['cancelled_30d']) > 0
            ? round(($data['sessions_30d'] / ($data['sessions_30d'] + $data['cancelled_30d'])) * 100)
            : 100;
        
        $recap_rate = $data['sessions_30d'] > 0
            ? round((($data['sessions_30d'] - $data['missing_recaps_30d']) / $data['sessions_30d']) * 100)
            : 0;

        return "You are the AI Coach for PTP Soccer, a youth soccer training platform where parents book 1-on-1 sessions with D1 college and MLS coaches. Analyze this trainer's dashboard data and give exactly 3-4 specific, actionable coaching tips. Be direct and concise. Each tip should have a bold title (1-4 words) and 1-2 sentences of advice. Focus on what will increase their bookings and earnings most.

TRAINER: {$data['trainer_name']}

LAST 30 DAYS:
- Earnings: \${$data['earnings_30d']} ({$earnings_change}% vs prev 30d)
- Sessions completed: {$data['sessions_30d']}
- Cancelled: {$data['cancelled_30d']} (completion rate: {$completion_rate}%)
- Unique clients: {$data['unique_clients_30d']}
- Repeat clients (all time): {$data['repeat_clients_total']}
- Upcoming booked: {$data['upcoming_sessions']}
- Pending payout: \${$data['pending_payout']}

RECAPS & REVIEWS:
- Recap rate: {$recap_rate}% ({$data['missing_recaps_30d']} sessions missing recaps in last 30d)
- Rating: {$data['avg_rating']}/5 ({$data['review_count']} reviews)
- Unreplied reviews: {$data['unreplied_reviews']}
{$profile_text}

AVAILABILITY: {$data['availability_next_14d']} slots in next 14 days
{$at_risk_text}
Respond with a JSON array of objects: [{\"title\": \"...\", \"body\": \"...\", \"priority\": \"high|medium|low\", \"icon\": \"emoji\"}]
Only return the JSON array, nothing else.";
    }

    /**
     * Call Claude API
     */
    private static function call_claude($api_key, $prompt) {
        // Check cache first
        $cache_key = 'ptp_ai_coach_' . md5($prompt);
        $cached = get_transient($cache_key);
        if ($cached) return $cached;
        
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body' => wp_json_encode(array(
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 600,
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt),
                ),
            )),
        ));
        
        if (is_wp_error($response)) {
            ptp_log('[PTP AI Coach] API error: ' . $response->get_error_message());
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code !== 200) {
            ptp_log('[PTP AI Coach] API returned ' . $code . ': ' . $body);
            return new \WP_Error('api_error', 'AI Coach temporarily unavailable');
        }
        
        $decoded = json_decode($body, true);
        $text = $decoded['content'][0]['text'] ?? '';
        
        // Parse JSON from response
        $insights = json_decode($text, true);
        if (!is_array($insights)) {
            // Try to extract JSON from markdown code block
            if (preg_match('/\[.*\]/s', $text, $matches)) {
                $insights = json_decode($matches[0], true);
            }
        }
        
        if (!is_array($insights)) {
            ptp_log('[PTP AI Coach] Could not parse response: ' . $text);
            return new \WP_Error('parse_error', 'Could not parse AI response');
        }
        
        // Cache for 6 hours
        set_transient($cache_key, $insights, 6 * HOUR_IN_SECONDS);
        
        return $insights;
    }
}

add_action('init', array('PTP_AI_Coach', 'init'));
