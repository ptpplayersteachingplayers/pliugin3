<?php
/**
 * PTP Google Calendar Integration - v71
 * Sync trainer availability with Google Calendar
 * 
 * @since 71.0.0
 */

defined('ABSPATH') || exit;

class PTP_Google_Calendar_V71 {
    
    private static $instance = null;
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->client_id = get_option('ptp_google_client_id', '');
        $this->client_secret = get_option('ptp_google_client_secret', '');
        $this->redirect_uri = admin_url('admin-ajax.php?action=ptp_google_oauth_callback');
        
        // AJAX handlers
        add_action('wp_ajax_ptp_google_connect', array($this, 'ajax_connect'));
        add_action('wp_ajax_ptp_google_oauth_callback', array($this, 'oauth_callback'));
        add_action('wp_ajax_ptp_google_disconnect', array($this, 'ajax_disconnect'));
        add_action('wp_ajax_ptp_google_sync', array($this, 'ajax_sync'));
        add_action('wp_ajax_ptp_google_get_calendars', array($this, 'ajax_get_calendars'));
        add_action('wp_ajax_ptp_google_set_calendar', array($this, 'ajax_set_calendar'));
        add_action('wp_ajax_ptp_get_calendar_status', array($this, 'ajax_get_status'));
        
        // Hook into booking creation to add to Google Calendar
        add_action('ptp_booking_confirmed', array($this, 'add_booking_to_calendar'), 10, 1);
        add_action('ptp_booking_created', array($this, 'add_booking_to_calendar'), 10, 1);
        
        // Cron for auto-sync
        add_action('ptp_sync_google_calendars', array($this, 'sync_all_calendars'));
        
        if (!wp_next_scheduled('ptp_sync_google_calendars')) {
            wp_schedule_event(time(), 'hourly', 'ptp_sync_google_calendars');
        }
    }
    
    /**
     * Add a booking to trainer's Google Calendar
     */
    public function add_booking_to_calendar($booking_id) {
        global $wpdb;
        
        // Get booking details
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.user_id as trainer_user_id, t.display_name as trainer_name,
                    p.first_name as player_first_name, p.last_name as player_last_name
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking || !$booking->trainer_user_id) {
            return;
        }
        
        // Check if trainer has Google Calendar connected
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_calendar_connections 
             WHERE user_id = %d AND provider = 'google' AND sync_enabled = 1",
            $booking->trainer_user_id
        ));
        
        if (!$connection || !$connection->calendar_id) {
            return;
        }
        
        // Build event details
        $player_name = trim(($booking->player_first_name ?? '') . ' ' . ($booking->player_last_name ?? ''));
        if (empty($player_name)) {
            $player_name = 'Player';
        }
        
        $session_date = $booking->session_date ?? $booking->date ?? '';
        $session_time = $booking->session_time ?? $booking->start_time ?? '16:00:00';
        $duration = $booking->duration ?? 60; // minutes
        
        if (empty($session_date)) {
            return;
        }
        
        // Create datetime strings
        $start_datetime = $session_date . 'T' . $session_time;
        $end_timestamp = strtotime($start_datetime) + ($duration * 60);
        $end_datetime = date('Y-m-d\TH:i:s', $end_timestamp);
        
        $event_data = array(
            'title' => 'PTP Training: ' . $player_name,
            'description' => sprintf(
                "Training session with %s\nBooked through PTP Training Platform\n\nBooking ID: %d",
                $player_name,
                $booking_id
            ),
            'start' => $start_datetime,
            'end' => $end_datetime,
            'location' => $booking->location ?? ''
        );
        
        // Create the event
        $event_id = $this->create_event($booking->trainer_user_id, $event_data);
        
        // Save Google Calendar event ID to booking
        if ($event_id) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('gcal_event_id' => $event_id),
                array('id' => $booking_id)
            );
        }
    }
    
    /**
     * Get OAuth URL for connecting Google account
     */
    public function get_auth_url($user_id) {
        $state = wp_create_nonce('google_oauth_' . $user_id);
        set_transient('ptp_google_oauth_' . $state, $user_id, 600);
        
        $params = array(
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state
        );
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    
    /**
     * AJAX: Initiate Google connection
     */
    public function ajax_connect() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Login required'));
        }
        
        if (empty($this->client_id)) {
            wp_send_json_error(array('message' => 'Google Calendar not configured'));
        }
        
        $auth_url = $this->get_auth_url(get_current_user_id());
        
        wp_send_json_success(array('auth_url' => $auth_url));
    }
    
    /**
     * OAuth callback handler
     */
    public function oauth_callback() {
        $code = sanitize_text_field($_GET['code'] ?? '');
        $state = sanitize_text_field($_GET['state'] ?? '');
        $error = sanitize_text_field($_GET['error'] ?? '');
        
        if ($error) {
            wp_redirect(home_url('/trainer-dashboard/?tab=schedule&gcal_error=denied'));
            exit;
        }
        
        // Verify state
        $user_id = get_transient('ptp_google_oauth_' . $state);
        if (!$user_id) {
            wp_redirect(home_url('/trainer-dashboard/?tab=schedule&gcal_error=invalid'));
            exit;
        }
        
        delete_transient('ptp_google_oauth_' . $state);
        
        // Exchange code for tokens
        $tokens = $this->exchange_code_for_tokens($code);
        
        if (!$tokens) {
            wp_redirect(home_url('/trainer-dashboard/?tab=schedule&gcal_error=token'));
            exit;
        }
        
        // Get user email from Google
        $email = $this->get_google_email($tokens['access_token']);
        
        // Save tokens with email
        $this->save_user_tokens($user_id, $tokens, $email);
        
        // Auto-select primary calendar
        $this->auto_select_primary_calendar($user_id, $tokens['access_token']);
        
        wp_redirect(home_url('/trainer-dashboard/?tab=schedule&gcal_connected=1'));
        exit;
    }
    
    /**
     * Get user email from Google API
     */
    private function get_google_email($access_token) {
        $response = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', array(
            'headers' => array('Authorization' => 'Bearer ' . $access_token),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return '';
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['email'] ?? '';
    }
    
    /**
     * Auto-select primary calendar after connection
     */
    private function auto_select_primary_calendar($user_id, $access_token) {
        $response = wp_remote_get('https://www.googleapis.com/calendar/v3/users/me/calendarList', array(
            'headers' => array('Authorization' => 'Bearer ' . $access_token),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['items'])) {
            foreach ($body['items'] as $cal) {
                if (!empty($cal['primary'])) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_calendar_connections',
                        array('calendar_id' => $cal['id']),
                        array('user_id' => $user_id, 'provider' => 'google')
                    );
                    break;
                }
            }
        }
    }
    
    /**
     * Exchange authorization code for tokens
     */
    private function exchange_code_for_tokens($code) {
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirect_uri
            )
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['access_token'])) {
            return array(
                'access_token' => $body['access_token'],
                'refresh_token' => $body['refresh_token'] ?? '',
                'expires_in' => $body['expires_in'] ?? 3600
            );
        }
        
        return null;
    }
    
    /**
     * Save user tokens to database
     */
    private function save_user_tokens($user_id, $tokens, $email = '') {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_calendar_connections';
        $expires = date('Y-m-d H:i:s', time() + $tokens['expires_in']);
        
        // Check for existing connection
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d AND provider = 'google'",
            $user_id
        ));
        
        $data = array(
            'user_id' => $user_id,
            'provider' => 'google',
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_expires' => $expires,
            'sync_enabled' => 1,
            'updated_at' => current_time('mysql')
        );
        
        if ($email) {
            $data['email'] = $email;
        }
        
        if ($existing) {
            $wpdb->update($table, $data, array('id' => $existing));
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
        }
    }
    
    /**
     * Get user tokens
     */
    private function get_user_tokens($user_id) {
        global $wpdb;
        
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_calendar_connections 
             WHERE user_id = %d AND provider = 'google'",
            $user_id
        ));
        
        if (!$connection) {
            return null;
        }
        
        // Check if token expired
        if (strtotime($connection->token_expires) < time()) {
            $new_tokens = $this->refresh_access_token($connection->refresh_token);
            if ($new_tokens) {
                $this->save_user_tokens($user_id, $new_tokens);
                return $new_tokens['access_token'];
            }
            return null;
        }
        
        return $connection->access_token;
    }
    
    /**
     * Refresh access token
     */
    private function refresh_access_token($refresh_token) {
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token'
            )
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['access_token'])) {
            return array(
                'access_token' => $body['access_token'],
                'refresh_token' => $refresh_token,
                'expires_in' => $body['expires_in'] ?? 3600
            );
        }
        
        return null;
    }
    
    /**
     * AJAX: Disconnect Google Calendar
     */
    public function ajax_disconnect() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        
        $wpdb->delete(
            $wpdb->prefix . 'ptp_calendar_connections',
            array('user_id' => get_current_user_id(), 'provider' => 'google')
        );
        
        wp_send_json_success(array('message' => 'Disconnected'));
    }
    
    /**
     * AJAX: Get calendar connection status
     */
    public function ajax_get_status() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_calendar_connections 
             WHERE user_id = %d AND provider = 'google'",
            $user_id
        ));
        
        // Get or generate ICS URL
        $ics_url = $this->get_ics_url($user_id);
        
        if ($connection) {
            wp_send_json_success(array(
                'connected' => true,
                'calendar_id' => $connection->calendar_id,
                'calendar_name' => $connection->email ?: 'Primary Calendar',
                'sync_enabled' => (bool)$connection->sync_enabled,
                'last_sync' => $connection->last_sync,
                'ics_url' => $ics_url
            ));
        } else {
            wp_send_json_success(array(
                'connected' => false,
                'ics_url' => $ics_url
            ));
        }
    }
    
    /**
     * Get ICS subscription URL for trainer
     */
    private function get_ics_url($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_calendar_connections';
        
        // Check if table exists first
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($table_exists !== $table) {
            return '';
        }
        
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT ics_token FROM $table WHERE user_id = %d LIMIT 1",
            $user_id
        ));
        
        if (!$connection || empty($connection->ics_token)) {
            // Create ICS token
            $token = wp_generate_password(32, false);
            
            if ($connection) {
                $wpdb->update($table, array('ics_token' => $token), array('user_id' => $user_id));
            } else {
                $wpdb->insert($table, array(
                    'user_id' => $user_id,
                    'provider' => 'ics',
                    'ics_token' => $token,
                    'created_at' => current_time('mysql')
                ));
            }
        } else {
            $token = $connection->ics_token;
        }
        
        return rest_url('ptp/v1/calendar/' . $token);
    }
    
    /**
     * AJAX: Get user's Google Calendars
     */
    public function ajax_get_calendars() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $access_token = $this->get_user_tokens(get_current_user_id());
        if (!$access_token) {
            wp_send_json_error(array('message' => 'Not connected'));
        }
        
        $response = wp_remote_get('https://www.googleapis.com/calendar/v3/users/me/calendarList', array(
            'headers' => array('Authorization' => 'Bearer ' . $access_token)
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'API error'));
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        $calendars = array();
        if (isset($body['items'])) {
            foreach ($body['items'] as $cal) {
                $calendars[] = array(
                    'id' => $cal['id'],
                    'name' => $cal['summary'],
                    'primary' => $cal['primary'] ?? false
                );
            }
        }
        
        wp_send_json_success(array('calendars' => $calendars));
    }
    
    /**
     * AJAX: Set selected calendar
     */
    public function ajax_set_calendar() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        
        $calendar_id = sanitize_text_field($_POST['calendar_id'] ?? '');
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_calendar_connections',
            array('calendar_id' => $calendar_id),
            array('user_id' => get_current_user_id(), 'provider' => 'google')
        );
        
        wp_send_json_success(array('message' => 'Calendar selected'));
    }
    
    /**
     * AJAX: Manual sync
     */
    public function ajax_sync() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $result = $this->sync_user_calendar(get_current_user_id());
        
        if ($result) {
            wp_send_json_success(array('message' => 'Calendar synced'));
        } else {
            wp_send_json_error(array('message' => 'Sync failed'));
        }
    }
    
    /**
     * Sync user's Google Calendar events
     */
    public function sync_user_calendar($user_id) {
        global $wpdb;
        
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_calendar_connections 
             WHERE user_id = %d AND provider = 'google'",
            $user_id
        ));
        
        if (!$connection || !$connection->calendar_id) {
            return false;
        }
        
        $access_token = $this->get_user_tokens($user_id);
        if (!$access_token) {
            return false;
        }
        
        // Fetch events for next 30 days
        $time_min = date('c');
        $time_max = date('c', strtotime('+30 days'));
        
        $url = sprintf(
            'https://www.googleapis.com/calendar/v3/calendars/%s/events?timeMin=%s&timeMax=%s&singleEvents=true',
            urlencode($connection->calendar_id),
            urlencode($time_min),
            urlencode($time_max)
        );
        
        $response = wp_remote_get($url, array(
            'headers' => array('Authorization' => 'Bearer ' . $access_token)
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['items'])) {
            return false;
        }
        
        // Get trainer ID
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if (!$trainer_id) {
            return false;
        }
        
        // Clear existing Google-synced blocks
        $wpdb->delete(
            $wpdb->prefix . 'ptp_availability_blocks',
            array('trainer_id' => $trainer_id, 'block_type' => 'google_busy')
        );
        
        // Insert busy times as unavailable blocks
        foreach ($body['items'] as $event) {
            if (isset($event['start']['dateTime']) && isset($event['end']['dateTime'])) {
                $start = new DateTime($event['start']['dateTime']);
                $end = new DateTime($event['end']['dateTime']);
                
                $wpdb->insert(
                    $wpdb->prefix . 'ptp_availability_blocks',
                    array(
                        'trainer_id' => $trainer_id,
                        'specific_date' => $start->format('Y-m-d'),
                        'start_time' => $start->format('H:i:s'),
                        'end_time' => $end->format('H:i:s'),
                        'block_type' => 'google_busy',
                        'is_recurring' => 0,
                        'created_at' => current_time('mysql')
                    )
                );
            }
        }
        
        // Update last sync time
        $wpdb->update(
            $wpdb->prefix . 'ptp_calendar_connections',
            array('last_sync' => current_time('mysql')),
            array('id' => $connection->id)
        );
        
        return true;
    }
    
    /**
     * Sync all connected calendars (cron)
     */
    public function sync_all_calendars() {
        global $wpdb;
        
        $connections = $wpdb->get_col(
            "SELECT user_id FROM {$wpdb->prefix}ptp_calendar_connections 
             WHERE provider = 'google' AND sync_enabled = 1"
        );
        
        foreach ($connections as $user_id) {
            $this->sync_user_calendar($user_id);
        }
    }
    
    /**
     * Create event in Google Calendar
     */
    public function create_event($user_id, $event_data) {
        global $wpdb;
        
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_calendar_connections 
             WHERE user_id = %d AND provider = 'google'",
            $user_id
        ));
        
        if (!$connection || !$connection->calendar_id) {
            return false;
        }
        
        $access_token = $this->get_user_tokens($user_id);
        if (!$access_token) {
            return false;
        }
        
        $url = sprintf(
            'https://www.googleapis.com/calendar/v3/calendars/%s/events',
            urlencode($connection->calendar_id)
        );
        
        $event = array(
            'summary' => $event_data['title'],
            'description' => $event_data['description'] ?? '',
            'start' => array(
                'dateTime' => $event_data['start'],
                'timeZone' => wp_timezone_string()
            ),
            'end' => array(
                'dateTime' => $event_data['end'],
                'timeZone' => wp_timezone_string()
            )
        );
        
        if (!empty($event_data['location'])) {
            $event['location'] = $event_data['location'];
        }
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($event)
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return $body['id'] ?? false;
    }
}

// Initialize
PTP_Google_Calendar_V71::instance();
