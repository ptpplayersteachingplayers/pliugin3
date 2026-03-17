<?php
/**
 * PTP Chatbot Scheduling API — v200.3
 * 
 * REST endpoints for AI chatbot + SMS-based scheduling.
 * Works with any AI chatbot platform
 * (OpenAI, Claude, Voiceflow, etc.) or directly via OpenPhone SMS webhooks.
 *
 * ENDPOINTS:
 *   POST /ptp/v1/chatbot/trainers           — Search trainers by location/name
 *   POST /ptp/v1/chatbot/availability        — Get available slots for a trainer
 *   POST /ptp/v1/chatbot/locations           — List all PTP training locations
 *   POST /ptp/v1/chatbot/book               — Create a booking + send SMS confirmation
 *   POST /ptp/v1/chatbot/cancel             — Cancel a booking
 *   POST /ptp/v1/chatbot/send-sms           — Send an arbitrary SMS
 *   GET  /ptp/v1/chatbot/trainer/(?P<id>\d+) — Get full trainer detail
 *   POST /ptp/v1/chatbot/sms-webhook        — OpenPhone incoming SMS webhook (conversational)
 *
 * AUTH: X-PTP-API-Key header or api_key param (except sms-webhook which uses OpenPhone verification)
 *
 * Enable: update_option('ptp_chatbot_api_enabled', true)
 *
 * @since 200
 */

defined('ABSPATH') || exit;

class PTP_Chatbot_API {

    const NS = 'ptp/v1';
    const KEY_OPTION = 'ptp_chatbot_api_key';

    /* ─── Bootstrap ─── */

    public static function init() {
        $enabled = get_option('ptp_chatbot_api_enabled', false);
        if (!$enabled) return;

        add_action('rest_api_init', [__CLASS__, 'routes']);

        // Migrate legacy API key if needed
        if (!get_option(self::KEY_OPTION)) {
            $legacy_key = get_option('ptp_salesmsg_api_key', '');
            if ($legacy_key) {
                update_option(self::KEY_OPTION, $legacy_key);
                // Keep legacy option alive — Command Center may still read it
            } else {
                $new_key = 'ptp_' . wp_generate_password(32, false, false);
                update_option(self::KEY_OPTION, $new_key);
                // Also write to legacy key so Command Center can find it
                update_option('ptp_salesmsg_api_key', $new_key);
            }
        }

        // Clean up legacy options
        if (get_option('ptp_salesmsg_enabled') !== false) {
            delete_option('ptp_salesmsg_enabled');
        }

        // Schedule daily cleanup of stale SMS conversation states
        if (!wp_next_scheduled('ptp_chatbot_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ptp_chatbot_cleanup');
        }
        add_action('ptp_chatbot_cleanup', [__CLASS__, 'cleanup_stale_states']);
    }

    /**
     * Clean up expired SMS conversation states from wp_options.
     * Runs daily via cron.
     */
    public static function cleanup_stale_states() {
        global $wpdb;

        // Remove SMS states older than 2 hours
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'ptp_sms_state_%' LIMIT 500"
        );
        $cleaned = 0;
        foreach ($rows as $row) {
            $state = maybe_unserialize($row->option_value);
            if (!is_array($state)) {
                delete_option($row->option_name);
                $cleaned++;
                continue;
            }
            $last = $state['_last_activity'] ?? 0;
            if ($last && (time() - $last) > 7200) {
                delete_option($row->option_name);
                $cleaned++;
            }
        }
        if ($cleaned) {
            ptp_log("[PTP Chatbot API] Cleaned up {$cleaned} stale SMS conversation states");
        }
    }

    /* ═══════════════════════════════════════════════
       ROUTES
       ═══════════════════════════════════════════════ */

    public static function routes() {
        $auth = ['permission_callback' => [__CLASS__, 'verify_key']];

        register_rest_route(self::NS, '/chatbot/trainers', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'search_trainers'], ...$auth,
        ]);
        register_rest_route(self::NS, '/chatbot/availability', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'get_availability'], ...$auth,
        ]);
        register_rest_route(self::NS, '/chatbot/locations', [
            'methods' => ['GET','POST'], 'callback' => [__CLASS__, 'get_locations'], ...$auth,
        ]);
        register_rest_route(self::NS, '/chatbot/book', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'create_booking'], ...$auth,
        ]);
        register_rest_route(self::NS, '/chatbot/cancel', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'cancel_booking'], ...$auth,
        ]);
        register_rest_route(self::NS, '/chatbot/send-sms', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'send_sms'], ...$auth,
        ]);
        register_rest_route(self::NS, '/chatbot/trainer/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'trainer_detail'], ...$auth,
        ]);

        // OpenPhone webhook (auth via shared secret, not API key)
        register_rest_route(self::NS, '/chatbot/sms-webhook', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'sms_webhook'],
            'permission_callback' => [__CLASS__, 'verify_openphone_webhook'],
        ]);

    }

    /* ═══════════════════════════════════════════════
       AUTH
       ═══════════════════════════════════════════════ */

    public static function verify_key($request) {
        $key = $request->get_header('X-PTP-API-Key') ?: $request->get_param('api_key');
        if (empty($key)) {
            return new WP_Error('missing_key', 'API key required', ['status' => 401]);
        }
        $stored = get_option(self::KEY_OPTION);
        if (!$stored || !hash_equals($stored, $key)) {
            return new WP_Error('invalid_key', 'Invalid API key', ['status' => 401]);
        }
        return true;
    }

    /**
     * Verify OpenPhone webhook (shared-secret or API key gate).
     * OpenPhone does not use HMAC signatures.
     */
    public static function verify_openphone_webhook($request) {
        // Option 1: shared secret header
        $expected_secret = get_option('ptp_openphone_webhook_secret', '');
        if (!empty($expected_secret)) {
            $header_secret = $request->get_header('X-OpenPhone-Secret');
            if (!$header_secret || !hash_equals($expected_secret, $header_secret)) {
                ptp_log('[PTP Chatbot API] OpenPhone webhook: invalid or missing secret');
                return new WP_Error('invalid_secret', 'Webhook secret mismatch', ['status' => 403]);
            }
            return true;
        }

        // Option 2: verify API key is configured (basic gate)
        $api_key = get_option('ptp_openphone_api_key', '');
        if (empty($api_key)) {
            return new WP_Error('sms_not_configured', 'OpenPhone API key not set', ['status' => 403]);
        }

        return true;
    }

    /* ═══════════════════════════════════════════════
       1. SEARCH TRAINERS
       POST /chatbot/trainers
       Params: location (string), name (string), date (Y-m-d), max_results (int)
       ═══════════════════════════════════════════════ */

    public static function search_trainers($request) {
        global $wpdb;

        $location    = sanitize_text_field($request->get_param('location') ?? '');
        $name        = sanitize_text_field($request->get_param('name') ?? '');
        $date        = sanitize_text_field($request->get_param('date') ?? '');
        $max_results = min(absint($request->get_param('max_results') ?: 5), 20);

        $where = "WHERE t.status = 'active'";
        $params = [];

        if ($location) {
            $like = '%' . $wpdb->esc_like($location) . '%';
            $where .= " AND (t.location LIKE %s OR t.training_locations LIKE %s OR t.city LIKE %s)";
            $params = array_merge($params, [$like, $like, $like]);
        }
        if ($name) {
            $like = '%' . $wpdb->esc_like($name) . '%';
            $where .= " AND t.display_name LIKE %s";
            $params[] = $like;
        }

        $sql = "SELECT t.id, t.display_name, t.slug, t.location, t.city, t.state,
                       t.hourly_rate, t.headline, t.college, t.playing_level,
                       t.average_rating, t.review_count, t.total_sessions,
                       t.training_locations, t.photo_url
                FROM {$wpdb->prefix}ptp_trainers t
                {$where}
                ORDER BY t.is_supercoach DESC, t.is_featured DESC, t.average_rating DESC
                LIMIT %d";
        $params[] = $max_results;

        $trainers = $wpdb->get_results(
            $params ? $wpdb->prepare($sql, ...$params) : $sql
        );

        $results = [];
        foreach ($trainers as $t) {
            $locs = $t->training_locations ? json_decode($t->training_locations, true) : [];
            $loc_names = array_column($locs ?: [], 'name');

            $item = [
                'id'          => absint($t->id),
                'name'        => $t->display_name,
                'slug'        => $t->slug,
                'location'    => $t->location ?: ($t->city ? $t->city . ', ' . $t->state : ''),
                'locations'   => $loc_names,
                'hourly_rate' => floatval($t->hourly_rate),
                'headline'    => $t->headline,
                'college'     => $t->college,
                'level'       => $t->playing_level,
                'rating'      => floatval($t->average_rating),
                'reviews'     => absint($t->review_count),
                'sessions'    => absint($t->total_sessions),
                'photo_url'   => $t->photo_url ? esc_url($t->photo_url) : null,
                'profile_url' => esc_url(home_url('/trainer/' . $t->slug)),
            ];

            // Include availability if date given
            if ($date && class_exists('PTP_Availability')) {
                $slots = PTP_Availability::get_available_slots($t->id, $date);
                $item['available_slots'] = count($slots);
                $item['next_slot'] = !empty($slots) ? $slots[0]['display'] : null;
            }

            $results[] = $item;
        }

        return rest_ensure_response([
            'success'  => true,
            'trainers' => $results,
            'count'    => count($results),
        ]);
    }

    /* ═══════════════════════════════════════════════
       2. GET AVAILABILITY
       POST /chatbot/availability
       Params: trainer_id (int), date (Y-m-d), days_ahead (int, max 14)
       ═══════════════════════════════════════════════ */

    public static function get_availability($request) {
        global $wpdb;

        $trainer_id = absint($request->get_param('trainer_id'));
        $date       = sanitize_text_field($request->get_param('date') ?? '');
        $days_ahead = min(absint($request->get_param('days_ahead') ?: 0), 14);

        if (!$trainer_id || !$date) {
            return new WP_Error('missing', 'trainer_id and date required', ['status' => 400]);
        }

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, hourly_rate, training_locations
             FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', ['status' => 404]);
        }

        $avail = [];
        $days = max(1, $days_ahead + 1);
        for ($i = 0; $i < $days; $i++) {
            $d = date('Y-m-d', strtotime($date . " +{$i} days"));
            if (!class_exists('PTP_Availability')) continue;
            $slots = PTP_Availability::get_available_slots($trainer_id, $d);
            if (!empty($slots)) {
                $avail[] = [
                    'date'     => $d,
                    'day_name' => date('l', strtotime($d)),
                    'display'  => date('l, M j', strtotime($d)),
                    'slots'    => $slots,
                    'count'    => count($slots),
                ];
            }
        }

        $locs = $trainer->training_locations ? json_decode($trainer->training_locations, true) : [];

        return rest_ensure_response([
            'success'      => true,
            'trainer'      => [
                'id'   => absint($trainer->id),
                'name' => $trainer->display_name,
                'rate' => floatval($trainer->hourly_rate),
            ],
            'locations'    => array_column($locs ?: [], 'name'),
            'availability' => $avail,
        ]);
    }

    /* ═══════════════════════════════════════════════
       3. GET LOCATIONS
       GET|POST /chatbot/locations
       Returns all PTP verified training locations
       ═══════════════════════════════════════════════ */

    public static function get_locations($request) {
        if (!class_exists('PTP_Admin')) {
            return new WP_Error('unavailable', 'Location data unavailable', ['status' => 500]);
        }
        $master = PTP_Admin::get_ptp_training_locations();
        $locs = [];
        foreach ($master as $key => $loc) {
            $locs[] = [
                'key'     => $key,
                'name'    => $loc['name'],
                'address' => $loc['address'],
                'lat'     => $loc['lat'],
                'lng'     => $loc['lng'],
            ];
        }
        return rest_ensure_response([
            'success'   => true,
            'locations' => $locs,
            'count'     => count($locs),
        ]);
    }

    /* ═══════════════════════════════════════════════
       4. CREATE BOOKING
       POST /chatbot/book
       Params: trainer_id, date, time, parent_name, parent_email,
               parent_phone, player_name, player_age, location,
               group_size, package, notes, send_sms (bool)
       ═══════════════════════════════════════════════ */

    public static function create_booking($request) {
        global $wpdb;

        $trainer_id   = absint($request->get_param('trainer_id'));
        $date         = sanitize_text_field($request->get_param('date') ?? '');
        $time         = sanitize_text_field($request->get_param('time') ?? '');
        $parent_name  = sanitize_text_field($request->get_param('parent_name') ?? '');
        $parent_email = sanitize_email($request->get_param('parent_email') ?? '');
        $parent_phone = sanitize_text_field($request->get_param('parent_phone') ?? '');
        $player_name  = sanitize_text_field($request->get_param('player_name') ?? '');
        $player_age   = absint($request->get_param('player_age') ?: 0);
        $location     = sanitize_text_field($request->get_param('location') ?? '');
        $group_size   = max(1, min(absint($request->get_param('group_size') ?: 1), 5));
        $package      = sanitize_text_field($request->get_param('package') ?? 'single');
        $notes        = sanitize_textarea_field($request->get_param('notes') ?? '');
        $send_sms     = $request->get_param('send_sms') !== false;

        // Validate required
        $missing = [];
        if (!$trainer_id)   $missing[] = 'trainer_id';
        if (!$date)         $missing[] = 'date';
        if (!$time)         $missing[] = 'time';
        if (!$parent_name)  $missing[] = 'parent_name';
        if (!$parent_email) $missing[] = 'parent_email';
        if (!$player_name)  $missing[] = 'player_name';
        if ($missing) {
            return new WP_Error('missing_fields', 'Required: ' . implode(', ', $missing), ['status' => 400]);
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('bad_date', 'Date must be YYYY-MM-DD', ['status' => 400]);
        }

        // Idempotency: prevent duplicate bookings within 2 minutes for same trainer+date+time+email
        $idem_key = 'ptp_book_idem_' . md5("{$trainer_id}_{$date}_{$time}_{$parent_email}");
        $existing_booking_num = get_transient($idem_key);
        if ($existing_booking_num) {
            // Return the existing booking instead of creating a duplicate
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, t.display_name as trainer_name
                 FROM {$wpdb->prefix}ptp_bookings b
                 LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 WHERE b.booking_number = %s",
                $existing_booking_num
            ));
            if ($existing) {
                $checkout_url = add_query_arg(
                    ['booking' => $existing->booking_number, 'email' => rawurlencode($parent_email)],
                    home_url('/training-checkout/')
                );
                return rest_ensure_response([
                    'success'      => true,
                    'duplicate'    => true,
                    'booking'      => [
                        'id'             => absint($existing->id),
                        'booking_number' => $existing->booking_number,
                        'trainer_name'   => $existing->trainer_name,
                        'date'           => $existing->session_date,
                        'time'           => $existing->start_time,
                        'formatted_date' => date('l, F j', strtotime($existing->session_date)),
                        'formatted_time' => date('g:i A', strtotime($existing->start_time)),
                        'location'       => $existing->location,
                        'status'         => $existing->status,
                    ],
                    'checkout_url' => esc_url($checkout_url),
                ]);
            }
        }

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', ['status' => 404]);
        }

        // Check slot — transaction for safety
        $wpdb->query('START TRANSACTION');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings
             WHERE trainer_id = %d AND session_date = %s AND start_time = %s
             AND status NOT IN ('cancelled','rejected')
             FOR UPDATE",
            $trainer_id, $date, $time
        ));

        if ($existing) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('slot_taken', 'This time slot is no longer available', ['status' => 409]);
        }

        // Pricing — use PTP_Packages as single source of truth
        $base_rate = floatval($trainer->hourly_rate ?: 80);
        if (class_exists('PTP_Packages')) {
            $package = PTP_Packages::resolve_key($package);
            $mult = PTP_Packages::group_multiplier($group_size);
            $pricing = PTP_Packages::calculate_price($base_rate, $package, $group_size);
            $total = $pricing['total'];
            $count = $pricing['sessions'];
        } else {
            $group_mult = [1 => 1, 2 => 1.6, 3 => 2, 4 => 2.4, 5 => 2.8];
            $mult = $group_mult[$group_size] ?? (1 + ($group_size - 1) * 0.4);
            $total = round($base_rate * $mult, 2);
            $pkg_counts   = ['single' => 1, 'pack3' => 3, '5pack' => 5, 'pack10' => 10];
            $pkg_discount = ['single' => 0, 'pack3' => 10, '5pack' => 15, 'pack10' => 20];
            $count = $pkg_counts[$package] ?? 1;
            $disc  = $pkg_discount[$package] ?? 0;
            $total = round($total * $count * (1 - $disc / 100), 2);
        }

        $fee_pct     = floatval(get_option('ptp_platform_fee_percent', 25));
        $trainer_pay = round($total * (1 - $fee_pct / 100), 2);
        $platform_fee= round($total * ($fee_pct / 100), 2);

        if (!$location) $location = $trainer->location;

        $booking_num = 'PTP-' . strtoupper(substr(md5(uniqid(wp_rand(), true)), 0, 8));
        $end_time    = date('H:i:s', strtotime($time . ' +1 hour'));

        $booking_notes  = $notes;
        $booking_notes .= "\n\n[Booked via Chatbot API]";
        $booking_notes .= "\nContact: {$parent_name} | {$parent_email}";
        if ($parent_phone) $booking_notes .= " | {$parent_phone}";
        $booking_notes .= "\nPlayer: {$player_name}";
        if ($player_age) $booking_notes .= " (Age: {$player_age})";
        if ($group_size > 1) $booking_notes .= "\nGroup size: {$group_size}";

        // v223-fix: Prevent double booking via chatbot
        if (class_exists('PTP_Booking') && method_exists('PTP_Booking', 'has_conflict')) {
            $chat_conflict = PTP_Booking::has_conflict($trainer_id, $date, $time, 60);
            if ($chat_conflict) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('slot_taken', 'This time slot is no longer available', ['status' => 409]);
            }
        }

        $result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', [
            'trainer_id'        => $trainer_id,
            'parent_id'         => 0,
            'player_id'         => 0,
            'session_date'      => $date,
            'start_time'        => $time,
            'end_time'          => $end_time,
            'duration_minutes'  => 60,
            'location'          => $location,
            'hourly_rate'       => $base_rate,
            'total_amount'      => $total,
            'trainer_payout'    => $trainer_pay,
            'platform_fee'      => $platform_fee,
            'notes'             => trim($booking_notes),
            'status'            => 'pending',
            'payment_status'    => 'pending',
            'booking_number'    => $booking_num,
            'session_type'      => $count > 1 ? 'package' : 'single',
            'session_count'     => $count,
            'sessions_remaining'=> $count,
            'created_at'        => current_time('mysql'),
        ]);

        if (!$result) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'Could not create booking', ['status' => 500]);
        }

        $booking_id = $wpdb->insert_id;
        $wpdb->query('COMMIT');

        // Store structured contact meta for reliable lookups
        update_option("ptp_booking_meta_{$booking_id}", [
            'parent_name'  => $parent_name,
            'parent_email' => $parent_email,
            'parent_phone' => $parent_phone,
            'player_name'  => $player_name,
            'player_age'   => $player_age,
            'group_size'   => $group_size,
            'booked_via'   => 'chatbot_api',
            'created_at'   => current_time('mysql'),
        ], false);

        // Link to existing WP user if email matches
        $existing_user = get_user_by('email', $parent_email);
        if ($existing_user) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                ['parent_id' => $existing_user->ID],
                ['id' => $booking_id]
            );
        }

        // Set idempotency key (2-minute window prevents duplicate bookings on retry)
        set_transient($idem_key, $booking_num, 120);

        // Build checkout URL
        $checkout_url = add_query_arg(
            ['booking' => $booking_num, 'email' => rawurlencode($parent_email)],
            home_url('/training-checkout/')
        );

        $fmt_date = date('l, F j', strtotime($date));
        $fmt_time = date('g:i A', strtotime($time));

        // Send SMS confirmation if requested
        $sms_sent = false;
        if ($send_sms && $parent_phone) {
            $sms_msg = "PTP Training Confirmed!\n"
                     . $trainer->display_name . "\n"
                     . $fmt_date . " at " . $fmt_time . "\n"
                     . ($location ? $location . "\n" : '')
                     . "Player: " . $player_name . "\n"
                     . ($group_size > 1 ? "Group: {$group_size} players\n" : '')
                     . "Total: $" . number_format($total, 0) . "\n"
                     . "Pay here: " . $checkout_url;
            $sms_sent = self::_send_sms($parent_phone, $sms_msg);

            // Also notify trainer
            if ($trainer->phone) {
                $trainer_msg = "New booking!\n"
                             . $player_name . ($player_age ? " (Age {$player_age})" : '') . "\n"
                             . $fmt_date . " at " . $fmt_time . "\n"
                             . ($location ?: 'TBD') . "\n"
                             . "Ref: " . $booking_num;
                self::_send_sms($trainer->phone, $trainer_msg);
            }
        }

        return rest_ensure_response([
            'success'      => true,
            'booking'      => [
                'id'             => $booking_id,
                'booking_number' => $booking_num,
                'trainer_name'   => $trainer->display_name,
                'date'           => $date,
                'time'           => $time,
                'formatted_date' => $fmt_date,
                'formatted_time' => $fmt_time,
                'location'       => $location,
                'player_name'    => $player_name,
                'group_size'     => $group_size,
                'package'        => $package,
                'amount'         => $total,
                'status'         => 'pending',
            ],
            'checkout_url' => esc_url($checkout_url),
            'sms_sent'     => $sms_sent,
            'sms_message'  => sprintf(
                'Training booked! %s with %s, %s at %s. Pay: %s',
                $player_name, $trainer->display_name, $fmt_date, $fmt_time, $checkout_url
            ),
        ]);
    }

    /* ═══════════════════════════════════════════════
       5. CANCEL BOOKING
       POST /chatbot/cancel
       Params: booking_number, parent_email (for verification)
       ═══════════════════════════════════════════════ */

    public static function cancel_booking($request) {
        global $wpdb;

        $booking_num = sanitize_text_field($request->get_param('booking_number') ?? '');
        $email       = sanitize_email($request->get_param('parent_email') ?? '');
        $phone       = sanitize_text_field($request->get_param('parent_phone') ?? '');

        if (!$booking_num) {
            return new WP_Error('missing', 'booking_number required', ['status' => 400]);
        }

        // Require at least one identifier for ownership verification
        if (!$email && !$phone) {
            return new WP_Error('missing_identity', 'parent_email or parent_phone required for verification', ['status' => 400]);
        }

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, t.display_name as trainer_name, t.phone as trainer_phone
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.booking_number = %s AND b.status NOT IN ('cancelled','completed')",
            $booking_num
        ));

        if (!$booking) {
            return new WP_Error('not_found', 'Booking not found or already cancelled', ['status' => 404]);
        }

        // Verify ownership: email or phone must appear in booking notes or parent record
        $notes_lower = strtolower($booking->notes ?? '');
        $verified = false;

        if ($email && stripos($notes_lower, strtolower($email)) !== false) {
            $verified = true;
        }
        if ($phone) {
            $clean_phone = preg_replace('/[^0-9]/', '', $phone);
            if ($clean_phone && strpos($notes_lower, $clean_phone) !== false) {
                $verified = true;
            }
        }
        // Also check if booking has a linked parent_id with matching email
        if (!$verified && $email && $booking->parent_id) {
            $parent_user = get_userdata($booking->parent_id);
            if ($parent_user && strtolower($parent_user->user_email) === strtolower($email)) {
                $verified = true;
            }
        }

        if (!$verified) {
            return new WP_Error('unauthorized', 'Email or phone does not match this booking', ['status' => 403]);
        }

        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            ['status' => 'cancelled', 'updated_at' => current_time('mysql')],
            ['id' => $booking->id]
        );

        // Notify trainer
        if ($booking->trainer_phone) {
            $msg = "Booking cancelled: " . $booking_num . "\n"
                 . date('M j', strtotime($booking->session_date)) . " at " . date('g:i A', strtotime($booking->start_time));
            self::_send_sms($booking->trainer_phone, $msg);
        }

        return rest_ensure_response([
            'success'        => true,
            'booking_number' => $booking_num,
            'status'         => 'cancelled',
        ]);
    }

    /* ═══════════════════════════════════════════════
       6. SEND SMS
       POST /chatbot/send-sms
       Params: phone, message
       ═══════════════════════════════════════════════ */

    public static function send_sms($request) {
        $phone   = sanitize_text_field($request->get_param('phone') ?? '');
        $message = sanitize_textarea_field($request->get_param('message') ?? '');

        if (!$phone || !$message) {
            return new WP_Error('missing', 'phone and message required', ['status' => 400]);
        }

        // Rate limit: max 10 SMS per phone per hour
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        $rate_key = 'ptp_sms_rate_' . md5($clean_phone);
        $rate_count = intval(get_transient($rate_key));
        if ($rate_count >= 10) {
            return new WP_Error('rate_limited', 'Too many SMS to this number. Try again later.', ['status' => 429]);
        }
        set_transient($rate_key, $rate_count + 1, HOUR_IN_SECONDS);

        // Global rate limit: max 100 SMS per hour across all numbers
        $global_key = 'ptp_sms_rate_global';
        $global_count = intval(get_transient($global_key));
        if ($global_count >= 100) {
            return new WP_Error('rate_limited', 'SMS sending limit reached. Try again later.', ['status' => 429]);
        }
        set_transient($global_key, $global_count + 1, HOUR_IN_SECONDS);

        $sent = self::_send_sms($phone, $message);

        return rest_ensure_response([
            'success' => $sent,
            'phone'   => $phone,
        ]);
    }

    /* ═══════════════════════════════════════════════
       7. TRAINER DETAIL
       GET /chatbot/trainer/{id}
       ═══════════════════════════════════════════════ */

    public static function trainer_detail($request) {
        global $wpdb;

        $trainer_id = absint($request->get_param('id'));
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', ['status' => 404]);
        }

        $locs = $trainer->training_locations ? json_decode($trainer->training_locations, true) : [];

        // Next 7 days availability
        $avail = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime("+{$i} days"));
            if (!class_exists('PTP_Availability')) continue;
            $slots = PTP_Availability::get_available_slots($trainer_id, $d);
            if ($slots) {
                $avail[] = [
                    'date'  => $d,
                    'day'   => date('l', strtotime($d)),
                    'slots' => count($slots),
                    'times' => array_slice(array_column($slots, 'display'), 0, 6),
                ];
            }
        }

        return rest_ensure_response([
            'success' => true,
            'trainer' => [
                'id'          => absint($trainer->id),
                'name'        => $trainer->display_name,
                'slug'        => $trainer->slug,
                'headline'    => $trainer->headline,
                'bio'         => wp_trim_words($trainer->bio ?: '', 100),
                'college'     => $trainer->college,
                'level'       => $trainer->playing_level,
                'hourly_rate' => floatval($trainer->hourly_rate),
                'location'    => $trainer->location,
                'locations'   => array_column($locs ?: [], 'name'),
                'rating'      => floatval($trainer->average_rating),
                'reviews'     => absint($trainer->review_count),
                'photo_url'   => $trainer->photo_url ? esc_url($trainer->photo_url) : null,
                'profile_url' => esc_url(home_url('/trainer/' . $trainer->slug)),
            ],
            'availability' => $avail,
        ]);
    }

    /* ═══════════════════════════════════════════════
       8. SMS WEBHOOK (OpenPhone Incoming)
       POST /chatbot/sms-webhook
       Handles conversational scheduling via text.
       Accepts OpenPhone webhook format and legacy format.
       ═══════════════════════════════════════════════ */

    public static function sms_webhook($request) {
        $body_json = json_decode($request->get_body(), true);

        // OpenPhone format: { "data": { "object": { "from": "+1...", "body": "..." } } }
        if (!empty($body_json['data']['object'])) {
            $obj  = $body_json['data']['object'];
            $from = sanitize_text_field($obj['from'] ?? '');
            $body = sanitize_text_field($obj['body'] ?? '');
        } else {
            // Legacy / generic format
            $from = sanitize_text_field($request->get_param('From') ?? $request->get_param('from') ?? '');
            $body = sanitize_text_field($request->get_param('Body') ?? $request->get_param('body') ?? '');
        }

        if (!$from || !$body) {
            return new WP_REST_Response('', 200);
        }

        // Rate limit: max 20 inbound SMS per phone per hour
        $rate_key = 'ptp_wh_rate_' . md5($from);
        $rate_count = intval(get_transient($rate_key));
        if ($rate_count >= 20) {
            ptp_log('[PTP Chatbot API] Rate limited webhook from: ' . $from);
            return new WP_REST_Response('', 200);
        }
        set_transient($rate_key, $rate_count + 1, HOUR_IN_SECONDS);

        $phone = preg_replace('/[^0-9+]/', '', $from);

        $state_key = 'ptp_sms_state_' . md5($phone);
        $state_raw = get_option($state_key);
        if ($state_raw && is_array($state_raw)) {
            $last_activity = $state_raw['_last_activity'] ?? 0;
            if ($last_activity && (time() - $last_activity) > 1800) {
                $state = ['step' => 'start', 'data' => []];
            } else {
                $state = $state_raw;
            }
        } else {
            $state = ['step' => 'start', 'data' => []];
        }

        $reply = self::_process_sms_step($phone, strtolower(trim($body)), $state);

        $state['_last_activity'] = time();
        update_option($state_key, $state, false);

        if ($reply) {
            self::_send_sms($phone, $reply);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Process conversational SMS step
     */
    private static function _process_sms_step($phone, $body, &$state) {
        global $wpdb;

        $step = $state['step'];
        $data = &$state['data'];

        // Reset command
        if (in_array($body, ['restart', 'reset', 'start over', 'cancel'])) {
            $state = ['step' => 'start', 'data' => []];
            return "No problem! Text BOOK to schedule a training session, or HELP for options.";
        }

        switch ($step) {
            case 'start':
                if (strpos($body, 'book') !== false || strpos($body, 'schedule') !== false || strpos($body, 'train') !== false) {
                    $state['step'] = 'pick_location';
                    // Get locations
                    $locs = class_exists('PTP_Admin') ? PTP_Admin::get_ptp_training_locations() : [];
                    $loc_list = '';
                    $i = 1;
                    foreach ($locs as $l) {
                        $loc_list .= "\n{$i}. {$l['name']} ({$l['address']})";
                        $data['locations'][$i] = $l;
                        $i++;
                    }
                    return "Where would you like to train?{$loc_list}\n\nReply with the number.";
                }
                if (strpos($body, 'help') !== false || strpos($body, 'hi') !== false || strpos($body, 'hello') !== false) {
                    return "Welcome to PTP Soccer Training!\n\nText:\nBOOK - Schedule a session\nSTATUS - Check your bookings\nHELP - See this menu\n\nOr visit " . home_url('/find-trainers/');
                }
                if (strpos($body, 'status') !== false) {
                    // Look up bookings by phone — check structured meta first, fall back to notes
                    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                    $phone_variants = [];
                    if (strlen($clean_phone) >= 10) {
                        $phone_variants[] = $clean_phone;
                        $phone_variants[] = substr($clean_phone, -10); // last 10 digits
                        $phone_variants[] = '+1' . substr($clean_phone, -10);
                        $phone_variants[] = '+' . $clean_phone;
                    }

                    // Search booking meta options for this phone
                    $meta_booking_ids = [];
                    $meta_rows = $wpdb->get_results(
                        "SELECT option_name, option_value FROM {$wpdb->options}
                         WHERE option_name LIKE 'ptp_booking_meta_%' ORDER BY option_name DESC LIMIT 100"
                    );
                    foreach ($meta_rows as $row) {
                        $meta = maybe_unserialize($row->option_value);
                        if (!is_array($meta)) continue;
                        $meta_phone = preg_replace('/[^0-9]/', '', $meta['parent_phone'] ?? '');
                        foreach ($phone_variants as $variant) {
                            $variant_clean = preg_replace('/[^0-9]/', '', $variant);
                            if ($variant_clean && $meta_phone && str_ends_with($meta_phone, substr($variant_clean, -10))) {
                                $bid = intval(str_replace('ptp_booking_meta_', '', $row->option_name));
                                if ($bid) $meta_booking_ids[] = $bid;
                                break;
                            }
                        }
                    }

                    $bookings = [];
                    if ($meta_booking_ids) {
                        $ids_in = implode(',', array_map('intval', array_unique($meta_booking_ids)));
                        $bookings = $wpdb->get_results(
                            "SELECT b.booking_number, b.session_date, b.start_time, b.status,
                                    t.display_name
                             FROM {$wpdb->prefix}ptp_bookings b
                             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                             WHERE b.id IN ({$ids_in}) AND b.status NOT IN ('cancelled')
                             ORDER BY b.session_date DESC LIMIT 3"
                        );
                    }

                    // Fallback to notes search if meta found nothing
                    if (!$bookings && $clean_phone) {
                        $bookings = $wpdb->get_results($wpdb->prepare(
                            "SELECT b.booking_number, b.session_date, b.start_time, b.status,
                                    t.display_name
                             FROM {$wpdb->prefix}ptp_bookings b
                             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                             WHERE b.notes LIKE %s AND b.status NOT IN ('cancelled')
                             ORDER BY b.session_date DESC LIMIT 3",
                            '%' . $wpdb->esc_like($clean_phone) . '%'
                        ));
                    }
                    if (!$bookings) {
                        return "No upcoming bookings found for this number. Text BOOK to schedule!";
                    }
                    $msg = "Your bookings:";
                    foreach ($bookings as $b) {
                        $msg .= "\n" . date('M j', strtotime($b->session_date)) . " " . date('g:iA', strtotime($b->start_time)) . " - " . $b->display_name . " [" . strtoupper($b->status) . "]";
                    }
                    return $msg;
                }
                return "Hi! Welcome to PTP Soccer. Text BOOK to schedule a training session, STATUS to check bookings, or HELP for options.";

            case 'pick_location':
                $num = intval($body);
                if ($num && isset($data['locations'][$num])) {
                    $data['location'] = $data['locations'][$num];
                    $state['step'] = 'pick_trainer';

                    // Find trainers at this location
                    $loc_name = $data['location']['name'];
                    $like = '%' . $wpdb->esc_like($loc_name) . '%';
                    $trainers = $wpdb->get_results($wpdb->prepare(
                        "SELECT id, display_name, hourly_rate, college
                         FROM {$wpdb->prefix}ptp_trainers
                         WHERE status = 'active' AND training_locations LIKE %s
                         ORDER BY is_featured DESC, average_rating DESC LIMIT 5",
                        $like
                    ));

                    if (!$trainers) {
                        $state['step'] = 'start';
                        return "No trainers available at {$loc_name} right now. Text BOOK to try another location.";
                    }

                    $data['trainers'] = [];
                    $msg = "Trainers at {$loc_name}:";
                    $i = 1;
                    foreach ($trainers as $t) {
                        $msg .= "\n{$i}. {$t->display_name} - \${$t->hourly_rate}/hr" . ($t->college ? " ({$t->college})" : '');
                        $data['trainers'][$i] = (array) $t;
                        $i++;
                    }
                    $msg .= "\n\nReply with the number.";
                    return $msg;
                }
                return "Please reply with a number from the list, or text RESTART to start over.";

            case 'pick_trainer':
                $num = intval($body);
                if ($num && isset($data['trainers'][$num])) {
                    $data['trainer'] = $data['trainers'][$num];
                    $state['step'] = 'pick_date';

                    // Show next available dates
                    $tid = $data['trainer']['id'];
                    $dates_msg = '';
                    $data['dates'] = [];
                    $j = 1;
                    for ($i = 0; $i < 14 && $j <= 5; $i++) {
                        $d = date('Y-m-d', strtotime("+{$i} days"));
                        if (class_exists('PTP_Availability')) {
                            $slots = PTP_Availability::get_available_slots($tid, $d);
                            if ($slots) {
                                $dates_msg .= "\n{$j}. " . date('D, M j', strtotime($d)) . " (" . count($slots) . " slots)";
                                $data['dates'][$j] = ['date' => $d, 'slots' => $slots];
                                $j++;
                            }
                        }
                    }

                    if (!$data['dates']) {
                        $state['step'] = 'pick_location';
                        return $data['trainer']['display_name'] . " has no availability in the next 2 weeks. Reply with another trainer number or RESTART.";
                    }

                    return "When works for " . $data['trainer']['display_name'] . "?" . $dates_msg . "\n\nReply with the number.";
                }
                return "Please reply with a trainer number, or text RESTART.";

            case 'pick_date':
                $num = intval($body);
                if ($num && isset($data['dates'][$num])) {
                    $data['date_pick'] = $data['dates'][$num];
                    $state['step'] = 'pick_time';

                    $slots = $data['date_pick']['slots'];
                    $msg = "Times on " . date('D, M j', strtotime($data['date_pick']['date'])) . ":";
                    $data['times'] = [];
                    $i = 1;
                    foreach (array_slice($slots, 0, 8) as $s) {
                        $msg .= "\n{$i}. " . $s['display'];
                        $data['times'][$i] = $s;
                        $i++;
                    }
                    $msg .= "\n\nReply with the number.";
                    return $msg;
                }
                return "Please reply with a date number, or RESTART.";

            case 'pick_time':
                $num = intval($body);
                if ($num && isset($data['times'][$num])) {
                    $data['time_pick'] = $data['times'][$num];
                    $state['step'] = 'get_name';
                    return "What is the parent/guardian's name?";
                }
                return "Please reply with a time number, or RESTART.";

            case 'get_name':
                if (strlen($body) >= 2) {
                    $data['parent_name'] = ucwords($body);
                    $state['step'] = 'get_email';
                    return "What is your email address?";
                }
                return "Please enter a valid name.";

            case 'get_email':
                if (is_email($body)) {
                    $data['parent_email'] = $body;
                    $state['step'] = 'get_player';
                    return "What is the player's first name?";
                }
                return "Please enter a valid email address.";

            case 'get_player':
                if (strlen($body) >= 2) {
                    $data['player_name'] = ucwords($body);
                    $state['step'] = 'get_age';
                    return "How old is " . $data['player_name'] . "? (Reply with a number, or SKIP)";
                }
                return "Please enter the player's name.";

            case 'get_age':
                $age = intval($body);
                if ($age >= 3 && $age <= 18) {
                    $data['player_age'] = $age;
                } elseif (in_array($body, ['skip', 'na', 'n/a', 'none', '0'])) {
                    $data['player_age'] = 0;
                } else {
                    return "Please enter an age between 3-18, or SKIP.";
                }
                $state['step'] = 'confirm';

                    $trainer = $data['trainer'];
                    $date = date('l, M j', strtotime($data['date_pick']['date']));
                    $time = $data['time_pick']['display'];
                    $loc = $data['location']['name'];

                    return "Please confirm your booking:\n\n"
                         . "Trainer: {$trainer['display_name']}\n"
                         . "Date: {$date}\n"
                         . "Time: {$time}\n"
                         . "Location: {$loc}\n"
                         . "Player: {$data['player_name']}"
                         . ($data['player_age'] ? " (Age {$data['player_age']})" : '') . "\n"
                         . "Rate: \${$trainer['hourly_rate']}\n\n"
                         . "Reply YES to confirm or NO to cancel.";

            case 'confirm':
                if (in_array($body, ['yes', 'y', 'confirm', 'yep', 'yeah'])) {
                    // Create the booking
                    $req = new WP_REST_Request('POST');
                    $req->set_param('trainer_id', $data['trainer']['id']);
                    $req->set_param('date', $data['date_pick']['date']);
                    $req->set_param('time', $data['time_pick']['time'] ?? $data['time_pick']['start']);
                    $req->set_param('parent_name', $data['parent_name']);
                    $req->set_param('parent_email', $data['parent_email']);
                    $req->set_param('parent_phone', $phone);
                    $req->set_param('player_name', $data['player_name']);
                    $req->set_param('player_age', $data['player_age'] ?? 0);
                    $req->set_param('location', $data['location']['name']);
                    $req->set_param('send_sms', false); // We'll send our own

                    $result = self::create_booking($req);
                    $resp = $result->get_data();

                    // Reset state
                    $state = ['step' => 'start', 'data' => []];

                    if (!empty($resp['success'])) {
                        return "Booking confirmed! " . $resp['booking']['booking_number'] . "\n\n"
                             . $resp['booking']['trainer_name'] . "\n"
                             . $resp['booking']['formatted_date'] . " at " . $resp['booking']['formatted_time'] . "\n"
                             . $resp['booking']['location'] . "\n\n"
                             . "Complete payment: " . $resp['checkout_url'] . "\n\n"
                             . "Text STATUS anytime to check your bookings.";
                    } else {
                        return "Sorry, that slot was just taken. Text BOOK to try again.";
                    }
                }
                if (in_array($body, ['no', 'n', 'cancel', 'nah'])) {
                    $state = ['step' => 'start', 'data' => []];
                    return "Booking cancelled. Text BOOK to start over anytime!";
                }
                return "Reply YES to confirm or NO to cancel.";
        }

        return "Text BOOK to schedule, STATUS to check bookings, or HELP for options.";
    }

    /* ═══════════════════════════════════════════════
       LEGACY ALIAS: trainers-summary
       ═══════════════════════════════════════════════ */

    public static function trainers_summary($request) {
        global $wpdb;
        $trainers = $wpdb->get_results(
            "SELECT id, display_name, location, hourly_rate, college, average_rating
             FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'
             ORDER BY is_featured DESC, average_rating DESC LIMIT 50"
        );
        $out = [];
        foreach ($trainers as $t) {
            $out[] = [
                'id'       => absint($t->id),
                'name'     => $t->display_name,
                'location' => $t->location,
                'rate'     => floatval($t->hourly_rate),
                'rating'   => floatval($t->average_rating),
                'college'  => $t->college,
            ];
        }
        return rest_ensure_response(['trainers' => $out, 'count' => count($out)]);
    }

    /* ═══════════════════════════════════════════════
       INTERNAL: Send SMS via OpenPhone
       ═══════════════════════════════════════════════ */

    private static function _send_sms($phone, $message) {
        // Format phone
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 10) $phone = '1' . $phone;
        if (strpos($phone, '+') !== 0) $phone = '+' . $phone;

        // Try PTP_SMS_V71 first
        if (class_exists('PTP_SMS_V71')) {
            PTP_SMS_V71::init();
            $result = PTP_SMS_V71::send($phone, $message);
            return !is_wp_error($result);
        }

        // Fallback: direct OpenPhone API
        $api_key = get_option('ptp_openphone_api_key', '');
        $from_id = get_option('ptp_openphone_from', '');

        if (!$api_key || !$from_id) {
            ptp_log('[PTP Chatbot API] OpenPhone not configured — cannot send SMS');
            return false;
        }

        $response = wp_remote_post('https://api.openphone.com/v1/messages', [
            'headers' => [
                'Authorization' => $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'content' => $message,
                'from'    => $from_id,
                'to'      => [$phone],
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            ptp_log('[PTP Chatbot API] OpenPhone error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) return true;

        ptp_log('[PTP Chatbot API] OpenPhone HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
        return false;
    }

    /* ═══════════════════════════════════════════════
       UTILITY
       ═══════════════════════════════════════════════ */

    public static function get_api_key() {
        return get_option(self::KEY_OPTION, '');
    }

    public static function regenerate_api_key() {
        $key = 'ptp_' . wp_generate_password(32, false, false);
        update_option(self::KEY_OPTION, $key);
        return $key;
    }
}

add_action('plugins_loaded', ['PTP_Chatbot_API', 'init'], 99);
