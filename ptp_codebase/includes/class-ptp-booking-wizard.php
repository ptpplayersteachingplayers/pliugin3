<?php
/**
 * PTP Booking Wizard - TeachMe.to Style 4-Step Funnel
 * 
 * Version 48.0.0
 * 
 * Flow:
 * Step 1: Location Selection (map, distance, free badges)
 * Step 2: Time Slot Selection (calendar, suggested badges)
 * Step 3: Package Selection (subscription vs one-time, frequency)
 * Step 4: Checkout (payment, trust badges, testimonials)
 * 
 * Cross-selling:
 * - Similar trainers when no availability
 * - Package upsells
 * - Gift cards
 * - Lead capture
 */

defined('ABSPATH') || exit;

class PTP_Booking_Wizard {
    
    const SLOT_DURATION = 30; // minutes
    const MAX_FUTURE_DAYS = 60;
    const SIMILAR_TRAINERS_COUNT = 4;
    
    public static function init() {
        // AJAX handlers
        add_action('wp_ajax_ptp_wizard_get_locations', array(__CLASS__, 'ajax_get_locations'));
        add_action('wp_ajax_nopriv_ptp_wizard_get_locations', array(__CLASS__, 'ajax_get_locations'));
        
        add_action('wp_ajax_ptp_wizard_get_slots', array(__CLASS__, 'ajax_get_slots'));
        add_action('wp_ajax_nopriv_ptp_wizard_get_slots', array(__CLASS__, 'ajax_get_slots'));
        
        add_action('wp_ajax_ptp_wizard_get_packages', array(__CLASS__, 'ajax_get_packages'));
        add_action('wp_ajax_nopriv_ptp_wizard_get_packages', array(__CLASS__, 'ajax_get_packages'));
        
        add_action('wp_ajax_ptp_wizard_check_availability', array(__CLASS__, 'ajax_check_availability'));
        add_action('wp_ajax_nopriv_ptp_wizard_check_availability', array(__CLASS__, 'ajax_check_availability'));
        
        add_action('wp_ajax_ptp_wizard_get_similar_trainers', array(__CLASS__, 'ajax_get_similar_trainers'));
        add_action('wp_ajax_nopriv_ptp_wizard_get_similar_trainers', array(__CLASS__, 'ajax_get_similar_trainers'));
        
        add_action('wp_ajax_ptp_wizard_submit_lead', array(__CLASS__, 'ajax_submit_lead'));
        add_action('wp_ajax_nopriv_ptp_wizard_submit_lead', array(__CLASS__, 'ajax_submit_lead'));
        
        add_action('wp_ajax_ptp_wizard_process_booking', array(__CLASS__, 'ajax_process_booking'));
        add_action('wp_ajax_nopriv_ptp_wizard_process_booking', array(__CLASS__, 'ajax_process_booking'));
        
        // Shortcode
        add_shortcode('ptp_booking_wizard', array(__CLASS__, 'render_wizard'));
    }
    
    /**
     * Get trainer's training locations with distance calculation
     */
    public static function get_locations($trainer_id, $user_lat = null, $user_lng = null) {
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) return array();
        
        $locations = array();
        
        // Parse training locations from JSON
        if (!empty($trainer->training_locations)) {
            $decoded = json_decode($trainer->training_locations, true);
            // v213: Fallback for WP magic quotes corrupted JSON
            if (!is_array($decoded)) $decoded = json_decode(wp_unslash($trainer->training_locations), true);
            if (is_array($decoded)) {
                foreach ($decoded as $idx => $loc) {
                    if (is_array($loc) && !empty($loc['name'])) {
                        $location = array(
                            'id' => $loc['id'] ?? 'loc_' . $idx,
                            'name' => sanitize_text_field($loc['name']),
                            'address' => sanitize_text_field($loc['address'] ?? $loc['name']),
                            'lat' => floatval($loc['lat'] ?? 0),
                            'lng' => floatval($loc['lng'] ?? 0),
                            'is_free' => !empty($loc['is_free']),
                            'type' => sanitize_text_field($loc['type'] ?? 'park'),
                            'photo' => esc_url($loc['photo'] ?? ''),
                            'amenities' => $loc['amenities'] ?? array(),
                            'distance' => null,
                            'distance_text' => ''
                        );
                        
                        // Calculate distance if user coords provided
                        if ($user_lat && $user_lng && $location['lat'] && $location['lng']) {
                            $distance = self::calculate_distance(
                                $user_lat, $user_lng,
                                $location['lat'], $location['lng']
                            );
                            $location['distance'] = round($distance, 1);
                            $location['distance_text'] = $distance < 1 
                                ? 'Less than 1 mile' 
                                : number_format($distance, 1) . ' miles';
                        }
                        
                        $locations[] = $location;
                    }
                }
            }
        }
        
        // Fallback to trainer's main location
        if (empty($locations)) {
            $locations[] = array(
                'id' => 'default',
                'name' => $trainer->city && $trainer->state 
                    ? $trainer->city . ', ' . $trainer->state 
                    : ($trainer->location ?: 'Training Location'),
                'address' => $trainer->location ?: '',
                'lat' => floatval($trainer->latitude),
                'lng' => floatval($trainer->longitude),
                'is_free' => false,
                'type' => 'default',
                'photo' => '',
                'amenities' => array(),
                'distance' => null,
                'distance_text' => ''
            );
        }
        
        // Sort by distance if calculated
        if ($user_lat && $user_lng) {
            usort($locations, function($a, $b) {
                if ($a['distance'] === null) return 1;
                if ($b['distance'] === null) return -1;
                return $a['distance'] <=> $b['distance'];
            });
        }
        
        return $locations;
    }
    
    /**
     * Get available time slots for a trainer on a specific date
     */
    public static function get_slots($trainer_id, $date, $location_id = null) {
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) return array();
        
        // Parse date
        $date_ts = strtotime($date);
        if (!$date_ts || $date_ts < strtotime('today')) {
            return array();
        }
        
        $day_of_week = date('w', $date_ts); // 0=Sunday
        
        // Get trainer's availability for this day
        $availability = PTP_Availability::get_day($trainer_id, $day_of_week);
        if (!$availability || !$availability->is_active) {
            return array();
        }
        
        // Check for blocked dates
        if (PTP_Availability::is_date_blocked($trainer_id, $date)) {
            return array();
        }
        
        // Get existing bookings for this date
        $existing_bookings = PTP_Booking::get_trainer_bookings_for_date($trainer_id, $date);
        $booked_times = array();
        foreach ($existing_bookings as $booking) {
            // Block the booking slot plus buffer
            $start = strtotime($booking->start_time);
            $end = strtotime($booking->end_time);
            for ($t = $start; $t < $end; $t += self::SLOT_DURATION * 60) {
                $booked_times[] = date('H:i', $t);
            }
        }
        
        // Generate available slots
        $slots = array();
        $start = strtotime($availability->start_time);
        $end = strtotime($availability->end_time);
        $lesson_length = intval($trainer->lesson_lengths ?: 60);
        
        // Calculate peak hours (typically 3-7pm for youth sports)
        $peak_start = strtotime('15:00');
        $peak_end = strtotime('19:00');
        
        // If booking today, skip past times
        if (date('Y-m-d', $date_ts) === date('Y-m-d')) {
            $now = strtotime('+1 hour'); // Buffer for last-minute bookings
            if ($now > $start) {
                $start = ceil($now / (self::SLOT_DURATION * 60)) * (self::SLOT_DURATION * 60);
            }
        }
        
        for ($time = $start; $time < $end - ($lesson_length * 60) + 60; $time += self::SLOT_DURATION * 60) {
            $time_str = date('H:i', $time);
            
            if (in_array($time_str, $booked_times)) {
                continue; // Already booked
            }
            
            // Check if full lesson duration is available
            $slot_available = true;
            for ($check = $time; $check < $time + ($lesson_length * 60); $check += self::SLOT_DURATION * 60) {
                if (in_array(date('H:i', $check), $booked_times)) {
                    $slot_available = false;
                    break;
                }
            }
            
            if (!$slot_available) continue;
            
            $is_peak = ($time >= $peak_start && $time < $peak_end);
            $is_suggested = false;
            
            // Mark certain slots as suggested (peak hours with good availability)
            $remaining_peak_slots = 0;
            for ($t = $time; $t < $end; $t += self::SLOT_DURATION * 60) {
                if ($t >= $peak_start && $t < $peak_end && !in_array(date('H:i', $t), $booked_times)) {
                    $remaining_peak_slots++;
                }
            }
            
            // Suggest peak slots when they're becoming scarce
            if ($is_peak && $remaining_peak_slots <= 4) {
                $is_suggested = true;
            }
            
            $slots[] = array(
                'time' => $time_str,
                'display' => date('g:i A', $time),
                'is_peak' => $is_peak,
                'is_suggested' => $is_suggested,
                'available' => true
            );
        }
        
        return $slots;
    }
    
    /**
     * Get available dates for calendar display
     */
    public static function get_available_dates($trainer_id, $month = null, $year = null) {
        $month = $month ?: date('n');
        $year = $year ?: date('Y');
        
        $available_dates = array();
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) return $available_dates;
        
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        
        for ($day = 1; $day <= $days_in_month; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $date_ts = strtotime($date);
            
            // Skip past dates
            if ($date_ts < strtotime('today')) continue;
            
            // Skip dates too far in future
            if ($date_ts > strtotime('+' . self::MAX_FUTURE_DAYS . ' days')) continue;
            
            $day_of_week = date('w', $date_ts);
            
            // Check if trainer has availability this day
            $availability = PTP_Availability::get_day($trainer_id, $day_of_week);
            if (!$availability || !$availability->is_active) continue;
            
            // Check for blocked dates
            if (PTP_Availability::is_date_blocked($trainer_id, $date)) continue;
            
            // Get slots for this date
            $slots = self::get_slots($trainer_id, $date);
            if (empty($slots)) continue;
            
            $available_dates[$date] = array(
                'date' => $date,
                'day' => $day,
                'day_of_week' => $day_of_week,
                'slots_count' => count($slots),
                'has_peak_slots' => !empty(array_filter($slots, fn($s) => $s['is_peak'])),
                'has_suggested' => !empty(array_filter($slots, fn($s) => $s['is_suggested']))
            );
        }
        
        return $available_dates;
    }
    
    /**
     * Get package options for a trainer
     */
    public static function get_packages($trainer_id, $options = array()) {
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) return array();
        
        $base_rate = floatval($trainer->hourly_rate ?: 80);
        $lesson_length = intval($trainer->lesson_lengths ?: 60);
        $max_participants = intval($trainer->max_participants ?: 4);
        
        $frequency = intval($options['frequency'] ?? 1);
        $participants = intval($options['participants'] ?? 1);
        $duration = intval($options['duration'] ?? $lesson_length);
        
        // Duration multiplier
        $duration_multiplier = $duration / 60;
        
        $packages = array(
            // Subscription packages (default/prominent)
            'subscription' => array(
                'type' => 'subscription',
                'name' => 'Weekly Training',
                'description' => 'Consistent weekly sessions for maximum improvement',
                'is_default' => true,
                'has_trial' => true,
                'trial_text' => 'First session FREE',
                'billing' => 'Billed weekly after trial',
                'options' => array(
                    array(
                        'frequency' => 1,
                        'frequency_label' => '1x per week',
                        'price_weekly' => round($base_rate * $duration_multiplier * $participants, 2),
                        'price_display' => '$' . round($base_rate * $duration_multiplier * $participants),
                        'per_session' => round($base_rate * $duration_multiplier, 2),
                        'savings' => 0,
                        'savings_text' => '',
                        'popular' => true
                    ),
                    array(
                        'frequency' => 2,
                        'frequency_label' => '2x per week',
                        'price_weekly' => round($base_rate * $duration_multiplier * 2 * 0.95 * $participants, 2),
                        'price_display' => '$' . round($base_rate * $duration_multiplier * 2 * 0.95 * $participants),
                        'per_session' => round($base_rate * $duration_multiplier * 0.95, 2),
                        'savings' => 5,
                        'savings_text' => 'Save 5%',
                        'popular' => false
                    ),
                    array(
                        'frequency' => 3,
                        'frequency_label' => '3x per week',
                        'price_weekly' => round($base_rate * $duration_multiplier * 3 * 0.90 * $participants, 2),
                        'price_display' => '$' . round($base_rate * $duration_multiplier * 3 * 0.90 * $participants),
                        'per_session' => round($base_rate * $duration_multiplier * 0.90, 2),
                        'savings' => 10,
                        'savings_text' => 'Save 10%',
                        'popular' => false
                    )
                )
            ),
            
            // One-time packages
            'one_time' => array(
                'type' => 'one_time',
                'name' => 'Lesson Packs',
                'description' => 'Pay once, schedule anytime',
                'is_default' => false,
                'has_trial' => false,
                'options' => array(
                    array(
                        'count' => 1,
                        'label' => 'Single Session',
                        'price' => round($base_rate * $duration_multiplier * $participants, 2),
                        'price_display' => '$' . round($base_rate * $duration_multiplier * $participants),
                        'per_session' => round($base_rate * $duration_multiplier, 2),
                        'savings' => 0,
                        'savings_text' => ''
                    ),
                    array(
                        'count' => 3,
                        'label' => '3-Pack',
                        'price' => round($base_rate * $duration_multiplier * 3 * 0.93 * $participants, 2),
                        'price_display' => '$' . round($base_rate * $duration_multiplier * 3 * 0.93 * $participants),
                        'per_session' => round($base_rate * $duration_multiplier * 0.93, 2),
                        'savings' => 7,
                        'savings_text' => 'Save 7%'
                    ),
                    array(
                        'count' => 5,
                        'label' => '5-Pack',
                        'price' => round($base_rate * $duration_multiplier * 5 * 0.90 * $participants, 2),
                        'price_display' => '$' . round($base_rate * $duration_multiplier * 5 * 0.90 * $participants),
                        'per_session' => round($base_rate * $duration_multiplier * 0.90, 2),
                        'savings' => 10,
                        'savings_text' => 'Save 10%',
                        'popular' => true
                    ),
                    array(
                        'count' => 10,
                        'label' => '10-Pack',
                        'price' => round($base_rate * $duration_multiplier * 10 * 0.85 * $participants, 2),
                        'price_display' => '$' . round($base_rate * $duration_multiplier * 10 * 0.85 * $participants),
                        'per_session' => round($base_rate * $duration_multiplier * 0.85, 2),
                        'savings' => 15,
                        'savings_text' => 'Save 15%'
                    )
                )
            )
        );
        
        // Duration options
        $durations = array(
            array('value' => 30, 'label' => '30 min', 'available' => true),
            array('value' => 60, 'label' => '60 min', 'available' => true, 'default' => true),
            array('value' => 90, 'label' => '90 min', 'available' => true),
        );
        
        // Participant options
        $participant_options = array();
        for ($i = 1; $i <= $max_participants; $i++) {
            $participant_options[] = array(
                'value' => $i,
                'label' => $i === 1 ? '1 Player' : $i . ' Players',
                'discount' => $i > 1 ? min(($i - 1) * 10, 30) : 0
            );
        }
        
        return array(
            'packages' => $packages,
            'durations' => $durations,
            'participants' => $participant_options,
            'base_rate' => $base_rate,
            'current_duration' => $duration,
            'current_participants' => $participants
        );
    }
    
    /**
     * Get similar trainers for cross-selling
     */
    public static function get_similar_trainers($trainer_id, $limit = null) {
        $limit = $limit ?: self::SIMILAR_TRAINERS_COUNT;
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) return array();
        
        global $wpdb;
        
        // Find trainers with similar:
        // - Location (within 15 miles)
        // - Specialties
        // - Rating
        // - Price range
        
        $similar = array();
        
        // Location-based query with distance
        if ($trainer->latitude && $trainer->longitude) {
            $results = $wpdb->get_results($wpdb->prepare("
                SELECT t.*,
                    (3959 * acos(cos(radians(%f)) * cos(radians(latitude)) * cos(radians(longitude) - radians(%f)) + sin(radians(%f)) * sin(radians(latitude)))) AS distance
                FROM {$wpdb->prefix}ptp_trainers t
                WHERE t.id != %d
                AND t.status = 'active'
                AND t.latitude IS NOT NULL
                AND t.longitude IS NOT NULL
                HAVING distance < 20
                ORDER BY 
                    CASE WHEN t.is_featured = 1 THEN 0 ELSE 1 END,
                    t.average_rating DESC,
                    distance ASC
                LIMIT %d
            ", $trainer->latitude, $trainer->longitude, $trainer->latitude, $trainer_id, $limit));
            
            foreach ($results as $row) {
                $similar[] = array(
                    'id' => $row->id,
                    'slug' => $row->slug,
                    'name' => $row->display_name,
                    'photo' => $row->photo_url ?: PTP_Images::avatar($row->display_name, 200),
                    'headline' => $row->headline ?: ($row->college ?: ''),
                    'rating' => number_format(floatval($row->average_rating ?: 5), 1),
                    'review_count' => intval($row->review_count),
                    'hourly_rate' => intval($row->hourly_rate ?: 80),
                    'distance' => round($row->distance, 1),
                    'is_featured' => (bool) $row->is_featured,
                    'has_availability' => class_exists('PTP_Availability') ? (bool) PTP_Availability::get_weekly($row->id) : true
                );
            }
        }
        
        return $similar;
    }
    
    /**
     * Calculate distance between two points (Haversine formula)
     */
    private static function calculate_distance($lat1, $lng1, $lat2, $lng2) {
        $earth_radius = 3959; // miles
        
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $lng1 = deg2rad($lng1);
        $lng2 = deg2rad($lng2);
        
        $dlat = $lat2 - $lat1;
        $dlng = $lng2 - $lng1;
        
        $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlng / 2) ** 2;
        $c = 2 * asin(sqrt($a));
        
        return $earth_radius * $c;
    }
    
    // ==========================================
    // AJAX HANDLERS
    // ==========================================
    
    public static function ajax_get_locations() {
        $trainer_id = intval($_REQUEST['trainer_id'] ?? 0);
        $user_lat = floatval($_REQUEST['lat'] ?? 0);
        $user_lng = floatval($_REQUEST['lng'] ?? 0);
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer'));
            return;
        }
        
        $locations = self::get_locations($trainer_id, $user_lat ?: null, $user_lng ?: null);
        
        wp_send_json_success(array(
            'locations' => $locations,
            'count' => count($locations)
        ));
    }
    
    public static function ajax_get_slots() {
        $trainer_id = intval($_REQUEST['trainer_id'] ?? 0);
        $date = sanitize_text_field($_REQUEST['date'] ?? '');
        $location_id = sanitize_text_field($_REQUEST['location_id'] ?? '');
        $month = intval($_REQUEST['month'] ?? 0);
        $year = intval($_REQUEST['year'] ?? 0);
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer'));
            return;
        }
        
        $response = array();
        
        // Get available dates for calendar
        if ($month && $year) {
            $response['dates'] = self::get_available_dates($trainer_id, $month, $year);
        }
        
        // Get slots for specific date
        if ($date) {
            $response['slots'] = self::get_slots($trainer_id, $date, $location_id);
            $response['date'] = $date;
        }
        
        wp_send_json_success($response);
    }
    
    public static function ajax_get_packages() {
        $trainer_id = intval($_REQUEST['trainer_id'] ?? 0);
        $frequency = intval($_REQUEST['frequency'] ?? 1);
        $participants = intval($_REQUEST['participants'] ?? 1);
        $duration = intval($_REQUEST['duration'] ?? 60);
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer'));
            return;
        }
        
        $packages = self::get_packages($trainer_id, array(
            'frequency' => $frequency,
            'participants' => $participants,
            'duration' => $duration
        ));
        
        wp_send_json_success($packages);
    }
    
    public static function ajax_check_availability() {
        $trainer_id = intval($_REQUEST['trainer_id'] ?? 0);
        $date = sanitize_text_field($_REQUEST['date'] ?? '');
        $time = sanitize_text_field($_REQUEST['time'] ?? '');
        
        if (!$trainer_id || !$date || !$time) {
            wp_send_json_error(array('message' => 'Missing parameters'));
            return;
        }
        
        // Check if slot is still available
        $slots = self::get_slots($trainer_id, $date);
        $available = false;
        
        foreach ($slots as $slot) {
            if ($slot['time'] === $time && $slot['available']) {
                $available = true;
                break;
            }
        }
        
        wp_send_json_success(array(
            'available' => $available,
            'date' => $date,
            'time' => $time
        ));
    }
    
    public static function ajax_get_similar_trainers() {
        $trainer_id = intval($_REQUEST['trainer_id'] ?? 0);
        $limit = intval($_REQUEST['limit'] ?? self::SIMILAR_TRAINERS_COUNT);
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer'));
            return;
        }
        
        $similar = self::get_similar_trainers($trainer_id, $limit);
        
        wp_send_json_success(array(
            'trainers' => $similar,
            'count' => count($similar)
        ));
    }
    
    public static function ajax_submit_lead() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        
        if (!$trainer_id || !$email) {
            wp_send_json_error(array('message' => 'Please provide your email'));
            return;
        }
        
        // Store lead in database
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'ptp_leads',
            array(
                'trainer_id' => $trainer_id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'message' => $message,
                'source' => 'booking_wizard',
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        // Send notification to trainer
        $trainer = PTP_Trainer::get($trainer_id);
        if ($trainer && $trainer->email) {
            $subject = 'New Inquiry from ' . ($name ?: $email);
            $body = "You have a new inquiry:\n\n";
            $body .= "Name: " . ($name ?: 'Not provided') . "\n";
            $body .= "Email: " . $email . "\n";
            $body .= "Phone: " . ($phone ?: 'Not provided') . "\n";
            $body .= "Message: " . ($message ?: 'No message') . "\n\n";
            $body .= "Reply to this inquiry via your dashboard or contact them directly.";
            
            wp_mail($trainer->email, $subject, $body);
        }
        
        wp_send_json_success(array(
            'message' => 'Thank you! We\'ll be in touch within 24 hours.',
            'avg_response' => '2 hours'
        ));
    }
    
    public static function ajax_process_booking() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_booking_wizard')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // This connects to the existing checkout process
        // Delegating to PTP_Ajax::process_checkout()
        $_POST['nonce'] = wp_create_nonce('ptp_checkout');
        PTP_Ajax::process_checkout();
    }
    
    /**
     * Render the booking wizard shortcode
     */
    public static function render_wizard($atts = array()) {
        $atts = shortcode_atts(array(
            'trainer_id' => 0,
            'trainer_slug' => '',
            'step' => 1
        ), $atts);
        
        // Get trainer
        $trainer = null;
        if ($atts['trainer_id']) {
            $trainer = PTP_Trainer::get($atts['trainer_id']);
        } elseif ($atts['trainer_slug']) {
            $trainer = PTP_Trainer::get_by_slug($atts['trainer_slug']);
        }
        
        if (!$trainer || $trainer->status !== 'active') {
            return '<div class="ptp-wizard-error">Trainer not found or unavailable.</div>';
        }
        
        // Load the template
        ob_start();
        $template = PTP_PLUGIN_DIR . 'templates/booking-wizard.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="ptp-wizard" style="padding: 40px; text-align: center;">
                <h2>Book with ' . esc_html($trainer->display_name) . '</h2>
                <p>Select a date and time on the trainer\'s profile to book a session.</p>
                <p><a href="' . home_url('/trainer/' . $trainer->slug . '/') . '" class="ptp-btn">View Profile →</a></p>
            </div>';
        }
        return ob_get_clean();
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_Booking_Wizard', 'init'), 25);
