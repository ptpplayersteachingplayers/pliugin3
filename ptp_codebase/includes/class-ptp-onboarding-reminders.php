<?php
/**
 * PTP Trainer Onboarding Reminders v133
 * 
 * Automated reminder system to ensure trainers complete their profile
 * after approval. Sends email + SMS reminders at strategic intervals.
 * 
 * Reminder Sequence:
 * - 24 hours after approval (gentle nudge)
 * - 3 days after approval (progress check)
 * - 7 days after approval (offer help)
 * - 14 days after approval (final warning)
 * 
 * @since 133.0.0
 */

defined('ABSPATH') || exit;

class PTP_Onboarding_Reminders {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        // Cron job for checking reminders
        add_action('ptp_check_onboarding_reminders', array(__CLASS__, 'check_and_send_reminders'));
        
        // Schedule the cron if not already scheduled
        add_action('init', array(__CLASS__, 'schedule_cron'));
        
        // Track when trainer is approved
        add_action('ptp_trainer_approved', array(__CLASS__, 'on_trainer_approved'), 10, 1);
        
        // Track onboarding progress
        add_action('ptp_trainer_onboarding_saved', array(__CLASS__, 'check_onboarding_completion'), 10, 1);
        
        // Admin columns
        add_filter('ptp_admin_trainer_columns', array(__CLASS__, 'add_admin_columns'));
        
        // Admin manual send
        add_action('wp_ajax_ptp_send_onboarding_reminder', array(__CLASS__, 'ajax_send_reminder'));
    }
    
    /**
     * Schedule daily cron check
     */
    public static function schedule_cron() {
        if (!wp_next_scheduled('ptp_check_onboarding_reminders')) {
            // Run twice daily at 9am and 5pm
            wp_schedule_event(strtotime('09:00:00'), 'twicedaily', 'ptp_check_onboarding_reminders');
        }
    }
    
    /**
     * Clear cron on deactivation
     */
    public static function clear_cron() {
        wp_clear_scheduled_hook('ptp_check_onboarding_reminders');
    }
    
    /**
     * When a trainer is approved, record the timestamp
     */
    public static function on_trainer_approved($trainer_id) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'approved_at' => current_time('mysql'),
                'onboarding_reminder_count' => 0,
                'last_onboarding_reminder_at' => null,
            ),
            array('id' => $trainer_id),
            array('%s', '%d', '%s'),
            array('%d')
        );
        
        ptp_log("[PTP Onboarding] Trainer #{$trainer_id} approved at " . current_time('mysql'));
    }
    
    /**
     * Check if trainer has completed onboarding
     */
    public static function check_onboarding_completion($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return;
        
        $completion = self::get_completion_status($trainer);
        
        // If 100% complete, mark as completed
        if ($completion['percentage'] >= 100) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                array('onboarding_completed_at' => current_time('mysql')),
                array('id' => $trainer_id),
                array('%s'),
                array('%d')
            );
            
            // v191: Also set user meta for quick checks elsewhere
            if (!empty($trainer->user_id)) {
                update_user_meta($trainer->user_id, 'ptp_onboarding_completed', current_time('mysql'));
            }
            
            ptp_log("[PTP Onboarding] Trainer #{$trainer_id} completed onboarding!");
            
            // Fire profile complete hook for SMS notification
            do_action('ptp_trainer_profile_complete', $trainer_id);
            
            // Send congratulations email
            self::send_completion_email($trainer);
        }
    }
    
    /**
     * Get detailed completion status for a trainer
     */
    public static function get_completion_status($trainer) {
        // Check Stripe status
        $stripe_complete = false;
        if (!empty($trainer->stripe_account_id) && class_exists('PTP_Stripe')) {
            $stripe_complete = PTP_Stripe::is_account_complete($trainer->stripe_account_id);
        }
        
        // Get availability
        $has_availability = false;
        if (class_exists('PTP_Availability') && method_exists('PTP_Availability', 'get_weekly')) {
            $availability = PTP_Availability::get_weekly($trainer->id);
            $has_availability = !empty($availability);
        }
        
        // Training locations
        $has_locations = false;
        if (!empty($trainer->training_locations)) {
            $locations = json_decode($trainer->training_locations, true);
            $has_locations = is_array($locations) && count($locations) > 0;
        }
        
        $steps = array(
            'photo' => array(
                'complete' => !empty($trainer->photo_url),
                'label' => 'Profile Photo',
                'priority' => 1,
            ),
            'bio' => array(
                'complete' => !empty($trainer->bio) && strlen($trainer->bio) >= 50,
                'label' => 'Bio',
                'priority' => 2,
            ),
            'experience' => array(
                'complete' => !empty($trainer->playing_level) || !empty($trainer->playing_experience),
                'label' => 'Playing Experience',
                'priority' => 3,
            ),
            'rate' => array(
                'complete' => !empty($trainer->hourly_rate) && $trainer->hourly_rate > 0,
                'label' => 'Hourly Rate',
                'priority' => 4,
            ),
            'location' => array(
                'complete' => !empty($trainer->city) && !empty($trainer->state),
                'label' => 'Service Area',
                'priority' => 5,
            ),
            'training_locations' => array(
                'complete' => $has_locations,
                'label' => 'Training Locations',
                'priority' => 6,
            ),
            'availability' => array(
                'complete' => $has_availability,
                'label' => 'Weekly Availability',
                'priority' => 7,
            ),
            'contract' => array(
                'complete' => !empty($trainer->contractor_agreement_signed),
                'label' => 'Trainer Agreement',
                'priority' => 8,
            ),
            'stripe' => array(
                'complete' => $stripe_complete,
                'label' => 'Payment Setup (Stripe)',
                'priority' => 9,
            ),
        );
        
        $completed = array_filter($steps, function($s) { return $s['complete']; });
        $incomplete = array_filter($steps, function($s) { return !$s['complete']; });
        
        // Sort incomplete by priority
        uasort($incomplete, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });
        
        return array(
            'steps' => $steps,
            'completed' => $completed,
            'incomplete' => $incomplete,
            'percentage' => count($steps) > 0 ? round((count($completed) / count($steps)) * 100) : 0,
            'completed_count' => count($completed),
            'total_count' => count($steps),
        );
    }
    
    /**
     * Check all approved trainers and send reminders as needed
     */
    public static function check_and_send_reminders() {
        global $wpdb;
        
        // Reminder schedule (hours after approval)
        $reminder_schedule = array(
            1 => 24,      // First reminder: 24 hours
            2 => 72,      // Second reminder: 3 days
            3 => 168,     // Third reminder: 7 days
            4 => 336,     // Final reminder: 14 days
        );
        
        $max_reminders = count($reminder_schedule);
        
        // Get approved trainers who haven't completed onboarding
        $trainers = $wpdb->get_results("
            SELECT t.* 
            FROM {$wpdb->prefix}ptp_trainers t
            WHERE t.status = 'active'
            AND (t.onboarding_completed_at IS NULL OR t.onboarding_completed_at = '0000-00-00 00:00:00')
            AND t.approved_at IS NOT NULL
            AND t.approved_at != '0000-00-00 00:00:00'
            AND (t.onboarding_reminder_count IS NULL OR t.onboarding_reminder_count < {$max_reminders})
        ");
        
        if (empty($trainers)) {
            ptp_log('[PTP Onboarding] No trainers need reminders');
            return;
        }
        
        $now = current_time('timestamp');
        $reminders_sent = 0;
        
        foreach ($trainers as $trainer) {
            $approved_at = strtotime($trainer->approved_at);
            $hours_since_approval = ($now - $approved_at) / 3600;
            
            $current_count = intval($trainer->onboarding_reminder_count ?? 0);
            $next_reminder_num = $current_count + 1;
            
            if ($next_reminder_num > $max_reminders) {
                continue; // Already sent all reminders
            }
            
            $hours_threshold = $reminder_schedule[$next_reminder_num];
            
            // Check if enough time has passed
            if ($hours_since_approval < $hours_threshold) {
                continue;
            }
            
            // Check if we already sent a reminder recently (within 12 hours)
            if (!empty($trainer->last_onboarding_reminder_at)) {
                $last_reminder = strtotime($trainer->last_onboarding_reminder_at);
                $hours_since_last = ($now - $last_reminder) / 3600;
                if ($hours_since_last < 12) {
                    continue;
                }
            }
            
            // Check completion status
            $completion = self::get_completion_status($trainer);
            
            // If they're actually complete, mark them and skip
            if ($completion['percentage'] >= 100) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    array('onboarding_completed_at' => current_time('mysql')),
                    array('id' => $trainer->id)
                );
                continue;
            }
            
            // Send the reminder
            $sent = self::send_reminder($trainer, $next_reminder_num, $completion);
            
            if ($sent) {
                // Update trainer record
                $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    array(
                        'onboarding_reminder_count' => $next_reminder_num,
                        'last_onboarding_reminder_at' => current_time('mysql'),
                    ),
                    array('id' => $trainer->id)
                );
                
                $reminders_sent++;
                ptp_log("[PTP Onboarding] Sent reminder #{$next_reminder_num} to trainer #{$trainer->id} ({$trainer->email})");
            }
        }
        
        ptp_log("[PTP Onboarding] Cron complete. Sent {$reminders_sent} reminders.");
    }
    
    /**
     * Send a reminder to a trainer
     */
    public static function send_reminder($trainer, $reminder_number, $completion) {
        $first_name = explode(' ', $trainer->display_name)[0];
        $onboarding_url = home_url('/trainer-onboarding/');
        
        // Build incomplete items list
        $incomplete_items = array();
        foreach ($completion['incomplete'] as $key => $step) {
            $incomplete_items[] = $step['label'];
        }
        
        // Get email content based on reminder number
        $email_content = self::get_reminder_email_content($reminder_number, $first_name, $completion, $onboarding_url);
        
        // Send email
        $email_sent = false;
        if (!empty($trainer->email) && class_exists('PTP_Email')) {
            $subject = self::get_reminder_subject($reminder_number, $first_name, $completion);
            $email_sent = PTP_Email::send_custom_email($trainer->email, $subject, $email_content);
        }
        
        // Send SMS
        $sms_sent = false;
        if (!empty($trainer->phone) && class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            $sms_message = self::get_reminder_sms($reminder_number, $first_name, $completion, $onboarding_url);
            $sms_sent = PTP_SMS::send($trainer->phone, $sms_message);
        }
        
        return $email_sent || $sms_sent;
    }
    
    /**
     * Get email subject based on reminder number
     */
    private static function get_reminder_subject($reminder_number, $first_name, $completion) {
        $percentage = $completion['percentage'];
        
        switch ($reminder_number) {
            case 1:
                return "Complete your PTP profile to start earning 💰";
            case 2:
                return "{$first_name}, you're {$percentage}% done - finish your profile!";
            case 3:
                return "Need help completing your PTP profile?";
            case 4:
                return "⚠️ Last chance: Complete your PTP profile";
            default:
                return "Complete your PTP trainer profile";
        }
    }
    
    /**
     * Get SMS message based on reminder number
     */
    private static function get_reminder_sms($reminder_number, $first_name, $completion, $url) {
        $percentage = $completion['percentage'];
        $remaining = count($completion['incomplete']);
        
        // Get first incomplete item
        $next_step = '';
        if (!empty($completion['incomplete'])) {
            $first_incomplete = reset($completion['incomplete']);
            $next_step = $first_incomplete['label'];
        }
        
        switch ($reminder_number) {
            case 1:
                return "Hey {$first_name}! 🎉 Welcome to PTP! Complete your profile to start getting booked: {$url}";
            case 2:
                return "Hi {$first_name}, you're {$percentage}% done with your PTP profile! Just {$remaining} steps left. Next up: {$next_step}. {$url}";
            case 3:
                return "{$first_name}, need help? Reply to this text and we'll walk you through completing your profile. Or finish here: {$url}";
            case 4:
                return "⚠️ {$first_name}, your PTP profile is incomplete. Complete it today to start earning: {$url}";
            default:
                return "Complete your PTP profile: {$url}";
        }
    }
    
    /**
     * Get email content based on reminder number
     */
    private static function get_reminder_email_content($reminder_number, $first_name, $completion, $url) {
        $percentage = $completion['percentage'];
        $remaining = count($completion['incomplete']);
        $completed_count = $completion['completed_count'];
        $total_count = $completion['total_count'];
        
        // Build checklist HTML
        $checklist_html = '<table style="width:100%;margin:24px 0;" cellpadding="0" cellspacing="0">';
        foreach ($completion['steps'] as $key => $step) {
            $icon = $step['complete'] ? '✅' : '⬜';
            $style = $step['complete'] ? 'color:#22C55E;' : 'color:#6B7280;';
            $checklist_html .= '<tr><td style="padding:8px 0;font-size:14px;' . $style . '">' . $icon . ' ' . esc_html($step['label']) . '</td></tr>';
        }
        $checklist_html .= '</table>';
        
        // Different content based on reminder number
        switch ($reminder_number) {
            case 1:
                $headline = "Let's Get You Set Up! 🚀";
                $body = "
                    <p>Congrats again on being approved as a PTP trainer! You're just a few steps away from receiving your first booking.</p>
                    <p>Here's your progress so far:</p>
                    {$checklist_html}
                    <p style='font-size:14px;color:#6B7280;'>Complete your profile in the next 48 hours and we'll feature you to parents in your area!</p>
                ";
                break;
                
            case 2:
                $headline = "You're {$percentage}% There!";
                $body = "
                    <p>Hi {$first_name}, you're making progress! Just {$remaining} more steps to go.</p>
                    {$checklist_html}
                    <p>Parents are actively looking for trainers in your area. Don't miss out on bookings!</p>
                ";
                break;
                
            case 3:
                $headline = "Need a Hand? 🤝";
                $body = "
                    <p>Hey {$first_name}, we noticed you haven't finished setting up your profile yet.</p>
                    <p><strong>Stuck on something?</strong> Just reply to this email and our team will help you out. Common questions:</p>
                    <ul style='margin:16px 0;padding-left:20px;color:#4B5563;'>
                        <li>\"How do I connect Stripe?\" - We'll walk you through it</li>
                        <li>\"What should I write in my bio?\" - We have templates!</li>
                        <li>\"I'm having technical issues\" - We'll fix it</li>
                    </ul>
                    {$checklist_html}
                ";
                break;
                
            case 4:
                $headline = "⚠️ Final Reminder";
                $body = "
                    <p>{$first_name}, this is our last reminder about your incomplete profile.</p>
                    <p>Without a complete profile, you won't appear in parent searches and can't receive bookings.</p>
                    {$checklist_html}
                    <p style='font-size:14px;color:#EF4444;'><strong>Please complete your profile today to avoid losing your spot.</strong></p>
                ";
                break;
                
            default:
                $headline = "Complete Your Profile";
                $body = $checklist_html;
        }
        
        // Build full email
        $email_html = '
        <table style="width:100%;max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;" cellpadding="0" cellspacing="0">
            <tr>
                <td style="background:#0A0A0A;padding:24px;text-align:center;border-radius:12px 12px 0 0;">
                    <img src="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png" alt="PTP" style="height:40px;">
                </td>
            </tr>
            <tr>
                <td style="background:#FFFFFF;padding:32px;">
                    <h1 style="font-family:\'Oswald\',sans-serif;font-size:24px;color:#0A0A0A;margin:0 0 16px;text-transform:uppercase;">' . $headline . '</h1>
                    ' . $body . '
                    <div style="text-align:center;margin-top:32px;">
                        <a href="' . esc_url($url) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;font-family:\'Oswald\',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;text-decoration:none;padding:16px 40px;border-radius:8px;">
                            Complete Profile →
                        </a>
                    </div>
                    <p style="font-size:12px;color:#9CA3AF;margin-top:32px;text-align:center;">
                        Questions? Reply to this email or reach us at support@ptpsummercamps.com
                    </p>
                </td>
            </tr>
            <tr>
                <td style="background:#F5F5F5;padding:16px;text-align:center;font-size:12px;color:#6B7280;border-radius:0 0 12px 12px;">
                    PTP - Players Teaching Players<br>
                    <a href="' . home_url() . '" style="color:#6B7280;">ptpsummercamps.com</a>
                </td>
            </tr>
        </table>';
        
        return $email_html;
    }
    
    /**
     * Send congratulations email when onboarding is complete
     */
    public static function send_completion_email($trainer) {
        $first_name = explode(' ', $trainer->display_name)[0];
        $dashboard_url = home_url('/trainer-dashboard/');
        
        $subject = "🎉 You're all set, {$first_name}! Time to start training";
        
        $content = '
        <table style="width:100%;max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;" cellpadding="0" cellspacing="0">
            <tr>
                <td style="background:linear-gradient(135deg,#22C55E 0%,#16A34A 100%);padding:32px;text-align:center;border-radius:12px 12px 0 0;">
                    <div style="font-size:48px;margin-bottom:12px;">🎉</div>
                    <h1 style="font-family:\'Oswald\',sans-serif;font-size:28px;color:#FFFFFF;margin:0;text-transform:uppercase;">Profile Complete!</h1>
                    <p style="color:rgba(255,255,255,0.9);margin:8px 0 0;font-size:15px;">You\'re ready to start receiving bookings</p>
                </td>
            </tr>
            <tr>
                <td style="background:#FFFFFF;padding:32px;">
                    <p style="font-size:15px;color:#4B5563;line-height:1.7;">
                        Awesome work, ' . esc_html($first_name) . '! Your profile is now live and parents can find you.
                    </p>
                    
                    <h3 style="font-family:\'Oswald\',sans-serif;font-size:16px;color:#0A0A0A;margin:24px 0 16px;text-transform:uppercase;">What\'s Next?</h3>
                    
                    <table style="width:100%;" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="padding:12px 0;border-bottom:1px solid #E5E5E5;">
                                <table cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="width:36px;vertical-align:top;">
                                            <span style="display:inline-block;width:24px;height:24px;background:#FCB900;color:#0A0A0A;border-radius:50%;text-align:center;line-height:24px;font-size:12px;font-weight:700;">1</span>
                                        </td>
                                        <td style="font-size:14px;color:#374151;">
                                            <strong>Check your dashboard</strong> - See your profile and availability
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:12px 0;border-bottom:1px solid #E5E5E5;">
                                <table cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="width:36px;vertical-align:top;">
                                            <span style="display:inline-block;width:24px;height:24px;background:#FCB900;color:#0A0A0A;border-radius:50%;text-align:center;line-height:24px;font-size:12px;font-weight:700;">2</span>
                                        </td>
                                        <td style="font-size:14px;color:#374151;">
                                            <strong>Share your profile</strong> - Send your link to friends & family
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:12px 0;">
                                <table cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="width:36px;vertical-align:top;">
                                            <span style="display:inline-block;width:24px;height:24px;background:#FCB900;color:#0A0A0A;border-radius:50%;text-align:center;line-height:24px;font-size:12px;font-weight:700;">3</span>
                                        </td>
                                        <td style="font-size:14px;color:#374151;">
                                            <strong>Respond quickly</strong> - Fast responses = more bookings
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                    
                    <div style="text-align:center;margin-top:32px;">
                        <a href="' . esc_url($dashboard_url) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;font-family:\'Oswald\',sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;text-decoration:none;padding:16px 40px;border-radius:8px;">
                            Go to Dashboard
                        </a>
                    </div>
                </td>
            </tr>
            <tr>
                <td style="background:#F5F5F5;padding:16px;text-align:center;font-size:12px;color:#6B7280;border-radius:0 0 12px 12px;">
                    Welcome to the team! 🙌<br>
                    PTP - Players Teaching Players
                </td>
            </tr>
        </table>';
        
        if (class_exists('PTP_Email')) {
            PTP_Email::send_custom_email($trainer->email, $subject, $content);
        }
        
        // Also send SMS
        if (!empty($trainer->phone) && class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            $sms = "🎉 {$first_name}, your PTP profile is complete! You're now visible to parents and can receive bookings. Check your dashboard: " . home_url('/trainer-dashboard/');
            PTP_SMS::send($trainer->phone, $sms);
        }
    }
    
    /**
     * Admin: Manually send a reminder
     */
    public static function ajax_send_reminder() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        if (!$trainer_id) {
            wp_send_json_error('Invalid trainer ID');
        }
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            wp_send_json_error('Trainer not found');
        }
        
        $completion = self::get_completion_status($trainer);
        $reminder_num = intval($trainer->onboarding_reminder_count ?? 0) + 1;
        
        $sent = self::send_reminder($trainer, min($reminder_num, 4), $completion);
        
        if ($sent) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                array(
                    'onboarding_reminder_count' => $reminder_num,
                    'last_onboarding_reminder_at' => current_time('mysql'),
                ),
                array('id' => $trainer_id)
            );
            
            wp_send_json_success('Reminder sent successfully');
        } else {
            wp_send_json_error('Failed to send reminder');
        }
    }
    
    /**
     * Get all trainers with incomplete onboarding for admin dashboard
     */
    public static function get_incomplete_trainers() {
        global $wpdb;
        
        $trainers = $wpdb->get_results("
            SELECT t.* 
            FROM {$wpdb->prefix}ptp_trainers t
            WHERE t.status = 'active'
            AND (t.onboarding_completed_at IS NULL OR t.onboarding_completed_at = '0000-00-00 00:00:00')
            ORDER BY t.approved_at DESC
        ");
        
        $results = array();
        foreach ($trainers as $trainer) {
            $completion = self::get_completion_status($trainer);
            $results[] = array(
                'trainer' => $trainer,
                'completion' => $completion,
            );
        }
        
        return $results;
    }
    
    /**
     * Run manual check (for admin testing)
     */
    public static function run_manual_check() {
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        self::check_and_send_reminders();
        return true;
    }
}
