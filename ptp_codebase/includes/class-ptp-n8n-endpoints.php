<?php
/**
 * PTP N8N Integration Endpoints
 * 
 * Adds REST API endpoints specifically for n8n workflow automation
 * 
 * @version 1.0.0
 * @author PTP Development
 * 
 * INSTALLATION:
 * 1. Upload this file to: wp-content/plugins/ptp-training-platform/includes/
 * 2. Add this line to ptp-training-platform.php after other includes:
 *    require_once PTP_PLUGIN_DIR . 'includes/class-ptp-n8n-endpoints.php';
 * 3. Or add to functions.php:
 *    require_once WP_PLUGIN_DIR . '/ptp-training-platform/includes/class-ptp-n8n-endpoints.php';
 */

defined('ABSPATH') || exit;

class PTP_N8N_Endpoints {
    
    const NAMESPACE = 'ptp/v1';
    
    /**
     * Initialize the endpoints
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }
    
    /**
     * Register REST routes for n8n
     */
    public static function register_routes() {
        
        // Get completed bookings from yesterday (for review requests)
        register_rest_route(self::NAMESPACE, '/bookings/completed-yesterday', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_completed_yesterday'),
            'permission_callback' => array(__CLASS__, 'check_api_key'),
        ));
        
        // Get completed bookings for a date range
        register_rest_route(self::NAMESPACE, '/bookings/completed', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_completed_bookings'),
            'permission_callback' => array(__CLASS__, 'check_api_key'),
        ));
        
        // Get upcoming sessions (for reminders)
        register_rest_route(self::NAMESPACE, '/bookings/upcoming', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_upcoming_bookings'),
            'permission_callback' => array(__CLASS__, 'check_api_key'),
        ));
        
        // Get sessions happening today
        register_rest_route(self::NAMESPACE, '/bookings/today', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_todays_bookings'),
            'permission_callback' => array(__CLASS__, 'check_api_key'),
        ));
        
        // Get sessions for a specific date
        register_rest_route(self::NAMESPACE, '/bookings/date/(?P<date>\d{4}-\d{2}-\d{2})', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_bookings_by_date'),
            'permission_callback' => array(__CLASS__, 'check_api_key'),
        ));
        
        // Webhook endpoint for session completion (alternative trigger)
        register_rest_route(self::NAMESPACE, '/webhooks/session-completed', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'webhook_session_completed'),
            'permission_callback' => array(__CLASS__, 'check_webhook_secret'),
        ));
    }
    
    /**
     * Check API key authentication
     * Accepts: ?api_key=XXX or Authorization: Bearer XXX header
     */
    public static function check_api_key($request) {
        // Get the API key from settings
        $valid_key = get_option('ptp_n8n_api_key');
        
        // If no key is set, generate one and save it
        if (empty($valid_key)) {
            $valid_key = wp_generate_password(32, false);
            update_option('ptp_n8n_api_key', $valid_key);
            
            // SECURITY: Never log secrets, even in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ptp_log('PTP N8N API Key generated. Retrieve from wp_options table (ptp_n8n_api_key).');
            }
        }
        
        // Check query param
        $provided_key = $request->get_param('api_key');
        
        // Check Authorization header
        if (empty($provided_key)) {
            $auth_header = $request->get_header('Authorization');
            if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
                $provided_key = substr($auth_header, 7);
            }
        }
        
        // Also allow PTP Native consumer key/secret for easier integration
        $consumer_key = $request->get_param('consumer_key');
        if ($consumer_key && false) {
            // Validate against PTP Native API keys
            global $wpdb;
            $key = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp-native_api_keys WHERE consumer_key = %s",
                hash('sha256', $consumer_key)
            ));
            // SECURITY: Only allow read_write permission keys for n8n endpoints
            if ($key && $key->permissions === 'read_write') {
                return true;
            }
        }
        
        return hash_equals($valid_key, $provided_key ?? '');
    }
    
    /**
     * Check webhook secret for incoming webhooks
     */
    public static function check_webhook_secret($request) {
        $secret = get_option('ptp_webhook_secret', '');
        if (empty($secret)) {
            $secret = wp_generate_password(32, false);
            update_option('ptp_webhook_secret', $secret);
        }
        
        $provided = $request->get_header('X-Webhook-Secret');
        return hash_equals($secret, $provided ?? '');
    }
    
    /**
     * Get completed bookings from yesterday
     * Used by: Review Request workflow
     */
    public static function get_completed_yesterday($request) {
        global $wpdb;
        
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT 
                b.id,
                b.booking_number,
                b.session_date,
                b.start_time,
                b.end_time,
                b.duration_minutes,
                b.location,
                b.total_amount,
                b.status,
                b.trainer_confirmed,
                b.parent_confirmed,
                b.created_at,
                b.updated_at,
                
                -- Trainer info
                t.id as trainer_id,
                t.display_name as trainer_name,
                t.slug as trainer_slug,
                t.photo_url as trainer_photo,
                t.phone as trainer_phone,
                tu.user_email as trainer_email,
                
                -- Parent info
                p.id as parent_id,
                p.display_name as parent_name,
                p.phone as parent_phone,
                pu.user_email as parent_email,
                
                -- Player info
                pl.id as player_id,
                pl.name as player_name,
                pl.age as player_age
                
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->users} tu ON t.user_id = tu.ID
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->users} pu ON p.user_id = pu.ID
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE b.session_date = %s
            AND b.status = 'completed'
            ORDER BY b.start_time ASC
        ", $yesterday));
        
        if (empty($bookings)) {
            return rest_ensure_response(array());
        }
        
        // Check if review already exists for each booking
        foreach ($bookings as &$booking) {
            $review = $wpdb->get_row($wpdb->prepare(
                "SELECT id, rating FROM {$wpdb->prefix}ptp_reviews WHERE booking_id = %d",
                $booking->id
            ));
            $booking->has_review = !empty($review);
            $booking->review_rating = $review->rating ?? null;
            
            // Build trainer profile URL
            $booking->trainer_profile_url = home_url('/trainer/' . $booking->trainer_slug . '/');
            
            // Build review URL (trainer profile with review anchor)
            $booking->review_url = home_url('/trainer/' . $booking->trainer_slug . '/#reviews');
        }
        
        return rest_ensure_response($bookings);
    }
    
    /**
     * Get completed bookings for a date range
     */
    public static function get_completed_bookings($request) {
        global $wpdb;
        
        $start_date = $request->get_param('start_date') ?: date('Y-m-d', strtotime('-7 days'));
        $end_date = $request->get_param('end_date') ?: date('Y-m-d');
        $trainer_id = $request->get_param('trainer_id');
        
        $where = "b.session_date BETWEEN %s AND %s AND b.status = 'completed'";
        $params = array($start_date, $end_date);
        
        if ($trainer_id) {
            $where .= " AND b.trainer_id = %d";
            $params[] = intval($trainer_id);
        }
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT 
                b.*,
                t.display_name as trainer_name,
                t.slug as trainer_slug,
                p.display_name as parent_name,
                p.phone as parent_phone,
                pu.user_email as parent_email,
                pl.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->users} pu ON p.user_id = pu.ID
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE $where
            ORDER BY b.session_date DESC, b.start_time DESC
        ", $params));
        
        return rest_ensure_response($bookings);
    }
    
    /**
     * Get upcoming bookings (for reminders)
     */
    public static function get_upcoming_bookings($request) {
        global $wpdb;
        
        $days_ahead = intval($request->get_param('days') ?: 2);
        $target_date = date('Y-m-d', strtotime("+{$days_ahead} days"));
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT 
                b.*,
                t.display_name as trainer_name,
                t.slug as trainer_slug,
                t.phone as trainer_phone,
                tu.user_email as trainer_email,
                p.display_name as parent_name,
                p.phone as parent_phone,
                pu.user_email as parent_email,
                pl.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->users} tu ON t.user_id = tu.ID
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->users} pu ON p.user_id = pu.ID
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE b.session_date = %s
            AND b.status IN ('confirmed', 'pending')
            ORDER BY b.start_time ASC
        ", $target_date));
        
        return rest_ensure_response($bookings);
    }
    
    /**
     * Get today's bookings
     */
    public static function get_todays_bookings($request) {
        global $wpdb;
        
        $today = date('Y-m-d');
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT 
                b.*,
                t.display_name as trainer_name,
                t.phone as trainer_phone,
                p.display_name as parent_name,
                p.phone as parent_phone,
                pl.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE b.session_date = %s
            AND b.status IN ('confirmed', 'pending')
            ORDER BY b.start_time ASC
        ", $today));
        
        return rest_ensure_response($bookings);
    }
    
    /**
     * Get bookings for a specific date
     */
    public static function get_bookings_by_date($request) {
        global $wpdb;
        
        $date = $request->get_param('date');
        $status = $request->get_param('status'); // Optional filter
        
        $where = "b.session_date = %s";
        $params = array($date);
        
        if ($status) {
            $where .= " AND b.status = %s";
            $params[] = $status;
        }
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT 
                b.*,
                t.display_name as trainer_name,
                t.slug as trainer_slug,
                p.display_name as parent_name,
                p.phone as parent_phone,
                pu.user_email as parent_email,
                pl.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
            LEFT JOIN {$wpdb->users} pu ON p.user_id = pu.ID
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            WHERE $where
            ORDER BY b.start_time ASC
        ", $params));
        
        return rest_ensure_response($bookings);
    }
    
    /**
     * Webhook handler for session completion
     * Can be called by n8n or other services when a session is marked complete
     */
    public static function webhook_session_completed($request) {
        $booking_id = $request->get_param('booking_id');
        
        if (!$booking_id) {
            return new WP_Error('missing_booking_id', 'Booking ID is required', array('status' => 400));
        }
        
        $booking = PTP_Booking::get_full($booking_id);
        
        if (!$booking) {
            return new WP_Error('booking_not_found', 'Booking not found', array('status' => 404));
        }
        
        // Return booking data for n8n to use
        return rest_ensure_response(array(
            'success' => true,
            'booking' => array(
                'id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'session_date' => $booking->session_date,
                'start_time' => $booking->start_time,
                'status' => $booking->status,
                'trainer_name' => $booking->trainer_name,
                'trainer_slug' => $booking->trainer_slug,
                'parent_name' => $booking->parent_name,
                'parent_user_id' => $booking->parent_user_id,
                'player_name' => $booking->player_name,
                'trainer_profile_url' => home_url('/trainer/' . $booking->trainer_slug . '/'),
            ),
        ));
    }
    
    /**
     * Admin page to show API keys (optional)
     */
    public static function admin_page() {
        $api_key = get_option('ptp_n8n_api_key', '');
        $webhook_secret = get_option('ptp_webhook_secret', '');
        
        ?>
        <div class="wrap">
            <h1>PTP N8N Integration</h1>
            
            <h2>API Credentials</h2>
            <table class="form-table">
                <tr>
                    <th>API Key</th>
                    <td>
                        <code style="background:#f5f5f5;padding:8px 12px;display:inline-block;font-size:14px;">
                            <?php echo esc_html($api_key); ?>
                        </code>
                        <p class="description">Use this in n8n HTTP Request nodes as: <code>?api_key=<?php echo esc_html($api_key); ?></code></p>
                    </td>
                </tr>
                <tr>
                    <th>Webhook Secret</th>
                    <td>
                        <code style="background:#f5f5f5;padding:8px 12px;display:inline-block;font-size:14px;">
                            <?php echo esc_html($webhook_secret); ?>
                        </code>
                        <p class="description">Use this as <code>X-Webhook-Secret</code> header for incoming webhooks</p>
                    </td>
                </tr>
            </table>
            
            <h2>Available Endpoints</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Method</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>/wp-json/ptp/v1/bookings/completed-yesterday</code></td>
                        <td>GET</td>
                        <td>Get all completed training sessions from yesterday (for review requests)</td>
                    </tr>
                    <tr>
                        <td><code>/wp-json/ptp/v1/bookings/completed</code></td>
                        <td>GET</td>
                        <td>Get completed bookings for date range. Params: start_date, end_date, trainer_id</td>
                    </tr>
                    <tr>
                        <td><code>/wp-json/ptp/v1/bookings/upcoming</code></td>
                        <td>GET</td>
                        <td>Get upcoming bookings. Params: days (default: 2)</td>
                    </tr>
                    <tr>
                        <td><code>/wp-json/ptp/v1/bookings/today</code></td>
                        <td>GET</td>
                        <td>Get all bookings scheduled for today</td>
                    </tr>
                    <tr>
                        <td><code>/wp-json/ptp/v1/bookings/date/{YYYY-MM-DD}</code></td>
                        <td>GET</td>
                        <td>Get bookings for a specific date. Params: status (optional)</td>
                    </tr>
                </tbody>
            </table>
            
            <h2>Example n8n HTTP Request</h2>
            <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;overflow-x:auto;">
{
  "method": "GET",
  "url": "<?php echo home_url('/wp-json/ptp/v1/bookings/completed-yesterday'); ?>",
  "authentication": "genericCredentialType",
  "genericAuthType": "httpQueryAuth",
  "sendQuery": true,
  "queryParameters": {
    "parameters": [
      {
        "name": "api_key",
        "value": "<?php echo esc_html($api_key); ?>"
      }
    ]
  }
}</pre>
        </div>
        <?php
    }
}

// Initialize
PTP_N8N_Endpoints::init();

// v223.1: Removed deprecated admin menu registration — optional feature disabled
