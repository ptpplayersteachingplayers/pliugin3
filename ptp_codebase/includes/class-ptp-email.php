<?php
/**
 * PTP Email System v29.4
 * Professional, bulletproof email templates for all inboxes
 * No emojis, consistent branding, mobile-optimized
 */

defined('ABSPATH') || exit;

class PTP_Email {
    
    private static $from_name = '';
    private static $from_email = '';
    
    public static function init() {
        // v221: Use centralized brand helper
        self::$from_email = function_exists('ptp_email_brand') ? ptp_email_brand('from_email') : get_option('ptp_from_email', get_option('admin_email'));
        self::$from_name  = function_exists('ptp_email_brand') ? ptp_email_brand('company') : get_option('ptp_company_name', 'PTP Soccer');
        add_filter('wp_mail_from', array(__CLASS__, 'set_from_email'));
        add_filter('wp_mail_from_name', array(__CLASS__, 'set_from_name'));
        add_filter('wp_mail_content_type', array(__CLASS__, 'set_html_content_type'));
        
        // Session reminder emails (24hr before)
        add_action('ptp_session_reminder', array(__CLASS__, 'send_session_reminder'), 10, 1);
        
        // v221: Approval email is now sent directly from admin_review_application
        // to include login credentials. The hook is kept as a no-op fallback
        // in case other code triggers it, but it checks for duplicate sends.
        // v136: Removed hook-based send_trainer_approval_email to prevent duplicates
        // The primary approval email is sent directly via send_application_approved()
        // from admin_review_application in class-ptp-ajax.php
        // add_action('ptp_trainer_approved', array(__CLASS__, 'send_trainer_approval_email'), 10, 1);
    }
    
    /**
     * v221: Hook-based approval email (fallback only)
     * The primary approval email is now sent directly via send_application_approved()
     * from admin_review_application. This hook handler checks for duplicates.
     */
    public static function send_trainer_approval_email($trainer_id) {
        // v221: Skip if send_application_approved already sent the email in this request
        $already_sent = get_transient('ptp_approval_email_sent_' . $trainer_id);
        if ($already_sent) {
            ptp_log("PTP Email: Skipping hook-based approval email for trainer #{$trainer_id} — already sent by admin_review_application");
            return true;
        }
        
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) {
            return false;
        }
        
        // v178: Fall back to WP user email if trainer.email is empty
        $email = $trainer->email;
        if (empty($email) && !empty($trainer->user_id)) {
            $user = get_user_by('ID', $trainer->user_id);
            if ($user) {
                $email = $user->user_email;
                // Also backfill the trainer record so this doesn't happen again
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    array('email' => $email),
                    array('id' => $trainer_id)
                );
            }
        }
        
        if (empty($email)) {
            ptp_log("PTP Email: No email found for trainer #{$trainer_id} — skipping approval email");
            return false;
        }
        
        $first_name = explode(' ', $trainer->display_name)[0];
        $subject = "You're Approved! Complete Your Profile";
        $login_url = home_url('/trainer-onboarding/');
        
        // v178: Send HTML (content-type filter is already text/html)
        $body = '<div style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:560px;margin:0 auto;padding:24px;">';
        $body .= '<h2 style="margin:0 0 12px;color:#0E0F11;">Welcome to ' . esc_html(ptp_email_brand('company')) . ', ' . esc_html($first_name) . '!</h2>';
        $body .= '<p style="color:#374151;font-size:15px;line-height:1.6;">Great news — your trainer application has been approved.</p>';
        $body .= '<p style="color:#374151;font-size:15px;line-height:1.6;">Complete your profile and you\'ll be live on the trainer directory where families can find and book you.</p>';
        $body .= '<div style="margin:24px 0;text-align:center;"><a href="' . esc_url($login_url) . '" style="display:inline-block;background:#FCB900;color:#0a0a0a;padding:14px 28px;font-weight:700;font-size:15px;text-decoration:none;">Complete Your Profile</a></div>';
        $body .= '<p style="color:#374151;font-size:14px;line-height:1.6;"><strong>Next steps:</strong></p>';
        $body .= '<p style="color:#374151;font-size:14px;line-height:1.8;">1. Upload a professional photo<br>2. Write your bio and set your hourly rate<br>3. Set your weekly availability<br>4. Connect Stripe for payouts<br>5. Sign the contractor agreement</p>';
        $body .= '<p style="color:#6B7280;font-size:13px;margin-top:24px;">— The PTP Team</p>';
        $body .= '</div>';
        
        $result = wp_mail($email, $subject, $body);
        ptp_log("PTP Email: Sent approval email to trainer #{$trainer_id} ({$email}): " . ($result ? 'success' : 'failed'));
        
        return $result;
    }
    
    public static function set_from_email($email) {
        return self::$from_email;
    }
    
    public static function set_from_name($name) {
        return self::$from_name;
    }
    
    public static function set_html_content_type() {
        return 'text/html';
    }
    
    /**
     * Send booking confirmation to parent
     */
    public static function send_booking_confirmation($booking_id) {
        global $wpdb;
        
        // v214: Check if unified checkout already sent this email (prevents duplicate)
        $already_sent = get_transient('ptp_training_email_sent_' . $booking_id);
        if ($already_sent) {
            ptp_log("[PTP Email v214] Skipping send_booking_confirmation for #{$booking_id} - already sent by unified checkout at " . ($already_sent['time'] ?? 'unknown'));
            return true; // Return true so callers don't retry
        }
        
        // v118.2: Use LEFT JOIN for BOTH players AND parents to handle guest checkouts
        // v132: Also get group_players for multi-player sessions
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug,
                   t.location as trainer_location, t.city as trainer_city, t.state as trainer_state,
                   t.phone as trainer_phone, t.training_locations as trainer_training_locations,
                   p.name as player_name,
                   pa.display_name as parent_name, pa.user_id as parent_user_id, pa.email as parent_email
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) {
            ptp_log("[PTP Email] send_booking_confirmation: Booking #$booking_id not found or query failed");
            return false;
        }
        
        // v132: Parse group_players JSON if available
        if (!empty($booking->group_players)) {
            $players_data = json_decode($booking->group_players, true);
            if (is_array($players_data) && count($players_data) > 0) {
                $booking->players_data = $players_data;
                ptp_log("[PTP Email v132] Found " . count($players_data) . " players in group_players");
            }
        }
        
        // v118.2: Get email with multiple fallbacks for guest checkout
        // Priority: WP user > parent record > guest_email column > parse from notes
        $email = '';
        if (!empty($booking->parent_user_id)) {
            $user = get_user_by('ID', $booking->parent_user_id);
            if ($user) $email = $user->user_email;
        }
        if (!$email && !empty($booking->parent_email)) {
            $email = $booking->parent_email;
        }
        if (!$email && !empty($booking->guest_email)) {
            $email = $booking->guest_email;
        }
        if (!$email && !empty($booking->notes)) {
            // Last resort: parse email from notes
            if (preg_match('/Email:\s*([^\s\n]+)/i', $booking->notes, $matches)) {
                $email = $matches[1];
            }
        }
        
        // v136: Fallback to Stripe billing email if payment_intent_id exists
        if (!$email && !empty($booking->payment_intent_id) && class_exists('PTP_Stripe')) {
            try {
                $pi = PTP_Stripe::retrieve_payment_intent($booking->payment_intent_id);
                if ($pi && !empty($pi['receipt_email'])) {
                    $email = $pi['receipt_email'];
                    ptp_log("[PTP Email v136] Recovered email from Stripe PI for booking #$booking_id: $email");
                }
            } catch (\Throwable $e) {
                ptp_log("[PTP Email v136] Stripe email lookup failed for booking #$booking_id: " . $e->getMessage());
            }
        }
        
        if (!$email) {
            ptp_log("[PTP Email] send_booking_confirmation: No email found for booking #$booking_id — tried WP user (parent_user_id=" . ($booking->parent_user_id ?? 'null') . "), parent record, guest_email, notes parse, and Stripe PI");
            
            // v136: Notify admin about failed email lookup so it can be resolved manually
            $admin_email = get_option('admin_email');
            if ($admin_email) {
                wp_mail(
                    $admin_email,
                    "⚠️ Missing Email - Booking #{$booking_id}",
                    "Booking #{$booking_id} ({$booking->booking_number}) has no parent email address.\n\n" .
                    "Trainer: {$booking->trainer_name}\n" .
                    "Date: {$booking->session_date}\n" .
                    "Parent ID: " . ($booking->parent_id ?? 'none') . "\n\n" .
                    "Please update the booking with the correct email address:\n" .
                    admin_url("admin.php?page=ptp-bookings&action=edit&id={$booking_id}")
                );
            }
            
            return false;
        }
        
        // Get trainer object for the template
        $trainer = (object) array(
            'display_name' => $booking->trainer_name,
            'photo_url' => $booking->trainer_photo,
            'slug' => $booking->trainer_slug ?? '',
            'phone' => $booking->trainer_phone ?? '',
            'training_locations' => $booking->trainer_training_locations ?? '',
        );
        
        // Get parent object for the template
        $parent = (object) array(
            'display_name' => $booking->parent_name,
            'email' => $email,
        );
        
        // v132: Use the new email template that supports multi-player
        // v214: Always pass explicit Content-Type headers (don't rely on global filter)
        $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . ptp_email_brand('from_training'));
        
        if (class_exists('PTP_Email_Templates')) {
            $email_content = PTP_Email_Templates::booking_confirmation($booking, $trainer, $parent);
            
            if ($email_content && !empty($email_content['body'])) {
                $subject = $email_content['subject'] ?? "Booking Confirmed - {$booking->booking_number}";
                $body = $email_content['body'];
                
                ptp_log("[PTP Email v214] Sending booking confirmation for #$booking_id to: $email");
                // v136: Set dedup flag BEFORE sending to prevent race condition
                set_transient('ptp_training_email_sent_' . $booking_id, array('time' => time(), 'parent' => $email, 'source' => 'PTP_Email'), 24 * HOUR_IN_SECONDS);
                $sent = wp_mail($email, $subject, $body, $headers);
                if (!$sent) {
                    // If send failed, remove the flag so it can be retried
                    delete_transient('ptp_training_email_sent_' . $booking_id);
                }
                ptp_log("[PTP Email v214] Booking confirmation for #$booking_id: " . ($sent ? 'SENT' : 'FAILED'));
                return $sent;
            }
        }
        
        // Fallback to old template if PTP_Email_Templates not available
        $subject = "Booking Confirmed - {$booking->booking_number}";
        
        // v118.1: Handle missing player name gracefully
        $player_name = $booking->player_name ?: 'Your Player';
        
        $data = array(
            'parent_name' => $booking->parent_name,
            'trainer_name' => $booking->trainer_name,
            'trainer_photo' => $booking->trainer_photo,
            'trainer_phone' => $booking->trainer_phone ?? '',
            'player_name' => $player_name,
            'date' => (!empty($booking->session_date) && $booking->session_date !== '0000-00-00' && strtotime($booking->session_date) > 0)
                ? date('l, F j, Y', strtotime($booking->session_date))
                : 'TBD - Your trainer will confirm',
            'time' => (!empty($booking->start_time) && $booking->start_time !== '00:00:00')
                ? date('g:i A', strtotime($booking->start_time)) . (!empty($booking->end_time) && $booking->end_time !== '00:00:00' ? ' - ' . date('g:i A', strtotime($booking->end_time)) : '')
                : 'TBD',
            // v225: Prefer booking's selected location, fall back to trainer profile
            'location' => self::resolve_trainer_location($booking),
            // v236: Resolve full street address for confirmation email
            'location_address' => self::resolve_location_address($booking),
            'total' => number_format($booking->total_amount, 2),
            'booking_number' => $booking->booking_number,
            'dashboard_url' => home_url('/my-training/'),
        );
        
        $body = self::render_template('booking-confirmation', $data);
        
        // v59.5: Allow camp crosssell system to add content
        $body = apply_filters('ptp_email_booking_confirmation_content', $body, $booking);
        
        ptp_log("[PTP Email v214] Sending booking confirmation (fallback) for #$booking_id to: $email");
        $sent = wp_mail($email, $subject, $body, $headers);
        if ($sent) {
            set_transient('ptp_training_email_sent_' . $booking_id, array('time' => time(), 'parent' => $email, 'source' => 'PTP_Email_fallback'), 24 * HOUR_IN_SECONDS);
        }
        ptp_log("[PTP Email v214] Booking confirmation for #$booking_id: " . ($sent ? 'SENT' : 'FAILED'));
        return $sent;
    }
    
    /**
     * Send new booking notification to trainer
     */
    public static function send_trainer_new_booking($booking_id) {
        global $wpdb;
        
        // v225: Dedup check — prevent duplicate trainer notifications
        $dedup_key = 'ptp_trainer_email_sent_' . $booking_id;
        if (get_transient($dedup_key)) {
            ptp_log("[PTP Email v225] Skipping send_trainer_new_booking for #{$booking_id} — already sent");
            return true;
        }
        
        // v118.2: Use LEFT JOIN for BOTH players AND parents to handle guest checkouts
        // v132: Also get group_players for multi-player sessions
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name, t.user_id as trainer_user_id, t.hourly_rate, t.email as trainer_email,
                   t.location as trainer_location, t.city as trainer_city, t.state as trainer_state,
                   p.name as player_name, p.age as player_age, p.skill_level,
                   COALESCE(pa.display_name, 'Guest') as parent_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) {
            ptp_log("[PTP Email] send_trainer_new_booking: Booking #$booking_id not found or query failed");
            return false;
        }
        
        // Get email from user account or trainer record
        $user = get_user_by('ID', $booking->trainer_user_id);
        $email = $user ? $user->user_email : ($booking->trainer_email ?? '');
        
        if (!$email) {
            ptp_log("[PTP Email] send_trainer_new_booking: No email found for booking #$booking_id");
            return false;
        }
        
        // v132: Parse group_players JSON for multi-player sessions
        $all_players = array();
        if (!empty($booking->group_players)) {
            $players_data = json_decode($booking->group_players, true);
            if (is_array($players_data)) {
                foreach ($players_data as $p) {
                    $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                    if (!empty($name)) {
                        $all_players[] = $name;
                    }
                }
            }
        }
        
        // Fallback to single player
        if (empty($all_players)) {
            $player_name = $booking->player_name ?: 'New Player';
            $all_players[] = $player_name;
        }
        
        $player_count = count($all_players);
        $is_group = $player_count > 1;
        
        // Format subject
        if ($is_group) {
            $subject = "New Group Booking - {$player_count} Players";
            $player_display = implode(', ', $all_players);
        } else {
            $subject = "New Booking - {$all_players[0]}";
            $player_display = $all_players[0];
        }
        
        // v220: Use actual trainer_payout from booking, or dynamically calculate
        $earnings = floatval($booking->trainer_payout ?? 0);
        if ($earnings <= 0 && floatval($booking->total_amount) > 0) {
            // Calculate dynamically using graduated fee if available
            if (class_exists('PTP_Unified_Checkout') && method_exists('PTP_Unified_Checkout', 'calculate_graduated_fee')) {
                $fee_calc = PTP_Unified_Checkout::calculate_graduated_fee(
                    $booking->trainer_id ?? 0,
                    $booking->parent_id ?? 0,
                    floatval($booking->total_amount),
                    1
                );
                $earnings = $fee_calc['trainer_payout'];
            } else {
                $fee_pct = floatval(get_option('ptp_platform_fee_percent', 25));
                $earnings = round($booking->total_amount * (1 - $fee_pct / 100), 2);
            }
        }
        $earnings = round($earnings, 2);
        
        $data = array(
            'trainer_name' => explode(' ', $booking->trainer_name)[0],
            'player_name' => $player_display,
            'player_count' => $player_count,
            'is_group' => $is_group,
            'all_players' => $all_players,
            'player_age' => $booking->player_age ?: 'Not specified',
            'skill_level' => $booking->skill_level ?: 'Not specified',
            'parent_name' => $booking->parent_name,
            'date' => (!empty($booking->session_date) && $booking->session_date !== '0000-00-00' && strtotime($booking->session_date) > 0)
                ? date('l, F j, Y', strtotime($booking->session_date))
                : 'TBD - Parent will confirm',
            // v220: Normalize time before display (safety net for legacy "1:00" stored as 01:00:00)
            'time' => call_user_func(function() use ($booking) {
                $raw = $booking->start_time ?? '';
                if (empty($raw) || $raw === '00:00:00') return 'TBD';
                if (function_exists('ptp_normalize_session_time')) {
                    $raw = ptp_normalize_session_time($raw) ?: $raw;
                }
                $ts = strtotime($raw);
                if ($ts === false) return 'TBD';
                $display = date('g:i A', $ts);
                if (!empty($booking->end_time) && $booking->end_time !== '00:00:00') {
                    $end_raw = $booking->end_time;
                    if (function_exists('ptp_normalize_session_time')) {
                        $end_raw = ptp_normalize_session_time($end_raw) ?: $end_raw;
                    }
                    $display .= ' - ' . date('g:i A', strtotime($end_raw));
                }
                return $display;
            }),
            // v225: Prefer booking's selected location, fall back to trainer profile
            'location' => self::resolve_trainer_location($booking),
            'earnings' => number_format($earnings, 2),
            'notes' => $booking->notes ?? '',
            'dashboard_url' => home_url('/trainer-dashboard/'),
            'package_name' => class_exists('PTP_Packages') ? (PTP_Packages::get($booking->package_type ?? ($booking->session_type ?? 'single'))['name'] ?? '1 Session') : '1 Session',
            'total_sessions' => intval($booking->total_sessions ?? ($booking->session_count ?? 1)),
            'group_size' => intval($booking->group_size ?? 1),
        );
        
        $body = self::render_template('trainer-new-booking', $data);
        
        ptp_log("[PTP Email] Sending trainer new booking notification for #$booking_id to: $email");
        $sent = wp_mail($email, $subject, $body);
        ptp_log("[PTP Email] Trainer new booking notification for #$booking_id: " . ($sent ? 'SENT' : 'FAILED'));
        
        // v225: Set dedup transient
        if ($sent) {
            set_transient('ptp_trainer_email_sent_' . $booking_id, array('time' => time(), 'email' => $email), 24 * HOUR_IN_SECONDS);
        }
        
        return $sent;
    }
    
    /**
     * Send session reminder
     */
    public static function send_session_reminder($booking_id, $to = 'both') {
        global $wpdb;
        
        // v118.1: Use LEFT JOIN for players
        // v132: Also get group_players for multi-player sessions
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name, t.user_id as trainer_user_id,
                   t.location as trainer_location, t.city as trainer_city, t.state as trainer_state,
                   p.name as player_name,
                   pa.display_name as parent_name, pa.user_id as parent_user_id
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) return false;
        
        // v132: Parse group_players for multi-player sessions
        $all_players = array();
        if (!empty($booking->group_players)) {
            $players_data = json_decode($booking->group_players, true);
            if (is_array($players_data)) {
                foreach ($players_data as $p) {
                    $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                    if (!empty($name)) {
                        $all_players[] = $name;
                    }
                }
            }
        }
        
        // Fallback to single player
        if (empty($all_players)) {
            $player_name = $booking->player_name ?: 'Your Player';
            $all_players[] = $player_name;
        }
        
        $player_count = count($all_players);
        $is_group = $player_count > 1;
        
        // Format subject line
        if ($is_group) {
            $subject_player = $player_count . " Players";
        } else {
            $subject_player = $all_players[0];
        }
        
        $date = date('l, F j', strtotime($booking->session_date));
        $time = date('g:i A', strtotime($booking->start_time));
        
        if ($to === 'parent' || $to === 'both') {
            $parent_user = get_user_by('ID', $booking->parent_user_id);
            if ($parent_user) {
                $body = self::render_template('session-reminder-parent', array(
                    'name' => explode(' ', $booking->parent_name)[0],
                    'player_name' => $is_group ? ($player_count . ' players') : $all_players[0],
                    'all_players' => $all_players,
                    'is_group' => $is_group,
                    'player_count' => $player_count,
                    'trainer_name' => $booking->trainer_name,
                    'date' => $date,
                    'time' => $time,
                    // v211: Prefer trainer's location from profile
                    'location' => self::resolve_trainer_location($booking),
                    'dashboard_url' => home_url('/my-training/'),
                ));
                wp_mail($parent_user->user_email, "Training Tomorrow - {$subject_player}", $body);
            }
        }
        
        if ($to === 'trainer' || $to === 'both') {
            $trainer_user = get_user_by('ID', $booking->trainer_user_id);
            if ($trainer_user) {
                $body = self::render_template('session-reminder-trainer', array(
                    'name' => explode(' ', $booking->trainer_name)[0],
                    'player_name' => $is_group ? ($player_count . ' players') : $all_players[0],
                    'all_players' => $all_players,
                    'is_group' => $is_group,
                    'player_count' => $player_count,
                    'date' => $date,
                    'time' => $time,
                    // v211: Prefer trainer's location from profile
                    'location' => self::resolve_trainer_location($booking),
                    'dashboard_url' => home_url('/trainer-dashboard/'),
                ));
                wp_mail($trainer_user->user_email, "Session Tomorrow - {$subject_player}", $body);
            }
        }
        
        return true;
    }
    
    /**
     * Send booking cancellation
     */
    public static function send_booking_cancelled($booking_id, $cancelled_by = 'parent', $reason = '') {
        global $wpdb;
        
        // v118.1: Use LEFT JOIN for players
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name, t.user_id as trainer_user_id,
                   p.name as player_name,
                   pa.display_name as parent_name, pa.user_id as parent_user_id
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) return false;
        
        $player_name = $booking->player_name ?: 'Your Player';
        
        $data = array(
            'player_name' => $player_name,
            'trainer_name' => $booking->trainer_name,
            'date' => date('l, F j, Y', strtotime($booking->session_date)),
            'time' => date('g:i A', strtotime($booking->start_time)),
            'reason' => $reason,
        );
        
        // Notify trainer
        $trainer_user = get_user_by('ID', $booking->trainer_user_id);
        if ($trainer_user) {
            $data['name'] = explode(' ', $booking->trainer_name)[0];
            $body = self::render_template('booking-cancelled', $data);
            wp_mail($trainer_user->user_email, "Session Cancelled - {$booking->player_name}", $body);
        }
        
        // Notify parent
        $parent_user = get_user_by('ID', $booking->parent_user_id);
        if ($parent_user) {
            $data['name'] = explode(' ', $booking->parent_name)[0];
            $body = self::render_template('booking-cancelled', $data);
            wp_mail($parent_user->user_email, "Session Cancelled - {$booking->player_name}", $body);
        }
        
        return true;
    }
    
    /**
     * Send new message notification
     */
    public static function send_new_message($conversation_id, $sender_id, $message_text) {
        global $wpdb;
        
        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_conversations WHERE id = %d",
            $conversation_id
        ));
        
        if (!$conv) return false;
        
        $recipient_id = ($conv->user_1_id == $sender_id) ? $conv->user_2_id : $conv->user_1_id;
        $recipient = get_user_by('ID', $recipient_id);
        $sender = get_user_by('ID', $sender_id);
        
        if (!$recipient || !$sender) return false;
        
        $body = self::render_template('new-message', array(
            'recipient_name' => explode(' ', $recipient->display_name)[0],
            'sender_name' => $sender->display_name,
            'message' => wp_trim_words($message_text, 30),
            'messages_url' => home_url('/messages/'),
        ));
        
        return wp_mail($recipient->user_email, "New Message from {$sender->display_name}", $body);
    }
    
    /**
     * Send payout notification
     */
    public static function send_payout_processed($trainer_id, $amount, $method) {
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) return false;
        
        $user = get_user_by('ID', $trainer->user_id);
        if (!$user) return false;
        
        $body = self::render_template('payout-processed', array(
            'name' => explode(' ', $trainer->display_name)[0],
            'amount' => number_format($amount, 2),
            'method' => ucfirst($method),
            'date' => date('F j, Y'),
            'dashboard_url' => home_url('/trainer-dashboard/?tab=earnings'),
        ));
        
        return wp_mail($user->user_email, "Payout Processed - \${$amount}", $body);
    }
    
    /**
     * Send application received
     */
    public static function send_application_received($email, $name) {
        $body = self::render_template('application-received', array(
            'name' => explode(' ', $name)[0],
        ));
        
        return wp_mail($email, "Application Received - " . ptp_email_brand('company'), $body);
    }
    
    /**
     * Send application approved
     * Can accept either (email, name, password) or (trainer_id, password)
     */
    public static function send_application_approved($email_or_trainer_id, $name_or_password = '', $password = null) {
        // Determine calling method - if first param is numeric, it's trainer_id style
        if (is_numeric($email_or_trainer_id)) {
            $trainer = PTP_Trainer::get($email_or_trainer_id);
            if (!$trainer) return false;
            
            $user = get_user_by('ID', $trainer->user_id);
            if (!$user) return false;
            
            $email = $user->user_email;
            $name = $trainer->display_name;
            $password = $name_or_password;
            
            // v221: Set transient to prevent duplicate from hook-based send_trainer_approval_email
            set_transient('ptp_approval_email_sent_' . $email_or_trainer_id, true, 300);
        } else {
            // Called with (email, name, password)
            $email = $email_or_trainer_id;
            $name = $name_or_password;
            // $password is already set from third param
            
            // v221: Try to find trainer_id to set the dedup transient
            global $wpdb;
            $tid = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE email = %s LIMIT 1", $email
            ));
            if ($tid) {
                set_transient('ptp_approval_email_sent_' . $tid, true, 300);
            }
        }
        
        $body = self::render_template('application-approved', array(
            'name' => explode(' ', $name)[0],
            'email' => $email,
            'password' => $password,
            'login_url' => home_url('/trainer-onboarding/'),
        ));
        
        return wp_mail($email, "Welcome to PTP - You're Approved!", $body);
    }
    
    /**
     * Send application rejected
     */
    public static function send_application_rejected($email, $name) {
        $body = self::render_template('application-rejected', array(
            'name' => explode(' ', $name)[0],
        ));
        
        return wp_mail($email, "Application Update - " . ptp_email_brand('company'), $body);
    }
    
    /**
     * Send contractor agreement
     */
    public static function send_contractor_agreement($trainer_id) {
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer || !$trainer->contractor_agreement_signed) return false;
        
        $user = get_user_by('ID', $trainer->user_id);
        if (!$user) return false;
        
        $body = self::render_template('contractor-agreement', array(
            'name' => $trainer->display_name,
            'signed_date' => date('F j, Y g:i A', strtotime($trainer->contractor_agreement_signed_at)),
            'ip_address' => $trainer->contractor_agreement_ip,
        ));
        
        return wp_mail($user->user_email, "Contractor Agreement Signed - " . ptp_email_brand('company'), $body);
    }
    
    /**
     * Send onboarding complete
     */
    public static function send_onboarding_complete($trainer_id) {
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) return false;
        
        $user = get_user_by('ID', $trainer->user_id);
        if (!$user) return false;
        
        $body = self::render_template('onboarding-complete', array(
            'name' => explode(' ', $trainer->display_name)[0],
            'profile_url' => home_url('/trainer/' . $trainer->slug . '/'),
            'dashboard_url' => home_url('/trainer-dashboard/'),
        ));
        
        return wp_mail($user->user_email, "Profile Complete - You're Live!", $body);
    }
    
    /**
     * Send welcome to parent
     */
    public static function send_welcome_parent($user_id) {
        $user = get_user_by('ID', $user_id);
        if (!$user) return false;
        
        $body = self::render_template('welcome-parent', array(
            'name' => explode(' ', $user->display_name)[0],
            'trainers_url' => home_url('/find-trainers/'),
        ));
        
        return wp_mail($user->user_email, "Welcome to " . ptp_email_brand('company'), $body);
    }
    
    /**
     * Send session completion request to parent
     * Called after trainer marks session complete
     */
    public static function send_session_completion_request($booking_id) {
        global $wpdb;
        
        // v118.1: Use LEFT JOIN for players
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name,
                   t.location as trainer_location, t.city as trainer_city, t.state as trainer_state,
                   p.name as player_name,
                   pa.display_name as parent_name, pa.user_id as parent_user_id
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) return false;
        
        $user = get_user_by('ID', $booking->parent_user_id);
        if (!$user) return false;
        
        $player_name = $booking->player_name ?: 'Your Player';
        
        $body = self::render_template('session-completion-request', array(
            'parent_name' => explode(' ', $booking->parent_name)[0],
            'player_name' => $player_name,
            'trainer_name' => $booking->trainer_name,
            'date' => date('l, F j, Y', strtotime($booking->session_date)),
            'time' => date('g:i A', strtotime($booking->start_time)),
            // v211: Prefer trainer's location from profile
            'location' => self::resolve_trainer_location($booking),
            'confirm_url' => class_exists('PTP_Escrow') ? PTP_Escrow::get_email_confirm_url($booking_id) : home_url('/my-training/?confirm=' . $booking_id),
            'dispute_url' => home_url('/my-training/?dispute=' . $booking_id),
        ));
        
        return wp_mail($user->user_email, "Please Confirm {$player_name}'s Session", $body);
    }
    
    /**
     * Send trainer notification that session is awaiting confirmation
     */
    public static function send_trainer_awaiting_confirmation($booking_id) {
        global $wpdb;
        
        // v118.1: Use LEFT JOIN for players
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name, t.user_id as trainer_user_id,
                   p.name as player_name,
                   pa.display_name as parent_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) return false;
        
        $user = get_user_by('ID', $booking->trainer_user_id);
        if (!$user) return false;
        
        $player_name = $booking->player_name ?: 'Player';
        // v220: Dynamic payout calculation
        $earnings = floatval($booking->trainer_payout ?? 0);
        if ($earnings <= 0 && floatval($booking->total_amount) > 0) {
            $fee_pct = floatval(get_option('ptp_platform_fee_percent', 25));
            $earnings = round($booking->total_amount * (1 - $fee_pct / 100), 2);
        }
        
        $body = self::render_template('trainer-awaiting-confirmation', array(
            'trainer_name' => explode(' ', $booking->trainer_name)[0],
            'player_name' => $player_name,
            'parent_name' => $booking->parent_name,
            'date' => date('l, F j, Y', strtotime($booking->session_date)),
            'earnings' => number_format($earnings, 2),
            'dashboard_url' => home_url('/trainer-dashboard/?tab=earnings'),
        ));
        
        return wp_mail($user->user_email, "Session Marked Complete - Awaiting Parent Confirmation", $body);
    }
    
    /**
     * Send payout completed notification (enhanced version)
     */
    public static function send_payout_completed($payout_id) {
        global $wpdb;
        
        $payout = $wpdb->get_row($wpdb->prepare("
            SELECT p.*, t.display_name as trainer_name, t.user_id as trainer_user_id
            FROM {$wpdb->prefix}ptp_payouts p
            JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
            WHERE p.id = %d
        ", $payout_id));
        
        if (!$payout) return false;
        
        $user = get_user_by('ID', $payout->trainer_user_id);
        if (!$user) return false;
        
        $body = self::render_template('payout-processed', array(
            'name' => explode(' ', $payout->trainer_name)[0],
            'amount' => number_format($payout->amount, 2),
            'method' => 'Stripe Connect',
            'date' => date('F j, Y'),
            'dashboard_url' => home_url('/trainer-dashboard/?tab=earnings'),
        ));
        
        return wp_mail($user->user_email, "Payout Processed - \${$payout->amount}", $body);
    }
    
    /**
     * Send review request
     */
    public static function send_review_request($booking_id) {
        global $wpdb;
        
        // v118.1: Use LEFT JOIN for players
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name, t.slug as trainer_slug,
                   p.name as player_name,
                   pa.display_name as parent_name, pa.user_id as parent_user_id
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) return false;
        
        $user = get_user_by('ID', $booking->parent_user_id);
        if (!$user) return false;
        
        $player_name = $booking->player_name ?: 'your player';
        
        $body = self::render_template('review-request', array(
            'parent_name' => explode(' ', $booking->parent_name)[0],
            'player_name' => $player_name,
            'trainer_name' => $booking->trainer_name,
            'review_url' => home_url('/trainer/' . $booking->trainer_slug . '/?review=' . $booking_id),
            'google_review_url' => get_option('ptp_google_review_url', 'https://g.page/r/CYour-Google-Review-Link/review'),
        ));
        
        return wp_mail($user->user_email, "How was {$player_name}'s session?", $body);
    }
    
    /**
     * Send a custom email with pre-built HTML content
     * Used by PTP_Onboarding_Reminders and other systems that build their own HTML
     * 
     * @param string $to     Recipient email
     * @param string $subject Email subject
     * @param string $html   Complete HTML body
     * @return bool
     * @since v191
     */
    public static function send_custom_email($to, $subject, $html) {
        if (empty($to) || !is_email($to)) {
            ptp_log("PTP Email: Invalid email address for custom email: {$to}");
            return false;
        }
        
        $result = wp_mail($to, $subject, $html);
        ptp_log("PTP Email: Custom email to {$to} [{$subject}]: " . ($result ? 'sent' : 'failed'));
        return $result;
    }
    
    /**
     * Master template renderer - Bulletproof for all email clients
     */
    /**
     * v225: Resolve the best location string for a booking.
     * Priority: booking's selected location (from checkout) > trainer profile location > city/state
     * Requires the query to include t.location as trainer_location, t.city as trainer_city, t.state as trainer_state.
     */
    private static function resolve_trainer_location($booking) {
        // v225: Priority 1 — the specific location parent selected during checkout
        if (!empty($booking->location)) {
            return $booking->location;
        }
        // Priority 2 — trainer's profile location (e.g. "Havertown, PA")
        if (!empty($booking->trainer_location)) {
            return $booking->trainer_location;
        }
        // Priority 3 — trainer city/state
        if (!empty($booking->trainer_city)) {
            return $booking->trainer_city . (!empty($booking->trainer_state) ? ', ' . $booking->trainer_state : '');
        }
        // v230: Priority 4 — first training_locations entry from trainer profile
        if (!empty($booking->trainer_id)) {
            $loc = self::get_first_training_location($booking->trainer_id);
            if ($loc) return $loc;
        }
        return '';
    }

    /**
     * v230: Extract first location from trainer's training_locations JSON
     */
    private static function get_first_training_location($trainer_id) {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT training_locations FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        if (empty($raw)) return '';
        $locs = json_decode($raw, true);
        if (!is_array($locs)) $locs = json_decode(wp_unslash($raw), true);
        if (!is_array($locs) || empty($locs)) return '';
        foreach ($locs as $loc) {
            if (is_array($loc) && !empty($loc['name'])) {
                return $loc['name'];
            }
        }
        return '';
    }

    /**
     * v236: Resolve full street address for a booking's location.
     * Checks location_notes first, then matches against trainer's training_locations JSON.
     */
    private static function resolve_location_address($booking) {
        // Priority 1: location_notes (set by unified checkout with full address + GPS)
        if (!empty($booking->location_notes)) {
            $addr = preg_replace('/\s*\|\s*GPS:.*$/i', '', $booking->location_notes);
            $addr = trim($addr);
            if ($addr && $addr !== ($booking->location ?? '')) {
                return $addr;
            }
        }
        // Priority 2: Look up address from trainer's training_locations by matching location name
        $location_name = self::resolve_trainer_location($booking);
        if (empty($location_name) || $location_name === 'Location TBD') {
            return '';
        }
        $trainer_id = $booking->trainer_id ?? 0;
        if (!$trainer_id) return '';
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT training_locations FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        if (empty($raw)) return '';
        $locs = json_decode($raw, true);
        if (!is_array($locs)) $locs = json_decode(wp_unslash($raw), true);
        if (!is_array($locs)) return '';
        // Try exact name match first
        foreach ($locs as $loc) {
            if (is_array($loc) && !empty($loc['name']) && $loc['name'] === $location_name && !empty($loc['address'])) {
                return trim($loc['address']);
            }
        }
        // Fallback: first location with an address
        foreach ($locs as $loc) {
            if (is_array($loc) && !empty($loc['address'])) {
                return trim($loc['address']);
            }
        }
        return '';
    }

    private static function render_template($template, $data) {
        $b = function_exists('ptp_email_brand') ? ptp_email_brand() : array();
        $logo_url = $b['logo_url'] ?? '';
        $home_url = $b['site_url'] ?? home_url();
        $company  = $b['company'] ?? 'PTP Soccer';
        $support_email = $b['support_email'] ?? get_option('admin_email');
        
        ob_start();
        ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="x-apple-disable-message-reformatting">
    <meta http-equiv="X-UA-Compatible" content="IE=edge"/>
    <title><?php echo esc_html($company); ?></title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <style type="text/css">
        table {border-collapse: collapse;}
        .button-td, .button-a {padding: 16px 32px !important;}
    </style>
    <![endif]-->
    <style type="text/css">
        body {margin: 0; padding: 0; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;}
        table, td {border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt;}
        img {border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic;}
        @media screen and (max-width: 600px) {
            .mobile-padding {padding-left: 16px !important; padding-right: 16px !important;}
            .mobile-stack {display: block !important; width: 100% !important;}
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #0E0F11; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">

    <!-- Preheader (hidden text for email preview) -->
    <div style="display: none; max-height: 0; overflow: hidden;">
        <?php echo self::get_preheader($template, $data); ?>
    </div>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #0E0F11;">
        <tr>
            <td align="center" style="padding: 40px 20px;" class="mobile-padding">
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width: 560px;">
                    
                    <!-- Logo -->
                    <tr>
                        <td align="center" style="padding-bottom: 32px;">
                            <a href="<?php echo esc_url($home_url); ?>" target="_blank">
                                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company); ?>" width="100" style="display: block; max-width: 100px; height: auto;">
                            </a>
                        </td>
                    </tr>
                    
                    <!-- Content Card -->
                    <tr>
                        <td>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #ffffff; border-radius: 16px; overflow: hidden;">
                                
                                <!-- Yellow Top Border -->
                                <tr>
                                    <td style="background-color: #FCB900; height: 5px; line-height: 5px; font-size: 5px;">&nbsp;</td>
                                </tr>
                                
                                <!-- Main Content -->
                                <tr>
                                    <td style="padding: 40px 36px 36px;" class="mobile-padding">
                                        <?php echo self::get_template_content($template, $data); ?>
                                    </td>
                                </tr>
                                
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 32px 20px; text-align: center;">
                            <p style="margin: 0 0 16px; font-size: 13px; color: #6B7280; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                                PA &bull; NJ &bull; DE &bull; MD &bull; NY
                            </p>
                            <p style="margin: 0; font-size: 12px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                                <a href="<?php echo esc_url($home_url); ?>" style="color: #FCB900; text-decoration: none;">Website</a>
                                <span style="color: #4B5563; margin: 0 8px;">|</span>
                                <a href="<?php echo esc_url(home_url('/account/')); ?>" style="color: #FCB900; text-decoration: none;">Account</a>
                                <span style="color: #4B5563; margin: 0 8px;">|</span>
                                <a href="mailto:<?php echo esc_attr($support_email); ?>" style="color: #FCB900; text-decoration: none;">Contact</a>
                            </p>
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
    
    /**
     * Get preheader text for email preview
     */
    private static function get_preheader($template, $data) {
        switch ($template) {
            case 'booking-confirmation':
                return "Your training session with {$data['trainer_name']} is confirmed.";
            case 'trainer-new-booking':
                return "New session booked with {$data['player_name']}.";
            case 'session-reminder-parent':
            case 'session-reminder-trainer':
                return "Reminder: Training session tomorrow at {$data['time']}.";
            case 'application-approved':
                return "Congratulations! You've been approved as a PTP trainer.";
            case 'payout-processed':
                return "Your payout of \${$data['amount']} has been processed.";
            case 'session-recap':
                return "Here's what {$data['player_name']} worked on with {$data['trainer_name']}.";
            default:
                return ptp_email_brand('company') . " - Elite 1-on-1 Training";
        }
    }
    
    /**
     * Get template-specific content - Professional, no emojis
     */
    private static function get_template_content($template, $data) {
        // Button styles
        $btn_primary = 'display: inline-block; background-color: #FCB900; color: #0E0F11; padding: 16px 32px; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 15px; font-family: -apple-system, BlinkMacSystemFont, sans-serif;';
        $btn_outline = 'display: inline-block; background-color: transparent; color: #374151; padding: 14px 28px; text-decoration: none; border-radius: 10px; font-weight: 600; font-size: 14px; border: 2px solid #E5E7EB; font-family: -apple-system, BlinkMacSystemFont, sans-serif;';
        
        // Text styles
        $h1 = 'margin: 0 0 8px; font-size: 26px; font-weight: 800; color: #0E0F11; font-family: -apple-system, BlinkMacSystemFont, sans-serif;';
        $subtitle = 'margin: 0 0 28px; font-size: 16px; color: #6B7280; line-height: 1.5; font-family: -apple-system, BlinkMacSystemFont, sans-serif;';
        $label = 'font-size: 11px; font-weight: 700; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.5px;';
        $value = 'font-size: 16px; font-weight: 600; color: #0E0F11; font-family: -apple-system, BlinkMacSystemFont, sans-serif;';
        
        ob_start();
        
        switch ($template) {
            
            case 'booking-confirmation':
                ?>
                <h1 style="<?php echo $h1; ?>">Booking Confirmed</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['parent_name']); ?>, your training session is all set.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <span style="<?php echo $label; ?>">Booking Number</span><br>
                                        <span style="font-size: 16px; font-weight: 700; color: #0E0F11;"><?php echo esc_html($data['booking_number']); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <span style="<?php echo $label; ?>">Trainer</span><br>
                                        <span style="<?php echo $value; ?>"><?php echo esc_html($data['trainer_name']); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <span style="<?php echo $label; ?>">Player</span><br>
                                        <span style="<?php echo $value; ?>"><?php echo esc_html($data['player_name']); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <span style="<?php echo $label; ?>">Date &amp; Time</span><br>
                                        <span style="<?php echo $value; ?>"><?php echo esc_html($data['date']); ?></span><br>
                                        <span style="font-size: 14px; color: #374151;"><?php echo esc_html($data['time']); ?></span>
                                    </td>
                                </tr>
                                <?php if (!empty($data['location'])): ?>
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <span style="<?php echo $label; ?>">Location</span><br>
                                        <span style="font-size: 15px; color: #0E0F11;"><?php echo esc_html($data['location']); ?></span>
                                        <?php if (!empty($data['location_address'])): ?><br>
                                        <span style="font-size: 13px; color: #6B7280;"><?php echo esc_html($data['location_address']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td style="padding: 12px 0;">
                                        <span style="<?php echo $label; ?>">Total Paid</span><br>
                                        <span style="font-size: 22px; font-weight: 800; color: #0E0F11;">$<?php echo esc_html($data['total']); ?></span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['dashboard_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">View Booking Details</a>
                        </td>
                    </tr>
                </table>
                
                <?php
                // Cross-sell: Camps & Clinics
                $cross_sell_products = array();
                if (false) {
                    $args = array(
                        'status' => 'publish',
                        'limit' => 3,
                        'category' => array('camps', 'clinics', 'camp', 'clinic'),
                        'orderby' => 'date',
                        'order' => 'DESC',
                    );
                    $cross_sell_products = ptp_get_camp_products($args);
                    
                    if (empty($cross_sell_products)) {
                        $all_products = ptp_get_camp_products(array('status' => 'publish', 'limit' => 15, 'orderby' => 'date', 'order' => 'DESC'));
                        foreach ($all_products as $product) {
                            $title_lower = strtolower($product->get_name());
                            if (strpos($title_lower, 'camp') !== false || strpos($title_lower, 'clinic') !== false) {
                                $cross_sell_products[] = $product;
                            }
                            if (count($cross_sell_products) >= 3) break;
                        }
                    }
                }
                
                if (!empty($cross_sell_products)): ?>
                <!-- Camps & Clinics Cross-sell -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top: 32px;">
                    <tr>
                        <td style="padding: 24px; background-color: #FFFBEB; border: 1px solid #FDE68A; border-radius: 12px;">
                            <p style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #0E0F11; text-align: center;">Keep the Training Going!</p>
                            <p style="margin: 0 0 20px; font-size: 14px; color: #374151; text-align: center; line-height: 1.5;">Join one of our upcoming camps or clinics in your area for even more high-rep, personalized training.</p>
                            
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <?php foreach ($cross_sell_products as $product): 
                                    $price = $product->get_price();
                                    $short_desc = $product->get_short_description();
                                    $camp_dates = '';
                                    if (preg_match('/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2}/', $short_desc, $date_match)) {
                                        $camp_dates = $date_match[0];
                                    }
                                ?>
                                <tr>
                                    <td style="padding: 10px 0; border-bottom: 1px solid #FDE68A;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td style="vertical-align: middle;">
                                                    <p style="margin: 0; font-size: 14px; font-weight: 700; color: #0E0F11;"><?php echo esc_html($product->get_name()); ?></p>
                                                    <?php if ($camp_dates): ?>
                                                    <p style="margin: 2px 0 0; font-size: 12px; color: #6B7280;"><?php echo esc_html($camp_dates); ?></p>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="vertical-align: middle; text-align: right;">
                                                    <span style="font-size: 16px; font-weight: 800; color: #B45309;">$<?php echo number_format($price, 0); ?></span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                            
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top: 16px;">
                                <tr>
                                    <td align="center">
                                        <a href="<?php echo esc_url(home_url('/shop/')); ?>" style="display: inline-block; background-color: #FCB900; color: #0E0F11; padding: 12px 24px; font-size: 14px; font-weight: 700; text-decoration: none; border-radius: 6px;" target="_blank">View All Camps & Clinics</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>
                <?php
                break;
                
            case 'trainer-new-booking':
                ?>
                <h1 style="<?php echo $h1; ?>">New <?php echo !empty($data['is_group']) ? 'Group ' : ''; ?>Booking</h1>
                <p style="<?php echo $subtitle; ?>">Great news <?php echo esc_html($data['trainer_name']); ?>, you have a new training session booked<?php echo !empty($data['is_group']) ? ' with ' . intval($data['player_count']) . ' players' : ''; ?>.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <?php if (!empty($data['is_group']) && !empty($data['all_players'])): ?>
                                        <span style="<?php echo $label; ?>">Players (<?php echo count($data['all_players']); ?>)</span><br>
                                        <div style="margin-top: 8px;">
                                            <?php foreach ($data['all_players'] as $idx => $p_name): ?>
                                            <span style="display:inline-block;background:#FEF3C7;border:1px solid #FCB900;padding:4px 10px;border-radius:4px;margin:2px 4px 2px 0;font-size:14px;">
                                                <strong style="color:#92400E;"><?php echo $idx + 1; ?>.</strong> <?php echo esc_html($p_name); ?>
                                            </span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <span style="<?php echo $label; ?>">Player</span><br>
                                        <span style="<?php echo $value; ?>"><?php echo esc_html($data['player_name']); ?></span>
                                        <?php if (!empty($data['player_age'])): ?>
                                        <span style="font-size: 14px; color: #6B7280;"> - <?php echo esc_html($data['player_age']); ?> years old</span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <span style="<?php echo $label; ?>">Parent</span><br>
                                        <span style="font-size: 15px; color: #0E0F11;"><?php echo esc_html($data['parent_name']); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <span style="<?php echo $label; ?>">Date &amp; Time</span><br>
                                        <span style="<?php echo $value; ?>"><?php echo esc_html($data['date']); ?></span><br>
                                        <span style="font-size: 14px; color: #374151;"><?php echo esc_html($data['time']); ?></span>
                                    </td>
                                </tr>
                                <?php if (!empty($data['location'])): ?>
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <span style="<?php echo $label; ?>">Location</span><br>
                                        <span style="font-size: 15px; color: #0E0F11;"><?php echo esc_html($data['location']); ?></span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td style="padding: 12px 0;">
                                        <span style="<?php echo $label; ?>">Your Earnings</span><br>
                                        <span style="font-size: 24px; font-weight: 800; color: #059669;">$<?php echo esc_html($data['earnings']); ?></span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <?php if (!empty($data['notes'])): ?>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 28px;">
                    <tr>
                        <td style="background-color: #FEF3C7; border-radius: 10px; padding: 16px; border-left: 4px solid #FCB900;">
                            <p style="margin: 0 0 4px; font-size: 11px; font-weight: 700; color: #92400E; text-transform: uppercase;">Note from Parent</p>
                            <p style="margin: 0; font-size: 14px; color: #78350F; line-height: 1.5;"><?php echo esc_html($data['notes']); ?></p>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['dashboard_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">View Dashboard</a>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'session-reminder-parent':
            case 'session-reminder-trainer':
                ?>
                <h1 style="<?php echo $h1; ?>">Session Tomorrow</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['name']); ?>, just a friendly reminder about tomorrow's training session.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 4px; font-size: 18px; font-weight: 700; color: #0E0F11;"><?php echo esc_html($data['date']); ?></p>
                            <p style="margin: 0 0 12px; font-size: 16px; color: #374151;"><?php echo esc_html($data['time']); ?></p>
                            <?php if ($template === 'session-reminder-parent'): ?>
                            <p style="margin: 0; font-size: 15px; color: #6B7280;">
                                Trainer: <?php echo esc_html($data['trainer_name']); ?>
                            </p>
                            <?php else: ?>
                            <!-- v132: Multi-player support for trainer reminder -->
                            <?php if (!empty($data['is_group']) && !empty($data['all_players'])): ?>
                            <p style="margin: 0 0 8px; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #9CA3AF;">Players (<?php echo count($data['all_players']); ?>)</p>
                            <div style="margin-bottom: 4px;">
                                <?php foreach ($data['all_players'] as $idx => $p_name): ?>
                                <span style="display:inline-block;background:#FEF3C7;border:1px solid #FCB900;padding:4px 10px;border-radius:4px;margin:2px 4px 2px 0;font-size:14px;">
                                    <strong style="color:#92400E;"><?php echo $idx + 1; ?>.</strong> <?php echo esc_html($p_name); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p style="margin: 0; font-size: 15px; color: #6B7280;">
                                Player: <?php echo esc_html($data['player_name']); ?>
                            </p>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($data['location'])): ?>
                            <p style="margin: 12px 0 0; font-size: 14px; color: #6B7280;">Location: <?php echo esc_html($data['location']); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['dashboard_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">View Details</a>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'booking-cancelled':
                ?>
                <h1 style="<?php echo $h1; ?>">Session Cancelled</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['name']); ?>, the following session has been cancelled.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FEF2F2; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 4px; font-size: 16px; font-weight: 600; color: #991B1B;"><?php echo esc_html($data['date']); ?> at <?php echo esc_html($data['time']); ?></p>
                            <p style="margin: 0; font-size: 14px; color: #7F1D1D;">
                                Player: <?php echo esc_html($data['player_name']); ?> &bull; Trainer: <?php echo esc_html($data['trainer_name']); ?>
                            </p>
                            <?php if (!empty($data['reason'])): ?>
                            <p style="margin: 12px 0 0; font-size: 14px; color: #991B1B;">Reason: <?php echo esc_html($data['reason']); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <p style="margin: 0; font-size: 14px; color: #6B7280; line-height: 1.5;">If you have any questions, please reply to this email or contact us.</p>
                <?php
                break;
                
            case 'payout-processed':
                ?>
                <h1 style="<?php echo $h1; ?>">Payout Processed</h1>
                <p style="<?php echo $subtitle; ?>">Great news <?php echo esc_html($data['name']); ?>, your earnings have been sent.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #ECFDF5; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 32px; text-align: center;">
                            <p style="margin: 0 0 4px; font-size: 14px; color: #065F46;">Amount Deposited</p>
                            <p style="margin: 0; font-size: 40px; font-weight: 800; color: #059669;">$<?php echo esc_html($data['amount']); ?></p>
                            <p style="margin: 8px 0 0; font-size: 14px; color: #6B7280;">via <?php echo esc_html($data['method']); ?> &bull; <?php echo esc_html($data['date']); ?></p>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['dashboard_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">View Earnings</a>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'application-received':
                ?>
                <h1 style="<?php echo $h1; ?>">Application Received</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['name']); ?>, thank you for applying to join the PTP trainer network.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #ECFDF5; border-radius: 12px; margin-bottom: 24px; border: 1px solid #BBF7D0;">
                    <tr>
                        <td style="padding: 20px; text-align: center;">
                            <p style="margin: 0; font-size: 14px; color: #166534; font-weight: 600;">Application Status: Under Review</p>
                            <p style="margin: 8px 0 0; font-size: 13px; color: #15803D;">We typically respond within 24-48 hours</p>
                        </td>
                    </tr>
                </table>
                
                <p style="margin: 0 0 24px; font-size: 15px; color: #374151; line-height: 1.6;">We're excited to learn about your athletic background. Our team reviews every application personally to ensure we maintain the highest quality trainers on our platform.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #0E0F11;">What happens next?</p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="padding: 10px 0; font-size: 14px; color: #374151; vertical-align: top;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td style="width: 36px; vertical-align: top;">
                                                    <span style="display: inline-block; width: 28px; height: 28px; background: #FCB900; color: #0E0F11; border-radius: 50%; text-align: center; line-height: 28px; font-weight: 700; font-size: 13px;">1</span>
                                                </td>
                                                <td style="vertical-align: top;">
                                                    <strong style="color: #111;">Application Review</strong><br>
                                                    <span style="color: #6B7280; font-size: 13px;">We verify your playing experience</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; font-size: 14px; color: #374151; vertical-align: top;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td style="width: 36px; vertical-align: top;">
                                                    <span style="display: inline-block; width: 28px; height: 28px; background: #E5E7EB; color: #6B7280; border-radius: 50%; text-align: center; line-height: 28px; font-weight: 700; font-size: 13px;">2</span>
                                                </td>
                                                <td style="vertical-align: top;">
                                                    <strong style="color: #111;">Approval Email</strong><br>
                                                    <span style="color: #6B7280; font-size: 13px;">Login credentials sent if approved</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; font-size: 14px; color: #374151; vertical-align: top;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td style="width: 36px; vertical-align: top;">
                                                    <span style="display: inline-block; width: 28px; height: 28px; background: #E5E7EB; color: #6B7280; border-radius: 50%; text-align: center; line-height: 28px; font-weight: 700; font-size: 13px;">3</span>
                                                </td>
                                                <td style="vertical-align: top;">
                                                    <strong style="color: #111;">Profile Setup</strong><br>
                                                    <span style="color: #6B7280; font-size: 13px;">Add photo, availability &amp; complete profile</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 0; font-size: 14px; color: #374151; vertical-align: top;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td style="width: 36px; vertical-align: top;">
                                                    <span style="display: inline-block; width: 28px; height: 28px; background: #E5E7EB; color: #6B7280; border-radius: 50%; text-align: center; line-height: 28px; font-weight: 700; font-size: 13px;">4</span>
                                                </td>
                                                <td style="vertical-align: top;">
                                                    <strong style="color: #111;">Start Earning</strong><br>
                                                    <span style="color: #6B7280; font-size: 13px;">Get matched with families &amp; accept bookings</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FFFBEB; border-radius: 12px; margin-bottom: 24px; border: 1px solid #FDE68A;">
                    <tr>
                        <td style="padding: 20px;">
                            <p style="margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #92400E;">While you wait...</p>
                            <p style="margin: 0; font-size: 14px; color: #B45309; line-height: 1.5;">Follow us on Instagram <a href="https://instagram.com/<?php echo esc_attr(ptp_email_brand('instagram')); ?>" style="color: #B45309; font-weight: 600;">@<?php echo esc_html(ptp_email_brand('instagram')); ?></a> to see what our trainers are doing!</p>
                        </td>
                    </tr>
                </table>
                
                <p style="margin: 0; font-size: 14px; color: #6B7280;">Questions? Just reply to this email - we're here to help!</p>
                <?php
                break;
                
            case 'application-approved':
                ?>
                <h1 style="<?php echo $h1; ?>">Welcome to <?php echo esc_html(ptp_email_brand('company')); ?></h1>
                <p style="<?php echo $subtitle; ?>">Congratulations <?php echo esc_html($data['name']); ?>, your application has been approved.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #ECFDF5; border-radius: 12px; margin-bottom: 24px; border: 1px solid #BBF7D0;">
                    <tr>
                        <td style="padding: 24px; text-align: center;">
                            <p style="margin: 0 0 4px; font-size: 14px; color: #166534; font-weight: 600;">Application Approved</p>
                            <p style="margin: 0; font-size: 13px; color: #15803D;">You're now part of the PTP trainer network</p>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 24px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #0E0F11;">Your Login Details</p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <span style="display: inline-block; width: 80px; font-size: 13px; color: #6B7280;">Email:</span>
                                        <span style="font-size: 14px; color: #111; font-weight: 500;"><?php echo esc_html($data['email']); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <span style="display: inline-block; width: 80px; font-size: 13px; color: #6B7280;">Password:</span>
                                        <?php if (!empty($data['password'])): ?>
                                        <code style="background: #FEF3C7; padding: 6px 14px; border-radius: 6px; font-family: 'SF Mono', Monaco, monospace; font-size: 14px; color: #92400E; font-weight: 600;"><?php echo esc_html($data['password']); ?></code>
                                        <?php else: ?>
                                        <span style="font-size: 14px; color: #111; font-weight: 500;">The password you chose when you applied</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if (!empty($data['password'])): ?>
                                <tr>
                                    <td style="padding: 12px 0 0;">
                                        <p style="margin: 0; font-size: 13px; color: #DC2626; font-weight: 500;">This is a temporary password. Change it after logging in.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <td style="padding: 8px 0 0;">
                                        <p style="margin: 0; font-size: 12px; color: #9CA3AF;">Forgot it? Use the "Forgot Password" link on the login page.</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 24px;">
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['login_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">Login &amp; Complete Your Profile</a>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 24px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #0E0F11;">Complete these steps to go live:</p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="padding: 8px 0; font-size: 14px; color: #374151;">
                                        <span style="display: inline-block; width: 24px; color: #FCB900; font-weight: 700;">1.</span>
                                        <strong>Add a professional photo</strong> - Parents want to see who they're booking
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0; font-size: 14px; color: #374151;">
                                        <span style="display: inline-block; width: 24px; color: #FCB900; font-weight: 700;">2.</span>
                                        <strong>Set your availability</strong> - Block out times you can train
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0; font-size: 14px; color: #374151;">
                                        <span style="display: inline-block; width: 24px; color: #FCB900; font-weight: 700;">3.</span>
                                        <strong>Complete your bio</strong> - Share your athletic story
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0; font-size: 14px; color: #374151;">
                                        <span style="display: inline-block; width: 24px; color: #FCB900; font-weight: 700;">4.</span>
                                        <strong>Submit SafeSport &amp; W-9</strong> - Required for training minors &amp; payments
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #DBEAFE; border-radius: 12px; margin-bottom: 24px; border: 1px solid #93C5FD;">
                    <tr>
                        <td style="padding: 20px;">
                            <p style="margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #1E40AF;">Coming Up Next</p>
                            <p style="margin: 0; font-size: 14px; color: #1E3A8A; line-height: 1.5;">You'll receive separate emails with instructions for SafeSport certification and W-9 tax form submission. These are required before you can receive bookings.</p>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FFFBEB; border-radius: 12px; border: 1px solid #FDE68A;">
                    <tr>
                        <td style="padding: 20px;">
                            <p style="margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #92400E;">Pro Tip</p>
                            <p style="margin: 0; font-size: 14px; color: #B45309; line-height: 1.5;">Trainers who complete their profile within 24 hours get 3x more bookings in their first month!</p>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'application-rejected':
                ?>
                <h1 style="<?php echo $h1; ?>">Application Update</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['name']); ?>, thank you for your interest in joining <?php echo esc_html(ptp_email_brand('company')); ?>.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 24px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 15px; color: #374151; line-height: 1.6;">After careful review of your application, we're unable to move forward at this time. This decision could be based on:</p>
                            <ul style="margin: 0 0 16px 20px; padding: 0; color: #6B7280; font-size: 14px; line-height: 1.8;">
                                <li>Current trainer capacity in your area</li>
                                <li>Playing experience requirements</li>
                                <li>Geographic coverage limitations</li>
                            </ul>
                            <p style="margin: 0; font-size: 15px; color: #374151; line-height: 1.6;">This doesn't mean the door is closed - we encourage you to reapply in the future, especially as you gain additional playing or coaching experience.</p>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FFFBEB; border-radius: 12px; margin-bottom: 24px; border: 1px solid #FDE68A;">
                    <tr>
                        <td style="padding: 20px;">
                            <p style="margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #92400E;">Stay Connected</p>
                            <p style="margin: 0; font-size: 14px; color: #B45309; line-height: 1.5;">Follow us on Instagram <a href="https://instagram.com/<?php echo esc_attr(ptp_email_brand('instagram')); ?>" style="color: #B45309; font-weight: 600;">@<?php echo esc_html(ptp_email_brand('instagram')); ?></a> and check back in a few months - our needs change as we expand!</p>
                        </td>
                    </tr>
                </table>
                
                <p style="margin: 0; font-size: 14px; color: #6B7280; line-height: 1.6;">We appreciate you taking the time to apply and wish you the best in your athletic journey. Keep grinding!</p>
                <?php
                break;
                
            case 'contractor-agreement':
                ?>
                <h1 style="<?php echo $h1; ?>">Agreement Signed</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['name']); ?>, this confirms your acceptance of the PTP Independent Contractor Agreement.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F0FDF4; border-radius: 12px; margin-bottom: 28px; border: 1px solid #BBF7D0;">
                    <tr>
                        <td style="padding: 20px;">
                            <p style="margin: 0 0 12px; font-size: 14px; font-weight: 700; color: #166534;">Agreement Signed Successfully</p>
                            <p style="margin: 0 0 4px; font-size: 13px; color: #374151;"><strong>Date:</strong> <?php echo esc_html($data['signed_date']); ?></p>
                            <p style="margin: 0 0 4px; font-size: 13px; color: #374151;"><strong>IP Address:</strong> <?php echo esc_html($data['ip_address']); ?></p>
                            <p style="margin: 0; font-size: 13px; color: #374151;"><strong>Agreement Version:</strong> 1.0</p>
                        </td>
                    </tr>
                </table>
                
                <p style="margin: 0; font-size: 14px; color: #6B7280; line-height: 1.5;">A copy of this agreement has been saved to your account. You can access it anytime from your trainer dashboard.</p>
                <?php
                break;
                
            case 'onboarding-complete':
                ?>
                <h1 style="<?php echo $h1; ?>">Your Profile is Now Active</h1>
                <p style="<?php echo $subtitle; ?>">Congratulations <?php echo esc_html($data['name']); ?>, your profile is live and visible to parents in your area.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #0E0F11;">Quick Tips to Get Bookings</p>
                            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 14px; line-height: 1.8;">
                                <li>Add a professional photo and bio</li>
                                <li>Set competitive hourly rates for your area</li>
                                <li>Keep your availability up to date</li>
                                <li>Respond to messages promptly</li>
                            </ul>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center" style="padding-bottom: 12px;">
                            <a href="<?php echo esc_url($data['profile_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">View Your Profile</a>
                        </td>
                    </tr>
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['dashboard_url']); ?>" style="<?php echo $btn_outline; ?>" target="_blank">Go to Dashboard</a>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'new-message':
                ?>
                <h1 style="<?php echo $h1; ?>">New Message</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['recipient_name']); ?>, you have a new message from <?php echo esc_html($data['sender_name']); ?>.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 8px; font-size: 13px; font-weight: 700; color: #6B7280;"><?php echo esc_html($data['sender_name']); ?> wrote:</p>
                            <p style="margin: 0; font-size: 15px; color: #374151; line-height: 1.6; font-style: italic;">"<?php echo esc_html($data['message']); ?>"</p>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['messages_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">View &amp; Reply</a>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'welcome-parent':
                ?>
                <h1 style="<?php echo $h1; ?>">Welcome to <?php echo esc_html(ptp_email_brand('company')); ?></h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['name']); ?>, thanks for joining PTP! We connect families with elite trainers for personalized 1-on-1 sessions.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #0E0F11;">What makes PTP different?</p>
                            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 14px; line-height: 1.8;">
                                <li>All trainers are current or former D1/Pro players</li>
                                <li>Personalized training focused on your player's needs</li>
                                <li>Flexible scheduling at locations near you</li>
                                <li>Easy booking and secure payments</li>
                            </ul>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['trainers_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">Find a Trainer</a>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'review-request':
                ?>
                <h1 style="<?php echo $h1; ?>">How Was the Session?</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['parent_name']); ?>, we hope <?php echo esc_html($data['player_name']); ?>'s session with <?php echo esc_html($data['trainer_name']); ?> went great!</p>
                
                <p style="margin: 0 0 28px; font-size: 15px; color: #374151; line-height: 1.6;">Your feedback helps other families find great trainers. It only takes a minute!</p>
                
                <!-- Review Trainer -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 20px;">
                    <tr>
                        <td style="padding: 24px; text-align: center;">
                            <p style="margin: 0 0 4px; font-size: 13px; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px;">Rate Your Trainer</p>
                            <p style="margin: 0 0 16px; font-size: 17px; font-weight: 700; color: #0E0F11;"><?php echo esc_html($data['trainer_name']); ?></p>
                            <a href="<?php echo esc_url($data['review_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">Leave Trainer Review</a>
                        </td>
                    </tr>
                </table>
                
                <!-- Google Review -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FFFBEB; border-radius: 12px; margin-bottom: 24px; border: 1px solid #FDE68A;">
                    <tr>
                        <td style="padding: 20px; text-align: center;">
                            <p style="margin: 0 0 4px; font-size: 13px; color: #92400E;">Love PTP?</p>
                            <p style="margin: 0 0 12px; font-size: 14px; color: #B45309;">Help other families find us on Google!</p>
                            <a href="<?php echo esc_url($data['google_review_url']); ?>" style="display: inline-block; background: #fff; color: #374151; padding: 10px 20px; font-size: 13px; font-weight: 600; text-decoration: none; border-radius: 6px; border: 1px solid #E5E7EB;" target="_blank">Leave Google Review</a>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'safesport-request':
                ?>
                <h1 style="<?php echo $h1; ?>">SafeSport Certification Required</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['name']); ?>, congratulations on your approval! To begin training with PTP, we need you to complete SafeSport certification.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FEF3C7; border-radius: 12px; margin-bottom: 28px; border: 1px solid #FCD34D;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 12px; font-size: 15px; font-weight: 700; color: #92400E;">Why SafeSport?</p>
                            <p style="margin: 0 0 16px; font-size: 14px; color: #78350F; line-height: 1.6;">SafeSport certification is required for all trainers working with minors. This training helps create a safe environment for young athletes and is a one-time requirement that takes about 90 minutes to complete.</p>
                            <ul style="margin: 0; padding-left: 20px; color: #78350F; font-size: 14px; line-height: 1.8;">
                                <li>Free to complete through U.S. Center for SafeSport</li>
                                <li>Valid for 2 years</li>
                                <li>Can be completed online at your own pace</li>
                            </ul>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #0E0F11;">How to Complete:</p>
                            <ol style="margin: 0; padding-left: 20px; color: #374151; font-size: 14px; line-height: 2;">
                                <li>Visit <a href="https://safesport.org" style="color: #FCB900; font-weight: 600;">safesport.org</a></li>
                                <li>Create an account and complete the "Core Training"</li>
                                <li>Download your certificate when finished</li>
                                <li>Reply to this email with your certificate attached</li>
                            </ol>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #DBEAFE; border-radius: 12px; margin-bottom: 24px; border: 1px solid #93C5FD;">
                    <tr>
                        <td style="padding: 16px 20px;">
                            <p style="margin: 0; font-size: 14px; color: #1E40AF;"><strong>Deadline:</strong> Please complete within 7 days to activate your profile.</p>
                        </td>
                    </tr>
                </table>
                
                <p style="margin: 0; font-size: 14px; color: #6B7280; line-height: 1.5;">Questions? Reply to this email and we'll help you out.</p>
                <?php
                break;
                
            case 'w9-request':
                ?>
                <h1 style="<?php echo $h1; ?>">W-9 Tax Form Required</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['name']); ?>, as an independent contractor with PTP, we need a completed W-9 form on file for tax purposes.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #0E0F11;">What is a W-9?</p>
                            <p style="margin: 0; font-size: 14px; color: #374151; line-height: 1.6;">The W-9 is a standard IRS form that provides us with your taxpayer identification number. This is required for us to pay you and report earnings to the IRS if you earn over $600 in a calendar year.</p>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F0FDF4; border-radius: 12px; margin-bottom: 28px; border: 1px solid #BBF7D0;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #166534;">How to Complete:</p>
                            <ol style="margin: 0; padding-left: 20px; color: #374151; font-size: 14px; line-height: 2;">
                                <li>Download the W-9 form from <a href="https://www.irs.gov/pub/irs-pdf/fw9.pdf" style="color: #166534; font-weight: 600;">IRS.gov</a></li>
                                <li>Fill out your name, address, and SSN or EIN</li>
                                <li>Sign and date the form</li>
                                <li>Reply to this email with the completed form attached</li>
                            </ol>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FEF3C7; border-radius: 12px; margin-bottom: 24px; border: 1px solid #FCD34D;">
                    <tr>
                        <td style="padding: 16px 20px;">
                            <p style="margin: 0; font-size: 14px; color: #92400E;"><strong>Important:</strong> Your first payout will be held until we receive your W-9.</p>
                        </td>
                    </tr>
                </table>
                
                <p style="margin: 0; font-size: 14px; color: #6B7280; line-height: 1.5;">Your information is kept secure and confidential. Questions? Reply to this email.</p>
                <?php
                break;
                
            case 'compliance-reminder':
                ?>
                <h1 style="<?php echo $h1; ?>">Reminder: Complete Your Requirements</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['name']); ?>, we noticed you haven't completed all your onboarding requirements yet.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FEE2E2; border-radius: 12px; margin-bottom: 28px; border: 1px solid #FECACA;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #991B1B;">Outstanding Requirements:</p>
                            <ul style="margin: 0; padding-left: 20px; color: #7F1D1D; font-size: 14px; line-height: 1.8;">
                                <?php if (!empty($data['missing_safesport'])): ?>
                                <li><strong>SafeSport Certification</strong> - Required to work with minors</li>
                                <?php endif; ?>
                                <?php if (!empty($data['missing_w9'])): ?>
                                <li><strong>W-9 Tax Form</strong> - Required for payment processing</li>
                                <?php endif; ?>
                            </ul>
                        </td>
                    </tr>
                </table>
                
                <p style="margin: 0 0 24px; font-size: 15px; color: #374151; line-height: 1.6;">Your profile will remain inactive until these requirements are completed. Please submit as soon as possible to start receiving bookings.</p>
                
                <p style="margin: 0; font-size: 14px; color: #6B7280; line-height: 1.5;">Need help? Reply to this email and we'll guide you through the process.</p>
                <?php
                break;
                
            case 'compliance-complete':
                ?>
                <h1 style="<?php echo $h1; ?>">All Set! You're Ready to Train</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['name']); ?>, congratulations! All your compliance requirements have been verified.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #D1FAE5; border-radius: 12px; margin-bottom: 28px; border: 1px solid #A7F3D0;">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 12px; font-size: 15px; font-weight: 700; color: #065F46;">Your Profile is Now Active!</p>
                            <p style="margin: 0; font-size: 14px; color: #047857; line-height: 1.6;">Parents in your area can now find and book sessions with you. Make sure your availability is up to date to start receiving bookings.</p>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center" style="padding-bottom: 12px;">
                            <a href="<?php echo esc_url($data['dashboard_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">Go to Dashboard</a>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'session-completion-request':
                ?>
                <h1 style="<?php echo $h1; ?>">Confirm Your Session</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['parent_name']); ?>, <?php echo esc_html($data['trainer_name']); ?> has marked <?php echo esc_html($data['player_name']); ?>'s session as complete. Please confirm to release payment.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 24px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <span style="<?php echo $label; ?>">Session</span><br>
                                        <span style="<?php echo $value; ?>"><?php echo esc_html($data['player_name']); ?> with <?php echo esc_html($data['trainer_name']); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0; border-bottom: 1px solid #E5E7EB;">
                                        <span style="<?php echo $label; ?>">Date &amp; Time</span><br>
                                        <span style="<?php echo $value; ?>"><?php echo esc_html($data['date']); ?> at <?php echo esc_html($data['time']); ?></span>
                                    </td>
                                </tr>
                                <?php if (!empty($data['location'])): ?>
                                <tr>
                                    <td style="padding: 12px 0;">
                                        <span style="<?php echo $label; ?>">Location</span><br>
                                        <span style="font-size: 15px; color: #0E0F11;"><?php echo esc_html($data['location']); ?></span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FEF3C7; border-radius: 8px; margin-bottom: 24px; border: 1px solid #FCD34D;">
                    <tr>
                        <td style="padding: 16px 20px;">
                            <p style="margin: 0; font-size: 14px; color: #92400E;"><strong>One-click confirm below</strong> — no login required. If you don't respond, the session will auto-confirm in 48 hours and payment will be released.</p>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center" style="padding-bottom: 12px;">
                            <a href="<?php echo esc_url($data['confirm_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">Confirm Session</a>
                        </td>
                    </tr>
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['dispute_url']); ?>" style="<?php echo $btn_outline; ?>" target="_blank">Report an Issue</a>
                        </td>
                    </tr>
                </table>
                
                <p style="margin: 24px 0 0; font-size: 13px; color: #6B7280; text-align: center; line-height: 1.5;">Had a problem with your session? Click "Report an Issue" and we'll help resolve it.</p>
                <?php
                break;
                
            case 'trainer-awaiting-confirmation':
                ?>
                <h1 style="<?php echo $h1; ?>">Session Marked Complete</h1>
                <p style="<?php echo $subtitle; ?>">Nice work <?php echo esc_html($data['trainer_name']); ?>! Your session with <?php echo esc_html($data['player_name']); ?> has been marked complete.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #DBEAFE; border-radius: 12px; margin-bottom: 28px; border: 1px solid #93C5FD;">
                    <tr>
                        <td style="padding: 24px; text-align: center;">
                            <p style="margin: 0 0 8px; font-size: 14px; color: #1E40AF;">Awaiting Parent Confirmation</p>
                            <p style="margin: 0 0 16px; font-size: 14px; color: #3B82F6;"><?php echo esc_html($data['parent_name']); ?> has 48 hours to confirm</p>
                            <p style="margin: 0 0 4px; font-size: 14px; color: #1E40AF;">Your Earnings</p>
                            <p style="margin: 0; font-size: 32px; font-weight: 800; color: #059669;">$<?php echo esc_html($data['earnings']); ?></p>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 20px;">
                            <p style="margin: 0 0 12px; font-size: 14px; font-weight: 700; color: #0E0F11;">What happens next?</p>
                            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 14px; line-height: 1.8;">
                                <li>Parent confirms the session was completed</li>
                                <li>Payment is automatically transferred to your Stripe account</li>
                                <li>Funds arrive in 1-3 business days</li>
                            </ul>
                            <p style="margin: 12px 0 0; font-size: 13px; color: #6B7280;">If the parent doesn't respond within 48 hours, the session will auto-confirm.</p>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['dashboard_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">View Earnings</a>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'new-training-created':
                ?>
                <h1 style="<?php echo $h1; ?>">New Training Created</h1>
                <p style="<?php echo $subtitle; ?>">Hey <?php echo esc_html($data['trainer_name']); ?>! Your new training has been created and is now available for booking.</p>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F0FDF4; border-radius: 12px; margin-bottom: 28px; border: 1px solid #86EFAC;">
                    <tr>
                        <td style="padding: 24px; text-align: center;">
                            <p style="margin: 0 0 8px; font-size: 14px; color: #166534;">Training Live</p>
                            <p style="margin: 0; font-size: 24px; font-weight: 800; color: #0E0F11;"><?php echo esc_html($data['product_name']); ?></p>
                            <?php if (!empty($data['product_price']) && $data['product_price'] !== 'Custom'): ?>
                            <p style="margin: 12px 0 0; font-size: 20px; font-weight: 700; color: #059669;">$<?php echo esc_html($data['product_price']); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <?php if (!empty($data['product_description'])): ?>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 20px;">
                            <p style="margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #0E0F11;">Description</p>
                            <p style="margin: 0; font-size: 14px; color: #374151; line-height: 1.6;"><?php echo esc_html($data['product_description']); ?></p>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F9FAFB; border-radius: 12px; margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 20px;">
                            <p style="margin: 0 0 12px; font-size: 14px; font-weight: 700; color: #0E0F11;">What happens next?</p>
                            <ul style="margin: 0; padding-left: 20px; color: #374151; font-size: 14px; line-height: 1.8;">
                                <li>Parents can now book this training on your profile</li>
                                <li>You'll receive notifications when bookings come in</li>
                                <li>Manage your availability in the trainer dashboard</li>
                            </ul>
                        </td>
                    </tr>
                </table>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['dashboard_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">View Dashboard</a>
                        </td>
                    </tr>
                </table>
                <?php
                break;
                
            case 'session-recap':
                $effort_colors = array(1 => '#EF4444', 2 => '#F59E0B', 3 => '#3B82F6', 4 => '#22C55E', 5 => '#FCB900');
                $effort_color = $effort_colors[intval($data['player_effort'])] ?? '#6B7280';
                $effort_dots = '';
                for ($i = 1; $i <= 5; $i++) {
                    $dot_color = $i <= intval($data['player_effort']) ? $effort_color : '#E5E7EB';
                    $effort_dots .= '<td style="padding: 0 3px;"><div style="width: 10px; height: 10px; border-radius: 50%; background: ' . $dot_color . ';"></div></td>';
                }
                ?>
                <h1 style="<?php echo $h1; ?>">Session Recap</h1>
                <p style="<?php echo $subtitle; ?>">Hi <?php echo esc_html($data['parent_name']); ?>, here's a summary of <?php echo esc_html($data['player_name']); ?>'s session.</p>
                
                <!-- Session Info Bar -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #0E0F11; border-radius: 12px; margin-bottom: 24px;">
                    <tr>
                        <td style="padding: 20px 24px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td>
                                        <span style="font-size: 11px; font-weight: 700; color: #FCB900; text-transform: uppercase; letter-spacing: 0.5px;">Coach</span><br>
                                        <span style="font-size: 16px; font-weight: 700; color: #FFFFFF;"><?php echo esc_html($data['trainer_name']); ?></span>
                                    </td>
                                    <td align="right">
                                        <span style="font-size: 11px; font-weight: 700; color: #FCB900; text-transform: uppercase; letter-spacing: 0.5px;">Date</span><br>
                                        <span style="font-size: 14px; font-weight: 500; color: #FFFFFF;"><?php echo esc_html($data['session_date']); ?></span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <?php if (!empty($data['focus_worked_on'])): ?>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 20px;">
                    <tr>
                        <td style="padding: 20px; background-color: #F9FAFB; border-radius: 12px; border-left: 4px solid #FCB900;">
                            <span style="<?php echo $label; ?>">What We Worked On</span>
                            <p style="margin: 8px 0 0; font-size: 15px; color: #0E0F11; line-height: 1.6;"><?php echo nl2br(esc_html($data['focus_worked_on'])); ?></p>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($data['achievements'])): ?>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 20px;">
                    <tr>
                        <td style="padding: 20px; background-color: #F0FDF4; border-radius: 12px; border-left: 4px solid #22C55E;">
                            <span style="<?php echo $label; ?> color: #16A34A;">Wins</span>
                            <p style="margin: 8px 0 0; font-size: 15px; color: #0E0F11; line-height: 1.6;"><?php echo nl2br(esc_html($data['achievements'])); ?></p>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($data['areas_to_improve'])): ?>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 20px;">
                    <tr>
                        <td style="padding: 20px; background-color: #FFF7ED; border-radius: 12px; border-left: 4px solid #F59E0B;">
                            <span style="<?php echo $label; ?> color: #D97706;">Areas to Keep Working On</span>
                            <p style="margin: 8px 0 0; font-size: 15px; color: #0E0F11; line-height: 1.6;"><?php echo nl2br(esc_html($data['areas_to_improve'])); ?></p>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($data['homework'])): ?>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 20px;">
                    <tr>
                        <td style="padding: 20px; background-color: #EFF6FF; border-radius: 12px; border-left: 4px solid #3B82F6;">
                            <span style="<?php echo $label; ?> color: #2563EB;">Homework</span>
                            <p style="margin: 8px 0 0; font-size: 15px; color: #0E0F11; line-height: 1.6;"><?php echo nl2br(esc_html($data['homework'])); ?></p>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($data['player_effort']) && $data['player_effort'] > 0): ?>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 28px;">
                    <tr>
                        <td style="padding: 20px; background-color: #F9FAFB; border-radius: 12px; text-align: center;">
                            <span style="<?php echo $label; ?>">Effort Level</span><br>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin-top: 10px;">
                                <tr><?php echo $effort_dots; ?></tr>
                            </table>
                            <span style="display: block; margin-top: 8px; font-size: 18px; font-weight: 800; color: <?php echo $effort_color; ?>;"><?php echo esc_html($data['effort_label']); ?></span>
                        </td>
                    </tr>
                </table>
                <?php endif; ?>
                
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr>
                        <td align="center">
                            <a href="<?php echo esc_url($data['dashboard_url']); ?>" style="<?php echo $btn_primary; ?>" target="_blank">View Dashboard</a>
                        </td>
                    </tr>
                </table>
                
                <p style="margin: 24px 0 0; font-size: 13px; color: #9CA3AF; text-align: center; line-height: 1.5;">
                    This recap was sent by your PTP coach after the session.
                </p>
                <?php
                break;
                
            default:
                ?>
                <p style="font-size: 15px; color: #374151;">Thank you for using <?php echo esc_html(ptp_email_brand('company')); ?>.</p>
                <?php
                break;
        }
        
        return ob_get_clean();
    }
    
    /**
     * Send new training created notification to trainer
     * Triggered by Stripe product.created webhook
     */
    public static function send_new_training_notification($trainer_id, $product_data) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare("
            SELECT t.*, u.user_email 
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
            WHERE t.id = %d
        ", $trainer_id));
        
        if (!$trainer) {
            ptp_log("[PTP Email] send_new_training_notification: Trainer #$trainer_id not found");
            return false;
        }
        
        $email = $trainer->user_email ?: $trainer->email;
        if (!$email) {
            ptp_log("[PTP Email] send_new_training_notification: No email found for trainer #$trainer_id");
            return false;
        }
        
        $trainer_name = explode(' ', $trainer->display_name)[0];
        $product_name = $product_data['name'] ?? 'New Training';
        $product_price = isset($product_data['default_price_amount']) 
            ? number_format($product_data['default_price_amount'] / 100, 2) 
            : 'Custom';
        
        $subject = "New Training Created - {$product_name}";
        
        $data = array(
            'trainer_name' => $trainer_name,
            'product_name' => $product_name,
            'product_price' => $product_price,
            'product_description' => $product_data['description'] ?? '',
            'stripe_product_id' => $product_data['id'] ?? '',
            'dashboard_url' => home_url('/trainer-dashboard/'),
        );
        
        $body = self::render_template('new-training-created', $data);
        
        ptp_log("[PTP Email] Sending new training notification for trainer #$trainer_id to: $email");
        $result = wp_mail($email, $subject, $body);
        
        // Also notify admin
        $admin_email = get_option('admin_email');
        $admin_subject = "New Training Created by {$trainer->display_name}";
        wp_mail($admin_email, $admin_subject, $body);
        
        return $result;
    }
    
    /**
     * Send SafeSport certification request email
     */
    public static function send_safesport_request($email, $name) {
        $subject = "Action Required: Complete SafeSport Certification";
        $body = self::render_template('safesport-request', array('name' => $name));
        return wp_mail($email, $subject, $body);
    }
    
    /**
     * Send W-9 tax form request email
     */
    public static function send_w9_request($email, $name) {
        $subject = "Action Required: Submit W-9 Tax Form";
        $body = self::render_template('w9-request', array('name' => $name));
        return wp_mail($email, $subject, $body);
    }
    
    /**
     * Send compliance reminder email
     */
    public static function send_compliance_reminder($email, $name, $missing_safesport = false, $missing_w9 = false) {
        $subject = "Reminder: Complete Your PTP Requirements";
        $body = self::render_template('compliance-reminder', array(
            'name' => $name,
            'missing_safesport' => $missing_safesport,
            'missing_w9' => $missing_w9
        ));
        return wp_mail($email, $subject, $body);
    }
    
    /**
     * Send compliance complete notification
     */
    public static function send_compliance_complete($email, $name) {
        $subject = "You're All Set - Profile Now Active!";
        $body = self::render_template('compliance-complete', array(
            'name' => $name,
            'dashboard_url' => home_url('/trainer-dashboard/')
        ));
        return wp_mail($email, $subject, $body);
    }
    
    /**
     * Schedule compliance email sequence for a trainer
     * v135.1: This is now MANUAL ONLY - called via admin quick actions
     * NOT automatically triggered on approval
     * 
     * Manual triggers available:
     * - Admin > Trainers > Quick Actions > Send SafeSport Request
     * - Admin > Trainers > Quick Actions > Send W-9 Request
     */
    public static function schedule_compliance_emails($trainer_id, $email, $name) {
        // Send SafeSport request immediately
        self::send_safesport_request($email, $name);
        
        // Schedule W9 email for 1 hour later
        wp_schedule_single_event(time() + 3600, 'ptp_send_w9_email', array($email, $name));
        
        // Schedule reminder emails at 3 days and 5 days if not completed
        wp_schedule_single_event(time() + (3 * DAY_IN_SECONDS), 'ptp_compliance_reminder', array($trainer_id));
        wp_schedule_single_event(time() + (5 * DAY_IN_SECONDS), 'ptp_compliance_reminder', array($trainer_id));
    }
    
    /**
     * Render session recap email (public method called by PTP_Training_Plans)
     */
    public static function render_session_recap_email($data) {
        return self::render_template('session-recap', $data);
    }
    
    /**
     * Render Training Plan email — premium PTP-branded email sent to parents
     * after every session. Includes session recap, skill ratings, homework,
     * and forward-looking next session plan.
     */
    public static function render_training_plan_email($data) {
        $b = function_exists('ptp_email_brand') ? ptp_email_brand() : array();
        $logo_url = $b['logo_url'] ?? '';
        $home_url = $b['site_url'] ?? home_url();
        $company  = $b['company'] ?? 'PTP Soccer';
        
        $effort = intval($data['player_effort'] ?? 0);
        $effort_colors = array(1 => '#EF4444', 2 => '#F59E0B', 3 => '#3B82F6', 4 => '#22C55E', 5 => '#FCB900');
        $effort_color = $effort_colors[$effort] ?? '#6B7280';
        $effort_label = $data['effort_label'] ?? '';
        
        // Build effort dots
        $effort_dots = '';
        if ($effort > 0) {
            for ($i = 1; $i <= 5; $i++) {
                $dc = $i <= $effort ? $effort_color : '#3A3A3A';
                $effort_dots .= '<td style="padding:0 3px"><div style="width:14px;height:14px;border-radius:50%;background:'.$dc.'"></div></td>';
            }
        }
        
        // Build sections — AI plan takes priority, manual sections as fallback
        $sections_html = '';
        
        if (!empty($data['ai_plan_html'])) {
            // AI-generated personalized training plan
            $sections_html = '<tr><td>' . $data['ai_plan_html'] . '</td></tr>';
        } else {
            // Manual fallback: trainer's raw session notes
            $section_data = array(
                array('icon' => '', 'color' => '#FCB900', 'bg' => '#FFFBEB', 'label' => 'WHAT WE WORKED ON', 'text' => $data['focus_worked_on'] ?? ''),
                array('icon' => '', 'color' => '#22C55E', 'bg' => '#F0FDF4', 'label' => 'WINS', 'text' => $data['achievements'] ?? ''),
                array('icon' => '', 'color' => '#F59E0B', 'bg' => '#FFF7ED', 'label' => 'AREAS TO KEEP BUILDING', 'text' => $data['areas_to_improve'] ?? ''),
                array('icon' => '', 'color' => '#3B82F6', 'bg' => '#EFF6FF', 'label' => 'PRACTICE PLAN / HOMEWORK', 'text' => $data['homework'] ?? ''),
                array('icon' => '', 'color' => '#8B5CF6', 'bg' => '#F5F3FF', 'label' => 'NEXT SESSION FOCUS', 'text' => $data['next_session_focus'] ?? ''),
            );
            
            foreach ($section_data as $sec) {
                if (empty($sec['text'])) continue;
                $sections_html .= '
                <tr><td style="padding:0 0 16px">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:'.$sec['bg'].';border-radius:12px;border-left:4px solid '.$sec['color'].'">
                        <tr><td style="padding:18px 20px">
                            <div style="font-family:Oswald,sans-serif;font-size:11px;font-weight:700;color:'.$sec['color'].';text-transform:uppercase;letter-spacing:0.8px;margin-bottom:8px">'.$sec['label'].'</div>
                            <div style="font-size:15px;color:#1A1A1A;line-height:1.65">'.nl2br(esc_html($sec['text'])).'</div>
                        </td></tr>
                    </table>
                </td></tr>';
            }
        }
        
        // Skills HTML pass-through
        $skills_section = '';
        if (!empty($data['skills_html'])) {
            $skills_section = '<tr><td style="padding:0 0 16px">'.$data['skills_html'].'</td></tr>';
        }
        
        ob_start();
        ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="x-apple-disable-message-reformatting">
    <title>Training Plan</title>
    <!--[if mso]>
    <style type="text/css">table{border-collapse:collapse;}.btn-td,.btn-a{padding:16px 32px!important;}</style>
    <![endif]-->
    <style type="text/css">
        body{margin:0;padding:0;-webkit-text-size-adjust:100%}
        table,td{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0}
        img{border:0;height:auto;line-height:100%;outline:none;text-decoration:none}
        @media screen and (max-width:600px){
            .mp{padding-left:16px!important;padding-right:16px!important}
            .mobile-stack{display:block!important;width:100%!important}
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#0A0A0A;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif">

<!-- Preheader -->
<div style="display:none;max-height:0;overflow:hidden">
    <?php echo esc_html($data['player_name']); ?>'s training plan from <?php echo esc_html($data['trainer_name']); ?> — <?php echo esc_html($data['session_date']); ?>
</div>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#0A0A0A">
<tr><td align="center" style="padding:40px 20px" class="mp">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width:560px">

    <!-- Logo -->
    <tr><td align="center" style="padding-bottom:32px">
        <?php if (!empty($logo_url)): ?>
        <a href="<?php echo esc_url($home_url); ?>" target="_blank">
            <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company); ?>" width="80" style="display:block;max-width:80px;height:auto">
        </a>
        <?php endif; ?>
    </td></tr>

    <!-- Header Card -->
    <tr><td>
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:linear-gradient(135deg,#1A1A1A,#252525);border-radius:16px 16px 0 0;border-bottom:3px solid #FCB900">
            <tr><td style="padding:32px 36px;text-align:center" class="mp">
                <div style="font-family:Oswald,sans-serif;font-size:12px;font-weight:700;color:#FCB900;text-transform:uppercase;letter-spacing:2px;margin-bottom:12px">TRAINING PLAN</div>
                <div style="font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:#FFFFFF;line-height:1.2;text-transform:uppercase"><?php echo esc_html($data['player_name']); ?></div>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin-top:16px">
                    <tr>
                        <td style="padding:0 16px;text-align:center;border-right:1px solid #3A3A3A">
                            <div style="font-size:10px;color:#9CA3AF;text-transform:uppercase;letter-spacing:0.5px">Coach</div>
                            <div style="font-size:14px;color:#FCB900;font-weight:600;margin-top:2px"><?php echo esc_html($data['trainer_name']); ?></div>
                        </td>
                        <td style="padding:0 16px;text-align:center">
                            <div style="font-size:10px;color:#9CA3AF;text-transform:uppercase;letter-spacing:0.5px">Date</div>
                            <div style="font-size:14px;color:#FFFFFF;font-weight:500;margin-top:2px"><?php echo esc_html($data['session_date']); ?></div>
                        </td>
                    </tr>
                </table>
            </td></tr>
        </table>
    </td></tr>

    <!-- Content Card -->
    <tr><td>
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#FFFFFF;border-radius:0 0 16px 16px">
            <tr><td style="padding:32px 36px" class="mp">
                
                <?php if (empty($data['ai_plan_html'])): ?>
                <!-- Greeting (only for manual fallback — AI plan has its own summary) -->
                <p style="font-size:15px;color:#374151;line-height:1.6;margin:0 0 24px">
                    Hi <?php echo esc_html($data['parent_name']); ?>, here's <?php echo esc_html($data['player_name']); ?>'s training plan from today's session.
                </p>
                <?php endif; ?>
                
                <!-- Sections -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <?php echo $sections_html; ?>
                    <?php echo $skills_section; ?>
                </table>
                
                <?php if ($effort > 0): ?>
                <!-- Effort Level -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom:24px">
                    <tr><td style="padding:20px;background:#F9FAFB;border-radius:12px;text-align:center">
                        <div style="font-family:Oswald,sans-serif;font-size:11px;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:10px">EFFORT LEVEL</div>
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center">
                            <tr><?php echo $effort_dots; ?></tr>
                        </table>
                        <div style="margin-top:8px;font-size:18px;font-weight:800;color:<?php echo $effort_color; ?>"><?php echo esc_html($effort_label); ?></div>
                    </td></tr>
                </table>
                <?php endif; ?>
                
                <!-- CTA Button -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                    <tr><td align="center" style="padding-top:8px">
                        <a href="<?php echo esc_url($data['dashboard_url']); ?>" target="_blank" style="display:inline-block;padding:16px 40px;background:#FCB900;color:#0A0A0A;font-family:Oswald,sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;text-decoration:none;border-radius:10px">View Full Progress</a>
                    </td></tr>
                </table>
                
            </td></tr>
        </table>
    </td></tr>

    <!-- Footer -->
    <tr><td style="padding:32px 20px;text-align:center">
        <p style="font-size:12px;color:#6B7280;margin:0;line-height:1.5">
            This training plan was created by your PTP coach.<br>
            <a href="<?php echo esc_url($home_url); ?>" style="color:#FCB900;text-decoration:none" target="_blank"><?php echo esc_html($company); ?></a>
        </p>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
