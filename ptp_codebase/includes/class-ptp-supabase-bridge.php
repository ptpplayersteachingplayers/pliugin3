<?php
/**
 * PTP Supabase Bridge v1.0.0
 * 
 * Real-time sync between WordPress and Supabase
 * Enables lightning-fast mobile app with Supabase backend
 * 
 * Features:
 * - Automatic data sync to Supabase
 * - Real-time notifications via Supabase
 * - Webhook handlers for app events
 * - Conflict resolution
 * - Offline-first support
 * 
 * @since 57.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PTP_Supabase_Bridge {
    
    private static $instance = null;
    private $supabase_url;
    private $supabase_key;
    private $service_key;
    
    // Tables to sync
    const SYNC_TABLES = [
        'trainers',
        'parents', 
        'players',
        'bookings',
        'availability',
        'messages',
        'reviews',
        'notifications'
    ];
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->supabase_url = get_option('ptp_supabase_url', '');
        $this->supabase_key = get_option('ptp_supabase_anon_key', '');
        $this->service_key = get_option('ptp_supabase_service_key', '');
        
        if (!$this->is_configured()) {
            return;
        }
        
        $this->init_hooks();
    }
    
    /**
     * Check if Supabase is configured
     */
    public function is_configured() {
        return !empty($this->supabase_url) && !empty($this->service_key);
    }
    
    /**
     * Initialize hooks for real-time sync
     */
    private function init_hooks() {
        // Trainer events
        add_action('ptp_trainer_created', [$this, 'sync_trainer'], 10, 1);
        add_action('ptp_trainer_updated', [$this, 'sync_trainer'], 10, 1);
        add_action('ptp_trainer_availability_updated', [$this, 'sync_availability'], 10, 1);
        
        // Booking events - CRITICAL for real-time
        add_action('ptp_booking_created', [$this, 'sync_booking'], 10, 1);
        add_action('ptp_booking_confirmed', [$this, 'sync_booking'], 10, 1);
        add_action('ptp_booking_completed', [$this, 'sync_booking'], 10, 1);
        add_action('ptp_booking_cancelled', [$this, 'sync_booking'], 10, 1);
        
        // Message events - Real-time chat
        add_action('ptp_message_sent', [$this, 'sync_message'], 10, 1);
        
        // Review events
        add_action('ptp_review_submitted', [$this, 'sync_review'], 10, 1);
        
        // Parent/Player events
        add_action('ptp_parent_created', [$this, 'sync_parent'], 10, 1);
        add_action('ptp_player_created', [$this, 'sync_player'], 10, 1);
        add_action('ptp_player_updated', [$this, 'sync_player'], 10, 1);
        
        // Push notification triggers
        add_action('ptp_booking_created', [$this, 'push_booking_notification'], 10, 2);
        add_action('ptp_message_sent', [$this, 'push_message_notification'], 10, 2);
        
        // Webhook endpoint for app -> WordPress
        add_action('rest_api_init', [$this, 'register_webhook_endpoints']);
        
        // Admin settings
        add_action('admin_menu', [$this, 'add_settings_page']);
    }
    
    /**
     * Make request to Supabase
     */
    private function supabase_request($endpoint, $method = 'GET', $data = null, $use_service_key = true) {
        $url = rtrim($this->supabase_url, '/') . '/rest/v1/' . ltrim($endpoint, '/');
        
        $headers = [
            'apikey' => $use_service_key ? $this->service_key : $this->supabase_key,
            'Authorization' => 'Bearer ' . ($use_service_key ? $this->service_key : $this->supabase_key),
            'Content-Type' => 'application/json',
            'Prefer' => 'return=representation'
        ];
        
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];
        
        if ($data && in_array($method, ['POST', 'PATCH', 'PUT'])) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $this->log_error('Supabase request failed', [
                'endpoint' => $endpoint,
                'error' => $response->get_error_message()
            ]);
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code >= 400) {
            $this->log_error('Supabase error response', [
                'endpoint' => $endpoint,
                'code' => $code,
                'body' => $body
            ]);
            return new WP_Error('supabase_error', $body['message'] ?? 'Unknown error', ['status' => $code]);
        }
        
        return $body;
    }
    
    /**
     * Send real-time notification via Supabase
     */
    public function send_realtime_event($channel, $event, $payload) {
        // Use Supabase Realtime broadcast
        $url = rtrim($this->supabase_url, '/') . '/realtime/v1/broadcast';
        
        $response = wp_remote_post($url, [
            'headers' => [
                'apikey' => $this->service_key,
                'Authorization' => 'Bearer ' . $this->service_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'channel' => $channel,
                'event' => $event,
                'payload' => $payload
            ]),
            'timeout' => 10
        ]);
        
        return !is_wp_error($response);
    }
    
    // =========================================================================
    // SYNC METHODS
    // =========================================================================
    
    /**
     * Sync trainer to Supabase
     */
    public function sync_trainer($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ), ARRAY_A);
        
        if (!$trainer) return;
        
        $supabase_data = [
            'wp_id' => $trainer['id'],
            'user_id' => $trainer['user_id'],
            'slug' => $trainer['slug'],
            'display_name' => $trainer['display_name'],
            'email' => $trainer['email'],
            'phone' => $trainer['phone'],
            'photo_url' => $trainer['photo_url'],
            'headline' => $trainer['headline'],
            'bio' => $trainer['bio'],
            'hourly_rate' => floatval($trainer['hourly_rate']),
            'location' => $trainer['location'],
            'latitude' => floatval($trainer['latitude']),
            'longitude' => floatval($trainer['longitude']),
            'specialties' => $trainer['specialties'],
            'college' => $trainer['college'],
            'pro_experience' => $trainer['pro_experience'],
            'certifications' => $trainer['certifications'],
            'average_rating' => floatval($trainer['average_rating']),
            'review_count' => intval($trainer['review_count']),
            'total_sessions' => intval($trainer['total_sessions']),
            'is_featured' => (bool)$trainer['is_featured'],
            'status' => $trainer['status'],
            'stripe_account_id' => $trainer['stripe_account_id'],
            'stripe_payouts_enabled' => (bool)$trainer['stripe_payouts_enabled'],
            'updated_at' => current_time('c')
        ];
        
        // Upsert to Supabase
        $result = $this->supabase_request(
            'trainers?wp_id=eq.' . $trainer_id,
            'GET'
        );
        
        if (empty($result)) {
            // Insert
            $this->supabase_request('trainers', 'POST', $supabase_data);
        } else {
            // Update
            $this->supabase_request(
                'trainers?wp_id=eq.' . $trainer_id,
                'PATCH',
                $supabase_data
            );
        }
        
        // Broadcast update for real-time listeners
        $this->send_realtime_event(
            'trainers',
            'UPDATE',
            ['trainer_id' => $trainer_id, 'slug' => $trainer['slug']]
        );
    }
    
    /**
     * Sync booking to Supabase - CRITICAL for app
     */
    public function sync_booking($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, 
                    t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug,
                    p.display_name as parent_name, p.email as parent_email,
                    COALESCE(pl.name, CONCAT(pl.first_name, ' ', pl.last_name)) as player_name, pl.age as player_age
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE b.id = %d",
            $booking_id
        ), ARRAY_A);
        
        if (!$booking) return;
        
        $supabase_data = [
            'wp_id' => $booking['id'],
            'trainer_id' => $booking['trainer_id'],
            'parent_id' => $booking['parent_id'],
            'player_id' => $booking['player_id'],
            'session_date' => $booking['session_date'],
            'start_time' => $booking['start_time'],
            'end_time' => $booking['end_time'],
            'duration' => intval($booking['duration_minutes'] ?? 60),
            'location' => $booking['location'],
            'location_type' => $booking['location_type'] ?? 'in_person',
            'status' => $booking['status'],
            'amount' => floatval($booking['total_amount'] ?? 0),
            'trainer_payout' => floatval($booking['trainer_payout']),
            'payment_status' => $booking['payment_status'],
            'escrow_status' => $booking['escrow_status'] ?? null,
            'notes' => $booking['notes'],
            'booking_number' => $booking['booking_number'] ?? '',
            // Denormalized for fast app queries
            'trainer_name' => $booking['trainer_name'],
            'trainer_photo' => $booking['trainer_photo'],
            'trainer_slug' => $booking['trainer_slug'],
            'parent_name' => $booking['parent_name'],
            'player_name' => $booking['player_name'],
            'player_age' => $booking['player_age'],
            'updated_at' => current_time('c')
        ];
        
        // Upsert
        $existing = $this->supabase_request('bookings?wp_id=eq.' . $booking_id, 'GET');
        
        if (empty($existing)) {
            $this->supabase_request('bookings', 'POST', $supabase_data);
        } else {
            $this->supabase_request('bookings?wp_id=eq.' . $booking_id, 'PATCH', $supabase_data);
        }
        
        // Real-time broadcast to both trainer and parent
        $this->send_realtime_event(
            'user_' . $booking['trainer_id'],
            'booking_' . $booking['status'],
            $supabase_data
        );
        
        $this->send_realtime_event(
            'parent_' . $booking['parent_id'],
            'booking_' . $booking['status'],
            $supabase_data
        );
    }
    
    /**
     * Sync message for real-time chat
     */
    public function sync_message($message_id) {
        global $wpdb;
        
        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT m.*, 
                    CASE WHEN m.sender_type = 'trainer' THEN t.display_name ELSE p.display_name END as sender_name,
                    CASE WHEN m.sender_type = 'trainer' THEN t.photo_url ELSE NULL END as sender_photo
             FROM {$wpdb->prefix}ptp_messages m
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON m.sender_type = 'trainer' AND m.sender_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON m.sender_type = 'parent' AND m.sender_id = p.id
             WHERE m.id = %d",
            $message_id
        ), ARRAY_A);
        
        if (!$message) return;
        
        $supabase_data = [
            'wp_id' => $message['id'],
            'conversation_id' => $message['conversation_id'],
            'sender_type' => $message['sender_type'],
            'sender_id' => $message['sender_id'],
            'sender_name' => $message['sender_name'],
            'sender_photo' => $message['sender_photo'],
            'message' => $message['message'],
            'is_read' => (bool)$message['is_read'],
            'created_at' => $message['created_at']
        ];
        
        $this->supabase_request('messages', 'POST', $supabase_data);
        
        // Real-time broadcast
        $this->send_realtime_event(
            'conversation_' . $message['conversation_id'],
            'new_message',
            $supabase_data
        );
    }
    
    /**
     * Sync availability
     */
    public function sync_availability($trainer_id) {
        global $wpdb;
        
        $slots = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_availability WHERE trainer_id = %d",
            $trainer_id
        ), ARRAY_A);
        
        // Delete existing and re-insert (simplest for availability)
        $this->supabase_request('availability?trainer_id=eq.' . $trainer_id, 'DELETE');
        
        foreach ($slots as $slot) {
            $this->supabase_request('availability', 'POST', [
                'wp_id' => $slot['id'],
                'trainer_id' => $slot['trainer_id'],
                'day_of_week' => $slot['day_of_week'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'is_available' => (bool)$slot['is_available'],
                'specific_date' => $slot['specific_date']
            ]);
        }
        
        $this->send_realtime_event('trainer_' . $trainer_id, 'availability_updated', [
            'trainer_id' => $trainer_id
        ]);
    }
    
    /**
     * Sync parent
     */
    public function sync_parent($parent_id) {
        global $wpdb;
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE id = %d",
            $parent_id
        ), ARRAY_A);
        
        if (!$parent) return;
        
        $this->supabase_request('parents', 'POST', [
            'wp_id' => $parent['id'],
            'user_id' => $parent['user_id'],
            'display_name' => $parent['display_name'],
            'email' => $parent['email'],
            'phone' => $parent['phone'],
            'location' => $parent['location'],
            'created_at' => $parent['created_at']
        ]);
    }
    
    /**
     * Sync player
     */
    public function sync_player($player_id) {
        global $wpdb;
        
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d",
            $player_id
        ), ARRAY_A);
        
        if (!$player) return;
        
        $existing = $this->supabase_request('players?wp_id=eq.' . $player_id, 'GET');
        
        $data = [
            'wp_id' => $player['id'],
            'parent_id' => $player['parent_id'],
            'first_name' => $player['first_name'],
            'last_name' => $player['last_name'],
            'age' => $player['age'],
            'skill_level' => $player['skill_level'],
            'position' => $player['position'],
            'notes' => $player['notes'],
            'updated_at' => current_time('c')
        ];
        
        if (empty($existing)) {
            $this->supabase_request('players', 'POST', $data);
        } else {
            $this->supabase_request('players?wp_id=eq.' . $player_id, 'PATCH', $data);
        }
    }
    
    /**
     * Sync review
     */
    public function sync_review($review_id) {
        global $wpdb;
        
        $review = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, p.display_name as reviewer_name
             FROM {$wpdb->prefix}ptp_reviews r
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON r.parent_id = p.id
             WHERE r.id = %d",
            $review_id
        ), ARRAY_A);
        
        if (!$review) return;
        
        $this->supabase_request('reviews', 'POST', [
            'wp_id' => $review['id'],
            'trainer_id' => $review['trainer_id'],
            'parent_id' => $review['parent_id'],
            'booking_id' => $review['booking_id'],
            'rating' => intval($review['rating']),
            'comment' => $review['comment'],
            'reviewer_name' => $review['reviewer_name'],
            'created_at' => $review['created_at']
        ]);
        
        // Update trainer's rating in real-time
        $this->sync_trainer($review['trainer_id']);
    }
    
    // =========================================================================
    // PUSH NOTIFICATIONS
    // =========================================================================
    
    /**
     * Push notification for new booking
     */
    public function push_booking_notification($booking_id, $booking) {
        global $wpdb;
        
        // Get trainer's Supabase user ID
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $booking->trainer_id
        ));
        
        if (!$trainer) return;
        
        // Insert notification into Supabase
        $this->supabase_request('notifications', 'POST', [
            'user_id' => $trainer->user_id,
            'user_type' => 'trainer',
            'type' => 'new_booking',
            'title' => '🎯 New Booking!',
            'body' => 'You have a new training session booked for ' . date('M j', strtotime($booking->session_date)),
            'data' => json_encode([
                'booking_id' => $booking_id,
                'screen' => 'BookingDetail'
            ]),
            'is_read' => false,
            'created_at' => current_time('c')
        ]);
        
        // Trigger push via Supabase Edge Function
        $this->trigger_push_notification($trainer->user_id, 'trainer', [
            'title' => '🎯 New Booking!',
            'body' => 'New session on ' . date('M j @ g:ia', strtotime($booking->session_date . ' ' . $booking->start_time)),
            'data' => ['booking_id' => $booking_id, 'screen' => 'BookingDetail']
        ]);
    }
    
    /**
     * Push notification for new message
     */
    public function push_message_notification($message_id, $message) {
        global $wpdb;
        
        // Determine recipient
        $recipient_type = $message->sender_type === 'trainer' ? 'parent' : 'trainer';
        $recipient_id = $message->sender_type === 'trainer' ? $message->parent_id : $message->trainer_id;
        
        $this->supabase_request('notifications', 'POST', [
            'user_id' => $recipient_id,
            'user_type' => $recipient_type,
            'type' => 'new_message',
            'title' => '💬 New Message',
            'body' => substr($message->message, 0, 100) . (strlen($message->message) > 100 ? '...' : ''),
            'data' => json_encode([
                'conversation_id' => $message->conversation_id,
                'screen' => 'Chat'
            ]),
            'is_read' => false
        ]);
        
        $this->trigger_push_notification($recipient_id, $recipient_type, [
            'title' => '💬 New Message',
            'body' => substr($message->message, 0, 100),
            'data' => ['conversation_id' => $message->conversation_id, 'screen' => 'Chat']
        ]);
    }
    
    /**
     * Trigger push notification via Supabase Edge Function
     */
    private function trigger_push_notification($user_id, $user_type, $notification) {
        $edge_function_url = rtrim($this->supabase_url, '/') . '/functions/v1/send-push';
        
        wp_remote_post($edge_function_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->service_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'user_id' => $user_id,
                'user_type' => $user_type,
                'notification' => $notification
            ]),
            'timeout' => 5,
            'blocking' => false // Fire and forget
        ]);
    }
    
    // =========================================================================
    // WEBHOOK HANDLERS (App -> WordPress)
    // =========================================================================
    
    /**
     * Register webhook endpoints
     */
    public function register_webhook_endpoints() {
        register_rest_route('ptp/v1', '/supabase/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => [$this, 'verify_webhook']
        ]);
        
        // Quick actions from app
        register_rest_route('ptp/v1', '/app/quick-book', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_quick_book'],
            'permission_callback' => [$this, 'verify_app_request']
        ]);
        
        register_rest_route('ptp/v1', '/app/confirm-session', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_confirm_session'],
            'permission_callback' => [$this, 'verify_app_request']
        ]);
    }
    
    /**
     * Verify webhook signature
     */
    public function verify_webhook($request) {
        $signature = $request->get_header('X-Supabase-Signature');
        $webhook_secret = get_option('ptp_supabase_webhook_secret', '');
        
        if (empty($webhook_secret)) {
            return true; // No secret configured, allow
        }
        
        $payload = $request->get_body();
        $expected = hash_hmac('sha256', $payload, $webhook_secret);
        
        return hash_equals($expected, $signature);
    }
    
    /**
     * Verify app request with JWT
     */
    public function verify_app_request($request) {
        $auth = $request->get_header('Authorization');
        
        if (empty($auth) || strpos($auth, 'Bearer ') !== 0) {
            return false;
        }
        
        $token = substr($auth, 7);
        
        // Verify JWT with Supabase
        $response = wp_remote_get($this->supabase_url . '/auth/v1/user', [
            'headers' => [
                'apikey' => $this->supabase_key,
                'Authorization' => 'Bearer ' . $token
            ]
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $user = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($user['id'])) {
            return false;
        }
        
        // Store user for later use
        $request->set_param('supabase_user', $user);
        
        return true;
    }
    
    /**
     * Handle webhook from Supabase
     */
    public function handle_webhook($request) {
        $data = $request->get_json_params();
        
        $type = $data['type'] ?? '';
        $table = $data['table'] ?? '';
        $record = $data['record'] ?? [];
        
        switch ($table) {
            case 'bookings':
                if ($type === 'UPDATE' && isset($record['status'])) {
                    $this->process_booking_status_change($record);
                }
                break;
                
            case 'messages':
                if ($type === 'INSERT') {
                    $this->process_new_message_from_app($record);
                }
                break;
        }
        
        return ['success' => true];
    }
    
    /**
     * Handle quick book from app (1-tap booking)
     */
    public function handle_quick_book($request) {
        $supabase_user = $request->get_param('supabase_user');
        $trainer_id = absint($request->get_param('trainer_id'));
        $slot_date = sanitize_text_field($request->get_param('date'));
        $slot_time = sanitize_text_field($request->get_param('time'));
        $player_id = absint($request->get_param('player_id'));
        $payment_method_id = sanitize_text_field($request->get_param('payment_method_id'));
        
        global $wpdb;
        
        // Get parent from Supabase user
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d OR email = %s",
            $supabase_user['id'],
            $supabase_user['email']
        ));
        
        if (!$parent) {
            return new WP_Error('no_parent', 'Parent account not found', ['status' => 404]);
        }
        
        // Get trainer
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('no_trainer', 'Trainer not found', ['status' => 404]);
        }
        
        // Check slot availability
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date = %s AND start_time = %s 
             AND status NOT IN ('cancelled')",
            $trainer_id, $slot_date, $slot_time
        ));
        
        if ($existing) {
            return new WP_Error('slot_taken', 'This time slot is no longer available', ['status' => 409]);
        }
        
        // Process payment
        if (class_exists('PTP_Stripe')) {
            $amount = floatval($trainer->hourly_rate);
            $payment = PTP_Stripe::charge_customer(
                $parent->stripe_customer_id,
                $payment_method_id,
                $amount * 100,
                'PTP Training - ' . $trainer->display_name
            );
            
            if (is_wp_error($payment)) {
                return $payment;
            }
        }
        
        // Create booking
        $wpdb->insert(
            $wpdb->prefix . 'ptp_bookings',
            [
                'trainer_id' => $trainer_id,
                'parent_id' => $parent->id,
                'player_id' => $player_id,
                'session_date' => $slot_date,
                'start_time' => $slot_time,
                'end_time' => date('H:i:s', strtotime($slot_time) + 3600),
                'duration' => 60,
                'total_amount' => $trainer->hourly_rate,
                'trainer_payout' => $trainer->hourly_rate * (1 - ptp_get_platform_fee()),
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'payment_intent_id' => $payment['id'] ?? '',
                'created_at' => current_time('mysql')
            ]
        );
        
        $booking_id = $wpdb->insert_id;
        
        // Trigger sync
        do_action('ptp_booking_created', $booking_id);
        
        return [
            'success' => true,
            'booking_id' => $booking_id,
            'message' => 'Booking confirmed!'
        ];
    }
    
    /**
     * Handle session confirmation from app
     */
    public function handle_confirm_session($request) {
        $booking_id = absint($request->get_param('booking_id'));
        $confirmed_by = sanitize_text_field($request->get_param('confirmed_by')); // 'trainer' or 'parent'
        $rating = intval($request->get_param('rating') ?? 0);
        $feedback = sanitize_textarea_field($request->get_param('feedback') ?? '');
        
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            return new WP_Error('not_found', 'Booking not found', ['status' => 404]);
        }
        
        // v216.1: Route through proper escrow/session completion system
        if ($confirmed_by === 'trainer') {
            // Trainer marking session complete — use escrow system
            if (class_exists('PTP_Escrow') && $booking->status === 'confirmed') {
                // Check for existing escrow
                $escrow = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_escrow WHERE booking_id = %d",
                    $booking_id
                ));
                
                if ($escrow) {
                    PTP_Escrow::mark_session_complete($booking_id);
                } else {
                    // No escrow — update directly and fire hooks
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        ['status' => 'completed', 'completed_at' => current_time('mysql')],
                        ['id' => $booking_id]
                    );
                    do_action('ptp_session_completed', $booking_id, $booking);
                    do_action('ptp_booking_completed', $booking_id, $booking);
                }
            }
        } elseif ($confirmed_by === 'parent') {
            // Parent confirming after session — use escrow parent confirm
            $escrow = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_escrow WHERE booking_id = %d AND status = 'session_complete'",
                $booking_id
            ));
            
            if ($escrow && class_exists('PTP_Escrow')) {
                // Update escrow with parent confirmation
                $wpdb->update(
                    $wpdb->prefix . 'ptp_escrow',
                    [
                        'status' => 'confirmed',
                        'parent_confirmed_at' => current_time('mysql'),
                    ],
                    ['id' => $escrow->id]
                );
                PTP_Escrow::release_funds($escrow->id);
            } else {
                // No escrow — just fire hooks
                do_action('ptp_session_completed', $booking_id, $booking);
                do_action('ptp_booking_completed', $booking_id, $booking);
            }
            
            // Store rating if provided
            if ($rating > 0) {
                do_action('ptp_review_submitted', $booking_id, [
                    'rating' => $rating,
                    'comment' => $feedback,
                ]);
            }
        }
        
        // Sync updated booking back to Supabase
        $this->sync_booking($booking_id);
        
        return [
            'success' => true,
            'message' => $confirmed_by === 'trainer' 
                ? 'Session marked complete. Waiting for parent confirmation.'
                : 'Session confirmed! Earnings are being processed.'
        ];
    }
    
    // =========================================================================
    // BULK SYNC
    // =========================================================================
    
    /**
     * Full sync of all data to Supabase
     */
    public function full_sync() {
        global $wpdb;
        
        $results = [];
        
        // Sync trainers
        $trainers = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'");
        foreach ($trainers as $id) {
            $this->sync_trainer($id);
        }
        $results['trainers'] = count($trainers);
        
        // Sync parents
        $parents = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}ptp_parents");
        foreach ($parents as $id) {
            $this->sync_parent($id);
        }
        $results['parents'] = count($parents);
        
        // Sync players
        $players = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}ptp_players");
        foreach ($players as $id) {
            $this->sync_player($id);
        }
        $results['players'] = count($players);
        
        // Sync recent bookings (last 90 days)
        $bookings = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings 
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        foreach ($bookings as $id) {
            $this->sync_booking($id);
        }
        $results['bookings'] = count($bookings);
        
        return $results;
    }
    
    // =========================================================================
    // ADMIN
    // =========================================================================
    
    /**
     * Add settings page
     */
    public function add_settings_page() {
        add_submenu_page(
            'ptp-training',
            'Supabase Settings',
            'Supabase Sync',
            'manage_options',
            'ptp-supabase',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (isset($_POST['ptp_supabase_save'])) {
            check_admin_referer('ptp_supabase_settings');
            
            update_option('ptp_supabase_url', sanitize_url($_POST['supabase_url']));
            update_option('ptp_supabase_anon_key', sanitize_text_field($_POST['anon_key']));
            update_option('ptp_supabase_service_key', sanitize_text_field($_POST['service_key']));
            
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        if (isset($_POST['ptp_supabase_sync'])) {
            check_admin_referer('ptp_supabase_settings');
            $results = $this->full_sync();
            echo '<div class="notice notice-success"><p>Sync complete! ' . 
                 'Trainers: ' . $results['trainers'] . ', ' .
                 'Parents: ' . $results['parents'] . ', ' .
                 'Players: ' . $results['players'] . ', ' .
                 'Bookings: ' . $results['bookings'] . '</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1>Supabase Integration</h1>
            
            <form method="post">
                <?php wp_nonce_field('ptp_supabase_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th>Supabase URL</th>
                        <td>
                            <input type="url" name="supabase_url" class="regular-text" 
                                   value="<?php echo esc_attr(get_option('ptp_supabase_url')); ?>"
                                   placeholder="https://xxxxx.supabase.co">
                        </td>
                    </tr>
                    <tr>
                        <th>Anon Key</th>
                        <td>
                            <input type="password" name="anon_key" class="regular-text" 
                                   value="<?php echo esc_attr(get_option('ptp_supabase_anon_key')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Service Key</th>
                        <td>
                            <input type="password" name="service_key" class="regular-text" 
                                   value="<?php echo esc_attr(get_option('ptp_supabase_service_key')); ?>">
                            <p class="description">Used for server-side operations</p>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="submit" name="ptp_supabase_save" class="button button-primary">Save Settings</button>
                    <button type="submit" name="ptp_supabase_sync" class="button">Full Sync Now</button>
                </p>
            </form>
            
            <hr>
            
            <h2>Status</h2>
            <p>
                <strong>Configured:</strong> <?php echo $this->is_configured() ? '✅ Yes' : '❌ No'; ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Log errors
     */
    private function log_error($message, $data = []) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log('PTP Supabase: ' . $message . ' - ' . json_encode($data));
        }
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Supabase_Bridge::instance();
}, 20);
