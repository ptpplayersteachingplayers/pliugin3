<?php
/**
 * Trainer Profile System v60.0
 * 
 * Rover-inspired profile completeness and trust badges
 * - Profile completeness scoring
 * - Trust badges (insurance, background check, verified, etc.)
 * - Profile strength indicators
 * - Required vs optional fields tracking
 */

defined('ABSPATH') || exit;

class PTP_Trainer_Profile {
    
    /**
     * Profile field definitions with weights
     * weight: importance for completeness score (1-10)
     * required: must have for profile to be "complete"
     * category: grouping for UI
     */
    private static $profile_fields = array(
        // Required fields (profile won't be approved without these)
        'photo_url' => array(
            'label' => 'Profile Photo',
            'weight' => 10,
            'required' => true,
            'category' => 'basics',
            'description' => 'A clear, professional headshot. Trainers with photos get 5x more bookings.',
            'tip' => 'Use a well-lit photo showing your face clearly. Action shots work great too!'
        ),
        'display_name' => array(
            'label' => 'Display Name',
            'weight' => 10,
            'required' => true,
            'category' => 'basics',
            'description' => 'Your name as it appears to families'
        ),
        'headline' => array(
            'label' => 'Headline',
            'weight' => 8,
            'required' => true,
            'category' => 'basics',
            'description' => 'A brief tagline (e.g., "D1 Midfielder | Youth Development Specialist")',
            'tip' => 'Mention your playing level and what you specialize in'
        ),
        'bio' => array(
            'label' => 'Bio',
            'weight' => 9,
            'required' => true,
            'category' => 'basics',
            'description' => 'Tell families about yourself, your experience, and coaching philosophy',
            'tip' => 'Aim for 150-300 words. Include your playing history and why you love coaching.',
            'min_length' => 100
        ),
        'hourly_rate' => array(
            'label' => 'Hourly Rate',
            'weight' => 10,
            'required' => true,
            'category' => 'pricing',
            'description' => 'Your rate per hour for 1-on-1 training'
        ),
        'location' => array(
            'label' => 'Primary Location',
            'weight' => 9,
            'required' => true,
            'category' => 'location',
            'description' => 'City/area where you offer training'
        ),
        'training_locations' => array(
            'label' => 'Training Locations',
            'weight' => 8,
            'required' => true,
            'category' => 'location',
            'description' => 'Specific fields/facilities where you train',
            'tip' => 'Add at least 2-3 locations to give parents options'
        ),
        
        // Highly recommended (strong impact on bookings)
        'intro_video_url' => array(
            'label' => 'Introduction Video',
            'weight' => 7,
            'required' => false,
            'category' => 'media',
            'description' => 'A 30-60 second video introducing yourself',
            'tip' => 'Trainers with videos get 3x more bookings. Just introduce yourself and share your coaching philosophy.',
            'impact' => 'high'
        ),
        'specialties' => array(
            'label' => 'Training Specialties',
            'weight' => 7,
            'required' => false,
            'category' => 'expertise',
            'description' => 'Skills you focus on (shooting, dribbling, etc.)',
            'tip' => 'Select 3-5 areas you excel at teaching'
        ),
        'playing_level' => array(
            'label' => 'Playing Level',
            'weight' => 6,
            'required' => false,
            'category' => 'credentials',
            'description' => 'Your highest level of play (Pro, D1, D2, etc.)'
        ),
        'college' => array(
            'label' => 'College/Team',
            'weight' => 6,
            'required' => false,
            'category' => 'credentials',
            'description' => 'Where you played collegiately or professionally'
        ),
        'position' => array(
            'label' => 'Position',
            'weight' => 4,
            'required' => false,
            'category' => 'credentials',
            'description' => 'Your primary playing position'
        ),
        
        // Good to have (improves profile quality)
        'gallery' => array(
            'label' => 'Action Photos',
            'weight' => 5,
            'required' => false,
            'category' => 'media',
            'description' => 'Photos of you playing or training athletes',
            'tip' => 'Add 3-5 photos showing you in action'
        ),
        'instagram' => array(
            'label' => 'Instagram',
            'weight' => 3,
            'required' => false,
            'category' => 'social',
            'description' => 'Your Instagram handle'
        ),
        'travel_radius' => array(
            'label' => 'Travel Radius',
            'weight' => 4,
            'required' => false,
            'category' => 'location',
            'description' => 'How far you\'re willing to travel for sessions'
        ),
        
        // Trust signals (handled separately but tracked)
        'is_background_checked' => array(
            'label' => 'Background Check',
            'weight' => 8,
            'required' => false,
            'category' => 'trust',
            'description' => 'Verified background check on file'
        ),
        'safesport_verified' => array(
            'label' => 'SafeSport Certified',
            'weight' => 6,
            'required' => false,
            'category' => 'trust',
            'description' => 'SafeSport certification completed'
        ),
    );
    
    /**
     * Trust badge definitions
     */
    private static $trust_badges = array(
        'verified' => array(
            'label' => 'Verified Trainer',
            'icon' => 'shield-check',
            'color' => '#10B981',
            'description' => 'Identity and credentials verified by PTP',
            'field' => 'is_verified'
        ),
        'background_checked' => array(
            'label' => 'Background Checked',
            'icon' => 'user-check',
            'color' => '#3B82F6',
            'description' => 'Passed comprehensive background check',
            'field' => 'is_background_checked'
        ),
        'safesport' => array(
            'label' => 'SafeSport Certified',
            'icon' => 'award',
            'color' => '#8B5CF6',
            'description' => 'Completed SafeSport training',
            'field' => 'safesport_verified'
        ),
        'supercoach' => array(
            'label' => 'Super Coach',
            'icon' => 'star',
            'color' => '#FCB900',
            'description' => 'Top-rated trainer with exceptional reviews',
            'field' => 'is_supercoach'
        ),
        'insured' => array(
            'label' => 'Insured',
            'icon' => 'shield',
            'color' => '#059669',
            'description' => 'Sessions covered by $1M liability insurance',
            'threshold' => null // Always show for active trainers
        ),
        'sessions_25' => array(
            'label' => '25+ Sessions',
            'icon' => 'calendar-check',
            'color' => '#6366F1',
            'description' => 'Completed 25+ training sessions',
            'field' => 'total_sessions',
            'threshold' => 25
        ),
        'sessions_100' => array(
            'label' => '100+ Sessions',
            'icon' => 'trophy',
            'color' => '#F59E0B',
            'description' => 'Completed 100+ training sessions',
            'field' => 'total_sessions',
            'threshold' => 100
        ),
        'top_rated' => array(
            'label' => 'Top Rated',
            'icon' => 'thumbs-up',
            'color' => '#EC4899',
            'description' => '4.8+ average rating',
            'field' => 'average_rating',
            'threshold' => 4.8
        ),
        'fast_responder' => array(
            'label' => 'Fast Responder',
            'icon' => 'zap',
            'color' => '#14B8A6',
            'description' => 'Typically responds within 2 hours',
            'field' => 'responsiveness_score',
            'threshold' => 90
        ),
    );
    
    /**
     * Calculate profile completeness score (0-100)
     */
    public static function get_completeness_score($trainer) {
        if (!$trainer) return 0;
        
        $total_weight = 0;
        $earned_weight = 0;
        
        foreach (self::$profile_fields as $field => $config) {
            $total_weight += $config['weight'];
            
            if (self::field_is_complete($trainer, $field, $config)) {
                $earned_weight += $config['weight'];
            }
        }
        
        return $total_weight > 0 ? round(($earned_weight / $total_weight) * 100) : 0;
    }
    
    /**
     * Check if a specific field is complete
     */
    public static function field_is_complete($trainer, $field, $config = null) {
        if (!$config) {
            $config = self::$profile_fields[$field] ?? array();
        }
        
        $value = $trainer->$field ?? null;
        
        // Handle JSON fields
        if (in_array($field, array('training_locations', 'gallery', 'specialties'))) {
            if (empty($value)) return false;
            $decoded = is_string($value) ? json_decode($value, true) : $value;
            if (!is_array($decoded) || empty($decoded)) return false;
            
            // For training_locations, need at least 1 valid location
            if ($field === 'training_locations') {
                foreach ($decoded as $loc) {
                    if (!empty($loc['name'])) return true;
                }
                return false;
            }
            return true;
        }
        
        // Handle numeric fields
        if (in_array($field, array('hourly_rate', 'travel_radius'))) {
            return !empty($value) && floatval($value) > 0;
        }
        
        // Handle boolean fields
        if (in_array($field, array('is_background_checked', 'safesport_verified', 'is_verified'))) {
            return !empty($value) && $value == 1;
        }
        
        // Handle text fields with minimum length
        if (!empty($config['min_length'])) {
            return !empty($value) && strlen(strip_tags($value)) >= $config['min_length'];
        }
        
        // Default: check if not empty
        return !empty($value);
    }
    
    /**
     * Get missing required fields
     */
    public static function get_missing_required($trainer) {
        $missing = array();
        
        foreach (self::$profile_fields as $field => $config) {
            if ($config['required'] && !self::field_is_complete($trainer, $field, $config)) {
                $missing[$field] = $config;
            }
        }
        
        return $missing;
    }
    
    /**
     * Get recommended improvements
     */
    public static function get_recommendations($trainer) {
        $recommendations = array();
        
        foreach (self::$profile_fields as $field => $config) {
            if (!$config['required'] && !self::field_is_complete($trainer, $field, $config)) {
                // Prioritize high-impact fields
                $priority = $config['weight'];
                if (!empty($config['impact']) && $config['impact'] === 'high') {
                    $priority += 5;
                }
                
                $recommendations[] = array(
                    'field' => $field,
                    'label' => $config['label'],
                    'description' => $config['description'],
                    'tip' => $config['tip'] ?? '',
                    'priority' => $priority,
                    'category' => $config['category']
                );
            }
        }
        
        // Sort by priority descending
        usort($recommendations, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        
        return $recommendations;
    }
    
    /**
     * Get all earned badges for a trainer
     */
    public static function get_badges($trainer) {
        if (!$trainer) return array();
        
        $badges = array();
        
        foreach (self::$trust_badges as $key => $badge) {
            $earned = false;
            
            if (isset($badge['threshold']) && $badge['threshold'] === null) {
                // Always show (like insurance for active trainers)
                $earned = ($trainer->status ?? '') === 'active';
            } elseif (isset($badge['field'])) {
                $value = $trainer->{$badge['field']} ?? 0;
                
                if (isset($badge['threshold'])) {
                    // Numeric threshold
                    $earned = floatval($value) >= $badge['threshold'];
                } else {
                    // Boolean field
                    $earned = !empty($value) && $value == 1;
                }
            }
            
            if ($earned) {
                $badges[$key] = $badge;
            }
        }
        
        return $badges;
    }
    
    /**
     * Get badge HTML for display
     */
    public static function render_badges($trainer, $max = 5, $size = 'small') {
        $badges = self::get_badges($trainer);
        if (empty($badges)) return '';
        
        $badges = array_slice($badges, 0, $max);
        
        $size_class = $size === 'large' ? 'ptp-badge-lg' : 'ptp-badge-sm';
        $html = '<div class="ptp-trust-badges ' . $size_class . '">';
        
        foreach ($badges as $key => $badge) {
            $html .= sprintf(
                '<span class="ptp-badge ptp-badge-%s" title="%s" style="--badge-color: %s">
                    %s
                    <span class="ptp-badge-label">%s</span>
                </span>',
                esc_attr($key),
                esc_attr($badge['description']),
                esc_attr($badge['color']),
                self::get_badge_icon($badge['icon'], $size === 'large' ? 18 : 14),
                esc_html($badge['label'])
            );
        }
        
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Get SVG icon for badge
     */
    private static function get_badge_icon($icon, $size = 14) {
        $icons = array(
            'shield-check' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>',
            'user-check' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>',
            'award' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>',
            'star' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            'shield' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
            'calendar-check' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 16l2 2 4-4"/></svg>',
            'trophy' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
            'thumbs-up' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>',
            'zap' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
        );
        
        return $icons[$icon] ?? '';
    }
    
    /**
     * Get profile completeness breakdown by category
     */
    public static function get_completeness_breakdown($trainer) {
        $categories = array(
            'basics' => array('label' => 'Basic Info', 'total' => 0, 'complete' => 0, 'fields' => array()),
            'media' => array('label' => 'Photos & Video', 'total' => 0, 'complete' => 0, 'fields' => array()),
            'location' => array('label' => 'Location', 'total' => 0, 'complete' => 0, 'fields' => array()),
            'expertise' => array('label' => 'Expertise', 'total' => 0, 'complete' => 0, 'fields' => array()),
            'credentials' => array('label' => 'Credentials', 'total' => 0, 'complete' => 0, 'fields' => array()),
            'trust' => array('label' => 'Trust & Safety', 'total' => 0, 'complete' => 0, 'fields' => array()),
        );
        
        foreach (self::$profile_fields as $field => $config) {
            $cat = $config['category'];
            if (!isset($categories[$cat])) continue;
            
            $is_complete = self::field_is_complete($trainer, $field, $config);
            
            $categories[$cat]['total']++;
            if ($is_complete) {
                $categories[$cat]['complete']++;
            }
            
            $categories[$cat]['fields'][$field] = array(
                'label' => $config['label'],
                'complete' => $is_complete,
                'required' => $config['required'],
                'tip' => $config['tip'] ?? ''
            );
        }
        
        // Calculate percentages
        foreach ($categories as $key => &$cat) {
            $cat['percent'] = $cat['total'] > 0 ? round(($cat['complete'] / $cat['total']) * 100) : 0;
        }
        
        return $categories;
    }
    
    /**
     * Get trainer stats for display
     */
    public static function get_display_stats($trainer) {
        return array(
            'total_sessions' => intval($trainer->total_sessions ?? 0),
            'average_rating' => floatval($trainer->average_rating ?? 0),
            'review_count' => intval($trainer->review_count ?? 0),
            'happy_score' => intval($trainer->happy_student_score ?? 0),
            'return_rate' => intval($trainer->return_rate ?? 0),
            'member_since' => $trainer->created_at ? date('M Y', strtotime($trainer->created_at)) : '',
            'response_time' => self::get_response_time_label($trainer->responsiveness_score ?? 100),
        );
    }
    
    /**
     * Get response time label
     */
    private static function get_response_time_label($score) {
        if ($score >= 95) return 'Within 1 hour';
        if ($score >= 85) return 'Within 2 hours';
        if ($score >= 70) return 'Within 4 hours';
        if ($score >= 50) return 'Within 24 hours';
        return 'Varies';
    }
    
    /**
     * Check if profile is complete enough to be active
     */
    public static function is_profile_ready($trainer) {
        $missing = self::get_missing_required($trainer);
        return empty($missing);
    }
    
    /**
     * Get availability summary for display
     */
    public static function get_availability_summary($trainer_id) {
        if (!class_exists('PTP_Availability')) return array();
        
        $availability = PTP_Availability::get_weekly($trainer_id);
        $days = array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat');
        $summary = array();
        
        foreach ($availability as $slot) {
            if ($slot->is_active) {
                $day_name = $days[$slot->day_of_week] ?? '';
                $start = date('g:ia', strtotime($slot->start_time));
                $end = date('g:ia', strtotime($slot->end_time));
                $summary[] = array(
                    'day' => $day_name,
                    'day_index' => $slot->day_of_week,
                    'hours' => $start . ' - ' . $end,
                    'start' => $slot->start_time,
                    'end' => $slot->end_time
                );
            }
        }
        
        return $summary;
    }
    
    /**
     * Get training locations formatted for display
     */
    public static function get_locations_display($trainer) {
        $locations = array();
        
        if (!empty($trainer->training_locations)) {
            $decoded = json_decode($trainer->training_locations, true);
            if (is_array($decoded)) {
                foreach ($decoded as $loc) {
                    if (!empty($loc['name'])) {
                        $locations[] = array(
                            'id' => $loc['id'] ?? uniqid(),
                            'name' => trim(str_replace(array('/', '\\'), '', $loc['name'])),
                            'address' => !empty($loc['address']) ? trim($loc['address']) : '',
                            'lat' => $loc['lat'] ?? null,
                            'lng' => $loc['lng'] ?? null,
                        );
                    }
                }
            }
        }
        
        // Fallback to general location
        if (empty($locations) && !empty($trainer->location)) {
            $locations[] = array(
                'id' => 'default',
                'name' => $trainer->location,
                'address' => $trainer->location,
            );
        }
        
        return $locations;
    }
    
    /**
     * Get field definitions (for forms)
     */
    public static function get_field_definitions() {
        return self::$profile_fields;
    }
    
    /**
     * Get badge definitions
     */
    public static function get_badge_definitions() {
        return self::$trust_badges;
    }
}
