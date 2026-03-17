<?php
/**
 * PTP Push Notifications
 * Firebase Cloud Messaging (FCM) integration for mobile app notifications
 */

defined('ABSPATH') || exit;

class PTP_Push_Notifications {
    
    public static function init() {
        // Hook into key events to send push notifications
        add_action('ptp_booking_created', array(__CLASS__, 'on_booking_created'), 10, 2);
        add_action('ptp_booking_confirmed', array(__CLASS__, 'on_booking_confirmed'), 10, 1);
        add_action('ptp_booking_cancelled', array(__CLASS__, 'on_booking_cancelled'), 10, 2);
        add_action('ptp_booking_completed', array(__CLASS__, 'on_booking_completed'), 10, 1);
        add_action('ptp_message_sent', array(__CLASS__, 'on_message_sent'), 10, 3);
        add_action('ptp_group_session_joined', array(__CLASS__, 'on_group_joined'), 10, 2);
        add_action('ptp_review_submitted', array(__CLASS__, 'on_review_submitted'), 10, 2);
        add_action('ptp_session_reminder', array(__CLASS__, 'on_session_reminder'), 10, 1);
    }
    
    /**
     * Get FCM server key from settings
     */
    private static function get_fcm_key() {
        return get_option('ptp_fcm_server_key', '');
    }
    
    /**
     * Get user's FCM tokens
     */
    public static function get_user_tokens($user_id) {
        global $wpdb;
        
        return $wpdb->get_col($wpdb->prepare(
            "SELECT token FROM {$wpdb->prefix}ptp_fcm_tokens 
             WHERE user_id = %d AND is_active = 1 AND updated_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $user_id
        ));
    }
    
    /**
     * Send push notification
     */
    public static function send($user_id, $title, $body, $data = array()) {
        $fcm_key = self::get_fcm_key();
        
        if (empty($fcm_key)) {
            // Log for debugging but don't fail
            ptp_log('PTP: FCM server key not configured');
            return false;
        }
        
        $tokens = self::get_user_tokens($user_id);
        
        if (empty($tokens)) {
            return false; // User has no registered devices
        }
        
        // Store notification in database
        self::store_notification($user_id, $title, $body, $data);
        
        // Send to FCM
        $payload = array(
            'registration_ids' => $tokens,
            'notification' => array(
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'badge' => self::get_unread_count($user_id),
            ),
            'data' => array_merge($data, array(
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            )),
            'priority' => 'high',
        );
        
        $response = wp_remote_post('https://fcm.googleapis.com/fcm/send', array(
            'headers' => array(
                'Authorization' => 'key=' . $fcm_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($payload),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('PTP FCM Error: ' . $response->get_error_message());
            return false;
        }
        
        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        // Remove invalid tokens
        if (!empty($result['results'])) {
            foreach ($result['results'] as $i => $r) {
                if (!empty($r['error']) && in_array($r['error'], array('InvalidRegistration', 'NotRegistered'))) {
                    self::remove_token($tokens[$i]);
                }
            }
        }
        
        return $result['success'] ?? 0;
    }
    
    /**
     * Store notification in database for in-app notification center
     */
    private static function store_notification($user_id, $title, $body, $data = array()) {
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . 'ptp_notifications', array(
            'user_id' => $user_id,
            'title' => $title,
            'message' => $body,
            'type' => $data['type'] ?? 'general',
            'data' => json_encode($data),
            'is_read' => 0,
            'created_at' => current_time('mysql'),
        ));
    }
    
    /**
     * Get unread notification count
     */
    public static function get_unread_count($user_id) {
        global $wpdb;
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_notifications 
             WHERE user_id = %d AND is_read = 0",
            $user_id
        ));
    }
    
    /**
     * Remove invalid FCM token
     */
    private static function remove_token($token) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_fcm_tokens',
            array('is_active' => 0),
            array('token' => $token)
        );
    }
    
    // ================================================================
    // EVENT HANDLERS
    // ================================================================
    
    /**
     * New booking created - notify trainer
     */
    public static function on_booking_created($booking_id, $booking) {
        global $wpdb;
        
        // Get trainer's user_id
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.ID as wp_user_id FROM {$wpdb->prefix}ptp_trainers t 
             JOIN {$wpdb->users} u ON t.user_id = u.ID 
             WHERE t.id = %d",
            $booking->trainer_id
        ));
        
        if (!$trainer) return;
        
        $player_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}ptp_players WHERE id = %d",
            $booking->player_id
        ));
        
        $date = date('M j', strtotime($booking->session_date));
        $time = date('g:i A', strtotime($booking->start_time));
        
        self::send(
            $trainer->wp_user_id,
            '📅 New Booking Request',
            "{$player_name} wants to book a session on {$date} at {$time}",
            array(
                'type' => 'booking_new',
                'booking_id' => $booking_id,
                'screen' => 'booking_detail',
            )
        );
    }
    
    /**
     * Booking confirmed - notify parent
     */
    public static function on_booking_confirmed($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, pa.user_id as parent_user_id
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) return;
        
        $date = date('M j', strtotime($booking->session_date));
        $time = date('g:i A', strtotime($booking->start_time));
        
        self::send(
            $booking->parent_user_id,
            '✅ Booking Confirmed!',
            "Your session with {$booking->trainer_name} on {$date} at {$time} is confirmed",
            array(
                'type' => 'booking_confirmed',
                'booking_id' => $booking_id,
                'screen' => 'booking_detail',
            )
        );
    }
    
    /**
     * Booking cancelled - notify other party
     */
    public static function on_booking_cancelled($booking_id, $cancelled_by) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.user_id as trainer_user_id,
                    pa.display_name as parent_name, pa.user_id as parent_user_id,
                    p.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) return;
        
        $date = date('M j', strtotime($booking->session_date));
        
        // Notify the other party
        if ($cancelled_by === 'trainer') {
            self::send(
                $booking->parent_user_id,
                '❌ Session Cancelled',
                "{$booking->trainer_name} has cancelled the session on {$date}",
                array(
                    'type' => 'booking_cancelled',
                    'booking_id' => $booking_id,
                    'screen' => 'bookings',
                )
            );
        } else {
            self::send(
                $booking->trainer_user_id,
                '❌ Session Cancelled',
                "{$booking->parent_name} cancelled the session on {$date}",
                array(
                    'type' => 'booking_cancelled',
                    'booking_id' => $booking_id,
                    'screen' => 'trainer_bookings',
                )
            );
        }
    }
    
    /**
     * Booking completed - notify parent to leave review
     */
    public static function on_booking_completed($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, pa.user_id as parent_user_id
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) return;
        
        self::send(
            $booking->parent_user_id,
            '⭐ How was the session?',
            "Leave a review for {$booking->trainer_name}",
            array(
                'type' => 'review_request',
                'booking_id' => $booking_id,
                'trainer_id' => $booking->trainer_id,
                'screen' => 'leave_review',
            )
        );
    }
    
    /**
     * New message received
     */
    public static function on_message_sent($message_id, $sender_id, $recipient_id) {
        global $wpdb;
        
        $sender = get_userdata($sender_id);
        $message = $wpdb->get_var($wpdb->prepare(
            "SELECT content FROM {$wpdb->prefix}ptp_messages WHERE id = %d",
            $message_id
        ));
        
        $preview = strlen($message) > 50 ? substr($message, 0, 47) . '...' : $message;
        
        self::send(
            $recipient_id,
            '💬 ' . $sender->display_name,
            $preview,
            array(
                'type' => 'message',
                'sender_id' => $sender_id,
                'screen' => 'conversation',
            )
        );
    }
    
    /**
     * Player joined group session - notify trainer
     */
    public static function on_group_joined($group_id, $player_id) {
        global $wpdb;
        
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT g.*, t.user_id as trainer_user_id
             FROM {$wpdb->prefix}ptp_group_sessions g
             JOIN {$wpdb->prefix}ptp_trainers t ON g.trainer_id = t.id
             WHERE g.id = %d",
            $group_id
        ));
        
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d",
            $player_id
        ));
        
        if (!$group || !$player) return;
        
        $spots_left = $group->max_players - $group->current_players - 1;
        
        self::send(
            $group->trainer_user_id,
            '👥 Player Joined Group Session',
            "{$player->name} joined \"{$group->title}\" ({$spots_left} spots left)",
            array(
                'type' => 'group_joined',
                'group_id' => $group_id,
                'screen' => 'group_detail',
            )
        );
    }
    
    /**
     * New review submitted - notify trainer
     */
    public static function on_review_submitted($review_id, $trainer_id) {
        global $wpdb;
        
        $review = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, pa.display_name as parent_name
             FROM {$wpdb->prefix}ptp_reviews r
             JOIN {$wpdb->prefix}ptp_parents pa ON r.parent_id = pa.id
             WHERE r.id = %d",
            $review_id
        ));
        
        $trainer = PTP_Trainer::get($trainer_id);
        
        if (!$review || !$trainer) return;
        
        $stars = str_repeat('⭐', $review->rating);
        
        self::send(
            $trainer->user_id,
            "New {$review->rating}-Star Review!",
            "{$review->parent_name} left you a review {$stars}",
            array(
                'type' => 'review_received',
                'review_id' => $review_id,
                'screen' => 'reviews',
            )
        );
    }
    
    /**
     * Session reminder (called by cron)
     */
    public static function on_session_reminder($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.user_id as trainer_user_id,
                    pa.user_id as parent_user_id, p.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) return;
        
        $time = date('g:i A', strtotime($booking->start_time));
        
        // Notify parent
        self::send(
            $booking->parent_user_id,
            '⏰ Session in 1 Hour',
            "Session with {$booking->trainer_name} at {$time}",
            array(
                'type' => 'session_reminder',
                'booking_id' => $booking_id,
                'screen' => 'booking_detail',
            )
        );
        
        // Notify trainer
        self::send(
            $booking->trainer_user_id,
            '⏰ Session in 1 Hour',
            "Session with {$booking->player_name} at {$time}",
            array(
                'type' => 'session_reminder',
                'booking_id' => $booking_id,
                'screen' => 'trainer_booking_detail',
            )
        );
    }
    
    /**
     * Send bulk notification to all users of a type
     */
    public static function send_bulk($user_type, $title, $body, $data = array()) {
        global $wpdb;
        
        if ($user_type === 'trainer') {
            $users = $wpdb->get_col("
                SELECT user_id FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'
            ");
        } elseif ($user_type === 'parent') {
            $users = $wpdb->get_col("
                SELECT user_id FROM {$wpdb->prefix}ptp_parents WHERE status = 'active'
            ");
        } else {
            return false;
        }
        
        $sent = 0;
        foreach ($users as $user_id) {
            if (self::send($user_id, $title, $body, $data)) {
                $sent++;
            }
        }
        
        return $sent;
    }
    
    /**
     * Send to audience (for App Control Center)
     */
    public static function send_to_audience($audience, $title, $message, $data = array()) {
        global $wpdb;
        
        $fcm_key = self::get_fcm_key();
        if (empty($fcm_key)) {
            return new WP_Error('no_fcm_key', 'FCM server key not configured');
        }
        
        // Get tokens based on audience
        if ($audience === 'trainers') {
            $tokens = $wpdb->get_col("
                SELECT DISTINCT f.token FROM {$wpdb->prefix}ptp_fcm_tokens f
                JOIN {$wpdb->prefix}ptp_trainers t ON f.user_id = t.user_id
                WHERE f.is_active = 1 AND t.status = 'active'
            ");
        } elseif ($audience === 'parents') {
            $tokens = $wpdb->get_col("
                SELECT DISTINCT f.token FROM {$wpdb->prefix}ptp_fcm_tokens f
                JOIN {$wpdb->prefix}ptp_parents p ON f.user_id = p.user_id
                WHERE f.is_active = 1
            ");
        } else {
            // All users
            $tokens = $wpdb->get_col("
                SELECT token FROM {$wpdb->prefix}ptp_fcm_tokens WHERE is_active = 1
            ");
        }
        
        if (empty($tokens)) {
            return array('sent' => 0, 'message' => 'No devices registered');
        }
        
        // FCM allows max 1000 tokens per request
        $chunks = array_chunk($tokens, 1000);
        $total_sent = 0;
        $total_failed = 0;
        
        foreach ($chunks as $chunk) {
            $payload = array(
                'registration_ids' => $chunk,
                'notification' => array(
                    'title' => $title,
                    'body' => $message,
                    'sound' => 'default',
                ),
                'data' => array_merge($data, array(
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'type' => 'broadcast',
                )),
                'priority' => 'high',
            );
            
            $response = wp_remote_post('https://fcm.googleapis.com/fcm/send', array(
                'headers' => array(
                    'Authorization' => 'key=' . $fcm_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($payload),
                'timeout' => 60,
            ));
            
            if (!is_wp_error($response)) {
                $result = json_decode(wp_remote_retrieve_body($response), true);
                $total_sent += ($result['success'] ?? 0);
                $total_failed += ($result['failure'] ?? 0);
                
                // Clean up invalid tokens
                if (!empty($result['results'])) {
                    foreach ($result['results'] as $i => $r) {
                        if (!empty($r['error']) && in_array($r['error'], array('InvalidRegistration', 'NotRegistered'))) {
                            self::remove_token($chunk[$i]);
                        }
                    }
                }
            }
        }
        
        return array(
            'sent' => $total_sent,
            'failed' => $total_failed,
            'total_devices' => count($tokens),
        );
    }
    
    /**
     * Check if push notifications are enabled
     */
    public static function is_enabled() {
        return !empty(get_option('ptp_fcm_server_key'));
    }
}
