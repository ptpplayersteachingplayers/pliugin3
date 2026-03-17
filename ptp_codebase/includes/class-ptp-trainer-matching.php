<?php
/**
 * PTP Trainer Matching Engine — v197
 * 
 * Scores and ranks trainers against a lead using:
 *   Location Proximity  35%
 *   Specialty Match      25%
 *   Availability         20%
 *   Age Group Fit        10%
 *   Rating & Experience  10%
 * 
 * Called from admin when viewing a lead (auto) or when marking Hot/Warm.
 * Returns top 3 trainers with match %, matched tags, distance, and slot count.
 *
 * @since v197
 */
defined('ABSPATH') || exit;

class PTP_Trainer_Matching {

    /* ═══════════════════════════════════════════════════════════
       WEIGHTS
       ═══════════════════════════════════════════════════════════ */
    const W_LOCATION    = 35;
    const W_SPECIALTY   = 25;
    const W_AVAILABILITY = 20;
    const W_AGE_FIT     = 10;
    const W_RATING      = 10;

    /* ═══════════════════════════════════════════════════════════
       GOAL → SPECIALTY KEYWORD MAP
       Parent writes → keywords detected → trainer specialties matched
       ═══════════════════════════════════════════════════════════ */
    private static $goal_map = [
        'finishing' => [
            'keywords'    => ['finish', 'scor', 'shoot', 'goal', 'strik'],
            'specialties' => ['shooting', 'attacking', '1v1', 'finishing', 'striker'],
        ],
        'confidence' => [
            'keywords'    => ['confiden', 'shy', 'afraid', 'nervous', 'mental', 'believe'],
            'specialties' => ['confidence', 'game iq', 'mentorship', 'mental', 'leadership'],
        ],
        'speed' => [
            'keywords'    => ['speed', 'fast', 'quick', 'agil', 'explos', 'sprint'],
            'specialties' => ['speed', 'agility', 'fitness', 'athletic', 'conditioning'],
        ],
        'technical' => [
            'keywords'    => ['touch', 'control', 'techni', 'dribbl', 'skill', 'ball master'],
            'specialties' => ['technical', 'ball mastery', 'dribbling', 'skills', 'footwork'],
        ],
        'defense' => [
            'keywords'    => ['defen', 'tackl', 'position', 'mark', 'intercep', 'back line'],
            'specialties' => ['defensive', 'positioning', 'tactical', 'defending'],
        ],
        'tryout' => [
            'keywords'    => ['tryout', 'try out', 'a team', 'travel', 'select', 'compet', 'make the team'],
            'specialties' => ['tryout prep', 'competition', 'mental game', 'competitive', 'elite'],
        ],
        'weak_foot' => [
            'keywords'    => ['weak foot', 'left foot', 'both feet', 'two foot', 'ambi'],
            'specialties' => ['technical', 'ball mastery', 'ambidexterity', 'footwork'],
        ],
        'goalkeeper' => [
            'keywords'    => ['keeper', 'goalie', 'goalkeeper', 'gk', 'goal keeper'],
            'specialties' => ['goalkeeper', 'goalie', 'gk', 'keeper'],
        ],
        'passing' => [
            'keywords'    => ['pass', 'vision', 'play mak', 'through ball', 'creativ'],
            'specialties' => ['passing', 'vision', 'playmaking', 'midfield', 'creative'],
        ],
        'heading' => [
            'keywords'    => ['head', 'aerial', 'cross', 'set piece'],
            'specialties' => ['heading', 'aerial', 'set pieces', 'crossing'],
        ],
    ];

    /* ═══════════════════════════════════════════════════════════
       MAIN ENTRY: match_for_lead()
       Input:  lead data (from ptp_session_applications row)
       Output: array of top 3 trainers with scores + metadata
       ═══════════════════════════════════════════════════════════ */
    public static function match_for_lead($lead) {
        if (!class_exists('PTP_Trainer')) {
            ptp_log('[PTP Match] PTP_Trainer class not available');
            return [];
        }

        // Get all active trainers
        $trainers = PTP_Trainer::get_all(['status' => 'active', 'limit' => 100]);
        if (empty($trainers)) {
            ptp_log('[PTP Match] No active trainers found');
            return [];
        }

        // Parse lead data
        $lead_zip       = is_object($lead) ? ($lead->state ?? '') : ($lead['state'] ?? '');
        $lead_age       = is_object($lead) ? intval($lead->child_age ?? 0) : intval($lead['age'] ?? 0);
        $lead_goals_raw = self::extract_goals_text($lead);
        $lead_position  = is_object($lead) ? ($lead->position ?? '') : ($lead['position'] ?? '');
        $call_notes     = is_object($lead) ? ($lead->call_notes ?? '') : ($lead['call_notes'] ?? '');
        $admin_notes    = is_object($lead) ? ($lead->admin_notes ?? '') : ($lead['admin_notes'] ?? '');

        // Combine all notes for specialty parsing
        $all_notes = trim($call_notes . ' ' . $admin_notes);

        // Parse goals into specialties (includes notes now)
        $parsed_goals = self::parse_goals($lead_goals_raw . ' ' . $all_notes);

        // Parse admin/call notes for scheduling, location, personality, and trainer name signals
        $note_signals = self::parse_note_signals($all_notes);

        // Get lead coordinates (from zip)
        $lead_coords = self::zip_to_coords($lead_zip);

        // Score each trainer
        $scored = [];
        foreach ($trainers as $t) {
            $result = self::score_trainer($t, $lead_coords, $lead_zip, $parsed_goals, $lead_age, $lead_position, $note_signals);
            if ($result['total'] > 0) {
                $scored[] = $result;
            }
        }

        // Sort by total score descending
        usort($scored, function($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        // Return top 3
        $top = array_slice($scored, 0, 3);

        // Normalize to percentages (max possible = 115 with notes bonus)
        foreach ($top as &$m) {
            $m['match_pct'] = min(99, max(10, round($m['total'])));
        }

        ptp_log('[PTP Match] Matched ' . count($top) . ' trainers for lead zip=' . $lead_zip . ' age=' . $lead_age . ' notes_signals=' . count($note_signals['day_prefs'] ?? []));
        return $top;
    }

    /* ═══════════════════════════════════════════════════════════
       SCORE A SINGLE TRAINER
       ═══════════════════════════════════════════════════════════ */
    private static function score_trainer($trainer, $lead_coords, $lead_zip, $parsed_goals, $lead_age, $lead_position, $note_signals = []) {
        $scores = [
            'location'     => 0,
            'specialty'    => 0,
            'availability' => 0,
            'age_fit'      => 0,
            'rating'       => 0,
            'notes_bonus'  => 0,
        ];
        $meta = [
            'trainer_id'    => intval($trainer->id),
            'name'          => $trainer->display_name ?? '',
            'slug'          => $trainer->slug ?? '',
            'photo'         => $trainer->photo_url ?? '',
            'headline'      => $trainer->headline ?? '',
            'college'       => $trainer->college ?? '',
            'team'          => $trainer->team ?? '',
            'playing_level' => $trainer->playing_level ?? '',
            'rating'        => floatval($trainer->average_rating ?? 0),
            'reviews'       => intval($trainer->review_count ?? 0),
            'sessions'      => intval($trainer->total_sessions ?? 0),
            'location'      => $trainer->location ?? '',
            'city'          => $trainer->city ?? '',
            'state'         => $trainer->state ?? '',
            'distance_mi'   => null,
            'matched_tags'  => [],
            'all_specialties' => [],
            'slots_this_week' => 0,
            'is_recommended'  => false,
            'training_locations' => $trainer->training_locations ? json_decode($trainer->training_locations, true) : [],
            'notes_matched' => [],
        ];

        // ── 1. LOCATION (35%) ──
        $distance = self::calc_distance($lead_coords, $trainer, $lead_zip);
        $meta['distance_mi'] = $distance;

        if ($distance !== null) {
            if ($distance <= 5)       $scores['location'] = self::W_LOCATION;
            elseif ($distance <= 10)  $scores['location'] = self::W_LOCATION * 0.8;
            elseif ($distance <= 15)  $scores['location'] = self::W_LOCATION * 0.6;
            elseif ($distance <= 20)  $scores['location'] = self::W_LOCATION * 0.4;
            elseif ($distance <= 30)  $scores['location'] = self::W_LOCATION * 0.2;
            // >30 miles = 0
        } else {
            // Fallback: match by state
            $trainer_state = strtoupper(trim($trainer->state ?? ''));
            $lead_state    = self::zip_to_state($lead_zip);
            if ($trainer_state && $lead_state && $trainer_state === $lead_state) {
                $scores['location'] = self::W_LOCATION * 0.5;
            }
        }

        // ── 2. SPECIALTY MATCH (25%) ──
        $trainer_specs = strtolower($trainer->specialties ?? '');
        $trainer_text  = strtolower(
            ($trainer->specialties ?? '') . ' ' .
            ($trainer->headline ?? '') . ' ' .
            ($trainer->bio ?? '') . ' ' .
            ($trainer->coaching_why ?? '') . ' ' .
            ($trainer->position ?? '')
        );

        $all_specs = array_map('trim', explode(',', $trainer_specs));
        $meta['all_specialties'] = array_filter($all_specs);

        $matched_count = 0;
        $matched_tags  = [];
        foreach ($parsed_goals['specialties'] as $spec) {
            $spec_lower = strtolower($spec);
            if (strpos($trainer_text, $spec_lower) !== false) {
                $matched_count++;
                $matched_tags[] = $spec;
            }
        }

        // Position match bonus
        if ($lead_position && strpos($trainer_text, strtolower($lead_position)) !== false) {
            $matched_count += 0.5;
            $matched_tags[] = $lead_position;
        }

        $meta['matched_tags'] = array_unique($matched_tags);
        $total_goals = max(1, count($parsed_goals['specialties']));
        $scores['specialty'] = min(self::W_SPECIALTY, self::W_SPECIALTY * ($matched_count / $total_goals));

        // If no goals were parsed but trainer has specialties, give partial credit
        if (empty($parsed_goals['specialties']) && !empty($meta['all_specialties'])) {
            $scores['specialty'] = self::W_SPECIALTY * 0.5;
        }

        // ── 3. AVAILABILITY (20%) ──
        $slots = self::count_available_slots($trainer->id);
        $meta['slots_this_week'] = $slots;
        // Include next 3 actual open slot times for scheduling UI
        $detailed_slots = self::get_available_slots_detailed($trainer->id, 14);
        $meta['next_open_slots'] = array_slice($detailed_slots, 0, 5);

        if ($slots === 0) {
            // No slots = heavy penalty but don't eliminate (they might open up)
            $scores['availability'] = 0;
        } elseif ($slots >= 6) {
            $scores['availability'] = self::W_AVAILABILITY;
        } elseif ($slots >= 4) {
            $scores['availability'] = self::W_AVAILABILITY * 0.8;
        } elseif ($slots >= 2) {
            $scores['availability'] = self::W_AVAILABILITY * 0.6;
        } else {
            $scores['availability'] = self::W_AVAILABILITY * 0.3;
        }

        // ── 4. AGE FIT (10%) ──
        // Based on trainer's typical session history and age range
        if ($lead_age > 0) {
            // Most PTP trainers work with ages 6-14, give full marks if in range
            $typical_min = 6;
            $typical_max = 14;
            if ($lead_age >= $typical_min && $lead_age <= $typical_max) {
                $scores['age_fit'] = self::W_AGE_FIT;
            } elseif ($lead_age >= 4 && $lead_age <= 18) {
                $scores['age_fit'] = self::W_AGE_FIT * 0.6;
            }
        } else {
            $scores['age_fit'] = self::W_AGE_FIT * 0.5; // Unknown age, partial credit
        }

        // ── 5. RATING & EXPERIENCE (10%) ──
        $rating = floatval($trainer->average_rating ?? 0);
        $sessions = intval($trainer->total_sessions ?? 0);
        $reviews = intval($trainer->review_count ?? 0);

        $rating_score = 0;
        if ($rating >= 4.8) $rating_score = 5;
        elseif ($rating >= 4.5) $rating_score = 4;
        elseif ($rating >= 4.0) $rating_score = 3;
        elseif ($rating > 0) $rating_score = 2;
        else $rating_score = 2.5; // New trainer, neutral

        $exp_score = 0;
        if ($sessions >= 30) $exp_score = 5;
        elseif ($sessions >= 15) $exp_score = 4;
        elseif ($sessions >= 5) $exp_score = 3;
        else $exp_score = 2; // New trainer

        $scores['rating'] = self::W_RATING * (($rating_score + $exp_score) / 10);

        // Featured boost
        if (!empty($trainer->is_featured)) {
            $scores['rating'] = min(self::W_RATING, $scores['rating'] + 2);
        }

        // ── 6. NOTES BONUS (up to +15 on top of base 100) ──
        // This is additive — notes enhance but never break existing scoring
        $notes_bonus = 0;
        $notes_reasons = [];

        if (!empty($note_signals)) {
            $trainer_name_lower = strtolower($meta['name']);
            $trainer_slug_lower = strtolower($meta['slug']);
            $trainer_bio_lower  = strtolower(($trainer->bio ?? '') . ' ' . ($trainer->coaching_why ?? '') . ' ' . ($trainer->headline ?? ''));
            $trainer_locs_lower = strtolower($trainer->training_locations ?? '');
            $trainer_city_lower = strtolower($meta['city'] . ' ' . ($meta['location'] ?? ''));

            // A) TRAINER NAME MATCH — if admin wrote "wants Eddy" and this IS Eddy → huge boost
            foreach ($note_signals['trainer_names'] ?? [] as $req_name) {
                if (strpos($trainer_name_lower, $req_name) !== false ||
                    strpos($trainer_slug_lower, str_replace(' ', '-', $req_name)) !== false) {
                    $notes_bonus += 15; // Max boost — this is effectively an override
                    $notes_reasons[] = 'Requested by name';
                    break;
                }
            }

            // B) DAY PREFERENCE — check if trainer has slots on preferred days
            if (!empty($note_signals['day_prefs'])) {
                $schedule = self::get_trainer_schedule($trainer->id);
                $active_days = [];
                foreach ($schedule as $s) {
                    if ($s['is_active']) $active_days[] = $s['day'];
                }
                $day_matches = 0;
                $day_total   = count($note_signals['day_prefs']);
                foreach (array_keys($note_signals['day_prefs']) as $pref_day) {
                    if (in_array($pref_day, $active_days, true)) {
                        $day_matches++;
                    }
                }
                if ($day_total > 0 && $day_matches > 0) {
                    $day_pct = $day_matches / $day_total;
                    $notes_bonus += round(5 * $day_pct); // Up to +5
                    $notes_reasons[] = 'Available on preferred day(s)';
                } elseif ($day_total > 0 && $day_matches === 0) {
                    $notes_bonus -= 8; // Penalty — NOT available on any preferred day
                    $notes_reasons[] = 'NOT available on preferred days';
                }
            }

            // C) TIME PREFERENCE — check slot times vs preferred time of day
            if (!empty($note_signals['time_prefs'])) {
                $schedule = self::get_trainer_schedule($trainer->id);
                $has_time_match = false;
                foreach ($schedule as $s) {
                    if (!$s['is_active']) continue;
                    $hour = intval(substr($s['start_time'], 0, 2));
                    foreach ($note_signals['time_prefs'] as $tp) {
                        if ($tp === 'morning' && $hour >= 6 && $hour < 12) $has_time_match = true;
                        if ($tp === 'afternoon' && $hour >= 12 && $hour < 16) $has_time_match = true;
                        if ($tp === 'after_school' && $hour >= 15 && $hour < 19) $has_time_match = true;
                        if ($tp === 'evening' && $hour >= 18) $has_time_match = true;
                    }
                }
                if ($has_time_match) {
                    $notes_bonus += 3;
                    $notes_reasons[] = 'Has slots at preferred time';
                }
            }

            // D) LOCATION PREFERENCE — check training locations against notes
            if (!empty($note_signals['location_prefs'])) {
                foreach ($note_signals['location_prefs'] as $loc_pref) {
                    if (strpos($trainer_locs_lower, $loc_pref) !== false ||
                        strpos($trainer_city_lower, $loc_pref) !== false) {
                        $notes_bonus += 5;
                        $notes_reasons[] = 'Trains near ' . ucfirst($loc_pref);
                        break;
                    }
                }
            }

            // E) PERSONALITY MATCH — check trainer bio for personality signals
            if (!empty($note_signals['personality'])) {
                $personality_keyword_map = [
                    'patient'    => ['patient', 'calm', 'supportive', 'encouraging', 'understanding', 'comfortable'],
                    'energetic'  => ['energy', 'intensity', 'passion', 'dynamic', 'competitive', 'fired'],
                    'strict'     => ['discipline', 'accountability', 'structure', 'push', 'challenge', 'demand'],
                    'funny'      => ['fun', 'enjoy', 'laugh', 'positive', 'light'],
                    'mentor'     => ['mentor', 'role model', 'relationship', 'guide', 'bond', 'connect', 'off the field'],
                    'young'      => [],  // Can't determine from bio easily
                    'experienced'=> ['experience', 'years', 'veteran', 'professional'],
                ];
                foreach ($note_signals['personality'] as $trait) {
                    $bio_keywords = $personality_keyword_map[$trait] ?? [];
                    foreach ($bio_keywords as $bk) {
                        if (strpos($trainer_bio_lower, $bk) !== false) {
                            $notes_bonus += 3;
                            $notes_reasons[] = ucfirst($trait) . ' match';
                            break 2; // Only one personality bonus
                        }
                    }
                }
            }

            // F) PLAYING LEVEL PREFERENCE
            $playing_level_lower = strtolower($meta['playing_level'] ?? '');
            if (!empty($note_signals['level_pref'])) {
                if ($note_signals['level_pref'] === 'pro' && (strpos($playing_level_lower, 'mls') !== false || strpos($playing_level_lower, 'pro') !== false || !empty($meta['team']))) {
                    $notes_bonus += 3;
                    $notes_reasons[] = 'Pro-level coach (requested)';
                } elseif ($note_signals['level_pref'] === 'college' && (strpos($playing_level_lower, 'd1') !== false || strpos($playing_level_lower, 'ncaa') !== false || !empty($meta['college']))) {
                    $notes_bonus += 3;
                    $notes_reasons[] = 'College-level coach (requested)';
                }
            }
        }

        // Cap notes bonus at +15 (can be negative for schedule mismatch)
        $scores['notes_bonus'] = max(-10, min(15, $notes_bonus));
        $meta['notes_matched'] = $notes_reasons;

        // ── TOTAL ──
        $total = array_sum($scores);

        return array_merge($meta, [
            'scores' => $scores,
            'total'  => round($total, 1),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════
       GOAL PARSER — extracts searchable text from lead data
       ═══════════════════════════════════════════════════════════ */
    private static function extract_goals_text($lead) {
        if (is_object($lead)) {
            return implode(' ', array_filter([
                $lead->biggest_challenge ?? '',
                $lead->goal ?? '',
                $lead->position ?? '',
            ]));
        }
        return implode(' ', array_filter([
            $lead['biggest_challenge'] ?? '',
            $lead['goal'] ?? '',
            $lead['position'] ?? '',
        ]));
    }

    /**
     * Parse free-text goals into trainer specialties
     * Returns: ['categories' => [...], 'specialties' => [...], 'keywords_matched' => [...]]
     */
    public static function parse_goals($text) {
        $text_lower = strtolower($text);
        $matched_categories  = [];
        $matched_specialties = [];
        $matched_keywords    = [];

        foreach (self::$goal_map as $category => $data) {
            foreach ($data['keywords'] as $kw) {
                if (strpos($text_lower, $kw) !== false) {
                    $matched_categories[] = $category;
                    $matched_specialties  = array_merge($matched_specialties, $data['specialties']);
                    $matched_keywords[]   = $kw;
                    break; // One keyword match per category is enough
                }
            }
        }

        return [
            'categories'       => array_unique($matched_categories),
            'specialties'      => array_unique($matched_specialties),
            'keywords_matched' => array_unique($matched_keywords),
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       NOTE SIGNAL PARSER — extracts scheduling, location,
       personality, and trainer preferences from admin notes
       ═══════════════════════════════════════════════════════════ */
    public static function parse_note_signals($notes_text) {
        $text = strtolower(trim($notes_text));
        $signals = [
            'day_prefs'       => [],   // e.g. [6 => 'saturday', 0 => 'sunday']
            'time_prefs'      => [],   // e.g. ['morning', 'after_school']
            'location_prefs'  => [],   // e.g. ['villanova', 'haverford']
            'trainer_names'   => [],   // e.g. ['eddy', 'cj']
            'personality'     => [],   // e.g. ['patient', 'energetic']
            'gender_pref'     => '',   // 'male' or 'female' or ''
            'level_pref'      => '',   // 'pro' or 'college' or ''
            'raw'             => $text,
        ];

        if (!$text) return $signals;

        // ── DAY PREFERENCES ──
        $day_map = [
            'sunday' => 0, 'sun ' => 0, 'sundays' => 0,
            'monday' => 1, 'mon ' => 1, 'mondays' => 1,
            'tuesday' => 2, 'tues' => 2, 'tuesdays' => 2,
            'wednesday' => 3, 'wed ' => 3, 'wednesdays' => 3,
            'thursday' => 4, 'thurs' => 4, 'thursdays' => 4,
            'friday' => 5, 'fri ' => 5, 'fridays' => 5,
            'saturday' => 6, 'sat ' => 6, 'saturdays' => 6,
            'weekends' => 'weekend', 'weekend' => 'weekend',
            'weekday' => 'weekday', 'weekdays' => 'weekday',
        ];
        foreach ($day_map as $kw => $dow) {
            if (strpos($text, $kw) !== false) {
                if ($dow === 'weekend') {
                    $signals['day_prefs'][0] = 'sunday';
                    $signals['day_prefs'][6] = 'saturday';
                } elseif ($dow === 'weekday') {
                    for ($i = 1; $i <= 5; $i++) $signals['day_prefs'][$i] = true;
                } else {
                    $signals['day_prefs'][$dow] = $kw;
                }
            }
        }

        // ── TIME PREFERENCES ──
        $time_map = [
            'morning'      => ['morning', 'morn', 'am ', 'before noon', 'early'],
            'afternoon'    => ['afternoon', 'after lunch', 'midday', '1pm', '2pm', '3pm'],
            'after_school' => ['after school', 'after practice', '4pm', '5pm', '6pm', 'evening'],
            'evening'      => ['evening', 'night', '7pm', '8pm'],
        ];
        foreach ($time_map as $slot => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($text, $kw) !== false) {
                    $signals['time_prefs'][] = $slot;
                    break;
                }
            }
        }
        $signals['time_prefs'] = array_unique($signals['time_prefs']);

        // ── LOCATION PREFERENCES ──
        // Common PA/NJ/DE training areas near PTP operations
        $location_keywords = [
            'villanova', 'haverford', 'bryn mawr', 'radnor', 'wayne', 'devon',
            'malvern', 'chester', 'media', 'springfield', 'ridley', 'swarthmore',
            'ardmore', 'narberth', 'conshohocken', 'king of prussia', 'kop',
            'west chester', 'downingtown', 'exton', 'paoli', 'berwyn',
            'newtown square', 'glen mills', 'concordville', 'chadds ford',
            'cherry hill', 'moorestown', 'haddonfield', 'voorhees',
            'wilmington', 'hockessin', 'kennett square', 'princeton',
            'doylestown', 'lansdale', 'blue bell', 'horsham',
            'philadelphia', 'philly', 'center city', 'south philly', 'northeast',
            'temple', 'drexel', 'penn', 'st joes', 'st. joe',
        ];
        foreach ($location_keywords as $loc) {
            if (strpos($text, $loc) !== false) {
                $signals['location_prefs'][] = $loc;
            }
        }

        // ── TRAINER NAME PREFERENCES ──
        // Check if admin wrote a specific trainer name ("wants Eddy", "prefers CJ", "asked for Franke")
        $name_patterns = [
            'wants ', 'prefers ', 'asked for ', 'requesting ', 'request ',
            'likes ', 'loved ', 'pick ', 'assign ', 'match with ',
            'give them ', 'pair with ',
        ];
        foreach ($name_patterns as $pat) {
            $pos = strpos($text, $pat);
            if ($pos !== false) {
                $after = trim(substr($text, $pos + strlen($pat)));
                // Grab next 1-3 words as potential trainer name
                $words = preg_split('/[\s,.\-!]+/', $after, 4);
                $name_candidate = '';
                foreach (array_slice($words, 0, 3) as $w) {
                    if (strlen($w) < 2 || in_array($w, ['a', 'an', 'the', 'to', 'for', 'on', 'in', 'at', 'with'])) break;
                    $name_candidate .= ($name_candidate ? ' ' : '') . $w;
                }
                if (strlen($name_candidate) >= 2) {
                    $signals['trainer_names'][] = $name_candidate;
                }
            }
        }

        // ── PERSONALITY / STYLE SIGNALS ──
        $personality_map = [
            'patient'    => ['patient', 'calm', 'gentle', 'soft', 'easygoing', 'easy going', 'low key', 'chill'],
            'energetic'  => ['energetic', 'hype', 'loud', 'energy', 'intense', 'fired up', 'pumped'],
            'strict'     => ['strict', 'tough', 'discipline', 'push hard', 'no nonsense', 'demanding'],
            'funny'      => ['funny', 'humor', 'laughs', 'fun', 'goofy', 'silly'],
            'mentor'     => ['mentor', 'role model', 'big brother', 'big sister', 'relationship', 'bond'],
            'young'      => ['young', 'relatable', 'close in age', 'college age'],
            'experienced'=> ['experienced', 'veteran', 'seasoned', 'been around', 'lots of sessions'],
        ];
        foreach ($personality_map as $trait => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($text, $kw) !== false) {
                    $signals['personality'][] = $trait;
                    break;
                }
            }
        }

        // ── GENDER PREFERENCE ──
        if (preg_match('/\b(female|woman|girl)\b.*\b(coach|trainer)\b|\b(coach|trainer)\b.*\b(female|woman|girl)\b/', $text)) {
            $signals['gender_pref'] = 'female';
        } elseif (preg_match('/\b(male|guy|man)\b.*\b(coach|trainer)\b|\b(coach|trainer)\b.*\b(male|guy|man)\b/', $text)) {
            $signals['gender_pref'] = 'male';
        }

        // ── LEVEL PREFERENCE ──
        if (preg_match('/\b(pro|mls|professional|union)\b/', $text)) {
            $signals['level_pref'] = 'pro';
        } elseif (preg_match('/\b(college|d1|ncaa|university)\b/', $text)) {
            $signals['level_pref'] = 'college';
        }

        return $signals;
    }

    /* ═══════════════════════════════════════════════════════════
       LOCATION HELPERS
       ═══════════════════════════════════════════════════════════ */

    /**
     * Calculate distance between lead and trainer in miles.
     * Uses trainer lat/lng if available, falls back to training_locations.
     */
    private static function calc_distance($lead_coords, $trainer, $lead_zip) {
        if (!$lead_coords) return null;

        // Try trainer's primary lat/lng
        $t_lat = floatval($trainer->latitude ?? 0);
        $t_lng = floatval($trainer->longitude ?? 0);

        if ($t_lat && $t_lng) {
            return self::haversine($lead_coords['lat'], $lead_coords['lng'], $t_lat, $t_lng);
        }

        // Try training_locations JSON
        if (!empty($trainer->training_locations)) {
            $locs = json_decode($trainer->training_locations, true);
            if (is_array($locs)) {
                $min_dist = null;
                foreach ($locs as $loc) {
                    $ll = null;
                    if (isset($loc['lat'], $loc['lng'])) {
                        $ll = ['lat' => floatval($loc['lat']), 'lng' => floatval($loc['lng'])];
                    } elseif (isset($loc['zip'])) {
                        $ll = self::zip_to_coords($loc['zip']);
                    } elseif (isset($loc['address'])) {
                        // Can't geocode on the fly, skip
                    }
                    if ($ll) {
                        $d = self::haversine($lead_coords['lat'], $lead_coords['lng'], $ll['lat'], $ll['lng']);
                        if ($min_dist === null || $d < $min_dist) $min_dist = $d;
                    }
                }
                if ($min_dist !== null) return round($min_dist, 1);
            }
        }

        // Try matching by city/location string vs zip (rough)
        $trainer_loc = strtolower(trim($trainer->location ?? ''));
        if ($trainer_loc) {
            $trainer_coords = self::location_to_coords($trainer_loc, $trainer->state ?? '');
            if ($trainer_coords) {
                return round(self::haversine(
                    $lead_coords['lat'], $lead_coords['lng'],
                    $trainer_coords['lat'], $trainer_coords['lng']
                ), 1);
            }
        }

        return null; // Can't determine distance
    }

    /**
     * Haversine formula — distance in miles between two lat/lng points
     */
    private static function haversine($lat1, $lng1, $lat2, $lng2) {
        $R = 3959; // Earth radius in miles
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c;
    }

    /**
     * Zip to approximate coordinates (PA/NJ focused)
     * Uses a built-in lookup table for common PTP service area zips.
     * Falls back to transient-cached geocoding.
     */
    private static function zip_to_coords($zip) {
        $zip = preg_replace('/[^0-9]/', '', substr(trim($zip), 0, 5));
        if (strlen($zip) < 5) return null;

        // Check cache
        $cache_key = 'ptp_zip_' . $zip;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        // Built-in PA/NJ zip lookup (PTP service area)
        $coords = self::get_zip_coords($zip);
        if ($coords) {
            set_transient($cache_key, $coords, MONTH_IN_SECONDS);
            return $coords;
        }

        return null;
    }

    /**
     * PA/NJ/DE area zip code coordinates (covers PTP service area)
     */
    private static function get_zip_coords($zip) {
        // Major PTP service area zips — covers Philadelphia suburbs + NJ
        $table = [
            // Main Line / Chester County
            '19087' => [40.0454, -75.3558], // Wayne / Radnor
            '19085' => [40.0379, -75.3399], // Villanova
            '19010' => [40.0087, -75.3154], // Bryn Mawr
            '19041' => [40.0029, -75.2915], // Haverford
            '19003' => [39.9981, -75.3127], // Ardmore
            '19072' => [40.0098, -75.2589], // Narberth
            '19066' => [40.0048, -75.2396], // Merion Station
            '19096' => [40.0298, -75.2705], // Wynnewood
            '19312' => [40.0376, -75.4694], // Berwyn
            '19335' => [39.9579, -75.6051], // Downingtown
            '19380' => [39.9665, -75.5793], // West Chester
            '19382' => [39.9289, -75.5683], // West Chester (south)
            '19320' => [39.9822, -75.6123], // Coatesville
            '19333' => [40.0583, -75.4060], // Devon
            '19301' => [40.0720, -75.4519], // Paoli
            '19355' => [40.0254, -75.5032], // Malvern
            '19343' => [40.0879, -75.5247], // Phoenixville
            
            // Delaware County
            '19013' => [39.8621, -75.3535], // Chester
            '19015' => [39.9021, -75.3418], // Brookhaven
            '19018' => [39.9207, -75.2955], // Clifton Heights
            '19023' => [39.9048, -75.2703], // Darby
            '19026' => [39.9432, -75.2984], // Drexel Hill
            '19063' => [39.9154, -75.4051], // Media
            '19064' => [39.9069, -75.3581], // Springfield
            '19073' => [39.9341, -75.3976], // Newtown Square
            '19008' => [39.8893, -75.3593], // Broomall
            '19050' => [39.9541, -75.2580], // Lansdowne
            '19078' => [39.8771, -75.3293], // Ridley Park
            '19070' => [39.9070, -75.3148], // Morton
            '19082' => [39.9482, -75.2746], // Upper Darby

            // Montgomery County
            '19401' => [40.1181, -75.3398], // Norristown
            '19403' => [40.1364, -75.3782], // Norristown (west)
            '19406' => [40.0880, -75.3451], // King of Prussia
            '19422' => [40.1511, -75.2659], // Blue Bell
            '19428' => [40.0776, -75.3113], // Conshohocken
            '19462' => [40.0987, -75.3189], // Plymouth Meeting
            '19444' => [40.0894, -75.2293], // Lafayette Hill
            '19002' => [40.1525, -75.2209], // Ambler
            '19446' => [40.2075, -75.2839], // Lansdale
            '19454' => [40.2196, -75.2428], // North Wales
            '19426' => [40.1843, -75.4529], // Collegeville
            '19468' => [40.2098, -75.5165], // Royersford
            
            // Philadelphia
            '19103' => [39.9527, -75.1718], // Center City
            '19104' => [39.9584, -75.1985], // University City
            '19107' => [39.9509, -75.1578], // Washington Square
            '19118' => [40.0713, -75.2053], // Chestnut Hill
            '19119' => [40.0574, -75.1891], // Mt. Airy
            '19128' => [40.0468, -75.2278], // Roxborough
            '19131' => [39.9881, -75.2290], // Overbrook
            '19146' => [39.9374, -75.1755], // Graduate Hospital
            '19147' => [39.9356, -75.1534], // South Philly / Queen Village
            '19148' => [39.9159, -75.1571], // South Philly
            '19130' => [39.9652, -75.1718], // Fairmount
            '19121' => [39.9749, -75.1732], // North Philly / Temple
            
            // Bucks County
            '18901' => [40.3093, -75.1299], // Doylestown
            '18940' => [40.2546, -74.9507], // Newtown
            '19047' => [40.1828, -74.9381], // Langhorne
            '19053' => [40.1704, -74.9843], // Feasterville
            '19020' => [40.1142, -74.9503], // Bensalem
            '19054' => [40.1520, -74.8506], // Levittown
            '18974' => [40.2202, -75.0609], // Warminster
            '18976' => [40.2410, -75.0527], // Warrington
            '18966' => [40.1947, -75.0925], // Southampton
            
            // South Jersey
            '08002' => [39.9190, -75.0119], // Cherry Hill
            '08003' => [39.8852, -74.9768], // Cherry Hill (east)
            '08034' => [39.9044, -75.0434], // Cherry Hill (west)
            '08043' => [39.8432, -75.0315], // Voorhees
            '08054' => [39.9537, -74.9086], // Mt. Laurel
            '08057' => [39.9790, -74.9451], // Moorestown
            '08075' => [40.0043, -74.8810], // Riverside
            '08021' => [39.7961, -75.0630], // Clementon
            '08009' => [39.8021, -74.9539], // Berlin
            '08053' => [39.8718, -74.9371], // Marlton
            '08081' => [39.7410, -75.0234], // Sicklerville
            '08096' => [39.8541, -75.1274], // Woodbury
            '08033' => [39.8990, -75.0693], // Haddonfield
            '08108' => [39.9242, -75.0908], // Collingswood
            '08110' => [39.9576, -75.0605], // Pennsauken
            '08060' => [39.9486, -74.8028], // Mount Holly
            '08016' => [40.0602, -74.8556], // Burlington
            '08628' => [40.2587, -74.7826], // Trenton area
            '08540' => [40.3488, -74.6552], // Princeton
            '08536' => [40.3262, -74.5793], // Plainsboro
            '08610' => [40.2083, -74.7423], // Hamilton
            '08648' => [40.2652, -74.7178], // Lawrence Twp
        ];

        if (isset($table[$zip])) {
            return ['lat' => $table[$zip][0], 'lng' => $table[$zip][1]];
        }

        // Prefix-based fallback for zips not in table
        $prefix3 = substr($zip, 0, 3);
        $fallbacks = [
            '190' => [40.02, -75.35],  // Main Line PA
            '191' => [39.95, -75.16],  // Philadelphia
            '194' => [40.13, -75.34],  // Montgomery County
            '189' => [40.25, -75.05],  // Bucks County
            '193' => [40.00, -75.55],  // Chester County
            '080' => [39.88, -75.01],  // South Jersey
            '081' => [39.75, -75.03],  // South Jersey (south)
            '085' => [40.28, -74.70],  // Central NJ
            '086' => [40.22, -74.76],  // Mercer County NJ
        ];

        if (isset($fallbacks[$prefix3])) {
            return ['lat' => $fallbacks[$prefix3][0], 'lng' => $fallbacks[$prefix3][1]];
        }

        return null;
    }

    /**
     * Zip prefix to state (simple)
     */
    private static function zip_to_state($zip) {
        $zip = preg_replace('/[^0-9]/', '', substr(trim($zip), 0, 5));
        if (strlen($zip) < 3) return '';
        $prefix = intval(substr($zip, 0, 3));

        // PA: 150-196
        if ($prefix >= 150 && $prefix <= 196) return 'PA';
        // NJ: 070-089
        if ($prefix >= 70 && $prefix <= 89) return 'NJ';
        // DE: 197-199
        if ($prefix >= 197 && $prefix <= 199) return 'DE';
        // MD: 206-219
        if ($prefix >= 206 && $prefix <= 219) return 'MD';
        // NY: 100-149
        if ($prefix >= 100 && $prefix <= 149) return 'NY';
        // CT: 060-069
        if ($prefix >= 60 && $prefix <= 69) return 'CT';

        return '';
    }

    /**
     * Location string to approximate coords (common PTP training locations)
     */
    private static function location_to_coords($location, $state = '') {
        $loc = strtolower(trim($location));
        $known = [
            'villanova'     => [40.0379, -75.3399],
            'wayne'         => [40.0454, -75.3558],
            'radnor'        => [40.0454, -75.3558],
            'bryn mawr'     => [40.0087, -75.3154],
            'devon'         => [40.0583, -75.4060],
            'paoli'         => [40.0720, -75.4519],
            'malvern'       => [40.0254, -75.5032],
            'berwyn'        => [40.0376, -75.4694],
            'downingtown'   => [39.9579, -75.6051],
            'west chester'  => [39.9665, -75.5793],
            'media'         => [39.9154, -75.4051],
            'newtown square'=> [39.9341, -75.3976],
            'king of prussia' => [40.0880, -75.3451],
            'conshohocken'  => [40.0776, -75.3113],
            'blue bell'     => [40.1511, -75.2659],
            'norristown'    => [40.1181, -75.3398],
            'cherry hill'   => [39.9190, -75.0119],
            'moorestown'    => [39.9790, -74.9451],
            'mount laurel'  => [39.9537, -74.9086],
            'haddonfield'   => [39.8990, -75.0693],
            'princeton'     => [40.3488, -74.6552],
            'doylestown'    => [40.3093, -75.1299],
            'langhorne'     => [40.1828, -74.9381],
            'bensalem'      => [40.1142, -74.9503],
            'springfield'   => [39.9069, -75.3581],
            'drexel hill'   => [39.9432, -75.2984],
            'upper darby'   => [39.9482, -75.2746],
            'lansdale'      => [40.2075, -75.2839],
            'collegeville'  => [40.1843, -75.4529],
            'phoenixville'  => [40.0879, -75.5247],
            'haverford'     => [40.0029, -75.2915],
            'ardmore'       => [39.9981, -75.3127],
            'narberth'      => [40.0098, -75.2589],
            'chestnut hill' => [40.0713, -75.2053],
        ];

        foreach ($known as $name => $coords) {
            if (strpos($loc, $name) !== false) {
                return ['lat' => $coords[0], 'lng' => $coords[1]];
            }
        }

        return null;
    }

    /* ═══════════════════════════════════════════════════════════
       AVAILABILITY HELPER
       ═══════════════════════════════════════════════════════════ */

    /**
     * Count available slots for a trainer in the next 7 days.
     * Checks ptp_availability (recurring) minus booked slots.
     */
    private static function count_available_slots($trainer_id) {
        $slots = self::get_available_slots_detailed($trainer_id);
        return count($slots);
    }

    /**
     * Get DETAILED available slots for a trainer in the next N days.
     * Returns array of [date, day_name, start_time, end_time] for each open slot.
     * Used by admin scheduling UI.
     */
    public static function get_available_slots_detailed($trainer_id, $days = 7) {
        global $wpdb;

        $avail = $wpdb->get_results($wpdb->prepare(
            "SELECT day_of_week, start_time, end_time 
             FROM {$wpdb->prefix}ptp_availability 
             WHERE trainer_id = %d AND is_active = 1
             ORDER BY day_of_week, start_time",
            $trainer_id
        ));

        if (empty($avail)) {
            return [];
        }

        $today = current_time('Y-m-d');
        $day_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $slots = [];

        for ($d = 0; $d < $days; $d++) {
            $date = date('Y-m-d', strtotime("+{$d} days", strtotime($today)));
            $dow  = intval(date('w', strtotime($date)));

            foreach ($avail as $a) {
                if (intval($a->day_of_week) === $dow) {
                    $booked = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                         WHERE trainer_id = %d AND session_date = %s AND start_time = %s 
                         AND status NOT IN ('cancelled','rejected')",
                        $trainer_id, $date, $a->start_time
                    ));
                    if (!$booked) {
                        $slots[] = [
                            'date'       => $date,
                            'day_name'   => $day_names[$dow],
                            'day_short'  => substr($day_names[$dow], 0, 3),
                            'start_time' => $a->start_time,
                            'end_time'   => $a->end_time,
                            'start_fmt'  => date('g:iA', strtotime($a->start_time)),
                            'end_fmt'    => date('g:iA', strtotime($a->end_time)),
                            'date_fmt'   => date('M j', strtotime($date)),
                            'is_today'   => ($d === 0),
                            'is_tomorrow'=> ($d === 1),
                        ];
                    }
                }
            }
        }

        return $slots;
    }

    /**
     * Get all recurring availability blocks for a trainer (admin view).
     * Returns raw schedule: which days/times they're set as available.
     */
    public static function get_trainer_schedule($trainer_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, day_of_week, start_time, end_time, is_active
             FROM {$wpdb->prefix}ptp_availability 
             WHERE trainer_id = %d
             ORDER BY day_of_week, start_time",
            $trainer_id
        ));

        $day_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $schedule = [];
        foreach ($rows as $r) {
            $schedule[] = [
                'id'         => intval($r->id),
                'day'        => intval($r->day_of_week),
                'day_name'   => $day_names[intval($r->day_of_week)],
                'start_time' => $r->start_time,
                'end_time'   => $r->end_time,
                'start_fmt'  => date('g:iA', strtotime($r->start_time)),
                'end_fmt'    => date('g:iA', strtotime($r->end_time)),
                'is_active'  => intval($r->is_active),
            ];
        }
        return $schedule;
    }

    /**
     * Get booked sessions for a trainer in next N days (for calendar view).
     */
    public static function get_trainer_bookings($trainer_id, $days = 14) {
        global $wpdb;
        $today = current_time('Y-m-d');
        $end = date('Y-m-d', strtotime("+{$days} days", strtotime($today)));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT b.id, b.session_date, b.start_time, b.end_time, b.status, b.session_type,
                    b.location, p.display_name as parent_name, pl.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE b.trainer_id = %d 
             AND b.session_date BETWEEN %s AND %s
             AND b.status NOT IN ('cancelled','rejected')
             ORDER BY b.session_date, b.start_time",
            $trainer_id, $today, $end
        ));

        $bookings = [];
        foreach ($rows as $r) {
            $bookings[] = [
                'id'          => intval($r->id),
                'date'        => $r->session_date,
                'date_fmt'    => date('M j', strtotime($r->session_date)),
                'day_name'    => date('l', strtotime($r->session_date)),
                'start_time'  => $r->start_time,
                'end_time'    => $r->end_time,
                'start_fmt'   => date('g:iA', strtotime($r->start_time)),
                'status'      => $r->status,
                'type'        => $r->session_type ?? 'single',
                'location'    => $r->location ?? '',
                'parent_name' => $r->parent_name ?? '',
                'player_name' => $r->player_name ?? '',
            ];
        }
        return $bookings;
    }

    /* ═══════════════════════════════════════════════════════════
       GENERATE BOOKING LINK
       ═══════════════════════════════════════════════════════════ */
    public static function generate_booking_link($trainer_slug, $app_code = '') {
        $url = home_url("/trainer/{$trainer_slug}/");
        if ($app_code) {
            // v134: Pass the actual app_code (PTP-XXXXXX) — don't convert to FREE- prefix
            $url = add_query_arg('code', strtoupper($app_code), $url);
        }
        return $url;
    }

    /* ═══════════════════════════════════════════════════════════
       FORMAT SMS MESSAGE
       ═══════════════════════════════════════════════════════════ */
    public static function format_trainer_sms($lead, $trainer_match) {
        $parent_first = explode(' ', is_object($lead) ? $lead->parent_name : $lead['parent'])[0];
        $child_name   = is_object($lead) ? $lead->child_name : $lead['child'];
        $trainer_name = $trainer_match['name'];
        $trainer_first = explode(' ', $trainer_name)[0];
        $headline     = $trainer_match['headline'] ?: ($trainer_match['college'] ? $trainer_match['college'] . ' D1' : 'PTP Coach');
        $location     = $trainer_match['city'] ?: $trainer_match['location'];
        $slug         = $trainer_match['slug'];
        $app_code     = is_object($lead) ? $lead->app_code : ($lead['code'] ?? '');
        $booking_link = self::generate_booking_link($slug, $app_code);

        // Distance string
        $dist = $trainer_match['distance_mi'];
        $dist_str = ($dist !== null && $dist > 0) ? round($dist, 1) . ' mi from you' : '';

        // Slots string
        $slots = $trainer_match['slots_this_week'];
        $slot_str = $slots > 0 ? "{$slots} slots open this week" : 'limited availability';

        $msg = "PTP Soccer: Great talking to you {$parent_first}! As promised, here's {$child_name}'s free evaluation with Coach {$trainer_name} ({$headline}):\n\n";
        $msg .= "Book with {$trainer_first} → {$booking_link}\n\n";
        if ($location) $msg .= "📍 {$location}";
        if ($dist_str) $msg .= " ({$dist_str})";
        $msg .= "\n{$slot_str}. Grab a time before they fill!";

        return $msg;
    }

    /* ═══════════════════════════════════════════════════════════
       FIRST SESSION TRAINING PLAN GENERATOR
       Builds a structured 60-min plan based on child's age,
       skill level, goals, and matched trainer specialties.
       ═══════════════════════════════════════════════════════════ */
    public static function generate_training_plan($lead, $trainer_match = null) {
        $child_name = is_object($lead) ? ($lead->child_name ?? 'Player') : ($lead['child'] ?? 'Player');
        $child_age  = intval(is_object($lead) ? ($lead->child_age ?? 10) : ($lead['age'] ?? 10));
        $exp_level  = strtolower(is_object($lead) ? ($lead->experience_level ?? '') : ($lead['experience'] ?? ''));
        $challenge  = strtolower(is_object($lead) ? ($lead->biggest_challenge ?? '') : ($lead['challenge'] ?? ''));
        $goal_text  = strtolower(is_object($lead) ? ($lead->goal ?? '') : ($lead['goal'] ?? ''));
        $position   = is_object($lead) ? ($lead->position ?? '') : ($lead['position'] ?? '');
        $all_text   = $challenge . ' ' . $goal_text;

        // Determine skill tier
        $skill = 'beginner';
        if (strpos($exp_level, 'ecnl') !== false || strpos($exp_level, 'mls') !== false || strpos($exp_level, 'odp') !== false) {
            $skill = 'advanced';
        } elseif (strpos($exp_level, 'travel') !== false || strpos($exp_level, 'club') !== false || strpos($exp_level, 'varsity') !== false) {
            $skill = 'intermediate';
        }

        // Determine age bracket for drill intensity
        $age_bracket = 'young'; // 6-8
        if ($child_age >= 12) $age_bracket = 'older'; // 12-14
        elseif ($child_age >= 9) $age_bracket = 'middle'; // 9-11

        // ── Detect primary focus areas from goals ──
        $focus_areas = [];
        $focus_map = [
            'confidence'  => ['confiden','shy','afraid','nervous','mental','believe','scared'],
            'finishing'   => ['finish','scor','shoot','goal','strik','net'],
            'dribbling'   => ['dribbl','1v1','skill','move','beat','take on'],
            'speed'       => ['speed','fast','quick','agil','explos','sprint'],
            'ball_mastery'=> ['touch','control','techni','first touch','ball master','juggl'],
            'defending'   => ['defen','tackl','position','mark','intercep','back'],
            'passing'     => ['pass','vision','through ball','play mak','creativ','combin'],
            'tryout_prep' => ['tryout','try out','make the team','travel','select','compet'],
            'goalkeeper'  => ['keeper','goalie','goalkeeper','gk','goal keeper','saves'],
            'weak_foot'   => ['weak foot','left foot','both feet','two foot'],
        ];
        foreach ($focus_map as $focus => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($all_text, $kw) !== false) {
                    $focus_areas[] = $focus;
                    break;
                }
            }
        }
        // Default focus if nothing detected
        if (empty($focus_areas)) {
            $focus_areas = ($skill === 'beginner') ? ['ball_mastery', 'confidence'] : ['ball_mastery', 'dribbling'];
        }
        // Limit to top 3 focus areas
        $focus_areas = array_slice(array_unique($focus_areas), 0, 3);

        // ── Build the plan ──
        $plan = [
            'child_name'  => $child_name,
            'child_age'   => $child_age,
            'skill_level' => $skill,
            'focus_areas' => $focus_areas,
            'duration'    => 60,
            'sections'    => [],
        ];

        // Section 1: Warm-Up & Evaluation (10 min)
        $warmup = [
            'title'    => 'Warm-Up & Skill Evaluation',
            'duration' => 10,
            'purpose'  => 'Build rapport, assess current level, get comfortable',
            'drills'   => [],
        ];
        if ($age_bracket === 'young') {
            $warmup['drills'][] = ['name' => 'Ball Tag', 'desc' => 'Dribble in a grid — coach tries to tag their ball away. Fun intro that shows comfort on the ball.', 'min' => 3];
            $warmup['drills'][] = ['name' => 'Passing Catch', 'desc' => 'Pass back and forth while chatting. Coach evaluates: first touch, passing weight, body shape.', 'min' => 4];
            $warmup['drills'][] = ['name' => 'Juggling Check', 'desc' => 'See how many juggles (feet, thighs, head). No pressure — just a baseline.', 'min' => 3];
        } elseif ($age_bracket === 'middle') {
            $warmup['drills'][] = ['name' => 'Dynamic Passing', 'desc' => 'Two-touch passing on the move. Coach observes: pace of play, body positioning, first touch quality.', 'min' => 4];
            $warmup['drills'][] = ['name' => 'Dribble Circuit', 'desc' => 'Cones in a square — inside/outside cuts, sole rolls, Cruyff turns. Evaluates comfort and technique.', 'min' => 3];
            $warmup['drills'][] = ['name' => 'Juggling Freestyle', 'desc' => 'Feet only for 30 sec, then add thighs + head. Record personal baseline.', 'min' => 3];
        } else {
            $warmup['drills'][] = ['name' => 'Rondo Warm-Up', 'desc' => 'Coach + player vs imaginary defender — quick one/two touch combos. Evaluates decision speed & first touch.', 'min' => 4];
            $warmup['drills'][] = ['name' => 'Technical Circuit', 'desc' => 'Tight dribbling through cones, sharp turns, both feet. Push tempo to expose weaknesses.', 'min' => 3];
            $warmup['drills'][] = ['name' => 'Juggling Challenge', 'desc' => 'Target: 30+ juggles with both feet. Track left vs right foot comfort.', 'min' => 3];
        }
        $plan['sections'][] = $warmup;

        // Section 2: Core Training Block 1 (20 min) — primary focus
        $primary_focus = $focus_areas[0];
        $block1 = [
            'title'    => 'Core Training: ' . ucwords(str_replace('_', ' ', $primary_focus)),
            'duration' => 20,
            'purpose'  => self::get_focus_purpose($primary_focus),
            'drills'   => self::get_focus_drills($primary_focus, $age_bracket, $skill),
        ];
        $plan['sections'][] = $block1;

        // Section 3: Core Training Block 2 (15 min) — secondary focus
        $secondary_focus = $focus_areas[1] ?? $focus_areas[0];
        if ($secondary_focus === $primary_focus && count($focus_areas) > 2) {
            $secondary_focus = $focus_areas[2];
        }
        $block2 = [
            'title'    => 'Development: ' . ucwords(str_replace('_', ' ', $secondary_focus)),
            'duration' => 15,
            'purpose'  => self::get_focus_purpose($secondary_focus),
            'drills'   => self::get_focus_drills($secondary_focus, $age_bracket, $skill),
        ];
        // Trim to 3 drills for the shorter block
        $block2['drills'] = array_slice($block2['drills'], 0, 3);
        $plan['sections'][] = $block2;

        // Section 4: Game Application & Scrimmage (10 min)
        $game = [
            'title'    => 'Game Application',
            'duration' => 10,
            'purpose'  => 'Apply skills in game-like situations. This is where confidence is built.',
            'drills'   => [],
        ];
        if ($primary_focus === 'finishing' || $primary_focus === 'tryout_prep') {
            $game['drills'][] = ['name' => 'Shooting Under Pressure', 'desc' => 'Receive, turn, finish. Coach applies realistic defensive pressure increasing each rep.', 'min' => 5];
            $game['drills'][] = ['name' => '1v1 to Goal', 'desc' => 'Coach as defender — player must beat them and score. Rewards creativity and decisiveness.', 'min' => 5];
        } elseif ($primary_focus === 'goalkeeper') {
            $game['drills'][] = ['name' => 'Shot Stopping', 'desc' => 'Rapid-fire shots from different angles. Focus on set position and footwork between saves.', 'min' => 5];
            $game['drills'][] = ['name' => 'Breakaway Saves', 'desc' => 'Coach runs at goal 1v1 — player works on closing angles, timing, and bravery.', 'min' => 5];
        } else {
            $game['drills'][] = ['name' => '1v1 Battles', 'desc' => 'Attack vs defend — coach alternates roles. Apply the moves and techniques from today.', 'min' => 5];
            $game['drills'][] = ['name' => 'Skill Showcase Game', 'desc' => 'Small-sided game scenario. Bonus points for using today\'s focus skill (e.g., weak foot, new move).', 'min' => 5];
        }
        $plan['sections'][] = $game;

        // Section 5: Cool Down & Debrief (5 min)
        $cooldown = [
            'title'    => 'Cool Down & Session Debrief',
            'duration' => 5,
            'purpose'  => 'Reinforce learning, set homework, connect with parent',
            'drills'   => [
                ['name' => 'Light Juggling + Stretch', 'desc' => 'Easy touches while stretching. Player relaxes and reflects.', 'min' => 2],
                ['name' => 'Session Debrief', 'desc' => 'Coach shares: 3 things they noticed (1 strength, 2 areas to develop). Sets 1 homework challenge for the week.', 'min' => 2],
                ['name' => 'Parent Recap', 'desc' => 'Quick 60-sec recap to parent: "Here\'s what we worked on, what I saw, and the plan going forward." Sets stage for recurring sessions.', 'min' => 1],
            ],
        ];
        $plan['sections'][] = $cooldown;

        // ── Homework assignment ──
        $homework_map = [
            'ball_mastery' => '50 sole rolls + 50 inside-outside touches each foot before next session',
            'confidence'   => 'Try 1 new move in practice this week — doesn\'t matter if it fails, just try it',
            'finishing'    => '50 shots with each foot against a wall or rebounder. Focus on striking through the ball.',
            'dribbling'    => 'Practice 3 new moves (stepover, scissors, Cruyff turn) — 10 reps each side daily',
            'speed'        => 'Ladder/cone agility drill 3x this week. Time yourself and try to beat it.',
            'defending'    => 'Watch 5 minutes of a pro CB and note how they position their body before a tackle',
            'passing'      => '100 wall passes — 50 right foot, 50 left. Focus on first touch after the return.',
            'tryout_prep'  => 'Record yourself doing 5 mins of ball work. Review with coach next session.',
            'goalkeeper'   => 'Wall throws: stand 3 yards from wall, rapid catches for 3 min. Build hand speed.',
            'weak_foot'    => 'EVERYTHING on your weak foot for 1 practice this week. Passing, shooting, dribbling.',
        ];
        $plan['homework'] = $homework_map[$primary_focus] ?? $homework_map['ball_mastery'];

        // ── Trainer notes ──
        $trainer_notes = "FIRST SESSION EVAL — Look for:\n";
        $trainer_notes .= "• Dominant foot vs weak foot comfort level\n";
        $trainer_notes .= "• First touch quality under no pressure vs light pressure\n";
        $trainer_notes .= "• Body shape when receiving (open or closed)\n";
        $trainer_notes .= "• Confidence level: do they attempt things or play safe?\n";
        $trainer_notes .= "• Coachability: do they listen, try, adjust?\n";
        if ($primary_focus === 'confidence') {
            $trainer_notes .= "• ⚠️ Parent flagged confidence as key goal — extra encouragement, celebrate attempts not just results\n";
        }
        if ($primary_focus === 'tryout_prep') {
            $trainer_notes .= "• ⚠️ Tryout prep — ask which team/league and when tryouts are. Time-sensitive.\n";
        }
        $plan['trainer_notes'] = $trainer_notes;

        return $plan;
    }

    /* ── Focus area drill libraries ── */
    private static function get_focus_purpose($focus) {
        $purposes = [
            'ball_mastery' => 'Improve comfort and control on the ball — the foundation of everything',
            'confidence'   => 'Build confidence through achievable wins and positive reinforcement',
            'finishing'    => 'Sharpen shooting technique, composure in front of goal',
            'dribbling'    => 'Develop 1v1 ability, close control, and creativity on the ball',
            'speed'        => 'Improve acceleration, agility, and speed with the ball',
            'defending'    => 'Develop body positioning, tackling technique, and defensive awareness',
            'passing'      => 'Improve passing accuracy, weight, vision, and decision-making',
            'tryout_prep'  => 'Prepare for competitive tryouts with high-intensity, game-realistic drills',
            'goalkeeper'   => 'Develop shot-stopping, positioning, distribution, and communication',
            'weak_foot'    => 'Develop comfort and confidence on the non-dominant foot',
        ];
        return $purposes[$focus] ?? 'Develop core technical skills';
    }

    private static function get_focus_drills($focus, $age_bracket, $skill) {
        $drills = [];
        switch ($focus) {
            case 'ball_mastery':
                $drills[] = ['name' => 'Cone Weave Mastery', 'desc' => 'Weave through 8 cones (2yd spacing) using inside/outside of both feet. Time each run, try to beat it.', 'min' => 5];
                $drills[] = ['name' => 'Sole Roll Series', 'desc' => 'Sole roll forward, pull back, sole roll across body. Both feet. Builds that "glued to foot" feel.', 'min' => 5];
                $drills[] = ['name' => 'Box Touch Challenge', 'desc' => 'Inside a 3x3 yard box: as many different touches as possible in 30 sec. Inside, outside, sole, lace.', 'min' => 5];
                $drills[] = ['name' => 'Move of the Day', 'desc' => 'Coach demos one signature move (e.g., La Croqueta, Elastico). Break it down, rep it 20 times each side.', 'min' => 5];
                break;
            case 'confidence':
                $drills[] = ['name' => 'Success Ladder', 'desc' => 'Start with easy skill → medium → hard. Every level completed = win. Build momentum through achievable challenges.', 'min' => 5];
                $drills[] = ['name' => '1v1 with Head Start', 'desc' => 'Player gets 2-yard advantage in 1v1. Coach defends at ~70%. Player experiences beating a defender.', 'min' => 5];
                $drills[] = ['name' => 'Decision Game', 'desc' => 'Coach calls "left/right/shoot" during dribble. No wrong answers — just decisive action. Speed up over time.', 'min' => 5];
                $drills[] = ['name' => 'Freestyle Show', 'desc' => 'Player picks their best move and teaches it to coach. Flips the dynamic — builds ownership and confidence.', 'min' => 5];
                break;
            case 'finishing':
                $drills[] = ['name' => 'Striking Technique', 'desc' => 'Laces, inside, outside — 10 shots each technique. Coach corrects: plant foot, body over ball, follow through.', 'min' => 5];
                $drills[] = ['name' => 'Receive & Finish', 'desc' => 'Coach passes ball, player takes touch and shoots. Vary: ground pass, bouncing ball, back-to-goal turns.', 'min' => 6];
                $drills[] = ['name' => 'Finishing Angles', 'desc' => 'Shoot from 5 different angles/distances. Learn to pick corners: near post, far post, low, driven.', 'min' => 5];
                $drills[] = ['name' => '5-Shot Challenge', 'desc' => 'Player gets 5 shots — each from different game-realistic scenario. Score out of 5. Beat your record.', 'min' => 4];
                break;
            case 'dribbling':
                $drills[] = ['name' => 'Move Menu', 'desc' => 'Learn 3 moves: stepover, scissors, chop. 10 reps each at walking speed, then jogging, then full speed.', 'min' => 6];
                $drills[] = ['name' => 'Gauntlet Run', 'desc' => 'Dribble through cone corridor. Coach steps in as passive/active defender at each gate. Use a move to beat them.', 'min' => 5];
                $drills[] = ['name' => 'Change of Direction', 'desc' => 'Dribble fast → sharp cut on whistle. Inside cut, outside cut, drag back. Game speed reactions.', 'min' => 5];
                $drills[] = ['name' => '1v1 King', 'desc' => 'Full-speed 1v1: player must beat coach using any move to cross end line. 5 attempts.', 'min' => 4];
                break;
            case 'speed':
                $drills[] = ['name' => 'Acceleration Bursts', 'desc' => '5-yard explosive sprints from standing start. Focus on first 3 steps — low body, powerful push.', 'min' => 4];
                $drills[] = ['name' => 'Cone Agility Circuit', 'desc' => 'T-drill, L-drill, and box drill. Sharp cuts, low center of gravity. Time each run.', 'min' => 6];
                $drills[] = ['name' => 'Speed Dribble', 'desc' => 'Dribble at 80% speed through gates spaced 10 yards apart. Ball must stay within 2 yards of body.', 'min' => 5];
                $drills[] = ['name' => 'Chase Down', 'desc' => 'Coach rolls ball ahead — player must sprint to it and control it before the line. Competitive and fun.', 'min' => 5];
                break;
            case 'defending':
                $drills[] = ['name' => 'Body Shape Basics', 'desc' => 'Low stance, side-on, jockey position. Coach dribbles — player mirrors without diving in.', 'min' => 5];
                $drills[] = ['name' => 'Channeling', 'desc' => 'Force the attacker one direction. Use body angle to take away their strong foot. 10 reps.', 'min' => 5];
                $drills[] = ['name' => 'Tackle Timing', 'desc' => 'Wait for the bad touch. Coach deliberately takes loose touches — player reads and pokes ball away.', 'min' => 5];
                $drills[] = ['name' => '1v1 Defensive Wins', 'desc' => 'Player defends 1v1. Win = force turnover, block shot, or push to sideline. Track wins.', 'min' => 5];
                break;
            case 'passing':
                $drills[] = ['name' => 'Wall Passing Rhythm', 'desc' => 'Two-touch, one-touch alternating. Inside foot both sides. Focus on clean first touch setting up the pass.', 'min' => 5];
                $drills[] = ['name' => 'Target Practice', 'desc' => 'Place cones as targets 10/15/20 yards away. Hit each cone with inside, outside, and driven pass.', 'min' => 5];
                $drills[] = ['name' => 'Pass & Move Triangle', 'desc' => 'Pass to cone, sprint to next position, receive return from coach. Constant movement + accuracy.', 'min' => 5];
                $drills[] = ['name' => 'Through Ball Vision', 'desc' => 'Coach sets up "defenders" (cones). Player must thread passes through gaps to targets. Read the space.', 'min' => 5];
                break;
            case 'tryout_prep':
                $drills[] = ['name' => 'First Touch Under Pressure', 'desc' => 'Coach delivers balls at game speed: driven, bouncing, aerial. Player controls and plays forward immediately.', 'min' => 5];
                $drills[] = ['name' => 'Tryout Combine Drills', 'desc' => 'Sprint + receive + turn + shoot. Simulate the drill stations that coaches evaluate at tryouts.', 'min' => 6];
                $drills[] = ['name' => 'Small-Sided Decisions', 'desc' => '2v1, 3v2 scenarios. Quick decisions: when to pass, when to dribble, when to shoot. Coaches look for this.', 'min' => 5];
                $drills[] = ['name' => 'Fitness & Mentality', 'desc' => 'High-intensity work with short rest. Stay sharp when tired — this is where tryout cuts happen.', 'min' => 4];
                break;
            case 'goalkeeper':
                $drills[] = ['name' => 'Set Position & Footwork', 'desc' => 'Proper stance, shuffling across goal. Move feet THEN dive — never just fall sideways.', 'min' => 5];
                $drills[] = ['name' => 'Low Saves', 'desc' => 'Collapse saves to both sides. Hands lead, get body behind the ball. 10 each side.', 'min' => 5];
                $drills[] = ['name' => 'High Catches', 'desc' => 'Coach lobs balls — W-grip catches at highest point. Build confidence going up for the ball.', 'min' => 5];
                $drills[] = ['name' => 'Distribution', 'desc' => 'Goal kicks, throws, punt technique. Accuracy to targets 20/30/40 yards out.', 'min' => 5];
                break;
            case 'weak_foot':
                $drills[] = ['name' => 'Weak Foot Only Dribble', 'desc' => 'Dribble through cones using ONLY the weak foot. Slow at first — speed comes with comfort.', 'min' => 5];
                $drills[] = ['name' => 'Weak Foot Passing Wall', 'desc' => '50 passes against a wall — weak foot only. Inside, then driven, then outside of foot.', 'min' => 5];
                $drills[] = ['name' => 'Weak Foot Shooting', 'desc' => '20 shots weak foot from various positions. Focus on technique over power.', 'min' => 5];
                $drills[] = ['name' => 'Both Feet Challenge', 'desc' => 'Alternate: right pass, left pass, right shot, left shot. Build the habit of using both.', 'min' => 5];
                break;
            default:
                $drills = self::get_focus_drills('ball_mastery', $age_bracket, $skill);
        }
        return $drills;
    }

    /* ── Format plan as text (for SMS/display) ── */
    public static function format_plan_text($plan) {
        $out = "📋 SESSION PLAN: {$plan['child_name']} (Age {$plan['child_age']}, {$plan['skill_level']})\n";
        $out .= "Focus: " . implode(' + ', array_map(function($f){ return ucwords(str_replace('_',' ',$f)); }, $plan['focus_areas'])) . "\n";
        $out .= "Duration: {$plan['duration']} min\n";
        $out .= str_repeat('─', 40) . "\n\n";

        foreach ($plan['sections'] as $section) {
            $out .= "▸ {$section['title']} ({$section['duration']} min)\n";
            $out .= "  Purpose: {$section['purpose']}\n";
            foreach ($section['drills'] as $drill) {
                $out .= "  • {$drill['name']} ({$drill['min']} min) — {$drill['desc']}\n";
            }
            $out .= "\n";
        }

        $out .= "📝 HOMEWORK: {$plan['homework']}\n\n";
        $out .= "🗒 TRAINER NOTES:\n{$plan['trainer_notes']}";

        return $out;
    }
}
