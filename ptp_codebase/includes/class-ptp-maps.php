<?php
/**
 * Google Maps Training Locations Component
 * PTP v25.0 - Display trainer locations on map
 */
defined('ABSPATH') || exit;

class PTP_Maps {
    
    private static $api_key;
    
    public static function init() {
        self::$api_key = get_option('ptp_google_maps_api_key', '') ?: get_option('ptp_google_maps_key', '');
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        
        // AJAX endpoints
        add_action('wp_ajax_ptp_geocode_location', array(__CLASS__, 'geocode_location'));
        add_action('wp_ajax_nopriv_ptp_geocode_location', array(__CLASS__, 'geocode_location'));
    }
    
    /**
     * Check if Maps is configured
     */
    public static function is_enabled() {
        return !empty(self::$api_key);
    }
    
    /**
     * Get API key
     */
    public static function get_api_key() {
        return self::$api_key;
    }
    
    /**
     * Enqueue Google Maps scripts
     */
    public static function enqueue_scripts() {
        if (!self::is_enabled()) {
            return;
        }
        
        // Skip pages that load Google Maps manually in their templates
        // trainer-dashboard, trainer-onboarding, and find-trainers have inline Google Maps scripts
        if (is_page(array('trainer-dashboard', 'trainer-onboarding', 'find-trainers'))) {
            return;
        }
        
        // Only load on relevant pages
        if (is_page(array('trainer-profile', 'my-training'))) {
            wp_enqueue_script(
                'google-maps',
                'https://maps.googleapis.com/maps/api/js?key=' . self::$api_key . '&libraries=places&loading=async',
                array(),
                null,
                true
            );
        }
    }
    
    /**
     * Render locations map
     */
    public static function render_map($locations, $options = array()) {
        if (!self::is_enabled() || empty($locations)) {
            return '';
        }
        
        $defaults = array(
            'id' => 'ptp-map-' . uniqid(),
            'height' => '300px',
            'zoom' => 12,
            'center_lat' => 39.9526,
            'center_lng' => -75.1652,
            'marker_color' => '#FCB900',
        );
        
        $opts = wp_parse_args($options, $defaults);
        
        // Convert locations to array if string
        if (is_string($locations)) {
            $decoded = json_decode($locations, true);
            if (is_array($decoded)) {
                $locations = $decoded;
            } else {
                $locations = array_filter(array_map('trim', explode("\n", $locations)));
            }
        }
        
        ob_start();
        ?>
        <div id="<?php echo esc_attr($opts['id']); ?>" class="ptp-locations-map" style="height: <?php echo esc_attr($opts['height']); ?>; border-radius: 12px; overflow: hidden; background: #0d1117; border: 1px solid rgba(255,255,255,0.1);"></div>
        
        <script>
        (function() {
            function initMap<?php echo esc_js(str_replace('-', '_', $opts['id'])); ?>() {
                const mapElement = document.getElementById('<?php echo esc_js($opts['id']); ?>');
                if (!mapElement || typeof google === 'undefined') return;
                
                const locations = <?php echo json_encode($locations); ?>;
                const geocoder = new google.maps.Geocoder();
                const bounds = new google.maps.LatLngBounds();
                
                const map = new google.maps.Map(mapElement, {
                    center: { lat: <?php echo floatval($opts['center_lat']); ?>, lng: <?php echo floatval($opts['center_lng']); ?> },
                    zoom: <?php echo intval($opts['zoom']); ?>,
                    styles: [
                        // PTP Custom Dark Theme - Gold accents
                        { elementType: 'geometry', stylers: [{ color: '#1a1a1a' }] },
                        { elementType: 'labels.text.stroke', stylers: [{ color: '#0A0A0A' }] },
                        { elementType: 'labels.text.fill', stylers: [{ color: '#9CA3AF' }] },
                        { featureType: 'administrative', elementType: 'geometry', stylers: [{ visibility: 'off' }] },
                        { featureType: 'administrative.locality', elementType: 'labels.text.fill', stylers: [{ color: '#E5E7EB' }] },
                        { featureType: 'poi', stylers: [{ visibility: 'off' }] },
                        { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#2d2d2d' }] },
                        { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: '#1a1a1a' }] },
                        { featureType: 'road', elementType: 'labels.text.fill', stylers: [{ color: '#9CA3AF' }] },
                        { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#3d3d3d' }] },
                        { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: '#1a1a1a' }] },
                        { featureType: 'road.highway', elementType: 'labels.text.fill', stylers: [{ color: '#F3F4F6' }] },
                        { featureType: 'transit', stylers: [{ visibility: 'off' }] },
                        { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0d1117' }] },
                        { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#4B5563' }] }
                    ],
                    mapTypeControl: false,
                    fullscreenControl: false,
                    streetViewControl: false,
                    zoomControl: true,
                    zoomControlOptions: { position: google.maps.ControlPosition.RIGHT_CENTER }
                });
                
                // Custom marker icon
                const markerIcon = {
                    path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z',
                    fillColor: '<?php echo esc_js($opts['marker_color']); ?>',
                    fillOpacity: 1,
                    strokeWeight: 2,
                    strokeColor: '#0E0F11',
                    scale: 1.5,
                    anchor: new google.maps.Point(12, 24)
                };
                
                let markersAdded = 0;
                
                locations.forEach((location, index) => {
                    if (!location || location.trim() === '') return;
                    
                    geocoder.geocode({ address: location + ', USA' }, (results, status) => {
                        if (status === 'OK' && results[0]) {
                            const marker = new google.maps.Marker({
                                map: map,
                                position: results[0].geometry.location,
                                icon: markerIcon,
                                title: location,
                                animation: google.maps.Animation.DROP
                            });
                            
                            const infoWindow = new google.maps.InfoWindow({
                                content: `
                                    <div style="padding: 12px; font-family: Inter, sans-serif; background: #1a1a1a; color: #fff; border-radius: 8px;">
                                        <div style="font-weight: 700; font-size: 14px; margin-bottom: 6px; color: #FFFFFF;">${location}</div>
                                        <a href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(location)}" 
                                           target="_blank" 
                                           style="color: #FCB900; font-size: 13px; font-weight: 600; text-decoration: none;">
                                            Get Directions →
                                        </a>
                                    </div>
                                `
                            });
                            
                            marker.addListener('click', () => {
                                infoWindow.open(map, marker);
                            });
                            
                            bounds.extend(results[0].geometry.location);
                            markersAdded++;
                            
                            if (markersAdded === locations.length) {
                                if (markersAdded > 1) {
                                    map.fitBounds(bounds, { padding: 50 });
                                }
                            }
                        }
                    });
                });
            }
            
            if (typeof google !== 'undefined' && google.maps) {
                initMap<?php echo esc_js(str_replace('-', '_', $opts['id'])); ?>();
            } else {
                window.addEventListener('load', initMap<?php echo esc_js(str_replace('-', '_', $opts['id'])); ?>);
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render trainer locations card with map
     */
    public static function render_trainer_locations($trainer, $show_map = true) {
        $locations = array();
        
        if (!empty($trainer->training_locations)) {
            $decoded = json_decode($trainer->training_locations, true);
            if (is_array($decoded)) {
                $locations = $decoded;
            } else {
                $locations = array_filter(array_map('trim', explode("\n", $trainer->training_locations)));
            }
        }
        
        if (empty($locations)) {
            return '';
        }
        
        ob_start();
        ?>
        <div class="ptp-trainer-locations-card">
            <h3 class="ptp-locations-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                Training Locations
            </h3>
            
            <?php if ($show_map && self::is_enabled()): ?>
                <?php echo self::render_map($locations, array(
                    'height' => '200px',
                    'center_lat' => $trainer->latitude ?: 39.9526,
                    'center_lng' => $trainer->longitude ?: -75.1652,
                )); ?>
            <?php endif; ?>
            
            <ul class="ptp-locations-list">
                <?php foreach ($locations as $loc): ?>
                <li class="ptp-location-item">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    <span><?php echo esc_html($loc); ?></span>
                    <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo urlencode($loc); ?>" 
                       target="_blank" 
                       class="ptp-directions-link">
                        Directions
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <style>
        .ptp-trainer-locations-card {
            background: #1a1a1a;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .ptp-locations-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 700;
            color: #FFFFFF;
            margin: 0 0 16px;
        }
        
        .ptp-locations-title svg {
            color: #FCB900;
        }
        
        .ptp-locations-list {
            list-style: none;
            padding: 0;
            margin: 16px 0 0;
        }
        
        .ptp-location-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-size: 14px;
        }
        
        .ptp-location-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .ptp-location-item svg {
            color: #9CA3AF;
            flex-shrink: 0;
        }
        
        .ptp-location-item span {
            flex: 1;
            color: #E5E7EB;
        }
        
        .ptp-directions-link {
            color: #FCB900;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }
        
        .ptp-directions-link:hover {
            text-decoration: underline;
        }
        
        .ptp-locations-map {
            background: #0d1117 !important;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Geocode a location via AJAX
     */
    public static function geocode_location() {
        $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';
        
        if (empty($address) || !self::is_enabled()) {
            wp_send_json_error(array('message' => 'Unable to geocode'));
        }
        
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query(array(
            'address' => $address,
            'key' => self::$api_key,
        ));
        
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Geocoding failed'));
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($body['status'] === 'OK' && !empty($body['results'])) {
            $location = $body['results'][0]['geometry']['location'];
            wp_send_json_success(array(
                'lat' => $location['lat'],
                'lng' => $location['lng'],
                'formatted_address' => $body['results'][0]['formatted_address'],
            ));
        }
        
        wp_send_json_error(array('message' => 'Location not found'));
    }
    
    /**
     * Get nearby trainers
     */
    public static function get_nearby_trainers($lat, $lng, $radius_miles = 25, $limit = 20) {
        global $wpdb;
        
        // Haversine formula for distance calculation
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT t.*, 
                   (3959 * acos(cos(radians(%f)) * cos(radians(latitude)) * cos(radians(longitude) - radians(%f)) + sin(radians(%f)) * sin(radians(latitude)))) AS distance
            FROM {$wpdb->prefix}ptp_trainers t
            WHERE t.is_active = 1 
            AND t.latitude IS NOT NULL 
            AND t.longitude IS NOT NULL
            HAVING distance < %f
            ORDER BY distance
            LIMIT %d
        ", $lat, $lng, $lat, $radius_miles, $limit));
        
        return $results;
    }
}

// Initialize
PTP_Maps::init();
