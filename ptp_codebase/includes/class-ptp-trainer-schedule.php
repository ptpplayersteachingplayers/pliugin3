<?php
/**
 * PTP Trainer Schedule & Locations
 * 
 * One-call access to any trainer's locations and weekly schedule.
 * 
 * USAGE (PHP):
 *   // Get everything for a trainer
 *   $data = PTP_Trainer_Schedule::get($trainer_id);
 *   $data['locations']  // array of training locations
 *   $data['schedule']   // weekly schedule with day names
 *   $data['exceptions'] // blocked dates
 *   $data['next_available'] // next open slot datetime
 * 
 *   // Just locations
 *   $locs = PTP_Trainer_Schedule::locations($trainer_id);
 * 
 *   // Just schedule
 *   $sched = PTP_Trainer_Schedule::schedule($trainer_id);
 * 
 *   // All trainers with locations + schedule
 *   $all = PTP_Trainer_Schedule::all();
 * 
 * USAGE (REST API):
 *   GET /wp-json/ptp/v1/trainers/{id}/schedule-locations
 *   GET /wp-json/ptp/v1/trainers/schedule-locations  (all trainers)
 * 
 * USAGE (AJAX - admin):
 *   action: ptp_get_trainer_schedule_locations
 *   trainer_id: 5  (optional, omit for all)
 */

if (!defined('ABSPATH')) exit;

class PTP_Trainer_Schedule {

    private static $day_names = array(
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    );

    public static function init() {
        // REST endpoints
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));

        // AJAX (admin + logged-in)
        add_action('wp_ajax_ptp_get_trainer_schedule_locations', array(__CLASS__, 'ajax_get'));
    }

    // ─── REST ROUTES ────────────────────────────────────────────────

    public static function register_routes() {
        register_rest_route('ptp/v1', '/trainers/(?P<id>\d+)/schedule-locations', array(
            'methods'  => 'GET',
            'callback' => array(__CLASS__, 'rest_get_single'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('ptp/v1', '/trainers/schedule-locations', array(
            'methods'  => 'GET',
            'callback' => array(__CLASS__, 'rest_get_all'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function rest_get_single($request) {
        $id = intval($request->get_param('id'));
        $data = self::get($id);

        if (is_wp_error($data)) {
            return $data;
        }

        return rest_ensure_response($data);
    }

    public static function rest_get_all($request) {
        $state = sanitize_text_field($request->get_param('state') ?? '');
        $city  = sanitize_text_field($request->get_param('city') ?? '');
        return rest_ensure_response(self::all($state, $city));
    }

    // ─── AJAX ───────────────────────────────────────────────────────

    public static function ajax_get() {
        $trainer_id = intval($_GET['trainer_id'] ?? 0);

        if ($trainer_id) {
            $data = self::get($trainer_id);
        } else {
            $state = sanitize_text_field($_GET['state'] ?? '');
            $city  = sanitize_text_field($_GET['city'] ?? '');
            $data  = self::all($state, $city);
        }

        wp_send_json_success($data);
    }

    // ─── CORE METHODS ───────────────────────────────────────────────

    /**
     * Get locations + schedule for one trainer
     *
     * @param  int   $trainer_id
     * @return array|WP_Error
     */
    public static function get($trainer_id) {
        $trainer_id = intval($trainer_id);
        if (!$trainer_id) {
            return new WP_Error('invalid_id', 'Trainer ID required', array('status' => 400));
        }

        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, slug, photo_url, location, city, state,
                    latitude, longitude, travel_radius, training_locations,
                    hourly_rate, status
             FROM {$wpdb->prefix}ptp_trainers
             WHERE id = %d",
            $trainer_id
        ));

        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
        }

        return array(
            'trainer_id'     => (int) $trainer->id,
            'name'           => $trainer->display_name,
            'slug'           => $trainer->slug,
            'photo_url'      => $trainer->photo_url,
            'status'         => $trainer->status,
            'home_base'      => array(
                'location' => $trainer->location,
                'city'     => $trainer->city,
                'state'    => $trainer->state,
                'lat'      => $trainer->latitude ? (float) $trainer->latitude : null,
                'lng'      => $trainer->longitude ? (float) $trainer->longitude : null,
                'travel_radius' => $trainer->travel_radius ? (int) $trainer->travel_radius : null,
            ),
            'locations'      => self::locations($trainer_id, $trainer),
            'schedule'       => self::schedule($trainer_id),
            'exceptions'     => self::exceptions($trainer_id),
            'next_available' => self::next_available($trainer_id),
            'hourly_rate'    => $trainer->hourly_rate ? (float) $trainer->hourly_rate : null,
        );
    }

    /**
     * Get training locations for a trainer
     *
     * @param  int         $trainer_id
     * @param  object|null $trainer  Pre-fetched trainer row (optional, avoids extra query)
     * @return array
     */
    public static function locations($trainer_id, $trainer = null) {
        global $wpdb;

        if (!$trainer) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT location, city, state, latitude, longitude, training_locations
                 FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                intval($trainer_id)
            ));
        }

        if (!$trainer) return array();

        $locations = array();

        if (!empty($trainer->training_locations)) {
            $decoded = json_decode($trainer->training_locations, true);
            // Fallback for WP magic quotes corrupted JSON
            if (!is_array($decoded) && !empty($trainer->training_locations)) {
                $decoded = json_decode(wp_unslash($trainer->training_locations), true);
            }
            if (is_array($decoded)) {
                foreach ($decoded as $loc) {
                    if (!empty($loc['name']) || !empty($loc['address'])) {
                        $locations[] = array(
                            'id'      => $loc['id'] ?? uniqid('loc_'),
                            'name'    => trim($loc['name'] ?? ''),
                            'address' => trim($loc['address'] ?? ''),
                            'city'    => $loc['city'] ?? '',
                            'state'   => $loc['state'] ?? '',
                            'zip'     => $loc['zip'] ?? '',
                            'lat'     => isset($loc['lat']) ? (float) $loc['lat'] : null,
                            'lng'     => isset($loc['lng']) ? (float) $loc['lng'] : null,
                            'notes'   => $loc['notes'] ?? '',
                        );
                    }
                }
            }
        }

        // Fallback to general location if no training_locations set
        if (empty($locations) && !empty($trainer->location)) {
            $locations[] = array(
                'id'      => 'default',
                'name'    => $trainer->location,
                'address' => $trainer->location,
                'city'    => $trainer->city ?? '',
                'state'   => $trainer->state ?? '',
                'zip'     => '',
                'lat'     => $trainer->latitude ? (float) $trainer->latitude : null,
                'lng'     => $trainer->longitude ? (float) $trainer->longitude : null,
                'notes'   => '',
            );
        }

        return $locations;
    }

    /**
     * Get weekly schedule for a trainer (human-readable)
     *
     * @param  int  $trainer_id
     * @return array  Keyed by day name, with active/start/end/slot_duration
     */
    public static function schedule($trainer_id) {
        $rows = PTP_Availability::get_weekly(intval($trainer_id));

        // Build full 7-day schedule
        $schedule = array();
        $active_by_day = array();

        foreach ($rows as $row) {
            $active_by_day[(int) $row->day_of_week] = $row;
        }

        foreach (self::$day_names as $dow => $name) {
            if (isset($active_by_day[$dow])) {
                $row = $active_by_day[$dow];
                $schedule[] = array(
                    'day'           => $name,
                    'day_short'     => substr($name, 0, 3),
                    'day_of_week'   => $dow,
                    'active'        => (bool) $row->is_active,
                    'start_time'    => substr($row->start_time, 0, 5), // HH:MM
                    'end_time'      => substr($row->end_time, 0, 5),
                    'slot_duration' => (int) ($row->slot_duration ?? 60),
                    'display'       => $row->is_active
                        ? substr($row->start_time, 0, 5) . ' - ' . substr($row->end_time, 0, 5)
                        : 'Off',
                );
            } else {
                $schedule[] = array(
                    'day'           => $name,
                    'day_short'     => substr($name, 0, 3),
                    'day_of_week'   => $dow,
                    'active'        => false,
                    'start_time'    => null,
                    'end_time'      => null,
                    'slot_duration' => 60,
                    'display'       => 'Off',
                );
            }
        }

        return $schedule;
    }

    /**
     * Get blocked/exception dates for a trainer
     *
     * @param  int    $trainer_id
     * @param  int    $days_ahead  How far ahead to look (default 60)
     * @return array
     */
    public static function exceptions($trainer_id, $days_ahead = 60) {
        global $wpdb;

        PTP_Availability::ensure_exceptions_table();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT exception_date, exception_type, reason, is_available,
                    start_time, end_time
             FROM {$wpdb->prefix}ptp_availability_exceptions
             WHERE trainer_id = %d
               AND exception_date >= CURDATE()
               AND exception_date <= DATE_ADD(CURDATE(), INTERVAL %d DAY)
             ORDER BY exception_date ASC",
            intval($trainer_id),
            intval($days_ahead)
        ));

        $exceptions = array();
        foreach ($results as $row) {
            $exceptions[] = array(
                'date'      => $row->exception_date,
                'type'      => $row->exception_type, // blocked, modified, added
                'reason'    => $row->reason ?? '',
                'available' => (bool) $row->is_available,
                'hours'     => $row->start_time && $row->end_time
                    ? substr($row->start_time, 0, 5) . ' - ' . substr($row->end_time, 0, 5)
                    : null,
            );
        }

        return $exceptions;
    }

    /**
     * Find the next available slot for a trainer
     *
     * @param  int  $trainer_id
     * @return string|null  ISO datetime of next open slot, or null
     */
    public static function next_available($trainer_id) {
        $trainer_id = intval($trainer_id);
        $active = PTP_Availability::get_active_weekly($trainer_id);

        if (empty($active)) return null;

        // Map active days
        $active_days = array();
        foreach ($active as $row) {
            $active_days[(int) $row->day_of_week] = $row;
        }

        global $wpdb;
        $now = new DateTime('now', new DateTimeZone('America/New_York'));

        // Look up to 14 days ahead
        for ($i = 0; $i < 14; $i++) {
            $check = clone $now;
            $check->modify("+{$i} days");
            $dow = (int) $check->format('w');
            $date_str = $check->format('Y-m-d');

            if (!isset($active_days[$dow])) continue;

            // Check exceptions
            if (PTP_Availability::is_date_blocked($trainer_id, $date_str)) continue;

            $row = $active_days[$dow];
            $slot_start = new DateTime($date_str . ' ' . $row->start_time, new DateTimeZone('America/New_York'));

            // If today, skip past slots
            if ($i === 0 && $slot_start < $now) {
                $duration = (int) ($row->slot_duration ?? 60);
                while ($slot_start < $now) {
                    $slot_start->modify("+{$duration} minutes");
                }
                $end = new DateTime($date_str . ' ' . $row->end_time, new DateTimeZone('America/New_York'));
                if ($slot_start >= $end) continue;
            }

            // Check if slot is booked
            $time_str = $slot_start->format('H:i:s');
            $booked = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
                 WHERE trainer_id = %d AND session_date = %s AND session_time = %s
                   AND status NOT IN ('cancelled', 'rejected')",
                $trainer_id, $date_str, $time_str
            ));

            if (!$booked) {
                return $slot_start->format('Y-m-d\TH:i:s');
            }
        }

        return null;
    }

    /**
     * Get locations + schedule for ALL active trainers
     *
     * @param  string $state  Filter by state (optional)
     * @param  string $city   Filter by city (optional)
     * @return array
     */
    public static function all($state = '', $city = '') {
        global $wpdb;

        $where = array("status = 'active'");
        $params = array();

        if ($state) {
            $where[] = "state = %s";
            $params[] = $state;
        }

        if ($city) {
            $where[] = "city LIKE %s";
            $params[] = '%' . $city . '%';
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE {$where_sql} ORDER BY display_name ASC";

        if (!empty($params)) {
            $ids = $wpdb->get_col($wpdb->prepare($sql, $params));
        } else {
            $ids = $wpdb->get_col($sql);
        }

        $trainers = array();
        foreach ($ids as $id) {
            $data = self::get((int) $id);
            if (!is_wp_error($data)) {
                $trainers[] = $data;
            }
        }

        return array(
            'count'    => count($trainers),
            'trainers' => $trainers,
        );
    }

    /**
     * Get a compact summary (for admin dashboards / quick views)
     *
     * @param  int  $trainer_id
     * @return array
     */
    public static function summary($trainer_id) {
        $data = self::get(intval($trainer_id));
        if (is_wp_error($data)) return array();

        $active_days = array_filter($data['schedule'], function($d) { return $d['active']; });
        $day_names = array_map(function($d) { return $d['day_short']; }, $active_days);

        return array(
            'name'           => $data['name'],
            'location_count' => count($data['locations']),
            'location_names' => array_column($data['locations'], 'name'),
            'active_days'    => implode(', ', $day_names),
            'active_day_count' => count($active_days),
            'next_available' => $data['next_available'],
            'hourly_rate'    => $data['hourly_rate'],
        );
    }
}
