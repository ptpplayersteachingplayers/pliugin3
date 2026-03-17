<?php
/**
 * PTP Social Login - Google and Apple Sign-In for Mobile App
 * 
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_Social_Login {
    
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }
    
    public static function register_routes() {
        $ns = 'ptp/v1';
        
        // Google Sign-In
        register_rest_route($ns, '/auth/google', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'google_login'),
            'permission_callback' => '__return_true',
        ));
        
        // Apple Sign-In
        register_rest_route($ns, '/auth/apple', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'apple_login'),
            'permission_callback' => '__return_true',
        ));
        
        // Check email availability
        register_rest_route($ns, '/auth/check-email', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'check_email'),
            'permission_callback' => '__return_true',
        ));
        
        // Link social account to existing user
        register_rest_route($ns, '/auth/link-social', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'link_social'),
            'permission_callback' => array('PTP_REST_API', 'check_auth'),
        ));
    }
    
    /**
     * Google Sign-In
     */
    public static function google_login($request) {
        $id_token = sanitize_text_field($request->get_param('id_token'));
        $role = sanitize_text_field($request->get_param('role')) ?: 'parent';
        
        if (empty($id_token)) {
            return new WP_Error('missing_token', 'Google ID token required', array('status' => 400));
        }
        
        // Verify token with Google
        $google_client_id = get_option('ptp_google_client_id');
        if (empty($google_client_id)) {
            return new WP_Error('not_configured', 'Google Sign-In not configured', array('status' => 400));
        }
        
        $verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $id_token;
        $response = wp_remote_get($verify_url, array('timeout' => 10));
        
        if (is_wp_error($response)) {
            return new WP_Error('verification_failed', 'Could not verify Google token', array('status' => 400));
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('invalid_token', $body['error_description'] ?? 'Invalid Google token', array('status' => 400));
        }
        
        // Verify audience
        if ($body['aud'] !== $google_client_id) {
            return new WP_Error('invalid_audience', 'Token not intended for this app', array('status' => 400));
        }
        
        $google_id = $body['sub'];
        $email = $body['email'];
        $name = $body['name'] ?? '';
        $given_name = $body['given_name'] ?? '';
        $family_name = $body['family_name'] ?? '';
        $picture = $body['picture'] ?? '';
        $email_verified = $body['email_verified'] ?? false;
        
        if (!$email_verified) {
            return new WP_Error('email_not_verified', 'Email not verified with Google', array('status' => 400));
        }
        
        return self::process_social_login(array(
            'provider' => 'google',
            'provider_id' => $google_id,
            'email' => $email,
            'first_name' => $given_name,
            'last_name' => $family_name,
            'display_name' => $name,
            'avatar_url' => $picture,
            'role' => $role,
        ));
    }
    
    /**
     * Apple Sign-In
     */
    public static function apple_login($request) {
        $id_token = sanitize_text_field($request->get_param('id_token'));
        $authorization_code = sanitize_text_field($request->get_param('authorization_code'));
        $first_name = sanitize_text_field($request->get_param('first_name'));
        $last_name = sanitize_text_field($request->get_param('last_name'));
        $role = sanitize_text_field($request->get_param('role')) ?: 'parent';
        
        if (empty($id_token)) {
            return new WP_Error('missing_token', 'Apple ID token required', array('status' => 400));
        }
        
        // Decode and verify Apple token
        $token_parts = explode('.', $id_token);
        if (count($token_parts) !== 3) {
            return new WP_Error('invalid_token', 'Invalid Apple token format', array('status' => 400));
        }
        
        $payload = json_decode(base64_decode($token_parts[1]), true);
        
        if (!$payload) {
            return new WP_Error('invalid_token', 'Could not decode Apple token', array('status' => 400));
        }
        
        // Verify expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return new WP_Error('token_expired', 'Apple token expired', array('status' => 400));
        }
        
        // Verify audience
        $apple_client_id = get_option('ptp_apple_client_id');
        if (!empty($apple_client_id) && isset($payload['aud']) && $payload['aud'] !== $apple_client_id) {
            return new WP_Error('invalid_audience', 'Token not intended for this app', array('status' => 400));
        }
        
        $apple_id = $payload['sub'];
        $email = $payload['email'] ?? '';
        
        // Apple may not provide name on subsequent logins
        // Use stored values if available
        if (empty($first_name) || empty($last_name)) {
            $existing = self::get_user_by_provider('apple', $apple_id);
            if ($existing) {
                $first_name = $first_name ?: get_user_meta($existing->ID, 'first_name', true);
                $last_name = $last_name ?: get_user_meta($existing->ID, 'last_name', true);
            }
        }
        
        return self::process_social_login(array(
            'provider' => 'apple',
            'provider_id' => $apple_id,
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => trim("$first_name $last_name"),
            'avatar_url' => '',
            'role' => $role,
        ));
    }
    
    /**
     * Process social login (create or login user)
     */
    private static function process_social_login($data) {
        global $wpdb;
        
        $provider = $data['provider'];
        $provider_id = $data['provider_id'];
        $email = $data['email'];
        $role = $data['role'];
        
        // Check if user exists by provider ID
        $existing_user = self::get_user_by_provider($provider, $provider_id);
        
        if ($existing_user) {
            // User exists - login
            return self::generate_login_response($existing_user);
        }
        
        // Check if user exists by email
        $user_by_email = get_user_by('email', $email);
        
        if ($user_by_email) {
            // Link social account to existing user
            update_user_meta($user_by_email->ID, "ptp_{$provider}_id", $provider_id);
            
            if (!empty($data['avatar_url'])) {
                update_user_meta($user_by_email->ID, 'ptp_avatar_url', $data['avatar_url']);
            }
            
            return self::generate_login_response($user_by_email);
        }
        
        // Create new user
        $username = self::generate_username($email, $data['display_name']);
        $password = wp_generate_password(24);
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Set user data
        wp_update_user(array(
            'ID' => $user_id,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'display_name' => $data['display_name'] ?: $data['first_name'],
        ));
        
        // Set provider ID
        update_user_meta($user_id, "ptp_{$provider}_id", $provider_id);
        
        // Set avatar
        if (!empty($data['avatar_url'])) {
            update_user_meta($user_id, 'ptp_avatar_url', $data['avatar_url']);
        }
        
        // Mark email as verified
        update_user_meta($user_id, 'ptp_email_verified', true);
        
        // Set role
        $user = get_user_by('ID', $user_id);
        
        if ($role === 'trainer') {
            $user->set_role('ptp_trainer');
            $needs_onboarding = true;
        } else {
            $user->set_role('ptp_parent');
            
            // Create parent record
            $display_name = $data['display_name'] ?: $data['first_name'];
            $wpdb->insert($wpdb->prefix . 'ptp_parents', array(
                'user_id' => $user_id,
                'display_name' => $display_name,
            ));
            
            $needs_onboarding = false;
        }
        
        return self::generate_login_response($user, true, $needs_onboarding);
    }
    
    /**
     * Generate login response with JWT token
     */
    private static function generate_login_response($user, $is_new = false, $needs_onboarding = false) {
        // Generate JWT token using the existing REST API method
        $token = self::generate_jwt($user->ID);
        
        // Get user data
        $user_data = PTP_REST_API::format_user_response($user);
        $user_data['needs_onboarding'] = $needs_onboarding;
        $user_data['is_new_user'] = $is_new;
        
        return rest_ensure_response(array(
            'token' => $token,
            'user' => $user_data,
        ));
    }
    
    /**
     * Generate JWT token
     */
    private static function generate_jwt($user_id) {
        $secret = get_option('ptp_jwt_secret');
        $expiry = time() + (7 * DAY_IN_SECONDS);
        
        $header = base64_encode(json_encode(array(
            'typ' => 'JWT',
            'alg' => 'HS256',
        )));
        
        $payload = base64_encode(json_encode(array(
            'iss' => get_site_url(),
            'iat' => time(),
            'exp' => $expiry,
            'user_id' => $user_id,
        )));
        
        $signature = hash_hmac('sha256', "$header.$payload", $secret);
        
        return "$header.$payload.$signature";
    }
    
    /**
     * Get user by social provider ID
     */
    private static function get_user_by_provider($provider, $provider_id) {
        $users = get_users(array(
            'meta_key' => "ptp_{$provider}_id",
            'meta_value' => $provider_id,
            'number' => 1,
        ));
        
        return !empty($users) ? $users[0] : null;
    }
    
    /**
     * Generate unique username
     */
    private static function generate_username($email, $display_name) {
        // Try display name first
        if (!empty($display_name)) {
            $base = sanitize_user(strtolower(str_replace(' ', '', $display_name)));
            if (!username_exists($base)) {
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
     * Check email availability
     */
    public static function check_email($request) {
        $email = sanitize_email($request->get_param('email'));
        
        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email format', array('status' => 400));
        }
        
        $exists = email_exists($email);
        
        $response = array(
            'available' => !$exists,
            'email' => $email,
        );
        
        if ($exists) {
            // Check which providers are linked
            $user = get_user_by('email', $email);
            $response['has_google'] = !empty(get_user_meta($user->ID, 'ptp_google_id', true));
            $response['has_apple'] = !empty(get_user_meta($user->ID, 'ptp_apple_id', true));
            $response['has_password'] = !empty($user->user_pass);
        }
        
        return rest_ensure_response($response);
    }
    
    /**
     * Link social account to existing user
     */
    public static function link_social($request) {
        $auth = PTP_REST_API::get_auth_user($request);
        $provider = sanitize_text_field($request->get_param('provider'));
        $id_token = sanitize_text_field($request->get_param('id_token'));
        
        if (!in_array($provider, array('google', 'apple'))) {
            return new WP_Error('invalid_provider', 'Invalid provider', array('status' => 400));
        }
        
        // Verify token and get provider ID
        if ($provider === 'google') {
            $google_client_id = get_option('ptp_google_client_id');
            $verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $id_token;
            $response = wp_remote_get($verify_url);
            
            if (is_wp_error($response)) {
                return new WP_Error('verification_failed', 'Could not verify token', array('status' => 400));
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['error']) || $body['aud'] !== $google_client_id) {
                return new WP_Error('invalid_token', 'Invalid Google token', array('status' => 400));
            }
            
            $provider_id = $body['sub'];
            
        } else {
            // Apple token verification
            $token_parts = explode('.', $id_token);
            if (count($token_parts) !== 3) {
                return new WP_Error('invalid_token', 'Invalid Apple token', array('status' => 400));
            }
            
            $payload = json_decode(base64_decode($token_parts[1]), true);
            $provider_id = $payload['sub'];
        }
        
        // Check if this provider ID is already linked to another user
        $existing = self::get_user_by_provider($provider, $provider_id);
        if ($existing && $existing->ID !== $auth['user_id']) {
            return new WP_Error('already_linked', 'This account is linked to another user', array('status' => 400));
        }
        
        // Link the account
        update_user_meta($auth['user_id'], "ptp_{$provider}_id", $provider_id);
        
        return rest_ensure_response(array(
            'message' => ucfirst($provider) . ' account linked successfully',
            'provider' => $provider,
        ));
    }
}

// Initialize
PTP_Social_Login::init();
