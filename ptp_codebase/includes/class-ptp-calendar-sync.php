<?php
/**
 * PTP Calendar Sync - BULLETPROOF v28.0.0
 * Google Calendar and Apple Calendar (ICS) integration for trainers
 * Features: OAuth2 flow, token refresh, booking sync, ICS feed
 */

defined('ABSPATH') || exit;

class PTP_Calendar_Sync {
    
    private static $google_client_id;
    private static $google_client_secret;
    private static $google_redirect_uri;
    private static $table_verified = false;
    
    /**
     * Initialize calendar sync
     */
    public static function init() {
        self::$google_client_id = get_option('ptp_google_client_id', '');
        self::$google_client_secret = get_option('ptp_google_client_secret', '');
        self::$google_redirect_uri = home_url('/ptp-calendar-callback/');
        
        // Ensure table exists
        add_action('admin_init', array(__CLASS__, 'ensure_table'), 5);
        
        // OAuth callback handler
        add_action('init', array(__CLASS__, 'handle_oauth_callback'));
        
        // AJAX endpoints
        add_action('wp_ajax_ptp_connect_google_calendar', array(__CLASS__, 'ajax_connect_google'));
        add_action('wp_ajax_ptp_disconnect_google_calendar', array(__CLASS__, 'ajax_disconnect_google'));
        add_action('wp_ajax_ptp_sync_calendar', array(__CLASS__, 'ajax_sync_calendar'));
        add_action('wp_ajax_ptp_get_calendar_status', array(__CLASS__, 'ajax_get_status'));
        
        // Sync on booking changes
        add_action('ptp_booking_created', array(__CLASS__, 'sync_booking_to_calendar'), 10, 1);
        add_action('ptp_booking_updated', array(__CLASS__, 'sync_booking_to_calendar'), 10, 1);
        add_action('ptp_booking_cancelled', array(__CLASS__, 'remove_booking_from_calendar'), 10, 1);
        
        // REST endpoint for ICS feed
        add_action('rest_api_init', array(__CLASS__, 'register_ics_endpoint'));
    }
    
    /**
     * Ensure calendar connections table exists
     */
    public static function ensure_table() {
        if (self::$table_verified) {
            return true;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ptp_calendar_connections';
        
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        
        if ($table_exists !== $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id bigint(20) UNSIGNED NOT NULL,
                provider enum('google','apple','outlook') NOT NULL DEFAULT 'google',
                access_token text,
                refresh_token text,
                token_expires datetime DEFAULT NULL,
                calendar_id varchar(255) DEFAULT 'primary',
                sync_enabled tinyint(1) DEFAULT 1,
                last_sync datetime DEFAULT NULL,
                ics_token varchar(64) DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY user_provider (user_id, provider),
                KEY ics_token (ics_token),
                KEY user_id (user_id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            ptp_log('PTP: Created calendar_connections table');
        }
        
        // Also ensure bookings table has calendar columns
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bookings_table'") === $bookings_table) {
            // Check if columns exist
            $columns = $wpdb->get_col("DESCRIBE $bookings_table");
            
            if (!in_array('google_event_id', $columns)) {
                $wpdb->query("ALTER TABLE $bookings_table ADD COLUMN google_event_id varchar(255) DEFAULT NULL");
            }
            
            if (!in_array('calendar_synced', $columns)) {
                $wpdb->query("ALTER TABLE $bookings_table ADD COLUMN calendar_synced tinyint(1) DEFAULT 0");
            }
        }
        
        self::$table_verified = true;
        return true;
    }
    
    /**
     * Backward-compatible create_tables method (called on plugin activation)
     */
    public static function create_tables() {
        self::ensure_table();
    }
    
    /**
     * Check if Google Calendar is configured
     */
    public static function is_google_configured() {
        return !empty(self::$google_client_id) && !empty(self::$google_client_secret);
    }
    
    /**
     * Get Google OAuth URL
     */
    public static function get_google_auth_url($user_id) {
        if (!self::is_google_configured()) {
            return false;
        }
        
        $state = wp_create_nonce('ptp_google_oauth_' . $user_id);
        set_transient('ptp_oauth_state_' . $state, $user_id, 600);
        
        $params = array(
            'client_id' => self::$google_client_id,
            'redirect_uri' => self::$google_redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.events',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        );
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    
    /**
     * Handle OAuth callback
     */
    public static function handle_oauth_callback() {
        if (!isset($_GET['code']) || strpos($_SERVER['REQUEST_URI'], 'ptp-calendar-callback') === false) {
            return;
        }
        
        $code = sanitize_text_field($_GET['code']);
        $state = sanitize_text_field($_GET['state'] ?? '');
        
        $user_id = get_transient('ptp_oauth_state_' . $state);
        if (!$user_id) {
            wp_die('Invalid or expired OAuth state. Please try again.');
        }
        
        delete_transient('ptp_oauth_state_' . $state);
        
        // Exchange code for tokens
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'code' => $code,
                'client_id' => self::$google_client_id,
                'client_secret' => self::$google_client_secret,
                'redirect_uri' => self::$google_redirect_uri,
                'grant_type' => 'authorization_code',
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('PTP Calendar OAuth Error: ' . $response->get_error_message());
            wp_die('Failed to connect to Google. Please try again.');
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            ptp_log('PTP Calendar OAuth Error: ' . ($body['error_description'] ?? $body['error']));
            wp_die('Google error: ' . esc_html($body['error_description'] ?? $body['error']));
        }
        
        if (empty($body['access_token'])) {
            wp_die('No access token received from Google');
        }
        
        // Save tokens
        self::ensure_table();
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_calendar_connections';
        
        $expires = new DateTime();
        $expires->modify('+' . intval($body['expires_in']) . ' seconds');
        
        // Generate ICS token
        $ics_token = wp_generate_password(32, false);
        
        // Use replace to handle existing connections
        $wpdb->replace($table, array(
            'user_id' => $user_id,
            'provider' => 'google',
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? '',
            'token_expires' => $expires->format('Y-m-d H:i:s'),
            'ics_token' => $ics_token,
            'sync_enabled' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ));
        
        // Redirect back to dashboard
        $redirect_url = home_url('/trainer-dashboard/?tab=schedule&calendar_connected=1');
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Refresh Google access token
     */
    private static function refresh_google_token($connection) {
        if (empty($connection->refresh_token)) {
            return false;
        }
        
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'client_id' => self::$google_client_id,
                'client_secret' => self::$google_client_secret,
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('PTP Calendar Token Refresh Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error']) || empty($body['access_token'])) {
            ptp_log('PTP Calendar Token Refresh Failed: ' . ($body['error_description'] ?? 'Unknown error'));
            return false;
        }
        
        global $wpdb;
        $expires = new DateTime();
        $expires->modify('+' . intval($body['expires_in']) . ' seconds');
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_calendar_connections',
            array(
                'access_token' => $body['access_token'],
                'token_expires' => $expires->format('Y-m-d H:i:s'),
                'updated_at' => current_time('mysql')
            ),
            array('id' => $connection->id)
        );
        
        $connection->access_token = $body['access_token'];
        return $connection;
    }
    
    /**
     * Get valid access token for user
     */
    public static function get_valid_token($user_id, $provider = 'google') {
        self::ensure_table();
        
        global $wpdb;
        
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_calendar_connections 
             WHERE user_id = %d AND provider = %s",
            $user_id, $provider
        ));
        
        if (!$connection) {
            return false;
        }
        
        // Check if token is expired or about to expire (5 min buffer)
        $expires = strtotime($connection->token_expires);
        if ($expires < (time() + 300)) {
            $connection = self::refresh_google_token($connection);
            if (!$connection) {
                return false;
            }
        }
        
        return $connection->access_token;
    }
    
    /**
     * Sync a booking to Google Calendar
     */
    public static function sync_booking_to_calendar($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.user_id as trainer_user_id, t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            return false;
        }
        
        $token = self::get_valid_token($booking->trainer_user_id);
        if (!$token) {
            return false; // No calendar connected
        }
        
        // Build event data
        $start_datetime = $booking->session_date . 'T' . $booking->start_time;
        $end_datetime = $booking->session_date . 'T' . $booking->end_time;
        
        $event = array(
            'summary' => 'Training Session: ' . ($booking->player_name ?: 'TBD'),
            'description' => sprintf(
                "Player: %s\nParent: %s\nBooking #: %s\nStatus: %s",
                $booking->player_name ?: 'TBD',
                $booking->parent_name ?? 'N/A',
                $booking->booking_number ?? $booking_id,
                ucfirst($booking->status ?? 'pending')
            ),
            'start' => array(
                'dateTime' => date('c', strtotime($start_datetime)),
                'timeZone' => wp_timezone_string()
            ),
            'end' => array(
                'dateTime' => date('c', strtotime($end_datetime)),
                'timeZone' => wp_timezone_string()
            ),
            'status' => ($booking->status === 'cancelled') ? 'cancelled' : 'confirmed'
        );
        
        if (!empty($booking->location)) {
            $event['location'] = $booking->location;
        }
        
        // Check if event already exists
        $event_id = $booking->google_event_id ?? null;
        
        if ($event_id) {
            // Update existing event
            $response = wp_remote_request('https://www.googleapis.com/calendar/v3/calendars/primary/events/' . $event_id, array(
                'method' => 'PUT',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($event),
                'timeout' => 30
            ));
        } else {
            // Create new event
            $response = wp_remote_post('https://www.googleapis.com/calendar/v3/calendars/primary/events', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($event),
                'timeout' => 30
            ));
        }
        
        if (is_wp_error($response)) {
            ptp_log('PTP Calendar Sync Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['id'])) {
            // Save event ID to booking
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'google_event_id' => $body['id'],
                    'calendar_synced' => 1
                ),
                array('id' => $booking_id)
            );
            
            // Update last sync time
            $wpdb->update(
                $wpdb->prefix . 'ptp_calendar_connections',
                array('last_sync' => current_time('mysql')),
                array('user_id' => $booking->trainer_user_id, 'provider' => 'google')
            );
            
            return true;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log('PTP Calendar Sync Failed: ' . (isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error'));
        }
        return false;
    }
    
    /**
     * Remove booking from Google Calendar
     */
    public static function remove_booking_from_calendar($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.user_id as trainer_user_id
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking || empty($booking->google_event_id)) {
            return false;
        }
        
        $token = self::get_valid_token($booking->trainer_user_id);
        if (!$token) {
            return false;
        }
        
        // Delete event
        $response = wp_remote_request('https://www.googleapis.com/calendar/v3/calendars/primary/events/' . $booking->google_event_id, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token
            ),
            'timeout' => 30
        ));
        
        if (!is_wp_error($response)) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('google_event_id' => null, 'calendar_synced' => 0),
                array('id' => $booking_id)
            );
            return true;
        }
        
        return false;
    }
    
    /**
     * Generate ICS feed for trainer
     */
    public static function generate_ics($trainer_id) {
        global $wpdb;
        
        // Get trainer
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return '';
        }
        
        // Get upcoming bookings
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, p.name as player_name_from_player
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             WHERE b.trainer_id = %d 
             AND b.session_date >= CURDATE()
             AND b.status NOT IN ('cancelled', 'refunded')
             ORDER BY b.session_date, b.start_time",
            $trainer_id
        ));
        
        // Build ICS
        $tz = wp_timezone_string() ?: 'America/New_York';
        
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//PTP Training//Calendar//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "X-WR-CALNAME:PTP Training - " . self::ics_escape($trainer->display_name) . "\r\n";
        $ics .= "X-WR-TIMEZONE:" . $tz . "\r\n";
        
        foreach ($bookings as $booking) {
            $player_name = $booking->player_name ?: $booking->player_name_from_player ?: 'Training Session';
            $start = new DateTime($booking->session_date . ' ' . $booking->start_time, new DateTimeZone($tz));
            $end = new DateTime($booking->session_date . ' ' . $booking->end_time, new DateTimeZone($tz));
            $created = new DateTime($booking->created_at ?: 'now', new DateTimeZone($tz));
            
            $uid = ($booking->booking_number ?: 'booking-' . $booking->id) . '@ptpsummercamps.com';
            
            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= "UID:" . $uid . "\r\n";
            $ics .= "DTSTART:" . $start->format('Ymd\THis') . "\r\n";
            $ics .= "DTEND:" . $end->format('Ymd\THis') . "\r\n";
            $ics .= "DTSTAMP:" . $created->format('Ymd\THis') . "Z\r\n";
            $ics .= "SUMMARY:Training: " . self::ics_escape($player_name) . "\r\n";
            
            $desc = "Player: " . $player_name;
            if (!empty($booking->parent_name)) {
                $desc .= "\\nParent: " . $booking->parent_name;
            }
            $desc .= "\\nBooking: " . ($booking->booking_number ?: $booking->id);
            $ics .= "DESCRIPTION:" . self::ics_escape($desc) . "\r\n";
            
            if (!empty($booking->location)) {
                $ics .= "LOCATION:" . self::ics_escape($booking->location) . "\r\n";
            }
            
            $ics .= "STATUS:" . ($booking->status === 'confirmed' ? 'CONFIRMED' : 'TENTATIVE') . "\r\n";
            $ics .= "END:VEVENT\r\n";
        }
        
        $ics .= "END:VCALENDAR\r\n";
        
        return $ics;
    }
    
    /**
     * Escape string for ICS format
     */
    private static function ics_escape($string) {
        return str_replace(
            array('\\', ';', ',', "\n", "\r"),
            array('\\\\', '\\;', '\\,', '\\n', ''),
            $string
        );
    }
    
    /**
     * Register ICS feed REST endpoint
     */
    public static function register_ics_endpoint() {
        register_rest_route('ptp/v1', '/calendar/(?P<token>[a-zA-Z0-9]+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'serve_ics_feed'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * Serve ICS feed
     */
    public static function serve_ics_feed($request) {
        self::ensure_table();
        
        global $wpdb;
        
        $token = $request->get_param('token');
        
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, t.id as trainer_id 
             FROM {$wpdb->prefix}ptp_calendar_connections c
             JOIN {$wpdb->prefix}ptp_trainers t ON c.user_id = t.user_id
             WHERE c.ics_token = %s",
            $token
        ));
        
        if (!$connection) {
            return new WP_Error('invalid_token', 'Invalid calendar token', array('status' => 404));
        }
        
        $ics = self::generate_ics($connection->trainer_id);
        
        $response = new WP_REST_Response($ics);
        $response->set_headers(array(
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="ptp-training.ics"',
            'Cache-Control' => 'no-cache, must-revalidate',
        ));
        
        return $response;
    }
    
    /**
     * Get ICS subscription URL for trainer
     */
    public static function get_ics_url($user_id) {
        self::ensure_table();
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_calendar_connections';
        
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT ics_token FROM $table WHERE user_id = %d",
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
                    'provider' => 'apple',
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
     * Get trainer's calendar connection status
     */
    public static function get_connection_status($user_id) {
        self::ensure_table();
        
        global $wpdb;
        
        $connections = $wpdb->get_results($wpdb->prepare(
            "SELECT provider, last_sync, sync_enabled, token_expires 
             FROM {$wpdb->prefix}ptp_calendar_connections 
             WHERE user_id = %d",
            $user_id
        ));
        
        $status = array(
            'google' => array(
                'connected' => false,
                'last_sync' => null,
                'sync_enabled' => false,
                'needs_reauth' => false
            ),
            'ics_url' => self::get_ics_url($user_id),
            'google_configured' => self::is_google_configured()
        );
        
        foreach ($connections as $conn) {
            if ($conn->provider === 'google') {
                $expired = !empty($conn->token_expires) && strtotime($conn->token_expires) < time();
                $status['google'] = array(
                    'connected' => true,
                    'last_sync' => $conn->last_sync,
                    'sync_enabled' => (bool) $conn->sync_enabled,
                    'needs_reauth' => $expired
                );
            }
        }
        
        return $status;
    }
    
    /**
     * AJAX: Connect Google Calendar
     */
    public static function ajax_connect_google() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        if (!self::is_google_configured()) {
            wp_send_json_error(array('message' => 'Google Calendar is not configured. Please contact support.'));
            return;
        }
        
        $auth_url = self::get_google_auth_url(get_current_user_id());
        
        if ($auth_url) {
            wp_send_json_success(array('auth_url' => $auth_url));
        } else {
            wp_send_json_error(array('message' => 'Failed to generate auth URL'));
        }
    }
    
    /**
     * AJAX: Disconnect Google Calendar
     */
    public static function ajax_disconnect_google() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'ptp_calendar_connections',
            array('user_id' => get_current_user_id(), 'provider' => 'google')
        );
        
        wp_send_json_success(array('message' => 'Google Calendar disconnected'));
    }
    
    /**
     * AJAX: Sync all bookings
     */
    public static function ajax_sync_calendar() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in() || !class_exists('PTP_Trainer')) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        global $wpdb;
        
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date >= CURDATE() AND status NOT IN ('cancelled', 'refunded')",
            $trainer->id
        ));
        
        $synced = 0;
        $failed = 0;
        
        foreach ($bookings as $booking) {
            if (self::sync_booking_to_calendar($booking->id)) {
                $synced++;
            } else {
                $failed++;
            }
        }
        
        wp_send_json_success(array(
            'synced' => $synced,
            'failed' => $failed,
            'message' => sprintf('%d bookings synced', $synced)
        ));
    }
    
    /**
     * AJAX: Get calendar status
     */
    public static function ajax_get_status() {
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $status = self::get_connection_status(get_current_user_id());
        
        wp_send_json_success($status);
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_Calendar_Sync', 'init'), 25);
