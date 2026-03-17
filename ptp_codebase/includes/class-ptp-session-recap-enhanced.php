<?php
/**
 * PTP Session Recap Enhanced v216
 * 
 * Adds to existing recap system:
 * - Skill ratings (1-5) across multiple categories
 * - Private trainer notes (not shown to parent)
 * - Progress tracking over time (trend indicators)
 * - Rich parent-side recap view with expandable details
 * - Recap edit capability (update within 48 hours)
 * - Admin view of all recaps for a trainer
 */

defined('ABSPATH') || exit;

class PTP_Session_Recap_Enhanced {

    public static function init() {
        // Enhanced recap endpoints
        add_action('wp_ajax_ptp_send_session_recap_v2', array(__CLASS__, 'ajax_send_recap'));
        add_action('wp_ajax_ptp_edit_session_recap', array(__CLASS__, 'ajax_edit_recap'));
        add_action('wp_ajax_ptp_get_player_progress', array(__CLASS__, 'ajax_get_player_progress'));
        add_action('wp_ajax_ptp_get_recap_detail', array(__CLASS__, 'ajax_get_recap_detail'));

        // Parent-side
        add_action('wp_ajax_ptp_parent_get_recap', array(__CLASS__, 'ajax_parent_get_recap'));
        add_action('wp_ajax_ptp_parent_get_progress', array(__CLASS__, 'ajax_parent_get_progress'));

        // Ensure table has enhanced columns
        add_action('admin_init', array(__CLASS__, 'ensure_enhanced_schema'), 6);
        add_action('init', array(__CLASS__, 'ensure_enhanced_schema'), 6);
    }

    private static $schema_checked = false;

    public static function ensure_enhanced_schema() {
        if (self::$schema_checked) return;
        global $wpdb;

        $table = $wpdb->prefix . 'ptp_session_notes';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            // Table doesn't exist yet - PTP_Training_Plans will create it
            self::$schema_checked = true;
            return;
        }

        $cols = $wpdb->get_col("DESCRIBE {$table}");

        $additions = array(
            'skill_ratings' => "ADD COLUMN skill_ratings text DEFAULT NULL COMMENT 'JSON: {dribbling:4, passing:3, etc}'",
            'private_notes' => "ADD COLUMN private_notes text DEFAULT NULL COMMENT 'Trainer-only notes, not shown to parent'",
            'recap_edited_at' => "ADD COLUMN recap_edited_at datetime DEFAULT NULL",
            'parent_viewed_at' => "ADD COLUMN parent_viewed_at datetime DEFAULT NULL",
            'overall_rating' => "ADD COLUMN overall_rating tinyint(1) DEFAULT NULL COMMENT '1-5 overall session rating'",
            'session_type' => "ADD COLUMN session_type varchar(50) DEFAULT 'individual' COMMENT 'individual, group, evaluation'",
            'duration_minutes' => "ADD COLUMN duration_minutes int DEFAULT 60",
            'next_session_focus' => "ADD COLUMN next_session_focus text DEFAULT NULL COMMENT 'What trainer plans to work on next session'"
        );

        foreach ($additions as $col => $sql) {
            if (!in_array($col, $cols)) {
                $wpdb->query("ALTER TABLE {$table} {$sql}");
            }
        }

        self::$schema_checked = true;
    }

    /**
     * Skill categories for ratings
     */
    public static function get_skill_categories() {
        return array(
            'dribbling' => 'Dribbling',
            'passing' => 'Passing',
            'first_touch' => 'First Touch',
            'shooting' => 'Shooting',
            'defending' => 'Defending',
            'positioning' => 'Positioning',
            'fitness' => 'Fitness',
            'confidence' => 'Confidence',
            'game_iq' => 'Game IQ'
        );
    }

    /**
     * AJAX: Send enhanced session recap
     */
    public static function ajax_send_recap() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Missing booking ID'));
        }

        global $wpdb;

        // Verify trainer owns this booking
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));

        if (!$booking || $booking->trainer_id != $trainer_id) {
            wp_send_json_error(array('message' => 'Booking not found or unauthorized'));
        }

        // Check existing recap
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_session_notes WHERE booking_id = %d",
            $booking_id
        ));

        if ($existing) {
            wp_send_json_error(array('message' => 'Recap already sent for this session. Use edit instead.'));
        }

        // Parse skill ratings from JSON
        $skill_ratings = null;
        if (!empty($_POST['skill_ratings'])) {
            $ratings_raw = is_string($_POST['skill_ratings']) 
                ? json_decode(stripslashes($_POST['skill_ratings']), true) 
                : $_POST['skill_ratings'];
            if (is_array($ratings_raw)) {
                $valid_categories = array_keys(self::get_skill_categories());
                $skill_ratings = array();
                foreach ($ratings_raw as $key => $val) {
                    if (in_array($key, $valid_categories) && intval($val) >= 1 && intval($val) <= 5) {
                        $skill_ratings[$key] = intval($val);
                    }
                }
                $skill_ratings = !empty($skill_ratings) ? json_encode($skill_ratings) : null;
            }
        }

        $insert_data = array(
            'booking_id' => $booking_id,
            'player_id' => intval($booking->player_id),
            'trainer_id' => intval($trainer_id),
            'session_date' => $booking->session_date,
            'focus_worked_on' => sanitize_textarea_field($_POST['focus_worked_on'] ?? ''),
            'achievements' => sanitize_textarea_field($_POST['achievements'] ?? ''),
            'areas_to_improve' => sanitize_textarea_field($_POST['areas_to_improve'] ?? ''),
            'homework' => sanitize_textarea_field($_POST['homework'] ?? ''),
            'player_effort' => max(0, min(5, intval($_POST['player_effort'] ?? 0))),
            'private_notes' => sanitize_textarea_field($_POST['private_notes'] ?? ''),
            'skill_ratings' => $skill_ratings,
            'overall_rating' => max(0, min(5, intval($_POST['overall_rating'] ?? 0))),
            'session_type' => sanitize_text_field($_POST['session_type'] ?? 'individual'),
            'duration_minutes' => max(15, min(240, intval($_POST['duration_minutes'] ?? 60))),
            'is_visible_to_parent' => intval($_POST['is_visible_to_parent'] ?? 1),
            'next_session_focus' => sanitize_textarea_field($_POST['next_session_focus'] ?? ''),
            'created_at' => current_time('mysql')
        );

        $result = $wpdb->insert($wpdb->prefix . 'ptp_session_notes', $insert_data);

        if (!$result) {
            wp_send_json_error(array('message' => 'Failed to save recap'));
        }

        // Send email to parent
        self::send_recap_email($booking_id, $insert_data);

        wp_send_json_success(array(
            'message' => 'Recap sent to parent!',
            'recap_id' => $wpdb->insert_id
        ));
    }

    /**
     * AJAX: Edit existing recap (within 48 hours)
     */
    public static function ajax_edit_recap() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) wp_send_json_error(array('message' => 'Missing booking ID'));

        global $wpdb;

        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));

        $recap = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_session_notes WHERE booking_id = %d AND trainer_id = %d",
            $booking_id, $trainer_id
        ));

        if (!$recap) {
            wp_send_json_error(array('message' => 'Recap not found'));
        }

        // Allow edits within 48 hours
        $created = strtotime($recap->created_at);
        if (time() - $created > 48 * 3600) {
            wp_send_json_error(array('message' => 'Recaps can only be edited within 48 hours'));
        }

        $update_data = array(
            'focus_worked_on' => sanitize_textarea_field($_POST['focus_worked_on'] ?? $recap->focus_worked_on),
            'achievements' => sanitize_textarea_field($_POST['achievements'] ?? $recap->achievements),
            'areas_to_improve' => sanitize_textarea_field($_POST['areas_to_improve'] ?? $recap->areas_to_improve),
            'homework' => sanitize_textarea_field($_POST['homework'] ?? $recap->homework),
            'player_effort' => max(0, min(5, intval($_POST['player_effort'] ?? $recap->player_effort))),
            'private_notes' => sanitize_textarea_field($_POST['private_notes'] ?? $recap->private_notes),
            'recap_edited_at' => current_time('mysql')
        );

        // Handle skill ratings
        if (!empty($_POST['skill_ratings'])) {
            $ratings_raw = is_string($_POST['skill_ratings']) 
                ? json_decode(stripslashes($_POST['skill_ratings']), true) 
                : $_POST['skill_ratings'];
            if (is_array($ratings_raw)) {
                $valid_categories = array_keys(self::get_skill_categories());
                $skill_ratings = array();
                foreach ($ratings_raw as $key => $val) {
                    if (in_array($key, $valid_categories) && intval($val) >= 1 && intval($val) <= 5) {
                        $skill_ratings[$key] = intval($val);
                    }
                }
                $update_data['skill_ratings'] = json_encode($skill_ratings);
            }
        }

        $wpdb->update(
            $wpdb->prefix . 'ptp_session_notes',
            $update_data,
            array('id' => $recap->id)
        );

        wp_send_json_success(array('message' => 'Recap updated'));
    }

    /**
     * AJAX: Get player progress over time (for trainer view)
     */
    public static function ajax_get_player_progress() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $player_id = intval($_POST['player_id'] ?? 0);
        if (!$player_id) wp_send_json_error(array('message' => 'Missing player ID'));

        global $wpdb;

        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));

        // Get all recaps for this player by this trainer
        $recaps = $wpdb->get_results($wpdb->prepare(
            "SELECT sn.*, b.session_date, COALESCE(pl.name, 'Player') as player_name
             FROM {$wpdb->prefix}ptp_session_notes sn
             JOIN {$wpdb->prefix}ptp_bookings b ON sn.booking_id = b.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON sn.player_id = pl.id
             WHERE sn.player_id = %d AND sn.trainer_id = %d
             ORDER BY b.session_date DESC
             LIMIT 20",
            $player_id, $trainer_id
        ));

        // Build skill trends
        $skill_trends = array();
        $categories = self::get_skill_categories();

        foreach (array_reverse($recaps) as $recap) {
            if (!empty($recap->skill_ratings)) {
                $ratings = json_decode($recap->skill_ratings, true);
                if (is_array($ratings)) {
                    foreach ($ratings as $skill => $val) {
                        if (!isset($skill_trends[$skill])) {
                            $skill_trends[$skill] = array(
                                'label' => $categories[$skill] ?? ucfirst($skill),
                                'values' => array(),
                                'dates' => array()
                            );
                        }
                        $skill_trends[$skill]['values'][] = intval($val);
                        $skill_trends[$skill]['dates'][] = $recap->session_date;
                    }
                }
            }
        }

        // Calculate trend direction for each skill
        foreach ($skill_trends as $skill => &$data) {
            $vals = $data['values'];
            if (count($vals) >= 2) {
                $recent = array_slice($vals, -2);
                $data['trend'] = $recent[1] > $recent[0] ? 'up' : ($recent[1] < $recent[0] ? 'down' : 'flat');
                $data['current'] = end($vals);
            } else {
                $data['trend'] = 'flat';
                $data['current'] = end($vals) ?: 0;
            }
        }

        // Effort trend
        $effort_values = array_filter(array_map(function($r) { return intval($r->player_effort); }, array_reverse($recaps)));

        wp_send_json_success(array(
            'player_name' => !empty($recaps) ? $recaps[0]->player_name : 'Player',
            'total_sessions' => count($recaps),
            'skill_trends' => $skill_trends,
            'effort_trend' => array_values($effort_values),
            'recaps' => array_map(function($r) {
                return array(
                    'id' => $r->id,
                    'booking_id' => $r->booking_id,
                    'session_date' => $r->session_date,
                    'focus' => $r->focus_worked_on,
                    'wins' => $r->achievements,
                    'improve' => $r->areas_to_improve,
                    'homework' => $r->homework,
                    'effort' => intval($r->player_effort),
                    'private_notes' => $r->private_notes,
                    'skills' => $r->skill_ratings ? json_decode($r->skill_ratings, true) : null,
                    'overall' => intval($r->overall_rating ?? 0),
                    'editable' => (time() - strtotime($r->created_at)) < 48 * 3600
                );
            }, $recaps)
        ));
    }

    /**
     * AJAX: Parent get recap detail (marks as viewed)
     */
    public static function ajax_parent_get_recap() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $booking_id = intval($_POST['booking_id'] ?? 0);
        if (!$booking_id) wp_send_json_error(array('message' => 'Missing booking'));

        global $wpdb;

        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            get_current_user_id()
        ));

        // Verify parent owns this booking
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d AND parent_id = %d",
            $booking_id, $parent_id
        ));

        if (!$booking) {
            wp_send_json_error(array('message' => 'Not found'));
        }

        $recap = $wpdb->get_row($wpdb->prepare(
            "SELECT sn.*, t.display_name as trainer_name, t.photo_url as trainer_photo
             FROM {$wpdb->prefix}ptp_session_notes sn
             JOIN {$wpdb->prefix}ptp_trainers t ON sn.trainer_id = t.id
             WHERE sn.booking_id = %d AND sn.is_visible_to_parent = 1",
            $booking_id
        ));

        if (!$recap) {
            wp_send_json_error(array('message' => 'No recap available'));
        }

        // Mark as viewed
        if (empty($recap->parent_viewed_at)) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_session_notes',
                array('parent_viewed_at' => current_time('mysql')),
                array('id' => $recap->id)
            );
        }

        $skill_categories = self::get_skill_categories();
        $skills_display = array();
        if (!empty($recap->skill_ratings)) {
            $skills = json_decode($recap->skill_ratings, true);
            if (is_array($skills)) {
                foreach ($skills as $key => $val) {
                    $skills_display[] = array(
                        'name' => $skill_categories[$key] ?? ucfirst($key),
                        'rating' => intval($val)
                    );
                }
            }
        }

        $effort_labels = array(1 => 'Needs Work', 2 => 'Fair', 3 => 'Good', 4 => 'Great', 5 => 'Elite');

        wp_send_json_success(array(
            'focus_worked_on' => $recap->focus_worked_on,
            'achievements' => $recap->achievements,
            'areas_to_improve' => $recap->areas_to_improve,
            'homework' => $recap->homework,
            'player_effort' => intval($recap->player_effort),
            'effort_label' => $effort_labels[intval($recap->player_effort)] ?? '',
            'overall_rating' => intval($recap->overall_rating ?? 0),
            'skills' => $skills_display,
            'trainer_name' => $recap->trainer_name,
            'trainer_photo' => $recap->trainer_photo,
            'session_date' => $recap->session_date,
            'created_at' => $recap->created_at
        ));
    }

    /**
     * AJAX: Parent get player progress over time
     */
    public static function ajax_parent_get_progress() {
        check_ajax_referer('ptp_nonce', 'nonce');

        $player_id = intval($_POST['player_id'] ?? 0);
        if (!$player_id) wp_send_json_error(array('message' => 'Missing player'));

        global $wpdb;

        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            get_current_user_id()
        ));

        // Only show recaps where parent owns the booking
        $recaps = $wpdb->get_results($wpdb->prepare(
            "SELECT sn.session_date, sn.player_effort, sn.skill_ratings, sn.overall_rating,
                    sn.focus_worked_on, sn.achievements, sn.homework,
                    t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_session_notes sn
             JOIN {$wpdb->prefix}ptp_bookings b ON sn.booking_id = b.id
             JOIN {$wpdb->prefix}ptp_trainers t ON sn.trainer_id = t.id
             WHERE sn.player_id = %d AND b.parent_id = %d AND sn.is_visible_to_parent = 1
             ORDER BY sn.session_date DESC
             LIMIT 20",
            $player_id, $parent_id
        ));

        if (empty($recaps)) {
            wp_send_json_success(array('recaps' => array(), 'total' => 0));
        }

        $categories = self::get_skill_categories();
        $skill_summary = array();

        foreach (array_reverse($recaps) as $r) {
            if (!empty($r->skill_ratings)) {
                $ratings = json_decode($r->skill_ratings, true);
                if (is_array($ratings)) {
                    foreach ($ratings as $key => $val) {
                        if (!isset($skill_summary[$key])) {
                            $skill_summary[$key] = array(
                                'name' => $categories[$key] ?? ucfirst($key),
                                'values' => array()
                            );
                        }
                        $skill_summary[$key]['values'][] = intval($val);
                    }
                }
            }
        }

        // Add trend to each skill
        foreach ($skill_summary as &$s) {
            $vals = $s['values'];
            $s['current'] = end($vals) ?: 0;
            $s['trend'] = count($vals) >= 2 
                ? ($vals[count($vals)-1] > $vals[count($vals)-2] ? 'improving' : ($vals[count($vals)-1] < $vals[count($vals)-2] ? 'declining' : 'stable'))
                : 'stable';
        }

        wp_send_json_success(array(
            'total' => count($recaps),
            'skills' => $skill_summary,
            'recaps' => array_map(function($r) {
                return array(
                    'date' => $r->session_date,
                    'trainer' => $r->trainer_name,
                    'focus' => $r->focus_worked_on,
                    'wins' => $r->achievements,
                    'homework' => $r->homework,
                    'effort' => intval($r->player_effort),
                    'overall' => intval($r->overall_rating ?? 0)
                );
            }, $recaps)
        ));
    }

    /**
     * Send recap email to parent
     */
    private static function send_recap_email($booking_id, $data) {
        global $wpdb;

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, 
                    t.display_name as trainer_name, t.photo_url as trainer_photo,
                    COALESCE(pa.display_name, 'Parent') as parent_name, 
                    pa.user_id as parent_user_id, pa.email as parent_email,
                    COALESCE(pl.name, 'Player') as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE b.id = %d",
            $booking_id
        ));

        if (!$booking) return;

        $parent_email = '';
        if (!empty($booking->parent_email)) {
            $parent_email = $booking->parent_email;
        } elseif (!empty($booking->parent_user_id)) {
            $user = get_userdata($booking->parent_user_id);
            if ($user) $parent_email = $user->user_email;
        }

        if (empty($parent_email)) return;

        $effort = intval($data['player_effort'] ?? 0);
        $effort_labels = array(1 => 'Needs Work', 2 => 'Fair', 3 => 'Good', 4 => 'Great', 5 => 'Elite');

        // Build skill ratings section
        $skills_html = '';
        if (!empty($data['skill_ratings'])) {
            $ratings = is_string($data['skill_ratings']) ? json_decode($data['skill_ratings'], true) : $data['skill_ratings'];
            if (is_array($ratings) && !empty($ratings)) {
                $categories = self::get_skill_categories();
                $skills_html = '<div style="margin:16px 0;padding:16px;background:#F9FAFB;border-radius:12px">';
                $skills_html .= '<p style="font-weight:700;font-size:14px;margin:0 0 12px;color:#1A1A1A">Skill Assessment</p>';
                foreach ($ratings as $key => $val) {
                    $label = $categories[$key] ?? ucfirst($key);
                    $dots = '';
                    for ($i = 1; $i <= 5; $i++) {
                        $color = $i <= $val ? '#FCB900' : '#E5E7EB';
                        $dots .= '<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:'.$color.';margin-right:3px"></span>';
                    }
                    $skills_html .= '<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #F3F4F6">';
                    $skills_html .= '<span style="font-size:13px;color:#374151">'.$label.'</span>';
                    $skills_html .= '<span>'.$dots.'</span></div>';
                }
                $skills_html .= '</div>';
            }
        }

        $email_data = array(
            'parent_name'      => $booking->parent_name,
            'player_name'      => $booking->player_name,
            'trainer_name'     => $booking->trainer_name,
            'trainer_photo'    => $booking->trainer_photo ?: '',
            'session_date'     => date('l, F j, Y', strtotime($booking->session_date)),
            'focus_worked_on'  => $data['focus_worked_on'] ?? '',
            'achievements'     => $data['achievements'] ?? '',
            'areas_to_improve' => $data['areas_to_improve'] ?? '',
            'homework'         => $data['homework'] ?? '',
            'next_session_focus' => $data['next_session_focus'] ?? '',
            'player_effort'    => $effort,
            'effort_label'     => $effort_labels[$effort] ?? '',
            'skills_html'      => $skills_html,
            'ai_plan_html'     => '',
            'dashboard_url'    => home_url('/parent-dashboard/')
        );

        // Use PTP_Email render if available, otherwise basic email
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'render_training_plan_email')) {
            $body = PTP_Email::render_training_plan_email($email_data);
        } elseif (class_exists('PTP_Email') && method_exists('PTP_Email', 'render_session_recap_email')) {
            $body = PTP_Email::render_session_recap_email($email_data);
        } else {
            $body = self::render_basic_recap_email($email_data);
        }

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $subject = "{$booking->player_name}'s Session Recap — {$booking->trainer_name}";
        wp_mail($parent_email, $subject, $body, $headers);
    }

    /**
     * Basic recap email template (fallback)
     */
    private static function render_basic_recap_email($data) {
        $effort_bar = '';
        if ($data['player_effort'] > 0) {
            for ($i = 1; $i <= 5; $i++) {
                $color = $i <= $data['player_effort'] ? '#FCB900' : '#E5E7EB';
                $effort_bar .= '<span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:'.$color.';margin-right:4px"></span>';
            }
        }

        $sections = '';
        $fields = array(
            array('#FCB900', 'What We Worked On', $data['focus_worked_on']),
            array('#22C55E', 'Wins', $data['achievements']),
            array('#F59E0B', 'Keep Working On', $data['areas_to_improve']),
            array('#3B82F6', 'Homework / Practice Plan', $data['homework']),
            array('#8B5CF6', 'Next Session Focus', $data['next_session_focus'] ?? '')
        );

        foreach ($fields as $f) {
            if (!empty($f[2])) {
                $sections .= '<div style="margin-bottom:16px;padding:14px;background:#F9FAFB;border-radius:10px;border-left:4px solid '.$f[0].'">';
                $sections .= '<div style="font-weight:700;font-size:13px;color:#1A1A1A;margin-bottom:6px">'.$f[1].'</div>';
                $sections .= '<div style="font-size:14px;color:#374151;line-height:1.6">'.nl2br(esc_html($f[2])).'</div>';
                $sections .= '</div>';
            }
        }

        return '
        <div style="max-width:560px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">
            <div style="padding:24px;background:#0A0A0A;border-radius:16px 16px 0 0;text-align:center">
                <div style="font-family:Oswald,sans-serif;font-size:18px;font-weight:700;color:#FCB900;letter-spacing:1px;text-transform:uppercase">TRAINING PLAN</div>
                <div style="color:#fff;font-size:14px;margin-top:6px">'.$data['player_name'].' with '.$data['trainer_name'].'</div>
                <div style="color:#9CA3AF;font-size:12px;margin-top:4px">'.$data['session_date'].'</div>
            </div>
            <div style="padding:24px;background:#fff;border:1px solid #E5E7EB;border-top:none;border-radius:0 0 16px 16px">
                '.$sections.'
                '.$data['skills_html'].'
                '.($effort_bar ? '<div style="text-align:center;padding:12px 0"><div style="font-size:12px;color:#6B7280;margin-bottom:6px">Effort Level</div>'.$effort_bar.'<div style="font-size:12px;color:#374151;margin-top:4px">'.$data['effort_label'].'</div></div>' : '').'
                <div style="text-align:center;margin-top:20px">
                    <a href="'.$data['dashboard_url'].'" style="display:inline-block;padding:12px 32px;background:#FCB900;color:#0A0A0A;font-weight:700;text-decoration:none;border-radius:8px;font-size:14px">View Full Progress</a>
                </div>
            </div>
        </div>';
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_Session_Recap_Enhanced', 'init'), 25);
