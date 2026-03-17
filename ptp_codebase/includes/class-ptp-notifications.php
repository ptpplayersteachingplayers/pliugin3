<?php
/**
 * Notifications Class
 */

defined('ABSPATH') || exit;

class PTP_Notifications {
    
    public static function create($user_id, $type, $title, $message, $data = array()) {
        global $wpdb;
        
        return $wpdb->insert($wpdb->prefix . 'ptp_notifications', array(
            'user_id' => $user_id,
            'type' => sanitize_text_field($type),
            'title' => sanitize_text_field($title),
            'message' => sanitize_textarea_field($message),
            'data' => json_encode($data),
        ));
    }
    
    public static function get_for_user($user_id, $limit = 20, $unread_only = false) {
        global $wpdb;
        
        $where = "user_id = %d";
        $params = array($user_id);
        
        if ($unread_only) {
            $where .= " AND is_read = 0";
        }
        
        $params[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_notifications WHERE $where ORDER BY created_at DESC LIMIT %d",
            $params
        ));
    }
    
    public static function get_unread_count($user_id) {
        global $wpdb;
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_notifications WHERE user_id = %d AND is_read = 0",
            $user_id
        ));
    }
    
    public static function mark_as_read($notification_id, $user_id) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_notifications',
            array('is_read' => 1, 'read_at' => current_time('mysql')),
            array('id' => $notification_id, 'user_id' => $user_id)
        );
    }
    
    public static function mark_all_read($user_id) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_notifications',
            array('is_read' => 1, 'read_at' => current_time('mysql')),
            array('user_id' => $user_id, 'is_read' => 0)
        );
    }
    
    public static function booking_created($booking_id) {
        $booking = PTP_Booking::get_full($booking_id);
        if (!$booking) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ptp_log('PTP Notifications: booking_created failed - booking not found: ' . $booking_id);
            }
            return;
        }
        
        // Extract date/time with fallbacks
        $date_raw = $booking->session_date ?: '';
        $time_raw = $booking->start_time ?: ($booking->session_time ?: '');
        
        // Fallback: parse from session_datetime if direct fields empty
        if ((!$date_raw || $date_raw === '0000-00-00') && !empty($booking->session_datetime)) {
            $dt = strtotime($booking->session_datetime);
            if ($dt) {
                $date_raw = date('Y-m-d', $dt);
                if (!$time_raw) $time_raw = date('H:i:s', $dt);
            }
        }
        
        $date = $date_raw ? date('l, F j', strtotime($date_raw)) : 'Date TBD';
        $time = $time_raw ? date('g:i A', strtotime($time_raw)) : 'Time TBD';
        
        // Get user data with null checks
        $trainer_user = isset($booking->trainer_user_id) ? get_userdata($booking->trainer_user_id) : null;
        $parent_user = isset($booking->parent_user_id) ? get_userdata($booking->parent_user_id) : null;
        
        // Notify trainer (in-app notification)
        if ($booking->trainer_user_id) {
            self::create(
                $booking->trainer_user_id,
                'new_booking',
                'New Booking!',
                sprintf('%s booked a session with %s on %s at %s', 
                    $booking->parent_name ?: 'A parent', $booking->player_name ?: 'a player', $date, $time),
                array('booking_id' => $booking_id)
            );
        }
        
        // Send email to trainer
        $trainer_email_addr = ($trainer_user && is_email($trainer_user->user_email)) 
            ? $trainer_user->user_email 
            : ($booking->trainer_email ?: '');
        
        if ($trainer_email_addr && is_email($trainer_email_addr)) {
            $trainer_subject = 'New Booking - ' . ($booking->player_name ?: 'New Player') . ' on ' . $date;
            $trainer_body = self::render_booking_email_html('trainer', $booking, $date, $time);
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . ptp_email_brand('from_training'));
            $sent = wp_mail($trainer_email_addr, $trainer_subject, $trainer_body, $headers);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ptp_log('PTP Notifications: Trainer email ' . ($sent ? 'sent' : 'FAILED') . ' to ' . $trainer_email_addr);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ptp_log('PTP Notifications: No valid trainer email for booking ' . $booking_id);
            }
        }
        
        // Notify parent (in-app notification)
        if ($booking->parent_user_id) {
            self::create(
                $booking->parent_user_id,
                'booking_confirmed',
                'Booking Confirmed!',
                sprintf('Your session with %s on %s at %s is confirmed!', 
                    $booking->trainer_name ?: 'your coach', $date, $time),
                array('booking_id' => $booking_id)
            );
        }
        
        // Send email to parent - with fallbacks for guest checkout
        $parent_email = null;
        if ($parent_user && is_email($parent_user->user_email)) {
            $parent_email = $parent_user->user_email;
        } elseif (!empty($booking->parent_email) && is_email($booking->parent_email)) {
            $parent_email = $booking->parent_email;
        } elseif (!empty($booking->guest_email) && is_email($booking->guest_email)) {
            $parent_email = $booking->guest_email;
        }
        
        if ($parent_email) {
            $parent_subject = 'Session Confirmed - ' . ($booking->trainer_name ?: 'Your Coach') . ' on ' . $date;
            $parent_body = self::render_booking_email_html('parent', $booking, $date, $time);
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . ptp_email_brand('from_training'));
            $sent = wp_mail($parent_email, $parent_subject, $parent_body, $headers);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ptp_log('PTP Notifications: Parent email ' . ($sent ? 'sent' : 'FAILED') . ' to ' . $parent_email);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ptp_log('PTP Notifications: No valid parent email for booking ' . $booking_id);
            }
        }
        
        // Admin notification for free session bookings
        $is_free = strpos($booking->source ?? '', 'free_session') !== false;
        if ($is_free) {
            $admin_email = get_option('admin_email');
            if ($admin_email) {
                $player_info = ($booking->player_name ?: 'Player');
                if (!empty($booking->player_age)) $player_info .= ' (age ' . intval($booking->player_age) . ')';
                $admin_body  = "FREE SESSION BOOKED\n\n";
                $admin_body .= "Trainer: " . ($booking->trainer_name ?: 'N/A') . "\n";
                $admin_body .= "Player: {$player_info}\n";
                $admin_body .= "Parent: " . ($booking->parent_name ?: 'N/A') . "\n";
                if (!empty($booking->parent_phone)) $admin_body .= "Phone: {$booking->parent_phone}\n";
                if (!empty($booking->parent_email)) $admin_body .= "Email: {$booking->parent_email}\n";
                $admin_body .= "Date: {$date} at {$time}\n";
                $admin_body .= "Location: " . ($booking->location ?: 'TBD') . "\n";
                if (!empty($booking->player_goals)) $admin_body .= "Goals: {$booking->player_goals}\n";
                $admin_body .= "\n" . admin_url('admin.php?page=ptp-free-sessions');
                wp_mail($admin_email, "[PTP] FREE SESSION BOOKED - {$booking->trainer_name}: {$player_info} on {$date}", $admin_body);
            }
        }
    }
    
    /**
     * Render HTML email for booking notifications
     */
    private static function render_booking_email_html($type, $booking, $date, $time) {
        $logo_url = 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png';
        $dashboard_url = home_url($type === 'trainer' ? '/trainer-dashboard/' : '/parent-dashboard/');
        $is_free_session = strpos($booking->source ?? '', 'free_session') !== false;
        
        // Pull names with fallbacks
        $trainer_name  = $booking->trainer_name ?: 'Your Coach';
        $parent_name   = $booking->parent_name ?: 'Parent';
        $player_name   = $booking->player_name ?: 'Player';
        $parent_first  = explode(' ', $parent_name)[0];
        $location      = $booking->location ?: '';
        
        // Rich data from get_full (player_age, player_goals, etc.)
        $player_age      = !empty($booking->player_age) ? intval($booking->player_age) : 0;
        $player_position = $booking->player_position ?? '';
        $player_goals    = $booking->player_goals ?? '';
        $player_skill    = $booking->player_skill ?? '';
        $parent_phone    = $booking->parent_phone ?? '';
        $trainer_headline = $booking->trainer_headline ?? '';
        $trainer_college  = $booking->trainer_college ?? '';
        $notes           = $booking->notes ?? '';
        $duration        = intval($booking->duration_minutes ?? 60);
        
        // Free session app source data (stashed by get_full)
        $fsa = isset($booking->_fsa_source) ? $booking->_fsa_source : null;
        if ($fsa) {
            if (!$player_age && !empty($fsa->child_age))         $player_age = intval($fsa->child_age);
            if (!$player_position && !empty($fsa->position))     $player_position = $fsa->position;
            if (!$player_goals)                                  $player_goals = trim(($fsa->biggest_challenge ?? '') . '. ' . ($fsa->goal ?? ''));
            if (!$parent_phone && !empty($fsa->phone))           $parent_phone = $fsa->phone;
            if (!$player_skill && !empty($fsa->experience_level)) $player_skill = $fsa->experience_level;
        }
        
        // Build trainer profile URL
        $trainer_profile_url = ($booking->trainer_slug ?? '') ? home_url('/trainer/' . $booking->trainer_slug . '/') : '';
        
        if ($type === 'trainer') {
            $heading = 'New Session Booked!';
            $message = $is_free_session 
                ? "A free evaluation session has been booked. Here's everything you need to know:" 
                : 'You have a new training session booked.';
            $details = array();
            $details['Player'] = $player_name . ($player_age ? ' (age ' . $player_age . ')' : '');
            if ($player_position)  $details['Position'] = ucfirst($player_position);
            if ($player_skill)     $details['Level'] = ucfirst($player_skill);
            $details['Parent']     = $parent_name;
            if ($parent_phone)     $details['Parent Phone'] = $parent_phone;
            $details['Date']       = $date;
            $details['Time']       = $time . ' (' . $duration . ' min)';
            if ($location)         $details['Location'] = $location;
            if ($player_goals)     $details['Focus / Goals'] = $player_goals;
            if ($notes && !strpos($notes, 'One-click')) $details['Notes'] = $notes;
            $cta_text = 'View in Dashboard';
        } else {
            $heading = 'Session Confirmed!';
            $message = $is_free_session
                ? "Great news {$parent_first}! Your free evaluation session is locked in."
                : "Your training session is confirmed, {$parent_first}!";
            $details = array();
            $details['Coach']    = $trainer_name . ($trainer_headline ? ' — ' . $trainer_headline : ($trainer_college ? ' (' . $trainer_college . ')' : ''));
            $details['Player']   = $player_name . ($player_age ? ' (age ' . $player_age . ')' : '');
            $details['Date']     = $date;
            $details['Time']     = $time . ' (' . $duration . ' min)';
            if ($location) $details['Location'] = $location;
            $details['Booking #'] = $booking->booking_number ?: ('#' . $booking->id);
            $cta_text = $trainer_profile_url ? 'Meet Your Coach' : 'View Booking';
            if ($trainer_profile_url) $dashboard_url = $trainer_profile_url;
        }
        
        // Build "what to bring" section for parent free session emails
        $extra_section = '';
        if ($type === 'parent' && $is_free_session) {
            $extra_section = '
                <table width="100%" style="background: #FFF9E6; border-radius: 8px; margin-bottom: 24px; border: 1px solid #FCB90040;" cellpadding="12" cellspacing="0">
                    <tr><td style="font-size: 14px; font-weight: 700; color: #0A0A0A;">What to Bring</td></tr>
                    <tr><td style="font-size: 13px; color: #525252; line-height: 1.6; padding-top: 0;">
                        &bull; Cleats, shin guards, and a water bottle<br>
                        &bull; A soccer ball (we have extras if needed)<br>
                        &bull; ' . esc_html($player_name) . ' should arrive 5-10 minutes early<br>
                        &bull; Questions? Reply to this email or text us anytime
                    </td></tr>
                </table>';
        }
        
        // Build prep section for trainer free session emails
        if ($type === 'trainer' && $is_free_session) {
            $prep_items = array('Review the player details above before your session');
            if ($player_goals) $prep_items[] = 'Focus area: ' . esc_html(substr($player_goals, 0, 100));
            $prep_items[] = 'First 10 min: connect with the kid, learn what they love about soccer';
            $prep_items[] = 'Last 5 min: give the parent one honest observation and one action item';
            
            $extra_section = '
                <table width="100%" style="background: #F0F7FF; border-radius: 8px; margin-bottom: 24px; border: 1px solid #60A5FA40;" cellpadding="12" cellspacing="0">
                    <tr><td style="font-size: 14px; font-weight: 700; color: #0A0A0A;">Session Prep</td></tr>
                    <tr><td style="font-size: 13px; color: #525252; line-height: 1.8; padding-top: 0;">';
            foreach ($prep_items as $pi) {
                $extra_section .= '&bull; ' . $pi . '<br>';
            }
            $extra_section .= '</td></tr></table>';
        }
        
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #F5F5F5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #F5F5F5; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="100%" style="max-width: 500px;" cellpadding="0" cellspacing="0">
                    <!-- Logo -->
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <img src="<?php echo esc_url($logo_url); ?>" alt="PTP" width="80" style="max-width: 80px;">
                        </td>
                    </tr>
                    
                    <!-- Card -->
                    <tr>
                        <td style="background: #FFFFFF; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                            <!-- Gold bar -->
                            <div style="height: 4px; background: #FCB900;"></div>
                            
                            <!-- Content -->
                            <div style="padding: 32px;">
                                <h1 style="margin: 0 0 8px; font-size: 24px; font-weight: 700; color: #0A0A0A; text-align: center;"><?php echo esc_html($heading); ?></h1>
                                <p style="margin: 0 0 24px; font-size: 15px; color: #525252; text-align: center;"><?php echo esc_html($message); ?></p>
                                
                                <!-- Details -->
                                <table width="100%" style="background: #F9FAFB; border-radius: 8px; margin-bottom: 24px;" cellpadding="12" cellspacing="0">
                                    <?php foreach ($details as $label => $value): if (!$value) continue; ?>
                                    <tr>
                                        <td style="font-size: 13px; color: #6B7280; font-weight: 600; width: 110px; border-bottom: 1px solid #E5E7EB; vertical-align: top; padding: 10px 12px;"><?php echo esc_html($label); ?></td>
                                        <td style="font-size: 14px; color: #0A0A0A; border-bottom: 1px solid #E5E7EB; padding: 10px 12px;"><?php echo esc_html($value); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                                
                                <?php echo $extra_section; ?>
                                
                                <!-- CTA -->
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td align="center">
                                            <a href="<?php echo esc_url($dashboard_url); ?>" style="display: inline-block; background: #FCB900; color: #0A0A0A; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 14px;"><?php echo esc_html($cta_text); ?></a>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 24px 20px; text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #9CA3AF;">PTP - Players Teaching Players</p>
                            <p style="margin: 8px 0 0; font-size: 12px; color: #9CA3AF;">Questions? Reply to this email or call (610) 761-5230</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    public static function booking_cancelled($booking_id, $cancelled_by) {
        $booking = PTP_Booking::get_full($booking_id);
        if (!$booking) return;
        
        $date_raw = $booking->session_date ?: '';
        if ((!$date_raw || $date_raw === '0000-00-00') && !empty($booking->session_datetime)) {
            $date_raw = date('Y-m-d', strtotime($booking->session_datetime));
        }
        $date = $date_raw ? date('l, F j', strtotime($date_raw)) : 'your upcoming session';
        
        $trainer_name = $booking->trainer_name ?: 'your coach';
        $player_name  = $booking->player_name ?: 'the player';
        
        // Determine who to notify
        if ($cancelled_by == $booking->trainer_user_id) {
            if (!empty($booking->parent_user_id)) {
                self::create(
                    $booking->parent_user_id,
                    'booking_cancelled',
                    'Booking Cancelled',
                    sprintf('Your session with %s on %s has been cancelled by the trainer.', 
                        $trainer_name, $date),
                    array('booking_id' => $booking_id)
                );
            }
        } else {
            if (!empty($booking->trainer_user_id)) {
                self::create(
                    $booking->trainer_user_id,
                    'booking_cancelled',
                    'Booking Cancelled',
                    sprintf('The session with %s on %s has been cancelled.', 
                        $player_name, $date),
                    array('booking_id' => $booking_id)
                );
            }
        }
    }
    
    public static function session_reminder($booking_id) {
        $booking = PTP_Booking::get_full($booking_id);
        if (!$booking) return;
        
        $time_raw = $booking->start_time ?: ($booking->session_time ?: '');
        if (!$time_raw && !empty($booking->session_datetime)) {
            $time_raw = date('H:i:s', strtotime($booking->session_datetime));
        }
        $time = $time_raw ? date('g:i A', strtotime($time_raw)) : 'scheduled time';
        
        $player_name  = $booking->player_name ?: 'your player';
        $trainer_name = $booking->trainer_name ?: 'your coach';
        
        // Remind trainer
        if (!empty($booking->trainer_user_id)) {
            self::create(
                $booking->trainer_user_id,
                'session_reminder',
                'Session Tomorrow',
                sprintf('Reminder: You have a session with %s tomorrow at %s', 
                    $player_name, $time),
                array('booking_id' => $booking_id)
            );
        }
        
        // Remind parent
        if (!empty($booking->parent_user_id)) {
            self::create(
                $booking->parent_user_id,
                'session_reminder',
                'Session Tomorrow',
                sprintf('Reminder: %s has a session with %s tomorrow at %s', 
                    $player_name, $trainer_name, $time),
                array('booking_id' => $booking_id)
            );
        }
    }
    
    public static function new_message($conversation_id, $sender_id) {
        $conversation = PTP_Messaging::get_conversation($conversation_id);
        if (!$conversation) return;
        
        $sender = get_userdata($sender_id);
        $sender_name = $sender ? $sender->display_name : 'Someone';
        
        $trainer = PTP_Trainer::get($conversation->trainer_id);
        $parent = PTP_Parent::get($conversation->parent_id);
        
        // Notify the other person
        if ($trainer && $trainer->user_id == $sender_id) {
            // Trainer sent, notify parent
            self::create(
                $parent->user_id,
                'new_message',
                'New Message',
                sprintf('You have a new message from %s', $trainer->display_name),
                array('conversation_id' => $conversation_id)
            );
        } elseif ($parent && $parent->user_id == $sender_id) {
            // Parent sent, notify trainer
            self::create(
                $trainer->user_id,
                'new_message',
                'New Message',
                sprintf('You have a new message from %s', $parent->display_name),
                array('conversation_id' => $conversation_id)
            );
        }
    }

    // ================================================================
    // MENTORSHIP NOTIFICATIONS
    // ================================================================

    private static function mentorship_email($to, $subject, $heading, $body_lines) {
        if (!is_email($to)) return;
        $logo_url = 'https://ptpsummercamps.com/wp-content/uploads/ptp-logo.png';
        $body  = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#fff;border:1px solid #eee;border-radius:12px;overflow:hidden">';
        $body .= '<div style="background:#0A0A0A;padding:20px 24px;text-align:center">';
        $body .= '<img src="' . esc_url($logo_url) . '" alt="PTP" height="36" style="max-height:36px">';
        $body .= '</div>';
        $body .= '<div style="padding:28px 28px 10px">';
        $body .= '<h2 style="margin:0 0 16px;font-size:20px;color:#0A0A0A">' . esc_html($heading) . '</h2>';
        foreach ($body_lines as $line) {
            $body .= '<p style="font-size:14px;color:#374151;line-height:1.6;margin:0 0 12px">' . $line . '</p>';
        }
        $body .= '</div>';
        $body .= '<div style="padding:16px 28px 28px;border-top:1px solid #f3f4f6;font-size:12px;color:#9ca3af">PTP Soccer &mdash; Players Teaching Players</div>';
        $body .= '</div>';

        add_filter('wp_mail_content_type', function() { return 'text/html'; });
        wp_mail($to, $subject, $body);
        remove_filter('wp_mail_content_type', function() { return 'text/html'; });
    }

    /**
     * Fires on: ptp_mentorship_interest_submitted
     * Param: $pair_id
     */
    public static function mentorship_interest_submitted($pair_id) {
        global $wpdb;
        $pair    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;
        $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $pair->trainer_id));
        if (!$trainer) return;
        $player  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_players WHERE id=%d", $pair->player_id));

        $trainer_user = get_userdata($trainer->user_id);
        if (!$trainer_user) return;

        $player_name = '';
        if ($player && !empty($player->name)) $player_name = $player->name;
        elseif ($player && !empty($player->first_name)) $player_name = $player->first_name;
        elseif (!empty($pair->player_name)) $player_name = $pair->player_name;
        else $player_name = 'a new player';
        $parent_email = $pair->parent_email ?: '';

        // Notify trainer
        self::create($trainer->user_id, 'mentorship_interest', 'New Mentorship Interest',
            sprintf('%s is interested in mentorship with you.', $player_name),
            array('pair_id' => $pair_id));

        self::mentorship_email(
            $trainer_user->user_email,
            'New Mentorship Interest — ' . $player_name,
            'New Mentorship Interest',
            array(
                '<strong>' . esc_html($player_name) . '</strong> has expressed interest in mentorship with you.',
                'Goals: ' . esc_html($pair->player_goals ?: 'Not specified'),
                'Log in to your dashboard to review and schedule an intro call.',
                '<a href="' . esc_url(home_url('/trainer-dashboard/')) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:8px;font-weight:700;text-decoration:none">View in Dashboard</a>',
            )
        );

        // Confirm to parent
        if (is_email($parent_email)) {
            self::mentorship_email(
                $parent_email,
                'Mentorship Interest Received — PTP',
                'Interest Received',
                array(
                    "We received your interest in mentorship for " . esc_html($player_name) . " with " . esc_html($trainer->display_name) . ".",
                    "Your coach will reach out to schedule a free intro call within 48 hours.",
                    "The intro call is 15 minutes, free, and a chance for " . esc_html($player_name) . " to meet their potential mentor.",
                )
            );
        }
    }

    /**
     * Fires on: ptp_mentorship_intro_completed
     * Param: $pair_id
     */
    public static function mentorship_intro_completed($pair_id) {
        global $wpdb;
        $pair    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;
        $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $pair->trainer_id));
        if (!$trainer) return;

        $parent_email = $pair->parent_email ?: '';
        $parent_user  = get_userdata($pair->parent_id);
        $email        = ($parent_user && is_email($parent_user->user_email)) ? $parent_user->user_email : $parent_email;

        if (!is_email($email)) return;

        $player  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_players WHERE id=%d", $pair->player_id));
        $player_name = $player ? $player->name : 'your player';

        self::mentorship_email(
            $email,
            'Your Intro Call is Complete — Next Steps',
            'Intro Call Complete',
            array(
                esc_html($trainer->display_name) . ' has confirmed your intro call with ' . esc_html($player_name) . ' is complete.',
                'Ready to start? Choose a mentorship package below and your first weekly session will be scheduled automatically.',
                '<a href="' . esc_url(add_query_arg(array('pair_id' => $pair_id), home_url('/mentorship-checkout/'))) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:8px;font-weight:700;text-decoration:none;font-family:Arial,sans-serif;letter-spacing:1px">CHOOSE YOUR PACKAGE &rarr;</a>',
                'Questions? Reply to this email or message your coach directly in the dashboard.',
            )
        );
    }

    /**
     * Fires on: ptp_mentorship_package_purchased
     * Param: $pair_id
     */
    public static function mentorship_package_purchased($pair_id) {
        global $wpdb;
        $pair    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;
        $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $pair->trainer_id));
        if (!$trainer) return;

        $player  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_players WHERE id=%d", $pair->player_id));
        $player_name = $player ? $player->name : 'your player';

        // Notify trainer
        self::create($trainer->user_id, 'mentorship_purchase', 'New Mentorship Active',
            sprintf('%s has purchased the %s package.', $player_name, ucfirst($pair->package_type)),
            array('pair_id' => $pair_id));

        $trainer_user = get_userdata($trainer->user_id);
        if ($trainer_user) {
            self::mentorship_email(
                $trainer_user->user_email,
                $player_name . ' is now an active mentee',
                'New Mentee Active',
                array(
                    esc_html($player_name) . ' has purchased the <strong>' . esc_html(ucfirst($pair->package_type)) . '</strong> package.',
                    'Sessions included: ' . $pair->sessions_total . '. Video reviews: ' . $pair->video_reviews_remaining . '.',
                    'Log in to schedule their first session.',
                    '<a href="' . esc_url(home_url('/trainer-dashboard/')) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:8px;font-weight:700;text-decoration:none">Open Dashboard</a>',
                )
            );
        }
    }

    /**
     * Fires on: ptp_mentorship_video_submitted
     * Param: $video_id
     */
    public static function mentorship_video_submitted($video_id) {
        global $wpdb;
        $video   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_videos WHERE id=%d", $video_id));
        if (!$video) return;
        $pair    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $video->pair_id));
        if (!$pair) return;
        $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $pair->trainer_id));
        if (!$trainer) return;
        $player  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_players WHERE id=%d", $pair->player_id));
        $player_name = $player ? $player->name : 'your mentee';

        self::create($trainer->user_id, 'mentorship_video', 'New Video Submission',
            sprintf('%s submitted a video for review.', $player_name),
            array('video_id' => $video_id, 'pair_id' => $video->pair_id));

        $trainer_user = get_userdata($trainer->user_id);
        if (!$trainer_user) return;

        self::mentorship_email(
            $trainer_user->user_email,
            $player_name . ' submitted a video — 48hr review window',
            'Video Submitted for Review',
            array(
                esc_html($player_name) . ' just submitted a video for your feedback.',
                $video->player_note ? 'Their note: <em>' . esc_html($video->player_note) . '</em>' : '',
                'Please review within 48 hours. Log in to your dashboard to watch and respond.',
                '<a href="' . esc_url(home_url('/trainer-dashboard/')) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:8px;font-weight:700;text-decoration:none">Review Now</a>',
            )
        );
    }

    /**
     * Fires on: ptp_mentorship_video_reviewed
     * Param: $video_id
     */
    public static function mentorship_video_reviewed($video_id) {
        global $wpdb;
        $video  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_videos WHERE id=%d", $video_id));
        if (!$video) return;
        $pair   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $video->pair_id));
        if (!$pair) return;

        $parent_user  = get_userdata($pair->parent_id);
        $parent_email = ($parent_user && is_email($parent_user->user_email)) ? $parent_user->user_email : $pair->parent_email;
        if (!is_email($parent_email)) return;

        $trainer  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $pair->trainer_id));
        $player   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_players WHERE id=%d", $pair->player_id));
        $player_name  = $player ? $player->name : 'your player';
        $trainer_name = $trainer ? $trainer->display_name : 'Your coach';

        if ($pair->parent_id) {
            self::create($pair->parent_id, 'mentorship_feedback', 'Video Feedback Ready',
                sprintf('%s reviewed %s\'s video.', $trainer_name, $player_name),
                array('video_id' => $video_id));
        }

        self::mentorship_email(
            $parent_email,
            $trainer_name . ' reviewed ' . $player_name . '\'s video',
            'Video Feedback Is Ready',
            array(
                esc_html($trainer_name) . ' has reviewed ' . esc_html($player_name) . '\'s video submission.',
                $video->coach_feedback ? 'Feedback: <em>' . esc_html(substr($video->coach_feedback, 0, 200)) . (strlen($video->coach_feedback) > 200 ? '...' : '') . '</em>' : '',
                'Log in to see the full feedback and any video response.',
                '<a href="' . esc_url(home_url('/trainer-dashboard/')) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:8px;font-weight:700;text-decoration:none">View Feedback</a>',
            )
        );
    }

    /**
     * Fires on: ptp_mentorship_session_scheduled
     * Param: $session_id
     */
    public static function mentorship_session_scheduled($session_id) {
        global $wpdb;
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id=%d", $session_id));
        if (!$session) return;
        $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $session->trainer_id));
        if (!$trainer) return;

        $attendees = $wpdb->get_results($wpdb->prepare(
            "SELECT sa.pair_id, mp.parent_id, mp.parent_email FROM {$wpdb->prefix}ptp_mentorship_session_attendees sa
             JOIN {$wpdb->prefix}ptp_mentorship_pairs mp ON sa.pair_id = mp.id
             WHERE sa.session_id = %d", $session_id
        ));

        $dt         = new DateTime($session->scheduled_at, new DateTimeZone('America/New_York'));
        $date_str   = $dt->format('l, F j');
        $time_str   = $dt->format('g:i A') . ' ET';
        $join_url   = $session->meeting_url ?: '';
        $type_label = ucfirst(str_replace('_', ' ', $session->session_type));

        foreach ($attendees as $a) {
            $parent_user  = get_userdata($a->parent_id);
            $parent_email = ($parent_user && is_email($parent_user->user_email)) ? $parent_user->user_email : $a->parent_email;
            if (!is_email($parent_email)) continue;

            if ($a->parent_id) {
                self::create($a->parent_id, 'session_scheduled', 'Session Scheduled',
                    sprintf('%s session with %s on %s at %s', $type_label, $trainer->display_name, $date_str, $time_str),
                    array('session_id' => $session_id));
            }

            self::mentorship_email(
                $parent_email,
                $type_label . ' Scheduled — ' . $date_str,
                $type_label . ' Confirmed',
                array(
                    esc_html($trainer->display_name) . ' has scheduled a <strong>' . esc_html($type_label) . '</strong>.',
                    'Date: <strong>' . esc_html($date_str) . '</strong> at <strong>' . esc_html($time_str) . '</strong>',
                    $join_url ? 'Join link: <a href="' . esc_url($join_url) . '">' . esc_url($join_url) . '</a>' : '',
                    'Add it to your calendar so your player is ready on time.',
                )
            );

            // Schedule 24hr reminder for pre-session note
            $remind_time = $dt->getTimestamp() - 86400;
            if ($remind_time > time()) {
                wp_schedule_single_event($remind_time, 'ptp_mentorship_pre_session_reminder', array($session_id, $a->pair_id, $parent_email));
            }
        }
    }

    // ================================================================
    // SESSION RECAP → PARENT  (fires after trainer submits post-session form)
    // ================================================================
    public static function mentorship_session_recap_ready($session_id, $pair_id) {
        global $wpdb;

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_sessions WHERE id = %d", $session_id
        ));
        if (!$session || empty($session->parent_summary)) return;

        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id = %d", $pair_id
        ));
        if (!$pair || empty($pair->parent_email)) return;

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $pair->trainer_id
        ));
        $trainer_name = $trainer ? $trainer->display_name : 'Your Mentor';
        $player_name  = $pair->player_name ?: 'Your Player';
        $session_num  = $session->session_number ?: ($pair->sessions_completed ?: 1);
        $total        = $pair->sessions_total ?: 24;

        $energy_stars = $session->energy_rating ? str_repeat('&#9733;', intval($session->energy_rating)) . str_repeat('&#9734;', 5 - intval($session->energy_rating)) : '';
        $remaining    = max(0, $total - $pair->sessions_completed);

        $lines = array(
            "<strong>Session #{$session_num} of {$total} complete.</strong>",
        );
        if ($energy_stars) {
            $lines[] = "<strong>Energy:</strong> {$energy_stars}";
        }
        $lines[] = '<strong>What happened this session:</strong>';
        $lines[] = esc_html($session->parent_summary);
        if ($session->action_item) {
            $lines[] = "<strong>This week's mission for {$player_name}:</strong>";
            $lines[] = '<em>' . esc_html($session->action_item) . '</em>';
        }
        if ($remaining > 0) {
            $lines[] = "{$remaining} sessions remaining in this package.";
        }
        $lines[] = '<a href="' . home_url('/dashboard/#mentorship') . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:8px;font-weight:700;text-decoration:none">View Dashboard &rarr;</a>';

        self::mentorship_email(
            $pair->parent_email,
            "Session #{$session_num} recap - {$player_name} & {$trainer_name}",
            "Session #{$session_num} Recap",
            $lines
        );

        if ($pair->parent_id) {
            self::create($pair->parent_id, 'session_recap',
                "Session #{$session_num} recap from {$trainer_name}",
                $session->parent_summary,
                array('pair_id' => $pair_id, 'session_id' => $session_id)
            );
        }
    }

    /**
     * Fires on: ptp_mentorship_cancelled
     * Param: $pair_id
     */
    public static function mentorship_cancelled($pair_id) {
        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;
        $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $pair->trainer_id));
        if (!$trainer) return;

        $player_name  = $pair->player_name ?: 'a player';
        $trainer_name = $trainer->display_name;
        $completed    = intval($pair->sessions_completed);
        $total        = intval($pair->sessions_total);
        $reason       = $pair->cancel_reason ?: 'No reason provided';

        // Notify trainer
        $trainer_user = get_userdata($trainer->user_id);
        if ($trainer_user && is_email($trainer_user->user_email)) {
            self::mentorship_email(
                $trainer_user->user_email,
                'Mentorship Cancelled - ' . $player_name,
                'Mentorship Cancelled',
                array(
                    '<strong>' . esc_html($player_name) . '</strong> has cancelled their mentorship.',
                    "Sessions completed: {$completed} of {$total}.",
                    '<strong>Reason:</strong> ' . esc_html($reason),
                    'Billing will stop at the end of the current billing period.',
                )
            );
        }

        // Confirm to parent
        $email = $pair->parent_email ?: '';
        $parent_user = $pair->parent_id ? get_userdata($pair->parent_id) : null;
        if ($parent_user && is_email($parent_user->user_email)) $email = $parent_user->user_email;

        if (is_email($email)) {
            self::mentorship_email(
                $email,
                'Mentorship Cancellation Confirmed - PTP',
                'Cancellation Confirmed',
                array(
                    "Your mentorship with {$trainer_name} for " . esc_html($player_name) . " has been cancelled.",
                    "You completed {$completed} of {$total} sessions. Billing will stop at the end of the current week.",
                    "We'd love to have you back anytime. You can restart mentorship from the dashboard.",
                    '<a href="' . home_url('/mentorship/') . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:8px;font-weight:700;text-decoration:none">Explore Mentorship</a>',
                )
            );
        }
    }

    /**
     * Fires on: ptp_mentorship_package_completed
     * Param: $pair_id
     */
    public static function mentorship_package_completed($pair_id) {
        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;
        $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $pair->trainer_id));
        if (!$trainer) return;

        $player_name  = $pair->player_name ?: 'your player';
        $trainer_name = $trainer->display_name;
        $total        = intval($pair->sessions_total);

        // Notify parent — renewal CTA
        $email = $pair->parent_email ?: '';
        $parent_user = $pair->parent_id ? get_userdata($pair->parent_id) : null;
        if ($parent_user && is_email($parent_user->user_email)) $email = $parent_user->user_email;

        if (is_email($email)) {
            self::mentorship_email(
                $email,
                esc_html($player_name) . ' completed all ' . $total . ' sessions!',
                'Package Complete!',
                array(
                    'Congratulations! <strong>' . esc_html($player_name) . '</strong> has completed all ' . $total . ' sessions with ' . esc_html($trainer_name) . '.',
                    esc_html($trainer_name) . ' knows ' . esc_html($player_name) . ' better than any coach out there now. Keep the momentum going with a new package.',
                    '<a href="' . esc_url(add_query_arg(array('pair_id' => $pair_id, 'renew' => 1), home_url('/mentorship-checkout/'))) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:12px 24px;border-radius:8px;font-weight:700;text-decoration:none;font-size:15px">CONTINUE WITH ' . esc_html(strtoupper($trainer_name)) . ' &rarr;</a>',
                    'Or reply to this email with any questions.',
                )
            );
        }

        // Notify trainer
        $trainer_user = get_userdata($trainer->user_id);
        if ($trainer_user && is_email($trainer_user->user_email)) {
            self::mentorship_email(
                $trainer_user->user_email,
                'Package Complete - ' . ($pair->player_name ?: 'Mentee'),
                'Package Complete',
                array(
                    '<strong>' . esc_html($pair->player_name ?: 'Your mentee') . '</strong> has completed all ' . $total . ' sessions.',
                    'We\'ve sent them a renewal email. If you want them to continue, reach out personally — a quick text goes a long way.',
                    '<a href="' . home_url('/dashboard/#mentorship') . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:8px;font-weight:700;text-decoration:none">View Dashboard</a>',
                )
            );
        }
    }

    /**
     * Fires on: ptp_mentorship_package_expiring
     * Param: $pair (full row object from cron)
     */
    public static function mentorship_package_expiring($pair) {
        if (!$pair || empty($pair->parent_email)) return;

        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id=%d", $pair->trainer_id));
        if (!$trainer) return;

        $player_name  = $pair->player_name ?: 'your player';
        $trainer_name = $pair->trainer_name ?? ($trainer->display_name ?? 'your coach');
        $remaining    = max(0, intval($pair->sessions_total) - intval($pair->sessions_completed));

        $email = $pair->parent_email;
        $parent_user = $pair->parent_id ? get_userdata($pair->parent_id) : null;
        if ($parent_user && is_email($parent_user->user_email)) $email = $parent_user->user_email;

        if (is_email($email)) {
            self::mentorship_email(
                $email,
                "Only {$remaining} sessions left - " . esc_html($player_name),
                "{$remaining} Sessions Remaining",
                array(
                    esc_html($player_name) . ' has <strong>' . $remaining . ' session' . ($remaining !== 1 ? 's' : '') . ' left</strong> with ' . esc_html($trainer_name) . '.',
                    'When your current package ends, you can renew to keep the momentum going. Your coach already knows ' . esc_html($player_name) . '\'s strengths, goals, and growth areas — starting fresh with someone new means losing all that context.',
                    '<a href="' . esc_url(add_query_arg(array('pair_id' => $pair->id, 'renew' => 1), home_url('/mentorship-checkout/'))) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:12px 24px;border-radius:8px;font-weight:700;text-decoration:none">RENEW PACKAGE &rarr;</a>',
                )
            );
        }

        // Also nudge trainer
        $trainer_user = get_userdata($trainer->user_id);
        if ($trainer_user && is_email($trainer_user->user_email)) {
            self::mentorship_email(
                $trainer_user->user_email,
                esc_html($pair->player_name ?: 'Mentee') . ' - ' . $remaining . ' sessions left',
                'Package Almost Done',
                array(
                    '<strong>' . esc_html($pair->player_name ?: 'Your mentee') . '</strong> has ' . $remaining . ' session' . ($remaining !== 1 ? 's' : '') . ' remaining.',
                    'Now is a great time to talk about continuing. Mention it during your next session — a personal ask converts better than any email.',
                )
            );
        }
    }

    /**
     * Fires on: ptp_mentorship_payment_failed
     * Param: $pair_id
     */
    public static function mentorship_payment_failed($pair_id) {
        global $wpdb;
        $pair = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE id=%d", $pair_id));
        if (!$pair) return;

        $email = $pair->parent_email ?: '';
        $parent_user = $pair->parent_id ? get_userdata($pair->parent_id) : null;
        if ($parent_user && is_email($parent_user->user_email)) $email = $parent_user->user_email;

        if (is_email($email)) {
            self::mentorship_email(
                $email,
                'Payment Issue - Mentorship',
                'Payment Failed',
                array(
                    'We had trouble processing your weekly mentorship payment.',
                    'Please update your payment method to avoid any interruption to ' . esc_html($pair->player_name ?: 'your player') . '\'s sessions.',
                    '<a href="' . home_url('/dashboard/#mentorship') . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:8px;font-weight:700;text-decoration:none">Update Payment &rarr;</a>',
                    'If you have any questions, reply to this email.',
                )
            );
        }
    }
}
