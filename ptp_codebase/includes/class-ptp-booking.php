<?php
/**
 * Booking Class
 */

defined('ABSPATH') || exit;

class PTP_Booking {
    
    public static function get($booking_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
    }
    
    public static function get_by_number($booking_number) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE booking_number = %s",
            $booking_number
        ));
    }
    
    public static function get_full($booking_id) {
        global $wpdb;
        // v216: Use try/catch + fallback for safety. JOINed tables may not exist on all installs.
        try {
            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, 
                        t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug, 
                        t.user_id as trainer_user_id, t.email as trainer_email, t.phone as trainer_phone,
                        t.headline as trainer_headline, t.college as trainer_college,
                        pa.display_name as parent_name, pa.user_id as parent_user_id, pa.email as parent_email, pa.phone as parent_phone,
                        pl.name as player_name, pl.age as player_age, pl.position as player_position, pl.goals as player_goals, pl.skill_level as player_skill
                 FROM {$wpdb->prefix}ptp_bookings b
                 JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
                 LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
                 WHERE b.id = %d",
                $booking_id
            ));

            // If JOIN returned NULL parent/player, pull from free session application
            if ($result && (empty($result->parent_name) || empty($result->player_name))) {
                $source = $result->source ?? '';
                $is_free_source = strpos($source, 'free_session') !== false;
                
                // Try to find matching application by email
                $lookup_email = $result->guest_email ?: ($result->parent_email ?: '');
                
                // If no email yet but we have parent_id, get it from ptp_parents
                if (!$lookup_email && !empty($result->parent_id)) {
                    $lookup_email = $wpdb->get_var($wpdb->prepare(
                        "SELECT email FROM {$wpdb->prefix}ptp_parents WHERE id=%d", $result->parent_id
                    ));
                }
                
                $app = null;
                if ($lookup_email) {
                    if ($is_free_source && ($result->trainer_slug ?? '')) {
                        // Specific: match by email + trainer
                        $app = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}ptp_session_applications 
                             WHERE email = %s AND trainer_slug = %s ORDER BY id DESC LIMIT 1",
                            $lookup_email, $result->trainer_slug
                        ));
                    }
                    if (!$app) {
                        // Broader: just by email
                        $app = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}ptp_session_applications 
                             WHERE email = %s ORDER BY id DESC LIMIT 1",
                            $lookup_email
                        ));
                    }
                }
                
                if ($app) {
                    if (empty($result->parent_name))     $result->parent_name     = $app->parent_name;
                    if (empty($result->player_name))     $result->player_name     = $app->child_name;
                    if (empty($result->parent_email))    $result->parent_email    = $app->email;
                    if (empty($result->parent_phone))    $result->parent_phone    = $app->phone;
                    if (empty($result->player_age))      $result->player_age      = $app->child_age;
                    if (empty($result->player_position)) $result->player_position = $app->position ?? '';
                    if (empty($result->player_goals))    $result->player_goals    = trim(($app->biggest_challenge ?? '') . '. ' . ($app->goal ?? ''));
                    if (empty($result->player_skill))    $result->player_skill    = $app->experience_level ?? '';
                    if (empty($result->source))          $result->source          = 'free_session_lookup';
                    $result->_fsa_source = $app;
                }
            }

            if ($result) return $result;
        } catch (\Throwable $e) {
            ptp_log('PTP Booking::get_full JOIN failed: ' . $e->getMessage());
        }
        // Fallback: basic booking row if JOINs fail (missing table, etc.)
        return self::get($booking_id);
    }
    
    public static function create($data) {
        global $wpdb;
        
        // Ensure table has correct columns
        PTP_Database::quick_repair();
        
        // Validate required fields
        $required = array('trainer_id', 'parent_id', 'player_id', 'session_date', 'start_time');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Missing required field: $field");
            }
        }
        
        // Start transaction for atomicity
        $wpdb->query('START TRANSACTION');
        
        try {
            // Check for double booking (with overlap detection)
            $duration = intval($data['duration_minutes'] ?? 60);
            $conflict_id = self::has_conflict($data['trainer_id'], $data['session_date'], $data['start_time'], $duration);
            if ($conflict_id) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('slot_taken', 'This time slot is no longer available (conflicts with booking #' . $conflict_id . ')');
            }
            
            // Get trainer rate
            $trainer = PTP_Trainer::get($data['trainer_id']);
            if (!$trainer) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('invalid_trainer', 'Trainer not found');
            }
            
            $hourly_rate = floatval($trainer->hourly_rate ?: 70);
            $duration = intval($data['duration_minutes'] ?? 60);
            $total_amount = ($hourly_rate * $duration) / 60;
            $platform_fee = $total_amount * ptp_get_platform_fee(); // Uses admin-configurable setting
            $trainer_payout = $total_amount - $platform_fee;
            
            // Calculate end time
            $start_time = $data['start_time'];
            $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
            
            // v236: Priority 1 — the specific location parent selected during checkout (e.g. "Villanova Stadium")
            // Priority 2 — trainer's profile location / city+state as fallback
            $trainer_location = '';
            if (!empty($data['location'])) {
                $trainer_location = sanitize_text_field($data['location']);
            } elseif (!empty($trainer->location)) {
                $trainer_location = $trainer->location;
            } elseif (!empty($trainer->city)) {
                $trainer_location = $trainer->city . (!empty($trainer->state) ? ', ' . $trainer->state : '');
            }
            // v236: Fallback to first training_locations entry
            if (empty($trainer_location) && !empty($trainer->training_locations)) {
                $tl = json_decode($trainer->training_locations, true);
                if (!is_array($tl)) $tl = json_decode(wp_unslash($trainer->training_locations), true);
                if (is_array($tl)) {
                    foreach ($tl as $_loc) {
                        if (is_array($_loc) && !empty($_loc['name'])) {
                            $trainer_location = $_loc['name'];
                            break;
                        }
                    }
                }
            }
            
            // Build insert data - only use columns we're sure exist
            $insert_data = array(
                'booking_number' => self::generate_booking_number(),
                'trainer_id' => intval($data['trainer_id']),
                'parent_id' => intval($data['parent_id']),
                'player_id' => intval($data['player_id']),
                'session_date' => sanitize_text_field($data['session_date']),
                'start_time' => $start_time,
                'end_time' => $end_time,
                'duration_minutes' => $duration,
                'location' => $trainer_location,
                'location_notes' => sanitize_text_field($data['location_notes'] ?? ($data['location_address'] ?? '')),
                'hourly_rate' => $hourly_rate,
                'total_amount' => $total_amount,
                'platform_fee' => $platform_fee,
                'trainer_payout' => $trainer_payout,
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'notes' => sanitize_textarea_field($data['notes'] ?? ''),
                'source' => sanitize_text_field($data['source'] ?? ''),
                'guest_email' => sanitize_email($data['parent_email'] ?? ($data['guest_email'] ?? '')),
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ptp_log('PTP Booking: Creating for trainer_id=' . $data['trainer_id'] . ', date=' . $data['session_date']);
            }
            
            $result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $insert_data);
            
            if ($result === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    ptp_log('PTP Booking Create Failed: ' . $wpdb->last_error);
                }
                
                // Try one more repair and retry
                PTP_Database::repair_tables();
                $result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $insert_data);
                
                if ($result === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('db_error', 'Failed to create booking: ' . $wpdb->last_error);
                }
            }
            
            $booking_id = $wpdb->insert_id;
            
            // Commit the transaction
            $wpdb->query('COMMIT');
            
            // Send notifications (wrapped in try-catch to not break booking)
            try {
                if (class_exists('PTP_Notifications')) {
                    PTP_Notifications::booking_created($booking_id);
                }
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    ptp_log('PTP Notification Error: ' . $e->getMessage());
                }
            }
            
            return $booking_id;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ptp_log('PTP Booking Exception: ' . $e->getMessage());
            }
            return new WP_Error('booking_error', 'An error occurred while creating the booking');
        }
    }
    
    /**
     * Check if exact start_time is already booked (legacy — use has_conflict for overlap-aware check)
     */
    public static function is_slot_booked($trainer_id, $date, $time) {
        global $wpdb;
        
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date = %s AND start_time = %s 
             AND status NOT IN ('cancelled', 'no_show', 'refunded')",
            $trainer_id, $date, $time
        ));
    }

    /**
     * v223-fix: Overlap-aware conflict check.
     * Returns conflicting booking ID, or false if no conflict.
     * Checks if a proposed session (date + start_time + duration) overlaps ANY existing active booking.
     *
     * @param int    $trainer_id
     * @param string $date        Y-m-d
     * @param string $start_time  H:i or H:i:s
     * @param int    $duration    minutes (default 60)
     * @param int    $exclude_id  booking ID to exclude (for reschedule/update)
     * @return int|false  conflicting booking id or false
     */
    public static function has_conflict($trainer_id, $date, $start_time, $duration = 60, $exclude_id = 0) {
        global $wpdb;

        // Normalise times to H:i:s
        $start = date('H:i:s', strtotime($start_time));
        $end   = date('H:i:s', strtotime($start_time) + ($duration * 60));

        // A new session [S1, E1] conflicts with existing [S2, E2] when S1 < E2 AND E1 > S2
        $sql = $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings
             WHERE trainer_id = %d
               AND session_date = %s
               AND status NOT IN ('cancelled', 'no_show', 'refunded')
               AND %s < end_time
               AND %s > start_time",
            $trainer_id, $date, $start, $end
        );

        if ($exclude_id > 0) {
            $sql .= $wpdb->prepare(" AND id != %d", $exclude_id);
        }

        $sql .= " LIMIT 1";

        $conflict = $wpdb->get_var($sql);
        return $conflict ? intval($conflict) : false;
    }
    
    public static function update_status($booking_id, $status, $user_id = null) {
        global $wpdb;
        
        $valid_statuses = array('pending', 'confirmed', 'completed', 'cancelled', 'no_show');
        if (!in_array($status, $valid_statuses)) {
            return new WP_Error('invalid_status', 'Invalid booking status');
        }
        
        $update_data = array('status' => $status);
        
        if ($status === 'cancelled' && $user_id) {
            $update_data['cancelled_by'] = $user_id;
            $update_data['cancelled_at'] = current_time('mysql');
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            $update_data,
            array('id' => $booking_id)
        );
        
        if ($result !== false && $status === 'completed') {
            $booking = self::get($booking_id);
            PTP_Trainer::update_stats($booking->trainer_id);
            PTP_Parent::update_stats($booking->parent_id);
            
            // Fire action for other integrations (trainer referrals, email automation, etc.)
            do_action('ptp_session_completed', $booking_id, $booking);
            do_action('ptp_booking_completed', $booking_id, $booking);
        }
        
        return $result;
    }
    
    public static function confirm_by_parent($booking_id, $parent_id) {
        global $wpdb;
        
        $booking = self::get($booking_id);
        if (!$booking || $booking->parent_id != $parent_id) {
            return new WP_Error('invalid_booking', 'Booking not found');
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'parent_confirmed' => 1,
                'parent_confirmed_at' => current_time('mysql'),
            ),
            array('id' => $booking_id)
        );
        
        // Check if both confirmed
        self::check_completion($booking_id);
        
        return true;
    }
    
    public static function confirm_by_trainer($booking_id, $trainer_id) {
        global $wpdb;
        
        $booking = self::get($booking_id);
        if (!$booking || $booking->trainer_id != $trainer_id) {
            return new WP_Error('invalid_booking', 'Booking not found');
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'trainer_confirmed' => 1,
                'trainer_confirmed_at' => current_time('mysql'),
            ),
            array('id' => $booking_id)
        );
        
        // Check if both confirmed
        self::check_completion($booking_id);
        
        return true;
    }
    
    private static function check_completion($booking_id) {
        global $wpdb;
        
        $booking = self::get($booking_id);
        if (!$booking) return;
        
        $parent_confirmed = isset($booking->parent_confirmed) ? $booking->parent_confirmed : 0;
        $trainer_confirmed = isset($booking->trainer_confirmed) ? $booking->trainer_confirmed : 0;
        
        if ($parent_confirmed && $trainer_confirmed) {
            self::update_status($booking_id, 'completed');
            
            // v216: Release funds via escrow system — look up escrow_id from booking
            if (class_exists('PTP_Escrow')) {
                $escrow_table = $wpdb->prefix . 'ptp_escrow';
                $escrow_id = null;
                if ($wpdb->get_var("SHOW TABLES LIKE '{$escrow_table}'") === $escrow_table) {
                    $escrow_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$escrow_table} WHERE booking_id = %d",
                        $booking_id
                    ));
                }
                
                if ($escrow_id && method_exists('PTP_Escrow', 'release_funds')) {
                    $result = PTP_Escrow::release_funds($escrow_id);
                    if (is_wp_error($result) && defined('WP_DEBUG') && WP_DEBUG) {
                        ptp_log('PTP Booking: Escrow release failed for booking ' . $booking_id . ' (escrow ' . $escrow_id . '): ' . $result->get_error_message());
                    }
                }
            }
            
            // Fire completion hooks safely
            try {
                do_action('ptp_session_completed', $booking_id, $booking);
                do_action('ptp_booking_ready_for_payout', $booking_id, $booking);
            } catch (\Throwable $e) {
                ptp_log('PTP Booking: Hook error on completion for booking ' . $booking_id . ': ' . $e->getMessage());
            }
        }
    }
    
    public static function get_trainer_bookings($trainer_id, $status = null, $upcoming = true) {
        global $wpdb;
        
        $where = "b.trainer_id = %d";
        $params = array($trainer_id);
        
        if ($status) {
            $where .= " AND b.status = %s";
            $params[] = $status;
        }
        
        if ($upcoming) {
            $where .= " AND b.session_date >= CURDATE()";
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, pa.display_name as parent_name, pl.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE $where
             ORDER BY b.session_date ASC, b.start_time ASC",
            $params
        ));
    }
    
    public static function get_pending_confirmations($trainer_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, pa.display_name as parent_name, pl.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE b.trainer_id = %d AND b.status = 'confirmed' AND b.trainer_confirmed = 0
             AND b.session_date < CURDATE()
             ORDER BY b.session_date DESC",
            $trainer_id
        ));
    }
    
    private static function generate_booking_number() {
        return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    }
    
    /**
     * Get all bookings for a trainer on a specific date
     * Used by booking wizard for availability checking
     */
    public static function get_trainer_bookings_for_date($trainer_id, $date) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, start_time, end_time, duration_minutes, status
             FROM {$wpdb->prefix}ptp_bookings
             WHERE trainer_id = %d 
             AND session_date = %s
             AND status NOT IN ('cancelled', 'no_show')
             ORDER BY start_time ASC",
            $trainer_id,
            $date
        ));
    }
}
