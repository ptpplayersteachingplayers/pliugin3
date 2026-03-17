<?php
/**
 * User Class - Base user functionality
 */

defined('ABSPATH') || exit;

class PTP_User {
    
    public static function get_user_type($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return false;
        }
        
        global $wpdb;
        
        // Check if trainer
        $trainer = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if ($trainer) {
            return 'trainer';
        }
        
        // Check if parent
        $parent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if ($parent) {
            return 'parent';
        }
        
        return 'none';
    }
    
    public static function is_trainer($user_id = null) {
        return self::get_user_type($user_id) === 'trainer';
    }
    
    public static function is_parent($user_id = null) {
        return self::get_user_type($user_id) === 'parent';
    }
    
    public static function get_dashboard_url($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        // Check if admin first
        if ($user_id && user_can($user_id, 'manage_options')) {
            return admin_url();
        }
        
        $type = self::get_user_type($user_id);
        
        if ($type === 'trainer') {
            // Check if trainer has completed onboarding
            global $wpdb;
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT photo_url, bio, hourly_rate FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
                $user_id
            ));
            
            // If trainer hasn't completed basic profile (photo + bio), send to onboarding
            if ($trainer && (empty($trainer->photo_url) || empty($trainer->bio))) {
                return home_url('/trainer-onboarding/');
            }
            
            return home_url('/trainer-dashboard/');
        } elseif ($type === 'parent') {
            return home_url('/parent-dashboard/');
        }
        
        // For users who aren't trainer/parent yet, send to account page or home
        return home_url('/account/');
    }
    
    public static function create_user($email, $password, $data = array()) {
        $username = sanitize_user(current(explode('@', $email)), true);
        $username = self::generate_unique_username($username);
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Update user data
        $update_data = array('ID' => $user_id);
        
        if (!empty($data['first_name'])) {
            $update_data['first_name'] = sanitize_text_field($data['first_name']);
        }
        if (!empty($data['last_name'])) {
            $update_data['last_name'] = sanitize_text_field($data['last_name']);
        }
        if (!empty($data['display_name'])) {
            $update_data['display_name'] = sanitize_text_field($data['display_name']);
        }
        
        wp_update_user($update_data);
        
        // Store phone if provided
        if (!empty($data['phone'])) {
            update_user_meta($user_id, 'phone', sanitize_text_field($data['phone']));
        }
        
        return $user_id;
    }
    
    private static function generate_unique_username($base_username) {
        $username = $base_username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    public static function login_user($user_id) {
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        do_action('wp_login', get_userdata($user_id)->user_login, get_userdata($user_id));
    }
}
