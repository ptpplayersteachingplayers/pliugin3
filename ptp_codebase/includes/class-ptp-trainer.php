<?php
/**
 * Trainer Class v54.0
 * Added: sort_order support for manual trainer ranking
 * Added: bulk update methods for featuring/ranking
 * Added: robust error handling and validation
 * Fixed: training_locations now included in allowed update fields
 * Added: get_by_email method
 * Added: link_to_user method
 * Fixed: is_new_trainer robustness
 */

defined('ABSPATH') || exit;

class PTP_Trainer {
    
    /**
     * Log trainer-related events
     */
    private static function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log("PTP_Trainer [{$level}]: {$message}");
        }
    }
    
    public static function get($trainer_id) {
        if (!$trainer_id || !is_numeric($trainer_id)) {
            return null;
        }
        
        // Check cache first
        $cache_key = 'ptp_trainer_' . $trainer_id;
        $cached = wp_cache_get($cache_key, 'ptp');
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        // Ensure trainer has a slug
        if ($trainer && empty($trainer->slug)) {
            $trainer = self::ensure_slug($trainer);
        }
        
        // Cache for 5 minutes
        if ($trainer) {
            wp_cache_set($cache_key, $trainer, 'ptp', 300);
        }
        
        return $trainer;
    }
    
    public static function get_by_user_id($user_id) {
        if (!$user_id || !is_numeric($user_id)) {
            return null;
        }
        
        // Check cache first
        $cache_key = 'ptp_trainer_user_' . $user_id;
        $cached = wp_cache_get($cache_key, 'ptp');
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        // Ensure trainer has a slug
        if ($trainer && empty($trainer->slug)) {
            $trainer = self::ensure_slug($trainer);
        }
        
        // Cache for 5 minutes
        if ($trainer) {
            wp_cache_set($cache_key, $trainer, 'ptp', 300);
        }
        
        return $trainer;
    }
    
    public static function get_by_slug($slug) {
        if (empty($slug)) return null;
        
        // Check cache first
        $cache_key = 'ptp_trainer_slug_' . sanitize_key($slug);
        $cached = wp_cache_get($cache_key, 'ptp_trainers');
        if ($cached !== false) {
            return $cached;
        }
        
        global $wpdb;
        
        // Try exact match first
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s",
            $slug
        ));
        
        // If no exact match, try case-insensitive match
        if (!$trainer) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE LOWER(slug) = LOWER(%s)",
                $slug
            ));
        }
        
        // If still no match, try matching by display_name converted to slug format
        if (!$trainer) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE LOWER(REPLACE(REPLACE(display_name, ' ', '-'), '.', '')) = LOWER(%s)",
                $slug
            ));
        }
        
        // Cache for 5 minutes
        wp_cache_set($cache_key, $trainer, 'ptp_trainers', 300);
        
        return $trainer;
    }
    
    /**
     * Get trainer by email address
     */
    public static function get_by_email($email) {
        if (empty($email)) return null;
        
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE email = %s",
            $email
        ));
    }
    
    /**
     * Link trainer to user ID (for trainers created before user account)
     */
    public static function link_to_user($trainer_id, $user_id) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('user_id' => $user_id),
            array('id' => $trainer_id),
            array('%d'),
            array('%d')
        );
    }
    
    /**
     * Ensure a trainer has a valid slug, generate one if missing
     */
    public static function ensure_slug($trainer) {
        if (!$trainer || !empty($trainer->slug)) {
            return $trainer;
        }
        
        global $wpdb;
        
        // Generate slug from display name
        $base_slug = sanitize_title($trainer->display_name ?: 'trainer');
        $slug = $base_slug;
        $counter = 1;
        
        // Ensure uniqueness
        while ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s AND id != %d",
            $slug, $trainer->id
        ))) {
            $slug = $base_slug . '-' . $counter;
            $counter++;
            if ($counter > 100) {
                $slug = $base_slug . '-' . time();
                break;
            }
        }
        
        // Save the slug
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('slug' => $slug),
            array('id' => $trainer->id),
            array('%s'),
            array('%d')
        );
        
        // Update the object
        $trainer->slug = $slug;
        
        return $trainer;
    }
    
    public static function get_all($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'status' => 'active',
            'orderby' => 'is_featured DESC, sort_order ASC, average_rating',
            'order' => 'DESC',
            'limit' => 50,
            'offset' => 0,
            'search' => '',
            'specialty' => '',
            'min_rate' => 0,
            'max_rate' => 9999,
            'latitude' => null,
            'longitude' => null,
            'radius' => 50,
            'featured_only' => false,
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array("status = %s");
        $params = array($args['status']);
        
        if (!empty($args['featured_only'])) {
            $where[] = "is_featured = 1";
        }
        
        if (!empty($args['search'])) {
            $where[] = "(display_name LIKE %s OR headline LIKE %s OR location LIKE %s)";
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        if (!empty($args['specialty'])) {
            $where[] = "specialties LIKE %s";
            $params[] = '%' . $wpdb->esc_like($args['specialty']) . '%';
        }
        
        if ($args['min_rate'] > 0) {
            $where[] = "hourly_rate >= %f";
            $params[] = $args['min_rate'];
        }
        
        if ($args['max_rate'] < 9999) {
            $where[] = "hourly_rate <= %f";
            $params[] = $args['max_rate'];
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Distance calculation if coordinates provided
        $select = "*";
        if ($args['latitude'] && $args['longitude']) {
            $select .= ", (3959 * acos(cos(radians(%f)) * cos(radians(latitude)) * cos(radians(longitude) - radians(%f)) + sin(radians(%f)) * sin(radians(latitude)))) AS distance";
            array_unshift($params, $args['latitude'], $args['longitude'], $args['latitude']);
            
            $where_clause .= " HAVING distance <= %f";
            $params[] = $args['radius'];
        }
        
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'is_featured DESC, sort_order ASC, average_rating DESC';
        }
        
        $sql = "SELECT $select FROM {$wpdb->prefix}ptp_trainers WHERE $where_clause ORDER BY $orderby LIMIT %d OFFSET %d";
        $params[] = $args['limit'];
        $params[] = $args['offset'];
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    public static function create($user_id, $data = array()) {
        global $wpdb;
        
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('invalid_user', 'User not found');
        }
        
        $display_name = !empty($data['display_name']) ? $data['display_name'] : $user->display_name;
        $slug = self::generate_unique_slug($display_name);
        
        // v118.1: Get email from data or from WordPress user
        $email = !empty($data['email']) ? sanitize_email($data['email']) : $user->user_email;
        
        $insert_data = array(
            'user_id' => $user_id,
            'display_name' => sanitize_text_field($display_name),
            'slug' => $slug,
            'email' => $email,
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'headline' => sanitize_text_field($data['headline'] ?? ''),
            'bio' => sanitize_textarea_field($data['bio'] ?? ''),
            'photo_url' => esc_url_raw($data['photo_url'] ?? ''),
            'hourly_rate' => floatval($data['hourly_rate'] ?? 0),
            'location' => sanitize_text_field($data['location'] ?? ''),
            'travel_radius' => intval($data['travel_radius'] ?? 15),
            'college' => sanitize_text_field($data['college'] ?? ''),
            'team' => sanitize_text_field($data['team'] ?? ''),
            'position' => sanitize_text_field($data['position'] ?? ''),
            'specialties' => is_array($data['specialties'] ?? null) ? implode(',', array_map('sanitize_text_field', $data['specialties'])) : sanitize_text_field($data['specialties'] ?? ''),
            'status' => 'pending',
        );
        
        $result = $wpdb->insert($wpdb->prefix . 'ptp_trainers', $insert_data);
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create trainer');
        }
        
        return $wpdb->insert_id;
    }
    
    public static function update($trainer_id, $data) {
        global $wpdb;
        
        $update_data = array();
        $allowed_fields = array(
            'display_name', 'slug', 'headline', 'bio', 'photo_url', 'hourly_rate',
            'location', 'city', 'state', 'latitude', 'longitude', 'travel_radius', 'college',
            'team', 'position', 'specialties', 'instagram', 'facebook', 'twitter', 'status', 'is_featured',
            'is_verified', 'is_background_checked', 'email', 'phone', 'playing_level',
            'training_locations', 'gallery', 'intro_video_url', 'stripe_account_id',
            // Extended fields (v87.6)
            'experience_years', 'coaching_why', 'training_philosophy', 'training_policy',
            'bio_sections', 'session_preferences', 'lesson_lengths', 'max_participants'
        );
        
        // Fields that should NOT be sanitized (JSON data)
        $json_fields = array('training_locations', 'gallery', 'bio_sections', 'session_preferences');
        
        // Text fields that need textarea sanitization
        $textarea_fields = array('bio', 'coaching_why', 'training_philosophy', 'training_policy');
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                if ($field === 'specialties' && is_array($data[$field])) {
                    $update_data[$field] = implode(',', array_map('sanitize_text_field', $data[$field]));
                } elseif (in_array($field, array('hourly_rate', 'latitude', 'longitude'))) {
                    $update_data[$field] = floatval($data[$field]);
                } elseif (in_array($field, array('travel_radius', 'is_featured', 'is_verified', 'is_background_checked', 'experience_years', 'max_participants'))) {
                    $update_data[$field] = intval($data[$field]);
                } elseif ($field === 'slug') {
                    // Validate and sanitize slug, ensure uniqueness
                    $new_slug = self::generate_unique_slug($data[$field], $trainer_id);
                    if ($new_slug) {
                        $update_data[$field] = $new_slug;
                    }
                } elseif (in_array($field, $json_fields)) {
                    // JSON fields - store as-is (already validated/encoded by caller)
                    $update_data[$field] = $data[$field];
                } elseif (in_array($field, $textarea_fields)) {
                    // Textarea fields - allow line breaks
                    $update_data[$field] = sanitize_textarea_field($data[$field]);
                } else {
                    $update_data[$field] = sanitize_text_field($data[$field]);
                }
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            $update_data,
            array('id' => $trainer_id)
        );
        
        // Clear cache on update
        if ($result !== false) {
            wp_cache_delete('ptp_trainer_' . $trainer_id, 'ptp');
            // Also clear user-based cache if we can find the user_id
            $user_id = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $trainer_id
            ));
            if ($user_id) {
                wp_cache_delete('ptp_trainer_user_' . $user_id, 'ptp');
            }
            
            // Clear trainers list transients
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ptp_trainers_default_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ptp_trainers_default_%'");
        }
        
        return $result;
    }
    
    /**
     * Generate a unique SEO-friendly slug
     */
    public static function generate_unique_slug($base_slug, $exclude_trainer_id = null) {
        global $wpdb;
        
        // Sanitize the base slug
        $slug = sanitize_title($base_slug);
        
        // Remove any numbers that were appended by previous slug generation
        $slug = preg_replace('/-\d+$/', '', $slug);
        
        if (empty($slug)) {
            return false;
        }
        
        // Check if slug exists (excluding current trainer)
        $where_clause = $exclude_trainer_id 
            ? $wpdb->prepare("WHERE slug = %s AND id != %d", $slug, $exclude_trainer_id)
            : $wpdb->prepare("WHERE slug = %s", $slug);
            
        $exists = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}ptp_trainers {$where_clause}");
        
        if (!$exists) {
            return $slug;
        }
        
        // Add a number suffix to make unique
        $counter = 1;
        while ($counter < 100) {
            $new_slug = $slug . '-' . $counter;
            $where_clause = $exclude_trainer_id 
                ? $wpdb->prepare("WHERE slug = %s AND id != %d", $new_slug, $exclude_trainer_id)
                : $wpdb->prepare("WHERE slug = %s", $new_slug);
                
            $exists = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}ptp_trainers {$where_clause}");
            
            if (!$exists) {
                return $new_slug;
            }
            $counter++;
        }
        
        // Fallback: add timestamp
        return $slug . '-' . time();
    }
    
    public static function update_stats($trainer_id) {
        global $wpdb;
        
        // Update total sessions
        $total_sessions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND status = 'completed'",
            $trainer_id
        ));
        
        // Update total earnings
        $total_earnings = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND status = 'completed'",
            $trainer_id
        ));
        
        // Update average rating
        $rating_data = $wpdb->get_row($wpdb->prepare(
            "SELECT AVG(rating) as avg_rating, COUNT(*) as review_count FROM {$wpdb->prefix}ptp_reviews WHERE trainer_id = %d AND is_published = 1",
            $trainer_id
        ));
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'total_sessions' => intval($total_sessions),
                'total_earnings' => floatval($total_earnings),
                'average_rating' => floatval($rating_data->avg_rating ?? 0),
                'review_count' => intval($rating_data->review_count ?? 0),
            ),
            array('id' => $trainer_id)
        );
    }
    
    public static function get_specialties_list() {
        return array(
            'ball_control' => 'Ball Control',
            'dribbling' => 'Dribbling',
            'passing' => 'Passing',
            'shooting' => 'Shooting',
            'finishing' => 'Finishing',
            'defending' => 'Defending',
            'goalkeeping' => 'Goalkeeping',
            'speed_agility' => 'Speed & Agility',
            'tactical' => 'Tactical IQ',
            'fitness' => 'Fitness & Conditioning',
            'mental' => 'Mental Training',
            '1v1' => '1v1 Training',
        );
    }
    
    public static function get_reviews($trainer_id, $limit = 10) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.display_name as parent_name 
             FROM {$wpdb->prefix}ptp_reviews r
             JOIN {$wpdb->prefix}ptp_parents p ON r.parent_id = p.id
             WHERE r.trainer_id = %d AND r.is_published = 1
             ORDER BY r.created_at DESC
             LIMIT %d",
            $trainer_id, $limit
        ));
    }
    
    /**
     * Check if trainer profile is complete
     */
    public static function is_profile_complete($trainer) {
        $status = self::get_profile_completion_status($trainer);
        return $status['percentage'] >= 100;
    }
    
    /**
     * Get detailed profile completion status
     */
    public static function get_profile_completion_status($trainer) {
        $required_fields = array(
            'photo_url' => array('label' => 'Profile Photo', 'weight' => 20),
            'headline' => array('label' => 'Headline', 'weight' => 15),
            'bio' => array('label' => 'Bio', 'weight' => 20),
            'location' => array('label' => 'Location', 'weight' => 15),
            'hourly_rate' => array('label' => 'Hourly Rate', 'weight' => 10),
            'specialties' => array('label' => 'Specialties', 'weight' => 10),
            'college_or_team' => array('label' => 'College or Team', 'weight' => 10),
        );
        
        $completed = array();
        $missing = array();
        $total_weight = 0;
        $earned_weight = 0;
        
        foreach ($required_fields as $field => $info) {
            $total_weight += $info['weight'];
            
            // Special case for college_or_team - either one counts
            if ($field === 'college_or_team') {
                $is_complete = !empty($trainer->college) || !empty($trainer->team);
            } else {
                $value = $trainer->$field ?? '';
                $is_complete = !empty($value) && $value != '0' && $value != '0.00';
            }
            
            if ($is_complete) {
                $earned_weight += $info['weight'];
                $completed[] = $info['label'];
            } else {
                $missing[] = array(
                    'field' => $field,
                    'label' => $info['label'],
                    'weight' => $info['weight']
                );
            }
        }
        
        // Check availability separately (bonus)
        global $wpdb;
        $has_availability = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_availability WHERE trainer_id = %d AND is_active = 1",
            $trainer->id
        ));
        
        return array(
            'percentage' => round(($earned_weight / $total_weight) * 100),
            'completed' => $completed,
            'missing' => $missing,
            'has_availability' => $has_availability > 0,
        );
    }
    
    /**
     * Check if this is a new trainer (just approved, never logged in to dashboard)
     */
    public static function is_new_trainer($trainer_id) {
        global $wpdb;
        
        // Get the trainer's user_id
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", 
            $trainer_id
        ));
        
        if (!$user_id) {
            return true; // No user linked, consider new
        }
        
        // Check if onboarding was completed
        $completed = get_user_meta($user_id, 'ptp_onboarding_completed', true);
        
        return empty($completed);
    }
    
    /**
     * Mark trainer onboarding as complete
     * v135.9: This is the ONLY place admin notification should be sent
     * Consolidates all admin emails into one comprehensive notification
     */
    public static function complete_onboarding($trainer_id) {
        global $wpdb;
        
        // Get full trainer data for the notification
        $trainer = self::get($trainer_id);
        if (!$trainer) {
            ptp_log("PTP: complete_onboarding called for non-existent trainer #{$trainer_id}");
            return false;
        }
        
        // Check if we've already sent the completion notification
        $already_notified = get_transient('ptp_onboarding_notified_' . $trainer_id);
        if ($already_notified) {
            ptp_log("PTP: Skipping duplicate admin notification for trainer #{$trainer_id}");
            return true;
        }
        
        // v134: Do NOT auto-activate - trainer stays pending until admin approves
        // Admin will manually set status=active after reviewing completed profile
        
        $user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id));
        if ($user_id) {
            update_user_meta($user_id, 'ptp_onboarding_completed', current_time('mysql'));
            delete_user_meta($user_id, 'ptp_needs_onboarding');
        }
        
        // Calculate completion percentage for the email
        // v136: Fixed - was calling non-existent get_completion_status($trainer_id)
        $completion = self::get_profile_completion_status($trainer);
        
        // Get training locations
        $locations = array();
        if (!empty($trainer->training_locations)) {
            $decoded = json_decode($trainer->training_locations, true);
            if (is_array($decoded)) {
                $locations = $decoded;
            }
        }
        
        // Get availability count
        $avail_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_availability WHERE trainer_id = %d AND is_active = 1",
            $trainer_id
        ));
        
        // Send ONE comprehensive admin notification
        $admin_email = get_option('admin_email');
        $subject = "✅ Trainer Ready for Approval - " . $trainer->name;
        
        $body = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "   🎯 TRAINER READY FOR REVIEW\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $body .= "TRAINER INFO\n";
        $body .= "────────────────────────────────────────────────\n";
        $body .= "Name: {$trainer->name}\n";
        $body .= "Email: {$trainer->email}\n";
        $body .= "Phone: " . ($trainer->phone ?: 'Not provided') . "\n";
        $body .= "Location: {$trainer->city}, {$trainer->state}\n\n";
        
        $body .= "CREDENTIALS\n";
        $body .= "────────────────────────────────────────────────\n";
        $body .= "Playing Level: " . ($trainer->playing_level ?: 'Not specified') . "\n";
        $body .= "Team/Club: " . ($trainer->team ?: 'Not specified') . "\n";
        $body .= "Rate: \$" . number_format($trainer->hourly_rate, 0) . "/hr\n\n";
        
        $body .= "PROFILE COMPLETION\n";
        $body .= "────────────────────────────────────────────────\n";
        $body .= "Photo: " . (!empty($trainer->photo_url) ? '✓ Uploaded' : '✗ Missing') . "\n";
        $body .= "Bio: " . (!empty($trainer->bio) && strlen($trainer->bio) > 50 ? '✓ Complete (' . strlen($trainer->bio) . ' chars)' : '✗ Incomplete') . "\n";
        $body .= "Training Locations: " . (count($locations) > 0 ? '✓ ' . count($locations) . ' location(s)' : '✗ None') . "\n";
        if (count($locations) > 0) {
            foreach ($locations as $i => $loc) {
                if (is_array($loc)) {
                    $body .= "   " . ($i+1) . ". " . ($loc['name'] ?? $loc['address'] ?? 'Unknown') . "\n";
                } else {
                    $body .= "   " . ($i+1) . ". " . $loc . "\n";
                }
            }
        }
        $body .= "Availability: " . ($avail_count > 0 ? "✓ {$avail_count} days set" : '✗ Not set') . "\n";
        $body .= "Contract: " . (!empty($trainer->contractor_agreement_signed) ? '✓ Signed' : '✗ Not signed') . "\n";
        $body .= "Stripe: " . (!empty($trainer->stripe_account_id) ? '✓ Connected' : '○ Pending (can connect later)') . "\n\n";
        
        if (!empty($trainer->bio)) {
            $body .= "BIO PREVIEW\n";
            $body .= "────────────────────────────────────────────────\n";
            $body .= substr($trainer->bio, 0, 300) . (strlen($trainer->bio) > 300 ? '...' : '') . "\n\n";
        }
        
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "   QUICK ACTIONS\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $body .= "👉 Review & Approve:\n";
        $body .= admin_url('admin.php?page=ptp-trainers&action=edit&id=' . $trainer_id) . "\n\n";
        $body .= "To activate: Edit trainer → Change status to 'Active' → Save\n\n";
        $body .= "View Public Profile (after approval):\n";
        $body .= home_url('/trainer/' . ($trainer->slug ?: 'trainer-' . $trainer_id) . '/') . "\n";
        
        wp_mail($admin_email, $subject, $body);
        
        // Set transient to prevent duplicate emails (expires in 1 hour)
        set_transient('ptp_onboarding_notified_' . $trainer_id, true, HOUR_IN_SECONDS);
        
        ptp_log("PTP: Trainer #{$trainer_id} ({$trainer->name}) completed onboarding - admin notified");
    }
    
    /**
     * Get trainer photo URL with fallback to avatar
     */
    public static function get_photo_url($trainer, $size = 400) {
        if (is_numeric($trainer)) {
            $trainer = self::get($trainer);
        }
        
        if (!$trainer) {
            return 'https://ui-avatars.com/api/?name=Trainer&size=' . $size . '&background=FCB900&color=0A0A0A&bold=true';
        }
        
        if (!empty($trainer->photo_url)) {
            return $trainer->photo_url;
        }
        
        // Generate avatar from name
        $name = $trainer->display_name ?: 'Trainer';
        return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&size=' . $size . '&background=FCB900&color=0A0A0A&bold=true';
    }
    
    /**
     * Check if trainer has a proper photo (not just avatar)
     */
    public static function has_photo($trainer) {
        if (is_numeric($trainer)) {
            $trainer = self::get($trainer);
        }
        
        return $trainer && !empty($trainer->photo_url);
    }
    
    /**
     * Get trainers missing photos (for admin alerts)
     */
    public static function get_trainers_without_photos() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, display_name, email, status 
             FROM {$wpdb->prefix}ptp_trainers 
             WHERE (photo_url IS NULL OR photo_url = '') 
             AND status = 'active'
             ORDER BY display_name"
        );
    }
    
    /**
     * =====================================================
     * TRAINER RANKING & FEATURING METHODS (v54)
     * =====================================================
     */
    
    /**
     * Set featured status for a trainer
     */
    public static function set_featured($trainer_id, $is_featured = true) {
        global $wpdb;
        
        $trainer_id = absint($trainer_id);
        if (!$trainer_id) {
            return new WP_Error('invalid_trainer', 'Invalid trainer ID');
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('is_featured' => $is_featured ? 1 : 0),
            array('id' => $trainer_id),
            array('%d'),
            array('%d')
        );
        
        if ($result === false) {
            self::log("Failed to set featured status for trainer {$trainer_id}", 'error');
            return new WP_Error('update_failed', 'Failed to update featured status');
        }
        
        // Clear cache
        wp_cache_delete('ptp_trainer_' . $trainer_id, 'ptp');
        
        self::log("Trainer {$trainer_id} featured status set to " . ($is_featured ? 'true' : 'false'));
        return true;
    }
    
    /**
     * Set sort order for a trainer
     */
    public static function set_sort_order($trainer_id, $sort_order = 0) {
        global $wpdb;
        
        $trainer_id = absint($trainer_id);
        $sort_order = absint($sort_order);
        
        if (!$trainer_id) {
            return new WP_Error('invalid_trainer', 'Invalid trainer ID');
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('sort_order' => $sort_order),
            array('id' => $trainer_id),
            array('%d'),
            array('%d')
        );
        
        if ($result === false) {
            self::log("Failed to set sort order for trainer {$trainer_id}", 'error');
            return new WP_Error('update_failed', 'Failed to update sort order');
        }
        
        // Clear cache
        wp_cache_delete('ptp_trainer_' . $trainer_id, 'ptp');
        
        return true;
    }
    
    /**
     * Bulk update sort orders for multiple trainers
     * @param array $order_data Array of trainer_id => sort_order pairs
     */
    public static function bulk_update_sort_order($order_data) {
        global $wpdb;
        
        if (!is_array($order_data) || empty($order_data)) {
            return new WP_Error('invalid_data', 'Invalid order data');
        }
        
        $updated = 0;
        $failed = 0;
        
        foreach ($order_data as $trainer_id => $sort_order) {
            $trainer_id = absint($trainer_id);
            $sort_order = absint($sort_order);
            
            if (!$trainer_id) continue;
            
            $result = $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                array('sort_order' => $sort_order),
                array('id' => $trainer_id),
                array('%d'),
                array('%d')
            );
            
            if ($result !== false) {
                $updated++;
                wp_cache_delete('ptp_trainer_' . $trainer_id, 'ptp');
            } else {
                $failed++;
            }
        }
        
        self::log("Bulk sort order update: {$updated} updated, {$failed} failed");
        
        return array(
            'updated' => $updated,
            'failed' => $failed,
        );
    }
    
    /**
     * Bulk update featured status
     * @param array $trainer_ids Array of trainer IDs
     * @param bool $is_featured Featured status to set
     */
    public static function bulk_set_featured($trainer_ids, $is_featured = true) {
        global $wpdb;
        
        if (!is_array($trainer_ids) || empty($trainer_ids)) {
            return new WP_Error('invalid_data', 'Invalid trainer IDs');
        }
        
        $trainer_ids = array_map('absint', $trainer_ids);
        $trainer_ids = array_filter($trainer_ids);
        
        if (empty($trainer_ids)) {
            return new WP_Error('invalid_data', 'No valid trainer IDs');
        }
        
        $placeholders = implode(',', array_fill(0, count($trainer_ids), '%d'));
        $featured_val = $is_featured ? 1 : 0;
        
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_trainers SET is_featured = %d WHERE id IN ({$placeholders})",
            array_merge(array($featured_val), $trainer_ids)
        ));
        
        if ($result === false) {
            self::log("Failed to bulk update featured status", 'error');
            return new WP_Error('update_failed', 'Failed to update featured status');
        }
        
        // Clear cache for all affected trainers
        foreach ($trainer_ids as $id) {
            wp_cache_delete('ptp_trainer_' . $id, 'ptp');
        }
        
        self::log("Bulk featured update: {$result} trainers set to " . ($is_featured ? 'featured' : 'unfeatured'));
        
        return array(
            'updated' => $result,
        );
    }
    
    /**
     * Get trainers for ranking page
     */
    public static function get_for_ranking($status = 'active') {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, display_name, photo_url, location, hourly_rate, 
                    is_featured, sort_order, total_sessions, average_rating, review_count
             FROM {$wpdb->prefix}ptp_trainers 
             WHERE status = %s 
             ORDER BY is_featured DESC, sort_order ASC, average_rating DESC",
            $status
        ));
    }
    
    /**
     * Get featured trainers count
     */
    public static function get_featured_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers 
             WHERE status = 'active' AND is_featured = 1"
        );
    }
    
    /**
     * Auto-assign sort orders based on current positions
     * Useful for initialization or resetting
     */
    public static function auto_assign_sort_orders() {
        global $wpdb;
        
        // Get all active trainers ordered by current ranking logic
        $trainers = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers 
             WHERE status = 'active' 
             ORDER BY is_featured DESC, average_rating DESC, total_sessions DESC"
        );
        
        $order = 0;
        foreach ($trainers as $trainer) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                array('sort_order' => $order),
                array('id' => $trainer->id),
                array('%d'),
                array('%d')
            );
            $order++;
        }
        
        self::log("Auto-assigned sort orders to " . count($trainers) . " trainers");
        return count($trainers);
    }
    
    /**
     * Validate trainer data before saving
     */
    public static function validate_data($data) {
        $errors = array();
        
        if (isset($data['display_name']) && empty(trim($data['display_name']))) {
            $errors[] = 'Display name is required';
        }
        
        if (isset($data['hourly_rate'])) {
            $rate = floatval($data['hourly_rate']);
            if ($rate < 0 || $rate > 500) {
                $errors[] = 'Hourly rate must be between $0 and $500';
            }
        }
        
        if (isset($data['email']) && !empty($data['email']) && !is_email($data['email'])) {
            $errors[] = 'Invalid email address';
        }
        
        if (isset($data['travel_radius'])) {
            $radius = intval($data['travel_radius']);
            if ($radius < 0 || $radius > 100) {
                $errors[] = 'Travel radius must be between 0 and 100 miles';
            }
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Clear all trainer caches
     */
    public static function clear_all_cache() {
        global $wpdb;
        
        $trainer_ids = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers"
        );
        
        foreach ($trainer_ids as $id) {
            wp_cache_delete('ptp_trainer_' . $id, 'ptp');
        }
        
        // Also clear user-based cache
        $user_ids = $wpdb->get_col(
            "SELECT user_id FROM {$wpdb->prefix}ptp_trainers WHERE user_id > 0"
        );
        
        foreach ($user_ids as $user_id) {
            wp_cache_delete('ptp_trainer_user_' . $user_id, 'ptp');
        }
        
        self::log("Cleared cache for " . count($trainer_ids) . " trainers");
        return count($trainer_ids);
    }
}
