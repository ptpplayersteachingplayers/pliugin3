<?php
/**
 * PTP Geocoding & Location Services
 * Handles ZIP code lookup, distance calculations, and location search
 */

defined('ABSPATH') || exit;

class PTP_Geocoding {
    
    private static $google_api_key;
    
    public static function init() {
        self::$google_api_key = get_option('ptp_google_maps_api_key', '') ?: get_option('ptp_google_maps_key', '');
        
        // AJAX handlers
        add_action('wp_ajax_ptp_geocode_address', array(__CLASS__, 'ajax_geocode_address'));
        add_action('wp_ajax_nopriv_ptp_geocode_address', array(__CLASS__, 'ajax_geocode_address'));
        add_action('wp_ajax_ptp_search_by_location', array(__CLASS__, 'ajax_search_by_location'));
        add_action('wp_ajax_nopriv_ptp_search_by_location', array(__CLASS__, 'ajax_search_by_location'));
    }
    
    /**
     * Check if geocoding is available
     */
    public static function is_available() {
        return !empty(self::$google_api_key);
    }
    
    /**
     * Geocode an address or ZIP code
     */
    public static function geocode($address) {
        if (!self::is_available()) {
            return self::geocode_zip_fallback($address);
        }
        
        $url = add_query_arg(array(
            'address' => urlencode($address),
            'key' => self::$google_api_key,
        ), 'https://maps.googleapis.com/maps/api/geocode/json');
        
        $response = wp_remote_get($url, array('timeout' => 10));
        
        if (is_wp_error($response)) {
            return self::geocode_zip_fallback($address);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($body['status'] !== 'OK' || empty($body['results'])) {
            return self::geocode_zip_fallback($address);
        }
        
        $result = $body['results'][0];
        $location = $result['geometry']['location'];
        
        // Extract address components
        $components = array();
        foreach ($result['address_components'] as $component) {
            foreach ($component['types'] as $type) {
                $components[$type] = $component['short_name'];
            }
        }
        
        return array(
            'success' => true,
            'latitude' => $location['lat'],
            'longitude' => $location['lng'],
            'formatted_address' => $result['formatted_address'],
            'city' => $components['locality'] ?? $components['sublocality'] ?? '',
            'state' => $components['administrative_area_level_1'] ?? '',
            'zip' => $components['postal_code'] ?? '',
            'country' => $components['country'] ?? 'US',
        );
    }
    
    /**
     * Fallback ZIP code geocoding using free database
     */
    private static function geocode_zip_fallback($input) {
        // Extract ZIP code from input
        preg_match('/\d{5}/', $input, $matches);
        
        if (empty($matches)) {
            return array('success' => false, 'error' => 'Invalid ZIP code');
        }
        
        $zip = $matches[0];
        
        // Try to get from cached data
        $zip_data = self::get_zip_data($zip);
        
        if (!$zip_data) {
            return array('success' => false, 'error' => 'ZIP code not found');
        }
        
        return array(
            'success' => true,
            'latitude' => $zip_data['latitude'],
            'longitude' => $zip_data['longitude'],
            'formatted_address' => "{$zip_data['city']}, {$zip_data['state']} {$zip}",
            'city' => $zip_data['city'],
            'state' => $zip_data['state'],
            'zip' => $zip,
            'country' => 'US',
        );
    }
    
    /**
     * Get ZIP code data from database
     */
    private static function get_zip_data($zip) {
        global $wpdb;
        
        // Check if we have a ZIP codes table
        $table = $wpdb->prefix . 'ptp_zip_codes';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
        
        if ($table_exists) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE zip = %s",
                $zip
            ), ARRAY_A);
        }
        
        // Fallback to hardcoded major ZIP codes
        $common_zips = self::get_common_zips();
        return $common_zips[$zip] ?? null;
    }
    
    /**
     * Common ZIP codes for fallback
     */
    private static function get_common_zips() {
        return array(
            // Pennsylvania
            '19019' => array('city' => 'Philadelphia', 'state' => 'PA', 'latitude' => 39.9526, 'longitude' => -75.1652),
            '19103' => array('city' => 'Philadelphia', 'state' => 'PA', 'latitude' => 39.9526, 'longitude' => -75.1652),
            '19087' => array('city' => 'Radnor', 'state' => 'PA', 'latitude' => 40.0462, 'longitude' => -75.3599),
            '19072' => array('city' => 'Narberth', 'state' => 'PA', 'latitude' => 40.0084, 'longitude' => -75.2607),
            '19041' => array('city' => 'Haverford', 'state' => 'PA', 'latitude' => 40.0101, 'longitude' => -75.3085),
            '19085' => array('city' => 'Villanova', 'state' => 'PA', 'latitude' => 40.0359, 'longitude' => -75.3435),
            '19010' => array('city' => 'Bryn Mawr', 'state' => 'PA', 'latitude' => 40.0218, 'longitude' => -75.3165),
            '19003' => array('city' => 'Ardmore', 'state' => 'PA', 'latitude' => 40.0062, 'longitude' => -75.2896),
            '19096' => array('city' => 'Wynnewood', 'state' => 'PA', 'latitude' => 39.9912, 'longitude' => -75.2774),
            '19312' => array('city' => 'Berwyn', 'state' => 'PA', 'latitude' => 40.0451, 'longitude' => -75.4385),
            // New Jersey
            '08034' => array('city' => 'Cherry Hill', 'state' => 'NJ', 'latitude' => 39.9346, 'longitude' => -75.0307),
            '08002' => array('city' => 'Cherry Hill', 'state' => 'NJ', 'latitude' => 39.9190, 'longitude' => -74.9946),
            '08043' => array('city' => 'Voorhees', 'state' => 'NJ', 'latitude' => 39.8454, 'longitude' => -74.9529),
            '08053' => array('city' => 'Marlton', 'state' => 'NJ', 'latitude' => 39.8912, 'longitude' => -74.9218),
            '08003' => array('city' => 'Cherry Hill', 'state' => 'NJ', 'latitude' => 39.8787, 'longitude' => -75.0146),
            // Delaware
            '19801' => array('city' => 'Wilmington', 'state' => 'DE', 'latitude' => 39.7459, 'longitude' => -75.5466),
            '19803' => array('city' => 'Wilmington', 'state' => 'DE', 'latitude' => 39.7996, 'longitude' => -75.5346),
            '19807' => array('city' => 'Wilmington', 'state' => 'DE', 'latitude' => 39.7971, 'longitude' => -75.5879),
            '19711' => array('city' => 'Newark', 'state' => 'DE', 'latitude' => 39.6837, 'longitude' => -75.7497),
            // Maryland
            '21201' => array('city' => 'Baltimore', 'state' => 'MD', 'latitude' => 39.2904, 'longitude' => -76.6122),
            '20814' => array('city' => 'Bethesda', 'state' => 'MD', 'latitude' => 38.9897, 'longitude' => -77.0997),
            '20852' => array('city' => 'Rockville', 'state' => 'MD', 'latitude' => 39.0840, 'longitude' => -77.1528),
            // New York
            '10001' => array('city' => 'New York', 'state' => 'NY', 'latitude' => 40.7484, 'longitude' => -73.9967),
            '10011' => array('city' => 'New York', 'state' => 'NY', 'latitude' => 40.7418, 'longitude' => -74.0002),
            '11201' => array('city' => 'Brooklyn', 'state' => 'NY', 'latitude' => 40.6943, 'longitude' => -73.9903),
            '10583' => array('city' => 'Scarsdale', 'state' => 'NY', 'latitude' => 41.0051, 'longitude' => -73.7846),
            '10701' => array('city' => 'Yonkers', 'state' => 'NY', 'latitude' => 40.9312, 'longitude' => -73.8987),
        );
    }
    
    /**
     * Calculate distance between two points (Haversine formula)
     */
    public static function calculate_distance($lat1, $lon1, $lat2, $lon2, $unit = 'miles') {
        $earth_radius_miles = 3959;
        $earth_radius_km = 6371;
        
        $lat1_rad = deg2rad($lat1);
        $lat2_rad = deg2rad($lat2);
        $delta_lat = deg2rad($lat2 - $lat1);
        $delta_lon = deg2rad($lon2 - $lon1);
        
        $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
             cos($lat1_rad) * cos($lat2_rad) *
             sin($delta_lon / 2) * sin($delta_lon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        if ($unit === 'km') {
            return $earth_radius_km * $c;
        }
        
        return $earth_radius_miles * $c;
    }
    
    /**
     * Find trainers within radius
     */
    public static function find_trainers_near($latitude, $longitude, $radius_miles = 25, $limit = 50) {
        global $wpdb;
        
        // Use SQL Haversine formula for efficiency
        $sql = $wpdb->prepare("
            SELECT t.*, 
                   (3959 * acos(
                       cos(radians(%f)) * cos(radians(latitude)) *
                       cos(radians(longitude) - radians(%f)) +
                       sin(radians(%f)) * sin(radians(latitude))
                   )) AS distance
            FROM {$wpdb->prefix}ptp_trainers t
            WHERE t.status = 'active'
              AND t.latitude IS NOT NULL
              AND t.longitude IS NOT NULL
            HAVING distance <= %f
            ORDER BY distance ASC
            LIMIT %d
        ", $latitude, $longitude, $latitude, $radius_miles, $limit);
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * AJAX: Geocode address
     */
    public static function ajax_geocode_address() {
        $address = sanitize_text_field($_POST['address'] ?? '');
        
        if (empty($address)) {
            wp_send_json_error(array('message' => 'Address is required'));
        }
        
        $result = self::geocode($address);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Search trainers by location
     */
    public static function ajax_search_by_location() {
        $zip = sanitize_text_field($_POST['zip'] ?? '');
        $radius = intval($_POST['radius'] ?? 25);
        
        if (empty($zip)) {
            wp_send_json_error(array('message' => 'ZIP code is required'));
        }
        
        // Geocode the ZIP
        $location = self::geocode($zip);
        
        if (!$location['success']) {
            wp_send_json_error(array('message' => 'Could not find location'));
        }
        
        // Find trainers
        $trainers = self::find_trainers_near(
            $location['latitude'],
            $location['longitude'],
            $radius
        );
        
        // Format trainer data
        $formatted = array_map(function($trainer) use ($location) {
            $distance = self::calculate_distance(
                $location['latitude'],
                $location['longitude'],
                $trainer->latitude,
                $trainer->longitude
            );
            
            // Use slug if available, otherwise fall back to ID
            $trainer_identifier = !empty($trainer->slug) ? $trainer->slug : $trainer->id;
            
            return array(
                'id' => $trainer->id,
                'name' => $trainer->display_name,
                'slug' => $trainer->slug ?: '',
                'headline' => $trainer->headline,
                'photo_url' => $trainer->photo_url,
                'hourly_rate' => floatval($trainer->hourly_rate),
                'average_rating' => floatval($trainer->average_rating),
                'review_count' => intval($trainer->review_count),
                'total_sessions' => intval($trainer->total_sessions),
                'college' => $trainer->college,
                'is_featured' => (bool)$trainer->is_featured,
                'is_verified' => (bool)$trainer->is_verified,
                'distance' => round($distance, 1),
                'profile_url' => home_url('/trainer/' . $trainer_identifier . '/'),
            );
        }, $trainers);
        
        wp_send_json_success(array(
            'location' => $location,
            'trainers' => $formatted,
            'count' => count($formatted),
        ));
    }
    
    /**
     * Update trainer location
     */
    public static function update_trainer_location($trainer_id, $address) {
        global $wpdb;

        $location = self::geocode($address);

        if (!$location['success']) {
            return false;
        }

        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'location' => $location['formatted_address'],
                'city' => $location['city'] ?? '',
                'state' => $location['state'] ?? '',
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
            ),
            array('id' => $trainer_id),
            array('%s', '%s', '%s', '%f', '%f'),
            array('%d')
        );

        return $location;
    }
    
    /**
     * Update parent location
     */
    public static function update_parent_location($parent_id, $address) {
        global $wpdb;
        
        $location = self::geocode($address);
        
        if (!$location['success']) {
            return false;
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_parents',
            array(
                'location' => $location['formatted_address'],
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
            ),
            array('id' => $parent_id),
            array('%s', '%f', '%f'),
            array('%d')
        );
        
        return $location;
    }
    
    /**
     * Get formatted distance string
     */
    public static function format_distance($miles) {
        if ($miles < 0.1) {
            return 'Less than 0.1 mi';
        } elseif ($miles < 1) {
            return round($miles, 1) . ' mi';
        } else {
            return round($miles) . ' mi away';
        }
    }
    
    /**
     * Create ZIP codes table
     */
    public static function create_table() {
        global $wpdb;
        
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_zip_codes (
            zip varchar(10) NOT NULL,
            city varchar(100) NOT NULL,
            state varchar(2) NOT NULL,
            latitude decimal(10,7) NOT NULL,
            longitude decimal(11,7) NOT NULL,
            PRIMARY KEY (zip),
            KEY state (state),
            KEY lat_lng (latitude, longitude)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Seed with common zips
        $common = self::get_common_zips();
        foreach ($common as $zip => $data) {
            $wpdb->replace(
                $wpdb->prefix . 'ptp_zip_codes',
                array_merge(array('zip' => $zip), $data),
                array('%s', '%s', '%s', '%f', '%f')
            );
        }
    }
}
