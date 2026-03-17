<?php
/**
 * PTP Google Web Login - OAuth 2.0 Flow for Website
 * Handles "Continue with Google" button on login/register pages
 * 
 * @version 1.0.0
 * @since 128.0.0
 */

defined('ABSPATH') || exit;

class PTP_Google_Web_Login {
    
    private static $instance = null;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function init() {
        self::instance();
    }
    
    private function __construct() {
        // Handle /login/google/ and /register/google/ routes - use parse_request to catch early
        add_action('parse_request', array($this, 'handle_google_routes'), 1);
        
        // Handle OAuth callback
        add_action('wp_ajax_ptp_google_login_callback', array($this, 'handle_callback'));
        add_action('wp_ajax_nopriv_ptp_google_login_callback', array($this, 'handle_callback'));
        
        // Add settings fields
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register admin settings
     */
    public function register_settings() {
        register_setting('ptp_settings', 'ptp_google_client_id');
        register_setting('ptp_settings', 'ptp_google_client_secret');
    }
    
    /**
     * Check if Google login is configured
     */
    public static function is_configured() {
        $client_id = get_option('ptp_google_client_id', '');
        $client_secret = get_option('ptp_google_client_secret', '');
        return !empty($client_id) && !empty($client_secret);
    }
    
    /**
     * Get the OAuth callback URL
     */
    public static function get_callback_url() {
        return admin_url('admin-ajax.php?action=ptp_google_login_callback');
    }
    
    /**
     * Handle /login/google/ and /register/google/ routes
     * Hooked into parse_request to intercept before WordPress 404s
     */
    public function handle_google_routes($wp = null) {
        // Safety check - ensure WordPress is ready
        if (!function_exists('home_url')) {
            return;
        }
        
        // Get the request path without query string
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (empty($request_uri)) {
            return;
        }
        
        $path = parse_url($request_uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            return;
        }
        
        $path = rtrim($path, '/'); // Normalize trailing slash
        
        // Handle WordPress in subdirectory
        $home_path = parse_url(home_url(), PHP_URL_PATH) ?: '';
        $home_path = rtrim($home_path, '/');
        
        // Remove home path prefix if WordPress is in subdirectory
        if ($home_path && strpos($path, $home_path) === 0) {
            $path = substr($path, strlen($home_path));
        }
        
        // Check for Google login/register routes
        if ($path === '/login/google' || $path === '/register/google') {
            $this->start_oauth_flow();
            exit;
        }
    }
    
    /**
     * Start the OAuth flow - redirect to Google
     */
    private function start_oauth_flow() {
        if (!self::is_configured()) {
            wp_redirect(home_url('/login/?error=google_not_configured'));
            exit;
        }
        
        $client_id = get_option('ptp_google_client_id');
        $redirect_uri = self::get_callback_url();
        
        // Generate state for CSRF protection
        $state = wp_generate_password(32, false);
        
        // Store state and redirect info in transient
        $redirect_to = isset($_GET['redirect_to']) ? esc_url($_GET['redirect_to']) : '';
        $is_register = strpos($_SERVER['REQUEST_URI'], '/register/') !== false;
        
        set_transient('ptp_google_oauth_' . $state, array(
            'redirect_to' => $redirect_to,
            'is_register' => $is_register,
            'timestamp' => time(),
        ), 600); // 10 minutes
        
        // Build Google OAuth URL
        $params = array(
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account', // Always show account picker
        );
        
        $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
        
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Handle OAuth callback from Google
     */
    public function handle_callback() {
        // Check for error from Google
        if (isset($_GET['error'])) {
            $error = sanitize_text_field($_GET['error']);
            ptp_log('PTP Google Login: OAuth error - ' . $error);
            wp_redirect(home_url('/login/?error=google_denied'));
            exit;
        }
        
        // Verify state
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        $stored_data = get_transient('ptp_google_oauth_' . $state);
        
        if (!$stored_data) {
            ptp_log('PTP Google Login: Invalid or expired state');
            wp_redirect(home_url('/login/?error=google_expired'));
            exit;
        }
        
        delete_transient('ptp_google_oauth_' . $state);
        
        // Get authorization code
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        
        if (empty($code)) {
            wp_redirect(home_url('/login/?error=google_no_code'));
            exit;
        }
        
        // Exchange code for tokens
        $tokens = $this->exchange_code_for_tokens($code);
        
        if (is_wp_error($tokens)) {
            ptp_log('PTP Google Login: Token exchange failed - ' . $tokens->get_error_message());
            wp_redirect(home_url('/login/?error=google_token_failed'));
            exit;
        }
        
        // Get user info from Google
        $google_user = $this->get_google_user_info($tokens['access_token']);
        
        if (is_wp_error($google_user)) {
            ptp_log('PTP Google Login: Failed to get user info - ' . $google_user->get_error_message());
            wp_redirect(home_url('/login/?error=google_user_failed'));
            exit;
        }
        
        // Find or create WordPress user
        $user = $this->find_or_create_user($google_user);
        
        if (is_wp_error($user)) {
            ptp_log('PTP Google Login: User creation failed - ' . $user->get_error_message());
            wp_redirect(home_url('/login/?error=' . urlencode($user->get_error_code())));
            exit;
        }
        
        // Log the user in
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true); // Remember me = true
        do_action('wp_login', $user->user_login, $user);
        
        // Determine redirect URL
        $redirect_url = $stored_data['redirect_to'] ?: '';
        
        if (empty($redirect_url)) {
            // Check if trainer or parent
            if (class_exists('PTP_Trainer')) {
                $trainer = PTP_Trainer::get_by_user_id($user->ID);
                if ($trainer) {
                    $redirect_url = home_url('/trainer-dashboard/');
                }
            }
            
            if (empty($redirect_url)) {
                $redirect_url = home_url('/parent-dashboard/');
            }
        }
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Exchange authorization code for tokens
     */
    private function exchange_code_for_tokens($code) {
        $client_id = get_option('ptp_google_client_id');
        $client_secret = get_option('ptp_google_client_secret');
        $redirect_uri = self::get_callback_url();
        
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body' => array(
                'code' => $code,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code',
            ),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('token_error', $body['error_description'] ?? $body['error']);
        }
        
        return $body;
    }
    
    /**
     * Get user info from Google
     */
    private function get_google_user_info($access_token) {
        $response = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('userinfo_error', $body['error']['message'] ?? 'Failed to get user info');
        }
        
        return $body;
    }
    
    /**
     * Find existing user or create new one
     */
    private function find_or_create_user($google_user) {
        global $wpdb;
        
        $google_id = $google_user['id'];
        $email = $google_user['email'];
        $name = $google_user['name'] ?? '';
        $given_name = $google_user['given_name'] ?? '';
        $family_name = $google_user['family_name'] ?? '';
        $picture = $google_user['picture'] ?? '';
        $verified_email = $google_user['verified_email'] ?? false;
        
        if (!$verified_email) {
            return new WP_Error('email_not_verified', 'Your Google email is not verified');
        }
        
        // Check if user exists by Google ID
        $users = get_users(array(
            'meta_key' => 'ptp_google_id',
            'meta_value' => $google_id,
            'number' => 1,
        ));
        
        if (!empty($users)) {
            $user = $users[0];
            
            // Update avatar if changed
            if (!empty($picture)) {
                update_user_meta($user->ID, 'ptp_avatar_url', $picture);
            }
            
            return $user;
        }
        
        // Check if user exists by email
        $user = get_user_by('email', $email);
        
        if ($user) {
            // Link Google account to existing user
            update_user_meta($user->ID, 'ptp_google_id', $google_id);
            
            if (!empty($picture)) {
                update_user_meta($user->ID, 'ptp_avatar_url', $picture);
            }
            
            return $user;
        }
        
        // Create new user
        $username = $this->generate_username($email, $name);
        $password = wp_generate_password(24, true, true);
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Update user profile
        wp_update_user(array(
            'ID' => $user_id,
            'first_name' => $given_name,
            'last_name' => $family_name,
            'display_name' => $name ?: $given_name,
        ));
        
        // Store Google ID and avatar
        update_user_meta($user_id, 'ptp_google_id', $google_id);
        update_user_meta($user_id, 'ptp_email_verified', true);
        
        if (!empty($picture)) {
            update_user_meta($user_id, 'ptp_avatar_url', $picture);
        }
        
        // Set role as parent by default (ensure role exists)
        $user = get_user_by('ID', $user_id);
        if (get_role('ptp_parent')) {
            $user->set_role('ptp_parent');
        } else {
            $user->set_role('subscriber'); // Fallback
        }
        
        // Create parent record (check table exists first)
        $table_name = $wpdb->prefix . 'ptp_parents';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
        
        if ($table_exists) {
            $display_name = $name ?: $given_name;
            $wpdb->insert($table_name, array(
                'user_id' => $user_id,
                'display_name' => $display_name,
                'email' => $email,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ));
        }
        
        // Fire action for new user
        do_action('ptp_google_user_created', $user_id, $google_user);
        
        return $user;
    }
    
    /**
     * Generate unique username
     */
    private function generate_username($email, $display_name) {
        // Try display name first
        if (!empty($display_name)) {
            $base = sanitize_user(strtolower(str_replace(' ', '', $display_name)));
            if (strlen($base) >= 3 && !username_exists($base)) {
                return $base;
            }
        }
        
        // Try email prefix
        $base = sanitize_user(strtolower(explode('@', $email)[0]));
        if (!username_exists($base)) {
            return $base;
        }
        
        // Add number suffix
        $i = 1;
        while (username_exists($base . $i)) {
            $i++;
        }
        
        return $base . $i;
    }
    
    /**
     * Render Google login button (helper for templates)
     */
    public static function render_button($text = 'Continue with Google', $class = '') {
        if (!self::is_configured()) {
            return '';
        }
        
        $redirect_to = isset($_GET['redirect_to']) ? '?redirect_to=' . urlencode($_GET['redirect_to']) : '';
        $url = home_url('/login/google/' . $redirect_to);
        
        ob_start();
        ?>
        <a href="<?php echo esc_url($url); ?>" class="ptp-google-btn <?php echo esc_attr($class); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            <span><?php echo esc_html($text); ?></span>
        </a>
        <?php
        return ob_get_clean();
    }
}

// Delay initialization until WordPress is ready
add_action('plugins_loaded', array('PTP_Google_Web_Login', 'init'), 20);
