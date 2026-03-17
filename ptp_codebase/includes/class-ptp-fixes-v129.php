<?php
/**
 * PTP Fixes v129 - Comprehensive Update
 * 
 * Fixes:
 * 1. Find-trainers map now shows training locations (not home locations)
 * 2. SMS reminders properly wired for booking, reminder, and post-training follow-up
 * 3. Parent dashboard styling improvements (name display, session details)
 * 
 * @version 129.0.0
 */

defined('ABSPATH') || exit;

class PTP_Fixes_V129 {
    
    public static function init() {
        // v216.1: Removed duplicate SMS hooks — PTP_SMS::send_post_training_followup is the
        // canonical handler on ptp_session_completed. These were causing 4x SMS sends per session.
        
        // Schedule post-training follow-up (2hrs after session end — separate from immediate SMS)
        add_action('ptp_booking_confirmed', array(__CLASS__, 'schedule_post_training_followup'), 20, 1);
        
        // Add custom cron action
        add_action('ptp_post_training_followup', array(__CLASS__, 'send_post_training_followup_scheduled'));
        
        // Filter trainer data to include training_locations coords
        add_filter('ptp_trainer_data_for_map', array(__CLASS__, 'add_training_location_coords'), 10, 1);
        
        // Fix parent display name
        add_filter('ptp_parent_display_name', array(__CLASS__, 'fix_parent_display_name'), 10, 2);
    }
    
    /**
     * Send post-training SMS follow-up
     */
    public static function send_post_training_sms($booking_id, $booking = null) {
        if (!class_exists('PTP_SMS_V71') && !class_exists('PTP_SMS')) {
            return;
        }
        
        global $wpdb;
        
        if (!$booking) {
            $booking = $wpdb->get_row($wpdb->prepare("
                SELECT b.*, 
                       t.display_name as trainer_name, t.slug as trainer_slug,
                       p.name as player_name,
                       pa.phone as parent_phone, pa.display_name as parent_name
                FROM {$wpdb->prefix}ptp_bookings b
                LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
                LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
                WHERE b.id = %d
            ", $booking_id));
        }
        
        if (!$booking || !$booking->parent_phone) return;
        
        $parent_first = self::extract_first_name($booking->parent_name);
        $player_name = $booking->player_name ?: 'your player';
        $review_url = home_url('/trainer/' . $booking->trainer_slug . '/?review=' . $booking_id);
        
        $message = "Hi {$parent_first}! Hope {$player_name} had a great session with {$booking->trainer_name} today!\n\n";
        $message .= "Quick feedback helps other families find the right trainer:\n{$review_url}\n\n";
        $message .= "Thanks for choosing PTP!";
        
        $sms_class = class_exists('PTP_SMS') ? 'PTP_SMS' : 'PTP_SMS_V71';
        call_user_func(array($sms_class, 'send'), $booking->parent_phone, $message);
        
        // Log
        ptp_log("[PTP SMS v129] Post-training follow-up sent to {$booking->parent_phone} for booking #{$booking_id}");
    }
    
    /**
     * Schedule post-training follow-up for 2 hours after session end
     */
    public static function schedule_post_training_followup($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT session_date, start_time, duration FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking || !$booking->session_date || !$booking->start_time) {
            return;
        }
        
        // Calculate end time (start + duration + 2 hours)
        $duration = $booking->duration ?: 60; // Default 1 hour
        $session_start = strtotime($booking->session_date . ' ' . $booking->start_time);
        $followup_time = $session_start + ($duration * 60) + (2 * 60 * 60); // 2 hours after session ends
        
        // Only schedule if in the future
        if ($followup_time > time()) {
            wp_schedule_single_event($followup_time, 'ptp_post_training_followup', array($booking_id));
            ptp_log("[PTP v129] Scheduled post-training followup for booking #{$booking_id} at " . date('Y-m-d H:i:s', $followup_time));
        }
    }
    
    /**
     * Send scheduled post-training follow-up
     */
    public static function send_post_training_followup_scheduled($booking_id) {
        global $wpdb;
        
        // Check if booking is actually completed
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        // Only send if not already cancelled
        if ($status && $status !== 'cancelled') {
            // v216.1: Delegate to PTP_SMS (canonical handler) instead of self::send_post_training_sms
            if (class_exists('PTP_SMS') && method_exists('PTP_SMS', 'send_post_training_followup')) {
                PTP_SMS::send_post_training_followup($booking_id);
            } elseif (class_exists('PTP_SMS_V71') && method_exists('PTP_SMS_V71', 'send_post_training_followup')) {
                PTP_SMS_V71::send_post_training_followup($booking_id);
            } else {
                self::send_post_training_sms($booking_id);
            }
        }
    }
    
    /**
     * Add training location coordinates to trainer data for map
     */
    public static function add_training_location_coords($trainer) {
        if (empty($trainer->training_locations)) {
            return $trainer;
        }
        
        $locations = json_decode($trainer->training_locations, true);
        if (!is_array($locations) || empty($locations)) {
            return $trainer;
        }
        
        // Process each location to ensure it has coordinates
        $processed_locations = array();
        foreach ($locations as $loc) {
            if (!empty($loc['lat']) && !empty($loc['lng'])) {
                $processed_locations[] = $loc;
            } elseif (!empty($loc['address']) && class_exists('PTP_Geocoding')) {
                // Try to geocode
                $geo = PTP_Geocoding::geocode($loc['address']);
                if (!empty($geo['success'])) {
                    $loc['lat'] = $geo['latitude'];
                    $loc['lng'] = $geo['longitude'];
                    $processed_locations[] = $loc;
                }
            }
        }
        
        if (!empty($processed_locations)) {
            $trainer->training_locations_processed = $processed_locations;
            
            // Set primary location to first training location (not home)
            $trainer->map_lat = $processed_locations[0]['lat'];
            $trainer->map_lng = $processed_locations[0]['lng'];
        }
        
        return $trainer;
    }
    
    /**
     * Fix parent display name (don't show full email)
     */
    public static function fix_parent_display_name($name, $user = null) {
        return self::extract_display_name($name);
    }
    
    /**
     * Extract a proper display name from email or full name
     */
    public static function extract_display_name($name) {
        if (empty($name)) {
            return 'Parent';
        }
        
        // Check if it's an email
        if (filter_var($name, FILTER_VALIDATE_EMAIL)) {
            // Extract username part before @
            $username = explode('@', $name)[0];
            
            // Try to extract name from common email patterns
            // e.g., john.smith@... => John Smith
            // e.g., johnsmith123@... => Johnsmith
            
            // Replace common separators with spaces
            $cleaned = preg_replace('/[._-]/', ' ', $username);
            
            // Remove trailing numbers
            $cleaned = preg_replace('/\d+$/', '', $cleaned);
            
            // Capitalize each word
            $cleaned = ucwords(trim($cleaned));
            
            if (strlen($cleaned) > 1) {
                return $cleaned;
            }
            
            // Fallback to just the username portion
            return ucfirst($username);
        }
        
        return $name;
    }
    
    /**
     * Extract first name from full name or email
     */
    public static function extract_first_name($name) {
        $display_name = self::extract_display_name($name);
        $parts = explode(' ', $display_name);
        return $parts[0];
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_Fixes_V129', 'init'), 20);
